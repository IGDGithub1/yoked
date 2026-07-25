<?php
declare(strict_types=1);

/**
 * The weekly check-in (SPEC-coaching §7.2).
 *
 * End of week, the full pass: weight, six measurements, the user's own read on the
 * week, and optionally an emphasis request (§7.2a, the adherence dividend). Claude
 * gets those plus the week's adherence and daily check-ins, and produces a review.
 *
 * THE TIMING IS THE DESIGN. Cron opens the check-in Saturday 18:00 local and the
 * plan generates Sunday 18:00, so the user has about 24 hours to answer something
 * that will actually shape the coming week. Before this the check-in opened Monday
 * 00:00, six hours AFTER the plan it was meant to inform, which made §7.2
 * unimplementable.
 *
 * A user who does not answer in time is not punished for it:
 *
 *   answered in the window  → the plan is built with it
 *   not answered            → the plan is built without it, reason = 'initial'
 *                             or 'check_in' depending on their history
 *   answered afterwards     → still goes to Claude, WITH the plan that already
 *                             generated. Claude either banks the answers for next
 *                             week or supersedes the plan (reason = 'check_in').
 *
 * That last branch is why the late path exists at all. Most weeks a late check-in
 * changes nothing. But "I broke my leg on Saturday" has to be able to reach a plan
 * that was written on Sunday, and asking Claude is the only way to tell those apart
 * without hardcoding a rule about what counts as serious.
 *
 * After the week has actually STARTED (Monday 00:00 local) the late path closes.
 * The answers are still stored and still reviewed, but a plan change from that
 * point is mid-week drift adaptation (§7.1), which is a different mechanism built
 * for exactly that.
 */
final class CheckIn
{
    /** Six measurements, in the order the UI shows them. Waist first: §7.2. */
    public const MEASUREMENTS = ['waist_cm', 'hips_cm', 'chest_cm', 'arm_cm', 'thigh_cm', 'neck_cm'];

    /**
     * Convert from whatever the user thinks in.
     *
     * The columns are metric and the UI shows the user's own units, exactly as
     * onboarding does (Onboarding::saveSection converts on the way in). Doing it
     * server-side is the point: if the client converted, a client that got it
     * wrong would store 180 kg for a 180 lb user and nothing downstream could tell.
     *
     * @param string $kind 'weight' or 'length'
     */
    private static function toMetric(float $v, string $units, string $kind): float
    {
        if ($units === 'metric') {
            return $v;
        }
        return $kind === 'weight'
            ? $v * 0.45359237   // lb → kg
            : $v * 2.54;        // in → cm
    }

    /**
     * Open a check-in for a week, if one does not exist.
     *
     * Idempotent by the unique key on (user_id, week_start): a concurrent sweep
     * loses the insert and reads the winner's row.
     */
    public static function open(int $userId, string $weekStart): ?array
    {
        try {
            DB::insert(
                'INSERT INTO weekly_checkins (user_id, week_start, status)
                 VALUES (?, ?, "pending")',
                [$userId, $weekStart]
            );
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }
        return self::find($userId, $weekStart);
    }

    public static function find(int $userId, string $weekStart): ?array
    {
        return DB::one(
            'SELECT * FROM weekly_checkins WHERE user_id = ? AND week_start = ?',
            [$userId, $weekStart]
        );
    }

    /**
     * The check-in the user should be looking at right now, if any.
     *
     * The most recent pending one. Deliberately not filtered by week: a user who
     * ignored last week's check-in and opens the app on Wednesday should still see
     * it, because an unanswered check-in is the thing the nudge is about.
     */
    public static function current(int $userId): ?array
    {
        return DB::one(
            'SELECT * FROM weekly_checkins
             WHERE user_id = ? AND status = "pending"
             ORDER BY week_start DESC LIMIT 1',
            [$userId]
        );
    }

