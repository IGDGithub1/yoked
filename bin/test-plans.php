<?php
declare(strict_types=1);

/**
 * End-to-end plan generation, using the two users from the specs.
 *
 * Seeds User #1 (52M, T2 diabetic, post-MI, poor cardio) and User #2 (21F,
 * recomp, protein floor) as a buddy pair, generates a real week for each, and
 * checks the output against the decisions in SPEC-coaching.md.
 *
 * This is the honest test of whether the specs and schema hold up: the sample
 * week in docs/sample-week.md was written by hand, and this is the first time
 * the app produces one itself.
 *
 * Two halves. The schema, prompt assembly, validator and retry merge are free and run by
 * default. The two live generations are OPT-IN behind --live: 22k-31k output tokens and
 * minutes each, which is worth paying deliberately and wrong to pay on every routine run.
 *
 *   php bin/test-plans.php               structural only, seconds, free
 *   php bin/test-plans.php --live        plus two real weeks (~$0.30, several minutes)
 *   php bin/test-plans.php --seed-only   seed users, generate nothing
 *   php bin/test-plans.php --keep        leave the test users behind
 */

require __DIR__ . '/../src/bootstrap_cli.php';
require YK_SRC . '/lib/Response.php';
require YK_SRC . '/lib/RateLimit.php';
require YK_SRC . '/lib/Goals.php';
require YK_SRC . '/lib/Claude.php';
require YK_SRC . '/lib/PlanSchema.php';
require YK_SRC . '/lib/BuddySchedule.php';   // Safety::checkAvailability reads it
require YK_SRC . '/lib/Safety.php';
require YK_SRC . '/lib/Plans.php';
require YK_SRC . '/lib/Drift.php';         // BuddyAbsence reads lastLoggedDate
require YK_SRC . '/lib/BuddyAbsence.php';  // Plans::gatherContext reads it
require YK_SRC . '/lib/BuddySkeleton.php';  // gatherContext and persist read it

$args     = array_slice($argv, 1);
$seedOnly = in_array('--seed-only', $args, true);
$keep     = in_array('--keep', $args, true);

/*
 * Live generation is OPT-IN, matching test-chat.php and test-vetoes.php.
 *
 * Sections 5 and 6 each generate a real week: 22k-31k output tokens, three to ten minutes
 * apiece, and money. That is worth paying deliberately and wrong to pay on every routine
 * run — bin/testall.sh was taking over ten minutes on this one suite and getting truncated
 * by the ssh timeout, which is how a harness ends up reporting a verdict it did not earn.
 *
 * Everything in sections 1 to 4a is free and still runs by default: the schema, the prompt
 * assembly, the validator, and the retry merge. That is the half that catches regressions.
 */
// Named $liveGen, not $live: a closure below already uses $live for a plan_versions row
// (Plans::live), and two meanings of one name in one file is a bug waiting to be written.
$liveGen = in_array('--live', $args, true);

$pass = 0;
$fail = 0;

function t(string $label, callable $fn): void
{
    global $pass, $fail;
    try {
        $r = $fn();
        if ($r === true) {
            printf("  ok    %s\n", $label);
            $pass++;
        } elseif ($r === null) {
            printf("  skip  %s\n", $label);
        } else {
            printf("  FAIL  %s — %s\n", $label, is_string($r) ? $r : 'false');
            $fail++;
        }
    } catch (Throwable $e) {
        printf("  FAIL  %s — %s\n", $label, $e->getMessage());
        $fail++;
    }
}

/** Next Monday, so generated dates are always in the future. */
function nextMonday(): string
{
    return date('Y-m-d', strtotime('next monday'));
}

// ---------------------------------------------------------------------------
// Seeding
// ---------------------------------------------------------------------------

/**
 * Both users from the specs, verbatim where the spec gives concrete facts.
 * Deliberately not simplified — the point is to exercise the real cases:
 * medical modifiers, a protein floor, refused cardio, no-scale kitchens.
 */
