<?php
declare(strict_types=1);

/**
 * Interjections (SPEC-coaching §6).
 *
 * The load-bearing sentence, and the reason this class is shaped the way it is:
 *
 *   > The user's message NEVER edits the plan. It is recorded as a stated circumstance.
 *   > Claude evaluates it. Only Claude's decision produces a new plan version.
 *   >
 *   > This is structural, not prompt-level. There is no code path from user text to plan
 *   > mutation that bypasses Claude's judgment — so "chat that can be talked into
 *   > anything" is not a failure mode that exists.
 *
 * So send() writes a row and stops. Nothing it does can change a plan. The evaluation is
 * a separate call that reads the recorded turn, and only its structured decision reaches
 * Plans::generateWeek. Three outcomes, from §6.1:
 *
 *   plan_changed   a fact that makes the week wrong → new version, reason='drift_adaptation'
 *   question       not enough to act on → ask (§6.3)
 *   declined       a preference restated, not a fact → pushback, in the user's tone
 *   acknowledged   noted, nothing to change
 *
 * The line is FACTS vs PREFERENCES, not hard vs easy (§6.2). "Travelling Mon-Thu, no gym"
 * reshuffles the week. "Don't feel like legs today" gets pushback. And "this is too hard"
 * is worth asking about — form? load? — it just does not automatically change anything.
 *
 * CONSTRAINTS ARE NOT EDITABLE FROM HERE, per SPEC-safety §6: "an LLM that can be argued
 * out of a constraint has no constraints." The schema enforces it too — user_constraints
 * has no 'chat' source — but the prompt says it and a test asserts it, because this is the
 * one place a user will try.
 */
final class Chat
{
    /** How many turns of history the model sees. */
    private const HISTORY_TURNS = 20;

    /**
     * Record what the user said.
     *
     * Writes a turn and, when the message states a durable fact, a circumstance. It does
     * NOT evaluate and it cannot change a plan: that separation is the structural half of
     * §6.1, and it is why evaluate() is a different method with a different caller.
     *
     * @return array{ok:bool, error:?string, turn_id:?int}
     */
    public static function send(int $userId, string $message): array
    {
        $body = Validate::str($message, 1, 2000);
        if ($body === null) {
            return ['ok' => false, 'error' => 'Say something first.', 'turn_id' => null];
        }

        // Rate limited: each turn is a model call, and a user holding down send should not
        // be able to spend the month's budget in an afternoon.
        if (!RateLimit::allow('chat:' . $userId, 40, 3600)) {
            return ['ok' => false, 'turn_id' => null,
                    'error' => 'That is a lot of messages in an hour. Give your coach a '
                               . 'moment to catch up.'];
        }

        // The plan this was said against, so a later revision can be explained.
        $tz   = Baseline::timezoneOf($userId);
        $week = Schedule::weekStart($tz);
        $live = Plans::live($userId, $week);

        $turnId = DB::insert(
            'INSERT INTO chat_turns (user_id, role, body, context_plan_version_id)
             VALUES (?, "user", ?, ?)',
            [$userId, $body, $live === null ? null : (int) $live['id']]
        );

        return ['ok' => true, 'error' => null, 'turn_id' => $turnId];
    }

    /** User turns with no reply yet, oldest first. What the sweep picks up. */
    public static function pending(int $userId, int $limit = 5): array
    {
        return DB::all(
            'SELECT id, body, context_plan_version_id, circumstance_id, created_at
             FROM chat_turns
             WHERE user_id = ? AND role = "user" AND answered_at IS NULL
             ORDER BY id LIMIT ' . max(1, min(20, $limit)),
            [$userId]
        );
    }

