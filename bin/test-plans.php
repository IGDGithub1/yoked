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
require YK_SRC . '/lib/ConstraintLabel.php';  // Settings reads it
require YK_SRC . '/lib/Settings.php';         // the home gym kit is editable there

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
    /*
     * A DIFFERENT pair of exercises each day.
     *
     * This fixture used to repeat leg-press and plank across all five days, which
     * checkWeeklyFrequency now rejects — correctly, since six exposures to one movement in a
     * week does not leave enough recovery. A "clean plan" fixture has to be a plan that is
     * actually clean, so it rotates.
     */
    $main = ['leg-press', 'back-squat', 'db-bench-press', 'barbell-row', 'overhead-press'];
    $core = ['plank', 'dead-bug', 'side-plank', 'pallof-press', 'bird-dog'];

    $sessions = [];
    for ($i = 0; $i < 5; $i++) {
        $sessions[] = [
            'date' => date('Y-m-d', strtotime(nextMonday() . " +{$i} days")),
            'session_type' => 'strength', 'focus' => 'full', 'is_committed' => true,
            'target_minutes' => 55, 'location' => 'full_gym',
            'exercises' => [
                ['slug' => $main[$i], 'block' => 'main', 'sets' => 3, 'target_reps' => '10'],
                ['slug' => $core[$i], 'block' => 'core', 'sets' => 3, 'target_seconds' => 40],
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
    // The parenthetical goes on its own line: testall.sh matches the summary anchored
    // end-to-end, so anything trailing it reads as NO SUMMARY and reports the suite as
    // broken. Same trap that hid test-claude.php's offline summary.
    printf("\n%d passed, %d failed\n", $pass, $fail);
    echo "  (seed only — no generation)\n";
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

// ---------------------------------------------------------------------------
echo "\n6c. one exercise, one entry — and a vocabulary worth choosing from\n";

t('the same slug twice in one session is rejected', function () use ($ids) {
    /*
     * A live generation produced "Dumbbell Row @8kg", then again at 9kg, then again at 10kg:
     * three sets of one movement written as three exercises, from a substitution that collided
     * with something already in the session.
     *
     * Rejected outright rather than only when the prescriptions match — the 8/9/10kg case would
     * have PASSED a narrower rule, and nothing in the UI renders a repeat as anything but two
     * separate exercises.
     */
    $u = $ids['u1'];
    $v = Safety::validateTraining([
        'sessions' => [[
            'date' => date('Y-m-d', strtotime('next monday')),
            'session_type' => 'strength',
            'exercises' => [
                ['slug' => 'dumbbell-row', 'block' => 'main', 'sets' => 3, 'target_reps' => '10'],
                ['slug' => 'dumbbell-row', 'block' => 'main', 'sets' => 3, 'target_reps' => '12'],
            ],
        ]],
    ], $u);

    foreach ($v as $violation) {
        if (str_contains($violation, 'more than once in the same session')) {
            return true;
        }
    }
    return 'a repeated exercise was accepted: ' . implode(' | ', $v);
});

t('a repeat is caught across blocks, not just within one', function () {
    /*
     * Scoped per SESSION rather than per block. The same movement in the warm-up and the main
     * block is still two rows on one screen, which is the thing that reads as broken.
     */
    $m = new ReflectionMethod(Safety::class, 'checkNoRepeats');
    $m->setAccessible(true);
    $v = $m->invoke(null, [
        'sessions' => [[
            'date' => '2026-08-03',
            'exercises' => [
                ['slug' => 'plank', 'block' => 'warmup'],
                ['slug' => 'plank', 'block' => 'core'],
            ],
        ]],
    ]);
    return $v !== [] ?: 'the same movement in two blocks was accepted';
});

t('an alias does not smuggle a duplicate past the check', function () {
    /*
     * Compared on the RESOLVED slug. An alias pair is exactly the shape a duplicate hides in:
     * two spellings of one movement look different to a string comparison and identical to the
     * person doing them.
     */
    $alias = DB::one(
        'SELECT a.alias, e.slug FROM exercise_aliases a
         JOIN exercises e ON e.id = a.exercise_id LIMIT 1'
    );
    if ($alias === null) {
        return null;   // no aliases seeded; nothing this test can say
    }

    $m = new ReflectionMethod(Safety::class, 'checkNoRepeats');
    $m->setAccessible(true);
    $v = $m->invoke(null, [
        'sessions' => [[
            'date' => '2026-08-03',
            'exercises' => [
                ['slug' => (string) $alias['slug'],  'block' => 'main'],
                ['slug' => (string) $alias['alias'], 'block' => 'main'],
            ],
        ]],
    ]);
    return $v !== []
        ?: "'{$alias['alias']}' and '{$alias['slug']}' were treated as different exercises";
});

t('two different exercises in one session are fine', function () {
    // The check must not fire on the normal case.
    $m = new ReflectionMethod(Safety::class, 'checkNoRepeats');
    $m->setAccessible(true);
    $v = $m->invoke(null, [
        'sessions' => [[
            'date' => '2026-08-03',
            'exercises' => [
                ['slug' => 'plank', 'block' => 'core'],
                ['slug' => 'dead-bug', 'block' => 'core'],
            ],
        ]],
    ]);
    return $v === [] ?: 'a clean session was rejected: ' . implode(' | ', $v);
});

t('the same slug on DIFFERENT days is fine', function () {
    // Squatting Monday and Thursday is a programme, not a duplicate.
    $m = new ReflectionMethod(Safety::class, 'checkNoRepeats');
    $m->setAccessible(true);
    $v = $m->invoke(null, [
        'sessions' => [
            ['date' => '2026-08-03', 'exercises' => [['slug' => 'plank', 'block' => 'core']]],
            ['date' => '2026-08-06', 'exercises' => [['slug' => 'plank', 'block' => 'core']]],
        ],
    ]);
    return $v === [] ?: 'the same movement on two days was rejected';
});

t('the prompt asks for one entry per exercise', function () use ($ids) {
    // Belt and braces: the check catches it, the rule stops it happening.
    $u    = $ids['u1'];
    $week = date('Y-m-d', strtotime('next monday'));

    $gather = new ReflectionMethod(Plans::class, 'gatherContext');
    $gather->setAccessible(true);
    $ctx = $gather->invoke(null, $u, $week);

    $sys = new ReflectionMethod(Plans::class, 'systemPrompt');
    $sys->setAccessible(true);
    return str_contains((string) $sys->invoke(null, $ctx), 'ONE ENTRY PER EXERCISE PER SESSION')
        ?: 'the rule is not in the prompt';
});

t('the vocabulary is narrowed to what the user can actually perform', function () {
    /*
     * vocabulary() took an $access parameter that was never passed, so a bodyweight-only user
     * was shown all 90 exercises, picked a barbell lift, and had the plan rejected by
     * checkAvailability afterwards — a wasted generation caused by offering a choice that was
     * always going to be refused.
     */
    $all  = PlanSchema::vocabulary();
    $body = PlanSchema::vocabulary(null, ['bodyweight']);

    $count = static function (array $v): int {
        $n = 0;
        foreach ($v as $patterns) {
            foreach ($patterns as $slugs) {
                $n += count($slugs);
            }
        }
        return $n;
    };

    $nAll  = $count($all);
    $nBody = $count($body);

    if ($nBody === 0) {
        return 'a bodyweight user gets an EMPTY vocabulary';
    }
    return $nBody < $nAll
        ?: "bodyweight sees {$nBody} of {$nAll} exercises, which is no filtering at all";
});

t('a mixed week gets the UNION, not the most restrictive day', function () {
    /*
     * Access is per DAY. Somebody with a full gym on Monday and a park on Saturday can still
     * squat on Monday, so filtering to the worst day of the week would hide most of their
     * library. The availability grid says which day is which.
     */
    $mixed = PlanSchema::vocabulary(null, ['full_gym', 'bodyweight']);
    $body  = PlanSchema::vocabulary(null, ['bodyweight']);

    $count = static function (array $v): int {
        $n = 0;
        foreach ($v as $patterns) {
            foreach ($patterns as $slugs) {
                $n += count($slugs);
            }
        }
        return $n;
    };
    return $count($mixed) > $count($body)
        ?: 'a mixed week was narrowed to its most restrictive day';
});

t('a banned movement is MARKED in the vocabulary, not hidden', function () use ($ids) {
    /*
     * Marked rather than removed. A ban is free text — a real one reads "box jumps", which is
     * not a slug — so subtracting reliably is not possible, and a model that cannot see why
     * something is absent may reach for an adjacent movement that is equally forbidden.
     *
     * Safety::validatePlan still enforces it. This only saves a generation.
     */
    $u = $ids['u1'];

    // A ban recorded as a display name, which is the awkward case.
    $ex = DB::one('SELECT slug, name FROM exercises WHERE slug = "back-squat"')
        ?? DB::one('SELECT slug, name FROM exercises WHERE pattern = "squat" LIMIT 1');
    if ($ex === null) {
        return null;
    }
    DB::run(
        'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
         VALUES (?, "movement", "hard", ?, "probe", "onboarding")',
        [$u, (string) $ex['name']]
    );

    try {
        $banned = Safety::bannedSlugs($u);
        if (!isset($banned[(string) $ex['slug']])) {
            return "the ban on '{$ex['name']}' does not cover its own slug";
        }

        $gather = new ReflectionMethod(Plans::class, 'gatherContext');
        $gather->setAccessible(true);
        $ctx = $gather->invoke(null, $u, date('Y-m-d', strtotime('next monday')));

        $sys = new ReflectionMethod(Plans::class, 'systemPrompt');
        $sys->setAccessible(true);
        $text = (string) $sys->invoke(null, $ctx);

        if (!str_contains($text, "{$ex['slug']} [BANNED]")) {
            return 'the banned exercise is not marked in the vocabulary';
        }
        // Still listed, so the model can see the exclusion rather than guessing at a gap.
        return str_contains($text, '[BANNED] is off limits')
            ?: 'nothing explains what the marking means';
    } finally {
        DB::run(
            'DELETE FROM user_constraints WHERE user_id = ? AND reason = "probe"', [$u]
        );
    }
});

t('the annotation agrees with the validator', function () use ($ids) {
    /*
     * The property that makes marking safe. If the two disagreed, the prompt would either mark
     * something the validator allows — narrowing the options for no reason — or fail to mark
     * something it rejects, which is the wasted generation this exists to prevent.
     *
     * They share one matching function; this asserts the outcome rather than the mechanism.
     */
    $u = $ids['u1'];
    $ex = DB::one('SELECT slug, name FROM exercises WHERE pattern = "squat" LIMIT 1');
    if ($ex === null) {
        return null;
    }
    DB::run(
        'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
         VALUES (?, "movement", "hard", ?, "probe", "onboarding")',
        [$u, (string) $ex['name']]
    );

    try {
        $marked = isset(Safety::bannedSlugs($u)[(string) $ex['slug']]);

        // What the validator says about a plan that uses it.
        $v = Safety::validateTraining([
            'sessions' => [[
                'date' => date('Y-m-d', strtotime('next monday')),
                'session_type' => 'strength',
                'exercises' => [
                    ['slug' => (string) $ex['slug'], 'block' => 'main',
                     'sets' => 3, 'target_reps' => '8'],
                ],
            ]],
        ], $u);
        $rejected = false;
        foreach ($v as $violation) {
            if (str_contains($violation, 'hard constraint')) {
                $rejected = true;
            }
        }

        return $marked === $rejected
            ?: sprintf(
                'marked=%s but validator rejects=%s',
                $marked ? 'yes' : 'no',
                $rejected ? 'yes' : 'no'
            );
    } finally {
        DB::run(
            'DELETE FROM user_constraints WHERE user_id = ? AND reason = "probe"', [$u]
        );
    }
});

// ---------------------------------------------------------------------------
echo "\n6d. a home gym is whatever the user says it is\n";

/** Total slugs in a vocabulary. */
function vocabSize(array $v): int
{
    $n = 0;
    foreach ($v as $patterns) {
        foreach ($patterns as $slugs) {
            $n += count($slugs);
        }
    }
    return $n;
}

t('home_gym was a synonym for full_gym, and is not any more', function () {
    /*
     * The defect. availableAt returned true for every exercise on a home_gym day, so somebody
     * with two dumbbells in a spare room was offered a cable tower, a hack squat and a pool.
     *
     * Nothing caught it: checkAvailability compares the SESSION location against the day
     * access, not exercises against equipment. The user just got a plan they could not perform.
     */
    $all  = vocabSize(PlanSchema::vocabulary(null, ['full_gym']));
    $home = vocabSize(PlanSchema::vocabulary(null, ['home_gym'], ['dumbbell']));

    return $home < $all
        ?: "a dumbbell-only home gym still sees all {$all} exercises";
});

t('an unanswered kit stays permissive', function () {
    /*
     * NULL and [] mean different things, and the difference is load-bearing.
     *
     * NULL is a user who onboarded before the question existed. Silently taking away their
     * barbell because we never asked would be worse than the bug this fixes. [] is a real
     * answer — "I have nothing" — and narrows to bodyweight.
     */
    $never = vocabSize(PlanSchema::vocabulary(null, ['home_gym'], null));
    $none  = vocabSize(PlanSchema::vocabulary(null, ['home_gym'], []));

    if ($never <= $none) {
        return 'an unasked user was narrowed as if they owned nothing';
    }
    return $none > 0
        ?: 'a user with no equipment gets an empty vocabulary';
});

t('each item unlocks more, and only what it should', function () {
    $none = vocabSize(PlanSchema::vocabulary(null, ['home_gym'], []));
    $db   = vocabSize(PlanSchema::vocabulary(null, ['home_gym'], ['dumbbell']));
    $both = vocabSize(PlanSchema::vocabulary(null, ['home_gym'], ['dumbbell', 'bench']));

    if (!($none < $db && $db < $both)) {
        return "not monotonic: none={$none} db={$db} db+bench={$both}";
    }

    // A dumbbell owner does not get machine work.
    $v = PlanSchema::vocabulary(null, ['home_gym'], ['dumbbell']);
    $flat = [];
    foreach ($v as $patterns) {
        foreach ($patterns as $slugs) {
            foreach ($slugs as $s) {
                $flat[$s] = true;
            }
        }
    }
    foreach (['leg-press', 'hack-squat', 'back-squat', 'seated-cable-row'] as $gymOnly) {
        if (isset($flat[$gymOnly])) {
            return "a dumbbell-only home gym was offered {$gymOnly}";
        }
    }
    // And it DOES get the dumbbell work.
    return isset($flat['goblet-squat']) && isset($flat['db-curl'])
        ?: 'a dumbbell owner was not offered dumbbell exercises';
});

t('an exercise needing two items needs BOTH', function () {
    /*
     * db-row is ["dumbbell", "bench"]. Owning the dumbbells is not enough, and a filter that
     * matched on any single token rather than all of them would offer it.
     */
    $dbOnly = PlanSchema::vocabulary(null, ['home_gym'], ['dumbbell']);
    $flat = [];
    foreach ($dbOnly as $patterns) {
        foreach ($patterns as $slugs) {
            foreach ($slugs as $s) {
                $flat[$s] = true;
            }
        }
    }
    if (isset($flat['db-row'])) {
        return 'db-row was offered to somebody with no bench';
    }

    $withBench = PlanSchema::vocabulary(null, ['home_gym'], ['dumbbell', 'bench']);
    $flat2 = [];
    foreach ($withBench as $patterns) {
        foreach ($patterns as $slugs) {
            foreach ($slugs as $s) {
                $flat2[$s] = true;
            }
        }
    }
    return isset($flat2['db-row'])
        ?: 'db-row was withheld from somebody with dumbbells and a bench';
});

t('a full gym day ignores the home kit entirely', function () {
    // The kit describes a home gym. It must not narrow the day they go to a real one.
    $a = vocabSize(PlanSchema::vocabulary(null, ['full_gym'], []));
    $b = vocabSize(PlanSchema::vocabulary(null, ['full_gym'], ['dumbbell']));
    $c = vocabSize(PlanSchema::vocabulary(null, ['full_gym'], null));
    return ($a === $b && $b === $c)
        ?: "full gym varies with the home kit: {$a}/{$b}/{$c}";
});

t('a mixed week keeps the gym day whole', function () {
    /*
     * Someone training at home Monday and at a gym Saturday can still squat on Saturday. The
     * union is what the vocabulary offers; the availability grid says which day is which.
     */
    $mixed = vocabSize(PlanSchema::vocabulary(null, ['home_gym', 'full_gym'], ['dumbbell']));
    $full  = vocabSize(PlanSchema::vocabulary(null, ['full_gym']));
    return $mixed === $full
        ?: "a mixed week sees {$mixed} where the gym day alone sees {$full}";
});

t('the kit reaches generation from the profile', function () use ($ids) {
    // The whole chain: training_preferences → gatherContext → the rendered vocabulary.
    $u = $ids['u1'];

    $before = DB::one(
        'SELECT home_equipment FROM training_preferences WHERE user_id = ?', [$u]
    );

    // Make every day a home gym day, with dumbbells only.
    DB::run('UPDATE availability SET access = "home_gym" WHERE user_id = ?', [$u]);
    DB::run(
        'INSERT INTO training_preferences (user_id, home_equipment) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE home_equipment = VALUES(home_equipment)',
        [$u, json_encode(['dumbbell'])]
    );

    try {
        $gather = new ReflectionMethod(Plans::class, 'gatherContext');
        $gather->setAccessible(true);
        $ctx = $gather->invoke(null, $u, date('Y-m-d', strtotime('next monday')));
        if (($ctx['error'] ?? null) !== null) {
            return 'context failed: ' . (string) $ctx['error'];
        }

        $sys = new ReflectionMethod(Plans::class, 'systemPrompt');
        $sys->setAccessible(true);
        $text = (string) $sys->invoke(null, $ctx);

        if (str_contains($text, 'hack-squat') || str_contains($text, 'leg-press')) {
            return 'machine work reached the prompt for a dumbbell-only home gym';
        }
        return str_contains($text, 'goblet-squat')
            ?: 'the dumbbell work did not reach the prompt';
    } finally {
        DB::run('UPDATE availability SET access = "full_gym" WHERE user_id = ?', [$u]);
        DB::run(
            'UPDATE training_preferences SET home_equipment = ? WHERE user_id = ?',
            [$before['home_equipment'] ?? null, $u]
        );
    }
});

t('the kit is editable, and rejects anything not on the list', function () use ($ids) {
    /*
     * People buy a bench, or move house and lose the garage rack. Onboarding asks once; the
     * profile is where it gets corrected without re-running the quiz.
     */
    $u = $ids['u1'];
    $before = Settings::homeEquipment($u);

    try {
        $r = Settings::save($u, ['home_equipment' => ['dumbbell', 'bench']]);
        if (!$r['ok']) {
            return 'a valid kit was rejected: ' . (string) $r['error'];
        }
        if (Settings::homeEquipment($u) !== ['dumbbell', 'bench']) {
            return 'the kit did not persist';
        }

        // Empty is a real answer, not a no-op.
        $r = Settings::save($u, ['home_equipment' => []]);
        if (!$r['ok'] || Settings::homeEquipment($u) !== []) {
            return 'clearing the kit did not work';
        }

        // Anything outside the six is a client bug or someone poking the API; storing it would
        // quietly widen the filter.
        $bad = Settings::save($u, ['home_equipment' => ['leg_press']]);
        return $bad['ok'] === false
            ?: 'an equipment token outside the offered list was accepted';
    } finally {
        DB::run(
            'UPDATE training_preferences SET home_equipment = ? WHERE user_id = ?',
            [$before === null ? null : json_encode($before), $u]
        );
    }
});

// ---------------------------------------------------------------------------
echo "\n6e. the vocabulary is scoped to what the week could use\n";

t('activities are reportable-only unless there is an outdoors day', function () {
    /*
     * Golf, kayaking, walking the dog. They exist so a user can LOG "I went hiking" and so the
     * calorie side can price it — the coach never prescribes one. On an outdoors day they are
     * the exception: a hike IS the session when there is no equipment and no roof (§3.3).
     */
    $indoor  = PlanSchema::categoriesFor(['full_gym']);
    $outdoor = PlanSchema::categoriesFor(['full_gym', 'outdoors']);

    if (in_array('activity', $indoor, true)) {
        return 'activities were offered to a gym-only week';
    }
    return in_array('activity', $outdoor, true)
        ?: 'activities were withheld from a week with an outdoors day';
});

t('mobility never reaches the vocabulary', function () {
    /*
     * Warm-ups are prescribed as warmup_detail PROSE, not as library slugs — the rules say
     * "set warmup_minutes and warmup_detail on every training session". So 139 stretches would
     * sit in the cached prefix being unusable.
     */
    foreach ([['full_gym'], ['outdoors'], ['bodyweight'], ['home_gym']] as $set) {
        if (in_array('mobility', PlanSchema::categoriesFor($set), true)) {
            return 'mobility was sent for access ' . implode('+', $set);
        }
    }
    return true;
});

t('strength and core always go', function () {
    // The substance of every training week, whatever else is true.
    foreach ([['bodyweight'], ['outdoors'], ['full_gym']] as $set) {
        $c = PlanSchema::categoriesFor($set, false);
        if (!in_array('strength', $c, true) || !in_array('core', $c, true)) {
            return 'strength or core was withheld for ' . implode('+', $set);
        }
    }
    return true;
});

t('somebody who does no cardio is not sent the cardio list', function () {
    $with    = PlanSchema::categoriesFor(['full_gym'], true);
    $without = PlanSchema::categoriesFor(['full_gym'], false);
    if (!in_array('cardio', $with, true)) {
        return 'cardio was withheld from somebody willing to do it';
    }
    return !in_array('cardio', $without, true)
        ?: 'cardio was sent to somebody who refuses all of it';
});

t('an unanswered cardio preference still gets cardio', function () use ($ids) {
    /*
     * An empty list from a user who was never asked is not a refusal. Silently withholding
     * cardio from them is a worse error than a slightly longer prompt, so the default is to
     * send it.
     */
    $u = $ids['u1'];
    $before = DB::one(
        'SELECT cardio_willing FROM training_preferences WHERE user_id = ?', [$u]
    );

    DB::run(
        'UPDATE training_preferences SET cardio_willing = NULL WHERE user_id = ?', [$u]
    );
    try {
        $gather = new ReflectionMethod(Plans::class, 'gatherContext');
        $gather->setAccessible(true);
        $ctx = $gather->invoke(null, $u, date('Y-m-d', strtotime('next monday')));
        return isset($ctx['vocabulary']['cardio'])
            ?: 'a user who was never asked lost their cardio vocabulary';
    } finally {
        DB::run(
            'UPDATE training_preferences SET cardio_willing = ? WHERE user_id = ?',
            [$before['cardio_willing'] ?? null, $u]
        );
    }
});

t('scoping actually shrinks the prompt', function () {
    /*
     * The point of the exercise. Measured rather than assumed: the library is about to grow
     * from 90 to over a thousand, which takes the vocabulary from ~400 tokens to ~4,400 in the
     * CACHED PREFIX of every generation.
     */
    $render = static function (array $v): int {
        $n = 0;
        foreach ($v as $cat => $pats) {
            $n += strlen((string) $cat) + 2;
            foreach ($pats as $p => $slugs) {
                $n += strlen((string) $p) + strlen(implode(', ', $slugs)) + 4;
            }
        }
        return $n;
    };

    $everything = $render(PlanSchema::vocabulary());
    $scoped     = $render(PlanSchema::vocabulary(
        null, ['full_gym'], null, PlanSchema::categoriesFor(['full_gym'])
    ));

    return $scoped < $everything
        ?: "scoping saved nothing: {$scoped} against {$everything} chars";
});

t('a scoped vocabulary still contains real exercises', function () {
    /*
     * The failure mode of a filter is emptiness, and an empty vocabulary produces a plan with
     * no exercises rather than an error anybody notices.
     */
    $v = PlanSchema::vocabulary(
        null, ['bodyweight'], null, PlanSchema::categoriesFor(['bodyweight'], true)
    );
    if (!isset($v['strength']) || $v['strength'] === []) {
        return 'a bodyweight week has no strength exercises at all';
    }
    return isset($v['core']) && $v['core'] !== []
        ?: 'a bodyweight week has no core exercises at all';
});

// ---------------------------------------------------------------------------
echo "\n6f. muscle groups, and the isolation cap they enable\n";

t('every real exercise knows what it works', function () {
    /*
     * `pattern` is a MOVEMENT classification and says nothing about anatomy — a bicep curl and
     * a lateral raise are both isolation. Without a muscle column there is no way to cap
     * isolation sensibly, detect a week that hammers quads and ignores hamstrings, or answer
     * "what does this work?" for a beginner.
     */
    $row = DB::one(
        'SELECT COUNT(*) AS n FROM exercises
         WHERE primary_muscle IS NULL AND category IN ("strength", "core")'
    );
    $n = (int) ($row['n'] ?? 0);
    return $n === 0 ?: "{$n} strength or core exercises have no primary muscle";
});

t('activities correctly have NO muscle', function () {
    /*
     * Golf, kayaking, walking the dog. Inventing a muscle for them would be worse than NULL —
     * it would put "Billiards" into a quadriceps balance check.
     */
    $row = DB::one(
        'SELECT COUNT(*) AS n FROM exercises
         WHERE primary_muscle IS NOT NULL AND category = "activity"'
    );
    return (int) ($row['n'] ?? 0) === 0
        ?: 'an activity was assigned a muscle it does not work';
});

t('isolation is capped per muscle, and compounds are not', function () {
    /*
     * Isolation was 241 of 1008 exercises and ~1,642 tokens, over a third of a gym user's
     * vocabulary: 50 bicep curls, 47 tricep extensions, 46 shoulder raises. Perhaps eight of
     * those curls are different movements.
     *
     * Squats are NOT capped, and the distinction is the point: a front squat and a Bulgarian
     * split squat train different things, so 58 of them is depth rather than redundancy.
     */
    $v = PlanSchema::vocabulary(null, ['full_gym'], null, ['strength']);
    $iso = count($v['strength']['isolation'] ?? []);
    $sq  = count($v['strength']['squat'] ?? []);

    $total = (int) (DB::one(
        'SELECT COUNT(*) AS n FROM exercises WHERE pattern = "isolation"'
    )['n'] ?? 0);

    if ($iso >= $total) {
        return "isolation was not capped: {$iso} of {$total}";
    }
    // Roughly 6 per muscle across ~15 muscles. Asserted loosely, since the access filter
    // and the muscle spread both move the exact figure.
    if ($iso > 120) {
        return "the cap barely bit: {$iso} isolation exercises still offered";
    }
    $allSquats = (int) (DB::one(
        'SELECT COUNT(*) AS n FROM exercises WHERE pattern = "squat" AND category = "strength"'
    )['n'] ?? 0);
    return $sq === $allSquats
        ?: "squats were capped too: {$sq} of {$allSquats}";
});

t('no muscle gets more than the cap', function () {
    $v = PlanSchema::vocabulary(null, ['full_gym'], null, ['strength']);
    $byMuscle = [];
    foreach ($v['strength']['isolation'] ?? [] as $slug) {
        $r = DB::one('SELECT primary_muscle FROM exercises WHERE slug = ?', [$slug]);
        $m = (string) ($r['primary_muscle'] ?? '?');
        $byMuscle[$m] = ($byMuscle[$m] ?? 0) + 1;
    }
    foreach ($byMuscle as $m => $n) {
        if ($n > PlanSchema::ISOLATION_PER_MUSCLE) {
            return "{$m} has {$n}, over the cap of " . PlanSchema::ISOLATION_PER_MUSCLE;
        }
    }
    return $byMuscle !== [] ?: 'no isolation work reached the vocabulary at all';
});

t('the cap runs AFTER the access filter, not before', function () {
    /*
     * Order matters and getting it backwards is invisible.
     *
     * If the cap counted first, a dumbbell-only user could have all six bicep slots taken by
     * cable curls and then see none of them — capped out, then filtered out, leaving nothing.
     * Filtering first means the six are six they can actually do.
     */
    $v = PlanSchema::vocabulary(
        null, ['home_gym'], ['dumbbell'], ['strength']
    );
    $iso = $v['strength']['isolation'] ?? [];
    if ($iso === []) {
        return 'a dumbbell owner was offered no isolation work at all';
    }
    // Everything offered must be performable with dumbbells.
    foreach ($iso as $slug) {
        $r = DB::one('SELECT equipment FROM exercises WHERE slug = ?', [$slug]);
        $eq = json_decode((string) $r['equipment'], true) ?: [];
        foreach ($eq as $token) {
            if (!in_array($token, ['dumbbell', 'incline_bench', 'box'], true)) {
                return "a dumbbell-only user was offered {$slug}, which needs {$token}";
            }
        }
    }
    return true;
});

t('the cap keeps the plain version of a movement', function () {
    /*
     * The cap takes the first N per muscle, so the ORDER decides which six survive. Plain
     * alphabetical gave "alternate-incline-dumbbell-curl" and three consecutive barbell shrugs,
     * because "b" wins. Shortest-name-first is a proxy for how basic a movement is:
     * "barbell-curl" is shorter than "barbell-curls-lying-against-an-incline" because it is the
     * plain version, which is what a coach reaches for and what a user recognises.
     */
    $v = PlanSchema::vocabulary(null, ['full_gym'], null, ['strength']);
    $iso = $v['strength']['isolation'] ?? [];

    // At least one recognisable basic per major muscle.
    foreach ([['curl', 'biceps'], ['shrug', 'traps'], ['calf', 'calves']] as [$word, $muscle]) {
        $found = false;
        foreach ($iso as $slug) {
            if (str_contains($slug, $word) && strlen($slug) < 20) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return "no short, recognisable {$word} survived the cap for {$muscle}";
        }
    }
    return true;
});

t('a merged duplicate still resolves by its old name', function () {
    /*
     * Six pairs of near-identical slugs existed after the import — our originals colliding with
     * the source's plurals, leg-extension against leg-extensions. The plural was ALIASED onto
     * the singular rather than deleted.
     *
     * A full prune was considered and rejected on the numbers: six pairs in 1008 exercises,
     * worth ~24 tokens of 3,340. The "49 bicep curls" that prompted the idea are variations —
     * incline, preacher, drag, spider — not duplicates, and the isolation cap had already
     * handled that bucket.
     *
     * Aliasing gets the same prompt benefit with none of the risk: an exercise is what a user
     * LOGS against, and deleting one breaks that log permanently the first time somebody types
     * the plural.
     */
    foreach (['hammer-curls' => 'hammer-curl',
              'leg-extensions' => 'leg-extension',
              'standing-calf-raises' => 'standing-calf-raise'] as $old => $canonical) {
        // Gone from the library, so it never renders twice in the vocabulary.
        $row = DB::one('SELECT slug FROM exercises WHERE slug = ?', [$old]);
        if ($row !== null) {
            return "{$old} is still a library row";
        }
        // But still resolvable, so a log or a plan naming it does not break.
        if (PlanSchema::resolveSlug($old) !== $canonical) {
            return "{$old} no longer resolves to {$canonical}";
        }
    }
    return true;
});

// ---------------------------------------------------------------------------
echo "\n6g. variety within the week, and a skill ceiling (§7.3)\n";

/** A week of $n sessions, each holding the given slugs. */
function weekOf(array $perDay): array
{
    $sessions = [];
    foreach ($perDay as $i => $slugs) {
        $ex = [];
        foreach ($slugs as $s) {
            $ex[] = ['slug' => $s, 'block' => 'main', 'sets' => 3, 'target_reps' => '10'];
        }
        $sessions[] = [
            'date' => date('Y-m-d', strtotime(nextMonday() . " +{$i} days")),
            'session_type' => 'strength', 'focus' => 'full', 'is_committed' => true,
            'exercises' => $ex,
        ];
    }
    return ['sessions' => $sessions];
}

t('the same movement four times in a week is rejected', function () {
    /*
     * The variety problem, correctly framed. Repetition ACROSS weeks is a programme — the same
     * session every Monday for two months is how progressive overload works, since you cannot
     * measure progress on a squat by squatting something else. Repetition WITHIN a week is the
     * flaw: six exposures in seven days does not leave enough recovery and crowds out
     * everything else.
     */
    $m = new ReflectionMethod(Safety::class, 'checkWeeklyFrequency');
    $m->setAccessible(true);
    $v = $m->invoke(null, weekOf([
        ['back-squat'], ['back-squat'], ['back-squat'], ['back-squat'],
    ]));
    foreach ($v as $violation) {
        if (str_contains($violation, 'appears 4 times')) {
            return true;
        }
    }
    return 'four exposures in a week were accepted: ' . implode(' | ', $v);
});

t('three times a week is fine', function () {
    // Twice is ordinary for a split; a third is defensible for a lagging lift.
    $m = new ReflectionMethod(Safety::class, 'checkWeeklyFrequency');
    $m->setAccessible(true);
    $v = $m->invoke(null, weekOf([['back-squat'], ['back-squat'], ['back-squat']]));
    return $v === [] ?: 'three exposures were rejected: ' . implode(' | ', $v);
});

t('the SAME movement across weeks is not the frequency check business', function () {
    /*
     * Asserted because the obvious reading of "variety" is cross-week rotation, and building
     * that would have fought progressive overload. The check only ever sees one week.
     */
    $m = new ReflectionMethod(Safety::class, 'checkWeeklyFrequency');
    $m->setAccessible(true);
    // A plan is one week, so two sessions is two sessions — there is no week-two to conflict.
    $v = $m->invoke(null, weekOf([['back-squat'], ['back-squat']]));
    return $v === [] ?: 'a twice-weekly squat was rejected';
});

t('an alias cannot smuggle a fourth appearance past the limit', function () {
    $alias = DB::one(
        'SELECT a.alias, e.slug FROM exercise_aliases a
         JOIN exercises e ON e.id = a.exercise_id LIMIT 1'
    );
    if ($alias === null) {
        return null;
    }
    $m = new ReflectionMethod(Safety::class, 'checkWeeklyFrequency');
    $m->setAccessible(true);
    $v = $m->invoke(null, weekOf([
        [(string) $alias['slug']], [(string) $alias['slug']],
        [(string) $alias['slug']], [(string) $alias['alias']],
    ]));
    return $v !== []
        ?: "'{$alias['alias']}' was counted as a different movement from '{$alias['slug']}'";
});

t('optional sessions count toward the limit', function () {
    // A bonus session still costs recovery, so it cannot be a way around the rule.
    $m = new ReflectionMethod(Safety::class, 'checkWeeklyFrequency');
    $m->setAccessible(true);
    $plan = weekOf([['back-squat'], ['back-squat'], ['back-squat']]);
    $plan['sessions'][] = [
        'date' => date('Y-m-d', strtotime(nextMonday() . ' +4 days')),
        'session_type' => 'strength', 'focus' => 'full',
        'is_committed' => false,   // optional
        'exercises' => [
            ['slug' => 'back-squat', 'block' => 'main', 'sets' => 3, 'target_reps' => '10'],
        ],
    ];
    return $m->invoke(null, $plan) !== []
        ?: 'an optional session was used to exceed the weekly limit';
});

t('a beginner is never offered an expert movement', function () {
    /*
     * The library has 44 expert-level exercises — atlas stones, renegade rows, clean and jerk.
     * The only guard was the model's judgement from stated experience, which works until it
     * does not, and the failure mode is somebody attempting one in week one.
     */
    $v = PlanSchema::vocabulary(
        null, ['full_gym'], null, ['strength'], PlanSchema::levelsUpTo('beginner')
    );
    $flat = [];
    foreach ($v as $pats) {
        foreach ($pats as $slugs) {
            foreach ($slugs as $s) {
                $flat[] = $s;
            }
        }
    }
    if ($flat === []) {
        return 'a beginner was offered no exercises at all';
    }
    foreach ($flat as $slug) {
        $r = DB::one('SELECT level FROM exercises WHERE slug = ?', [$slug]);
        if (($r['level'] ?? null) === 'expert') {
            return "a beginner was offered {$slug}, which is expert level";
        }
    }
    return true;
});

t('an advanced user still gets everything', function () {
    $beginner = PlanSchema::vocabulary(
        null, ['full_gym'], null, ['strength'], PlanSchema::levelsUpTo('beginner')
    );
    $advanced = PlanSchema::vocabulary(
        null, ['full_gym'], null, ['strength'], PlanSchema::levelsUpTo('advanced')
    );
    $count = static function (array $v): int {
        $n = 0;
        foreach ($v as $pats) {
            foreach ($pats as $s) {
                $n += count($s);
            }
        }
        return $n;
    };
    return $count($advanced) > $count($beginner)
        ?: 'an advanced user sees no more than a beginner';
});

t('a returning user gets intermediate, not expert', function () {
    /*
     * They have the movement patterns but not the current strength. §8's whole baseline
     * fortnight exists because what somebody did three years ago is not what they can do today.
     */
    $levels = PlanSchema::levelsUpTo('returning');
    if (!in_array('intermediate', $levels ?? [], true)) {
        return 'a returning user was capped below intermediate';
    }
    return !in_array('expert', $levels ?? [], true)
        ?: 'a returning user was offered expert movements';
});

t('an unstated experience applies no ceiling', function () {
    // Withholding exercises from somebody we never asked would be a worse error than a
    // slightly wider vocabulary, and the model still sees their profile.
    return PlanSchema::levelsUpTo(null) === null
        ?: 'an unstated experience was given a ceiling';
});

t('exercises with no level are never filtered out', function () {
    /*
     * NULL means the difficulty is unknown or does not apply — activities, cardio, and our own
     * 90 originals which predate the column. Excluding those would silently strip a beginner's
     * vocabulary of everything the app shipped with.
     */
    $v = PlanSchema::vocabulary(
        null, ['full_gym'], null, ['strength'], ['beginner']
    );
    $flat = [];
    foreach ($v as $pats) {
        foreach ($pats as $slugs) {
            foreach ($slugs as $s) {
                $flat[$s] = true;
            }
        }
    }
    // db-curl is one of ours and has no level.
    $orig = DB::one('SELECT slug FROM exercises WHERE level IS NULL AND category = "strength" LIMIT 1');
    if ($orig === null) {
        return null;
    }
    return isset($flat[(string) $orig['slug']])
        ?: "{$orig['slug']} has no level and was filtered out of a beginner's vocabulary";
});

t('the prompt states both rules', function () use ($ids) {
    $u = $ids['u1'];
    $gather = new ReflectionMethod(Plans::class, 'gatherContext');
    $gather->setAccessible(true);
    $ctx = $gather->invoke(null, $u, date('Y-m-d', strtotime('next monday')));

    $sys = new ReflectionMethod(Plans::class, 'systemPrompt');
    $sys->setAccessible(true);
    $text = (string) $sys->invoke(null, $ctx);

    if (!str_contains($text, 'NO MOVEMENT MORE THAN THREE TIMES IN THE WEEK')) {
        return 'the frequency rule is not in the prompt';
    }
    if (!str_contains($text, 'KEEP THE MAIN LIFTS, ROTATE THE ACCESSORIES')) {
        return 'the rotation rule is not in the prompt';
    }
    // And it says WHY the compounds stay, or the model reads it as arbitrary.
    return str_contains($text, 'progressed and measured')
        ?: 'the prompt does not explain why the main lifts stay put';
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
