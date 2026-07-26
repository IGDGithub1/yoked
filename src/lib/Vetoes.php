<?php
declare(strict_types=1);

/**
 * Vetoes (SPEC-coaching §5).
 *
 * "There's no 'nah, I don't want to do that workout' without a very good excuse."
 *
 * A veto is a user rejecting one prescribed thing — a meal, a session, a single exercise.
 * Four rules from the spec shape everything below:
 *
 *   §5.1 A REASON IS REQUIRED. No bare rejection; the reason is the whole value. Enforced
 *        in raise(), not in the UI, because the UI is not the only caller.
 *
 *   §5.2 SCOPE IS THE KEY DISTINCTION. "Thunderstorms, can't hike" replaces one instance.
 *        "I hate salmon, never again" also promotes to a SOFT constraint. Without the
 *        split the app either forgets a permanent dislike or reshuffles forever around a
 *        trip that ended weeks ago.
 *
 *   §5.3 REPLACE, DO NOT DELETE. A veto produces a new plan version with something that
 *        still serves the goal. "No time to cook Thursday" gets a faster meal hitting
 *        similar macros, not a dropped meal.
 *
 *   §5.4 CLAUDE MAY DECLINE. And a declined veto is still logged, because a user vetoing
 *        legs every Thursday for four weeks is a pattern to raise at the check-in rather
 *        than silently accommodate.
 *
 * THE ONE AUTOMATED CONSTRAINT WRITE PATH, AND WHY IT IS SAFE
 *
 * SPEC-safety §6 says constraints change only through deliberate profile edits, and names
 * a veto reason as something that must NOT edit one. §7 carves out the single exception:
 * a standing veto becomes a constraint, "and it only ever creates SOFT constraints, never
 * hard."
 *
 * That is enforced three ways here, deliberately redundantly:
 *
 *   1. promote() hardcodes the literal 'soft'. There is no tier parameter, so no caller
 *      can pass one and no model output can influence it.
 *   2. The INSERT names source = 'veto_promotion', also literal.
 *   3. A soft constraint is advisory by construction — Safety::validatePlan enforces hard
 *      constraints and merely reports soft ones. So even a promotion that should not have
 *      happened cannot block a plan; it biases one.
 *
 * The asymmetry is the point: this path can add a preference the user stated, and cannot
 * remove a limit they set. An LLM that can be argued out of a constraint has no
 * constraints — but one that can be told "stop suggesting salmon" is just listening.
 *
 * THE ROUTE/CRON SPLIT
 *
 * raise() records and returns. evaluate() decides, and when it accepts, regenerates the
 * week — which takes minutes. Same shape as Chat, for the same reason: no HTTP request
 * holds a plan generation open.
 */
final class Vetoes
{
    /** What can be refused. Mirrors vetoes.subject_type. */
    private const SUBJECTS = ['session', 'exercise', 'meal'];

    /** Mirrors vetoes.reason_code. A code plus free text: the code is for pattern
     *  detection (§5.4, "four vetoes in a year says nothing; four in a week says
     *  something"), the text is for the coach to actually read. */
    private const REASON_CODES = [
        'no_time', 'dont_like', 'cant_do', 'unwell', 'weather', 'travel',
        'equipment', 'other',
    ];

    private const SCOPES = ['today', 'standing'];

    /**
     * Rate limit.
     *
     * Higher than it sounds necessary because a legitimate bad week produces a burst — a
     * user who catches flu on Monday may refuse most of the week in one sitting, and being
     * throttled mid-honesty is the wrong lesson to teach. Low enough that a script cannot
     * spend the plan-generation budget: only ACCEPTED vetoes regenerate, and the
     * evaluation itself is a small call.
     */
    private const RATE_MAX    = 20;
    private const RATE_WINDOW = 3600;

    // ---- raising -------------------------------------------------------------