function seedUsers(): array
{
    $ids = [];

    // ---- User #1 ----------------------------------------------------------
    $u1 = DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
         VALUES (?, ?, ?, ?, "active")',
        ['plantest_u1', 'Test User One', 'plantest1@example.test', 'x']
    );
    $ids['u1'] = $u1;

    DB::run(
        'INSERT INTO profiles
         (user_id, date_of_birth, birth_sex, height_cm, units, tone, nudge_intensity,
          explanation_depth, core_emphasis, committed_days_per_week,
          baseline_sleep_hours, baseline_sleep_quality, baseline_activity,
          baseline_stress, baseline_energy, physician_clearance, trainer_notes)
         VALUES (?, ?, "male", 193.0, "imperial", "sarcastic_hardass", "persistent",
                 "brief", "standard", 5, 6.5, "fair", "light", "moderate", "low",
                 "yes", ?)',
        [
            $u1,
            date('Y-m-d', strtotime('-52 years')),
            'Type II diabetic; heart attack ~5 years ago, heart recovered well. '
            . 'Diabetes is the day-to-day limiter. Knows what to do; doing it is the problem. '
            . 'Cooks for himself only, good cook but works efficiently.',
        ]
    );

    $goalPreset = DB::one("SELECT id FROM goal_presets WHERE slug = 'lower-carb'");
    DB::run(
        'INSERT INTO goals
         (user_id, primary_goal, goal_preset_id, secondary_goals, success_statement,
          requested_timeline, horizon_weeks, scale_vs_feel, status)
         VALUES (?, "lose_fat", ?, ?, ?, "16_weeks", 16, "both", "active")',
        [
            $u1,
            $goalPreset['id'] ?? null,
            json_encode(['build_muscle', 'improve_cardio', 'improve_strength']),
            'Lose some weight and visceral fat, gain muscle and strength, and get '
            . 'my cardio back. If I could look like Brad Pitt in Fight Club that '
            . 'would be great. Super easy, right.',
        ]
    );

    // Mon-Fri full gym 60 min early morning; Sat 180 outdoors; Sun 60 outdoors.
    foreach ([1, 2, 3, 4, 5] as $d) {
        DB::run(
            'INSERT INTO availability (user_id, weekday, can_train, minutes, access, preferred_time)
             VALUES (?, ?, "yes", 60, "full_gym", "early_morning")',
            [$u1, $d]
        );
    }
    DB::run(
        'INSERT INTO availability (user_id, weekday, can_train, minutes, access, preferred_time)
         VALUES (?, 6, "yes", 180, "outdoors", "midday")', [$u1]
    );
    DB::run(
        'INSERT INTO availability (user_id, weekday, can_train, minutes, access, preferred_time)
         VALUES (?, 7, "yes", 60, "outdoors", "midday")', [$u1]
    );

    DB::run(
        'INSERT INTO food_preferences
         (user_id, meals_eaten, structure, cooking_skill, weekday_cook_minutes,
          weekend_cook_minutes, cooking_for, meal_preps, budget_sensitivity,
          dietary_pattern, eat_out_current, eat_out_requested, eat_out_agreed,
          caffeine_per_day, kitchen_equipment, cuisines)
         VALUES (?, ?, "mix", "good", 20, 45, 1, "sometimes", "not_a_concern",
                 "none", 10, 4, 3, 2, ?, ?)',
        [
            $u1,
            json_encode(['breakfast', 'lunch', 'dinner', 'snacks']),
            json_encode(['oven', 'stovetop', 'microwave', 'air fryer', 'grill', 'food scale']),
            json_encode(['american', 'mexican', 'italian']),
        ]
    );

    DB::run(
        'INSERT INTO training_preferences
         (user_id, experience, currently_training, past_success, self_strength,
          self_cardio, cardio_willing, cardio_refused, preferred_split, cardio_feeling)
         VALUES (?, "returning", "occasionally", ?, "below_average", "poor", ?, ?,
                 "upper_lower", ?)',
        [
            $u1,
            'Has a fitness background and has been in shape before, years ago.',
            json_encode(['recumbent-bike', 'walking', 'hiking', 'pickleball']),
            json_encode(['running', 'rower']),
            'Cardio sucks and is the worst thing ever.',
        ]
    );

    // Conditions are MODIFIERS, not blocks — guidance text, nothing banned.
    DB::run(
        'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, guidance, source)
         VALUES (?, "condition", "hard", "type_2_diabetes", ?, ?, "onboarding")',
        [
            $u1,
            'Type II diabetic',
            'Carb distribution and meal timing matter. Avoid large isolated carb '
            . 'loads; spread carbs across meals. This is NOT a carb ban.',
        ]
    );
    DB::run(
        'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, guidance, source)
         VALUES (?, "condition", "hard", "cardiac_history", ?, ?, "onboarding")',
        [
            $u1,
            'Heart attack ~5 years ago, recovered well',
            'Include a real warm-up on every session and progress intensity '
            . 'gradually. Avoid heavy overhead barbell work with prolonged '
            . 'breath-holding; prefer machine pressing. This is NOT an intensity ceiling.',
        ]
    );
    DB::run(
        'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
         VALUES (?, "cardio", "soft", "running", ?, "onboarding")',
        [$u1, 'Refuses running — knees and general loathing']
    );

    // ---- User #2 ----------------------------------------------------------
    $u2 = DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
         VALUES (?, ?, ?, ?, "active")',
        ['plantest_u2', 'Test User Two', 'plantest2@example.test', 'x']
    );
    $ids['u2'] = $u2;

    DB::run(
        'INSERT INTO profiles
         (user_id, date_of_birth, birth_sex, height_cm, units, tone, nudge_intensity,
          explanation_depth, core_emphasis, committed_days_per_week,
          baseline_sleep_hours, baseline_sleep_quality, baseline_activity,
          baseline_stress, baseline_energy, trainer_notes)
         VALUES (?, ?, "female", 160.0, "imperial", "sarcastic_hardass", "gentle",
                 "brief", "standard", 5, 7.0, "good", "moderate", "moderate", "ok", ?)',
        [
            $u2,
            date('Y-m-d', strtotime('-21 years')),
            'Historically very low intake (500-700 kcal, mostly carbs); much '
            . 'improved but still below where it needs to be. Chooses energy '
            . 'drinks over food. Previous serious gym block worked extremely well. '
            . 'Maintenance through diet and motivation are the real issues.',
        ]
    );

    // The building-intake preset: a generous calorie lower bound so an honest
    // short day with protein on target still counts.
    $recompPreset = DB::one("SELECT id FROM goal_presets WHERE slug = 'recomp-building-intake'");
    DB::run(
        'INSERT INTO goals
         (user_id, primary_goal, goal_preset_id, secondary_goals, success_statement,
          requested_timeline, horizon_weeks, scale_vs_feel, status)
         VALUES (?, "recomp", ?, ?, ?, "12_weeks", 12, "look_feel", "active")',
        [
            $u2,
            $recompPreset['id'] ?? null,
            json_encode(['build_muscle']),
            'Tone up, better muscle definition, less fat and puffiness. Not trying '
            . 'to get huge. Want to slay in a little black dress.',
        ]
    );

    foreach ([1, 2, 3, 4, 5] as $d) {
        DB::run(
            'INSERT INTO availability (user_id, weekday, can_train, minutes, access, preferred_time)
             VALUES (?, ?, "yes", 60, "full_gym", "early_morning")',
            [$u2, $d]
        );
    }
    DB::run(
        'INSERT INTO availability (user_id, weekday, can_train, minutes, access, preferred_time)
         VALUES (?, 6, "yes", 180, "outdoors", "midday")', [$u2]
    );
    DB::run(
        'INSERT INTO availability (user_id, weekday, can_train, minutes, access, preferred_time)
         VALUES (?, 7, "yes", 60, "outdoors", "midday")', [$u2]
    );

    // No food scale — recipes must use household measures.
    DB::run(
        'INSERT INTO food_preferences
         (user_id, meals_eaten, structure, cooking_skill, weekday_cook_minutes,
          weekend_cook_minutes, cooking_for, meal_preps, budget_sensitivity,
          dietary_pattern, eat_out_current, eat_out_requested, eat_out_agreed,
          caffeine_per_day, kitchen_equipment, cuisines)
         VALUES (?, ?, "spell_it_out", "basic", 25, 40, 1, "yes", "tight",
                 "none", 7, 3, 2, 3, ?, ?)',
        [
            $u2,
            // Skips breakfast — calories must redistribute, not vanish.
            json_encode(['lunch', 'dinner', 'snacks']),
            json_encode(['stovetop', 'microwave', 'air fryer']),
            json_encode(['mexican', 'asian', 'mediterranean']),
        ]
    );

    DB::run(
        'INSERT INTO training_preferences
         (user_id, experience, currently_training, past_success, self_strength,
          self_cardio, cardio_willing, cardio_refused, preferred_split, cardio_feeling)
         VALUES (?, "returning", "3_4", ?, "average", "good", ?, ?, "upper_lower", ?)',
        [
            $u2,
            'Last serious gym grind worked amazingly well — was in excellent shape.',
            json_encode(['running', 'rower', 'treadmill', 'hiking', 'pickleball']),
            json_encode([]),
            'Runs the occasional 5k and tries to stay active daily.',
        ]
    );

    // Protein floor: Claude proposes, user confirms, stored as user-owned.
    DB::run(
        'INSERT INTO user_constraints
         (user_id, kind, tier, subject, reason, floor_value, source)
         VALUES (?, "target_floor", "hard", "protein_g", ?, 110.0, "claude_proposed")',
        [$u2, 'Protein is the priority for a recomp; agreed floor']
    );
    DB::run(
        'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
         VALUES (?, "food", "hard", "shellfish", ?, "onboarding")',
        [$u2, 'Shellfish allergy']
    );

    // Buddy pair — they train together.
    $lo = min($u1, $u2);
    $hi = max($u1, $u2);
    DB::run(
        'INSERT INTO buddy_pairs (user_lo, user_hi, status, requested_by)
         VALUES (?, ?, "active", ?)',
        [$lo, $hi, $u1]
    );

    return $ids;
}

function cleanup(): void
{
    // FKs cascade from users, so this clears profiles, goals, availability,
    // constraints, plans and prescriptions in one go.
    //
    // DO NOT RUN TWO COPIES OF THIS SUITE AT ONCE. The match is by prefix, not
    // by this run's own ids, so a second run's teardown deletes the first run's
    // users mid-flight. That surfaces as a bewildering FK violation on
    // plan_versions, or "No such user: 46" from a generation that was working
    // fine — hunted twice already. This suite makes live API calls and takes
    // several minutes, which makes the overlap easy to cause by accident.
    DB::run("DELETE FROM users WHERE username LIKE 'plantest_%'");
}

// ---------------------------------------------------------------------------

echo "Plan generation tests\n\n";

cleanup();   // clear any residue from an interrupted earlier run

echo "1. seeding the two spec users\n";
$ids = [];
t('seed both users, buddy-paired', function () use (&$ids) {
    $ids = seedUsers();
    return ($ids['u1'] > 0 && $ids['u2'] > 0) ?: 'seeding failed';
});

