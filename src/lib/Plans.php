<?php
declare(strict_types=1);

/**
 * Plan generation (SPEC-coaching.md §2, §3).
 *
 * Turns a user's profile, constraints, availability, and history into a week —
 * then validates it against hard constraints and persists it as a new
 * plan_versions row with its prescriptions.
 *
 * The load-bearing decision is versioning: a plan is never mutated. Adherence
 * needs a stable referent ("did they follow the plan?" is meaningless if the
 * plan silently changed), Claude needs to see WHY a plan changed mid-week to
 * coach the next one, and chat can change plans — so changes are frequent and
 * every one must be attributable.
 */
final class Plans
{
    /** Prompt-cache the stable profile; only pays off above ~1024 tokens. */
    private const CACHE_SYSTEM = true;

    /**
     * Output ceiling for a week.
     *
     * A week is seven days of prescribed sessions with per-exercise detail PLUS
     * seven days of meals with structured ingredients, and measured runs land at
     * 22k-31k output tokens. This was 32000, which truncated two of six recorded
     * generations — and a truncation is not a degraded plan, it is no plan: the
     * JSON is incomplete, so nothing parses and nothing persists.
     *
     * Public so bin/aicalls.php can report headroom and test-claude can assert
     * the value, rather than either of them hardcoding a copy that drifts.
     *
     * Costs nothing when unused: max_tokens is a ceiling, and output is billed
     * per token actually emitted. Check bin/aicalls.php before lowering it.
     */
    public const MAX_OUTPUT_TOKENS = 64000;

    /**
     * Graft a retry's answer onto the previous, more complete one.
     *
     * A retry that is told "the Tuesday lunch has no ingredients" answers with
     * the corrected Tuesday, not with the whole week — measured at 10k-13k
     * output tokens against the 28k the first attempt produced. Taking the last
     * answer wholesale therefore throws away six good days to fix one meal, and
     * the result fails validation for being incomplete, which is a worse outcome
     * than the single violation it set out to fix.
     *
     * So entries are merged by identity: anything the retry supplies wins for
     * that identity, anything it omits is kept from the previous version. A plan
     * is a set of dated entries rather than an ordered list, so there is no
     * positional meaning to lose.
     *
     * A day's identity is its date. A SESSION's is not: an optional session sits
     * alongside a committed one on the same date (SPEC-coaching §3.3a), so date
     * alone would silently drop one of the pair. Identity is therefore the date
     * plus the commitment flag plus the type.
     *
     * Scalar and summary fields take the retry's value when it gives one, since
     * a correction may legitimately restate the week's expectations.
     */
    private static function mergePlans(array $prev, array $next): array
    {
        $merged = array_merge($prev, $next);

        $dayKey = static fn(array $r): string => (string) ($r['date'] ?? '');

        // Committed and optional sessions coexist on one date, and so can a
        // strength session and a separate conditioning one.
        $sessionKey = static fn(array $r): string => implode('|', [
            (string) ($r['date'] ?? ''),
            ($r['is_committed'] ?? false) ? 'c' : 'o',
            (string) ($r['session_type'] ?? ''),
        ]);

        foreach (['days' => $dayKey, 'sessions' => $sessionKey] as $field => $keyOf) {
            $byId = [];
            // Entries in $prev then $next, so the retry's version of a given
            // identity overwrites the earlier one and new identities are added.
            foreach ([$prev[$field] ?? [], $next[$field] ?? []] as $rows) {
                foreach ($rows as $row) {
                    // An undated entry cannot be merged — there is nothing to
                    // match it on — so it is dropped here and the validator
                    // reports the resulting gap.
                    if (!is_array($row) || ($row['date'] ?? '') === '') {
                        continue;
                    }
                    $byId[$keyOf($row)] = $row;
                }
            }
            if ($byId !== []) {
                ksort($byId);   // chronological; the model's order is not load-bearing
                $merged[$field] = array_values($byId);
            }
        }

        return $merged;
    }

    /**
     * Generate and persist a week.
     *
     * @param string $weekStart  a Monday, YYYY-MM-DD
     * @param string $reason     plan_versions.reason
     * @return array{ok: bool, plan_version_id: ?int, plan: ?array, error: ?string, violations: list<string>}
     */
    public static function generateWeek(
        int $userId,
        string $weekStart,
        string $reason = 'initial',
        array $extraContext = []
    ): array {
        $context = self::gatherContext($userId, $weekStart);
        if ($context['error'] !== null) {
            return ['ok' => false, 'plan_version_id' => null, 'plan' => null,
                    'error' => $context['error'], 'violations' => []];
        }

        /*
         * The cost line this generation shows up on.
         *
         * Deliberately coarser than `reason`: 'veto' gets its own bucket because §5.4 asks
         * how OFTEN vetoes happen, and a replacement that costs a full week's generation is
         * the expensive answer to that question. The rest collapse into plan_generation,
         * since separating a drift adaptation from a check-in rebuild tells nobody anything.
         */
        $purpose = match ($reason) {
            'provisional'      => 'provisional_plan',
            'veto'             => 'veto_replacement',
            'drift_adaptation' => 'plan_generation',
            'check_in'         => 'plan_generation',
            default            => 'plan_generation',
        };

        /*
         * TWO CALLS, TRAINING FIRST.
         *
         * A week used to be one request: seven days of sessions AND seven days of meals with
         * structured ingredients, 22k-31k output tokens. Measured over six live buddy
         * generations, three came back with ONE day of nutrition where seven were asked for —
         * and because the halves shared a document, a complete, valid training week was thrown
         * away along with the food. Every leader succeeded; every failure was the second user
         * of a pair, whose prompt also carries the shared-session skeleton.
         *
         * Splitting does two things. Each call asks for roughly half as much, which makes the
         * short-answer failure less likely. And it makes that failure survivable, which matters
         * more: a fragment on the food half now costs the food half.
         *
         * Training goes first because it is what the buddy pairing depends on and what the user
         * is most likely to need tomorrow, and because the nutrition call is told what the
         * training week looks like so the food can suit it.
         *
         * Validation runs inside each retry loop: a violating plan names its violations in the
         * retry prompt and is regenerated. Two retries, then fail (SPEC-safety.md §5).
         */
        $training = Claude::generateValidated(
            PlanSchema::training(),
            [
                'purpose'      => $purpose,
                'user_id'      => $userId,
                'max_tokens'   => self::MAX_OUTPUT_TOKENS,
                'system'       => self::systemPrompt($context),
                'cache_system' => self::CACHE_SYSTEM,
                'messages'     => [[
                    'role'    => 'user',
                    'content' => self::userPrompt($context, $weekStart, $extraContext),
                ]],
            ],
            fn(array $plan): array => Safety::validateTraining($plan, $userId),
            3,
            // Graft a retry's answer onto the previous one rather than replacing
            // it. See mergePlans, and the note in Claude::generateValidated.
            self::mergePlans(...)
        );

        /*
         * A failed TRAINING half is still a failed week.
         *
         * There is no useful half-plan in the other direction: meals with no sessions is not a
         * training programme, and the buddy pairing, adherence and everything §10 does are
         * built on sessions existing.
         */
        if (!$training['ok']) {
            return ['ok' => false, 'plan_version_id' => null, 'plan' => null,
                    'error' => $training['error'] ?? 'Generation failed.',
                    'violations' => $training['violations'] ?? []];
        }

        $plan = $training['data'];

        $nutrition = self::generateNutrition($userId, $weekStart, $context, $plan, $purpose);
        if ($nutrition !== null) {
            $plan['days'] = $nutrition['days'] ?? [];
        }

        $planVersionId = self::persist($userId, $weekStart, $reason, $plan, [
            'attempts' => $training['attempts'] ?? 1,
            'model'    => $training['model'] ?? null,
            'usage'    => $training['usage'] ?? [],
            // Recorded so the retry sweep can find weeks that still need feeding, and so a
            // half-written week is visible in the history rather than looking complete.
            'nutrition_pending' => $nutrition === null,
        ]);

        return ['ok' => true, 'plan_version_id' => $planVersionId,
                'plan' => $plan, 'error' => null, 'violations' => [],
                'nutrition_pending' => $nutrition === null];
    }

    /**
     * The food half, generated against the training week that was just written.
     *
     * Returns null when it could not be produced. That is not an error the caller should
     * propagate: the training week is already good, and a user with a complete training plan
     * and no meals yet is in a far better position than one with nothing. The week is marked
     * nutrition_pending and bin/cron.php picks it up.
     *
     * Separate from generateWeek so the retry sweep can call it on its own, against a plan that
     * already exists.
     */
    public static function generateNutrition(
        int $userId,
        string $weekStart,
        array $context,
        array $trainingPlan,
        string $purpose = 'plan_generation'
    ): ?array {
        $result = Claude::generateValidated(
            PlanSchema::nutrition(),
            [
                'purpose'      => $purpose,
                'user_id'      => $userId,
                'max_tokens'   => self::MAX_OUTPUT_TOKENS,
                // The same cached prefix as the training call, so the second request pays for
                // the profile once. Anthropic keys the cache on the literal prefix, so this
                // only works while the two calls build the system prompt identically.
                'system'       => self::systemPrompt($context),
                'cache_system' => self::CACHE_SYSTEM,
                'messages'     => [[
                    'role'    => 'user',
                    'content' => self::nutritionPrompt($context, $weekStart, $trainingPlan),
                ]],
            ],
            fn(array $plan): array => Safety::validateNutrition($plan, $userId),
            3,
            self::mergePlans(...)
        );

        if (!$result['ok']) {
            error_log(sprintf(
                '[yoked] nutrition half failed for user %d week %s: %s',
                $userId,
                $weekStart,
                (string) ($result['error'] ?? '?')
                . ($result['violations'] ? ' | ' . implode('; ', $result['violations']) : '')
            ));
            return null;
        }

        return $result['data'];
    }

    // ---- context assembly --------------------------------------------------

