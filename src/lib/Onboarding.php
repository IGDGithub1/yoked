<?php
declare(strict_types=1);

/**
 * The onboarding quiz: answer storage, and projection into the typed tables
 * the coaching engine reads.
 *
 * Implements SPEC-onboarding.md. Two things matter more than the rest:
 *
 * 1. Answers are stored twice, deliberately. onboarding_answers is the record
 *    of what was asked and answered — resumable, and the question set will
 *    churn during build. The typed tables (profiles, goals, availability,
 *    food_preferences, training_preferences, user_constraints) are the working
 *    copies that generation and validation actually query. Projection is
 *    idempotent, so re-answering a question corrects the projection.
 *
 * 2. Constraint extraction decides tiers, and Safety.php enforces whatever it
 *    produces. A mis-tiered constraint quietly degrades the whole safety model,
 *    so the defaults here are conservative and the one case where a user can
 *    plausibly harm themselves by mis-clicking is flagged for confirmation
 *    rather than silently accepted (see extractInjuries).
 */
final class Onboarding
{
    /** Sections that must be complete before anything can be generated. */
    public const BLOCKING_SECTIONS = ['1', '2', '3'];

    /** All sections, in order, with their question keys. */
    public const SECTIONS = [
        '1'  => ['name' => 'Identity & metrics', 'keys' => ['1.1', '1.2', '1.3', '1.4', '1.5', '1.6', '1.7']],
        '2'  => ['name' => 'Goals',              'keys' => ['2.1', '2.2', '2.3', '2.4', '2.5', '2.6']],
        '3'  => ['name' => 'Medical & safety',   'keys' => ['3.1', '3.2', '3.3', '3.4', '3.5', '3.6', '3.7']],
        '4'  => ['name' => 'Food preferences',   'keys' => ['4.1', '4.2', '4.3', '4.4', '4.5', '4.6', '4.7', '4.8', '4.9', '4.10']],
        '5'  => ['name' => 'Food logistics',     'keys' => ['5.1', '5.2', '5.3', '5.4', '5.5', '5.6', '5.7', '5.8', '5.9']],
        '6'  => ['name' => 'Training history',   'keys' => ['6.1', '6.2', '6.3', '6.4', '6.5', '6.6', '6.7', '6.8', '6.9', '6.10', '6.11']],
        '7'  => ['name' => 'Schedule & access',  'keys' => ['7.1', '7.2', '7.3', '7.4']],
        '8'  => ['name' => 'Daily life',         'keys' => ['8.1', '8.2', '8.3', '8.4', '8.5', '8.6']],
        '9'  => ['name' => 'Coaching style',     'keys' => ['9.1', '9.2', '9.3', '9.4', '9.5', '9.6', '9.7', '9.8']],
        '10' => ['name' => 'Optional context',   'keys' => ['10.1', '10.2', '10.3', '10.4']],
    ];

    /**
     * Questions that must have an answer for their section to count as done.
     *
     * Not every key is required — 1.6 (measurements) and 1.7 (photos) are
     * prompted again after the baseline fortnight, when the user has more
     * reason to care.
     */
    private const REQUIRED_KEYS = [
        '1' => ['1.1', '1.2', '1.3', '1.4', '1.5'],
        '2' => ['2.1', '2.3', '2.5', '2.6'],
        // 3.1-3.5 may legitimately be "none", but must be ANSWERED — an unasked
        // allergy question is not the same as no allergies.
        '3' => ['3.1', '3.2', '3.4', '3.5', '3.6'],
        '4' => ['4.3', '4.4', '4.6'],
        '5' => ['5.1', '5.2', '5.3', '5.4'],
        '6' => ['6.1', '6.2', '6.8'],
        '7' => ['7.1'],
        '8' => ['8.1', '8.3', '8.5'],
        '9' => ['9.1', '9.4'],
        '10' => [],
    ];

    // ---- answer storage ----------------------------------------------------

    /**
     * Save one answer. Idempotent — re-answering replaces and re-projects.
     *
     * @return array{ok: bool, error: ?string, confirm: ?array}
     *         `confirm` is set when the answer is accepted but warrants a
     *         second look (see extractInjuries).
     */
    public static function saveAnswer(int $userId, string $key, $value): array
    {
        if (!self::isKnownKey($key)) {
            return ['ok' => false, 'error' => "Unknown question '{$key}'.", 'confirm' => null];
        }

        DB::run(
            'INSERT INTO onboarding_answers (user_id, question_key, answer)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE answer = VALUES(answer), answered_at = NOW()',
            [$userId, $key, json_encode($value)]
        );

        // Project immediately rather than at the end. A user who abandons
        // halfway still has a usable partial profile, and the SPA can show real
        // derived values (BMR, week shape) as they go.
        $confirm = self::projectSection($userId, self::sectionOf($key));

        self::advanceState($userId);

        return ['ok' => true, 'error' => null, 'confirm' => $confirm];
    }

