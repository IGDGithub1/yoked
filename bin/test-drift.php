<?php
declare(strict_types=1);

/**
 * Tests for the escalation rule (SPEC-coaching §4.2) and the nudge ladder (§9).
 *
 * Seeds users at controlled states and asserts the CLASSIFICATION and the DECISION,
 * never the generated copy. Drift::ask() and Nudge::compose() are model calls; what
 * matters here is that they are reached exactly when they should be and not otherwise.
 *
 * The thing being protected is quiet. An on-track user must produce nothing at all,
 * and minor drift must produce nothing NOW — those two silences are most of the
 * design, and they are the easiest thing to break by accident.
 *
 *   php bin/test-drift.php
 *   php bin/test-drift.php --keep
 */

require __DIR__ . '/../src/bootstrap_cli.php';
require YK_SRC . '/lib/Response.php';
// Claude::send() rate-limits per user, so RateLimit is needed by any path that could
// reach a model call — even one this suite avoids taking.
require YK_SRC . '/lib/RateLimit.php';
require YK_SRC . '/lib/Goals.php';
require YK_SRC . '/lib/Claude.php';
require YK_SRC . '/lib/PlanSchema.php';
require YK_SRC . '/lib/BuddySchedule.php';   // Safety::checkAvailability reads it
require YK_SRC . '/lib/Safety.php';
require YK_SRC . '/lib/Plans.php';
require YK_SRC . '/lib/Nutrition.php';
require YK_SRC . '/lib/CheckIn.php';
require YK_SRC . '/lib/Notify.php';
require YK_SRC . '/lib/Drift.php';
require YK_SRC . '/lib/Nudge.php';

$keep = in_array('--keep', array_slice($argv, 1), true);

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
        } else {
            printf("  FAIL  %s — %s\n", $label, is_string($r) ? $r : 'false');
            $fail++;
        }
    } catch (Throwable $e) {
        printf("  FAIL  %s — %s\n", $label, $e->getMessage());
        $fail++;
    }
}

DB::run("DELETE FROM users WHERE username LIKE 'drtest_%'");

$TZ = 'UTC';

/** A user with a plan covering the assessment window, so targets exist. */
function seedUser(string $suffix, array $opts = []): int
{
    global $TZ;

    // Created a fortnight ago, not today. A brand-new account has legitimately had no
    // chance to log anything, and "since when" falls back to created_at — so a
    // same-day fixture would make every absence test read as zero quiet days.
    $userId = DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash,
                            onboarding_state, created_at)
         VALUES (?, ?, ?, "x", "active", DATE_SUB(NOW(), INTERVAL 14 DAY))',
        ['drtest_' . $suffix, 'DR ' . $suffix, "drtest_{$suffix}@example.test"]
    );
    DB::run(
        'INSERT INTO profiles (user_id, timezone, nudge_intensity, nudge_after_days)
         VALUES (?, ?, ?, ?)',
        [$userId, $TZ, $opts['intensity'] ?? 'gentle', $opts['nudge_after'] ?? 3]
    );

    // A plan spanning the last fortnight so prescribed_days targets exist for the
    // heavy-overeat check to compare against.
    $weekStart = Schedule::weekStart($TZ, date('Y-m-d', strtotime('-7 days')));
    $planId = DB::insert(
        'INSERT INTO plan_versions (user_id, week_start, version, reason, summary)
         VALUES (?, ?, 1, "initial", "Seeded for drift tests.")',
        [$userId, $weekStart]
    );
    for ($i = -14; $i <= 7; $i++) {
        DB::run(
            'INSERT INTO prescribed_days
                (plan_version_id, day_date, target_calories, target_protein_g,
                 target_fat_g, target_carbs_g, constraints)
             VALUES (?, ?, 2000, 150, 70, 200, ?)',
            [$planId, date('Y-m-d', strtotime("{$i} days")),
             json_encode(['calories' => ['mode' => 'range_pct', 'lo' => 0.85, 'hi' => 1.05]])]
        );
    }
    return $userId;
}

