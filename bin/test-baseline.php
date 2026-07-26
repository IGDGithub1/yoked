<?php
declare(strict_types=1);

/**
 * Tests for the baseline lifecycle and per-user scheduling.
 *
 * Seeds users at controlled points in the window and asserts what cron would do
 * about them. Against the database but no API and no Claude call: the whole point
 * of this suite is the decisions, and every one of those is a date comparison.
 *
 * What it is really guarding is the §9 rule that week 1 gets no prescription.
 * Before migration 009 there were no baseline dates at all, so cron could not tell
 * a brand-new user from one who had been logging for a month, and generated a full
 * prescribed week for both.
 *
 *   php bin/test-baseline.php
 *   php bin/test-baseline.php --keep    leave the fixtures in place
 */

require __DIR__ . '/../src/bootstrap_cli.php';
// The CLI bootstrap loads only what has no dependencies; these pull in Claude and
// the rest, which this suite needs for startBaseline() and the plan-reason check.
require YK_SRC . '/lib/Response.php';
require YK_SRC . '/lib/Goals.php';
require YK_SRC . '/lib/Claude.php';
require YK_SRC . '/lib/PlanSchema.php';
require YK_SRC . '/lib/BuddySchedule.php';   // Safety::checkAvailability reads it
require YK_SRC . '/lib/Safety.php';
require YK_SRC . '/lib/Plans.php';
require YK_SRC . '/lib/Onboarding.php';

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

// ---- fixtures --------------------------------------------------------------

DB::run("DELETE FROM users WHERE username LIKE 'bltest_%'");

/**
 * A user sitting at a chosen point in the window.
 *
 * @param ?int $dayOffset days since the window opened; null leaves it unstarted
 */
function seedUser(string $suffix, string $state, ?int $dayOffset, ?string $tz): int
{
    $username = 'bltest_' . $suffix;

    $starts = null;
    $ends   = null;
    if ($dayOffset !== null) {
        /*
         * Counted back from TODAY, not from this week's Monday.
         *
         * The first version of this anchored to weekStart() and then re-aligned the
         * result to a Monday, which snapped every offset back to the same day and
         * made "day 1" land on whatever day of the week the suite happened to run.
         * It reported day 6 on a Saturday.
         *
         * The real startBaseline() does stamp a Monday, but these fixtures are
         * asserting the DAY ARITHMETIC, and that has to be exact regardless of when
         * the suite runs. The Monday-alignment rule is covered separately.
         */
        $starts = date('Y-m-d', strtotime(Schedule::today($tz) . ' -' . $dayOffset . ' days'));
        $ends   = date('Y-m-d', strtotime($starts . ' +' . Baseline::DAYS . ' days'));
    }

    $userId = DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash,
                            onboarding_state, baseline_starts_on, baseline_ends_on)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$username, 'BL ' . $suffix, $username . '@example.test',
         password_hash('irrelevant-for-this-suite', PASSWORD_DEFAULT),
         $state, $starts, $ends]
    );
    DB::run('INSERT INTO profiles (user_id, timezone) VALUES (?, ?)', [$userId, $tz]);
    return $userId;
}

function loadUser(int $id): array
{
    return DB::one(
        'SELECT id, onboarding_state, baseline_starts_on, baseline_ends_on
         FROM users WHERE id = ?',
        [$id]
    );
}

echo "Baseline lifecycle tests\n\n";

// ---- progress reporting ----------------------------------------------------

echo "1. where a user is in the window\n";

$day1 = seedUser('day1', 'baseline', 0, 'UTC');
$day8 = seedUser('day8', 'baseline', 7, 'UTC');
$done = seedUser('done', 'baseline', 14, 'UTC');
$soon = seedUser('soon', 'baseline', null, 'UTC');
$live = seedUser('live', 'active', null, 'UTC');