    /** Save several answers at once — one section's worth from the SPA. */
    public static function saveAnswers(int $userId, array $answers): array
    {
        $errors   = [];
        $confirms = [];
        $sections = [];

        foreach ($answers as $key => $value) {
            if (!self::isKnownKey((string) $key)) {
                $errors[] = "Unknown question '{$key}'.";
                continue;
            }
            DB::run(
                'INSERT INTO onboarding_answers (user_id, question_key, answer)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE answer = VALUES(answer), answered_at = NOW()',
                [$userId, (string) $key, json_encode($value)]
            );
            $sections[self::sectionOf((string) $key)] = true;
        }

        // Project once per touched section, not once per answer.
        foreach (array_keys($sections) as $section) {
            $c = self::projectSection($userId, (string) $section);
            if ($c !== null) {
                $confirms[] = $c;
            }
        }

        self::advanceState($userId);

        return [
            'ok'       => $errors === [],
            'errors'   => $errors,
            'confirm'  => $confirms,
            'progress' => self::progress($userId),
        ];
    }

    /** Every stored answer, keyed by question. */
    public static function answers(int $userId): array
    {
        $out = [];
        foreach (DB::all(
            'SELECT question_key, answer FROM onboarding_answers WHERE user_id = ?',
            [$userId]
        ) as $row) {
            $out[$row['question_key']] = json_decode((string) $row['answer'], true);
        }
        return $out;
    }

    private static function answer(int $userId, string $key, $default = null)
    {
        $row = DB::one(
            'SELECT answer FROM onboarding_answers WHERE user_id = ? AND question_key = ?',
            [$userId, $key]
        );
        if ($row === null) {
            return $default;
        }
        $v = json_decode((string) $row['answer'], true);
        return $v === null ? $default : $v;
    }

    // ---- progress ----------------------------------------------------------

    /** Per-section completion, plus whether generation is unblocked. */
    public static function progress(int $userId): array
    {
        $answered = array_keys(self::answers($userId));

        $sections = [];
        foreach (self::SECTIONS as $id => $meta) {
            $required = self::REQUIRED_KEYS[$id] ?? [];
            $missing  = array_values(array_diff($required, $answered));
            $sections[$id] = [
                'name'     => $meta['name'],
                'complete' => $missing === [],
                'missing'  => $missing,
                'answered' => count(array_intersect($meta['keys'], $answered)),
                'total'    => count($meta['keys']),
                'blocking' => in_array((string) $id, self::BLOCKING_SECTIONS, true),
            ];
        }

        $blockingDone = true;
        foreach (self::BLOCKING_SECTIONS as $id) {
            if (!$sections[$id]['complete']) {
                $blockingDone = false;
                break;
            }
        }

        $allDone = true;
        foreach ($sections as $id => $s) {
            if ($id !== '10' && !$s['complete']) {   // §10 is optional
                $allDone = false;
                break;
            }
        }

        return [
            'sections'      => $sections,
            'blocking_done' => $blockingDone,
            'all_done'      => $allDone,
        ];
    }

    /** Where the SPA should send this user next. */
    public static function nextStep(int $userId, string $state): array
    {
        if ($state === 'active' || $state === 'baseline') {
            return ['step' => $state === 'baseline' ? 'baseline' : 'dashboard'];
        }

        $progress = self::progress($userId);
        foreach (self::SECTIONS as $id => $meta) {
            if ($id === '10') {
                continue;
            }
            if (!$progress['sections'][$id]['complete']) {
                return [
                    'step'    => 'onboarding',
                    'section' => (string) $id,
                    'name'    => $meta['name'],
                    'missing' => $progress['sections'][$id]['missing'],
                ];
            }
        }
        // Everything required is answered but state has not advanced — the
        // baseline has not been started yet.
        return ['step' => 'start_baseline'];
    }

    /**
     * Move onboarding_state forward when the answers justify it.
     *
     * Never moves it backward: a user who edits an answer after starting the
     * baseline should not be dumped back into the quiz.
     */
    private static function advanceState(int $userId): void
    {
        $row = DB::one('SELECT onboarding_state FROM users WHERE id = ?', [$userId]);
        if ($row === null) {
            return;
        }
        $state = (string) $row['onboarding_state'];

        // Past the quiz already — leave it alone.
        if (in_array($state, ['baseline', 'active'], true)) {
            return;
        }

        // Answering anything at all moves pending → in_progress, and that is the
        // only transition here. Completing the quiz deliberately does NOT advance
        // the state: startBaseline() is the act that does, because the user has to
        // be told what the two weeks are for and agree to start.
        if ($state === 'pending') {
            DB::run("UPDATE users SET onboarding_state = 'in_progress' WHERE id = ?", [$userId]);
        }
    }