    /** The conversation, oldest first, for display. */
    public static function history(int $userId, int $limit = 50): array
    {
        $rows = DB::all(
            'SELECT id, role, body, outcome, drift_state, circumstance_id,
                    resulting_plan_version_id, answered_at, created_at
             FROM chat_turns
             WHERE user_id = ?
             ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
            [$userId]
        );

        $out = [];
        foreach (array_reverse($rows) as $r) {
            $out[] = [
                'id'      => (int) $r['id'],
                'role'    => (string) $r['role'],
                'body'    => (string) $r['body'],
                'outcome' => $r['outcome'],
                // Marks a turn the COACH opened rather than one the user did. The view
                // shows those differently: a question you were asked is not the same as
                // a reply to something you said.
                'drift'   => $r['drift_state'],
                'plan_changed' => $r['resulting_plan_version_id'] !== null,
                'pending' => (string) $r['role'] === 'user' && $r['answered_at'] === null,
                'at'      => (string) $r['created_at'],
            ];
        }
        return $out;
    }

    /** Is the user waiting on the coach? Drives the "thinking" state in the view. */
    public static function hasPending(int $userId): bool
    {
        return DB::one(
            'SELECT 1 AS x FROM chat_turns
             WHERE user_id = ? AND role = "user" AND answered_at IS NULL LIMIT 1',
            [$userId]
        ) !== null;
    }

    // ---- the evaluation -----------------------------------------------------