t('goal presets resolved', function () use ($ids) {
    foreach (['u1', 'u2'] as $k) {
        $g = DB::one(
            'SELECT goal_preset_id FROM goals WHERE user_id = ?', [$ids[$k]]
        );
        if ($g === null || $g['goal_preset_id'] === null) {
            return "{$k} has no goal preset — did migration 005 run?";
        }
    }
    return true;
});

echo "\n2. schema is acceptable to structured outputs (no API calls)\n";

t('schema lints clean', function () {
    // The API rejects additionalProperties:true on any object, and several
    // JSON Schema keywords outright. Catching that here is free; catching it at
    // request time costs a user their plan.
    $problems = PlanSchema::lint();
    return $problems === [] ? true : implode('; ', $problems);
});

t('lint actually catches a bad schema', function () {
    // Guard against a lint that passes everything.
    $bad = ['type' => 'object', 'additionalProperties' => true, 'properties' => []];
    return PlanSchema::lint($bad) !== [] ?: 'lint missed additionalProperties:true';
});

t('lint catches unsupported keywords', function () {
    $bad = ['type' => 'object', 'additionalProperties' => false, 'properties' => [
        'n' => ['type' => 'integer', 'minimum' => 1],
    ]];
    $p = PlanSchema::lint($bad);
    foreach ($p as $msg) {
        if (str_contains($msg, 'minimum')) {
            return true;
        }
    }
    return 'lint missed an unsupported keyword';
});

echo "\n3. context and prompt assembly (no API calls)\n";

$sysPrompt = (new ReflectionMethod(Plans::class, 'systemPrompt'));
$sysPrompt->setAccessible(true);
$gather = (new ReflectionMethod(Plans::class, 'gatherContext'));
$gather->setAccessible(true);

$ctx1 = null;
t('context gathers without error for U1', function () use ($gather, $ids, &$ctx1) {
    $ctx1 = $gather->invoke(null, $ids['u1'], nextMonday());
    return $ctx1['error'] === null ?: 'context error: ' . $ctx1['error'];
});

t('system prompt carries hard constraints as absolute', function () use ($sysPrompt, $ctx1) {
    $s = $sysPrompt->invoke(null, $ctx1);
    if (!str_contains($s, 'HARD CONSTRAINTS')) {
        return 'no hard constraints section';
    }
    if (!str_contains($s, 'type_2_diabetes')) {
        return 'diabetes constraint missing';
    }
    // Conditions must read as modifiers, not bans.
    return str_contains($s, 'NOT a carb ban') ?: 'condition guidance missing';
});

t('system prompt states committed-day count', function () use ($sysPrompt, $ctx1) {
    $s = $sysPrompt->invoke(null, $ctx1);
    return str_contains($s, 'Committed sessions per week: 5') ?: 'committed count missing';
});

t('system prompt carries the exercise vocabulary', function () use ($sysPrompt, $ctx1) {
    $s = $sysPrompt->invoke(null, $ctx1);
    return (str_contains($s, 'EXERCISE VOCABULARY') && str_contains($s, 'trap-bar-deadlift'))
        ?: 'vocabulary missing or incomplete';
});

t('tone brief matches the chosen tone', function () use ($sysPrompt, $ctx1) {
    $s = $sysPrompt->invoke(null, $ctx1);
    return str_contains($s, 'Roast excuses, never the person')
        ?: 'sarcastic_hardass brief not applied';
});

t('cacheable prefix is long enough to actually cache', function () use ($sysPrompt, $ctx1) {
    $s = $sysPrompt->invoke(null, $ctx1);
    // Sonnet 5's floor is 1024 tokens; ~4 chars/token is the usual rule of
    // thumb, so ~4096 chars. Below that the breakpoint silently does nothing.
    $chars = strlen($s);
    printf("        system prompt: %d chars (~%d tokens)\n", $chars, (int) ($chars / 4));
    return $chars > 4096 ?: "only {$chars} chars — below the ~1024-token cache floor";
});

echo "\n4. the validator rejects what it should (no API calls)\n";

t('rejects a category-member ingredient (shrimp vs shellfish)', function () use ($ids) {
    // The constraint is recorded as "shellfish"; the ingredient is "shrimp".
    // Plain substring matching runs the wrong way here and lets it through,
    // so this is the test that catches an unsafe validator.
    $plan = ['days' => [[
        'date' => nextMonday(), 'target_calories' => 2000, 'target_protein_g' => 150,
        'target_fat_g' => 60, 'target_carbs_g' => 180,
        'meals' => [[
            'slot' => 'dinner', 'kind' => 'specified', 'name' => 'Garlic stir fry',
            'ingredients' => [['item' => 'shrimp', 'grams' => 200]],
        ]],
    ]]];
    $v = Safety::validatePlan($plan, $ids['u2']);
    foreach ($v as $msg) {
        if (str_contains($msg, 'shrimp') && str_contains($msg, 'shellfish')) {
            return true;
        }
    }
    return 'shrimp not caught under the shellfish constraint: ' . json_encode($v);
});

t('rejects a banned food named in the recipe title', function () use ($ids) {
    // Anonymised ingredients must not launder a banned food out of the name.
    $plan = ['days' => [[
        'date' => nextMonday(), 'target_calories' => 2000, 'target_protein_g' => 150,
        'target_fat_g' => 60, 'target_carbs_g' => 180,
        'meals' => [[
            'slot' => 'lunch', 'kind' => 'specified', 'name' => 'Lobster roll',
            'ingredients' => [['item' => 'bread roll', 'grams' => 60]],
        ]],
    ]]];
    $v = Safety::validatePlan($plan, $ids['u2']);
    foreach ($v as $msg) {
        if (str_contains($msg, 'lobster')) {
            return true;
        }
    }
    return 'lobster in the title not caught: ' . json_encode($v);
});

t('prompt spells out a category\'s members', function () use ($ids) {
    // "Avoid shellfish" alone is not enough: validation rejects shrimp, so the
    // prompt has to name shrimp.
    $block = Safety::promptBlock($ids['u2']);
    return (str_contains($block, 'This covers:') && str_contains($block, 'shrimp'))
        ?: 'category members not expanded in the prompt block';
});

t('rejects a target below a hard floor', function () use ($ids) {
    $plan = ['days' => [[
        'date' => nextMonday(), 'target_calories' => 1800,
        'target_protein_g' => 80,   // floor is 110
        'target_fat_g' => 55, 'target_carbs_g' => 160, 'meals' => [],
    ]]];
    $v = Safety::validatePlan($plan, $ids['u2']);
    foreach ($v as $msg) {
        if (str_contains($msg, 'protein_g') && str_contains($msg, 'floor')) {
            return true;
        }
    }
    return 'floor not enforced: ' . json_encode($v);
});

t('rejects a session on an unavailable day', function () use ($ids) {
    // Give U1 a day off, then schedule on it.
    DB::run('UPDATE availability SET can_train = "no" WHERE user_id = ? AND weekday = 3',
        [$ids['u1']]);
    $wed = date('Y-m-d', strtotime(nextMonday() . ' +2 days'));
    $plan = ['sessions' => [[
        'date' => $wed, 'session_type' => 'strength', 'is_committed' => true,
        'target_minutes' => 60, 'exercises' => [['slug' => 'back-squat', 'block' => 'main']],
    ]]];
    $v = Safety::validatePlan($plan, $ids['u1']);
    DB::run('UPDATE availability SET can_train = "yes" WHERE user_id = ? AND weekday = 3',
        [$ids['u1']]);
    foreach ($v as $msg) {
        if (str_contains($msg, 'unavailable')) {
            return true;
        }
    }
    return 'availability not enforced: ' . json_encode($v);
});