/**
 * Log a day with real content, which is what "logged" means to Drift.
 *
 * A bare logged_days row does NOT count: Nutrition::dayId() creates one whenever
 * anything touches a day, including a check-in with no food, and treating that as
 * logged would hide real absence.
 */
function logDay(int $userId, string $date, array $opts = []): void
{
    $dayId = DB::insert(
        'INSERT INTO logged_days
            (user_id, log_date, macro_on_target, failure_count,
             sessions_prescribed, sessions_completed)
         VALUES (?, ?, ?, ?, ?, ?)',
        [
            $userId, $date,
            $opts['on_target'] ?? 1,
            $opts['failures'] ?? 0,
            $opts['prescribed'] ?? 0,
            $opts['completed'] ?? 0,
        ]
    );
    $mealId = DB::insert(
        'INSERT INTO logged_meals (logged_day_id, slot) VALUES (?, "breakfast")',
        [$dayId]
    );
    DB::run(
        'INSERT INTO logged_entries (logged_meal_id, name, calories, protein_g, fat_g, carbs_g, source)
         VALUES (?, "Seeded food", ?, 40, 20, 50, "manual")',
        [$mealId, $opts['calories'] ?? 600]
    );
}

/** Yesterday backwards: day 1 is yesterday, since today is never judged. */
function ago(int $n): string
{
    return date('Y-m-d', strtotime("-{$n} days"));
}

function loadUser(int $id): array
{
    return DB::one('SELECT id, onboarding_state, baseline_starts_on FROM users WHERE id = ?', [$id]);
}

echo "Drift and nudge tests\n\n";

// ---- on track --------------------------------------------------------------

echo "1. on track produces nothing\n";

$ok = seedUser('ontrack');
for ($i = 1; $i <= 7; $i++) {
    logDay($ok, ago($i), ['prescribed' => 1, 'completed' => 1, 'calories' => 1900]);
}

t('a fully logged, on-target week is on_track', function () use ($ok, $TZ) {
    $a = Drift::assess(loadUser($ok), $TZ);
    return $a['state'] === Drift::ON_TRACK ? true : "{$a['state']}: {$a['reason']}";
});

t('on_track warrants no Claude call', function () {
    return Drift::warrantsClaude(Drift::ON_TRACK) === false;
});

t('on_track warrants no nudge', function () {
    return Drift::warrantsNudge(Drift::ON_TRACK) === false;
});

t('today is never judged, only completed days', function () use ($ok, $TZ) {
    // Nothing logged today, and the user is still on track. Judging a day at 2pm for
    // having eaten one meal is the scolding this app is built to avoid.
    $a = Drift::assess(loadUser($ok), $TZ);
    return $a['state'] === Drift::ON_TRACK
        ?: "an unlogged TODAY changed the state to {$a['state']}";
});

// ---- minor -----------------------------------------------------------------

echo "\n2. minor drift is noted, never acted on\n";

$minor = seedUser('minor');
for ($i = 1; $i <= 7; $i++) {
    // One missed session on day 3, everything else clean.
    logDay($minor, ago($i), [
        'prescribed' => 1,
        'completed'  => $i === 3 ? 0 : 1,
        'calories'   => 1900,
    ]);
}

t('one missed session is minor, not significant', function () use ($minor, $TZ) {
    $a = Drift::assess(loadUser($minor), $TZ);
    return $a['state'] === Drift::MINOR ? true : "{$a['state']}: {$a['reason']}";
});

t('minor drift warrants NO Claude call', function () {
    // §4.2: minor drift aggregates for the weekly check-in. Asking about one missed
    // session is exactly the noise the escalation rule exists to prevent.
    return Drift::warrantsClaude(Drift::MINOR) === false;
});

t('minor drift warrants no nudge either', function () {
    return Drift::warrantsNudge(Drift::MINOR) === false;
});

t('the missed session is counted, so the check-in can see it', function () use ($minor, $TZ) {
    $a = Drift::assess(loadUser($minor), $TZ);
    return $a['missed_sessions'] === 1 ? true : "counted {$a['missed_sessions']}";
});

// ---- significant -----------------------------------------------------------

echo "\n3. significant drift asks questions\n";

