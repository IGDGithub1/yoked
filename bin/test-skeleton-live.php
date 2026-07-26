<?php
declare(strict_types=1);

/**
 * Does a real pair actually converge? (SPEC-coaching §10.6)
 *
 * The structural tests in bin/test-buddy-schedule.php prove the skeleton is read, rendered into
 * the prompt, and stamped on both sides. None of that proves the MODEL honours it, and that is
 * the only thing that matters to a pair standing in a gym.
 *
 * So this generates two real weeks: user A leads, user B follows A's written plan, and the two
 * are compared on the shared days. Opt-in and separate from the routine suites because it costs
 * two full generations (~22k-31k output tokens each, several minutes).
 *
 * The pair is deliberately MISMATCHED — a beginner at home with dumbbells against an
 * experienced lifter with a full gym — because a well-matched pair would converge by accident
 * and prove nothing. §10.2's claim is that the shape survives a mismatch while the prescriptions
 * diverge, which is only testable when there is a real gap to bridge.
 *
 *   php bin/test-skeleton-live.php          seed and report, generate nothing
 *   php bin/test-skeleton-live.php --live   two real generations (costs money, minutes)
 *   php bin/test-skeleton-live.php --keep   leave the fixtures behind for inspection
 */

require __DIR__ . '/../src/bootstrap_cli.php';
require YK_SRC . '/lib/Response.php';
require YK_SRC . '/lib/RateLimit.php';
require YK_SRC . '/lib/Goals.php';
require YK_SRC . '/lib/Claude.php';
require YK_SRC . '/lib/PlanSchema.php';
require YK_SRC . '/lib/Notify.php';
require YK_SRC . '/lib/Friends.php';
require YK_SRC . '/lib/BuddySchedule.php';
require YK_SRC . '/lib/Safety.php';
require YK_SRC . '/lib/Buddies.php';
require YK_SRC . '/lib/ConstraintLabel.php';
require YK_SRC . '/lib/Settings.php';
require YK_SRC . '/lib/Drift.php';
require YK_SRC . '/lib/BuddyAbsence.php';
require YK_SRC . '/lib/BuddySkeleton.php';
require YK_SRC . '/lib/Plans.php';

$args = array_slice($argv, 1);
$live = in_array('--live', $args, true);
$keep = in_array('--keep', $args, true);

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

DB::run("DELETE FROM users WHERE username LIKE 'skel_%'");

/**
 * A user with a full profile, goal and grid.
 *
 * Enough to generate against: gatherContext refuses without a goal, and the validator refuses
 * without availability.
 */
function seed(string $handle, array $opts): int
{
    $userId = (int) DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
         VALUES (?, ?, ?, "x", "active")',
        ['skel_' . $handle, $opts['name'], "skel_{$handle}@example.test"]
    );

    /*
     * Experience level goes in trainer_notes, not a column: there is no training_age field, and
     * §2 treats "how much have you done before" as prose because the useful version of that
     * answer is never an enum.
     */
    DB::run(
        'INSERT INTO profiles
         (user_id, timezone, committed_days_per_week, date_of_birth, birth_sex, height_cm,
          units, tone, nudge_intensity, explanation_depth, core_emphasis,
          baseline_sleep_hours, baseline_sleep_quality, baseline_activity,
          baseline_stress, baseline_energy, coaching_paused, trainer_notes)
         VALUES (?, "UTC", ?, ?, ?, ?, "metric", "direct_no_fluff", "gentle",
                 "brief", "standard", 7.0, "good", "moderate", "moderate", "ok", 0, ?)',
        [$userId, $opts['committed'], $opts['dob'], $opts['birth_sex'],
         $opts['height_cm'], $opts['notes']]
    );

    DB::run(
        'INSERT INTO goals (user_id, primary_goal, success_statement, requested_timeline,
                            horizon_weeks, scale_vs_feel, status)
         VALUES (?, ?, ?, "16_weeks", 16, "both", "active")',
        [$userId, $opts['goal'], $opts['success']]
    );

    for ($d = 1; $d <= 7; $d++) {
        $day = $opts['days'][$d] ?? null;
        DB::run(
            'INSERT INTO availability (user_id, weekday, can_train, minutes, access)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $d, $day === null ? 'no' : 'yes',
             $day[0] ?? null, $day[1] ?? null]
        );
    }

    /*
     * A recent weight, so the plan is not built against a blank body.
     *
     * Reviewed, not open: an unanswered check-in is a pending question, and seeding one would
     * have the coach chasing a reply during a test about something else.
     */
    DB::run(
        'INSERT INTO weekly_checkins (user_id, week_start, weight_kg, status, completed_at)
         VALUES (?, ?, ?, "completed", NOW())',
        [$userId, date('Y-m-d', strtotime('monday last week')), $opts['weight_kg']]
    );

    return $userId;
}