    /**
     * Begin the baseline fortnight.
     *
     * Separate from advanceState because it is a deliberate act, not a
     * side-effect of answering the last question: the user is told what the two
     * weeks are for and agrees to start.
     */
    public static function startBaseline(int $userId): array
    {
        $progress = self::progress($userId);
        if (!$progress['all_done']) {
            return ['ok' => false, 'error' => 'Some required questions are still unanswered.',
                    'progress' => $progress];
        }
        DB::run(
            'UPDATE users SET onboarding_state = "baseline" WHERE id = ? AND onboarding_state = "in_progress"',
            [$userId]
        );
        return ['ok' => true, 'error' => null, 'progress' => $progress];
    }

    // ---- projection --------------------------------------------------------

    /**
     * Project a section's answers into the typed tables.
     *
     * Returns a confirmation prompt when one is warranted, otherwise null.
     */
    private static function projectSection(int $userId, string $section): ?array
    {
        return match ($section) {
            '1'  => self::projectIdentity($userId),
            '2'  => self::projectGoal($userId),
            '3'  => self::projectMedical($userId),
            '4', '5' => self::projectFood($userId),
            '6'  => self::projectTraining($userId),
            '7'  => self::projectAvailability($userId),
            '8'  => self::projectDailyLife($userId),
            '9'  => self::projectCoachingStyle($userId),
            default => null,
        };
    }

    /** Ensure a profiles row exists before any partial update touches it. */
    private static function ensureProfile(int $userId): void
    {
        DB::run('INSERT IGNORE INTO profiles (user_id) VALUES (?)', [$userId]);
    }

    private static function projectIdentity(int $userId): ?array
    {
        self::ensureProfile($userId);

        $units = self::answer($userId, '1.5', 'imperial');

        // Height and weight arrive in the user's chosen units and are stored
        // metric. Storing what was typed would mean every reader has to know
        // which units it is in.
        $height = self::answer($userId, '1.3');
        $heightCm = null;
        if (is_numeric($height)) {
            $heightCm = $units === 'metric' ? (float) $height : (float) $height * 2.54;
        }

        DB::run(
            'UPDATE profiles SET date_of_birth = ?, birth_sex = ?, height_cm = ?, units = ?
             WHERE user_id = ?',
            [
                Validate::date(self::answer($userId, '1.1')),
                Validate::enum(self::answer($userId, '1.2'), ['male', 'female']),
                $heightCm !== null ? round($heightCm, 1) : null,
                Validate::enum($units, ['imperial', 'metric']) ?? 'imperial',
                $userId,
            ]
        );

        // Starting weight and measurements land as the first check-in, not on
        // profiles — weight is a time series, and storing "current weight" as a
        // scalar means the trend has no origin point.
        $weight = self::answer($userId, '1.4');
        if (is_numeric($weight)) {
            $weightKg = $units === 'metric' ? (float) $weight : (float) $weight * 0.453592;
            $m = self::answer($userId, '1.6', []);
            $toCm = fn($v) => is_numeric($v)
                ? round($units === 'metric' ? (float) $v : (float) $v * 2.54, 1)
                : null;

            DB::run(
                'INSERT INTO weekly_checkins
                 (user_id, week_start, weight_kg, waist_cm, hips_cm, chest_cm,
                  arm_cm, thigh_cm, neck_cm, status, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "completed", NOW())
                 ON DUPLICATE KEY UPDATE
                   weight_kg = VALUES(weight_kg), waist_cm = VALUES(waist_cm),
                   hips_cm = VALUES(hips_cm), chest_cm = VALUES(chest_cm),
                   arm_cm = VALUES(arm_cm), thigh_cm = VALUES(thigh_cm),
                   neck_cm = VALUES(neck_cm)',
                [
                    $userId,
                    date('Y-m-d', strtotime('monday this week')),
                    round($weightKg, 2),
                    $toCm($m['waist'] ?? null), $toCm($m['hips'] ?? null),
                    $toCm($m['chest'] ?? null), $toCm($m['arm'] ?? null),
                    $toCm($m['thigh'] ?? null), $toCm($m['neck'] ?? null),
                ]
            );
        }
        return null;
    }