$twoGaps = seedUser('twogaps');
// Five logged days out of seven: two unlogged is significant on its own (§4.2).
foreach ([1, 2, 5, 6, 7] as $i) {
    logDay($twoGaps, ago($i), ['prescribed' => 1, 'completed' => 1, 'calories' => 1900]);
}

t('two unlogged days in the window is significant', function () use ($twoGaps, $TZ) {
    $a = Drift::assess(loadUser($twoGaps), $TZ);
    if ($a['state'] !== Drift::SIGNIFICANT) {
        return "{$a['state']}: {$a['reason']}";
    }
    return $a['unlogged'] === 2 ? true : "counted {$a['unlogged']} unlogged";
});

t('significant drift is the only state that warrants Claude', function () {
    return Drift::warrantsClaude(Drift::SIGNIFICANT) === true;
});

$both = seedUser('both');
for ($i = 1; $i <= 7; $i++) {
    // A missed session AND a heavy overeat. Either alone is a bad day; together they
    // are the pattern §4.2 calls significant.
    logDay($both, ago($i), [
        'prescribed' => 1,
        'completed'  => $i === 2 ? 0 : 1,
        'calories'   => $i === 2 ? 3000 : 1900,   // 3000 vs a 2000 target
        'on_target'  => $i === 2 ? 0 : 1,
        'failures'   => $i === 2 ? 2 : 0,
    ]);
}

t('a missed session plus a heavy overeat is significant', function () use ($both, $TZ) {
    $a = Drift::assess(loadUser($both), $TZ);
    if ($a['state'] !== Drift::SIGNIFICANT) {
        return "{$a['state']}: {$a['reason']} (missed {$a['missed_sessions']}, "
             . "heavy {$a['heavy_overeat_days']})";
    }
    return $a['missed_sessions'] > 0 && $a['heavy_overeat_days'] > 0
        ?: 'state was significant but the counts do not support it';
});

t('a heavy overeat ALONE is only minor', function () use ($TZ) {
    // One big day is a big day. Adaptation is not punishment, and interrupting
    // someone over a single meal out is how an app gets deleted.
    $u = seedUser('heavyonly');
    for ($i = 1; $i <= 7; $i++) {
        logDay($u, ago($i), [
            'prescribed' => 1, 'completed' => 1,
            'calories'   => $i === 4 ? 3000 : 1900,
        ]);
    }
    $a = Drift::assess(loadUser($u), $TZ);
    return $a['state'] === Drift::MINOR ? true : "{$a['state']}: {$a['reason']}";
});

t('a re-ask is suppressed while the same patch continues', function () use ($both) {
    // Without this the sweep asks every 15 minutes forever.
    if (Drift::alreadyAsked($both)) {
        return 'reported asked before anything was asked';
    }
    DB::insert(
        'INSERT INTO chat_turns (user_id, role, body, outcome, drift_state)
         VALUES (?, "assistant", "Seeded question.", "question", "significant")',
        [$both]
    );
    return Drift::alreadyAsked($both) === true ?: 'an asked question was not detected';
});

// ---- absent ----------------------------------------------------------------

echo "\n4. absence nudges\n";

$gone = seedUser('gone', ['nudge_after' => 3]);
logDay($gone, ago(6), ['prescribed' => 1, 'completed' => 1]);

t('silence past the threshold is absent, outranking everything else', function () use ($gone, $TZ) {
    $a = Drift::assess(loadUser($gone), $TZ);
    if ($a['state'] !== Drift::ABSENT) {
        return "{$a['state']}: {$a['reason']}";
    }
    return $a['quiet_days'] >= 3 ? true : "quiet_days was {$a['quiet_days']}";
});

t('absent warrants a nudge, not a Claude question', function () {
    // Absence gets a nudge. It does not get a coaching conversation about a week
    // there is no data for.
    return Drift::warrantsNudge(Drift::ABSENT) === true
        && Drift::warrantsClaude(Drift::ABSENT) === false;
});

t('last_logged is the last day with real content', function () use ($gone, $TZ) {
    $a = Drift::assess(loadUser($gone), $TZ);
    return $a['last_logged'] === ago(6) ? true : "reported {$a['last_logged']}";
});