$week = date('Y-m-d', strtotime('next monday'));

/*
 * The mismatch is the point.
 *
 * A trains five days with a full gym and eight years behind him. B trains three days at home
 * with dumbbells and has never followed a program. They share Mon/Wed/Fri.
 */
$a = seed('lead', [
    'name' => 'Skel Lead', 'committed' => 5,
    'birth_sex' => 'male', 'dob' => date('Y-m-d', strtotime('-36 years')),
    'height_cm' => 183.0, 'weight_kg' => 88.0,
    'goal' => 'build_muscle',
    'success' => 'Pull 500 for a single and still see my abs.',
    'notes' => 'Eight years of consistent barbell training. Knows the lifts, trains hard, '
             . 'wants volume. Nothing to work around.',
    'days' => [
        1 => [75, 'full_gym'], 2 => [75, 'full_gym'], 3 => [75, 'full_gym'],
        5 => [75, 'full_gym'], 6 => [60, 'full_gym'],
    ],
]);

$b = seed('follow', [
    'name' => 'Skel Follow', 'committed' => 3,
    'birth_sex' => 'female', 'dob' => date('Y-m-d', strtotime('-48 years')),
    'height_cm' => 163.0, 'weight_kg' => 74.0,
    'goal' => 'lose_fat',
    'success' => 'Get up the stairs at work without stopping, and lose two dress sizes.',
    'notes' => 'Has never followed a training program. A pair of adjustable dumbbells and a '
             . 'mat at home. Needs to be taught the movements, not pushed.',
    'days' => [1 => [45, 'home_gym'], 3 => [45, 'home_gym'], 5 => [45, 'home_gym']],
]);

Friends::request($a, $b);
Friends::respond($b, $a, true);
Buddies::invite($a, $b);
Buddies::respond($b, true);
$pair   = BuddySchedule::activePair($a);
$pairId = (int) $pair['id'];

printf("Skeleton convergence, week of %s\n", $week);
printf("  leader   #%d  5 days, full gym, advanced\n", $a);
printf("  follower #%d  3 days, home gym, beginner\n", $b);
printf("  shared days: %s\n\n", implode(',', BuddySchedule::agreedDays($pairId)));

echo "1. the fixture is what the test assumes\n";

t('they share exactly Mon, Wed and Fri', function () use ($pairId) {
    return BuddySchedule::agreedDays($pairId) === [1, 3, 5]
        ?: 'agreed days are ' . implode(',', BuddySchedule::agreedDays($pairId));
});

t('the shared session is capped at the shorter window', function () use ($a, $pairId) {
    // 45 minutes, not 75: whoever has to leave sets the length (§10.3).
    $eff = BuddySchedule::effective($a, $pairId);
    return $eff[1]['minutes'] === 45
        ?: 'the shared Monday is ' . var_export($eff[1]['minutes'], true) . ' minutes';
});

t('the shared session is capped at the more restrictive access', function () use ($a, $pairId) {
    // home_gym, not full_gym: B cannot train at A's gym, so the pair trains where both can.
    $eff = BuddySchedule::effective($a, $pairId);
    return $eff[1]['access'] === 'home_gym'
        ?: 'the shared Monday access is ' . var_export($eff[1]['access'], true);
});

t('the leader has nothing to follow', function () use ($a, $week) {
    return BuddySkeleton::toFollow($a, $week) === null
        ?: 'the leader was given a skeleton before anybody generated';
});

