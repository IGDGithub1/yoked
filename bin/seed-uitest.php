<?php
declare(strict_types=1);

/**
 * Seed a persistent user with a plan on TODAY, for driving the UI in a browser.
 *
 * bin/test-logging.php seeds a near-identical fixture, but deliberately pins it
 * to the Monday of the plan week so its assertions are deterministic, and drops
 * the user at the end. Neither suits a browser: the logging screen opens on
 * today, and a user that vanishes cannot be clicked through twice.
 *
 * Re-runnable. Deletes and recreates the same username, so the plan follows
 * today's date rather than going stale overnight.
 *
 *   php bin/seed-uitest.php              # seed / re-seed
 *   php bin/seed-uitest.php --drop       # remove it
 */

require __DIR__ . '/../src/bootstrap_cli.php';

const UI_USER = 'uitest_logging';
const UI_PASS = 'a-long-enough-passphrase';

/**
 * A second fixture, mid-baseline, so the observation UI can be driven too.
 *
 * The main fixture is 'active' with a live plan, which is the right shape for
 * logging but shows none of the baseline countdown. This one sits on day 3 of 14
 * with no plan at all, which is what a real user's first week actually looks like.
 */
const UI_BASE_USER = 'uitest_baseline';

$drop = in_array('--drop', array_slice($argv, 1), true);

// ON DELETE CASCADE from users carries the plan, the logged days and the rest.
DB::run('DELETE FROM users WHERE username IN (?, ?)', [UI_USER, UI_BASE_USER]);
// Login is rate limited per identifier, and a re-seed mid-testing would
// otherwise inherit the previous run's failed attempts.
foreach ([UI_USER, UI_BASE_USER] as $u) {
    DB::run('DELETE FROM rate_limits WHERE bucket LIKE ?', ['login:%' . $u . '%']);
}

if ($drop) {
    echo 'removed ' . UI_USER . ' and ' . UI_BASE_USER . "\n";
    exit(0);
}

$today = date('Y-m-d');
// The plan week has to CONTAIN today, or Nutrition::dayId stamps the day with a
// null plan_version_id and nothing is prescribed on screen.
$monday = date('Y-m-d', strtotime('monday this week'));