    /**
     * An answered check-in for a week, or null.
     *
     * What plan generation asks before deciding whether it has the user's input.
     * Only 'completed' counts: a pending row is an unanswered question, and a
     * skipped one is a deliberate pass.
     */
    public static function answeredFor(int $userId, string $weekStart): ?array
    {
        return DB::one(
            'SELECT * FROM weekly_checkins
             WHERE user_id = ? AND week_start = ? AND status = "completed"',
            [$userId, $weekStart]
        );
    }

    /**
     * Store the user's answers.
     *
     * Every field is optional. A check-in that says only "knee felt off on
     * Thursday" is worth more than one nobody filled in, and demanding six
     * measurements is how you get zero check-ins by week four.
     *
     * @return array{ok:bool, error:?string, checkin:?array, late:bool}
     */
    public static function answer(int $userId, int $checkinId, array $body, ?string $tz = null): array
    {
        $row = DB::one(
            'SELECT * FROM weekly_checkins WHERE id = ? AND user_id = ?',
            [$checkinId, $userId]
        );
        if ($row === null) {
            return ['ok' => false, 'error' => 'No such check-in.', 'checkin' => null, 'late' => false];
        }
        if ((string) $row['status'] === 'completed') {
            return ['ok' => false, 'error' => 'That check-in is already answered.',
                    'checkin' => $row, 'late' => false];
        }

        // The user enters their own units; the columns are metric.
        $units = (string) (DB::one('SELECT units FROM profiles WHERE user_id = ?', [$userId])['units']
                           ?? 'imperial');
        $imperial = $units !== 'metric';

        $fields = [];

        if (array_key_exists('weight_kg', $body)) {
            /*
             * Validated in the USER's units, then converted.
             *
             * Ranges are generous on purpose: this is a number read off a scale,
             * and refusing a real bodyweight for looking unusual is worse than
             * storing it. The bounds only exist to catch a decimal-point slip.
             */
            $v = null;
            if ($body['weight_kg'] !== null) {
                $lo = $imperial ? 45.0 : 20.0;
                $hi = $imperial ? 880.0 : 400.0;
                $raw = Validate::floatRange($body['weight_kg'], $lo, $hi);
                if ($raw === null) {
                    return ['ok' => false, 'error' => 'That weight does not look right.',
                            'checkin' => $row, 'late' => false];
                }
                $v = round(self::toMetric($raw, $units, 'weight'), 2);
            }
            $fields['weight_kg'] = $v;
        }

        foreach (self::MEASUREMENTS as $m) {
            if (!array_key_exists($m, $body)) {
                continue;
            }
            $v = null;
            if ($body[$m] !== null) {
                $lo = $imperial ? 4.0 : 10.0;
                $hi = $imperial ? 120.0 : 300.0;
                $raw = Validate::floatRange($body[$m], $lo, $hi);
                if ($raw === null) {
                    $label = str_replace('_cm', '', $m);
                    return ['ok' => false, 'error' => "That {$label} measurement does not look right.",
                            'checkin' => $row, 'late' => false];
                }
                $v = round(self::toMetric($raw, $units, 'length'), 1);
            }
            $fields[$m] = $v;
        }

        if (array_key_exists('self_report', $body)) {
            $fields['self_report'] = Validate::str($body['self_report'], 1, 4000);
        }
        if (array_key_exists('emphasis_request', $body)) {
            $fields['emphasis_request'] = Validate::str($body['emphasis_request'], 1, 2000);
        }

        /*
         * Late = the plan this check-in was meant to shape already exists.
         *
         * Compared against the PLAN, not against a clock. The user's slot is Sunday
         * 18:00, but a cron sweep can be delayed, a user can be in a zone where the
         * boundary falls oddly, and what actually matters is whether there is
         * already a plan their answers could have shaped.
         *
         * Keyed on the CHECK-IN's own plan week rather than "the coming week", so
         * this agrees with canStillAlterPlan(). The first version asked about
         * nextMonday() here and about weekStart+7 there, which meant a month-old
         * check-in could report late = true while being unable to alter anything —
         * true individually, incoherent together.
         */
        $planWeek = self::planWeekFor((string) $row['week_start']);
        $late     = Plans::live($userId, $planWeek) !== null;

        $sets   = [];
        $params = [];
        foreach ($fields as $col => $val) {
            $sets[]   = "{$col} = ?";
            $params[] = $val;
        }
        $sets[] = 'status = "completed"';
        $sets[] = 'answered_at = NOW()';
        $sets[] = 'answered_late = ' . ($late ? '1' : '0');
        $params[] = $checkinId;

        DB::run(
            'UPDATE weekly_checkins SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );

        return [
            'ok' => true, 'error' => null, 'late' => $late,
            'checkin' => DB::one('SELECT * FROM weekly_checkins WHERE id = ?', [$checkinId]),
        ];
    }

    /** A deliberate pass. Distinct from ignoring it, and it stops the nudges. */
    public static function skip(int $userId, int $checkinId): bool
    {
        return DB::run(
            'UPDATE weekly_checkins SET status = "skipped", answered_at = NOW()
             WHERE id = ? AND user_id = ? AND status = "pending"',
            [$checkinId, $userId]
        )->rowCount() > 0;
    }

    /**
     * Is the late-alteration window still open?
     *
     * A check-in covering week W was meant to shape the plan for W+7. It can still
     * alter that plan right up until W+7 actually begins; after that, changing the
     * plan is mid-week drift adaptation (§7.1), which is the mechanism built for
     * "something changed mid-week". Two code paths reaching the same outcome is how
     * they drift apart.
     *
     * A STALE check-in therefore cannot alter anything, and that is correct rather
     * than a gap: a check-in for a month-old week has no live plan to be late for,
     * and its answers are banked for the next generation instead.
     *
     * The subtlety that bit the first version of this: `answer()` decides LATENESS
     * against the coming week's plan, while this decides ALTERABILITY against the
     * check-in's own W+7. Those are the same thing for a current check-in and
     * different for an old one, so both live here with the distinction spelled out.
     */
    public static function canStillAlterPlan(string $weekStart, ?string $tz = null): bool
    {
        $planWeek = self::planWeekFor($weekStart);
        return Schedule::today($tz) < $planWeek;
    }

    /** The Monday of the week a check-in for $weekStart was meant to shape. */
    public static function planWeekFor(string $weekStart): string
    {
        return date('Y-m-d', strtotime($weekStart . ' +7 days'));
    }

    // ---- the Claude pass ----------------------------------------------------

    /**
     * Review an answered check-in.
     *
     * Two jobs in one call, because they need the same context and the answer to
     * the second depends on the first:
     *
     *   1. A read on the week, which the user sees.
     *   2. For a LATE check-in, whether the already-generated plan should change.
     *
     * Deliberately NOT a keyword heuristic. "Broke my leg" is obvious, but "my
     * knee felt off on Thursday" and "work is getting busier" are judgment calls,
     * and SPEC is explicit that Claude decides and the app holds the truth. Cost is
     * one call per answered check-in, which is once a week per user.
     */
    public static function review(int $userId, int $checkinId, ?string $tz = null): array
    {
        $checkin = DB::one('SELECT * FROM weekly_checkins WHERE id = ? AND user_id = ?',
            [$checkinId, $userId]);
        if ($checkin === null) {
            return ['ok' => false, 'error' => 'No such check-in.'];
        }

        $weekStart = (string) $checkin['week_start'];
        $late      = (int) $checkin['answered_late'] === 1;
        $canAlter  = $late && self::canStillAlterPlan($weekStart, $tz);
        $planWeek  = self::planWeekFor($weekStart);
        $livePlan  = $late ? Plans::live($userId, $planWeek) : null;

        $result = Claude::json(self::schema($canAlter), [
            'purpose'    => 'weekly_review',
            'user_id'    => $userId,
            'max_tokens' => 4000,
            'system'     => self::systemPrompt($userId, $canAlter),
            'messages'   => [[
                'role'    => 'user',
                'content' => self::userPrompt($userId, $checkin, $livePlan, $canAlter),
            ]],
        ]);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Review failed.'];
        }

        $data = $result['data'];

        // Store the review. The user sees this whether or not the plan changed.
        DB::run(
            'UPDATE weekly_checkins SET claude_review = ?, completed_at = NOW() WHERE id = ?',
            [Tone::clean((string) ($data['review'] ?? '')), $checkinId]
        );

        // The emphasis request, granted or not. Declined ones are kept: a user with
        // poor adherence asking to drop what they have been skipping is a
        // conversation, and the record is what makes that conversation possible.
        if (($checkin['emphasis_request'] ?? null) !== null && isset($data['emphasis'])) {
            DB::run(
                'INSERT INTO emphasis_grants
                    (user_id, checkin_id, request, decision, reasoning, active)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $userId, $checkinId, (string) $checkin['emphasis_request'],
                    Validate::enum($data['emphasis']['decision'] ?? null,
                        ['granted', 'declined', 'partial']) ?? 'declined',
                    (string) ($data['emphasis']['reasoning'] ?? ''),
                    ($data['emphasis']['decision'] ?? '') === 'declined' ? 0 : 1,
                ]
            );
        }