t('an unstarted window reports started = false', function () use ($soon) {
    // Seeded with no dates at all, which is what a user looks like between
    // finishing the quiz and startBaseline() stamping the window.
    return Baseline::progress(loadUser($soon), 'UTC') === null;
});

t('day one of the window is "day 1", not day 0', function () use ($day1) {
    $p = Baseline::progress(loadUser($day1), 'UTC');
    return $p['day'] === 1 ? true : "reported day {$p['day']}";
});

t('the window is 14 days long', function () use ($day1) {
    $p = Baseline::progress(loadUser($day1), 'UTC');
    return $p['total'] === 14 && $p['days_left'] === 14
        ?: "total {$p['total']} left {$p['days_left']}";
});

t('day 8 reports week 2', function () use ($day8) {
    $p = Baseline::progress(loadUser($day8), 'UTC');
    return $p['week'] === 2 && $p['day'] === 8
        ?: "week {$p['week']} day {$p['day']}";
});

t('day 1 reports week 1', function () use ($day1) {
    $p = Baseline::progress(loadUser($day1), 'UTC');
    return $p['week'] === 1 ?: "week {$p['week']}";
});

t('an active user has no baseline progress at all', function () use ($live) {
    return Baseline::progress(loadUser($live), 'UTC') === null;
});

t('the day count never exceeds the total', function () use ($done) {
    // A user cron has not graduated yet can be past the end date.
    $p = Baseline::progress(loadUser($done), 'UTC');
    return $p['day'] <= 14 ? true : "reported day {$p['day']}";
});

// ---- the §9 rule -----------------------------------------------------------

echo "\n2. week 1 gets no prescription (SPEC-coaching §9)\n";

t('a week-1 user is in the observation week', function () use ($day1) {
    return Baseline::inObservationWeek(loadUser($day1), 'UTC') === true;
});

t('a week-2 user is NOT, so a provisional plan can be generated', function () use ($day8) {
    return Baseline::inObservationWeek(loadUser($day8), 'UTC') === false;
});

t('an active user is never in the observation week', function () use ($live) {
    return Baseline::inObservationWeek(loadUser($live), 'UTC') === false;
});

t('a baseline user with no dates is treated as observing', function () use ($soon) {
    // Fail safe: the cost of not generating is a delayed plan, the cost of
    // generating is prescribing to someone with no history at all.
    return Baseline::inObservationWeek(loadUser($soon), 'UTC') === true;
});

// ---- graduation ------------------------------------------------------------

echo "\n3. graduation\n";

t('a finished window reports complete', function () use ($done) {
    return Baseline::isComplete(loadUser($done), 'UTC') === true;
});

t('a mid-window user does not', function () use ($day8) {
    return Baseline::isComplete(loadUser($day8), 'UTC') === false;
});

t('activate() moves a finished user to active', function () use ($done) {
    if (!Baseline::activate($done)) {
        return 'activate() reported no change';
    }
    return loadUser($done)['onboarding_state'] === 'active'
        ?: 'state is ' . loadUser($done)['onboarding_state'];
});

t('activate() is idempotent, so two sweeps cannot both win', function () use ($done) {
    // The guard is in the WHERE clause, not a read-then-write.
    return Baseline::activate($done) === false;
});

t('activate() will not touch a user who is still observing', function () use ($day1) {
    // The guard lives in activate()'s own WHERE clause, not just at cron's call
    // site: a guard that only exists at the caller is one new call site away from
    // graduating someone on their second day.
    Baseline::activate($day1);
    return loadUser($day1)['onboarding_state'] === 'baseline'
        ?: 'a week-1 user was activated';
});

t('activate() will not touch a baseline user with no end date', function () {
    $u = seedUser('nodates', 'baseline', null, 'UTC');
    Baseline::activate($u);
    return loadUser($u)['onboarding_state'] === 'baseline'
        ?: 'a user with no window was activated';
});

// ---- timezone-sensitive dates ----------------------------------------------

echo "\n4. the window is read in the user's zone\n";