if (!$live) {
    echo "\nnote: run with --live to generate two real weeks (costs money, takes minutes).\n";
    if (!$keep) {
        DB::run("DELETE FROM users WHERE username LIKE 'skel_%'");
        echo "fixtures removed\n";
    }
    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}

// ---------------------------------------------------------------------------
echo "\n2. the leader generates first\n";

$t0 = microtime(true);
$rA = Plans::generateWeek($a, $week, 'initial');
printf("  leader generation: %s in %ds\n",
    $rA['ok'] ? 'ok' : 'FAILED', (int) (microtime(true) - $t0));
if (!$rA['ok']) {
    printf("  error: %s\n", (string) ($rA['error'] ?? '?'));
    foreach ($rA['violations'] ?? [] as $v) {
        printf("    violation: %s\n", (string) $v);
    }
    exit(1);
}

t('the leader shared days are stamped', function () use ($rA) {
    $row = DB::one(
        'SELECT COUNT(*) AS n FROM prescribed_sessions
         WHERE plan_version_id = ? AND shared_skeleton_key IS NOT NULL',
        [(int) $rA['plan_version_id']]
    );
    $n = (int) ($row['n'] ?? 0);
    return $n === 3 ?: "{$n} sessions stamped, expected 3";
});

t('the follower can now see a skeleton', function () use ($b, $week) {
    $s = BuddySkeleton::toFollow($b, $week);
    if ($s === null) {
        return 'no skeleton after the leader generated';
    }
    return count($s['days']) === 3
        ?: 'the skeleton covers ' . count($s['days']) . ' days, expected 3';
});

echo "\n3. the follower generates against it\n";

$t0 = microtime(true);
$rB = Plans::generateWeek($b, $week, 'initial');
printf("  follower generation: %s in %ds\n",
    $rB['ok'] ? 'ok' : 'FAILED', (int) (microtime(true) - $t0));
if (!$rB['ok']) {
    printf("  error: %s\n", (string) ($rB['error'] ?? '?'));
    foreach ($rB['violations'] ?? [] as $v) {
        printf("    violation: %s\n", (string) $v);
    }
    exit(1);
}

/** Both users' committed sessions on the shared days, keyed by date. */
function sessionsFor(int $planVersionId): array
{
    $out = [];
    foreach (DB::all(
        'SELECT id, session_date, session_type, focus, target_minutes, location,
                warmup_minutes, shared_skeleton_key
         FROM prescribed_sessions
         WHERE plan_version_id = ? AND is_committed = 1
         ORDER BY session_date',
        [$planVersionId]
    ) as $r) {
        $out[(string) $r['session_date']] = $r;
    }
    return $out;
}

function blockOf(int $sessionId, string $block): array
{
    return DB::all(
        'SELECT e.name, e.slug, e.pattern, pe.sets, pe.target_reps, pe.target_seconds,
                pe.target_weight_kg
         FROM prescribed_exercises pe
         JOIN exercises e ON e.id = pe.exercise_id
         WHERE pe.session_id = ? AND pe.block = ?
         ORDER BY pe.sort_order',
        [$sessionId, $block]
    );
}

$sesA = sessionsFor((int) $rA['plan_version_id']);
$sesB = sessionsFor((int) $rB['plan_version_id']);

$sharedDates = [];
foreach ([1, 3, 5] as $wd) {
    $sharedDates[] = date('Y-m-d', strtotime($week . ' +' . ($wd - 1) . ' days'));
}