    private static function projectGoal(int $userId): ?array
    {
        $primary = Validate::enum(self::answer($userId, '2.1'), [
            'lose_fat', 'build_muscle', 'recomp', 'improve_cardio',
            'improve_strength', 'general_health',
        ]);
        if ($primary === null) {
            return null;   // not answered yet
        }

        $timeline = Validate::enum(self::answer($userId, '2.5'), [
            '8_weeks', '12_weeks', '16_weeks', '6_months', '1_year', 'none',
        ]);
        $horizon = match ($timeline) {
            '8_weeks' => 8, '12_weeks' => 12, '16_weeks' => 16,
            '6_months' => 26, '1_year' => 52, default => null,
        };

        // Preset selection is advisory — Claude may propose a different one and
        // the user's explicit choice always wins. Matched on `suits`, which is
        // why that column exists.
        $preset = DB::one(
            'SELECT id FROM goal_presets WHERE JSON_CONTAINS(suits, JSON_QUOTE(?)) LIMIT 1',
            [$primary]
        );

        $existing = DB::one(
            'SELECT id FROM goals WHERE user_id = ? AND status = "active"', [$userId]
        );

        $params = [
            $primary,
            $preset['id'] ?? null,
            json_encode(Validate::enumList(self::answer($userId, '2.2', []), [
                'lose_fat', 'build_muscle', 'recomp', 'improve_cardio',
                'improve_strength', 'general_health',
            ]) ?? []),
            // Verbatim, never parsed — the highest-signal answer in the quiz.
            Validate::str(self::answer($userId, '2.3'), 0, 4000),
            Validate::str(self::answer($userId, '2.4'), 0, 500),
            $timeline,
            $horizon,
            Validate::enum(self::answer($userId, '2.6'), ['scale', 'look_feel', 'both']) ?? 'both',
        ];

        if ($existing !== null) {
            DB::run(
                'UPDATE goals SET primary_goal = ?, goal_preset_id = ?, secondary_goals = ?,
                                  success_statement = ?, event_note = ?, requested_timeline = ?,
                                  horizon_weeks = ?, scale_vs_feel = ?
                 WHERE id = ?',
                array_merge($params, [(int) $existing['id']])
            );
        } else {
            DB::run(
                'INSERT INTO goals
                 (primary_goal, goal_preset_id, secondary_goals, success_statement,
                  event_note, requested_timeline, horizon_weeks, scale_vs_feel,
                  user_id, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "active")',
                array_merge($params, [$userId])
            );
        }
        return null;
    }

    // ---- §3: the constraint extraction ------------------------------------

    /**
     * Medical answers into user_constraints.
     *
     * Replaces this user's onboarding-sourced constraints wholesale each time,
     * so editing an answer removes what it used to imply. User-edited and
     * veto-promoted constraints are left alone — they did not come from here.
     */
    private static function projectMedical(int $userId): ?array
    {
        self::ensureProfile($userId);

        DB::run(
            'DELETE FROM user_constraints WHERE user_id = ? AND source = "onboarding"',
            [$userId]
        );

        self::extractAllergies($userId);
        self::extractConditions($userId);
        $confirm = self::extractInjuries($userId);
        self::extractDislikedMovements($userId);
        self::extractRefusedCardio($userId);
        self::extractDietaryPattern($userId);

        DB::run(
            'UPDATE profiles SET physician_clearance = ?, medications = ?, trainer_notes = ?
             WHERE user_id = ?',
            [
                Validate::enum(self::answer($userId, '3.6'), ['yes', 'no', 'not_asked']),
                Validate::str(self::answer($userId, '3.3'), 0, 2000),
                Validate::str(self::answer($userId, '3.7'), 0, 4000),
                $userId,
            ]
        );

        return $confirm;
    }

    /** 3.1 — allergies and intolerances. Always hard. */
    private static function extractAllergies(int $userId): void
    {
        $a = self::answer($userId, '3.1', []);
        foreach (self::asItemList($a) as $item) {
            DB::run(
                'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
                 VALUES (?, "food", "hard", ?, ?, "onboarding")',
                [$userId, strtolower($item), 'Allergy or intolerance reported at onboarding']
            );
        }
    }

    /**
     * 3.2 — conditions. Hard, but as MODIFIERS: guidance text, nothing banned.
     *
     * The guidance is what reaches the generation prompt, so it has to say what
     * to do differently rather than merely naming the condition. "Type II
     * diabetes" alone tells the model nothing actionable.
     */
    private static function extractConditions(int $userId): void
    {
        $guidance = [
            'diabetes_t1' => 'Carb distribution and meal timing matter. Avoid large '
                . 'isolated carb loads; spread carbs across meals. NOT a carb ban.',
            'diabetes_t2' => 'Carb distribution and meal timing matter. Avoid large '
                . 'isolated carb loads; spread carbs across meals. NOT a carb ban.',
            'heart'       => 'Include a real warm-up on every session and progress '
                . 'intensity gradually. NOT an intensity ceiling.',
            'hypertension' => 'Avoid prolonged breath-holding under load; caution on '
                . 'heavy overhead work. Prefer machine pressing to heavy barbell overhead.',
            'thyroid'     => 'Energy and recovery may vary more than expected; watch '
                . 'the daily check-ins for a pattern.',
            'pcos'        => 'Insulin sensitivity may be reduced; carb distribution matters.',
            'gi'          => 'Note trigger foods; prefer lower-FODMAP options where flagged.',
            'joint'       => 'Prefer lower-impact substitutions for the affected joint.',
        ];

        $conditions = self::asItemList(self::answer($userId, '3.2', []));
        $freeText   = Validate::str(self::answer($userId, '3.2_detail'), 0, 2000);

        foreach ($conditions as $c) {
            $key = strtolower(str_replace([' ', '-'], '_', $c));
            DB::run(
                'INSERT INTO user_constraints
                 (user_id, kind, tier, subject, reason, guidance, source)
                 VALUES (?, "condition", "hard", ?, ?, ?, "onboarding")',
                [
                    $userId,
                    $key,
                    'Reported at onboarding' . ($freeText ? ": {$freeText}" : ''),
                    // An unrecognised condition still gets a constraint row, with
                    // the user's own words as guidance — better than dropping it
                    // because it was not on our list.
                    $guidance[$key] ?? ($freeText ?: 'User-reported condition; take into account.'),
                ]
            );
        }
    }