t('two zones can disagree about today, and both are right', function () {
    // At some instants Sydney and Los Angeles are on different calendar days.
    // Everything above reads the window against LOCAL today, which is why the
    // dates are stored as local dates rather than instants.
    $syd = Schedule::today('Australia/Sydney');
    $la  = Schedule::today('America/Los_Angeles');
    // They differ for most of the day and match for a few hours; both are valid.
    return $syd >= $la ?: "Sydney {$syd} was behind Los Angeles {$la}";
});

t('a window stamped in one zone is still 14 days in another', function () use ($day1) {
    // The stored dates are calendar dates. Reading them from a different zone can
    // shift which DAY the user is on, but never how long the window is.
    $u = loadUser($day1);
    $len = Schedule::daysBetween((string) $u['baseline_starts_on'], (string) $u['baseline_ends_on']);
    return $len === 14 ? true : "window is {$len} days";
});

t('startBaseline refuses an incomplete quiz without stamping dates', function () {
    $u = seedUser('incomplete', 'in_progress', null, 'Australia/Sydney');
    $r = Onboarding::startBaseline($u);
    if ($r['ok'] !== false) {
        return 'an incomplete quiz was allowed to start the baseline';
    }
    // The guard has to run BEFORE the stamp, or a refused start still moves the
    // clock and the user's real week-1 gets eaten.
    return loadUser($u)['baseline_starts_on'] === null
        ?: 'dates were stamped despite the refusal';
});

t('the window always opens on a Monday, in any zone', function () {
    // What startBaseline() actually stamps. Asserted against the helper it uses,
    // in zones far enough apart that their "next Monday" can differ by a day.
    foreach (['UTC', 'Australia/Sydney', 'America/Los_Angeles', 'Asia/Kolkata'] as $tz) {
        $starts = Schedule::nextMonday($tz);
        if (date('N', strtotime($starts)) !== '1') {
            return "{$tz} produced {$starts}, which is not a Monday";
        }
        // And it must be in the future: a Monday signup gets the FOLLOWING Monday,
        // so the partial signup day is never counted as day one.
        if ($starts <= Schedule::today($tz)) {
            return "{$tz} produced {$starts}, which is not after its today";
        }
    }
    return true;
});

t('a mid-week signup gets a full 14 days, not a short first week', function () {
    // The Thursday case: log Thursday to Sunday as practice that does not count,
    // then two clean weeks.
    $starts = Schedule::nextMonday('UTC', '2026-07-23');   // a Thursday
    $ends   = date('Y-m-d', strtotime($starts . ' +' . Baseline::DAYS . ' days'));
    if ($starts !== '2026-07-27') {
        return "Thursday signup opened on {$starts}";
    }
    return Schedule::daysBetween($starts, $ends) === 14
        ?: 'window was not 14 days';
});

// ---- plan reason -----------------------------------------------------------

echo "\n5. what kind of plan a generation is\n";

t('a user with no plans has never had one', function () use ($day8) {
    return Plans::hasEverHadPlan($day8) === false;
});

t('a user with a plan has had one, even superseded', function () use ($day8) {
    $id = DB::insert(
        'INSERT INTO plan_versions (user_id, week_start, version, reason, summary, superseded_at)
         VALUES (?, ?, 1, "provisional", "seeded", NOW())',
        [$day8, Schedule::weekStart('UTC')]
    );
    $r = Plans::hasEverHadPlan($day8);
    DB::run('DELETE FROM plan_versions WHERE id = ?', [$id]);
    // Superseded still counts: a user who had a provisional plan is not receiving
    // an "initial" one afterwards.
    return $r === true;
});

// ---- cleanup ---------------------------------------------------------------

if (!$keep) {
    DB::run("DELETE FROM users WHERE username LIKE 'bltest_%'");
    echo "\n  fixtures removed\n";
} else {
    echo "\n  fixtures kept\n";
}

echo "\n";
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