t('a bare logged_days row does not count as logged', function () use ($TZ) {
    /*
     * The trap. Nutrition::dayId() creates a row the moment anything touches a day,
     * including a daily check-in with no food. If that counted as logged, a user who
     * taps an energy rating and nothing else would never be nudged, which defeats
     * the entire ladder.
     */
    $u = seedUser('bareonly');
    DB::run(
        'INSERT INTO logged_days (user_id, log_date, energy) VALUES (?, ?, 4)',
        [$u, ago(1)]
    );
    $a = Drift::assess(loadUser($u), $TZ);
    if ($a['last_logged'] !== null) {
        return "last_logged was {$a['last_logged']} for a content-free row";
    }
    return $a['state'] === Drift::ABSENT
        ?: "state was {$a['state']} for a user who has logged no content at all";
});

t('days before the user existed are not counted as drift', function () use ($TZ) {
    /*
     * The bug a cron dry run caught. A user two days into their baseline was reported
     * as "7 of the last 7 days unlogged" and classified SIGNIFICANT, so the coach
     * would have opened a conversation about a week that had not happened to them.
     * Five of those days predated the account.
     *
     * A brand-new user has nothing to drift from. What they need is a nudge to start.
     */
    $u = DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash,
                            onboarding_state, baseline_starts_on, baseline_ends_on,
                            created_at)
         VALUES ("drtest_new", "DR new", "drtest_new@example.test", "x", "baseline",
                 DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 12 DAY),
                 DATE_SUB(NOW(), INTERVAL 2 DAY))'
    );
    DB::run(
        'INSERT INTO profiles (user_id, timezone, nudge_after_days) VALUES (?, ?, 3)',
        [$u, $TZ]
    );

    $row = DB::one(
        'SELECT id, onboarding_state, baseline_starts_on, created_at FROM users WHERE id = ?',
        [$u]
    );
    $a = Drift::assess($row, $TZ);

    // Two days in, nothing logged, threshold of 3: below the nudge threshold and
    // certainly not a coaching conversation.
    if ($a['state'] === Drift::SIGNIFICANT) {
        return "classified significant with {$a['unlogged']} unlogged days for a "
             . '2-day-old account';
    }
    return $a['unlogged'] <= 2
        ? true
        : "counted {$a['unlogged']} unlogged days for an account that is 2 days old";
});

t('a user below their own threshold is not nudged', function () use ($TZ) {
    // Two quiet days with a threshold of 3. The passive indicator is the whole
    // response at this point.
    $u = seedUser('twoquiet', ['nudge_after' => 3]);
    logDay($u, ago(2), ['prescribed' => 1, 'completed' => 1]);
    $a = Drift::assess(loadUser($u), $TZ);
    if ($a['state'] === Drift::ABSENT) {
        return "state was absent at {$a['quiet_days']} quiet days with a threshold of 3";
    }
    return Nudge::forAbsence($u, $a) === null ?: 'a nudge was sent below the threshold';
});

t('the user\'s own threshold is honoured, not a hardcoded one', function () use ($TZ) {
    // §9.3 is an onboarding question with a default of 3, not a constant.
    $u = seedUser('patient', ['nudge_after' => 6]);
    logDay($u, ago(4), ['prescribed' => 1, 'completed' => 1]);
    $a = Drift::assess(loadUser($u), $TZ);
    return $a['state'] !== Drift::ABSENT
        ? true
        : "four quiet days triggered absence for a user who asked for six";
});

// ---- the ceilings ----------------------------------------------------------

echo "\n5. how hard nudges push\n";

t('leave_me_alone means one nudge and then silence', function () {
    return Tone::nudgeCeiling('leave_me_alone') === 1
        ?: (string) Tone::nudgeCeiling('leave_me_alone');
});

t('the ceilings rise with intensity', function () {
    $g = Tone::nudgeCeiling('gentle');
    $p = Tone::nudgeCeiling('persistent');
    $r = Tone::nudgeCeiling('relentless');
    return $g < $p && $p < $r ?: "gentle {$g}, persistent {$p}, relentless {$r}";
});