    /**
     * 3.4 — injuries. The user picks the tier (§3.4), and can pick wrong.
     *
     * Someone marks a genuinely serious injury `soft` because they do not want
     * to feel limited, and Claude will then propose loading it. So: accept the
     * answer, but when the free text reads like something clinical, hand back a
     * confirmation prompt. Their call either way — just not an accident.
     */
    private static function extractInjuries(int $userId): ?array
    {
        $injuries = self::answer($userId, '3.4', []);
        if (!is_array($injuries)) {
            return null;
        }

        // Words that suggest a clinician was involved, or that tissue actually
        // failed. Not a diagnosis — a prompt to look twice.
        $seriousMarkers = [
            'surgery', 'surgical', 'operation', 'post-op', 'postop',
            'tear', 'torn', 'rupture', 'ruptured', 'fracture', 'fractured', 'broken',
            'acl', 'mcl', 'pcl', 'meniscus', 'labrum', 'labral', 'rotator cuff',
            'herniat', 'disc', 'sciatica', 'dislocat', 'replacement',
            'physio', 'physical therapy', 'doctor', 'surgeon', 'orthopedic',
            'orthopaedic', 'mri', 'chronic',
        ];

        $needsConfirm = [];

        foreach ($injuries as $inj) {
            if (!is_array($inj)) {
                continue;
            }
            $area        = Validate::str($inj['area'] ?? null, 1, 120);
            $description = Validate::str($inj['description'] ?? null, 0, 500) ?? '';
            $tier        = Validate::enum($inj['tier'] ?? 'hard', ['hard', 'soft']) ?? 'hard';
            $workingToward = (bool) ($inj['work_up_to'] ?? false);

            if ($area === null) {
                continue;
            }

            $progression = null;
            if ($tier === 'soft' && $workingToward) {
                // The anti-staleness mechanism: scaffold toward it rather than
                // excluding it forever.
                $progression = json_encode([
                    'target' => $area,
                    'status' => 'working_toward',
                ]);
            }

            DB::run(
                'INSERT INTO user_constraints
                 (user_id, kind, tier, subject, reason, progression, source)
                 VALUES (?, "movement", ?, ?, ?, ?, "onboarding")',
                [
                    $userId, $tier, strtolower($area),
                    $description !== '' ? $description : 'Injury reported at onboarding',
                    $progression,
                ]
            );

            // Only worth asking about a soft tier — a hard one is already the
            // cautious answer.
            if ($tier === 'soft' && $description !== '') {
                $haystack = strtolower($description);
                foreach ($seriousMarkers as $marker) {
                    if (str_contains($haystack, $marker)) {
                        $needsConfirm[] = [
                            'question'    => '3.4',
                            'subject'     => $area,
                            'description' => $description,
                            'matched'     => $marker,
                            'current_tier' => 'soft',
                            'message'     => "You marked \"{$area}\" as something to work "
                                . 'around rather than avoid entirely, but described it as '
                                . "involving \"{$marker}\". Soft means the plan may still "
                                . 'load it, with a reason given. Is that what you want?',
                        ];
                        break;
                    }
                }
            }
        }

        return $needsConfirm === [] ? null : [
            'type'  => 'tier_check',
            'items' => $needsConfirm,
        ];
    }

    /** 3.5 — movements they cannot do or hate. Soft unless flagged otherwise. */
    private static function extractDislikedMovements(int $userId): void
    {
        foreach (self::asItemList(self::answer($userId, '3.5', [])) as $m) {
            DB::run(
                'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
                 VALUES (?, "movement", "soft", ?, ?, "onboarding")',
                [$userId, strtolower($m), 'Disliked or difficult; reported at onboarding']
            );
        }
    }