$seed = DB::tx(function () use ($today, $monday): array {
    $userId = DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
         VALUES (?, ?, ?, ?, "active")',
        [UI_USER, 'UI Test', UI_USER . '@example.test',
         password_hash(UI_PASS, PASSWORD_DEFAULT)]
    );
    DB::run('INSERT INTO profiles (user_id) VALUES (?)', [$userId]);

    $preset = DB::one("SELECT id FROM goal_presets WHERE slug = 'recomp'");
    DB::run(
        'INSERT INTO goals (user_id, primary_goal, goal_preset_id, success_statement, status)
         VALUES (?, ?, ?, ?, "active")',
        [$userId, 'recomp', $preset === null ? null : (int) $preset['id'],
         'Seeded for UI testing.']
    );

    $planId = DB::insert(
        'INSERT INTO plan_versions (user_id, week_start, version, reason, summary)
         VALUES (?, ?, 1, "initial", ?)',
        [$userId, $monday, 'Seeded for UI testing.']
    );

    // Constraints are frozen onto the prescribed day, so the verdict the UI
    // shows comes from the plan rather than the user's current goal.
    $dayId = DB::insert(
        'INSERT INTO prescribed_days
            (plan_version_id, day_date, target_calories, target_protein_g,
             target_fat_g, target_carbs_g, constraints)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$planId, $today, 2400, 180.0, 80.0, 220.0, json_encode([
            'protein'  => ['mode' => 'at_least'],
            'calories' => ['mode' => 'range_pct', 'lo' => 0.85, 'hi' => 1.05],
            'fat'      => ['mode' => 'ignore'],
            'carbs'    => ['mode' => 'ignore'],
        ])]
    );

    foreach ([
        ['breakfast', 'Eggs and oats',      600, 45.0, 22.0, 50.0, 6.0],
        ['lunch',     'Chicken and rice',   800, 70.0, 22.0, 70.0, 4.0],
        ['dinner',    'Beef and potatoes', 1000, 65.0, 36.0, 100.0, 8.0],
    ] as [$s, $n, $cal, $pro, $fat, $carb, $fib]) {
        DB::run(
            'INSERT INTO prescribed_meals
                (prescribed_day_id, slot, kind, name, calories, protein_g, fat_g,
                 carbs_g, fiber_g, prep_minutes, ingredients)
             VALUES (?, ?, "specified", ?, ?, ?, ?, ?, ?, ?, ?)',
            [$dayId, $s, $n, $cal, $pro, $fat, $carb, $fib, 15,
             json_encode([['item' => 'test food', 'household' => '1 cup']])]
        );
    }

    // One committed session and one optional, which is the pair that shows the
    // adherence asymmetry on screen (§3.3a).
    //
    // `focus` is an ENUM('upper','lower','full','push','pull','core',
    // 'conditioning','none') — an invalid member is silently coerced to '' by
    // MySQL rather than rejected, which reads on screen as a session with no
    // name at all. Use the enum values, not prose.
    $committed = DB::insert(
        'INSERT INTO prescribed_sessions
            (plan_version_id, session_date, session_type, focus, focus_detail,
             is_committed, target_minutes, location, warmup_minutes,
             warmup_detail, rationale)
         VALUES (?, ?, "strength", "full", ?, 1, 55, "full_gym", 10, ?, ?)',
        [$planId, $today, 'Compound lifts, moderate volume.',
         'Bike five minutes, then ramp.',
         'Week one is about establishing load, not chasing it.']
    );
    DB::run(
        'INSERT INTO prescribed_sessions
            (plan_version_id, session_date, session_type, focus, is_committed,
             target_minutes, location)
         VALUES (?, ?, "cardio", "conditioning", 0, 30, "outdoors")',
        [$planId, $today]
    );

    foreach ([['leg-press', 'main'], ['plank', 'core']] as [$slug, $block]) {
        $ex = DB::one('SELECT id FROM exercises WHERE slug = ?', [$slug]);
        if ($ex === null) {
            continue;
        }
        DB::run(
            'INSERT INTO prescribed_exercises
                (session_id, exercise_id, block, sets, target_reps,
                 target_weight_kg, target_rpe, rest_seconds)
             VALUES (?, ?, ?, 3, "10", 60, 7, 90)',
            [$committed, (int) $ex['id'], $block]
        );
    }

    return ['user_id' => $userId, 'plan_id' => $planId];
});

printf(
    "seeded %s / %s — user #%d, plan #%d, prescribed for %s\n",
    UI_USER, UI_PASS, $seed['user_id'], $seed['plan_id'], $today
);

// ---- the baseline fixture --------------------------------------------------

// Day 3 of 14: far enough in that the countdown has something to show, still
// inside week 1 where nothing is prescribed.
$blStart = date('Y-m-d', strtotime($today . ' -2 days'));
$blEnd   = date('Y-m-d', strtotime($blStart . ' +14 days'));

$blUser = DB::tx(function () use ($blStart, $blEnd): int {
    $userId = DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash,
                            onboarding_state, baseline_starts_on, baseline_ends_on)
         VALUES (?, ?, ?, ?, "baseline", ?, ?)',
        [UI_BASE_USER, 'Baseline Test', UI_BASE_USER . '@example.test',
         password_hash(UI_PASS, PASSWORD_DEFAULT), $blStart, $blEnd]
    );
    // A timezone, so the schedule reads local rather than falling back to UTC.
    DB::run(
        'INSERT INTO profiles (user_id, timezone) VALUES (?, "America/New_York")',
        [$userId]
    );
    $preset = DB::one("SELECT id FROM goal_presets WHERE slug = 'recomp'");
    DB::run(
        'INSERT INTO goals (user_id, primary_goal, goal_preset_id, success_statement, status)
         VALUES (?, "recomp", ?, "Seeded for UI testing.", "active")',
        [$userId, $preset === null ? null : (int) $preset['id']]
    );
    // Deliberately NO plan_versions row: week 1 is pure observation (§9), and the
    // absence is the thing being tested.
    return $userId;
});

printf(
    "seeded %s / %s — user #%d, baseline %s to %s (day 3 of 14, no plan)\n",
    UI_BASE_USER, UI_PASS, $blUser, $blStart, $blEnd
);