t('rejects a session longer than the day allows', function () use ($ids) {
    $plan = ['sessions' => [[
        'date' => nextMonday(), 'session_type' => 'strength', 'is_committed' => true,
        'target_minutes' => 120,   // Monday has 60
        'exercises' => [['slug' => 'back-squat', 'block' => 'main']],
    ]]];
    $v = Safety::validatePlan($plan, $ids['u1']);
    foreach ($v as $msg) {
        if (str_contains($msg, 'minutes')) {
            return true;
        }
    }
    return 'duration not enforced: ' . json_encode($v);
});

t('rejects the wrong committed-session count', function () use ($ids) {
    $plan = ['sessions' => [
        ['date' => nextMonday(), 'session_type' => 'strength', 'is_committed' => true,
         'target_minutes' => 60,
         'exercises' => [['slug' => 'back-squat', 'block' => 'main'],
                         ['slug' => 'plank', 'block' => 'core']]],
    ]];
    $v = Safety::validatePlan($plan, $ids['u1']);   // 1 committed, wants 5
    foreach ($v as $msg) {
        if (str_contains($msg, 'committed')) {
            return true;
        }
    }
    return 'committed count not enforced: ' . json_encode($v);
});

t('rejects a strength day with no core block', function () use ($ids) {
    $plan = ['sessions' => [[
        'date' => nextMonday(), 'session_type' => 'strength', 'is_committed' => true,
        'target_minutes' => 60,
        'exercises' => [['slug' => 'back-squat', 'block' => 'main']],
    ]]];
    $v = Safety::validatePlan($plan, $ids['u1']);
    foreach ($v as $msg) {
        if (str_contains($msg, 'core block')) {
            return true;
        }
    }
    return 'core requirement not enforced: ' . json_encode($v);
});

t('rejects an unknown exercise slug', function () use ($ids) {
    $plan = ['sessions' => [[
        'date' => nextMonday(), 'session_type' => 'strength', 'is_committed' => true,
        'target_minutes' => 60,
        'exercises' => [['slug' => 'invented-super-lift', 'block' => 'main'],
                        ['slug' => 'plank', 'block' => 'core']],
    ]]];
    $v = Safety::validatePlan($plan, $ids['u1']);
    foreach ($v as $msg) {
        if (str_contains($msg, 'not in the library')) {
            return true;
        }
    }
    return 'unknown slug not caught: ' . json_encode($v);
});

t('accepts a clean minimal plan', function () use ($ids) {
    $sessions = [];
    for ($i = 0; $i < 5; $i++) {
        $sessions[] = [
            'date' => date('Y-m-d', strtotime(nextMonday() . " +{$i} days")),
            'session_type' => 'strength', 'focus' => 'full', 'is_committed' => true,
            'target_minutes' => 55, 'location' => 'full_gym',
            'exercises' => [
                ['slug' => 'leg-press', 'block' => 'main', 'sets' => 3, 'target_reps' => '10'],
                ['slug' => 'plank', 'block' => 'core', 'sets' => 3, 'target_seconds' => 40],
            ],
        ];
    }
    // Meals are populated rather than left empty. They used to be `[]`, which
    // was fine while nothing checked for them, but a day with no meals is not a
    // valid plan for a nutrition coach — Safety::checkWeekIsWhole says so now,
    // and it is right to. Kept deliberately plain: this test is about the
    // hard-constraint checks passing on a structurally sound plan, so the meals
    // exist to be structurally sound and nothing more.
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[] = [
            'date' => date('Y-m-d', strtotime(nextMonday() . " +{$i} days")),
            'target_calories' => 2200, 'target_protein_g' => 180,
            'target_fat_g' => 70, 'target_carbs_g' => 150,
            'meals' => [
                [
                    'slot' => 'breakfast', 'kind' => 'specified', 'name' => 'Eggs and oats',
                    'prep_minutes' => 10,
                    'calories' => 600, 'protein_g' => 45, 'fat_g' => 22, 'carbs_g' => 50,
                    'ingredients' => [
                        ['item' => 'eggs', 'household' => '3 large'],
                        ['item' => 'rolled oats', 'household' => '1 cup'],
                    ],
                ],
                [
                    'slot' => 'lunch', 'kind' => 'specified', 'name' => 'Chicken and rice',
                    'prep_minutes' => 15,
                    'calories' => 800, 'protein_g' => 70, 'fat_g' => 22, 'carbs_g' => 70,
                    'ingredients' => [
                        ['item' => 'chicken breast', 'household' => '6 oz'],
                        ['item' => 'white rice', 'household' => '1 cup cooked'],
                    ],
                ],
                [
                    'slot' => 'dinner', 'kind' => 'specified', 'name' => 'Beef and potatoes',
                    'prep_minutes' => 20,
                    'calories' => 800, 'protein_g' => 65, 'fat_g' => 26, 'carbs_g' => 30,
                    'ingredients' => [
                        ['item' => 'lean ground beef', 'household' => '6 oz'],
                        ['item' => 'potatoes', 'household' => '2 medium'],
                    ],
                ],
            ],
        ];
    }
    $v = Safety::validatePlan(['sessions' => $sessions, 'days' => $days], $ids['u1']);
    return $v === [] ?: 'clean plan rejected: ' . json_encode($v);
});

echo "\n4a. retry merge (no API calls)\n";

/** mergePlans is private; it is the retry path's correctness, so test it directly. */
$merge = function (array $prev, array $next): array {
    $m = new ReflectionMethod(Plans::class, 'mergePlans');
    $m->setAccessible(true);
    return $m->invoke(null, $prev, $next);
};

t('a partial retry keeps the days it did not mention', function () use ($merge) {
    // The real failure this exists for: attempt 1 returns a full week, the retry
    // returns only the day it was asked to fix, and taking the retry wholesale
    // threw the other six away.
    $prev = ['days' => [], 'sessions' => []];
    for ($i = 0; $i < 7; $i++) {
        $prev['days'][] = ['date' => sprintf('2026-07-%02d', 27 + $i), 'meals' => ['old']];
    }
    $next = ['days' => [['date' => '2026-07-28', 'meals' => ['fixed']]]];

    $out = $merge($prev, $next);
    if (count($out['days']) !== 7) {
        return 'expected 7 days, got ' . count($out['days']);
    }
    foreach ($out['days'] as $d) {
        $want = $d['date'] === '2026-07-28' ? 'fixed' : 'old';
        if (($d['meals'][0] ?? null) !== $want) {
            return "{$d['date']} should carry '{$want}'";
        }
    }
    return true;
});

t('days come back in date order', function () use ($merge) {
    $out = $merge(
        ['days' => [['date' => '2026-07-29'], ['date' => '2026-07-27']]],
        ['days' => [['date' => '2026-07-28']]]
    );
    $dates = array_column($out['days'], 'date');
    return $dates === ['2026-07-27', '2026-07-28', '2026-07-29']
        ?: 'wrong order: ' . implode(',', $dates);
});

t('a committed and an optional session on one date both survive', function () use ($merge) {
    // Sessions cannot be keyed on date alone (SPEC-coaching §3.3a): keying on
    // date would silently drop one of the pair, which is data loss that looks
    // like a smaller week rather than an error.
    $prev = ['sessions' => [
        ['date' => '2026-07-27', 'is_committed' => true,  'session_type' => 'strength'],
        ['date' => '2026-07-27', 'is_committed' => false, 'session_type' => 'conditioning'],
    ]];
    $out = $merge($prev, ['sessions' => []]);
    return count($out['sessions']) === 2
        ?: 'expected both sessions, got ' . count($out['sessions']);
});