t('an unknown intensity falls back rather than going unlimited', function () {
    return Tone::nudgeCeiling('nonsense') === Tone::nudgeCeiling('gentle');
});

t('every tone has a written brief', function () {
    // The label is how a user picks; the brief is what makes the tone real.
    foreach (Tone::TONES as $tone) {
        if (strlen(Tone::brief($tone)) < 30) {
            return "{$tone} has no real brief";
        }
    }
    return true;
});

t('em dashes are stripped from generated copy', function () {
    /*
     * Asking the model not to use them is not enough. Across five tone/intensity
     * combinations it ignored an explicit "NO EM DASHES" instruction in two of them,
     * and generated copy is exactly what the browser suite never sees, so the failure
     * lands in a user's face rather than in a test.
     */
    $cases = [
        // Spaced: becomes a comma, without doubling the space.
        ['Four days quiet — log whenever.', 'Four days quiet, log whenever.'],
        // Unspaced: must not weld the words together.
        ['3 days—no drama.', '3 days, no drama.'],
        // En dash too.
        ['Six days – what happened?', 'Six days, what happened?'],
        // Nothing to do.
        ['Six days. What happened?', 'Six days. What happened?'],
    ];
    foreach ($cases as [$in, $want]) {
        $got = Tone::clean($in);
        if ($got !== $want) {
            return "\"{$in}\" became \"{$got}\", wanted \"{$want}\"";
        }
    }
    return true;
});

t('cleaning does not leave doubled punctuation', function () {
    // A dash right after a full stop would otherwise produce ". ,".
    return !str_contains(Tone::clean('Done. — Next week.'), '. ,')
        ?: Tone::clean('Done. — Next week.');
});

t('nudge pressure is a separate axis from tone', function () {
    // A sarcastic hardass who asked to be left alone gets one dry line, not a
    // personality overriding an explicit answer.
    return Tone::nudgeBrief('leave_me_alone') !== Tone::nudgeBrief('relentless');
});

// ---- notifications ---------------------------------------------------------

echo "\n6. notification plumbing\n";

t('a notification can be written and read back', function () use ($gone) {
    $id = Notify::create($gone, 'absence', 'Test nudge.', null, null, null);
    if ($id === null) {
        return 'create returned null with dedupe disabled';
    }
    $unread = Notify::unread($gone);
    return count($unread) > 0 && $unread[0]['body'] === 'Test nudge.'
        ?: 'the notification did not come back';
});

t('dedupe suppresses a repeat inside the window', function () use ($gone) {
    // The guard that stops a 15-minute sweep producing 96 copies of one nudge.
    $second = Notify::create($gone, 'absence', 'Duplicate.', null, null, 20);
    return $second === null ?: 'a duplicate nudge was written';
});

t('an unknown type throws rather than writing an invisible row', function () use ($gone) {
    try {
        Notify::create($gone, 'not_a_real_type', 'x', null, null, null);
        return 'no exception for an unknown type';
    } catch (InvalidArgumentException $e) {
        return true;
    }
});

t('marking read removes it from unread', function () use ($gone) {
    $unread = Notify::unread($gone);
    $before = count($unread);
    if ($before === 0) {
        return 'nothing unread to mark';
    }
    Notify::markRead($gone, [$unread[0]['id']]);
    return count(Notify::unread($gone)) === $before - 1
        ?: 'the count did not drop';
});

t('one user cannot dismiss another user\'s notification', function () use ($gone, $ok) {
    // Ids are sequential and guessable, so this is scoped in the UPDATE rather than
    // checked first.
    $id = Notify::create($ok, 'absence', 'Belongs to someone else.', null, null, null);
    $n  = Notify::markRead($gone, [$id]);
    if ($n !== 0) {
        return 'markRead touched a row belonging to another user';
    }
    return count(Notify::unread($ok)) > 0 ?: 'the victim\'s notification vanished';
});

// ---- cleanup ---------------------------------------------------------------

if (!$keep) {
    DB::run("DELETE FROM users WHERE username LIKE 'drtest_%'");
    echo "\n  fixtures removed\n";
} else {
    echo "\n  fixtures kept\n";
}

echo "\n";
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