echo "\n  what the two of them actually got:\n";
foreach ($sharedDates as $d) {
    $x = $sesA[$d] ?? null;
    $y = $sesB[$d] ?? null;
    printf("\n  %s (%s)\n", $d, date('D', strtotime($d)));
    printf("    leader:   %s\n", $x === null ? 'NO SESSION' : sprintf(
        '%s/%s %smin %s', $x['session_type'], $x['focus'],
        (string) $x['target_minutes'], (string) $x['location']
    ));
    printf("    follower: %s\n", $y === null ? 'NO SESSION' : sprintf(
        '%s/%s %smin %s', $y['session_type'], $y['focus'],
        (string) $y['target_minutes'], (string) $y['location']
    ));
    if ($x !== null && $y !== null) {
        foreach (['main', 'core'] as $block) {
            $bx = blockOf((int) $x['id'], $block);
            $by = blockOf((int) $y['id'], $block);
            printf("    %s leader:   %s\n", $block, implode(' | ', array_map(
                fn($e) => "{$e['pattern']}:{$e['name']} {$e['sets']}x{$e['target_reps']}"
                        . ($e['target_weight_kg'] !== null ? " @{$e['target_weight_kg']}kg" : ''),
                $bx
            )) ?: '(none)');
            printf("    %s follower: %s\n", $block, implode(' | ', array_map(
                fn($e) => "{$e['pattern']}:{$e['name']} {$e['sets']}x{$e['target_reps']}"
                        . ($e['target_weight_kg'] !== null ? " @{$e['target_weight_kg']}kg" : ''),
                $by
            )) ?: '(none)');
        }
    }
}

echo "\n4. the shape converged\n";

t('both have a committed session on every shared day', function () use ($sesA, $sesB, $sharedDates) {
    foreach ($sharedDates as $d) {
        if (!isset($sesA[$d])) return "the leader has no committed session on {$d}";
        if (!isset($sesB[$d])) return "the follower has no committed session on {$d}";
    }
    return true;
});

t('both plans carry the same skeleton key per shared day',
    function () use ($sesA, $sesB, $sharedDates, $pairId) {
        foreach ($sharedDates as $d) {
            $want = BuddySkeleton::keyFor($pairId, $d);
            if ((string) ($sesA[$d]['shared_skeleton_key'] ?? '') !== $want) {
                return "the leader key for {$d} is not the pair key";
            }
            if ((string) ($sesB[$d]['shared_skeleton_key'] ?? '') !== $want) {
                return "the follower key for {$d} is not the pair key";
            }
        }
        return true;
    }
);

t('the session type matches on every shared day', function () use ($sesA, $sesB, $sharedDates) {
    foreach ($sharedDates as $d) {
        $x = (string) $sesA[$d]['session_type'];
        $y = (string) $sesB[$d]['session_type'];
        if ($x !== $y) return "{$d}: leader {$x}, follower {$y}";
    }
    return true;
});

t('the focus matches on every shared day', function () use ($sesA, $sesB, $sharedDates) {
    // The one the model is most likely to drift on, because a beginner's "best" focus for a day
    // genuinely differs from an advanced lifter's. §10.0 says the pairing wins anyway.
    $off = [];
    foreach ($sharedDates as $d) {
        $x = (string) $sesA[$d]['focus'];
        $y = (string) $sesB[$d]['focus'];
        if ($x !== $y) $off[] = "{$d}: {$x} vs {$y}";
    }
    return $off === [] ?: implode('; ', $off);
});

t('the main movement PATTERNS match, in order', function () use ($sesA, $sesB, $sharedDates) {
    /*
     * §10.1. The pattern is the shared thing, not the exercise: a hinge is a hinge whether it is
     * a trap-bar deadlift or a dumbbell RDL, and that is exactly what lets this mismatched pair
     * work side by side.
     */
    $off = [];
    foreach ($sharedDates as $d) {
        $x = array_column(blockOf((int) $sesA[$d]['id'], 'main'), 'pattern');
        $y = array_column(blockOf((int) $sesB[$d]['id'], 'main'), 'pattern');
        if ($x !== $y) {
            $off[] = sprintf('%s: [%s] vs [%s]', $d, implode(',', $x), implode(',', $y));
        }
    }
    return $off === [] ?: implode('; ', $off);
});