t('a retry replaces the session it restates, not its sibling', function () use ($merge) {
    $prev = ['sessions' => [
        ['date' => '2026-07-27', 'is_committed' => true,  'session_type' => 'strength', 'v' => 'old'],
        ['date' => '2026-07-27', 'is_committed' => false, 'session_type' => 'conditioning', 'v' => 'old'],
    ]];
    $next = ['sessions' => [
        ['date' => '2026-07-27', 'is_committed' => true, 'session_type' => 'strength', 'v' => 'new'],
    ]];
    $out = $merge($prev, $next);
    if (count($out['sessions']) !== 2) {
        return 'expected 2 sessions, got ' . count($out['sessions']);
    }
    foreach ($out['sessions'] as $s) {
        $want = ($s['is_committed'] ?? false) ? 'new' : 'old';
        if (($s['v'] ?? null) !== $want) {
            return "{$s['session_type']} should be '{$want}'";
        }
    }
    return true;
});

t('an undated entry is dropped rather than merged under an empty key', function () use ($merge) {
    $out = $merge(
        ['days' => [['date' => '2026-07-27', 'meals' => ['keep']]]],
        ['days' => [['meals' => ['no date']], ['date' => '', 'meals' => ['blank']]]]
    );
    return count($out['days']) === 1 && $out['days'][0]['date'] === '2026-07-27'
        ?: 'undated entries leaked in: ' . json_encode($out['days']);
});

t('summary fields take the retry value', function () use ($merge) {
    $out = $merge(
        ['summary' => 'first', 'expectations' => 'unchanged', 'days' => []],
        ['summary' => 'corrected']
    );
    return ($out['summary'] ?? null) === 'corrected'
        && ($out['expectations'] ?? null) === 'unchanged'
        ?: 'scalar merge wrong: ' . json_encode($out);
});

if ($seedOnly) {
    printf("\n%d passed, %d failed (seed only — no generation)\n", $pass, $fail);
    printf("Test users left in place: u1=%d u2=%d\n", $ids['u1'], $ids['u2']);
    exit($fail === 0 ? 0 : 1);
}

// ---------------------------------------------------------------------------

echo "\n5. live generation — User #1\n";

$week = nextMonday();
$gen1 = null;

t('generates and persists a week', function () use ($ids, $week, &$gen1, $liveGen) {
    if (!$liveGen) {
        return null;
    }
    $started = microtime(true);
    $gen1 = Plans::generateWeek($ids['u1'], $week, 'initial');
    $secs = round(microtime(true) - $started, 1);

    if (!$gen1['ok']) {
        return 'generation failed: ' . $gen1['error']
             . ($gen1['violations'] ? ' | violations: ' . json_encode($gen1['violations']) : '');
    }
    printf("        %ds, plan_version_id=%d\n", $secs, $gen1['plan_version_id']);
    return true;
});

if ($gen1 !== null && $gen1['ok']) {
    $plan = $gen1['plan'];

    t('exactly 5 committed sessions', function () use ($plan) {
        $committed = 0;
        $optional  = 0;
        foreach ($plan['sessions'] ?? [] as $s) {
            if (in_array($s['session_type'], ['active_recovery', 'rest'], true)) {
                continue;
            }
            ($s['is_committed'] ?? false) ? $committed++ : $optional++;
        }
        printf("        committed=%d optional=%d\n", $committed, $optional);
        return $committed === 5 ?: "expected 5 committed, got {$committed}";
    });

    t('every strength day has a core block', function () use ($plan) {
        foreach ($plan['sessions'] ?? [] as $s) {
            if (($s['session_type'] ?? '') !== 'strength') {
                continue;
            }
            $core = array_filter($s['exercises'] ?? [], fn($e) => ($e['block'] ?? '') === 'core');
            if ($core === []) {
                return "{$s['date']} has no core block";
            }
        }
        return true;
    });

    t('cardiac modifier produced required warm-ups', function () use ($plan) {
        $required = 0;
        foreach ($plan['sessions'] ?? [] as $s) {
            if (($s['warmup_required'] ?? false) === true) {
                $required++;
            }
        }
        printf("        sessions with warmup_required: %d\n", $required);
        // The modifier says "include a real warm-up on every session", so at
        // least the strength days should be flagged.
        return $required > 0 ?: 'no session marked warmup_required despite cardiac history';
    });

    t('no refused cardio prescribed', function () use ($plan) {
        foreach ($plan['sessions'] ?? [] as $s) {
            foreach ($s['exercises'] ?? [] as $e) {
                if (in_array($e['slug'] ?? '', ['running', 'rower'], true)) {
                    return "prescribed {$e['slug']}, which they refuse";
                }
            }
        }
        return true;
    });

    t('all 7 days have macro targets', function () use ($plan, $week) {
        $dates = array_column($plan['days'] ?? [], 'date');
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime($week . " +{$i} days"));
            if (!in_array($d, $dates, true)) {
                return "missing nutrition day {$d}";
            }
        }
        return true;
    });

    t('targets vary between training and rest days', function () use ($plan) {
        $cals = array_unique(array_column($plan['days'] ?? [], 'target_calories'));
        printf("        distinct daily calorie targets: %d\n", count($cals));
        return count($cals) > 1
            ?: 'every day has identical calories — training and rest should differ';
    });

    t('meals carry structured ingredients, not prose', function () use ($plan) {
        $specified = 0;
        foreach ($plan['days'] ?? [] as $d) {
            foreach ($d['meals'] ?? [] as $m) {
                if (($m['kind'] ?? '') !== 'specified') {
                    continue;
                }
                $specified++;
                $ings = $m['ingredients'] ?? [];
                if (!is_array($ings) || $ings === []) {
                    return "{$d['date']} {$m['slot']} is 'specified' with no ingredients";
                }
                if (!isset($ings[0]['item'])) {
                    return 'ingredient has no "item" key: ' . json_encode($ings[0]);
                }
            }
        }
        printf("        fully specified meals: %d\n", $specified);
        return $specified > 0 ?: 'no specified meals at all';
    });

    t('prep time respects the 20-minute weekday limit', function () use ($plan) {
        $over = [];
        foreach ($plan['days'] ?? [] as $d) {
            $weekday = (int) date('N', strtotime((string) $d['date']));
            if ($weekday > 5) {
                continue;   // weekends allow 45
            }
            foreach ($d['meals'] ?? [] as $m) {
                $prep = (int) ($m['prep_minutes'] ?? 0);
                if ($prep > 20) {
                    $over[] = "{$d['date']} {$m['slot']} ({$prep}min)";
                }
            }
        }
        if ($over !== []) {
            // Not a hard constraint — capacity guidance in the prompt. Report
            // rather than fail, so a soft miss doesn't fail the suite.
            printf("        note: over 20min on a weekday: %s\n", implode(', ', $over));
        }
        return true;
    });

    t('persisted plan hydrates back correctly', function () use ($gen1) {
        $h = Plans::hydrate($gen1['plan_version_id']);
        if ($h === null) {
            return 'hydrate returned null';
        }
        if (($h['sessions'] ?? []) === []) {
            return 'no sessions persisted';
        }
        if (($h['days'] ?? []) === []) {
            return 'no days persisted';
        }
        $exCount = 0;
        foreach ($h['sessions'] as $s) {
            $exCount += count($s['exercises'] ?? []);
        }
        printf("        persisted: %d sessions, %d exercises, %d days\n",
            count($h['sessions']), $exCount, count($h['days']));
        return $exCount > 0 ?: 'sessions persisted with no exercises';
    });

    t('plan is the live version', function () use ($ids, $week, $gen1) {
        $live = Plans::live($ids['u1'], $week);
        return ($live !== null && (int) $live['id'] === $gen1['plan_version_id'])
            ?: 'live lookup did not return the new plan';
    });

    t('regenerating supersedes rather than mutating', function () use ($ids, $week, $gen1) {
        // Persist a second version by hand — cheaper than a second API call,
        // and it is the versioning behaviour under test, not generation.
        $persist = (new ReflectionMethod(Plans::class, 'persist'));
        $persist->setAccessible(true);
        $second = $persist->invoke(null, $ids['u1'], $week, 'veto', $gen1['plan'], []);

        $rows = DB::all(
            'SELECT id, version, superseded_at FROM plan_versions
             WHERE user_id = ? AND week_start = ? ORDER BY version',
            [$ids['u1'], $week]
        );
        if (count($rows) !== 2) {
            return 'expected 2 versions, got ' . count($rows);
        }
        if ($rows[0]['superseded_at'] === null) {
            return 'v1 was not superseded';
        }
        if ($rows[1]['superseded_at'] !== null) {
            return 'v2 should be live';
        }
        // v1 must still exist — days already logged point at it.
        return (int) $rows[1]['id'] === $second ?: 'live version is not the new one';
    });

    echo "\n   --- User #1, week of {$week} ---\n";
    printf("   %s\n", wordwrap((string) ($plan['summary'] ?? ''), 68, "\n   "));
    if (!empty($plan['expectations'])) {
        printf("\n   Expectations: %s\n",
            wordwrap((string) $plan['expectations'], 68, "\n   "));
    }
    echo "\n";
    foreach ($plan['sessions'] ?? [] as $s) {
        $dow = date('D', strtotime((string) $s['date']));
        printf("   %s %s  %-16s %-12s %s%s\n",
            $dow, $s['date'], $s['session_type'], $s['focus'] ?? '',
            ($s['is_committed'] ?? false) ? 'COMMITTED' : 'optional',
            isset($s['target_minutes']) ? " ({$s['target_minutes']}min)" : '');
        foreach ($s['exercises'] ?? [] as $e) {
            $spec = [];
            if (isset($e['sets']))             { $spec[] = "{$e['sets']}x"; }
            if (isset($e['target_reps']))      { $spec[] = $e['target_reps']; }
            if (isset($e['target_weight_kg'])) { $spec[] = "@{$e['target_weight_kg']}kg"; }
            if (isset($e['target_seconds']))   { $spec[] = "{$e['target_seconds']}s"; }
            if (isset($e['target_rpe']))       { $spec[] = "RPE{$e['target_rpe']}"; }
            printf("       [%s] %-24s %s\n", $e['block'], $e['slug'], implode(' ', $spec));
        }
    }
}