        return [
            'ok'          => true,
            'error'       => null,
            'review'      => Tone::clean((string) ($data['review'] ?? '')),
            'alter_plan'  => $canAlter && (bool) ($data['alter_plan'] ?? false),
            'alter_reason' => $data['alter_reason'] ?? null,
            'usage'       => $result['usage'] ?? [],
        ];
    }

    /** Record what became of a late check-in. */
    public static function recordLateOutcome(int $checkinId, string $outcome, ?int $planVersionId): void
    {
        DB::run(
            'UPDATE weekly_checkins SET late_outcome = ?, plan_version_id = COALESCE(?, plan_version_id)
             WHERE id = ?',
            [$outcome === 'altered' ? 'altered' : 'banked', $planVersionId, $checkinId]
        );
    }

    /** Link a check-in to the plan it fed. */
    public static function linkPlan(int $checkinId, int $planVersionId): void
    {
        DB::run('UPDATE weekly_checkins SET plan_version_id = ? WHERE id = ?',
            [$planVersionId, $checkinId]);
    }

    // ---- prompt construction -----------------------------------------------

    /**
     * The response shape.
     *
     * Structured output rather than prose parsing: "did Claude say to change the
     * plan" cannot be a regex over an essay. The alter fields only exist when
     * altering is actually possible, so the model is never offered a decision it
     * has no authority to make.
     */
    private static function schema(bool $canAlter): array
    {
        $props = [
            'review' => [
                'type' => 'string',
                'description' => 'Your read on the week, addressed to the user in their '
                    . 'coaching tone. Progress against the goal, what worked, what did not, '
                    . 'and what changes next week. A few short paragraphs.',
            ],
        ];
        $required = ['review'];

        if ($canAlter) {
            $props['alter_plan'] = [
                'type' => 'boolean',
                'description' => 'TRUE only if something in this check-in makes the '
                    . 'already-generated plan for the coming week wrong or unsafe: an injury, '
                    . 'lost access to a gym or kitchen, illness, travel. A week that simply '
                    . 'went poorly is NOT a reason to regenerate.',
            ];
            $props['alter_reason'] = [
                'type' => 'string',
                'description' => 'If alter_plan is true, the specific fact that requires the '
                    . 'change, phrased as an instruction to the plan generator.',
            ];
            $required[] = 'alter_plan';
        }

        $props['emphasis'] = [
            'type' => 'object',
            'description' => 'Only when the user made an emphasis request.',
            'properties' => [
                'decision'  => ['type' => 'string', 'enum' => ['granted', 'declined', 'partial']],
                'reasoning' => ['type' => 'string'],
            ],
            'required' => ['decision', 'reasoning'],
            'additionalProperties' => false,
        ];

        // The bare schema object, matching PlanSchema::build(). Claude::json()
        // wraps it in output_config.format itself, so a {name, schema} envelope
        // here would nest one level too deep and 400.
        return [
            'type' => 'object',
            // Required by the current model family on EVERY object, including the
            // nested one above. PlanSchema::lint() exists because getting this
            // wrong is a request-time 400 rather than a test failure.
            'additionalProperties' => false,
            'required' => $required,
            'properties' => $props,
        ];
    }

    private static function systemPrompt(int $userId, bool $canAlter): string
    {
        $profile = DB::one(
            'SELECT tone, explanation_depth FROM profiles WHERE user_id = ?',
            [$userId]
        ) ?? [];

        $lines = [
            'You are this user\'s coach, writing their weekly check-in review.',
            '',
            'Tone: ' . ($profile['tone'] ?? 'friendly_encouraging')
                . '. Explanation depth: ' . ($profile['explanation_depth'] ?? 'brief') . '.',
            '',
            'Rules that are not negotiable:',
            '- TREND OVER POINTS. Weight moves for a dozen reasons daily. Never read a '
                . 'single measurement as progress or failure.',
            '- A bad week adjusts the plan. It never adds penance. Do not prescribe extra '
                . 'work to make up for missed work.',
            '- Macro adherence outranks menu adherence. Someone who ignored the menu and hit '
                . 'their macros did well.',
            '- Optional sessions are a bonus and never a debt. Skipping every optional day is '
                . 'still a perfect week.',
            '- Address absence, never shame a logged bad day. A logged bad day is a success.',
        ];

        if ($canAlter) {
            $lines[] = '';
            $lines[] = 'This check-in arrived AFTER next week\'s plan was already generated. '
                . 'You are deciding whether the plan has to change. Set alter_plan TRUE only '
                . 'for a fact that makes the existing plan wrong or unsafe: an injury, illness, '
                . 'lost access to equipment or a kitchen, travel. A week that went badly is not '
                . 'such a fact. Most late check-ins should be FALSE.';
        }

        return implode("\n", $lines);
    }

    private static function userPrompt(int $userId, array $checkin, ?array $livePlan, bool $canAlter): string
    {
        $weekStart = (string) $checkin['week_start'];
        $out = ["=== THE WEEK OF {$weekStart} ==="];

        // What the user said.
        $out[] = '';
        $out[] = '--- Their own report ---';

        /*
         * Rendered in the USER's units, not the storage units.
         *
         * The columns are metric and the conversion on the way IN was right, but this
         * prompt handed Claude "113.85 kg" for an imperial user and the review came
         * back quoting kilograms at someone who thinks in pounds. Converting on input
         * and forgetting the output is a whole-round-trip mistake: the numbers were
         * correct and the coach still sounded like it was talking about someone else.
         */
        $units  = (string) (DB::one('SELECT units FROM profiles WHERE user_id = ?', [$userId])['units']
                            ?? 'imperial');
        $metric = $units === 'metric';
        $wUnit  = $metric ? 'kg' : 'lb';
        $lUnit  = $metric ? 'cm' : 'in';

        $toW = static fn(float $kg): float => round($metric ? $kg : $kg / 0.45359237, 1);
        $toL = static fn(float $cm): float => round($metric ? $cm : $cm / 2.54, 1);

        if (($checkin['weight_kg'] ?? null) !== null) {
            $out[] = "Weight: {$toW((float) $checkin['weight_kg'])} {$wUnit}";
        }
        $ms = [];
        foreach (self::MEASUREMENTS as $m) {
            if (($checkin[$m] ?? null) !== null) {
                $ms[] = str_replace('_cm', '', $m)
                    . ': ' . $toL((float) $checkin[$m]) . " {$lUnit}";
            }
        }
        if ($ms !== []) {
            $out[] = 'Measurements: ' . implode(', ', $ms);
        }

        // Stated explicitly, because the model will otherwise reach for whichever
        // unit the numbers look like they belong to.
        $out[] = "(This user thinks in {$units} units. Use {$wUnit} and {$lUnit} "
               . 'throughout, and never mention the other system.)';
        $out[] = ($checkin['self_report'] ?? null) !== null
            ? "They said: {$checkin['self_report']}"
            : 'They left the written report blank.';
        if (($checkin['emphasis_request'] ?? null) !== null) {
            $out[] = '';
            $out[] = 'EMPHASIS REQUEST (§7.2a — weigh against their adherence): '
                . $checkin['emphasis_request'];
        }

        // The trend, which is the thing that actually matters.
        $out[] = '';
        $out[] = '--- Weight and measurement history, newest first ---';
        $hist = DB::all(
            'SELECT week_start, weight_kg, waist_cm, hips_cm, chest_cm, arm_cm, thigh_cm, neck_cm
             FROM weekly_checkins
             WHERE user_id = ? AND status = "completed" AND weight_kg IS NOT NULL
             ORDER BY week_start DESC LIMIT 12',
            [$userId]
        );
        if ($hist === []) {
            $out[] = 'No previous check-ins with a weight. This is the first data point, so '
                . 'there is no trend yet and you must not invent one.';
        } else {
            foreach ($hist as $h) {
                // Same conversion as above: the history is stored metric and read in
                // whatever the user thinks in.
                $bits = ["{$h['week_start']}: " . $toW((float) $h['weight_kg']) . " {$wUnit}"];
                if ($h['waist_cm'] !== null) {
                    $bits[] = 'waist ' . $toL((float) $h['waist_cm']) . " {$lUnit}";
                }
                $out[] = '  ' . implode(', ', $bits);
            }
        }

        // Adherence, computed rather than asked about.
        $out[] = '';
        $out[] = '--- What they actually did that week ---';
        $days = DB::all(
            'SELECT log_date, macro_on_target, macro_short_but_ok, failure_count,
                    sessions_prescribed, sessions_completed,
                    energy, sleep_hours, sleep_quality, soreness, mood, notes
             FROM logged_days
             WHERE user_id = ? AND log_date >= ? AND log_date < DATE_ADD(?, INTERVAL 7 DAY)
             ORDER BY log_date',
            [$userId, $weekStart, $weekStart]
        );
        if ($days === []) {
            $out[] = 'Nothing logged at all that week.';
        } else {
            foreach ($days as $d) {
                $bits = [(string) $d['log_date']];
                if ($d['sessions_prescribed'] !== null) {
                    $bits[] = "training {$d['sessions_completed']}/{$d['sessions_prescribed']} committed";
                }
                if ($d['macro_on_target'] !== null) {
                    $bits[] = 'macros ' . ((int) $d['macro_on_target'] === 1
                        ? ((int) $d['macro_short_but_ok'] === 1 ? 'on target (calories short)' : 'on target')
                        : "off by {$d['failure_count']}");
                }
                foreach (['energy' => 'energy', 'soreness' => 'soreness', 'mood' => 'mood'] as $k => $label) {
                    if ($d[$k] !== null) {
                        $bits[] = "{$label} {$d[$k]}/5";
                    }
                }
                if ($d['sleep_hours'] !== null) {
                    $bits[] = "slept {$d['sleep_hours']}h";
                }
                if (($d['notes'] ?? null) !== null) {
                    $bits[] = "note: {$d['notes']}";
                }
                $out[] = '  ' . implode(' | ', $bits);
            }
        }

        // The plan under review, when there is one to alter.
        if ($canAlter && $livePlan !== null) {
            $out[] = '';
            $out[] = '--- The plan already generated for the coming week ---';
            $out[] = "Week of {$livePlan['week_start']}, version {$livePlan['version']}.";
            if (($livePlan['summary'] ?? null) !== null) {
                $out[] = "Summary: {$livePlan['summary']}";
            }
            $out[] = '';
            $out[] = 'Decide whether anything in the report above makes this plan wrong or '
                . 'unsafe. If it does not, say so by setting alter_plan false; the answers are '
                . 'still recorded and will shape the following week.';
        }

        return implode("\n", $out);
    }
}