    /**
     * Record a veto. Decides nothing.
     *
     * Returns ['ok', 'error', 'veto_id']. The subject is verified to belong to the
     * caller's LIVE plan before anything is written: a client could otherwise veto an
     * arbitrary row id, and the only prescriptions it has any business refusing are the
     * ones it was just shown.
     */
    public static function raise(int $userId, array $body): array
    {
        if (!RateLimit::allow('veto:' . $userId, self::RATE_MAX, self::RATE_WINDOW)) {
            return ['ok' => false, 'veto_id' => null,
                    'error' => 'That is a lot of vetoes in one go. Try again a bit later.'];
        }

        $subjectType = Validate::enum($body['subject_type'] ?? null, self::SUBJECTS);
        $subjectId   = Validate::id($body['subject_id'] ?? null);
        $reasonCode  = Validate::enum($body['reason_code'] ?? null, self::REASON_CODES);
        $scope       = Validate::enum($body['scope'] ?? null, self::SCOPES) ?? 'today';

        if ($subjectType === null || $subjectId === null) {
            return ['ok' => false, 'veto_id' => null,
                    'error' => 'Say what you are turning down.'];
        }
        if ($reasonCode === null) {
            return ['ok' => false, 'veto_id' => null, 'error' => 'A reason is required.'];
        }

        /*
         * §5.1, enforced rather than encouraged.
         *
         * The code alone is not a reason — 'other' with no text is a bare rejection wearing
         * a label. So free text is required whenever the code does not already say
         * something specific, and 'dont_like' is in that set on purpose: "I don't like it"
         * is the least actionable thing a user can say, and the whole value of a standing
         * veto is knowing WHAT they dislike about it.
         */
        $needsText = in_array($reasonCode, ['other', 'dont_like', 'cant_do'], true);
        $text      = Validate::str($body['reason_text'] ?? null, 1, 500);
        if ($needsText && $text === null) {
            return ['ok' => false, 'veto_id' => null,
                    'error' => 'Tell your coach why, in a few words.'];
        }

        $subject = self::ownedSubject($userId, $subjectType, $subjectId);
        if ($subject === null) {
            return ['ok' => false, 'veto_id' => null,
                    'error' => 'That is not something in your current plan.'];
        }

        /*
         * A day that has already been lived cannot be refused.
         *
         * Not pedantry: accepting one would regenerate the past, and §3's "days BEFORE
         * today already happened" instruction exists precisely because rewriting history
         * makes the record useless. If they did not do it, that is a LOG, not a veto.
         */
        $tz = Baseline::timezoneOf($userId);
        if ($subject['day_date'] !== null
            && $subject['day_date'] < Schedule::today($tz)) {
            return ['ok' => false, 'veto_id' => null,
                    'error' => 'That day has already been and gone. Log what you actually did instead.'];
        }

        // One pending veto per subject. A double-tap must not queue two evaluations, each
        // of which would regenerate the week.
        $existing = DB::one(
            'SELECT id FROM vetoes
             WHERE user_id = ? AND subject_type = ? AND subject_id = ?
               AND outcome = "pending" LIMIT 1',
            [$userId, $subjectType, $subjectId]
        );
        if ($existing !== null) {
            return ['ok' => true, 'veto_id' => (int) $existing['id'], 'error' => null];
        }

        $vetoId = DB::insert(
            'INSERT INTO vetoes
                (user_id, subject_type, subject_id, reason_code, reason_text, scope)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $subjectType, $subjectId, $reasonCode, $text, $scope]
        );