echo "\n6. live generation — User #2 (buddy, different prescription)\n";

$gen2 = null;
t('generates a week for the buddy', function () use ($ids, $week, &$gen2, $liveGen) {
    if (!$liveGen) {
        return null;
    }
    $gen2 = Plans::generateWeek($ids['u2'], $week, 'initial');
    if (!$gen2['ok']) {
        return 'generation failed: ' . $gen2['error']
             . ($gen2['violations'] ? ' | ' . json_encode($gen2['violations']) : '');
    }
    printf("        plan_version_id=%d\n", $gen2['plan_version_id']);
    return true;
});

if ($gen2 !== null && $gen2['ok']) {
    $plan2 = $gen2['plan'];

    t('protein floor of 110g respected every day', function () use ($plan2) {
        $min = null;
        foreach ($plan2['days'] ?? [] as $d) {
            $p = (float) $d['target_protein_g'];
            $min = $min === null ? $p : min($min, $p);
            if ($p < 110.0) {
                return "{$d['date']} sets protein to {$p}g, below the 110g floor";
            }
        }
        printf("        lowest daily protein target: %.0fg\n", $min ?? 0);
        return true;
    });

    t('no shellfish anywhere', function () use ($plan2) {
        foreach ($plan2['days'] ?? [] as $d) {
            foreach ($d['meals'] ?? [] as $m) {
                $hay = strtolower((string) ($m['name'] ?? ''));
                foreach ($m['ingredients'] ?? [] as $i) {
                    $hay .= ' ' . strtolower((string) ($i['item'] ?? ''));
                }
                foreach (['shellfish', 'shrimp', 'prawn', 'crab', 'lobster'] as $bad) {
                    if (str_contains($hay, $bad)) {
                        return "{$d['date']} {$m['slot']} contains {$bad}";
                    }
                }
            }
        }
        return true;
    });

    t('no breakfast prescribed (she skips it)', function () use ($plan2) {
        foreach ($plan2['days'] ?? [] as $d) {
            foreach ($d['meals'] ?? [] as $m) {
                if (($m['slot'] ?? '') === 'breakfast' && ($m['kind'] ?? '') === 'specified') {
                    return "{$d['date']} prescribes a specified breakfast";
                }
            }
        }
        return true;
    });

    t('household measures used (no food scale)', function () use ($plan2) {
        $withHousehold = 0;
        $total = 0;
        foreach ($plan2['days'] ?? [] as $d) {
            foreach ($d['meals'] ?? [] as $m) {
                foreach ($m['ingredients'] ?? [] as $i) {
                    $total++;
                    if (!empty($i['household'])) {
                        $withHousehold++;
                    }
                }
            }
        }
        printf("        ingredients with household measures: %d/%d\n", $withHousehold, $total);
        // Guidance, not a hard constraint — report rather than fail.
        return true;
    });

    t('both users diverge on main lifts', function () use ($gen1, $plan2) {
        if ($gen1 === null || !$gen1['ok']) {
            return null;
        }
        $mainOf = function (array $plan): array {
            $out = [];
            foreach ($plan['sessions'] ?? [] as $s) {
                foreach ($s['exercises'] ?? [] as $e) {
                    if (($e['block'] ?? '') === 'main') {
                        $out[] = $e['slug'];
                    }
                }
            }
            return $out;
        };
        $a = $mainOf($gen1['plan']);
        $b = $mainOf($plan2);
        $shared = count(array_intersect($a, $b));
        printf("        U1 main lifts: %d, U2: %d, shared: %d\n",
            count($a), count($b), $shared);
        // Not asserting a specific divergence — the point is that both got a
        // real prescription rather than one being a copy of the other.
        return (count($a) > 0 && count($b) > 0) ?: 'one user got no main work';
    });
}

// ---------------------------------------------------------------------------
echo "\n6b. training and nutrition are generated separately\n";

t('the two schemas cover the whole plan between them, and do not overlap', function () {
    /*
     * The split is only safe because the halves are genuinely disjoint. If a field lived in
     * both, two calls could disagree about it and the merge would silently pick one.
     */
    $whole = array_keys(PlanSchema::build()['properties']);
    $train = array_keys(PlanSchema::training()['properties']);
    $food  = array_keys(PlanSchema::nutrition()['properties']);

    $both = array_intersect($train, $food);
    if ($both !== []) {
        return 'both halves claim: ' . implode(', ', $both);
    }
    $missing = array_diff($whole, array_merge($train, $food));
    return $missing === []
        ?: 'no half produces: ' . implode(', ', $missing);
});

