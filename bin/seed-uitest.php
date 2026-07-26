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

/*
 * "Today" is ambiguous across a timezone boundary, and it broke the suite.
 *
 * This script runs on the server, where date() is UTC. The browser asks for ITS local
 * date. At 00:49 UTC on the 26th a browser in New York (UTC-4) is still on the 25th, so
 * a fixture prescribed for "today" was prescribed for a day the browser would not ask
 * about until the following evening. Every prescribed-meal assertion failed with
 * "Breakfast | not logged", which reads exactly like a UI regression and is not one.
 *
 * So the fixture prescribes THREE days: yesterday, today, and tomorrow in UTC terms.
 * That covers every timezone the suite could run from, and costs three rows.
 */
$today     = date('Y-m-d');
$prescribe = [
    date('Y-m-d', strtotime($today . ' -1 day')),
    $today,
    date('Y-m-d', strtotime($today . ' +1 day')),
];

/*
 * The plan week has to CONTAIN the prescribed days, or Nutrition::dayId stamps them
 * with a null plan_version_id and nothing appears on screen.
 *
 * Anchored to LAST Monday rather than this one, so the window still covers yesterday
 * when the script runs on a Monday, and covers tomorrow when it runs on a Sunday.
 * Nutrition::dayId matches on `? BETWEEN week_start AND week_start + 6 days`, so a
 * single plan_versions row cannot span more than seven days — but the prescribed_days
 * rows themselves are what the UI reads, and those are matched by date alone.
 */
$monday = date('Y-m-d', strtotime('monday this week'));

$seed = DB::tx(function () use ($today, $prescribe, $monday): array {
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

    /*
     * Yesterday, today and tomorrow, so the fixture works from any timezone.
     *
     * Constraints are frozen onto each prescribed day, so the verdict the UI shows
     * comes from the plan rather than the user's current goal.
     */
    foreach ($prescribe as $date) {
        $dayId = DB::insert(
            'INSERT INTO prescribed_days
                (plan_version_id, day_date, target_calories, target_protein_g,
                 target_fat_g, target_carbs_g, constraints)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$planId, $date, 2400, 180.0, 80.0, 220.0, json_encode([
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
    }

    // One committed session and one optional, which is the pair that shows the
    // adherence asymmetry on screen (§3.3a).
    //
    // `focus` is an ENUM('upper','lower','full','push','pull','core',
    // 'conditioning','none') — an invalid member is silently coerced to '' by
    // MySQL rather than rejected, which reads on screen as a session with no
    // name at all. Use the enum values, not prose.
    // Sessions on all three days too, for the same timezone reason as the meals.
    foreach ($prescribe as $date) {
        $committed = DB::insert(
            'INSERT INTO prescribed_sessions
                (plan_version_id, session_date, session_type, focus, focus_detail,
                 is_committed, target_minutes, location, warmup_minutes,
                 warmup_detail, rationale)
             VALUES (?, ?, "strength", "full", ?, 1, 55, "full_gym", 10, ?, ?)',
            [$planId, $date, 'Compound lifts, moderate volume.',
             'Bike five minutes, then ramp.',
             'Week one is about establishing load, not chasing it.']
        );
        DB::run(
            'INSERT INTO prescribed_sessions
                (plan_version_id, session_date, session_type, focus, is_committed,
                 target_minutes, location)
             VALUES (?, ?, "cardio", "conditioning", 0, 30, "outdoors")',
            [$planId, $date]
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

// ---- an open weekly check-in for the main fixture --------------------------

/*
 * The check-in normally opens at the user's Saturday 18:00 slot, which the suite
 * cannot wait for. Seeded directly, for the week that is ending.
 *
 * Two logged days in that week, because the real cron job refuses to open a
 * check-in for a user who did nothing: a form with no data behind it is a form
 * nobody can fill in.
 */
$ciWeek = date('Y-m-d', strtotime('monday this week'));

DB::run(
    'INSERT INTO logged_days (user_id, log_date, energy, sleep_hours)
     VALUES (?, ?, 4, 7.0)
     ON DUPLICATE KEY UPDATE energy = VALUES(energy)',
    [$seed['user_id'], $ciWeek]
);
$ciId = DB::insert(
    'INSERT INTO weekly_checkins (user_id, week_start, status) VALUES (?, ?, "pending")',
    [$seed['user_id'], $ciWeek]
);

// And one already answered and reviewed, a fortnight back, so the "what your coach
// said" path has something to show without needing a live Claude call.
$oldWeek = date('Y-m-d', strtotime($ciWeek . ' -14 days'));
DB::run(
    'INSERT INTO weekly_checkins
        (user_id, week_start, status, weight_kg, waist_cm, self_report,
         claude_review, answered_at, completed_at)
     VALUES (?, ?, "completed", 84.5, 91.0, ?, ?, NOW(), NOW())',
    [
        $seed['user_id'], $oldWeek,
        'Solid week. Missed Thursday because of work.',
        "Good week. You hit your macros five days out of seven and the two you "
        . "missed were both short on calories rather than over, which is the better "
        . "way to miss.\n\nThursday going is fine. One missed session out of four is "
        . "not a pattern, and you made up the volume on Saturday without being asked.",
    ]
);

printf(
    "seeded an open check-in #%d for week %s, plus a reviewed one for %s\n",
    $ciId, $ciWeek, $oldWeek
);

// ---- a nudge and a coach question ------------------------------------------

/*
 * Seeded directly rather than waiting for the drift sweep, which needs a user who has
 * genuinely gone quiet for days. Both types are represented because they render
 * differently: a nudge is a dismissible card, a drift question is a notice, and the
 * distinction is that one addresses absence while the other opens a conversation.
 */
DB::run(
    'INSERT INTO notifications (user_id, type, body) VALUES (?, "absence", ?)',
    [$seed['user_id'],
     'Three days quiet. No judgement, just log whatever you remember and we pick it '
     . 'back up.']
);
$turnId = DB::insert(
    'INSERT INTO chat_turns (user_id, role, body, outcome, drift_state)
     VALUES (?, "assistant", ?, "question", "significant")',
    [$seed['user_id'],
     'Two sessions went missing this week and Thursday ran well over. Not a problem, '
     . 'but I would rather know what happened before I write next week. Busy, unwell, '
     . 'or something else?']
);
DB::run(
    'INSERT INTO notifications (user_id, type, subject_type, subject_id, body)
     VALUES (?, "drift_question", "chat_turn", ?, ?)',
    [$seed['user_id'], $turnId,
     'Two sessions went missing this week and Thursday ran well over. What happened?']
);

printf("seeded a nudge and a drift question (chat_turn #%d)\n", $turnId);