    /**
     * Everything the generator needs to know.
     *
     * Deliberately generous: the whole app costs ~$10-15/month for four users,
     * so there is no reason to economise on context quality. The split matters
     * more than the volume — stable facts go in the cached system prompt,
     * volatile ones in the user message after the cache breakpoint.
     */
    private static function gatherContext(int $userId, string $weekStart): array
    {
        $user = DB::one(
            'SELECT id, username, display_name, onboarding_state FROM users WHERE id = ?',
            [$userId]
        );
        if ($user === null) {
            return ['error' => "No such user: {$userId}"];
        }

        $profile = DB::one('SELECT * FROM profiles WHERE user_id = ?', [$userId]);
        if ($profile === null) {
            return ['error' => 'User has no profile; onboarding is incomplete.'];
        }

        $goal = DB::one(
            'SELECT g.*, gp.slug AS preset_slug, gp.name AS preset_name, gp.constraints
             FROM goals g
             LEFT JOIN goal_presets gp ON gp.id = g.goal_preset_id
             WHERE g.user_id = ? AND g.status = "active"
             ORDER BY g.created_at DESC LIMIT 1',
            [$userId]
        );
        if ($goal === null) {
            return ['error' => 'User has no active goal.'];
        }

        $availability = DB::all(
            'SELECT weekday, can_train, minutes, access, equipment, is_chaotic, preferred_time
             FROM availability WHERE user_id = ? ORDER BY weekday',
            [$userId]
        );
        if ($availability === []) {
            return ['error' => 'User has no availability grid; onboarding is incomplete.'];
        }

        /*
         * Overlay the buddy schedule (SPEC-coaching §10.1a).
         *
         * A shared day replaces whatever the individual grid said, including a `no`: agreeing
         * to a Wednesday you never offered has to make Wednesday trainable, or Safety rejects
         * the plan the agreement was for. The shared day brings its own minutes and access,
         * because the grid has nothing useful to say about a day that was never offered.
         *
         * The grid's other columns are kept as-is. `equipment` and `is_chaotic` are facts
         * about the user's week that a shared day does not change, and `preferred_time` is a
         * preference rather than a constraint.
         *
         * Done here rather than inside BuddySchedule::effective because the prompt needs those
         * extra columns and the validator does not. effective() stays the narrow authority on
         * "can they train, how long, where"; this is the prompt's richer view of the same
         * answer, and the two agree by construction because this reads from that.
         */
        $pair     = BuddySchedule::activePair($userId);
        $pairId   = $pair === null ? null : (int) $pair['id'];

        /*
         * Is the buddy actually going to be there this week (§10.5)?
         *
         * Travel declared in advance, illness declared mid-week, or simply gone quiet — all
         * three collapse to one question here. When the answer is no, the pair id is dropped
         * and the schedule resolves to the user's OWN grid, which is why the fallback needs no
         * separate generation path: "solo" is just what effective() returns with no pair.
         *
         * §10.5: "Pairing is an enhancement to a complete single-user plan, never a dependency
         * of one." This is the line that makes that literally true.
         */
        $buddyAway = $pairId === null
            ? null
            : BuddyAbsence::availableFor($userId, $weekStart);
        if ($buddyAway !== null && $buddyAway['available'] === false) {
            $pairId = null;
        }

        $effective = BuddySchedule::effective($userId, $pairId);

        foreach ($availability as &$a) {
            $wd  = (int) $a['weekday'];
            $eff = $effective[$wd] ?? null;
            if ($eff === null) {
                continue;
            }
            $a['can_train'] = $eff['can_train'];
            $a['minutes']   = $eff['minutes'];
            $a['access']    = $eff['access'];
            // What the prompt renders as "shared with their buddy".
            $a['shared']    = (bool) $eff['shared'];
        }
        unset($a);

        /*
         * The access levels this user actually has THIS week.
         *
         * Drives the exercise vocabulary, so the model is not shown barbell work by somebody
         * whose whole week is bodyweight. Taken from the EFFECTIVE grid rather than the raw
         * one, so a shared day contributes the facility the pair agreed on.
         *
         * Only trainable days count: the access recorded against a day they cannot train is
         * not something they have.
         */
        $accessSet = [];
        foreach ($availability as $a) {
            // 'sometimes' counts: it is a maybe, not a no, and excluding it would strip the
            // equipment from exactly the chaotic-schedule user who has least of it.
            if (!in_array($a['can_train'] ?? 'no', ['yes', 'sometimes'], true)) {
                continue;
            }
            if (($a['access'] ?? null) !== null) {
                $accessSet[(string) $a['access']] = true;
            }
        }
        $accessSet = array_keys($accessSet);

        /*
         * What they have at home, for the days marked home_gym.
         *
         * NULL and [] mean different things and the distinction is load-bearing: NULL is a user
         * who was never asked and keeps the permissive old behaviour, [] is a user who said
         * they have nothing and gets bodyweight work.
         */
        $trainingPrefs = DB::one(
            'SELECT * FROM training_preferences WHERE user_id = ?', [$userId]
        );

        $homeKit = null;
        if ($trainingPrefs !== null && $trainingPrefs['home_equipment'] !== null) {
            $decoded = json_decode((string) $trainingPrefs['home_equipment'], true);
            $homeKit = is_array($decoded) ? $decoded : null;
        }

        /*
         * Does this user do any cardio at all? (§6.8, §6.9)
         *
         * Decides whether the cardio half of the library is worth sending. Somebody who listed
         * nothing they are willing to do gets no cardio prescribed, so the ~240 tokens of cardio
         * slugs are pure waste in their cached prefix.
         *
         * Defaults to TRUE when unanswered: an empty list from a user who was never asked is
         * not a refusal, and silently withholding cardio from them would be a worse error than
         * a slightly longer prompt.
         */
        $wantsCardio = true;
        if ($trainingPrefs !== null && $trainingPrefs['cardio_willing'] !== null) {
            $willing = json_decode((string) $trainingPrefs['cardio_willing'], true);
            if (is_array($willing)) {
                $wantsCardio = $willing !== [];
            }
        }

        /*
         * How many committed sessions this user should get, and where the surplus comes from
         * (§10.3b). Unpaired this is simply their stated count.
         */
        $target = BuddySchedule::committedTarget($userId, $pairId);

        return [
            'error'        => null,
            'committed_target' => $target,
            // Null when unpaired. Carries the reason so the prompt can say WHY this week is
            // solo rather than silently producing one.
            'buddy_away'   => $buddyAway !== null && $buddyAway['available'] === false
                ? $buddyAway
                : null,
            'user'         => $user,
            'profile'      => $profile,
            'goal'         => $goal,
            'availability' => $availability,
            'food'         => DB::one('SELECT * FROM food_preferences WHERE user_id = ?', [$userId]),
            // Already read above, for the home kit and the cardio-willingness check.
            'training'     => $trainingPrefs,
            'constraints'  => Safety::promptBlock($userId),
            /*
             * Only what they can perform somewhere in this week (see $accessSet above), with a
             * home_gym day narrowed to the kit they actually own.
             *
             * $homeKit is null when they were never asked, which keeps the old permissive
             * behaviour for users who onboarded before the question existed rather than
             * silently taking away equipment we have no answer about.
             */
            'vocabulary'   => PlanSchema::vocabulary(
                null,
                $accessSet,
                $homeKit,
                // Scoped by category as well as access: activities are reportable-only unless
                // there is an outdoors day, mobility is warm-up prose rather than slugs, and
                // cardio only matters to somebody who does any.
                PlanSchema::categoriesFor($accessSet, $wantsCardio),
                // A skill ceiling: a beginner is never shown an expert movement. Null when
                // experience is unstated, which leaves the judgement to the model as before.
                PlanSchema::levelsUpTo($trainingPrefs['experience'] ?? null)
            ),
            // Which of those the user is banned from, so the prompt can mark them rather than
            // hide them. Enforcement stays with Safety::validatePlan.
            'banned_slugs' => Safety::bannedSlugs($userId),
            'history'      => self::adherenceHistory($userId, $weekStart),
            'loads'        => self::recentLoads($userId),
            'vetoes'       => self::standingVetoes($userId),
            'circumstances' => self::activeCircumstances($userId, $weekStart),
            'emphasis'     => self::activeEmphasis($userId),
            'checkins'     => self::recentCheckins($userId),
            'trend'        => self::weightTrend($userId),
            'buddy'        => self::buddy($userId),
            /*
             * The pair's shared session shape, when the buddy has already generated (§10.6).
             *
             * Null means this user LEADS: either they are unpaired, or their buddy's week is not
             * written yet, in which case the plan they are about to get becomes the skeleton.
             * Both cases generate identically — leading is not a mode, it is just what going
             * first looks like.
             */
            'skeleton'     => BuddySkeleton::toFollow($userId, $weekStart),
            // §10.4. Whether the pairing is doing what §10.0 bet it would. Null when unpaired
            // or when no shared session has come round yet.
            'buddy_adherence' => self::buddyAdherence($userId, $weekStart),
        ];
    }

    /** Last 4 weeks: prescribed vs. actual, the core coaching signal. */
    private static function adherenceHistory(int $userId, string $weekStart): array
    {
        return DB::all(
            'SELECT ld.log_date, ld.macro_on_target, ld.macro_short_but_ok,
                    ld.sessions_prescribed, ld.sessions_completed,
                    ld.energy, ld.sleep_hours, ld.soreness, ld.mood
             FROM logged_days ld
             WHERE ld.user_id = ?
               AND ld.log_date >= DATE_SUB(?, INTERVAL 28 DAY)
               AND ld.log_date < ?
             ORDER BY ld.log_date DESC',
            [$userId, $weekStart, $weekStart]
        );
    }