    /**
     * 6.9 — refused cardio. Soft by design, though it behaves nearly hard.
     *
     * Prescribing cardio someone explicitly refused is the fastest way to lose
     * adherence. It stays soft because a user whose GOAL is better cardio while
     * hating all of it would otherwise have nothing prescribable — Claude
     * proposes the least-hated option with a reason.
     */
    private static function extractRefusedCardio(int $userId): void
    {
        foreach (self::asItemList(self::answer($userId, '6.9', [])) as $c) {
            DB::run(
                'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
                 VALUES (?, "cardio", "soft", ?, ?, "onboarding")',
                [$userId, strtolower($c), 'Explicitly refused at onboarding']
            );
        }
    }

    /** 4.3 — dietary pattern. Hard where it is an ethical or religious line. */
    private static function extractDietaryPattern(int $userId): void
    {
        $pattern = Validate::enum(self::answer($userId, '4.3'), [
            'none', 'vegetarian', 'vegan', 'pescatarian', 'keto',
            'paleo', 'halal', 'kosher', 'other',
        ]);
        if ($pattern === null || $pattern === 'none') {
            return;
        }

        // vegan/vegetarian/halal/kosher are lines a user does not want crossed
        // by accident. keto/paleo are dietary preferences that a coach may
        // legitimately propose bending.
        $hard = in_array($pattern, ['vegan', 'vegetarian', 'pescatarian', 'halal', 'kosher'], true);

        DB::run(
            'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
             VALUES (?, "food", ?, ?, ?, "onboarding")',
            [
                $userId,
                $hard ? 'hard' : 'soft',
                'dietary_pattern:' . $pattern,
                'Dietary pattern reported at onboarding',
            ]
        );

        // 4.1 — foods they will not eat. Soft: a preference, not an allergy.
        foreach (self::asItemList(self::answer($userId, '4.1', [])) as $food) {
            DB::run(
                'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
                 VALUES (?, "food", "soft", ?, ?, "onboarding")',
                [$userId, strtolower($food), 'Will not eat; reported at onboarding']
            );
        }
    }

    // ---- §4/§5, §6, §7, §8, §9 -------------------------------------------

    private static function projectFood(int $userId): ?array
    {
        $mealsEaten = Validate::enumList(self::answer($userId, '4.4', []), [
            'breakfast', 'lunch', 'dinner', 'snacks',
        ]) ?? ['breakfast', 'lunch', 'dinner', 'snacks'];

        // 4.5 (would rather skip) subtracts from 4.4. Asking both and only
        // honouring one would make the second question pointless.
        $skip = Validate::enumList(self::answer($userId, '4.5', []), [
            'breakfast', 'lunch', 'dinner', 'snacks',
        ]) ?? [];
        $mealsEaten = array_values(array_diff($mealsEaten, $skip));

        DB::run(
            'INSERT INTO food_preferences
             (user_id, cuisines, dietary_pattern, meals_eaten, structure, cooking_skill,
              weekday_cook_minutes, weekend_cook_minutes, cooking_for, meal_preps,
              budget_sensitivity, kitchen_equipment, eat_out_current, eat_out_requested,
              caffeine_per_day, alcohol_per_week)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               cuisines = VALUES(cuisines), dietary_pattern = VALUES(dietary_pattern),
               meals_eaten = VALUES(meals_eaten), structure = VALUES(structure),
               cooking_skill = VALUES(cooking_skill),
               weekday_cook_minutes = VALUES(weekday_cook_minutes),
               weekend_cook_minutes = VALUES(weekend_cook_minutes),
               cooking_for = VALUES(cooking_for), meal_preps = VALUES(meal_preps),
               budget_sensitivity = VALUES(budget_sensitivity),
               kitchen_equipment = VALUES(kitchen_equipment),
               eat_out_current = VALUES(eat_out_current),
               eat_out_requested = VALUES(eat_out_requested),
               caffeine_per_day = VALUES(caffeine_per_day),
               alcohol_per_week = VALUES(alcohol_per_week)',
            [
                $userId,
                json_encode(self::asItemList(self::answer($userId, '4.2', []))),
                Validate::enum(self::answer($userId, '4.3'), [
                    'none', 'vegetarian', 'vegan', 'pescatarian', 'keto',
                    'paleo', 'halal', 'kosher', 'other',
                ]) ?? 'none',
                json_encode($mealsEaten),
                Validate::enum(self::answer($userId, '4.6'), [
                    'spell_it_out', 'targets_and_options', 'mix',
                ]) ?? 'mix',
                Validate::enum(self::answer($userId, '5.1'), [
                    'cant_cook', 'basic', 'competent', 'good', 'excellent',
                ]),
                Validate::intRange(self::answer($userId, '5.2'), 0, 600),
                Validate::intRange(self::answer($userId, '5.3'), 0, 600),
                Validate::intRange(self::answer($userId, '5.4'), 1, 20) ?? 1,
                Validate::enum(self::answer($userId, '5.5'), ['eagerly', 'sometimes', 'no']),
                Validate::enum(self::answer($userId, '5.8'), ['tight', 'moderate', 'not_a_concern']),
                json_encode(self::asItemList(self::answer($userId, '5.9', []))),
                Validate::intRange(self::answer($userId, '5.6'), 0, 30),
                Validate::intRange(self::answer($userId, '5.7'), 0, 21),
                Validate::intRange(self::answer($userId, '4.8'), 0, 20),
                Validate::intRange(self::answer($userId, '4.9'), 0, 100),
            ]
        );
        return null;
    }

