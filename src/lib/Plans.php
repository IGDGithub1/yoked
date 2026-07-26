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

        // Validation runs inside the retry loop: a violating plan names its
        // violations in the retry prompt and is regenerated. Two retries, then
        // fail loudly (SPEC-safety.md §5).
        $result = Claude::generateValidated(
            PlanSchema::build(),
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
            fn(array $plan): array => Safety::validatePlan($plan, $userId),
            3,
            // Graft a retry's answer onto the previous one rather than replacing
            // it. See mergePlans, and the note in Claude::generateValidated.
            self::mergePlans(...)
        );

        if (!$result['ok']) {
            return ['ok' => false, 'plan_version_id' => null, 'plan' => null,
                    'error' => $result['error'] ?? 'Generation failed.',
                    'violations' => $result['violations'] ?? []];
        }

        $planVersionId = self::persist($userId, $weekStart, $reason, $result['data'], [
            'attempts' => $result['attempts'] ?? 1,
            'model'    => $result['model'] ?? null,
            'usage'    => $result['usage'] ?? [],
        ]);

        return ['ok' => true, 'plan_version_id' => $planVersionId,
                'plan' => $result['data'], 'error' => null, 'violations' => []];
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

        return [
            'error'        => null,
            'user'         => $user,
            'profile'      => $profile,
            'goal'         => $goal,
            'availability' => $availability,
            'food'         => DB::one('SELECT * FROM food_preferences WHERE user_id = ?', [$userId]),
            'training'     => DB::one('SELECT * FROM training_preferences WHERE user_id = ?', [$userId]),
            'constraints'  => Safety::promptBlock($userId),
            'vocabulary'   => PlanSchema::vocabulary(),
            'history'      => self::adherenceHistory($userId, $weekStart),
            'loads'        => self::recentLoads($userId),
            'vetoes'       => self::standingVetoes($userId),
            'circumstances' => self::activeCircumstances($userId, $weekStart),
            'emphasis'     => self::activeEmphasis($userId),
            'checkins'     => self::recentCheckins($userId),
            'trend'        => self::weightTrend($userId),
            'buddy'        => self::buddy($userId),
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
        $out[] = self::rules((int) $p['committed_days_per_week'], (string) ($p['core_emphasis'] ?? 'standard'));

        $out[] = '';
        $out[] = '=== EXERCISE VOCABULARY ===';
        $out[] = 'Use ONLY these slugs. They are grouped by category and movement pattern.';
        foreach ($ctx['vocabulary'] as $category => $patterns) {
            $out[] = strtoupper((string) $category) . ':';
            foreach ($patterns as $pattern => $slugs) {
                $out[] = "  {$pattern}: " . implode(', ', $slugs);
            }
        }

        return implode("\n", $out);
    }

    /** The rules that make a plan conform to the specs. */
    private static function rules(int $committed, string $coreEmphasis): string
    {
        $coreMinutes = match ($coreEmphasis) {
            'light' => '5-8', 'heavy' => '12-15', 'off' => '0', default => '8-12',
        };

        $rules = [
            "COMMITTED VS OPTIONAL: mark exactly {$committed} sessions as "
            . 'is_committed true. That is the week. Anything beyond is '
            . 'is_committed false — bonus, never debt. Active recovery and rest '
            . 'days never count toward the committed total.',

            'CUT BY GOAL VALUE, NOT CALENDAR POSITION: if the ideal structure '
            . 'wants more sessions than the committed count allows, drop what '
            . 'the GOAL needs least. If cardio is their lagging metric, keep the '
            . 'cardio day committed and give up a strength day — do not simply '
            . 'drop the last day of the week.',
        ];

        if ($coreEmphasis !== 'off') {
            $rules[] = "CORE ON EVERY STRENGTH DAY: {$coreMinutes} minutes, block "
                . '"core", placed AFTER the main work — a fatigued core before '
                . 'heavy squats is a form risk. Pattern-match it to the day: '
                . 'lower/squat gets anti-rotation + lower back + isometric holds; '
                . 'lower/hinge gets lower back + anti-rotation + loaded carries; '
                . 'upper/horizontal gets anti-extension + flexion; upper/vertical '
                . 'gets anti-lateral-flexion + overhead stability. Brief '
                . 'anti-rotation activation may go in the warm-up.';
        }

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
        $out[] = "Build the week of {$weekStart} (Monday) through {$end} (Sunday).";
        $out[] = 'Every date in that range needs a nutrition day. Training '
               . 'sessions go on the days the availability grid allows.';

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

        if ($ctx['buddy'] !== null) {
            $out[] = '';
            $out[] = '=== TRAINING BUDDY ===';
            $out[] = "They train with {$ctx['buddy']['buddy_name']}. Where a session "
                   . 'is shared, the CORE BLOCK should be identical between them — '
                   . 'same exercises, same sets, same reps. Loaded core work shares '
                   . 'the movement and scales the weight. Main lifts diverge freely '
                   . 'by ability.';
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

            return $planVersionId;
        });
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