    /**
     * Is the pairing actually working? (§10.4)
     *
     * §10.0 bets that pairing improves adherence — that somebody beholden to a friend keeps
     * going where a solo user quits. Nothing measured whether that bet pays off, for a given
     * pair or at all, and a coach cannot adjust something it cannot see.
     *
     * NOT A NUDGE. The obvious reading of §10.4 is "tell them their buddy trained and they did
     * not", and that is worth nothing: if the two of them meet on Wednesday and one does not
     * show, the other learns it by standing in the gym alone. An app narrating that afterwards
     * is telling somebody what they already lived through.
     *
     * What the app cannot see from the gym floor is the PATTERN: shared days quietly being
     * skipped while solo days get done, or a pair who log the same days and never actually
     * meet. That is what this reports, and it goes to the coach rather than to the buddy.
     *
     * Returns null when unpaired, so the prompt says nothing at all.
     */
    private static function buddyAdherence(int $userId, string $weekStart): ?array
    {
        $pair = BuddySchedule::activePair($userId);
        if ($pair === null) {
            return null;
        }

        /*
         * Shared sessions in the last four weeks: prescribed, completed, and how many were
         * actually done together.
         *
         * Keyed off shared_skeleton_key rather than the weekday, because the agreed days can
         * change mid-window and a session's own row is the only record of what it was when it
         * was prescribed.
         */
        $row = DB::one(
            'SELECT
                COUNT(*) AS shared_prescribed,
                SUM(ls.id IS NOT NULL AND ls.status = "completed") AS shared_completed,
                SUM(ls.trained_with_buddy = 1) AS shared_together
             FROM prescribed_sessions ps
             JOIN plan_versions pv ON pv.id = ps.plan_version_id
             LEFT JOIN logged_sessions ls ON ls.prescribed_session_id = ps.id
             WHERE pv.user_id = ?
               AND pv.superseded_at IS NULL
               AND ps.shared_skeleton_key IS NOT NULL
               AND ps.is_committed = 1
               AND ps.session_date >= DATE_SUB(?, INTERVAL 28 DAY)
               AND ps.session_date < ?',
            [$userId, $weekStart, $weekStart]
        );

        // The same question for the days they train alone, which is the comparison that makes
        // the shared figure mean anything.
        $solo = DB::one(
            'SELECT
                COUNT(*) AS solo_prescribed,
                SUM(ls.id IS NOT NULL AND ls.status = "completed") AS solo_completed
             FROM prescribed_sessions ps
             JOIN plan_versions pv ON pv.id = ps.plan_version_id
             LEFT JOIN logged_sessions ls ON ls.prescribed_session_id = ps.id
             WHERE pv.user_id = ?
               AND pv.superseded_at IS NULL
               AND ps.shared_skeleton_key IS NULL
               AND ps.is_committed = 1
               AND ps.session_date >= DATE_SUB(?, INTERVAL 28 DAY)
               AND ps.session_date < ?',
            [$userId, $weekStart, $weekStart]
        );

        $sharedPrescribed = (int) ($row['shared_prescribed'] ?? 0);
        if ($sharedPrescribed === 0) {
            // Paired, but no shared sessions have come round yet. Nothing to report and
            // nothing to infer from silence.
            return null;
        }

        return [
            'shared_prescribed' => $sharedPrescribed,
            'shared_completed'  => (int) ($row['shared_completed'] ?? 0),
            'shared_together'   => (int) ($row['shared_together'] ?? 0),
            'solo_prescribed'   => (int) ($solo['solo_prescribed'] ?? 0),
            'solo_completed'    => (int) ($solo['solo_completed'] ?? 0),
        ];
    }

    /**
     * Recent per-exercise loads and RPE — what justifies every weight change.
     *
     * RPE is the single most valuable field here: "RPE 7 at 85kg" is what makes
     * "go to 95kg" a decision rather than a guess.
     */
    private static function recentLoads(int $userId): array
    {
        return DB::all(
            'SELECT e.slug, e.name,
                    le.actual_weight_kg, le.actual_reps, le.sets_completed, le.rpe,
                    le.logged_at
             FROM logged_exercises le
             JOIN logged_sessions ls ON ls.id = le.logged_session_id
             JOIN exercises e ON e.id = le.exercise_id
             WHERE ls.user_id = ?
               AND le.skipped = 0
               AND le.logged_at >= (NOW() - INTERVAL 28 DAY)
             ORDER BY le.logged_at DESC
             LIMIT 200',
            [$userId]
        );
    }

    /**
     * Standing vetoes — permanent dislikes, distinct from "not today".
     *
     * EXCLUDES any whose promoted constraint the user has since switched off, and that join
     * is load-bearing rather than tidy. An accepted standing veto reaches the prompt as
     * "These are permanent. Do not prescribe them again", and the soft constraint it created
     * reaches it separately through Safety, which filters on `active`. So without this,
     * switching the preference off in the profile would stop the constraint and leave the
     * veto still saying never again: the switch would appear to work and silently would not.
     *
     * A veto that promoted nothing (scope standing, but the coach concluded no lasting
     * preference) has a null promoted_constraint_id and is kept — there is no switch for the
     * user to have thrown.
     */
    private static function standingVetoes(int $userId): array
    {
        return DB::all(
            'SELECT v.subject_type, v.reason_code, v.reason_text, v.created_at
             FROM vetoes v
             LEFT JOIN user_constraints uc ON uc.id = v.promoted_constraint_id
             WHERE v.user_id = ? AND v.scope = "standing" AND v.outcome = "accepted"
               AND (v.promoted_constraint_id IS NULL OR uc.active = 1)
             ORDER BY v.created_at DESC LIMIT 50',
            [$userId]
        );
    }

    /**
     * Circumstances still in force for the target week.
     *
     * Expiry matters: without it the app reshuffles forever around a trip that
     * ended three weeks ago.
     */
    private static function activeCircumstances(int $userId, string $weekStart): array
    {
        return DB::all(
            'SELECT kind, detail, starts_on, ends_on
             FROM circumstances
             WHERE user_id = ? AND active = 1
               AND (ends_on IS NULL OR ends_on >= ?)
             ORDER BY created_at DESC',
            [$userId, $weekStart]
        );
    }

    /** Granted emphasis requests — the adherence dividend (§7.2a). */
    private static function activeEmphasis(int $userId): array
    {
        return DB::all(
            'SELECT request, decision, reasoning, created_at
             FROM emphasis_grants
             WHERE user_id = ? AND active = 1 AND decision IN ("granted", "partial")
             ORDER BY created_at DESC LIMIT 10',
            [$userId]
        );
    }

    private static function recentCheckins(int $userId): array
    {
        return DB::all(
            'SELECT week_start, weight_kg, waist_cm, hips_cm, chest_cm, arm_cm,
                    thigh_cm, neck_cm, self_report, emphasis_request
             FROM weekly_checkins
             WHERE user_id = ? AND status = "completed"
             ORDER BY week_start DESC LIMIT 6',
            [$userId]
        );
    }

    /**
     * Weight trend, not raw points.
     *
     * Weight moves for a dozen reasons daily. Evaluation reads direction over
     * time; a single reading is noise dressed as signal.
     */
    private static function weightTrend(int $userId): array
    {
        $rows = DB::all(
            'SELECT week_start, weight_kg FROM weekly_checkins
             WHERE user_id = ? AND weight_kg IS NOT NULL
             ORDER BY week_start DESC LIMIT 8',
            [$userId]
        );
        if (count($rows) < 2) {
            return ['points' => $rows, 'direction' => 'insufficient data'];
        }

        $newest = (float) $rows[0]['weight_kg'];
        $oldest = (float) $rows[count($rows) - 1]['weight_kg'];
        $delta  = $newest - $oldest;

        return [
            'points'    => $rows,
            'delta_kg'  => round($delta, 2),
            'weeks'     => count($rows),
            'direction' => abs($delta) < 0.5 ? 'flat' : ($delta < 0 ? 'down' : 'up'),
        ];
    }

    /** Active buddy pair, if any (§10). */
    private static function buddy(int $userId): ?array
    {
        $row = DB::one(
            'SELECT bp.id, bp.user_lo, bp.user_hi,
                    u.display_name AS buddy_name, u.id AS buddy_id
             FROM buddy_pairs bp
             JOIN users u ON u.id = CASE WHEN bp.user_lo = ? THEN bp.user_hi ELSE bp.user_lo END
             WHERE bp.status = "active" AND (bp.user_lo = ? OR bp.user_hi = ?)
             LIMIT 1',
            [$userId, $userId, $userId]
        );
        return $row;
    }

    // ---- prompt construction -----------------------------------------------

    /**
     * The stable half of the prompt — cached.
     *
     * Everything here changes rarely: who the user is, what they're working
     * toward, what they can't do, what the app's rules are. Nothing per-request
     * belongs in this string. A timestamp here would silently make every call a
     * cache miss and nobody would notice except the bill.
     */
    private static function systemPrompt(array $ctx): string
    {
        $p        = $ctx['profile'];
        $goal     = $ctx['goal'];
        $food     = $ctx['food'] ?? [];
        $training = $ctx['training'] ?? [];

        $tone = self::toneBrief((string) ($p['tone'] ?? 'friendly_encouraging'));
        $depth = match ($p['explanation_depth'] ?? 'brief') {
            'just_tell_me' => 'Give the prescription with no explanation.',
            'explain'      => 'Explain your reasoning for the week and for notable exercise choices.',
            default        => 'Give brief one-line reasons for the week and for non-obvious choices.',
        };

        $age = $p['date_of_birth']
            ? (int) ((time() - strtotime((string) $p['date_of_birth'])) / 31557600)
            : null;

        $out = [];
        $out[] = 'You are the training and nutrition coach inside Yoked. You author '
               . "this user's week: their training sessions and their menu.";
        $out[] = '';
        $out[] = "VOICE: {$tone}";
        $out[] = $depth;
        $out[] = '';
        $out[] = '=== THE USER ===';
        $out[] = 'Name: ' . ($ctx['user']['display_name'] ?? '');
        if ($age !== null)          { $out[] = "Age: {$age}"; }
        if ($p['birth_sex'])        { $out[] = "Sex: {$p['birth_sex']}"; }
        if ($p['height_cm'])        { $out[] = "Height: {$p['height_cm']} cm"; }
        if ($p['baseline_activity']) { $out[] = "Day-to-day activity: {$p['baseline_activity']}"; }
        if ($p['baseline_sleep_hours']) {
            $out[] = "Typical sleep: {$p['baseline_sleep_hours']}h ({$p['baseline_sleep_quality']})";
        }
        if ($p['baseline_energy'])  { $out[] = "Typical energy: {$p['baseline_energy']}"; }
        if ($p['baseline_stress'])  { $out[] = "Typical stress: {$p['baseline_stress']}"; }
        if ($p['medications'])      { $out[] = "Medications: {$p['medications']}"; }
        if ($p['physician_clearance']) {
            // Self-reported and flagged as such. Context, not a gate.
            $out[] = "Physician clearance for vigorous exercise: {$p['physician_clearance']} "
                   . '(SELF-REPORTED, not verified)';
        }
        if ($p['trainer_notes'])    { $out[] = "Other notes: {$p['trainer_notes']}"; }

        $out[] = '';
        $out[] = '=== GOAL ===';
        $out[] = "Primary: {$goal['primary_goal']}";
        if ($goal['secondary_goals']) {
            $sec = json_decode((string) $goal['secondary_goals'], true);
            if (is_array($sec) && $sec !== []) {
                $out[] = 'Secondary: ' . implode(', ', $sec);
            }
        }
        if ($goal['success_statement']) {
            // The highest-signal answer in onboarding. Verbatim, never parsed.
            $out[] = 'In their own words: "' . $goal['success_statement'] . '"';
        }
        if ($goal['requested_timeline']) {
            $out[] = "Requested timeline: {$goal['requested_timeline']}"
                   . ($goal['horizon_weeks'] ? " (working horizon: {$goal['horizon_weeks']} weeks)" : '');
        }
        if ($goal['scale_vs_feel']) {
            $out[] = "Cares more about: {$goal['scale_vs_feel']}";
        }
        if ($goal['preset_name']) {
            $out[] = "Nutrition scoring preset: {$goal['preset_name']}";
        }

        if ($ctx['constraints'] !== '') {
            $out[] = '';
            $out[] = '=== CONSTRAINTS ===';
            $out[] = $ctx['constraints'];
        }

        $out[] = '';
        $out[] = '=== WEEKLY AVAILABILITY ===';
        $out[] = 'Access is a property of a DAY, not of the user. Never prescribe '
               . 'equipment a day does not have.';
        foreach ($ctx['availability'] as $a) {
            $name = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][(int) $a['weekday']];
            $line = "  {$name}: {$a['can_train']}";
            if ($a['minutes']) { $line .= ", {$a['minutes']} min"; }
            if ($a['access'])  { $line .= ", {$a['access']}"; }
            if ($a['preferred_time']) { $line .= ", prefers {$a['preferred_time']}"; }
            if ((int) $a['is_chaotic'] === 1) { $line .= ' [usually chaotic]'; }
            // §10.1a: a day the pair agreed to train together. Marked so the model puts a
            // committed session there rather than treating it as one option among seven.
            if (($a['shared'] ?? false) === true) { $line .= ' [SHARED with their buddy]'; }
            if ($a['equipment']) {
                $eq = json_decode((string) $a['equipment'], true);
                if (is_array($eq) && $eq !== []) {
                    $line .= ' — has: ' . implode(', ', $eq);
                }
            }
            $out[] = $line;
        }
        $out[] = "Committed sessions per week: {$p['committed_days_per_week']}";