t('the core block is identical', function () use ($sesA, $sesB, $sharedDates) {
    // §10.2a, the strongest claim in the feature: same exercises, same sets, same reps or holds.
    $off = [];
    foreach ($sharedDates as $d) {
        $shape = fn(array $rows): array => array_map(
            fn($e) => [$e['slug'], (int) $e['sets'],
                       (string) $e['target_reps'], (string) $e['target_seconds']],
            $rows
        );
        $x = $shape(blockOf((int) $sesA[$d]['id'], 'core'));
        $y = $shape(blockOf((int) $sesB[$d]['id'], 'core'));
        if ($x !== $y) {
            $off[] = sprintf('%s: %s vs %s', $d, json_encode($x), json_encode($y));
        }
    }
    return $off === [] ?: implode('; ', $off);
});

echo "\n5. the prescriptions still diverge\n";

t('the follower is not given the leader loads', function () use ($sesA, $sesB, $sharedDates) {
    /*
     * The failure this whole design has to avoid. A beginner at 74kg who has never trained must
     * not inherit an advanced 88kg lifter's working weights just because they share a rack.
     *
     * Asserted as "not every load identical" rather than "every load lower", because a matched
     * bodyweight movement legitimately has no load at all, and a light accessory can coincide.
     */
    $sameEverywhere = true;
    $compared = 0;
    foreach ($sharedDates as $d) {
        $x = blockOf((int) $sesA[$d]['id'], 'main');
        $y = blockOf((int) $sesB[$d]['id'], 'main');
        foreach ($x as $i => $ex) {
            $wx = $ex['target_weight_kg'];
            $wy = $y[$i]['target_weight_kg'] ?? null;
            if ($wx === null || $wy === null) {
                continue;   // bodyweight or timed; nothing to compare
            }
            $compared++;
            if ((float) $wx !== (float) $wy) {
                $sameEverywhere = false;
            }
        }
    }
    if ($compared === 0) {
        return null;   // no loaded movement in common; nothing this test can say
    }
    return !$sameEverywhere
        ?: "all {$compared} loaded movements were prescribed at the same weight";
});

t('the follower plan respects their own access', function () use ($sesB, $sharedDates) {
    // home_gym, not the leader's full gym. If the skeleton could override this, matching would
    // have become a way to prescribe equipment somebody does not own.
    foreach ($sharedDates as $d) {
        $loc = (string) $sesB[$d]['location'];
        if ($loc === 'full_gym') {
            return "{$d}: the follower was sent to a full gym they do not have";
        }
    }
    return true;
});

t('the follower keeps their own session length', function () use ($sesB, $sharedDates) {
    foreach ($sharedDates as $d) {
        $m = (int) $sesB[$d]['target_minutes'];
        if ($m > 45) {
            return "{$d}: the follower got {$m} minutes against a 45 minute window";
        }
    }
    return true;
});

t('the leader keeps their extra days to themselves', function () use ($sesA, $week) {
    // Tue and Sat are A's alone: 5 committed days against 3 shared ones (§10.3b).
    $tue = date('Y-m-d', strtotime($week . ' +1 days'));
    $sat = date('Y-m-d', strtotime($week . ' +5 days'));
    $extra = 0;
    foreach ([$tue, $sat] as $d) {
        if (isset($sesA[$d])) $extra++;
    }
    return $extra > 0 ?: 'the leader lost both of their individual days';
});

t('neither plan mentions the other person', function () use ($rA, $rB) {
    /*
     * The prompt says "you are writing one plan, for this user only". A rationale that names the
     * buddy reads as though the app is coaching two people at once, and it leaks whatever the
     * model inferred about them.
     */
    foreach ([['leader', (int) $rA['plan_version_id'], 'Skel Follow'],
              ['follower', (int) $rB['plan_version_id'], 'Skel Lead']] as [$who, $pid, $other]) {
        $rows = DB::all(
            'SELECT rationale FROM prescribed_sessions WHERE plan_version_id = ?', [$pid]
        );
        foreach ($rows as $r) {
            if (stripos((string) $r['rationale'], $other) !== false) {
                return "the {$who} plan names the other person";
            }
        }
    }
    return true;
});

// ---------------------------------------------------------------------------

if (!$keep) {
    DB::run("DELETE FROM users WHERE username LIKE 'skel_%'");
    echo "\nfixtures removed\n";
} else {
    printf("\nfixtures kept: leader=%d follower=%d\n", $a, $b);
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