    /**
     * Evaluate one user turn and act on it.
     *
     * The ONLY path from a message to a plan change, and it goes through a structured
     * decision rather than prose. "Did Claude agree to change the plan" cannot be a regex
     * over an essay, and the schema is what makes the boundary real: the model returns an
     * enum, and PHP decides what that enum is allowed to do.
     *
     * @return array{ok:bool, error:?string, outcome:?string, plan_version_id:?int}
     */
    public static function evaluate(int $userId, int $turnId): array
    {
        $turn = DB::one(
            'SELECT * FROM chat_turns WHERE id = ? AND user_id = ? AND role = "user"',
            [$turnId, $userId]
        );
        if ($turn === null) {
            return ['ok' => false, 'error' => 'No such turn.', 'outcome' => null,
                    'plan_version_id' => null];
        }

        $tz       = Baseline::timezoneOf($userId);
        $today    = Schedule::today($tz);
        $week     = Schedule::weekStart($tz);
        $livePlan = Plans::live($userId, $week);

        /*
         * Can the plan actually be revised right now?
         *
         * Only if there IS one for this week. During the baseline there deliberately is
         * not, and offering the model a decision it has no authority to carry out is how
         * a promise gets made that the code cannot keep — so the schema omits the option
         * entirely in that case.
         */
        $canRevise = $livePlan !== null;

        $result = Claude::json(self::schema($canRevise), [
            'purpose'    => 'interjection',
            'user_id'    => $userId,
            'max_tokens' => 2000,
            'system'     => self::systemPrompt($userId, $canRevise),
            'messages'   => [[
                'role'    => 'user',
                'content' => self::context($userId, $turn, $livePlan, $today),
            ]],
        ]);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Evaluation failed.',
                    'outcome' => null, 'plan_version_id' => null];
        }

        $data    = $result['data'];
        $reply   = Tone::clean((string) ($data['reply'] ?? ''));
        $outcome = Validate::enum(
            $data['outcome'] ?? null,
            ['acknowledged', 'question', 'plan_changed', 'declined']
        ) ?? 'acknowledged';

        if ($reply === '') {
            return ['ok' => false, 'error' => 'Empty reply.', 'outcome' => null,
                    'plan_version_id' => null];
        }

        // A durable fact gets recorded as a circumstance, whatever the outcome. §6.4: it
        // expires unless it is the permanent kind, so the app stops reshuffling around a
        // trip that ended.
        $circumstanceId = self::recordCircumstance($userId, $turnId, $data, $today);

        /*
         * The plan revision, and note what has to be true to reach it: the model said
         * plan_changed, AND there is a live plan, AND the schema even offered the option.
         * PHP is the gate, not the prompt.
         */
        $planVersionId = null;
        if ($outcome === 'plan_changed' && $canRevise) {
            $gen = Plans::generateWeek($userId, $week, 'drift_adaptation', [
                'interjection' => [
                    'said'    => (string) $turn['body'],
                    'change'  => (string) ($data['change'] ?? ''),
                    'from_day' => $today,
                ],
            ]);
            if ($gen['ok']) {
                $planVersionId = (int) $gen['plan_version_id'];
            } else {
                /*
                 * The revision failed but the conversation still happened.
                 *
                 * Downgraded to 'acknowledged' rather than left claiming a change that did
                 * not land: the user reads the reply, and a reply saying "I have moved
                 * your sessions" beside an unchanged plan is worse than one that does not.
                 */
                $outcome = 'acknowledged';
                error_log('[yoked] interjection revision failed for turn ' . $turnId
                    . ': ' . (string) ($gen['error'] ?? 'unknown'));
            }
        }

        DB::ensureConnected();   // generation can outlive the 60s wait_timeout

        DB::tx(function () use ($userId, $turnId, $reply, $outcome, $planVersionId,
                               $circumstanceId, $livePlan): void {
            DB::insert(
                'INSERT INTO chat_turns
                    (user_id, role, body, outcome, resulting_plan_version_id,
                     context_plan_version_id)
                 VALUES (?, "assistant", ?, ?, ?, ?)',
                [$userId, $reply, $outcome, $planVersionId,
                 $livePlan === null ? null : (int) $livePlan['id']]
            );
            DB::run(
                'UPDATE chat_turns SET answered_at = NOW(), circumstance_id = ?
                 WHERE id = ?',
                [$circumstanceId, $turnId]
            );
        });

        return ['ok' => true, 'error' => null, 'outcome' => $outcome,
                'plan_version_id' => $planVersionId];
    }

    /**
     * Record a durable fact, if the model identified one.
     *
     * Separate from the plan decision on purpose: a fact worth remembering is not the same
     * as a fact worth re-planning for. "I hate salmon" changes no session and should still
     * be on file forever; "travelling Thursday" changes one and expires.
     */
    private static function recordCircumstance(
        int $userId,
        int $turnId,
        array $data,
        string $today
    ): ?int {
        $c = $data['circumstance'] ?? null;
        if (!is_array($c)) {
            return null;
        }

        $kinds  = ['travel', 'illness', 'injury', 'schedule', 'equipment', 'other'];
        $kind   = Validate::enum($c['kind'] ?? null, $kinds);
        $detail = Validate::str($c['detail'] ?? null, 1, 500);
        if ($kind === null || $detail === null) {
            return null;
        }

        // §6.4 again. An end date unless the model says it is permanent, because
        // open-ended is the answer that costs something if it is wrong.
        $endsOn = Validate::date($c['ends_on'] ?? null);
        if ($endsOn === null && ($c['permanent'] ?? false) !== true) {
            $endsOn = date('Y-m-d', strtotime($today . ' +7 days'));
        }

        return DB::insert(
            'INSERT INTO circumstances (user_id, chat_turn_id, kind, detail, starts_on, ends_on)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $turnId, $kind, $detail, $today, $endsOn]
        );
    }

    // ---- prompt -------------------------------------------------------------

    /**
     * The response shape.
     *
     * Structured rather than prose, because the outcome drives a code path. The `change`
     * and `circumstance` fields exist so the revision has something specific to act on
     * rather than the generator re-reading the whole conversation.
     */
    private static function schema(bool $canRevise): array
    {
        $outcomes = $canRevise
            ? ['acknowledged', 'question', 'plan_changed', 'declined']
            // No live plan to revise, so the option is not offered at all. Better than
            // letting the model choose it and having PHP silently refuse.
            : ['acknowledged', 'question', 'declined'];

        $props = [
            'reply' => [
                'type' => 'string',
                'description' => 'What you say to them, in their coaching voice. Two to '
                    . 'four sentences. No greeting, no signature.',
            ],
            'outcome' => [
                'type' => 'string',
                'enum' => $outcomes,
                'description' => 'plan_changed: they stated a FACT that makes this week '
                    . 'wrong. question: you need one specific detail before you can act. '
                    . 'declined: a preference restated rather than a fact. acknowledged: '
                    . 'noted, nothing to change.',
            ],
            'circumstance' => [
                'type' => 'object',
                'description' => 'A durable fact worth remembering, if they stated one. '
                    . 'Omit when they did not.',
                'properties' => [
                    'kind'   => [
                        'type' => 'string',
                        'enum' => ['travel', 'illness', 'injury', 'schedule', 'equipment', 'other'],
                    ],
                    'detail' => ['type' => 'string'],
                    'ends_on' => [
                        'type' => 'string',
                        'description' => 'YYYY-MM-DD when this stops applying. Omit if '
                            . 'permanent.',
                    ],
                    'permanent' => [
                        'type' => 'boolean',
                        'description' => 'True only for something that never expires, like '
                            . 'a food they will always dislike.',
                    ],
                ],
                'required' => ['kind', 'detail'],
                'additionalProperties' => false,
            ],
        ];

        if ($canRevise) {
            $props['change'] = [
                'type' => 'string',
                'description' => 'When outcome is plan_changed: what the plan must do '
                    . 'differently, phrased as an instruction to the generator. Be '
                    . 'specific about which days and what changes.',
            ];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['reply', 'outcome'],
            'properties' => $props,
        ];
    }

    private static function systemPrompt(int $userId, bool $canRevise): string
    {
        $p = DB::one(
            'SELECT tone, explanation_depth FROM profiles WHERE user_id = ?', [$userId]
        ) ?? [];

        $lines = [
            'You are this user\'s coach, replying to something they told you mid-week.',
            '',
            'VOICE: ' . Tone::brief((string) ($p['tone'] ?? 'friendly_encouraging')),
            'Explanation depth: ' . ($p['explanation_depth'] ?? 'brief') . '.',
            '',
            'THE LINE YOU ARE DRAWING is facts versus preferences, NOT hard versus easy.',
            '',
            'A FACT ABOUT REALITY reshuffles the week:',
            '  "Travelling Mon to Thu, no gym, no kitchen"',
            '  "Thunderstorms on hiking day"',
            '  "Slept four hours, wrecked"',
            '  "Tweaked my shoulder"',
            '',
            'A PREFERENCE RESTATED gets pushback, in their voice:',
            '  "Do not feel like legs today"',
            '  "Can we do less cardio"',
            '  "I would rather do arms"',
            '',
            '"This is too hard" sits between them. It is worth ASKING about — is the form '
            . 'wrong, is the load too aggressive, is something hurting — and it does not '
            . 'automatically change anything.',
            '',
            'Rules that are not negotiable:',
            '- ADAPTATION IS NOT PUNISHMENT. Illness lightens the week rather than failing '
            . 'it. Being busy reshuffles it. A heavy day adjusts the days that are left. '
            . 'You never add make-up work, never prescribe a corrective deficit, and never '
            . 'frame anything as owed.',
            '- YOU CANNOT CHANGE A CONSTRAINT. If they say a hard limit no longer applies '
            . '("my knee is fine now", "I can eat peanuts again"), that is a circumstance '
            . 'you may respond to by suggesting they review it in their profile. You do '
            . 'not edit it and you do not plan as though it were gone. A coach who can be '
            . 'argued out of a constraint has none.',
            '- Ask for ONE specific detail when you need it, not a questionnaire. "Cannot '
            . 'make Thursday" deserves "what is the constraint, time or travel or energy?" '
            . 'because the answer decides whether the fix is a shorter session, a '
            . 'hotel-room session, or a rest day.',
            '- Do not shame a logged bad day. They logged it, which is the hard part.',
        ];

        if (!$canRevise) {
            $lines[] = '';
            $lines[] = 'THERE IS NO PLAN THIS WEEK to revise — they are still in their '
                . 'observation fortnight. Acknowledge, ask, or decline. Do not promise a '
                . 'change you cannot make.';
        }

        return implode("\n", $lines);
    }

    /** What the coach can see: the conversation, the plan, and the constraints. */
    private static function context(int $userId, array $turn, ?array $livePlan, string $today): string
    {
        $out = ['=== WHAT THEY JUST SAID ===', (string) $turn['body'], ''];

        $history = DB::all(
            'SELECT role, body, outcome, drift_state, created_at
             FROM chat_turns
             WHERE user_id = ? AND id < ?
             ORDER BY id DESC LIMIT ' . self::HISTORY_TURNS,
            [$userId, (int) $turn['id']]
        );
        if ($history !== []) {
            $out[] = '=== THE CONVERSATION SO FAR, oldest first ===';
            foreach (array_reverse($history) as $h) {
                $who = (string) $h['role'] === 'user' ? 'THEM' : 'YOU';
                // A turn you opened is worth marking: if they are answering a question you
                // asked, the reply should read as a continuation rather than a fresh topic.
                $mark = $h['drift_state'] !== null ? ' (you raised this)' : '';
                $out[] = "  {$who}{$mark}: {$h['body']}";
            }
            $out[] = '';
        }

        $out[] = "=== TODAY IS {$today} ===";

        if ($livePlan !== null) {
            $out[] = '';
            $out[] = "=== THIS WEEK'S PLAN (version {$livePlan['version']}, week of "
                   . "{$livePlan['week_start']}) ===";
            if (($livePlan['summary'] ?? null) !== null) {
                $out[] = (string) $livePlan['summary'];
            }
            $out[] = '';
            $out[] = 'Sessions still to come:';
            $rows = DB::all(
                'SELECT session_date, session_type, focus, is_committed, target_minutes
                 FROM prescribed_sessions
                 WHERE plan_version_id = ? AND session_date >= ?
                 ORDER BY session_date, is_committed DESC',
                [(int) $livePlan['id'], $today]
            );
            if ($rows === []) {
                $out[] = '  (none — the week is done)';
            }
            foreach ($rows as $r) {
                $out[] = sprintf(
                    '  %s: %s%s%s%s',
                    $r['session_date'],
                    $r['session_type'],
                    $r['focus'] ? " / {$r['focus']}" : '',
                    $r['target_minutes'] ? " / {$r['target_minutes']} min" : '',
                    (int) $r['is_committed'] === 1 ? '' : ' (optional)'
                );
            }
        } else {
            $out[] = '';
            $out[] = 'There is no plan for this week.';
        }

        /*
         * The constraints, stated as unchangeable.
         *
         * Included so the coach does not plan around a limit it has forgotten, and labelled
         * so it does not try to edit one. SPEC-safety §6: the control lives in one
         * deliberate place, and this is not it.
         */
        $constraints = DB::all(
            'SELECT kind, tier, subject, reason FROM user_constraints
             WHERE user_id = ? AND active = 1 ORDER BY tier, kind',
            [$userId]
        );
        if ($constraints !== []) {
            $out[] = '';
            $out[] = '=== THEIR CONSTRAINTS — you cannot change these from here ===';
            foreach ($constraints as $c) {
                $out[] = sprintf(
                    '  [%s] %s: %s%s',
                    $c['tier'], $c['kind'], $c['subject'],
                    $c['reason'] ? " ({$c['reason']})" : ''
                );
            }
            $out[] = 'If they say one of these no longer applies, suggest they review it in '
                   . 'their profile. Do not act as though it were lifted.';
        }

        $active = DB::all(
            'SELECT kind, detail, ends_on FROM circumstances
             WHERE user_id = ? AND active = 1
               AND (ends_on IS NULL OR ends_on >= ?)
             ORDER BY created_at DESC LIMIT 20',
            [$userId, $today]
        );
        if ($active !== []) {
            $out[] = '';
            $out[] = '=== CIRCUMSTANCES ALREADY ON FILE ===';
            foreach ($active as $c) {
                $span = $c['ends_on'] ? " (until {$c['ends_on']})" : ' (open-ended)';
                $out[] = "  [{$c['kind']}] {$c['detail']}{$span}";
            }
        }

        return implode("\n", $out);
    }
}