        if ($training !== null && $training !== []) {
            $out[] = '';
            $out[] = '=== TRAINING BACKGROUND ===';
            foreach ([
                'experience'         => 'Experience',
                'currently_training' => 'Currently training',
                'self_strength'      => 'Self-rated strength',
                'self_cardio'        => 'Self-rated cardio',
                'preferred_split'    => 'Preferred split',
            ] as $k => $label) {
                if (!empty($training[$k])) { $out[] = "{$label}: {$training[$k]}"; }
            }
            if (!empty($training['past_success'])) {
                $out[] = "Previously in great shape: {$training['past_success']}";
            }
            foreach (['cardio_willing' => 'Cardio they will do',
                      'cardio_refused' => 'Cardio they refuse'] as $k => $label) {
                $v = json_decode((string) ($training[$k] ?? ''), true);
                if (is_array($v) && $v !== []) { $out[] = "{$label}: " . implode(', ', $v); }
            }
            if (!empty($training['cardio_feeling'])) {
                $out[] = "On cardio: \"{$training['cardio_feeling']}\"";
            }
        }

        if ($food !== null && $food !== []) {
            $out[] = '';
            $out[] = '=== FOOD ===';
            $meals = json_decode((string) ($food['meals_eaten'] ?? '[]'), true);
            if (is_array($meals) && $meals !== []) {
                $out[] = 'Meals they actually eat: ' . implode(', ', $meals);
                $out[] = 'Do not prescribe a meal they do not eat. If they skip '
                       . 'breakfast, redistribute those calories.';
            }
            $out[] = "Structure preference: {$food['structure']}";
            foreach ([
                'cooking_skill'        => 'Cooking skill',
                'weekday_cook_minutes' => 'Weekday cooking minutes',
                'weekend_cook_minutes' => 'Weekend cooking minutes',
                'cooking_for'          => 'Cooking for (people)',
                'meal_preps'           => 'Meal preps',
                'budget_sensitivity'   => 'Budget sensitivity',
                'dietary_pattern'      => 'Dietary pattern',
                'eat_out_agreed'       => 'Unplanned meals per week (agreed)',
                'caffeine_per_day'     => 'Caffeine/day',
            ] as $k => $label) {
                if (($food[$k] ?? null) !== null && $food[$k] !== '') {
                    $out[] = "{$label}: {$food[$k]}";
                }
            }
            // A good cook with 20 minutes needs EFFICIENT recipes, not simple
            // ones. Those are different requests.
            $out[] = 'Cooking skill and available time are separate constraints. '
                   . 'A skilled cook with little time needs efficient recipes, '
                   . 'not simplified ones.';
            $eq = json_decode((string) ($food['kitchen_equipment'] ?? '[]'), true);
            if (is_array($eq) && $eq !== []) {
                $out[] = 'Kitchen: ' . implode(', ', $eq);
                if (!in_array('food scale', $eq, true) && !in_array('food_scale', $eq, true)) {
                    $out[] = 'No food scale — give household measures, not gram weights.';
                }
            }
            $cuisines = json_decode((string) ($food['cuisines'] ?? '[]'), true);
            if (is_array($cuisines) && $cuisines !== []) {
                $out[] = 'Likes: ' . implode(', ', $cuisines);
            }
        }

        $out[] = '';
        $out[] = '=== HOW TO BUILD THE WEEK ===';
        /*
         * The committed count the model should hit, which is not always the stated one.
         *
         * §10.3b: a paired user whose shared days do not cover their commitment CHOOSES what
         * happens to the difference, and two of the three answers lower the committed count.
         * Passing the stated figure regardless would produce a plan the validator rejects.
         */
        $out[] = self::rules((int) ($ctx['committed_target']['committed'] ?? $p['committed_days_per_week']));

        /*
         * The vocabulary, already narrowed to what this user can perform somewhere this week
         * (see gatherContext's $accessSet), with anything they are banned from MARKED.
         *
         * Marked rather than removed, deliberately. A ban is free text — a real one reads
         * "box jumps", which is not a slug — so subtracting it reliably is not possible:
         * "squats" should probably take out back-squat, goblet-squat and bulgarian-split-squat,
         * and a naive match gets that wrong in both directions.
         *
         * Showing the exclusion also beats hiding it. A model that cannot see why a movement is
         * absent may reach for something adjacent and equally forbidden, where one that sees
         * "back-squat [BANNED]" knows the whole area is closed. Safety::validatePlan still
         * enforces it either way — this reduces wasted generations, it is not the boundary.
         */
        $banned = $ctx['banned_slugs'] ?? [];

        $out[] = '';
        $out[] = '=== EXERCISE VOCABULARY ===';
        $out[] = 'Use ONLY these slugs. They are grouped by category and movement pattern.';
        if ($banned !== []) {
            $out[] = 'Anything marked [BANNED] is off limits for this user — a plan containing '
                   . 'one is rejected. It is listed so you can see what is excluded.';
        }
        foreach ($ctx['vocabulary'] as $category => $patterns) {
            $out[] = strtoupper((string) $category) . ':';
            foreach ($patterns as $pattern => $slugs) {
                $marked = [];
                foreach ($slugs as $slug) {
                    $marked[] = isset($banned[$slug]) ? "{$slug} [BANNED]" : $slug;
                }
                $out[] = "  {$pattern}: " . implode(', ', $marked);
            }
        }

