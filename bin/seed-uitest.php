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

/**
 * A third fixture, for the Next Day Review (§4.1a).
 *
 * Its own user because the review's whole behaviour is time-gated, and the two fixtures
 * above must NOT be: the main one has review_hour 0 so a dozen unrelated assertions do
 * not pass or fail depending on the clock, and the baseline one deliberately has no plan
 * at all, so a review would find nothing to show.
 *
 * This one carries a real evening review_hour, in a timezone CHOSEN at seed time to be
 * inside the window, plus a plan covering tomorrow and a genuinely prep-heavy dinner. See
 * the fixture below for why the zone is picked rather than fixed.
 */
const UI_REVIEW_USER = 'uitest_review';

$drop = in_array('--drop', array_slice($argv, 1), true);

$allUsers = [UI_USER, UI_BASE_USER, UI_REVIEW_USER];

// ON DELETE CASCADE from users carries the plan, the logged days and the rest.
DB::run(
    'DELETE FROM users WHERE username IN (?, ?, ?)',
    $allUsers
);
/*
 * Clear the login throttles this fixture will trip.
 *
 * Two buckets, and only the first was being cleared. Per-IDENTIFIER is the obvious one: a
 * re-seed mid-testing would otherwise inherit the previous run's attempts.
 *
 * Per-IP is the one that actually broke a run. The browser suite signs in seven separate
 * times — once per fixture per section — against a ceiling of 20 per IP per 15 minutes, so
 * two consecutive runs plus a couple of hand probes exhausts it and every block from that
 * point on fails at the sign-in screen. Thirty cascading failures that all look like real
 * bugs, and the cause is the app defending itself correctly.
 *
 * Cleared by AGE rather than by address, because this script runs over SSH on the server and
 * the browser runs on someone's laptop: RateLimit::ip() here returns 0.0.0.0, not the address
 * that will actually be doing the signing in. So there is no way to name the right bucket.
 *
 * An EXPIRED window is safe to delete for anyone: RateLimit treats a window older than its
 * period as spent and starts a new one, so removing those rows changes no decision. It only
 * stops a stale row from being counted against the next run.
 */
foreach ($allUsers as $u) {
    DB::run('DELETE FROM rate_limits WHERE bucket LIKE ?', ['login:%' . $u . '%']);
}
DB::run(
    'DELETE FROM rate_limits
     WHERE bucket LIKE "login:ip:%" AND window_start < (NOW() - INTERVAL 15 MINUTE)'
);