t('the training schema asks for sessions and NOT for days', function () {
    // The whole point. A training call that still asks for seven days of meals has not been
    // split, it has been duplicated.
    $s = PlanSchema::training();
    if (!isset($s['properties']['sessions'])) {
        return 'the training half does not ask for sessions';
    }
    if (isset($s['properties']['days'])) {
        return 'the training half still asks for nutrition days';
    }
    return in_array('sessions', $s['required'], true)
        ?: 'sessions is optional in the training half';
});

t('the nutrition schema asks for days and NOT for sessions', function () {
    $s = PlanSchema::nutrition();
    if (!isset($s['properties']['days'])) {
        return 'the nutrition half does not ask for days';
    }
    return !isset($s['properties']['sessions'])
        ?: 'the nutrition half still asks for training sessions';
});

t('only one half writes the summary', function () {
    // Two independently written summaries of one week would contradict each other.
    $train = PlanSchema::training()['properties'];
    $food  = PlanSchema::nutrition()['properties'];
    if (!isset($train['summary'])) {
        return 'nobody writes the summary';
    }
    return !isset($food['summary'])
        ?: 'both halves write a summary';
});

t('both schemas survive the structured-output linter', function () {
    // Same rules as the combined one: no unsupported keywords, no blown optional budget.
    foreach (['training' => PlanSchema::training(),
              'nutrition' => PlanSchema::nutrition()] as $name => $schema) {
        $problems = PlanSchema::lint($schema);
        if ($problems !== []) {
            return "{$name}: " . implode('; ', $problems);
        }
    }
    return true;
});

t('the training validator ignores a missing meal plan', function () use ($ids) {
    /*
     * The assertion the whole split rests on. A training week with no days at all must be
     * judged clean by the training validator — otherwise a short food answer would still take
     * the training half down with it, which is the failure this was built to stop.
     */
    $u = $ids['u1'];
    $plan = [
        'summary' => 'x', 'expectations' => 'y',
        'sessions' => [],   // empty is fine here; checkCommittedCount is what judges the count
        // no 'days' key at all
    ];
    $v = Safety::validateTraining($plan, $u);
    foreach ($v as $violation) {
        if (stripos($violation, 'day') !== false || stripos($violation, 'meal') !== false) {
            return "the training validator complained about food: {$violation}";
        }
    }
    return true;
});

t('the nutrition validator still catches a one-day answer', function () use ($ids) {
    /*
     * The exact failure that cost three live generations: one day returned where seven were
     * asked for. It has to keep being caught — the split contains the damage, it does not make
     * a fragment acceptable.
     */
    $u = $ids['u1'];
    $v = Safety::validateNutrition([
        'days' => [['date' => date('Y-m-d'), 'meals' => []]],
    ], $u);
    foreach ($v as $violation) {
        if (str_contains($violation, 'a week needs 7')) {
            return true;
        }
    }
    return 'a one-day meal plan was accepted: ' . implode(' | ', $v);
});

t('the nutrition validator ignores missing sessions', function () use ($ids) {
    // The mirror of the training case. Food is judged on food.
    $u = $ids['u1'];
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[] = ['date' => date('Y-m-d', strtotime("+{$i} days")), 'meals' => []];
    }
    $v = Safety::validateNutrition(['days' => $days], $u);
    foreach ($v as $violation) {
        if (stripos($violation, 'session') !== false
            || stripos($violation, 'exercise') !== false) {
            return "the nutrition validator complained about training: {$violation}";
        }
    }
    return true;
});

t('validatePlan still checks both halves', function () use ($ids) {
    /*
     * The combined validator is still used by tests and by anything reasoning about a whole
     * plan, so splitting the implementation must not have narrowed it. A plan that is bad in
     * both directions should report both.
     */
    $u = $ids['u1'];
    $v = Safety::validatePlan(['sessions' => [], 'days' => []], $u);
    $sawFood = false;
    foreach ($v as $violation) {
        if (str_contains($violation, 'no days at all')) {
            $sawFood = true;
        }
    }
    return $sawFood ?: 'the combined validator no longer checks nutrition: ' . implode(' | ', $v);
});

t('a week with no meal days is listed as awaiting nutrition', function () use ($ids) {
    /*
     * The retry sweep's work list. Counted from prescribed_days rather than trusted from the
     * generation_meta flag: the rows are what the user actually has, and a flag can be stale in
     * both directions.
     */
    $u = $ids['u1'];
    $week = date('Y-m-d', strtotime('next monday'));

    DB::run('DELETE FROM plan_versions WHERE user_id = ? AND week_start = ?', [$u, $week]);
    $planId = (int) DB::insert(
        'INSERT INTO plan_versions (user_id, week_start, version, reason, summary)
         VALUES (?, ?, 1, "initial", "training only")',
        [$u, $week]
    );

    $found = false;
    foreach (Plans::awaitingNutrition(date('Y-m-d')) as $p) {
        if ($p['plan_version_id'] === $planId) {
            $found = true;
        }
    }
    if (!$found) {
        return 'a plan with no days was not picked up';
    }

    // And once it has days, it drops off the list.
    DB::run(
        'INSERT INTO prescribed_days
            (plan_version_id, day_date, target_calories, target_protein_g,
             target_fat_g, target_carbs_g)
         VALUES (?, ?, 2000, 150, 60, 200)',
        [$planId, $week]
    );
    foreach (Plans::awaitingNutrition(date('Y-m-d')) as $p) {
        if ($p['plan_version_id'] === $planId) {
            return 'a fed plan is still listed as awaiting nutrition';
        }
    }

    DB::run('DELETE FROM plan_versions WHERE id = ?', [$planId]);
    return true;
});

t('a week already gone by is not backfilled', function () use ($ids) {
    // Paying to fill in meals for a week somebody has already lived through helps nobody.
    $u = $ids['u1'];
    $old = date('Y-m-d', strtotime('monday -4 weeks'));

    DB::run('DELETE FROM plan_versions WHERE user_id = ? AND week_start = ?', [$u, $old]);
    $planId = (int) DB::insert(
        'INSERT INTO plan_versions (user_id, week_start, version, reason)
         VALUES (?, ?, 1, "initial")',
        [$u, $old]
    );

    foreach (Plans::awaitingNutrition(date('Y-m-d')) as $p) {
        if ($p['plan_version_id'] === $planId) {
            DB::run('DELETE FROM plan_versions WHERE id = ?', [$planId]);
            return 'a month-old week was queued for backfill';
        }
    }
    DB::run('DELETE FROM plan_versions WHERE id = ?', [$planId]);
    return true;
});

t('a superseded plan is never backfilled', function () use ($ids) {
    // It is not what the user is looking at, so feeding it would spend money on a plan nobody
    // can see.
    $u = $ids['u1'];
    $week = date('Y-m-d', strtotime('next monday'));

    DB::run('DELETE FROM plan_versions WHERE user_id = ? AND week_start = ?', [$u, $week]);
    $planId = (int) DB::insert(
        'INSERT INTO plan_versions (user_id, week_start, version, reason, superseded_at)
         VALUES (?, ?, 1, "initial", NOW())',
        [$u, $week]
    );

    $listed = false;
    foreach (Plans::awaitingNutrition(date('Y-m-d')) as $p) {
        if ($p['plan_version_id'] === $planId) {
            $listed = true;
        }
    }
    DB::run('DELETE FROM plan_versions WHERE id = ?', [$planId]);
    return !$listed ?: 'a superseded plan was queued for backfill';
});