    private static function projectTraining(int $userId): ?array
    {
        DB::run(
            'INSERT INTO training_preferences
             (user_id, experience, currently_training, past_success, self_strength,
              self_cardio, known_lifts, cardio_willing, cardio_refused,
              preferred_split, cardio_feeling)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               experience = VALUES(experience),
               currently_training = VALUES(currently_training),
               past_success = VALUES(past_success),
               self_strength = VALUES(self_strength), self_cardio = VALUES(self_cardio),
               known_lifts = VALUES(known_lifts), cardio_willing = VALUES(cardio_willing),
               cardio_refused = VALUES(cardio_refused),
               preferred_split = VALUES(preferred_split),
               cardio_feeling = VALUES(cardio_feeling)',
            [
                $userId,
                Validate::enum(self::answer($userId, '6.1'), [
                    'never', 'beginner', 'intermediate', 'advanced', 'returning',
                ]),
                Validate::enum(self::answer($userId, '6.2'), [
                    'not_at_all', 'occasionally', '1_2', '3_4', '5_plus',
                ]),
                Validate::str(self::answer($userId, '6.3'), 0, 2000),
                Validate::enum(self::answer($userId, '6.4'), [
                    'poor', 'below_average', 'average', 'good', 'strong',
                ]),
                Validate::enum(self::answer($userId, '6.5'), [
                    'poor', 'below_average', 'average', 'good', 'excellent',
                ]),
                json_encode(self::asItemList(self::answer($userId, '6.6', []))),
                json_encode(self::asItemList(self::answer($userId, '6.8', []))),
                json_encode(self::asItemList(self::answer($userId, '6.9', []))),
                Validate::enum(self::answer($userId, '6.10'), [
                    'full_body', 'upper_lower', 'ppl', 'no_preference',
                ]) ?? 'no_preference',
                Validate::str(self::answer($userId, '6.11'), 0, 1000),
            ]
        );

        // 6.7 — best recent lifts. Seeds starting loads where the baseline has
        // not measured them yet. Deliberately kept alongside the baseline's own
        // measurement: the claim-vs-reality gap is the point.
        $lifts = self::answer($userId, '6.7', []);
        if (is_array($lifts)) {
            foreach ($lifts as $lift) {
                if (!is_array($lift)) {
                    continue;
                }
                $slug = PlanSchema::resolveSlug((string) ($lift['exercise'] ?? ''));
                $weight = Validate::floatRange($lift['weight'] ?? null, 0, 1000);
                if ($slug === null || $weight === null) {
                    continue;
                }
                // Stored as an answer only — NOT as a logged_exercise. Writing a
                // claimed lift into the training log would corrupt adherence
                // history with something that never happened.
            }
        }
        return null;
    }

    /**
     * §7.1 — the weekly availability grid.
     *
     * Seven rows, one per weekday. Access is a property of a DAY: "full gym at
     * work Mon-Fri, bodyweight at home weekends" is ordinary, and a user-level
     * enum would flatten it and then prescribe barbell work on a Saturday.
     */
    private static function projectAvailability(int $userId): ?array
    {
        $grid = self::answer($userId, '7.1', []);
        if (!is_array($grid)) {
            return null;
        }

        $committed = 0;
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $day = $grid[(string) $weekday] ?? $grid[$weekday] ?? null;
            if (!is_array($day)) {
                continue;
            }

            $canTrain = Validate::enum($day['can_train'] ?? 'no', ['yes', 'no', 'sometimes']) ?? 'no';
            if ($canTrain === 'yes') {
                $committed++;
            }

            DB::run(
                'INSERT INTO availability
                 (user_id, weekday, can_train, minutes, access, equipment, is_chaotic, preferred_time)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   can_train = VALUES(can_train), minutes = VALUES(minutes),
                   access = VALUES(access), equipment = VALUES(equipment),
                   is_chaotic = VALUES(is_chaotic), preferred_time = VALUES(preferred_time)',
                [
                    $userId, $weekday, $canTrain,
                    Validate::intRange($day['minutes'] ?? null, 0, 600),
                    Validate::enum($day['access'] ?? null, [
                        'full_gym', 'home_gym', 'bodyweight', 'outdoors',
                    ]),
                    json_encode(self::asItemList($day['equipment'] ?? [])),
                    (Validate::bool($day['is_chaotic'] ?? false) ?? false) ? 1 : 0,
                    Validate::enum($day['preferred_time'] ?? null, [
                        'early_morning', 'morning', 'midday', 'afternoon', 'evening', 'varies',
                    ]),
                ]
            );
        }