        return ['ok' => true, 'veto_id' => (int) $vetoId, 'error' => null];
    }

    /**
     * Resolve a subject to its owning plan and date, or null if it is not the user's.
     *
     * Ownership is expressed in the WHERE clause rather than fetched-then-checked, which
     * is the same discipline the rest of the codebase uses: a query that cannot return
     * another user's row needs no follow-up guard to remember.
     */
    private static function ownedSubject(int $userId, string $type, int $id): ?array
    {
        if ($type === 'meal') {
            return DB::one(
                'SELECT pm.id, pm.slot AS label, pd.day_date, pv.id AS plan_version_id
                 FROM prescribed_meals pm
                 JOIN prescribed_days pd ON pd.id = pm.prescribed_day_id
                 JOIN plan_versions pv   ON pv.id = pd.plan_version_id
                 WHERE pm.id = ? AND pv.user_id = ? AND pv.superseded_at IS NULL',
                [$id, $userId]
            );
        }

        if ($type === 'session') {
            return DB::one(
                'SELECT ps.id, ps.session_type AS label, ps.session_date AS day_date,
                        pv.id AS plan_version_id
                 FROM prescribed_sessions ps
                 JOIN plan_versions pv ON pv.id = ps.plan_version_id
                 WHERE ps.id = ? AND pv.user_id = ? AND pv.superseded_at IS NULL',
                [$id, $userId]
            );
        }

        // The exercise's name lives in the `exercises` library, not on the prescription —
        // prescribed_exercises carries only an exercise_id. Joined so the label the model
        // reads is "Romanian deadlift" rather than a row number.
        return DB::one(
            'SELECT pe.id, e.name AS label, ps.session_date AS day_date,
                    pv.id AS plan_version_id
             FROM prescribed_exercises pe
             JOIN exercises e            ON e.id = pe.exercise_id
             JOIN prescribed_sessions ps ON ps.id = pe.session_id
             JOIN plan_versions pv       ON pv.id = ps.plan_version_id
             WHERE pe.id = ? AND pv.user_id = ? AND pv.superseded_at IS NULL',
            [$id, $userId]
        );
    }

    // ---- reading -------------------------------------------------------------

    /** The queue cron works through, oldest first. */
    public static function pending(int $limit = 20): array
    {
        return DB::all(
            'SELECT id, user_id FROM vetoes
             WHERE outcome = "pending"
             ORDER BY created_at LIMIT ' . max(1, min(100, $limit))
        );
    }

    /**
     * Vetoes on this week's plan, for the UI.
     *
     * Includes declined ones. A user who was told no needs to see that they were told no,
     * or they will simply ask again — and §5.4's pattern signal only exists because the
     * declines are visible rather than swallowed.
     */
    public static function forWeek(int $userId, string $weekStart): array
    {
        $rows = DB::all(
            'SELECT v.id, v.subject_type, v.subject_id, v.reason_code, v.reason_text,
                    v.scope, v.outcome, v.claude_response, v.promoted_constraint_id,
                    v.resulting_plan_version_id, v.created_at
             FROM vetoes v
             WHERE v.user_id = ? AND v.created_at >= ?
             ORDER BY v.created_at DESC LIMIT 100',
            [$userId, $weekStart . ' 00:00:00']
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'           => (int) $r['id'],
                'subject_type' => (string) $r['subject_type'],
                'subject_id'   => (int) $r['subject_id'],
                'reason_code'  => (string) $r['reason_code'],
                'reason_text'  => $r['reason_text'],
                'scope'        => (string) $r['scope'],
                'outcome'      => (string) $r['outcome'],
                'reply'        => $r['claude_response'],
                // Whether it became a standing preference. The user asked for permanence;
                // they should be able to see whether they got it.
                'promoted'     => $r['promoted_constraint_id'] !== null,
                'plan_changed' => $r['resulting_plan_version_id'] !== null,
                'at'           => (string) $r['created_at'],
            ];
        }
        return $out;
    }

    /** Is anything of the user's still waiting on a decision? Drives the pending UI. */
    public static function hasPending(int $userId): bool
    {
        return DB::one(
            'SELECT 1 AS x FROM vetoes WHERE user_id = ? AND outcome = "pending" LIMIT 1',
            [$userId]
        ) !== null;
    }

    // ---- the decision --------------------------------------------------------

    /**
     * Decide one veto, and act on it.
     *
     * The only path from a veto to a plan change, and it runs through a structured output
     * whose enum PHP re-checks before doing anything. Accepting regenerates the week;
     * declining writes a reply and nothing else.
     */
    public static function evaluate(int $userId, int $vetoId): array
    {
        $veto = DB::one(
            'SELECT * FROM vetoes WHERE id = ? AND user_id = ? AND outcome = "pending"',
            [$vetoId, $userId]
        );
        if ($veto === null) {
            return ['ok' => false, 'error' => 'No such veto.', 'outcome' => null,
                    'plan_version_id' => null, 'constraint_id' => null];
        }

        $subject = self::ownedSubject(
            $userId,
            (string) $veto['subject_type'],
            (int) $veto['subject_id']
        );

        /*
         * The subject went away between raising and evaluating.
         *
         * Possible in normal use: another veto, an interjection, or the Sunday generation
         * can supersede the plan while this one sits in the queue. There is nothing left
         * to replace, so close it out rather than regenerate against a stale target — and
         * do NOT mark it accepted, because nothing was done.
         */
        if ($subject === null) {
            DB::run(
                'UPDATE vetoes SET outcome = "declined", claude_response = ? WHERE id = ?',
                ['Your plan changed before I got to this, so there was nothing left to swap out.',
                 $vetoId]
            );
            return ['ok' => true, 'error' => null, 'outcome' => 'declined',
                    'plan_version_id' => null, 'constraint_id' => null];
        }

        $tz    = Baseline::timezoneOf($userId);
        $today = Schedule::today($tz);
        $week  = Schedule::weekStart($tz);

        /*
         * Is there a live plan to revise at all?
         *
         * The subject was found in one, so ordinarily yes. Read it anyway: the schema
         * offers 'accepted' only when a replacement can actually be produced, on the same
         * principle as Chat — never let the model choose an outcome PHP will silently
         * refuse to carry out.
         */
        $livePlan  = Plans::live($userId, $week);
        $canRevise = $livePlan !== null;

        $result = Claude::json(self::schema($canRevise), [
            'purpose'    => 'veto_replacement',
            'user_id'    => $userId,
            'max_tokens' => 2000,
            'system'     => self::systemPrompt($userId, $canRevise),
            'messages'   => [[
                'role'    => 'user',
                'content' => self::context($userId, $veto, $subject, $today),
            ]],
        ]);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Evaluation failed.',
                    'outcome' => null, 'plan_version_id' => null, 'constraint_id' => null];
        }

        $data  = $result['data'];
        $reply = Tone::clean((string) ($data['reply'] ?? ''));

        /*
         * PHP is the gate, not the prompt.
         *
         * The allowlist is restated literally here rather than derived from the schema, so
         * a schema edit cannot quietly widen what the code will act on, and the fallback is
         * the outcome that does nothing.
         */
        $outcome = Validate::enum($data['outcome'] ?? null, ['accepted', 'declined'])
            ?? 'declined';
        if (!$canRevise) {
            $outcome = 'declined';
        }

        if ($reply === '') {
            return ['ok' => false, 'error' => 'Empty reply.', 'outcome' => null,
                    'plan_version_id' => null, 'constraint_id' => null];
        }

        /*
         * §5.2: promotion happens only for an ACCEPTED STANDING veto.
         *
         * Both conditions matter. A declined veto must not leave a preference behind — the
         * coach held the line, and promoting anyway would hand the user the outcome they
         * were just refused. And a 'today' veto is explicitly not permanent.
         */
        $planVersionId = null;
        if ($outcome === 'accepted') {
            $gen = Plans::generateWeek($userId, $week, 'veto', [
                'veto' => [
                    'from_day'     => $today,
                    'subject'      => (string) $veto['subject_type'],
                    'subject_label' => (string) ($subject['label'] ?? ''),
                    'on_day'       => (string) ($subject['day_date'] ?? ''),
                    'reason_code'  => (string) $veto['reason_code'],
                    'said'         => (string) ($veto['reason_text'] ?? ''),
                    'scope'        => (string) $veto['scope'],
                    'replacement'  => (string) ($data['replacement'] ?? ''),
                ],
            ]);

            if ($gen['ok']) {
                $planVersionId = (int) $gen['plan_version_id'];
            } else {
                /*
                 * §5.3 promised a replacement and there is not one.
                 *
                 * Downgraded to declined rather than left claiming acceptance, and the
                 * reply is replaced too — the model's text says "here is your swap", which
                 * beside an unchanged plan is worse than admitting the failure.
                 */
                $outcome = 'declined';
                $reply   = 'I could not put a replacement together just now. Your plan is '
                         . 'unchanged, so nothing is lost. Try again in a bit.';
                error_log('[yoked] veto replacement failed for veto ' . $vetoId
                    . ': ' . (string) ($gen['error'] ?? 'unknown'));
            }
        }

        DB::ensureConnected();   // generation can outlive the 60s wait_timeout

        /*
         * §5.2: promotion happens only for an ACCEPTED STANDING veto.
         *
         * AFTER the generation, not before, and that ordering is the whole point. An earlier
         * version promoted first, and when the replacement then failed and downgraded the
         * outcome to 'declined', the soft constraint stayed behind: the user read "your plan
         * is unchanged" while a preference had quietly been written from a veto they were
         * told was refused. Caught by the live suite reporting `outcome: declined,
         * constraint: 417`.
         *
         * So $outcome here is the FINAL one. Both conditions still matter: a declined veto
         * must leave nothing behind, and a 'today' veto is explicitly not permanent.
         */
        $constraintId = null;
        if ($outcome === 'accepted' && (string) $veto['scope'] === 'standing') {
            $constraintId = self::promote($userId, $veto, $data);
        }

        DB::tx(function () use ($vetoId, $userId, $veto, $subject, $outcome, $reply,
                               $planVersionId, $constraintId): void {
            DB::run(
                'UPDATE vetoes
                    SET outcome = ?, claude_response = ?, resulting_plan_version_id = ?,
                        promoted_constraint_id = ?
                  WHERE id = ?',
                [$outcome, $reply, $planVersionId, $constraintId, $vetoId]
            );

            /*
             * §3: "a vetoed meal stays in the record, marked vetoed, with its reason and
             * replacement." The mark goes on the OLD row, which the regeneration has just
             * superseded. It is a tombstone on history, not an edit to the live plan.
             */
            if ($outcome === 'accepted') {
                self::markSubject(
                    (string) $veto['subject_type'],
                    (int) $veto['subject_id'],
                    $vetoId
                );
            }

            // §3's trigger_ref, which Plans::persist() does not write. Set here because
            // this is where the causing row is known.
            if ($planVersionId !== null) {
                DB::run(
                    'UPDATE plan_versions SET trigger_type = "veto", trigger_id = ?
                      WHERE id = ? AND user_id = ?',
                    [$vetoId, $planVersionId, $userId]
                );
            }
        });

        return ['ok' => true, 'error' => null, 'outcome' => $outcome,
                'plan_version_id' => $planVersionId, 'constraint_id' => $constraintId];
    }

    /** Tombstone the refused row. Table chosen by subject_type, never interpolated. */
    private static function markSubject(string $type, int $id, int $vetoId): void
    {
        $table = match ($type) {
            'meal'     => 'prescribed_meals',
            'session'  => 'prescribed_sessions',
            'exercise' => 'prescribed_exercises',
            default    => null,
        };
        if ($table === null) {
            return;
        }
        // A match over a closed set rather than string concatenation of caller input: the
        // only values reaching here are the three the ENUM permits.
        DB::run("UPDATE {$table} SET vetoed_by_id = ? WHERE id = ?", [$vetoId, $id]);
    }

    // ---- the one automated constraint write ----------------------------------

    /**
     * Promote a standing veto to a SOFT constraint (SPEC-safety §7).
     *
     * NOTE WHAT THIS FUNCTION CANNOT DO. There is no tier parameter; 'soft' is a literal.
     * There is no way to reach an existing constraint from here — no UPDATE, no DELETE, no
     * lookup by subject that could collide with a hard one. It inserts, or it does nothing.
     * So the worst a compromised model output can achieve is an unwanted soft preference,
     * which the user can see in their profile and switch off.
     *
     * Returns the new constraint id, or null if the model gave nothing worth storing.
     */
    private static function promote(int $userId, array $veto, array $data): ?int
    {
        $c = $data['constraint'] ?? null;
        if (!is_array($c)) {
            return null;
        }

        // Only the kinds a veto can sensibly produce. 'condition' is absent deliberately:
        // a medical condition is not something a user refuses a salmon fillet into
        // existence, and it is the kind whose guidance drives hard safety behaviour.
        $kind    = Validate::enum($c['kind'] ?? null, ['food', 'movement', 'cardio', 'equipment']);
        $subject = Validate::str($c['subject'] ?? null, 1, 120);
        if ($kind === null || $subject === null) {
            return null;
        }

        $reason = Validate::str($c['reason'] ?? null, 1, 500)
            ?? Validate::str($veto['reason_text'] ?? null, 1, 500)
            ?? 'Turned down and asked not to see it again.';

        /*
         * Already on file?
         *
         * Checked so a user refusing salmon three times gets one preference rather than
         * three. Matched case-insensitively on subject within the same kind. Crucially this
         * is a read that can only cause the function to RETURN EARLY — it never updates the
         * row it finds, so a hard constraint with the same subject is left completely
         * untouched and its id is not returned as though this veto had created it.
         */
        $dupe = DB::one(
            'SELECT id, tier FROM user_constraints
             WHERE user_id = ? AND kind = ? AND LOWER(subject) = LOWER(?) AND active = 1
             LIMIT 1',
            [$userId, $kind, $subject]
        );
        if ($dupe !== null) {
            return (string) $dupe['tier'] === 'soft' ? (int) $dupe['id'] : null;
        }

        return DB::tx(function () use ($userId, $kind, $subject, $reason): int {
            $id = (int) DB::insert(
                // tier and source are LITERALS. Not parameters, not variables, not
                // anything the model can reach. SPEC-safety §7: veto_promotion "only ever
                // creates SOFT constraints, never hard."
                'INSERT INTO user_constraints
                    (user_id, kind, tier, subject, reason, source, active)
                 VALUES (?, ?, "soft", ?, ?, "veto_promotion", 1)',
                [$userId, $kind, $subject, $reason]
            );

            // Audited, so a plan can always be explained after the fact. Onboarding's
            // inserts skip this; a write triggered by a model decision must not.
            DB::insert(
                'INSERT INTO user_constraint_audit
                    (constraint_id, user_id, action, old_value, new_value)
                 VALUES (?, ?, "create", NULL, ?)',
                [$id, $userId, json_encode([
                    'kind' => $kind, 'tier' => 'soft', 'subject' => $subject,
                    'reason' => $reason, 'source' => 'veto_promotion',
                    'via' => 'standing veto',
                ], JSON_UNESCAPED_SLASHES)]
            );

            return $id;
        });
    }

    // ---- the model call ------------------------------------------------------

    /**
     * The decision schema.
     *
     * 'accepted' is withheld entirely when there is no live plan to revise, the same
     * device Chat uses: better than letting the model pick an outcome PHP would refuse.
     */
    private static function schema(bool $canRevise): array
    {
        $properties = [
            'outcome' => [
                'type' => 'string',
                'enum' => $canRevise ? ['accepted', 'declined'] : ['declined'],
                'description' => $canRevise
                    ? 'accepted if the reason justifies a swap; declined if it does not.'
                    : 'declined. There is no live plan to change.',
            ],
            'reply' => [
                'type' => 'string',
                'description' => 'Two or three sentences to the user, second person. '
                    . 'If declining, say why, plainly and without lecturing. '
                    . 'No em dashes.',
            ],
        ];
        $required = ['outcome', 'reply'];

        if ($canRevise) {
            $properties['replacement'] = [
                'type' => 'string',
                'description' => 'What should take its place, and why it still serves the '
                    . 'goal. A faster meal at similar macros, a different movement for the '
                    . 'same pattern. Never a deletion. Empty if declining.',
            ];
            $required[] = 'replacement';

            /*
             * The promotion payload, offered only when the veto was raised as standing.
             * Optional even then: the model may accept a swap without concluding anything
             * permanent, and an empty object is how it says so.
             */
            $properties['constraint'] = [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'kind' => [
                        'type' => 'string',
                        'enum' => ['food', 'movement', 'cardio', 'equipment'],
                    ],
                    'subject' => [
                        'type' => 'string',
                        'description' => 'The specific thing to stop suggesting. '
                            . '"salmon", not "fish I dislike".',
                    ],
                    'reason' => ['type' => 'string'],
                ],
                'required' => ['kind', 'subject', 'reason'],
                'description' => 'Only for a standing veto you accepted, and only when a '
                    . 'lasting preference is genuinely what they expressed. This becomes a '
                    . 'soft preference on their profile.',
            ];
        }

        /*
         * The BARE schema object, not wrapped in {name, schema}.
         *
         * Claude::json does that wrapping itself when it builds output_config.format. Passing
         * a pre-wrapped one produces a 400 whose message names the outer object as having no
         * type, which reads like a malformed property rather than one layer too many.
         */
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => $properties,
            'required'             => $required,
        ];
    }

    /** The system prompt. */
    private static function systemPrompt(int $userId, bool $canRevise): string
    {
        $profile = DB::one('SELECT tone FROM profiles WHERE user_id = ?', [$userId]);
        $tone    = Tone::brief((string) ($profile['tone'] ?? 'straight'));

        $out = [
            'You are the user\'s coach. They have turned down one thing you prescribed and '
            . 'given a reason. Decide whether the reason justifies it.',
            '',
            $tone,
            '',
            'ACCEPT when the reason is a fact about their circumstances: no time, no '
            . 'equipment, weather, travel, illness, pain, or a genuine lasting dislike. '
            . 'These are the reasons a real coach works around without argument.',
            '',
            'DECLINE when the reason is reluctance wearing a fact\'s clothes. "Not feeling '
            . 'it", "too hard", "I would rather do arms" are not circumstances. Say so '
            . 'kindly and briefly, and hold the line. Do not lecture, do not bargain, and '
            . 'do not re-litigate something you already declined.',
            '',
            'When you accept, REPLACE, never delete. The replacement still has to serve the '
            . 'goal: a faster meal at similar macros, a different movement for the same '
            . 'pattern, an easier session rather than no session. Dropping it is not a '
            . 'replacement.',
        ];

        if (!$canRevise) {
            $out[] = '';
            $out[] = 'There is no live plan this week, so you cannot swap anything out. '
                   . 'Acknowledge what they said and tell them it is noted.';
        }

        $out[] = '';
        $out[] = 'NEVER use em dashes or en dashes. Use a comma or a full stop.';

        // Hard constraints are stated so a replacement cannot violate one, and so the model
        // knows what it must not offer to relax.
        $out[] = '';
        $out[] = Safety::promptBlock($userId);
        $out[] = '';
        $out[] = 'You cannot lift a constraint from a veto reason. If they say a limit no '
               . 'longer applies, tell them to change it in their profile.';

        return implode("\n", $out);
    }

    /** What the model is deciding about. */
    private static function context(
        int $userId,
        array $veto,
        array $subject,
        string $today
    ): string {
        $out = [
            '=== WHAT THEY TURNED DOWN ===',
            sprintf(
                '  A %s (%s) on %s.',
                (string) $veto['subject_type'],
                (string) ($subject['label'] ?? 'unknown'),
                (string) ($subject['day_date'] ?? 'unknown date')
            ),
            '  Reason code: ' . (string) $veto['reason_code'],
            '  In their words: ' . (($veto['reason_text'] ?? '') !== ''
                ? (string) $veto['reason_text'] : '(none given)'),
            '  Scope: ' . ((string) $veto['scope'] === 'standing'
                ? 'STANDING. They are asking never to see this again, not just today.'
                : 'today only.'),
            '',
            'Today is ' . $today . '.',
        ];

        /*
         * §5.4's pattern signal.
         *
         * "A user vetoing legs every Thursday for four weeks is a pattern to address, not
         * silently accommodate." The model cannot see that unless it is told, so the recent
         * history goes in — declines included, which is the half that reveals repetition.
         */
        $history = DB::all(
            'SELECT subject_type, reason_code, reason_text, scope, outcome, created_at
             FROM vetoes
             WHERE user_id = ? AND id <> ? AND created_at >= (NOW() - INTERVAL 60 DAY)
             ORDER BY created_at DESC LIMIT 15',
            [$userId, (int) $veto['id']]
        );
        if ($history !== []) {
            $out[] = '';
            $out[] = '=== WHAT THEY HAVE TURNED DOWN BEFORE (60 days) ===';
            foreach ($history as $h) {
                $out[] = sprintf(
                    '  %s  %s [%s] %s -> %s',
                    substr((string) $h['created_at'], 0, 10),
                    (string) $h['subject_type'],
                    (string) $h['reason_code'],
                    (string) ($h['reason_text'] ?? ''),
                    (string) $h['outcome']
                );
            }
            $out[] = 'A repeated refusal of the same thing is a pattern. If you see one, '
                   . 'name it in your reply rather than accommodating it silently again.';
        }

        return implode("\n", $out);
    }
}