        return implode("\n", $out);
    }

    /** The rules that make a plan conform to the specs. */
    private static function rules(int $committed): string
    {
        $rules = [
            "COMMITTED VS OPTIONAL: mark exactly {$committed} sessions as "
            . 'is_committed true. That is the week. Anything beyond is '
            . 'is_committed false — bonus, never debt. Active recovery and rest '
            . 'days never count toward the committed total.',

            /*
             * The availability grid binds OPTIONAL sessions too.
             *
             * Live runs failed twice on this and both times the offending sessions were
             * optional: a cardio day and a mobility day dropped onto weekdays the user had
             * marked unavailable. The model was not overreaching on the commitment — it had
             * filled its quota and then added bonuses, which the prompt invites ("anything
             * beyond is a bonus") without ever saying bonuses live on available days too.
             *
             * The validator rejects these outright, so a plan that includes one is not a
             * generous plan, it is a rejected plan and a wasted generation.
             */
            'NEVER SCHEDULE ANYTHING ON AN UNAVAILABLE DAY. A day the availability grid '
            . 'marks unavailable takes NO session of any kind — not a committed one, not an '
            . 'optional one, not cardio, not mobility, not active recovery. If there is not '
            . 'enough room in their available days for everything worth doing, do less. A '
            . 'plan with a session on a closed day is rejected in full.',

            /*
             * One entry per exercise, per session.
             *
             * A live follower produced "Dumbbell Row 3x10-12 @8kg", then again at 9kg, then
             * again at 10kg — three entries for one movement, which is three sets of one
             * exercise written as three exercises. It came from substituting a row for a
             * pull-up it had no bar for and then filling the remaining slots with the same
             * substitute.
             *
             * Nothing in the UI renders a repeat as anything but two separate exercises, so
             * even a deliberate one reads as a bug. Safety::checkNoRepeats enforces this; the
             * rule is here so the model gets it right rather than being corrected on a retry.
             */
            'ONE ENTRY PER EXERCISE PER SESSION: never list the same slug twice in the same '
            . 'session. Multiple sets of a movement are ONE entry with a set count, not '
            . 'repeated entries. If a substitution would duplicate something already in the '
            . 'session, pick a different exercise.',

            /*
             * Frequency, and the variety that follows from it (§7.3).
             *
             * The thing to avoid is repetition WITHIN a week, not across weeks. The same
             * session every Monday for two months is a programme, and progressive overload
             * requires it — you cannot measure progress on a squat by squatting something
             * else. Six exposures to one movement in seven days is the actual problem:
             * not enough recovery, and it crowds out everything else.
             *
             * The accessory half is where variety belongs, and the library now supports it:
             * 27 vertical pulls where there were 3.
             */
            'NO MOVEMENT MORE THAN THREE TIMES IN THE WEEK. Twice is normal for a split, a '
            . 'third is fine for a lagging lift, four does not leave enough recovery. Count '
            . 'across every session including the optional ones.',

            'KEEP THE MAIN LIFTS, ROTATE THE ACCESSORIES. The compound movements — squat, '
            . 'hinge, press, row, pull — should stay week to week so their loads can be '
            . 'progressed and measured. The isolation work around them is where variety '
            . 'belongs: a lateral raise has no PR to chase, so vary it freely. As somebody '
            . 'gets stronger, move them toward harder variants of the same pattern rather '
            . 'than swapping the pattern out.',

            'CUT BY GOAL VALUE, NOT CALENDAR POSITION: if the ideal structure '
            . 'wants more sessions than the committed count allows, drop what '
            . 'the GOAL needs least. If cardio is their lagging metric, keep the '
            . 'cardio day committed and give up a strength day — do not simply '
            . 'drop the last day of the week.',
        ];

        /*
         * Unconditional, and 8-12 minutes always.
         *
         * §3.3b: "Built in by default, not asked as a preference." This was briefly wired to
         * a profiles.core_emphasis dial with an 'off' setting, which contradicted the spec in
         * both directions at once — it let a user switch core work off entirely, and it made
         * a structural decision about programming into a preference. Core work is the app's
         * business, like the warm-up. Removed.
         */
        $rules[] = 'CORE ON EVERY STRENGTH DAY: 8-12 minutes, block '
            . '"core", placed AFTER the main work — a fatigued core before '
            . 'heavy squats is a form risk. Pattern-match it to the day: '
            . 'lower/squat gets anti-rotation + lower back + isometric holds; '
            . 'lower/hinge gets lower back + anti-rotation + loaded carries; '
            . 'upper/horizontal gets anti-extension + flexion; upper/vertical '
            . 'gets anti-lateral-flexion + overhead stability. Brief '
            . 'anti-rotation activation may go in the warm-up.';

        return implode("\n\n", array_merge($rules, [
            'WARM-UPS ARE PRESCRIBED, not left to the user. Set warmup_minutes and '
            . 'warmup_detail on every training session. Where a cardiac or joint '
            . 'modifier applies, set warmup_required true and make it longer.',

            'MEALS: breakfast, lunch and dinner are fully specified — name, '
            . 'STRUCTURED ingredients with quantities, prep time, method, and '
            . 'macros. Snacks are macro targets (kind: target_only) with optional '
            . 'suggestions. Ingredients must be structured items, never prose: '
            . 'they are validated against allergies, and structure is what makes '
            . '"ate as planned" a one-tap log.',

            // kind and ingredients have to agree. A 'specified' meal with no
            // ingredients passes the schema and reaches the user as a meal name
            // with nothing to shop for, so it is stated as a rule here and
            // enforced in Safety::checkMealCompleteness.
            "KIND MUST MATCH CONTENT: kind 'specified' REQUIRES a non-empty "
            . 'ingredients list, and every ingredient needs both an item and a '
            . 'household measure ("1 cup", "6 oz") — grams alone are useless to '
            . "someone without a scale. If you do not want to prescribe a recipe, "
            . "use kind 'target_only' with a target_note instead. Do not mark a "
            . "meal 'specified' and leave it empty.",

            'MACRO TARGETS ARE PER-DAY, not per-week. Training days and rest days '
            . 'differ. Every day in the week needs its own targets.',

            'RATIONALE: give a one-line reason per session, and per exercise where '
            . 'a substitution would otherwise look arbitrary. Explain the choice, '
            . 'not the movement.',

            'EXPECTATIONS: state plainly what is realistic in this timeframe, so '
            . 'that a week with no visible change does not read as failure.',

            'Return ONLY the JSON object. No preamble, no markdown fence.',
        ]));
    }

    /**
     * Tone brief for the prompt. The label is how a user picks; this is what works.
     *
     * Delegates to Tone so plans, nudges, and check-in reviews all speak with the
     * same voice. It lived here first and was copied nowhere, which was fine until
     * nudges needed it too — two copies of a character brief is two voices.
     */
    private static function toneBrief(string $tone): string
    {
        return Tone::brief($tone);
    }

    /**
     * The volatile half — after the cache breakpoint.
     *
     * This is where anything that changes week to week lives: the dates, what
     * they actually did, what they've vetoed, what's going on in their life.
     */
    private static function userPrompt(array $ctx, string $weekStart, array $extra): string
    {
        $out = [];
        $end = date('Y-m-d', strtotime($weekStart . ' +6 days'));
        $out[] = "Build the TRAINING week of {$weekStart} (Monday) through {$end} (Sunday).";
        /*
         * Training only. The food half is a separate call (see nutritionPrompt).
         *
         * Said explicitly because this prompt still carries the user's whole profile, including
         * their food preferences and dietary constraints — the system prompt is shared between
         * the two calls so the cached prefix is paid for once. Without this line the model sees
         * all that food context and reasonably assumes meals are wanted.
         */
        $out[] = 'Sessions only in this answer — the meal plan is asked for separately. '
               . 'EVERY session goes on a day the availability grid marks available, including '
               . 'optional ones.';

        // History first: what actually happened outranks what was planned.
        if ($ctx['history'] !== []) {
            $out[] = '';
            $out[] = '=== RECENT ADHERENCE (last 4 weeks) ===';
            $logged = count($ctx['history']);
            $onTarget = $shortOk = $sessionsDone = $sessionsPlanned = 0;
            foreach ($ctx['history'] as $d) {
                if ((int) ($d['macro_on_target'] ?? 0) === 1) { $onTarget++; }
                if ((int) ($d['macro_short_but_ok'] ?? 0) === 1) { $shortOk++; }
                $sessionsDone    += (int) ($d['sessions_completed'] ?? 0);
                $sessionsPlanned += (int) ($d['sessions_prescribed'] ?? 0);
            }
            $out[] = "Days logged: {$logged}. On macro target: {$onTarget}.";
            if ($shortOk > 0) {
                // Calories short but macros landed — adherent with a note, not
                // a failure. Coming up from a low base, punishing an honest
                // attempt is how the app loses them.
                $out[] = "Days where calories came in short but macros landed: {$shortOk}. "
                       . 'Those count as adherent — do not treat them as misses. '
                       . 'If the pattern persists, the training load may be ahead '
                       . 'of the fuel; say so.';
            }
            $out[] = "Committed sessions: {$sessionsDone} completed of {$sessionsPlanned} prescribed.";
        } else {
            $out[] = '';
            $out[] = 'No logged history yet — this is an early week. Be '
                   . 'conservative with loads and state that you are calibrating.';
        }

        if ($ctx['loads'] !== []) {
            $out[] = '';
            $out[] = '=== RECENT LOADS AND RPE ===';
            $out[] = 'Progress loads from these. RPE is what justifies a change: '
                   . 'RPE 7 or below at a given weight means there was more in the tank.';
            $seen = [];
            foreach ($ctx['loads'] as $l) {
                $slug = (string) $l['slug'];
                if (isset($seen[$slug]) && $seen[$slug] >= 3) {
                    continue;   // three most recent per exercise is plenty
                }
                $seen[$slug] = ($seen[$slug] ?? 0) + 1;
                $bits = [];
                if ($l['actual_weight_kg'] !== null) { $bits[] = "{$l['actual_weight_kg']}kg"; }
                if ($l['sets_completed'] !== null)   { $bits[] = "{$l['sets_completed']} sets"; }
                if ($l['actual_reps'] !== null)      { $bits[] = "x{$l['actual_reps']}"; }
                if ($l['rpe'] !== null)              { $bits[] = "RPE {$l['rpe']}"; }
                $out[] = "  {$l['name']} ({$slug}): " . implode(' ', $bits)
                       . ' — ' . substr((string) $l['logged_at'], 0, 10);
            }
        }

        if ($ctx['trend']['direction'] !== 'insufficient data') {
            $out[] = '';
            $out[] = '=== WEIGHT TREND ===';
            $out[] = "Direction over {$ctx['trend']['weeks']} readings: "
                   . "{$ctx['trend']['direction']} ({$ctx['trend']['delta_kg']} kg). "
                   . 'Read the trend, not any single reading.';
        }

        if ($ctx['checkins'] !== []) {
            $latest = $ctx['checkins'][0];
            $out[] = '';
            $out[] = '=== LATEST CHECK-IN (' . $latest['week_start'] . ') ===';
            foreach (['weight_kg' => 'Weight (kg)', 'waist_cm' => 'Waist (cm)',
                      'hips_cm' => 'Hips', 'chest_cm' => 'Chest', 'arm_cm' => 'Arm',
                      'thigh_cm' => 'Thigh', 'neck_cm' => 'Neck'] as $k => $label) {
                if ($latest[$k] !== null) { $out[] = "{$label}: {$latest[$k]}"; }
            }
            if ($latest['self_report']) {
                $out[] = 'Their read on the week: "' . $latest['self_report'] . '"';
            }
        }

        if ($ctx['emphasis'] !== []) {
            $out[] = '';
            $out[] = '=== STANDING EMPHASIS (earned through adherence) ===';
            foreach ($ctx['emphasis'] as $e) {
                $out[] = "  - \"{$e['request']}\" [{$e['decision']}]"
                       . ($e['reasoning'] ? " — {$e['reasoning']}" : '');
            }
            $out[] = 'Keep honouring these without being re-asked.';
        }

        if ($ctx['vetoes'] !== []) {
            $out[] = '';
            $out[] = '=== STANDING VETOES ===';
            foreach ($ctx['vetoes'] as $v) {
                $out[] = "  - [{$v['reason_code']}] "
                       . ($v['reason_text'] ?: '(no detail)');
            }
            $out[] = 'These are permanent. Do not prescribe them again.';
        }

        if ($ctx['circumstances'] !== []) {
            $out[] = '';
            $out[] = '=== ACTIVE CIRCUMSTANCES ===';
            foreach ($ctx['circumstances'] as $c) {
                $span = $c['ends_on'] ? " (until {$c['ends_on']})" : ' (open-ended)';
                $out[] = "  - [{$c['kind']}] {$c['detail']}{$span}";
            }
            $out[] = 'Work around these. They are facts about their week, not preferences.';
        }

        /*
         * What a shared day means, and deliberately nothing more.
         *
         * Generation is still per-user (§10.6 is unbuilt), so this cannot promise the two
         * sessions match. What it CAN do is get both people in the gym on the same days, which
         * is where the accountability comes from — and tell the model that a shared day is not
         * a free choice among seven.
         *
         * §10.0 is the licence for the last line: where individual optimisation and staying
         * synced conflict, the pairing wins. Without saying so, the model will move a session
         * off a shared day whenever the split would be marginally better, which quietly
         * defeats the whole feature.
         */
        if ($ctx['buddy'] !== null) {
            $shared = [];
            foreach ($ctx['availability'] as $a) {
                if (($a['shared'] ?? false) === true) {
                    $shared[] = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][(int) $a['weekday']];
                }
            }

            $out[] = '';
            $out[] = '=== TRAINING BUDDY ===';
            $out[] = "They train with {$ctx['buddy']['buddy_name']}.";

            if ($shared !== []) {
                $out[] = 'Days marked SHARED are days the two of them train together: '
                       . implode(', ', $shared) . '.';
                $out[] = 'PUT A COMMITTED SESSION ON EVERY SHARED DAY. Being there at the same '
                       . 'time as their buddy is the point, and it outranks a marginally '
                       . 'better split. Do not move work off a shared day to tidy the week.';
                $out[] = 'You are writing one plan, for this user only. Do not describe what '
                       . 'their buddy is doing.';

                if (($ctx['skeleton'] ?? null) === null) {
                    /*
                     * This user is generating FIRST, so there is nothing to match yet — and the
                     * plan written here becomes the skeleton their buddy follows (§10.6).
                     *
                     * Not said out loud. Telling the model "you are setting the pattern for
                     * someone else" invites it to hedge toward a generic middle, and the
                     * follower needs a real plan for a real person to match, not a compromise
                     * written for nobody.
                     */
                    $out[] = 'Their buddy\'s week is not written yet, so do not assume the two '
                           . 'sessions match beyond the day itself.';
                }
            } elseif (($ctx['buddy_away'] ?? null) !== null) {
                /*
                 * §10.5. The shared days were dropped upstream — gatherContext discards the
                 * pair id when the buddy is away, so `availability` has no SHARED markers and
                 * the week is already solo by construction.
                 *
                 * Said out loud anyway, because the model can see they HAVE a buddy and would
                 * otherwise have to guess why none of the days are shared. A guess here reads
                 * as "invent something buddy-ish", which is the opposite of what is wanted.
                 */
                $out[] = 'Their buddy is away this week, so plan it for them ALONE, from '
                       . 'their own available days. Do not mention their buddy in the plan or '
                       . 'hold days open for them.';
                $out[] = 'This is not a setback and must not be framed as one. It is a normal '
                       . 'week that happens to be solo.';
            } else {
                $out[] = 'They have no shared days agreed yet, so plan this week normally.';
            }

            $surplus = $ctx['committed_target'] ?? [];
            if (($surplus['surplus'] ?? 0) > 0) {
                $out[] = '';
                if (($surplus['mode'] ?? null) !== null) {
                    $out[] = match ((string) $surplus['mode']) {
                        // §10.3b, in the model's terms rather than the schema's.
                        'keep_commitment' => 'Beyond the shared days they still want their full '
                            . 'committed count, so fill the rest from their own available days.',
                        'extras_optional' => 'Beyond the shared days, any further sessions are '
                            . 'OPTIONAL: is_committed false, no adherence cost.',
                        'match_buddy'     => 'They want their week to match the shared days only. '
                            . 'Do not add extra training days beyond them.',
                        default           => '',
                    };
                } else {
                    /*
                     * The surplus question is outstanding, and the SILENCE was the bug.
                     *
                     * With 5 committed days and 3 shared, this block used to render nothing at
                     * all unless the user had answered §10.3b. So the model was told to commit
                     * to exactly 5, shown 3 shared days, and left to work out where the other
                     * two went — and a live leader answered by putting a session on a Thursday
                     * the grid had closed, running a shared Friday to 60 minutes against a
                     * 45-minute window, and committing 7 sessions instead of 5.
                     *
                     * The default until they answer is their stated count filled from their own
                     * available days (committedTarget returns exactly that), so say so rather
                     * than leaving a gap the model has to fill by guessing.
                     */
                    $out[] = sprintf(
                        'They train %d days a week and %d of those are shared. The other %d '
                        . 'come from their OWN available days — the ones not marked SHARED. '
                        . 'They have not yet said whether they want it that way, so assume '
                        . 'they do.',
                        (int) $surplus['committed'],
                        (int) $surplus['committed'] - (int) $surplus['surplus'],
                        (int) $surplus['surplus']
                    );
                    $out[] = 'Do not invent a day to fit them on. If the available days cannot '
                           . 'hold the full count, commit to fewer.';
                }
            }

            /*
             * Is the pairing earning its keep? (§10.4)
             *
             * §10.0 trades training precision for adherence, on the bet that somebody beholden
             * to a friend sticks with it. This is the only place the app can check whether that
             * bet is paying off for THIS pair, and the coach is the only thing that can act on
             * the answer.
             *
             * Given as counts rather than a verdict. "3 of 4 shared, 1 of 4 solo" is a fact the
             * model can weigh against everything else it knows; "the pairing is working" is a
             * judgement made here with none of that context.
             */
            $ba = $ctx['buddy_adherence'] ?? null;
            if ($ba !== null) {
                $out[] = '';
                $out[] = sprintf(
                    'Over the last 4 weeks: %d of %d SHARED sessions done, %d of those actually '
                    . 'trained together.',
                    $ba['shared_completed'], $ba['shared_prescribed'], $ba['shared_together']
                );
                if ($ba['solo_prescribed'] > 0) {
                    $out[] = sprintf(
                        'On their own days: %d of %d done.',
                        $ba['solo_completed'], $ba['solo_prescribed']
                    );
                }
                $out[] = 'Use this to judge whether the pairing is helping them. If the shared '
                       . 'days are the ones getting done, lean further into them. If the shared '
                       . 'days are being skipped, or they are logged as done but not TOGETHER, '
                       . 'the pairing is not doing its job and the week should not depend on it.';
                $out[] = 'Do not comment on what their buddy did or did not do, and do not use '
                       . 'their buddy to shame them into training.';
            }
        }

        /*
         * The shared skeleton (§10.6, §10.2a).
         *
         * This is what makes the sessions themselves match rather than only the days. It was
         * absent for a long time on purpose: coordinating the inside of a session is a claim
         * per-user generation cannot keep, because the model sees one person and cannot know
         * what the other was told, so "identical between them" would agree by coincidence.
         *
         * It can be kept now because the buddy's plan is already WRITTEN and is being read back
         * here. The matching is against a real session, not an intention.
         *
         * Placed after the constraint block deliberately: whatever the skeleton asks for, the
         * user's own limits were stated first and the divergence instruction below points back
         * at them.
         */
        if (($ctx['skeleton'] ?? null) !== null) {
            $out[] = '';
            $out[] = BuddySkeleton::promptBlock($ctx['skeleton']);
        }

        if ($ctx['history'] === []) {
            $out[] = '';
            $out[] = 'Recent daily check-ins: none yet.';
        } else {
            $recent = array_slice($ctx['history'], 0, 7);
            $out[] = '';
            $out[] = '=== LAST 7 DAYS: ENERGY / SLEEP / SORENESS / MOOD ===';
            foreach ($recent as $d) {
                $bits = [];
                foreach (['energy' => 'E', 'sleep_hours' => 'sleep',
                          'soreness' => 'sore', 'mood' => 'mood'] as $k => $label) {
                    if ($d[$k] !== null) { $bits[] = "{$label} {$d[$k]}"; }
                }
                if ($bits !== []) {
                    $out[] = "  {$d['log_date']}: " . implode(', ', $bits);
                }
            }
        }

        /*
         * The weekly check-in gets prose rather than a JSON dump.
         *
         * The generic branch below hands the model json_encode() output, which is
         * fine for a machine-shaped fact and poor for a human's words: the user's
         * own report of their week is the most valuable single input here and it
         * should not arrive wrapped in braces and escapes.
         */
        if (isset($extra['check_in']) && is_array($extra['check_in'])) {
            $ci = $extra['check_in'];
            $out[] = '';
            $out[] = '=== THEIR WEEKLY CHECK-IN (week of ' . ($ci['week'] ?? '?') . ') ===';
            $out[] = 'This is the user speaking about the week that just ended. It '
                   . 'outranks any inference you would otherwise draw from the logs.';
            if (($ci['self_report'] ?? null) !== null) {
                $out[] = '';
                $out[] = 'They said: ' . $ci['self_report'];
            }
            if (($ci['weight_kg'] ?? null) !== null) {
                $out[] = "Weight this week: {$ci['weight_kg']} kg. Read the TREND, never "
                       . 'a single reading.';
            }
            if (($ci['emphasis_request'] ?? null) !== null) {
                // §7.2a. The decision has already been made and recorded by the
                // review pass; this is here so the plan HONOURS it rather than
                // re-litigating it.
                $out[] = '';
                $out[] = 'They asked for an emphasis shift: ' . $ci['emphasis_request'];
                $out[] = 'Any granted emphasis is listed under STANDING EMPHASIS above. '
                       . 'Apply it to this week rather than deciding it again.';
            }
            if (($ci['review'] ?? null) !== null) {
                $out[] = '';
                $out[] = 'Your own review of that week, already sent to them:';
                $out[] = (string) $ci['review'];
                $out[] = 'Build a week consistent with what you told them.';
            }
            unset($extra['check_in']);
        }

        /*
         * A mid-week interjection (§6), which is a different job from a weekly build.
         *
         * The week is already running: days before today have HAPPENED and cannot be
         * reshuffled, so the instruction has to say so. Getting this wrong would produce a
         * "revision" that moves Monday's session on a Thursday, which is not a plan, it is
         * a rewrite of history.
         */
        if (isset($extra['interjection']) && is_array($extra['interjection'])) {
            $ij = $extra['interjection'];
            $out[] = '';
            $out[] = '=== MID-WEEK REVISION (§7.1) ===';
            $out[] = 'This week is already underway and you are revising it, not building '
                   . 'it fresh.';
            if (($ij['from_day'] ?? null) !== null) {
                $out[] = "Today is {$ij['from_day']}. Days BEFORE today already happened: "
                       . 'reproduce them exactly as they were prescribed. Only change today '
                       . 'and the days after it.';
            }
            $out[] = '';
            $out[] = 'They told you: ' . (string) ($ij['said'] ?? '');
            if (($ij['change'] ?? '') !== '') {
                $out[] = '';
                $out[] = 'Your own decision about what to change: ' . (string) $ij['change'];
                $out[] = 'Carry that out. Do not re-litigate it.';
            }
            $out[] = '';
            $out[] = 'ADAPTATION IS NOT PUNISHMENT. Do not add make-up work, do not '
                   . 'prescribe a corrective deficit, and do not frame anything as owed.';
            unset($extra['interjection']);
        }

        /*
         * A veto replacement (§5.3).
         *
         * Shares the mid-week problem above — days already lived must come back unchanged —
         * but the instruction is narrower and worth stating separately. An interjection
         * says "something about my week changed"; a veto names ONE prescription and refuses
         * it. Replacing the whole week's approach because a user turned down Thursday's
         * salmon is an overreaction, and the failure mode is real: the model is being asked
         * to regenerate seven days and only one thing is supposed to move.
         */
        if (isset($extra['veto']) && is_array($extra['veto'])) {
            $v = $extra['veto'];
            $out[] = '';
            $out[] = '=== A REJECTED PRESCRIPTION, TO BE REPLACED (§5.3) ===';
            $out[] = 'This week is already underway. You are swapping out one thing, not '
                   . 'rebuilding the week.';
            if (($v['from_day'] ?? null) !== null) {
                $out[] = "Today is {$v['from_day']}. Days BEFORE today already happened: "
                       . 'reproduce them exactly as they were prescribed.';
            }
            $out[] = '';
            $out[] = sprintf(
                'They turned down the %s "%s" on %s. Reason [%s]: %s',
                (string) ($v['subject'] ?? 'item'),
                (string) ($v['subject_label'] ?? ''),
                (string) ($v['on_day'] ?? 'an unknown day'),
                (string) ($v['reason_code'] ?? 'other'),
                (string) ($v['said'] ?? '(no detail)')
            );
            if (($v['replacement'] ?? '') !== '') {
                $out[] = '';
                $out[] = 'Your own decision about the replacement: '
                       . (string) $v['replacement'];
                $out[] = 'Carry that out. Do not re-litigate it.';
            }
            $out[] = '';
            $out[] = 'REPLACE IT, DO NOT DELETE IT. The replacement still has to serve the '
                   . 'goal: similar macros from a faster meal, the same movement pattern '
                   . 'from a different exercise. A dropped meal or a missing session is not '
                   . 'a replacement.';
            $out[] = 'Change ONLY that one thing and whatever genuinely has to move with it. '
                   . 'Everything else in the week stays as it was.';
            if ((string) ($v['scope'] ?? '') === 'standing') {
                $out[] = 'They asked never to see this again, so do not reintroduce it later '
                       . 'in the week under another name.';
            }
            unset($extra['veto']);
        }

        foreach ($extra as $label => $value) {
            $out[] = '';
            $out[] = '=== ' . strtoupper((string) $label) . ' ===';
            $out[] = is_string($value) ? $value : json_encode($value);
        }

        return implode("\n", $out);
    }

    /**
     * The food half's request, written against the training week that already exists.
     *
     * MUCH SHORTER THAN userPrompt, deliberately. The profile, the food preferences, the
     * dietary constraints and the macro floors are all in the SYSTEM prompt, which both calls
     * share — so this only has to say what week, what training it is feeding, and the handful
     * of volatile signals that change how somebody should eat.
     *
     * Sharing the system prompt is also what keeps the second call cheap: Anthropic caches on
     * the literal prefix, so the profile is paid for once across the pair of requests. That
     * only holds while both calls build the system prompt identically, which is why this takes
     * the same $ctx rather than assembling its own.
     */
    private static function nutritionPrompt(
        array $ctx,
        string $weekStart,
        array $trainingPlan
    ): string {
        $out = [];
        $end = date('Y-m-d', strtotime($weekStart . ' +6 days'));

        $out[] = "Build the MEAL PLAN for the week of {$weekStart} (Monday) through "
               . "{$end} (Sunday).";
        $out[] = 'EVERY ONE of those seven dates needs a day entry, with its macro targets and '
               . 'its meals. Six days and a note is not a week; a partial answer is rejected '
               . 'and regenerated, which wastes everybody\'s time.';
        $out[] = 'The training for this week is already written and is shown below. Do not '
               . 'change it and do not return it — feed it.';

        /*
         * The week's training, as a shape rather than in full.
         *
         * What nutrition actually needs from a session is when it is, how long, and how hard —
         * enough to put the carbohydrates near the heavy days and keep the rest days lighter.
         * Sending the whole exercise list would spend a thousand tokens telling the food half
         * about rep ranges it cannot act on.
         */
        $out[] = '';
        $out[] = '=== THIS WEEK\'S TRAINING ===';
        $byDate = [];
        foreach ($trainingPlan['sessions'] ?? [] as $s) {
            $date = (string) ($s['date'] ?? '');
            if ($date === '') {
                continue;
            }
            $bits = [(string) ($s['session_type'] ?? 'session')];
            if (($s['focus'] ?? 'none') !== 'none') {
                $bits[] = (string) $s['focus'];
            }
            if (($s['target_minutes'] ?? null) !== null) {
                $bits[] = $s['target_minutes'] . ' min';
            }
            if (($s['is_committed'] ?? false) !== true) {
                $bits[] = 'optional';
            }
            $byDate[$date][] = implode(', ', $bits);
        }
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', strtotime($weekStart . " +{$i} days"));
            $name = date('D', strtotime($date));
            $out[] = "  {$name} {$date}: "
                   . ($byDate[$date] ?? [] ? implode(' | ', $byDate[$date]) : 'rest');
        }

        // Where the week is going, in the coach's own words, so the food matches the intent
        // rather than only the schedule.
        if (($trainingPlan['summary'] ?? '') !== '') {
            $out[] = '';
            $out[] = '=== THE WEEK\'S INTENT ===';
            $out[] = (string) $trainingPlan['summary'];
        }

        /*
         * The volatile signals that change how somebody should eat this week.
         *
         * Weight trend decides whether the targets hold or move. A check-in can say "I was
         * starving on the low days". A circumstance — travel, a week of night shifts — changes
         * what is cookable, which is the difference between a plan followed and a plan ignored.
         */
        /*
         * Guarded on the DIRECTION, not on the array being present.
         *
         * weightTrend always returns something; with fewer than two readings it returns just
         * ['points', 'direction' => 'insufficient data'] and no weeks or delta_kg. A null check
         * therefore passes and then interpolates two missing keys — which is exactly what a
         * live run caught here, as two PHP warnings inside a paid generation.
         */
        if (($ctx['trend']['direction'] ?? 'insufficient data') !== 'insufficient data') {
            $out[] = '';
            $out[] = '=== WEIGHT TREND ===';
            $out[] = "Direction over {$ctx['trend']['weeks']} readings: "
                   . "{$ctx['trend']['direction']} ({$ctx['trend']['delta_kg']} kg). "
                   . 'Read the trend, not any single reading.';
        }

        if (($ctx['checkins'] ?? []) !== []) {
            $latest = $ctx['checkins'][0];
            $said   = trim((string) ($latest['self_report'] ?? ''));
            if ($said !== '') {
                $out[] = '';
                $out[] = '=== WHAT THEY SAID LAST WEEK ===';
                $out[] = $said;
            }
        }

        if (($ctx['circumstances'] ?? []) !== []) {
            $out[] = '';
            $out[] = '=== ACTIVE CIRCUMSTANCES ===';
            foreach ($ctx['circumstances'] as $c) {
                $out[] = '  - ' . (string) ($c['note'] ?? $c['kind'] ?? '');
            }
        }

        // Recent adherence, as one line. A user who has been missing macro targets needs
        // easier food, not a better-argued plan.
        if (($ctx['history'] ?? []) !== []) {
            $onTarget = 0;
            foreach ($ctx['history'] as $d) {
                if ((int) ($d['macro_on_target'] ?? 0) === 1) {
                    $onTarget++;
                }
            }
            $out[] = '';
            $out[] = sprintf(
                'Recent eating: %d of %d logged days hit their macro targets.',
                $onTarget,
                count($ctx['history'])
            );
        }

        return implode("\n", $out);
    }

    // ---- persistence -------------------------------------------------------

    /**
     * Write a plan as a new version, superseding the current one.
     *
     * All inside one transaction: a half-written plan is worse than no plan,
     * because the live-version lookup would find something incomplete.
     */
    private static function persist(
        int $userId,
        string $weekStart,
        string $reason,
        array $plan,
        array $meta
    ): int {
        return DB::tx(function () use ($userId, $weekStart, $reason, $plan, $meta): int {
            // Next version number for this user-week.
            $row = DB::one(
                'SELECT COALESCE(MAX(version), 0) AS v FROM plan_versions
                 WHERE user_id = ? AND week_start = ?',
                [$userId, $weekStart]
            );
            $version = (int) ($row['v'] ?? 0) + 1;

            // Supersede the previous live version. Never deleted — adherence
            // for days already logged still points at it.
            DB::run(
                'UPDATE plan_versions SET superseded_at = NOW()
                 WHERE user_id = ? AND week_start = ? AND superseded_at IS NULL',
                [$userId, $weekStart]
            );

            $buddy = self::buddy($userId);

            $planVersionId = DB::insert(
                'INSERT INTO plan_versions
                 (user_id, week_start, version, reason, buddy_pair_id, summary, generation_meta)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId, $weekStart, $version, $reason,
                    $buddy['id'] ?? null,
                    self::composeSummary($plan),
                    json_encode($meta),
                ]
            );

            self::persistSessions($planVersionId, $plan);
            self::persistDays($planVersionId, $plan);

            /*
             * Link the shared days to the pair's skeleton (§10.6).
             *
             * After the sessions rather than inside the INSERT, because the key depends on the
             * pair and the date, not on anything in the plan payload — and doing it here means
             * one lookup per plan instead of one per session. Still inside the transaction, so
             * a plan is never briefly visible with its shared days unlinked.
             *
             * Runs for BOTH users of a pair: the leader stamps so the follower can find the
             * skeleton, the follower stamps so both rows carry the same key. A no-op when
             * unpaired.
             */
            BuddySkeleton::stamp($userId, $planVersionId, $weekStart);

            return $planVersionId;
        });
    }

    /**
     * Weeks whose training was written but whose food never arrived.
     *
     * The retry sweep's work list. Only live plans, only weeks that are current or still to
     * come — backfilling meals for a week somebody has already lived through would be spending
     * money to fill in history.
     *
     * @return list<array{user_id:int, week_start:string, plan_version_id:int}>
     */
    public static function awaitingNutrition(string $today): array
    {
        $out = [];
        foreach (DB::all(
            'SELECT pv.id, pv.user_id, pv.week_start, pv.generation_meta
             FROM plan_versions pv
             LEFT JOIN prescribed_days pd ON pd.plan_version_id = pv.id
             WHERE pv.superseded_at IS NULL
               AND pv.week_start >= DATE_SUB(?, INTERVAL 6 DAY)
             GROUP BY pv.id
             HAVING COUNT(pd.id) = 0',
            [$today]
        ) as $r) {
            /*
             * Counted rather than trusting the flag.
             *
             * generation_meta says what we BELIEVED at write time; the absence of
             * prescribed_days rows is what the user actually has. A plan whose nutrition was
             * filled in by an earlier sweep has rows and a stale flag, and a plan that lost its
             * days some other way still needs feeding.
             */
            $out[] = [
                'plan_version_id' => (int) $r['id'],
                'user_id'         => (int) $r['user_id'],
                'week_start'      => (string) $r['week_start'],
            ];
        }
        return $out;
    }

    /**
     * Fill in the food for a week whose training is already written.
     *
     * Adds prescribed_days to the EXISTING plan version rather than superseding it. A new
     * version would be dishonest — nothing about the training changed, and adherence points at
     * version ids, so re-versioning would orphan anything already logged against this week.
     *
     * Returns ['ok', 'error']. Safe to call twice: a plan that already has days is left alone.
     */
    public static function fillNutrition(int $userId, string $weekStart): array
    {
        $plan = DB::one(
            'SELECT id FROM plan_versions
             WHERE user_id = ? AND week_start = ? AND superseded_at IS NULL
             ORDER BY id DESC LIMIT 1',
            [$userId, $weekStart]
        );
        if ($plan === null) {
            return ['ok' => false, 'error' => 'No live plan for that week.'];
        }
        $planVersionId = (int) $plan['id'];

        $existing = DB::one(
            'SELECT COUNT(*) AS n FROM prescribed_days WHERE plan_version_id = ?',
            [$planVersionId]
        );
        if ((int) ($existing['n'] ?? 0) > 0) {
            return ['ok' => true, 'error' => null];   // already fed
        }

        $context = self::gatherContext($userId, $weekStart);
        if ($context['error'] !== null) {
            return ['ok' => false, 'error' => (string) $context['error']];
        }

        /*
         * The training half is read back from the database rather than regenerated.
         *
         * It is what the user is actually looking at, and asking the model for it again would
         * cost a second training week and risk producing a different one.
         */
        $sessions = [];
        foreach (DB::all(
            'SELECT session_date, session_type, focus, is_committed, target_minutes
             FROM prescribed_sessions
             WHERE plan_version_id = ?
             ORDER BY session_date, sort_order',
            [$planVersionId]
        ) as $s) {
            $sessions[] = [
                'date'           => (string) $s['session_date'],
                'session_type'   => (string) $s['session_type'],
                'focus'          => $s['focus'],
                'is_committed'   => (bool) $s['is_committed'],
                'target_minutes' => $s['target_minutes'] === null
                    ? null : (int) $s['target_minutes'],
            ];
        }

        $summaryRow = DB::one(
            'SELECT summary FROM plan_versions WHERE id = ?', [$planVersionId]
        );

        $nutrition = self::generateNutrition(
            $userId,
            $weekStart,
            $context,
            ['sessions' => $sessions, 'summary' => (string) ($summaryRow['summary'] ?? '')],
            'plan_generation'
        );
        if ($nutrition === null) {
            return ['ok' => false, 'error' => 'The meal plan could not be generated.'];
        }

        self::persistDays($planVersionId, $nutrition);

        // The flag was only ever a hint; the rows are the truth. Cleared anyway so the history
        // does not keep claiming a week is half-written after it has been finished.
        DB::run(
            "UPDATE plan_versions
             SET generation_meta = JSON_SET(
                 COALESCE(generation_meta, '{}'), '$.nutrition_pending', false
             )
             WHERE id = ?",
            [$planVersionId]
        );

        return ['ok' => true, 'error' => null];
    }

    /** Summary plus expectations — both are shown to the user. */
    private static function composeSummary(array $plan): ?string
    {
        $parts = array_filter([
            (string) ($plan['summary'] ?? ''),
            (string) ($plan['expectations'] ?? ''),
        ]);
        return $parts === [] ? null : implode("\n\n", $parts);
    }

    private static function persistSessions(int $planVersionId, array $plan): void
    {
        $order = 0;
        foreach ($plan['sessions'] ?? [] as $s) {
            $sessionId = DB::insert(
                'INSERT INTO prescribed_sessions
                 (plan_version_id, session_date, session_type, focus, focus_detail,
                  is_committed, target_minutes, location, warmup_minutes,
                  warmup_required, warmup_detail, rationale, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $planVersionId,
                    $s['date'],
                    $s['session_type'],
                    $s['focus'] ?? 'none',
                    $s['focus_detail'] ?? null,
                    ($s['is_committed'] ?? false) ? 1 : 0,
                    $s['target_minutes'] ?? null,
                    $s['location'] ?? null,
                    $s['warmup_minutes'] ?? null,
                    ($s['warmup_required'] ?? false) ? 1 : 0,
                    $s['warmup_detail'] ?? null,
                    $s['rationale'] ?? null,
                    $order++,
                ]
            );

            $exOrder = 0;
            foreach ($s['exercises'] ?? [] as $ex) {
                $slug = PlanSchema::resolveSlug((string) ($ex['slug'] ?? ''));
                if ($slug === null) {
                    // Validation already rejects unknown slugs, so reaching
                    // here means a slug resolved during validation and stopped
                    // resolving now — worth a log rather than silent skipping.
                    error_log('[yoked] persist: unresolvable slug ' . ($ex['slug'] ?? '?'));
                    continue;
                }
                $exRow = DB::one('SELECT id FROM exercises WHERE slug = ?', [$slug]);
                if ($exRow === null) {
                    continue;
                }

                DB::run(
                    'INSERT INTO prescribed_exercises
                     (session_id, exercise_id, block, sort_order, sets, target_reps,
                      target_weight_kg, is_per_side, target_seconds, target_distance_m,
                      target_rpe, rest_seconds, cardio_detail, rationale)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $sessionId,
                        (int) $exRow['id'],
                        $ex['block'] ?? 'main',
                        $exOrder++,
                        $ex['sets'] ?? null,
                        $ex['target_reps'] ?? null,
                        $ex['target_weight_kg'] ?? null,
                        ($ex['is_per_side'] ?? false) ? 1 : 0,
                        $ex['target_seconds'] ?? null,
                        $ex['target_distance_m'] ?? null,
                        $ex['target_rpe'] ?? null,
                        $ex['rest_seconds'] ?? null,
                        // cardio_prescription arrives as a flat string (the
                        // schema's optional-parameter budget rules out a nested
                        // object), so wrap it for the JSON column.
                        isset($ex['cardio_prescription'])
                            ? json_encode(['prescription' => $ex['cardio_prescription']])
                            : null,
                        $ex['rationale'] ?? null,
                    ]
                );
            }
        }
    }

    private static function persistDays(int $planVersionId, array $plan): void
    {
        // The goal constraint set is frozen onto each day, so a later change to
        // the user's goal does not retroactively re-judge logged history.
        $goalConstraints = null;
        $userRow = DB::one(
            'SELECT user_id FROM plan_versions WHERE id = ?', [$planVersionId]
        );
        if ($userRow !== null) {
            $goalConstraints = Goals::forUser((int) $userRow['user_id']);
        }

        foreach ($plan['days'] ?? [] as $d) {
            $dayId = DB::insert(
                'INSERT INTO prescribed_days
                 (plan_version_id, day_date, target_calories, target_protein_g,
                  target_fat_g, target_carbs_g, constraints, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $planVersionId,
                    $d['date'],
                    (int) $d['target_calories'],
                    (float) $d['target_protein_g'],
                    (float) $d['target_fat_g'],
                    (float) $d['target_carbs_g'],
                    json_encode($goalConstraints ?? []),
                    $d['notes'] ?? null,
                ]
            );

            $order = 0;
            foreach ($d['meals'] ?? [] as $m) {
                DB::run(
                    'INSERT INTO prescribed_meals
                     (prescribed_day_id, slot, kind, name, ingredients, method,
                      prep_minutes, calories, protein_g, fat_g, carbs_g, fiber_g,
                      target_note, suggestions, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $dayId,
                        $m['slot'],
                        $m['kind'] ?? 'specified',
                        $m['name'] ?? null,
                        isset($m['ingredients']) ? json_encode($m['ingredients']) : null,
                        $m['method'] ?? null,
                        $m['prep_minutes'] ?? null,
                        $m['calories'] ?? null,
                        $m['protein_g'] ?? null,
                        $m['fat_g'] ?? null,
                        $m['carbs_g'] ?? null,
                        $m['fiber_g'] ?? null,
                        $m['target_note'] ?? null,
                        isset($m['suggestions']) ? json_encode($m['suggestions']) : null,
                        $order++,
                    ]
                );
            }
        }
    }

    // ---- reads -------------------------------------------------------------

    /** The live plan version for a user-week, or null. */
    public static function live(int $userId, string $weekStart): ?array
    {
        return DB::one(
            'SELECT * FROM plan_versions
             WHERE user_id = ? AND week_start = ? AND superseded_at IS NULL',
            [$userId, $weekStart]
        );
    }

    /**
     * Has this user ever had a plan of any kind?
     *
     * Decides whether a generation is their genuine first ('initial') or a
     * subsequent week. Counts superseded and provisional versions too: a user who
     * had a provisional plan during the baseline is not receiving an "initial"
     * plan afterwards, and reason is meant to describe what CAUSED the version.
     */
    public static function hasEverHadPlan(int $userId): bool
    {
        return DB::one(
            'SELECT 1 AS x FROM plan_versions WHERE user_id = ? LIMIT 1',
            [$userId]
        ) !== null;
    }

    /** A full plan, hydrated for display. */
    public static function hydrate(int $planVersionId): ?array
    {
        $plan = DB::one('SELECT * FROM plan_versions WHERE id = ?', [$planVersionId]);
        if ($plan === null) {
            return null;
        }

        $sessions = DB::all(
            'SELECT * FROM prescribed_sessions
             WHERE plan_version_id = ? ORDER BY session_date, sort_order',
            [$planVersionId]
        );
        foreach ($sessions as &$s) {
            $s['exercises'] = DB::all(
                'SELECT pe.*, e.slug, e.name, e.category, e.pattern, e.load_type
                 FROM prescribed_exercises pe
                 JOIN exercises e ON e.id = pe.exercise_id
                 WHERE pe.session_id = ?
                 ORDER BY FIELD(pe.block, "warmup", "main", "core", "cooldown"), pe.sort_order',
                [(int) $s['id']]
            );
        }
        unset($s);

        $days = DB::all(
            'SELECT * FROM prescribed_days
             WHERE plan_version_id = ? ORDER BY day_date',
            [$planVersionId]
        );
        foreach ($days as &$d) {
            $d['meals'] = DB::all(
                'SELECT * FROM prescribed_meals
                 WHERE prescribed_day_id = ? ORDER BY sort_order',
                [(int) $d['id']]
            );
        }
        unset($d);

        $plan['sessions'] = $sessions;
        $plan['days']     = $days;
        return $plan;
    }
}