t('filling a week that already has meals costs nothing', function () use ($ids) {
    /*
     * Two overlapping sweeps, or a manual run after an automatic one. fillNutrition has to
     * check before it spends, because the claim only guards concurrent runs and not a second
     * one a minute later.
     */
    $u = $ids['u1'];
    $week = date('Y-m-d', strtotime('next monday'));

    DB::run('DELETE FROM plan_versions WHERE user_id = ? AND week_start = ?', [$u, $week]);
    $planId = (int) DB::insert(
        'INSERT INTO plan_versions (user_id, week_start, version, reason)
         VALUES (?, ?, 1, "initial")',
        [$u, $week]
    );
    DB::run(
        'INSERT INTO prescribed_days
            (plan_version_id, day_date, target_calories, target_protein_g,
             target_fat_g, target_carbs_g)
         VALUES (?, ?, 2000, 150, 60, 200)',
        [$planId, $week]
    );

    $before = (int) (DB::one('SELECT COUNT(*) AS n FROM ai_calls')['n'] ?? 0);
    $r = Plans::fillNutrition($u, $week);
    $after = (int) (DB::one('SELECT COUNT(*) AS n FROM ai_calls')['n'] ?? 0);

    DB::run('DELETE FROM plan_versions WHERE id = ?', [$planId]);

    if (!$r['ok']) {
        return 'filling an already-fed week reported failure: ' . (string) $r['error'];
    }
    return $before === $after
        ?: 'an already-fed week still made a model call';
});

t('the nutrition prompt carries the training week it is feeding', function () use ($ids) {
    /*
     * The food half has to know which days are heavy, or it cannot put the carbohydrates
     * anywhere sensible. It must NOT be handed the exercise list — a thousand tokens of rep
     * ranges it cannot act on.
     */
    $u    = $ids['u1'];
    $week = date('Y-m-d', strtotime('next monday'));

    $gather = new ReflectionMethod(Plans::class, 'gatherContext');
    $gather->setAccessible(true);
    $ctx = $gather->invoke(null, $u, $week);
    if (($ctx['error'] ?? null) !== null) {
        return 'context failed: ' . (string) $ctx['error'];
    }

    $m = new ReflectionMethod(Plans::class, 'nutritionPrompt');
    $m->setAccessible(true);
    $text = (string) $m->invoke(null, $ctx, $week, [
        'summary'  => 'A calibration week.',
        'sessions' => [[
            'date' => $week, 'session_type' => 'strength', 'focus' => 'lower',
            'is_committed' => true, 'target_minutes' => 60,
        ]],
    ]);

    if (!str_contains($text, 'MEAL PLAN')) {
        return 'the prompt does not say it wants a meal plan';
    }
    if (!str_contains($text, 'strength')) {
        return 'the training week is not described to the food half';
    }
    if (!str_contains($text, 'rest')) {
        return 'the rest days are not marked, so the food cannot follow the training';
    }
    if (!str_contains($text, 'A calibration week.')) {
        return 'the week\'s intent is not passed on';
    }
    // Seven dates, so a short answer has no excuse.
    if (!str_contains($text, 'EVERY ONE of those seven dates')) {
        return 'the prompt does not insist on all seven days';
    }
    // The food half must not re-emit the training, which would be a second chance to get it
    // wrong and a second chance to disagree with what is already persisted.
    return str_contains($text, 'do not return it')
        ?: 'the prompt does not tell it to leave the training alone';
});

t('the prompt forbids sessions on unavailable days, optional included', function () use ($ids) {
    /*
     * Two live generations died on this and both times the offending sessions were OPTIONAL:
     * cardio on a Tuesday and mobility on a Thursday, on a user whose grid said neither day was
     * available. The model was not overreaching on the commitment — it had filled its quota
     * from the shared days and then added bonuses, which the prompt invites without ever saying
     * bonuses live on available days too.
     *
     * The validator rejects those outright, so the prompt has to be at least as firm as the
     * validator. Anything softer just buys a rejected plan and a wasted generation.
     */
    $u    = $ids['u1'];
    $week = date('Y-m-d', strtotime('next monday'));

    $gather = new ReflectionMethod(Plans::class, 'gatherContext');
    $gather->setAccessible(true);
    $ctx = $gather->invoke(null, $u, $week);
    if (($ctx['error'] ?? null) !== null) {
        return 'context failed: ' . (string) $ctx['error'];
    }

    /*
     * Checked across BOTH prompts.
     *
     * The standing rule lives in systemPrompt, which is the cached prefix and the right home
     * for something that never varies. The per-request reminder is in userPrompt. Reading only
     * one reported a missing rule that was present all along, which is the same mistake the
     * shared-day marker test records making.
     */
    $sys = new ReflectionMethod(Plans::class, 'systemPrompt');
    $sys->setAccessible(true);
    $prompt = new ReflectionMethod(Plans::class, 'userPrompt');
    $prompt->setAccessible(true);
    $all = (string) $sys->invoke(null, $ctx)
         . "\n" . (string) $prompt->invoke(null, $ctx, $week, []);

    if (!str_contains($all, 'NEVER SCHEDULE ANYTHING ON AN UNAVAILABLE DAY')) {
        return 'the rule is not stated';
    }
    // And that it explicitly covers the case that actually failed.
    return str_contains($all, 'not an optional one')
        ?: 'the rule does not say optional sessions are covered';
});

t('a trend with too few readings does not break the nutrition prompt', function () use ($ids) {
    /*
     * weightTrend always returns something, but with fewer than two readings it returns only
     * ['points', 'direction' => 'insufficient data'] — no weeks, no delta_kg. The first version
     * of nutritionPrompt guarded on the array being non-null, which passes, and then
     * interpolated two keys that were not there.
     *
     * Caught as two PHP warnings inside a paid live generation, which is the expensive way to
     * find a missing guard.
     */
    $u    = $ids['u1'];
    $week = date('Y-m-d', strtotime('next monday'));

    $gather = new ReflectionMethod(Plans::class, 'gatherContext');
    $gather->setAccessible(true);
    $ctx = $gather->invoke(null, $u, $week);

    // Force the shape a brand-new user has.
    $ctx['trend'] = ['points' => [], 'direction' => 'insufficient data'];

    $m = new ReflectionMethod(Plans::class, 'nutritionPrompt');
    $m->setAccessible(true);

    $warnings = [];
    set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
        $warnings[] = $msg;
        return true;
    });
    try {
        $text = (string) $m->invoke(null, $ctx, $week, ['sessions' => [], 'summary' => '']);
    } finally {
        restore_error_handler();
    }

    if ($warnings !== []) {
        return 'the prompt raised: ' . implode('; ', $warnings);
    }
    // And it says nothing about a trend it does not have.
    return !str_contains($text, 'WEIGHT TREND')
        ?: 'a trend section was written for a user with no readings';
});

echo "\n7. cost\n";
$summary = Claude::usageSummary(1);
foreach ($summary['by_purpose'] as $row) {
    printf("  %-18s %3d calls  in=%-7d out=%-6d cached=%-7d  ~$%s\n",
        $row['purpose'], $row['calls'], $row['input_tokens'],
        $row['output_tokens'], $row['cached_tokens'],
        $row['est_cost'] === null ? '?' : number_format((float) $row['est_cost'], 4));
}
printf("  estimated total today: $%s\n", number_format((float) $summary['est_total'], 4));

if (!$keep) {
    cleanup();
    echo "\n  test users removed\n";
} else {
    printf("\n  test users kept: u1=%d u2=%d\n", $ids['u1'], $ids['u2']);
}

if (!$liveGen) {
    echo "\nnote: run with --live to actually generate weeks (costs money, takes minutes).\n";
}
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