if ($drop) {
    echo 'removed ' . implode(', ', $allUsers) . "\n";
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

// $today is not captured: the three-day $prescribe window replaced every use of it
// inside here, and an unused capture is a claim about what this closure needs.
$seed = DB::tx(function () use ($prescribe, $monday): array {
    $userId = DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
         VALUES (?, ?, ?, ?, "active")',
        [UI_USER, 'UI Test', UI_USER . '@example.test',
         password_hash(UI_PASS, PASSWORD_DEFAULT)]
    );
    /*
     * review_hour 0 means "off", and that is deliberate for the MAIN fixture.
     *
     * The Next Day Review only appears after the user's evening hour, and the browser
     * suite runs at whatever time of day it runs. Leaving it on would make a dozen
     * unrelated assertions pass or fail depending on the clock. UI_REVIEW_USER carries a
     * real evening hour instead, in a zone chosen to be inside the window.
     *
     * coaching_paused = 1 on every UI fixture, and not because these users should be
     * ignored. The browser suite RAISES things cron would otherwise act on for real: a veto
     * left behind by a test run gets swept up and spends a full plan generation, which is
     * minutes of model time and real money for an assertion that already passed. Every cron
     * job checks this flag, so pausing makes the fixtures inert to the scheduler while
     * leaving every request path untouched. The suites that DO exercise cron seed their own
     * users (test-vetoes.php, test-chat.php) and delete them afterwards.
     */
    DB::run(
        'INSERT INTO profiles (user_id, review_hour, coaching_paused) VALUES (?, 0, 1)',
        [$userId]
    );

    $preset = DB::one("SELECT id FROM goal_presets WHERE slug = 'recomp'");
    DB::run(
        'INSERT INTO goals (user_id, primary_goal, goal_preset_id, success_statement, status)
         VALUES (?, ?, ?, ?, "active")',
        [$userId, 'recomp', $preset === null ? null : (int) $preset['id'],
         'Seeded for UI testing.']
    );

    /*
     * One constraint of each tier, so the profile's preferences list has both cases.
     *
     * The soft one is source veto_promotion deliberately: that is the row the Veto form
     * promises can be switched back on, so it is the one the browser suite should be able to
     * click. The hard one must render with NO switch, which is the assertion that matters.
     */
    DB::run(
        'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
         VALUES (?, "food", "hard", "peanuts", "anaphylaxis", "onboarding")',
        [$userId]
    );
    DB::run(
        'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
         VALUES (?, "food", "soft", "salmon", "turned it down", "veto_promotion")',
        [$userId]
    );
    /*
     * A condition and a dietary pattern, because those are the two that read badly and the
     * first fixture had neither.
     *
     * Subjects here are the real stored shapes, not tidied ones: `diabetes_t2` and
     * `dietary_pattern:vegetarian` are exactly what onboarding writes, and the whole point
     * is that the screen must not show them that way. Seeding a pre-tidied subject would
     * make the fixture pass a test the real data would fail.
     */
    DB::run(
        'INSERT INTO user_constraints
            (user_id, kind, tier, subject, reason, guidance, source)
         VALUES (?, "condition", "hard", "diabetes_t2", "Reported at onboarding", ?,
                 "onboarding")',
        [$userId, 'Carb distribution and meal timing matter. Avoid large isolated carb '
                . 'loads; spread carbs across meals. NOT a carb ban.']
    );
    DB::run(
        'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
         VALUES (?, "food", "hard", "dietary_pattern:vegetarian",
                 "Dietary pattern reported at onboarding", "onboarding")',
        [$userId]
    );
    DB::run(
        'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
         VALUES (?, "cardio", "soft", "stair-machine", "Refused at onboarding", "onboarding")',
        [$userId]
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
        'INSERT INTO profiles (user_id, timezone, coaching_paused)
         VALUES (?, "America/New_York", 1)',
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

// ---- the Next Day Review fixture -------------------------------------------

/*
 * A user the review reliably shows for.
 *
 * THE TIMEZONE IS CHOSEN, NOT FIXED, and that is the whole trick.
 *
 * The review only appears at or after the user's local review_hour. This used to hardcode
 * America/New_York with review_hour 1, on the reasoning that hour 1 is "past for 23 hours
 * out of 24" — which is true and still broke the suite, because the one hour it is NOT past
 * is local midnight to 1am, and a run at 00:07 New York time hit exactly that. Five
 * assertions failed against a correctly working feature.
 *
 * So instead: keep review_hour at a real evening value and pick a zone where it is
 * currently evening. At any instant such a zone exists, because the world spans every hour
 * at once. Same device the suite itself uses to test the evening window without mocking a
 * clock.
 */
const UI_REVIEW_HOUR = 20;

/*
 * Candidates spanning the whole day, so one of them is always in the window. Ordered west
 * to east; the first match wins.
 */
$revTz = null;
foreach ([
    'Pacific/Honolulu', 'America/Anchorage', 'America/Los_Angeles', 'America/Denver',
    'America/Chicago', 'America/New_York', 'America/Halifax', 'America/Sao_Paulo',
    'Atlantic/Azores', 'UTC', 'Europe/Berlin', 'Europe/Moscow', 'Asia/Dubai',
    'Asia/Karachi', 'Asia/Kolkata', 'Asia/Bangkok', 'Asia/Shanghai', 'Asia/Tokyo',
    'Australia/Brisbane', 'Australia/Sydney', 'Pacific/Auckland',
] as $candidate) {
    $h = (int) Schedule::now($candidate)->format('G');
    // At or after the hour, and not so late that "tomorrow" rolls over mid-run.
    if ($h >= UI_REVIEW_HOUR && $h <= 22) {
        $revTz = $candidate;
        break;
    }
}
if ($revTz === null) {
    // Should be unreachable: the candidate list covers every UTC offset in use. Loud rather
    // than silently seeding a fixture the suite will then fail against.
    fwrite(STDERR, "seed-uitest: no timezone is currently in the review window; "
        . "the candidate list has a gap.\n");
    exit(1);
}

$revTom = Schedule::now($revTz)->modify('+1 day')->format('Y-m-d');

$revUser = DB::tx(function () use ($revTz, $revTom): int {
    $userId = DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
         VALUES (?, ?, ?, ?, "active")',
        [UI_REVIEW_USER, 'Review Test', UI_REVIEW_USER . '@example.test',
         password_hash(UI_PASS, PASSWORD_DEFAULT)]
    );
    DB::run(
        'INSERT INTO profiles (user_id, timezone, review_hour, coaching_paused)
         VALUES (?, ?, ?, 1)',
        [$userId, $revTz, UI_REVIEW_HOUR]
    );
    $preset = DB::one("SELECT id FROM goal_presets WHERE slug = 'recomp'");
    DB::run(
        'INSERT INTO goals (user_id, primary_goal, goal_preset_id, success_statement, status)
         VALUES (?, "recomp", ?, "Seeded for UI testing.", "active")',
        [$userId, $preset === null ? null : (int) $preset['id']]
    );

    $planId = DB::insert(
        'INSERT INTO plan_versions (user_id, week_start, version, reason, summary)
         VALUES (?, ?, 1, "initial", "Seeded for the Next Day Review.")',
        [$userId, Schedule::weekStart($revTz)]
    );
    $dayId = DB::insert(
        'INSERT INTO prescribed_days
            (plan_version_id, day_date, target_calories, target_protein_g,
             target_fat_g, target_carbs_g)
         VALUES (?, ?, 2400, 180, 80, 220)',
        [$planId, $revTom]
    );

    // 5 minutes: below the threshold, must NOT be flagged.
    DB::run(
        'INSERT INTO prescribed_meals
            (prescribed_day_id, slot, kind, name, calories, protein_g, fat_g, carbs_g,
             prep_minutes)
         VALUES (?, "breakfast", "specified", "Overnight oats", 500, 30, 15, 60, 5)',
        [$dayId]
    );
    // 45 minutes: the §4.1a case, "tomorrow's dinner needs 40 minutes".
    DB::run(
        'INSERT INTO prescribed_meals
            (prescribed_day_id, slot, kind, name, calories, protein_g, fat_g, carbs_g,
             prep_minutes, method)
         VALUES (?, "dinner", "specified", "Slow-braised beef", 900, 60, 40, 50, 45, ?)',
        [$dayId, 'Brown the beef, then braise for forty minutes.']
    );

    $sid = DB::insert(
        'INSERT INTO prescribed_sessions
            (plan_version_id, session_date, session_type, focus, is_committed,
             target_minutes, location, rationale)
         VALUES (?, ?, "strength", "push", 1, 50, "full_gym", ?)',
        [$planId, $revTom, 'Heavy day, so eat before it.']
    );
    $ex = DB::one("SELECT id FROM exercises WHERE slug = 'leg-press'");
    if ($ex !== null) {
        DB::run(
            'INSERT INTO prescribed_exercises
                (session_id, exercise_id, block, sets, target_reps)
             VALUES (?, ?, "main", 3, "10")',
            [$sid, (int) $ex['id']]
        );
    }
    return $userId;
});

// Prints the chosen zone and its local time, so a suite failure here can be told apart
// from a fixture that was seeded outside the window.
printf(
    "seeded %s / %s — user #%d, review_hour %d in %s (local %s), tomorrow %s (one prep-heavy meal)\n",
    UI_REVIEW_USER, UI_PASS, $revUser, UI_REVIEW_HOUR, $revTz,
    Schedule::now($revTz)->format('H:i'), $revTom
);

// ---- the social graph -------------------------------------------------------

/*
 * One accepted friendship and one request waiting, using the fixtures that already exist.
 *
 * Both states are needed and neither can be produced by the suite alone: accepting requires
 * signing in as the OTHER person, and the browser suite drives one session at a time. So the
 * fixture supplies what the UI has to render, and the suite drives what a single user can
 * actually do — search, ask, accept, remove.
 *
 * uitest_review asks uitest_logging, so the main fixture opens with a badge of 1.
 * uitest_baseline is already a friend, so the list is not empty either.
 */
DB::run(
    'INSERT INTO friendships (user_lo, user_hi, requester_id, status, responded_at)
     VALUES (?, ?, ?, "accepted", NOW())',
    [min($seed['user_id'], $blUser), max($seed['user_id'], $blUser), $blUser]
);
DB::run(
    'INSERT INTO friendships (user_lo, user_hi, requester_id, status)
     VALUES (?, ?, ?, "pending")',
    [min($seed['user_id'], $revUser), max($seed['user_id'], $revUser), $revUser]
);

printf(
    "seeded the social graph — %s is friends with %s and has a request from %s\n",
    UI_USER, UI_BASE_USER, UI_REVIEW_USER
);

/*
 * Availability grids for the two friends, so the buddy intersection has something to compute.
 *
 * Deliberately only PARTIALLY overlapping: uitest_logging trains Mon/Wed/Fri/Sat and
 * uitest_baseline Wed/Fri/Sun, so the shared list is Wednesday and Friday. A full overlap
 * would pass a test that a broken intersection would also pass.
 *
 * Friday minutes differ (90 against 45), so the "shorter of the two" rule has a case where
 * the two figures actually disagree.
 */
$grids = [
    $seed['user_id'] => [1 => 60, 3 => 60, 5 => 90, 6 => 120],
    $blUser          => [3 => 45, 5 => 45, 7 => 60],
];
foreach ($grids as $gridUser => $days) {
    for ($d = 1; $d <= 7; $d++) {
        DB::run(
            'INSERT INTO availability (user_id, weekday, can_train, minutes, access,
                                       preferred_time)
             VALUES (?, ?, ?, ?, "full_gym", "early_morning")
             ON DUPLICATE KEY UPDATE can_train = VALUES(can_train), minutes = VALUES(minutes)',
            [$gridUser, $d, isset($days[$d]) ? 'yes' : 'no', $days[$d] ?? null]
        );
    }
}

/*
 * A pending buddy invitation from the friend, so the accept path is drivable in one session.
 *
 * The browser suite signs in as one user at a time, so it cannot both send and accept. Same
 * reason the friend request above is seeded rather than created by the suite.
 */
DB::run(
    'INSERT INTO buddy_pairs (user_lo, user_hi, status, requested_by)
     VALUES (?, ?, "pending", ?)',
    [min($seed['user_id'], $blUser), max($seed['user_id'], $blUser), $blUser]
);

printf(
    "seeded a buddy invite from %s (they share Wednesday and Friday)\n",
    UI_BASE_USER
);