        // Committed capacity is derived from the grid rather than asked
        // separately — two sources for one fact would drift.
        if ($committed > 0) {
            self::ensureProfile($userId);
            DB::run(
                'UPDATE profiles SET committed_days_per_week = ? WHERE user_id = ?',
                [min($committed, 7), $userId]
            );
        }
        return null;
    }

    /**
     * §8 — daily-life baselines.
     *
     * These exist so a daily check-in can be read as a DELTA. "Energy: low"
     * means nothing without knowing that low is this user's normal.
     */
    private static function projectDailyLife(int $userId): ?array
    {
        self::ensureProfile($userId);
        DB::run(
            'UPDATE profiles SET baseline_sleep_hours = ?, baseline_sleep_quality = ?,
                                 baseline_activity = ?, baseline_stress = ?, baseline_energy = ?
             WHERE user_id = ?',
            [
                Validate::floatRange(self::answer($userId, '8.1'), 0, 24),
                Validate::enum(self::answer($userId, '8.2'), ['poor', 'fair', 'good', 'great']),
                Validate::enum(self::answer($userId, '8.3'), [
                    'sedentary', 'light', 'moderate', 'very',
                ]),
                Validate::enum(self::answer($userId, '8.4'), [
                    'low', 'moderate', 'high', 'very_high',
                ]),
                Validate::enum(self::answer($userId, '8.5'), [
                    'drained', 'low', 'ok', 'good', 'high',
                ]),
                $userId,
            ]
        );
        return null;
    }

    private static function projectCoachingStyle(int $userId): ?array
    {
        self::ensureProfile($userId);
        DB::run(
            'UPDATE profiles SET tone = ?, nudge_intensity = ?, nudge_after_days = ?,
                                 explanation_depth = ?, hide_photos = ?, hide_measurements = ?
             WHERE user_id = ?',
            [
                Validate::enum(self::answer($userId, '9.1'), [
                    'sarcastic_hardass', 'high_school_coach', 'motivational_speaker',
                    'funny_positive', 'friendly_encouraging', 'direct_no_fluff',
                ]) ?? 'friendly_encouraging',
                Validate::enum(self::answer($userId, '9.2'), [
                    'leave_me_alone', 'gentle', 'persistent', 'relentless',
                ]) ?? 'gentle',
                Validate::intRange(self::answer($userId, '9.3'), 1, 30) ?? 3,
                Validate::enum(self::answer($userId, '9.4'), [
                    'just_tell_me', 'brief', 'explain',
                ]) ?? 'brief',
                // Default hidden: sharing a body metric should be a choice the
                // user makes, not one they discover they made.
                (Validate::bool(self::answer($userId, '9.5', true)) ?? true) ? 1 : 0,
                (Validate::bool(self::answer($userId, '9.6', true)) ?? true) ? 1 : 0,
                $userId,
            ]
        );
        return null;
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * Coerce a multi-select answer into a flat list of non-empty strings.
     *
     * The SPA may send `["a","b"]`, `{"selected":[...],"other":"..."}`, or a
     * bare string. Accepting all three keeps the client simple and means an
     * "other" free-text entry is never silently dropped.
     */
    private static function asItemList($v): array
    {
        if (is_string($v)) {
            $v = trim($v);
            return $v === '' ? [] : [$v];
        }
        if (!is_array($v)) {
            return [];
        }

        $items = [];
        if (isset($v['selected']) && is_array($v['selected'])) {
            $items = $v['selected'];
            if (!empty($v['other'])) {
                // Split "peanuts, shellfish" into separate constraints, since
                // each needs to match independently.
                foreach (preg_split('/[,;\n]+/', (string) $v['other']) as $piece) {
                    $items[] = $piece;
                }
            }
        } else {
            $items = $v;
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                continue;
            }
            $s = trim($item);
            if ($s !== '' && strtolower($s) !== 'none') {
                $out[] = $s;
            }
        }
        return array_values(array_unique($out));
    }

    private static function isKnownKey(string $key): bool
    {
        // 3.2_detail is a companion free-text field rather than its own numbered
        // question, so it is allowed alongside the numbered set.
        if ($key === '3.2_detail') {
            return true;
        }
        foreach (self::SECTIONS as $meta) {
            if (in_array($key, $meta['keys'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function sectionOf(string $key): string
    {
        return explode('.', $key)[0];
    }
}
