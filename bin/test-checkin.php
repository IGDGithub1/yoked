<?php
declare(strict_types=1);

/**
 * Tests for the weekly check-in state machine.
 *
 * Over real HTTP for the routes, plus direct library calls for the parts a client
 * cannot reach. Deliberately does NOT exercise CheckIn::review(): that is a Claude
 * call costing minutes and money, and none of these assertions are about the model.
 * What is asserted is the machinery around it — when a check-in opens, what counts
 * as late, when a late one can still change a plan, and that answering is
 * idempotent.
 *
 *   php bin/test-checkin.php
 *   php bin/test-checkin.php --base=http://…
 *   php bin/test-checkin.php --keep
 */

require __DIR__ . '/../src/bootstrap_cli.php';
require YK_SRC . '/lib/Response.php';
require YK_SRC . '/lib/RateLimit.php';
require YK_SRC . '/lib/Goals.php';
require YK_SRC . '/lib/Claude.php';
require YK_SRC . '/lib/PlanSchema.php';
require YK_SRC . '/lib/Safety.php';
require YK_SRC . '/lib/Plans.php';
require YK_SRC . '/lib/Nutrition.php';
require YK_SRC . '/lib/CheckIn.php';

$base = 'https://yoked.lil-boxes.com';
$keep = false;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--base=')) {
        $base = rtrim(substr($a, 7), '/');
    } elseif ($a === '--keep') {
        $keep = true;
    }
}

$pass = 0;
$fail = 0;
$cookieJar = sys_get_temp_dir() . '/yoked-ci-cookies-' . getmypid() . '.txt';
$csrf = null;

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

/** @return array{status:int, body:?array, raw:string} */
function req(string $method, string $path, ?array $body = null): array
{
    global $base, $cookieJar, $csrf;

    $ch = curl_init($base . '/api/' . ltrim($path, '/'));
    $headers = ['accept: application/json'];
    if ($body !== null) {
        $headers[] = 'content-type: application/json';
    }
    if ($csrf !== null && !in_array($method, ['GET', 'HEAD'], true)) {
        $headers[] = 'x-csrf-token: ' . $csrf;
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err    = curl_error($ch);
    unset($ch);

    if ($raw === '' && $err !== '') {
        throw new RuntimeException("transport: {$err}");
    }
    $d = json_decode($raw, true);
    if (is_array($d) && isset($d['csrf']) && is_string($d['csrf'])) {
        $csrf = $d['csrf'];
    }
    return ['status' => $status, 'body' => is_array($d) ? $d : null, 'raw' => $raw];
}

echo "Check-in tests against {$base}\n\n";

// ---- fixture ---------------------------------------------------------------

DB::run("DELETE FROM users WHERE username LIKE 'citest_%'");
DB::run("DELETE FROM rate_limits WHERE bucket LIKE 'login:%citest%'");

$username = 'citest_' . substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'a-long-enough-passphrase';

// The week that is ending, and the one a plan would cover.
$thisWeek = Schedule::weekStart('UTC');
$nextWeek = date('Y-m-d', strtotime($thisWeek . ' +7 days'));

$userId = DB::tx(function () use ($username, $password, $thisWeek): int {
    $id = DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
         VALUES (?, ?, ?, ?, "active")',
        [$username, 'CI Test', $username . '@example.test',
         password_hash($password, PASSWORD_DEFAULT)]
    );
    DB::run('INSERT INTO profiles (user_id, timezone) VALUES (?, "UTC")', [$id]);

    // A logged day in the week ending, because the check-in job only opens one for
    // a user who actually did something.
    DB::run(
        'INSERT INTO logged_days (user_id, log_date, energy) VALUES (?, ?, 4)',
        [$id, $thisWeek]
    );
    return $id;
});

printf("        user #%d (%s), week ending %s\n\n", $userId, $username, $thisWeek);

// ---- opening ---------------------------------------------------------------

echo "1. opening a check-in\n";

t('a check-in opens for a week', function () use ($userId, $thisWeek) {
    $c = CheckIn::open($userId, $thisWeek);
    return $c !== null && (string) $c['status'] === 'pending'
        ?: 'no pending check-in after open()';
});

t('opening twice does not create a second', function () use ($userId, $thisWeek) {
    CheckIn::open($userId, $thisWeek);
    $n = (int) DB::one(
        'SELECT COUNT(*) AS n FROM weekly_checkins WHERE user_id = ? AND week_start = ?',
        [$userId, $thisWeek]
    )['n'];
    return $n === 1 ? true : "{$n} rows for one week";
});

t('current() finds the pending one', function () use ($userId, $thisWeek) {
    $c = CheckIn::current($userId);
    return $c !== null && (string) $c['week_start'] === $thisWeek
        ?: 'current() did not return it';
});

t('answeredFor() returns nothing while it is pending', function () use ($userId, $thisWeek) {
    return CheckIn::answeredFor($userId, $thisWeek) === null;
});

// ---- the late window -------------------------------------------------------

echo "\n2. when a late check-in can still change the plan\n";

t('the window is open before the plan week starts', function () use ($thisWeek) {
    // Today is inside the week that is ending, so next week has not begun.
    return CheckIn::canStillAlterPlan($thisWeek, 'UTC') === true;
});

t('the window is shut once the plan week has started', function () {
    // A check-in for a week that ended a fortnight ago: its plan week is already
    // in the past, so any change now is mid-week drift adaptation (§7.1) instead.
    $old = date('Y-m-d', strtotime(Schedule::weekStart('UTC') . ' -14 days'));
    return CheckIn::canStillAlterPlan($old, 'UTC') === false;
});

// ---- answering over HTTP ---------------------------------------------------

echo "\n3. answering it\n";

t('a CSRF token can be fetched', function () use (&$csrf) {
    $r = req('GET', 'me');
    $csrf = $r['body']['csrf'] ?? null;
    return is_string($csrf) && $csrf !== '' ?: 'no token';
});

t('the fixture logs in', function () use ($username, $password) {
    $r = req('POST', 'login', ['identifier' => $username, 'password' => $password]);
    return $r['status'] === 200 ?: "login returned {$r['status']}: {$r['raw']}";
});

$checkinId = null;

t('GET checkin/weekly reports the pending one', function () use (&$checkinId, $thisWeek) {
    $r = req('GET', 'checkin/weekly');
    if ($r['status'] !== 200) {
        return "status {$r['status']}: {$r['raw']}";
    }
    $p = $r['body']['pending'] ?? null;
    if ($p === null) {
        return 'no pending check-in in the payload';
    }
    $checkinId = (int) $p['id'];
    if ((string) $p['week_start'] !== $thisWeek) {
        return "week_start was {$p['week_start']}, expected {$thisWeek}";
    }
    // No plan for next week yet, so answering now still shapes it.
    return ($p['can_shape_plan'] ?? null) === true
        ?: 'can_shape_plan was false with no plan in existence';
});

t('the route does not collide with the daily check-in', function () {
    // routes/training.php has `PUT checkin/{date}`, which would swallow
    // `checkin/weekly` if it were registered first. First match wins.
    $r = req('GET', 'checkin/weekly');
    return $r['status'] === 200 ?: "GET checkin/weekly returned {$r['status']}";
});

t('a partial answer is accepted', function () use (&$checkinId) {
    // Only a written report. Demanding six measurements weekly is how you get zero
    // check-ins by week four.
    $r = req('PUT', "checkin/weekly/{$checkinId}", [
        'self_report' => 'Knee felt off on Thursday, otherwise a good week.',
    ]);
    return $r['status'] === 200 ?: "status {$r['status']}: {$r['raw']}";
});

t('answering marks it completed and stamps the time', function () use ($userId, $thisWeek) {
    $c = CheckIn::answeredFor($userId, $thisWeek);
    if ($c === null) {
        return 'answeredFor() still returns nothing';
    }
    return $c['answered_at'] !== null ?: 'answered_at was not stamped';
});

t('it was NOT flagged late, since no plan existed', function () use ($userId, $thisWeek) {
    $c = CheckIn::answeredFor($userId, $thisWeek);
    return (int) $c['answered_late'] === 0
        ?: 'answered_late was set with no plan in existence';
});

t('answering twice is refused rather than silently re-opening it', function () use (&$checkinId) {
    $r = req('PUT', "checkin/weekly/{$checkinId}", ['self_report' => 'again']);
    return $r['status'] === 422 ?: "status {$r['status']}: {$r['raw']}";
});

t('the written report survived exactly', function () use ($userId, $thisWeek) {
    $c = CheckIn::answeredFor($userId, $thisWeek);
    return str_contains((string) $c['self_report'], 'Knee felt off')
        ?: 'report was ' . var_export($c['self_report'], true);
});

t('a bad weight is rejected with a reason', function () use ($userId, $thisWeek) {
    // Against the library, since the HTTP route now has an answered check-in.
    $c = CheckIn::open($userId, date('Y-m-d', strtotime($thisWeek . ' -7 days')));
    $r = CheckIn::answer($userId, (int) $c['id'], ['weight_kg' => 900], 'UTC');
    return $r['ok'] === false ?: 'a 900 kg bodyweight was accepted';
});

// ---- lateness --------------------------------------------------------------

echo "\n4. a check-in answered after the plan was built\n";

$planId = null;

t('a plan already built for the check-in\'s week makes the answer late', function () use ($userId, $thisWeek, &$planId) {
    /*
     * The real Sunday-evening case: the plan generated without the check-in, and
     * the user answers afterwards.
     *
     * The check-in and the plan have to CORRESPOND — a check-in for week W is late
     * only if the plan for W+7 exists. Seeding a plan for an unrelated week and
     * calling the answer late is what produced the incoherent state above.
     *
     * Hand-built rather than generated: this is about the lateness flag, not the
     * model, and a real generation costs minutes.
     */
    $week     = date('Y-m-d', strtotime($thisWeek . ' -21 days'));
    $planWeek = CheckIn::planWeekFor($week);

    $planId = DB::insert(
        'INSERT INTO plan_versions (user_id, week_start, version, reason, summary)
         VALUES (?, ?, 1, "initial", "Seeded for the lateness test.")',
        [$userId, $planWeek]
    );

    $c = CheckIn::open($userId, $week);
    $r = CheckIn::answer($userId, (int) $c['id'], ['self_report' => 'Broke my leg.'], 'UTC');

    if (!$r['ok']) {
        return 'answer failed: ' . $r['error'];
    }
    return $r['late'] === true ?: 'answer was not flagged late despite a live plan';
});

t('with no plan for its week, an answer is not late however old', function () use ($userId, $thisWeek) {
    // Staleness is not lateness. A check-in nobody ever built a plan against has
    // nothing to be late for, and its answers are banked for the next generation.
    $week = date('Y-m-d', strtotime($thisWeek . ' -42 days'));
    $c = CheckIn::open($userId, $week);
    $r = CheckIn::answer($userId, (int) $c['id'], ['self_report' => 'Ancient history.'], 'UTC');
    return $r['late'] === false ?: 'an answer was late with no plan for its week';
});

t('the late flag is stored, not just returned', function () use ($userId, $thisWeek) {
    $week = date('Y-m-d', strtotime($thisWeek . ' -21 days'));
    $c = CheckIn::find($userId, $week);
    return (int) $c['answered_late'] === 1 ?: 'answered_late was not persisted';
});

t('lateness and alterability are separate questions, both keyed on the same week', function () use ($userId, $thisWeek) {
    /*
     * These are two DIFFERENT questions and it took a probe to see that clearly:
     *
     *   answered_late      — was there already a plan when they answered?  (history)
     *   canStillAlterPlan  — is that plan's week still in the future?      (now)
     *
     * An old check-in answered after its plan was built is late AND unalterable:
     * both true, and not a contradiction. What WAS wrong is that the two used
     * different notions of "the plan" — one asked about the coming week, the other
     * about the check-in's own W+7 — so for a stale check-in they were answering
     * about different plans entirely. Both key on planWeekFor() now.
     *
     * What must never happen is the reverse: alterable while not late. That would
     * mean offering to change a plan that does not exist.
     */
    $stale = date('Y-m-d', strtotime($thisWeek . ' -21 days'));
    $c = CheckIn::find($userId, $stale);
    $late      = (int) $c['answered_late'] === 1;
    $alterable = CheckIn::canStillAlterPlan($stale, 'UTC');

    if ($alterable && !$late) {
        return 'alterable without a plan to alter';
    }
    // And the plan week both are keyed on is genuinely in the past for this one.
    $planWeek = CheckIn::planWeekFor($stale);
    return $planWeek < Schedule::today('UTC')
        ? true
        : "plan week {$planWeek} is not in the past, so this is not the stale case";
});

t('planWeekFor is the week after the one being reported on', function () use ($thisWeek) {
    $expected = date('Y-m-d', strtotime($thisWeek . ' +7 days'));
    return CheckIn::planWeekFor($thisWeek) === $expected
        ?: CheckIn::planWeekFor($thisWeek) . " != {$expected}";
});

t('a late outcome can be recorded as banked', function () use ($userId, $thisWeek) {
    $week = date('Y-m-d', strtotime($thisWeek . ' -21 days'));
    $c = CheckIn::find($userId, $week);
    CheckIn::recordLateOutcome((int) $c['id'], 'banked', null);
    return (string) CheckIn::find($userId, $week)['late_outcome'] === 'banked'
        ?: 'outcome was not stored';
});

t('a late outcome can be recorded as altered, with the plan', function () use ($userId, $thisWeek, &$planId) {
    $week = date('Y-m-d', strtotime($thisWeek . ' -21 days'));
    $c = CheckIn::find($userId, $week);
    CheckIn::recordLateOutcome((int) $c['id'], 'altered', $planId);
    $after = CheckIn::find($userId, $week);
    return (string) $after['late_outcome'] === 'altered'
        && (int) $after['plan_version_id'] === $planId
        ?: 'outcome or plan link was not stored';
});

// ---- skipping and nudging --------------------------------------------------

echo "\n5. skipping and nudging\n";

t('a check-in can be skipped deliberately', function () use ($userId, $thisWeek) {
    $week = date('Y-m-d', strtotime($thisWeek . ' -28 days'));
    $c = CheckIn::open($userId, $week);
    if (!CheckIn::skip($userId, (int) $c['id'])) {
        return 'skip() reported no change';
    }
    return (string) CheckIn::find($userId, $week)['status'] === 'skipped'
        ?: 'status was not skipped';
});

t('a skipped check-in is not treated as answered', function () use ($userId, $thisWeek) {
    // 'skipped' means "asked and declined", which is not input to a plan.
    $week = date('Y-m-d', strtotime($thisWeek . ' -28 days'));
    return CheckIn::answeredFor($userId, $week) === null;
});

t('a skipped check-in cannot be skipped twice', function () use ($userId, $thisWeek) {
    $week = date('Y-m-d', strtotime($thisWeek . ' -28 days'));
    $c = CheckIn::find($userId, $week);
    return CheckIn::skip($userId, (int) $c['id']) === false;
});

t('nudge bookkeeping starts at zero', function () use ($userId, $thisWeek) {
    $week = date('Y-m-d', strtotime($thisWeek . ' -35 days'));
    $c = CheckIn::open($userId, $week);
    return (int) $c['nudge_count'] === 0 && $c['last_nudged_at'] === null
        ?: 'a fresh check-in already had nudges';
});

// ---- the observation week --------------------------------------------------

echo "\n6. no check-in during observation\n";

t('a user in baseline week 1 gets no check-in', function () {
    /*
     * Reported from a real account: a user who created it the same day received a
     * "your coach on last week" review for a week they were not present for, because
     * the check-in job accepted any baseline user. Plan generation had this guard from
     * the start; the check-in job did not.
     *
     * There is nothing to report ON during observation: no plan to have adhered to and
     * no targets to have hit.
     */
    DB::run("DELETE FROM users WHERE username IN ('citest_obs', 'citest_soon')");
    $u = DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash,
                            onboarding_state, baseline_starts_on, baseline_ends_on)
         VALUES ("citest_obs", "CI obs", "citest_obs@example.test", "x", "baseline",
                 CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY))'
    );
    DB::run('INSERT INTO profiles (user_id, timezone) VALUES (?, "UTC")', [$u]);

    $row = DB::one(
        'SELECT id, onboarding_state, baseline_starts_on, baseline_ends_on FROM users WHERE id = ?',
        [$u]
    );
    return Baseline::inObservationWeek($row, 'UTC') === true
        ?: 'a day-one baseline user was not treated as observing';
});

t('a user whose baseline has not started yet is also observing', function () {
    // The exact case from the report: baseline_starts_on is in the FUTURE, so the
    // check-in job must not open anything for the week just gone.
    $u = DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash,
                            onboarding_state, baseline_starts_on, baseline_ends_on)
         VALUES ("citest_soon", "CI soon", "citest_soon@example.test", "x", "baseline",
                 DATE_ADD(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 16 DAY))'
    );
    DB::run('INSERT INTO profiles (user_id, timezone) VALUES (?, "UTC")', [$u]);

    $row = DB::one(
        'SELECT id, onboarding_state, baseline_starts_on, baseline_ends_on FROM users WHERE id = ?',
        [$u]
    );
    return Baseline::inObservationWeek($row, 'UTC') === true
        ?: 'a user whose baseline starts in two days was not treated as observing';
});

// ---- units round-trip ------------------------------------------------------

echo "\n7. the review speaks the user's units\n";

/** The rendered prompt, which is private, reached through reflection. */
function renderedPrompt(int $userId, array $checkin): string
{
    $ref = new ReflectionMethod(CheckIn::class, 'userPrompt');
    $ref->setAccessible(true);
    return (string) $ref->invoke(null, $userId, $checkin, null, false);
}

t('an imperial user\'s weight round-trips through the prompt as pounds', function () use ($userId) {
    /*
     * Reported from a real account: the review said "Weight at 113.85kg" to a user who
     * had chosen imperial. The conversion on the way IN was correct all along; the
     * prompt then handed Claude the raw stored metric with "kg" hardcoded.
     *
     * Converting on input and forgetting the output is a whole-round-trip mistake: the
     * numbers were right and the coach still sounded like it was describing someone
     * else.
     */
    DB::run('UPDATE profiles SET units = "imperial" WHERE user_id = ?', [$userId]);

    $week = date('Y-m-d', strtotime('monday this week -49 days'));
    $c = CheckIn::open($userId, $week);
    // 251 lb, which stores as ~113.85 kg: the exact number from the report.
    $r = CheckIn::answer($userId, (int) $c['id'], ['weight_kg' => 251.0], 'UTC');
    if (!$r['ok']) {
        return 'answer failed: ' . $r['error'];
    }

    $stored = (float) CheckIn::find($userId, $week)['weight_kg'];
    if (abs($stored - 113.85) > 0.2) {
        return "251 lb stored as {$stored} kg, expected ~113.85";
    }

    $prompt = renderedPrompt($userId, CheckIn::find($userId, $week));
    if (str_contains($prompt, ' kg')) {
        return 'the prompt still quotes kilograms to an imperial user';
    }
    if (!str_contains($prompt, ' lb')) {
        return 'the prompt does not quote pounds';
    }
    // And it says so explicitly, because the model otherwise reaches for whichever
    // unit the numbers look like they belong to.
    return str_contains($prompt, 'imperial units')
        ?: 'the prompt does not state which unit system to use';
});

t('a metric user still gets kilograms', function () use ($userId) {
    DB::run('UPDATE profiles SET units = "metric" WHERE user_id = ?', [$userId]);

    $week = date('Y-m-d', strtotime('monday this week -56 days'));
    $c = CheckIn::open($userId, $week);
    CheckIn::answer($userId, (int) $c['id'], ['weight_kg' => 80.0], 'UTC');

    $prompt = renderedPrompt($userId, CheckIn::find($userId, $week));
    if (!str_contains($prompt, ' kg')) {
        return 'a metric user was not given kilograms';
    }
    return str_contains($prompt, 'metric units') ?: 'the prompt does not state metric';
});

// ---- the profile schedule --------------------------------------------------

echo "\n8. the schedule itself\n";

t('the check-in slot defaults a day before the plan slot', function () use ($userId) {
    // Saturday 18:00 against Sunday 18:00: the whole point is that the user gets
    // roughly 24 hours to answer something that will shape the coming week.
    $p = DB::one(
        'SELECT checkin_weekday, checkin_hour, plan_generation_weekday, plan_generation_hour
         FROM profiles WHERE user_id = ?',
        [$userId]
    );
    if ((int) $p['checkin_weekday'] !== 6 || (int) $p['checkin_hour'] !== 18) {
        return "check-in slot is day {$p['checkin_weekday']} hour {$p['checkin_hour']}";
    }
    if ((int) $p['plan_generation_weekday'] !== 7 || (int) $p['plan_generation_hour'] !== 18) {
        return "plan slot is day {$p['plan_generation_weekday']} hour {$p['plan_generation_hour']}";
    }
    // And the gap is a real 24 hours, not a coincidence of numbers.
    return true;
});

t('the check-in slot fires before the plan slot in the same week', function () {
    // Asserted through Schedule so a change to either default is caught here
    // rather than by a user getting a plan before being asked.
    $sat = Schedule::slotPassed('UTC', 6, 18);
    $sun = Schedule::slotPassed('UTC', 7, 18);
    // If Sunday's has passed then Saturday's must have too. The reverse is fine.
    return !$sun || $sat ?: 'the plan slot passed while the check-in slot had not';
});

// ---- cleanup ---------------------------------------------------------------

if (!$keep) {
    DB::run('DELETE FROM users WHERE id = ?', [$userId]);
    // The observation fixtures, which are separate users rather than states of the
    // main one: inObservationWeek() reads the row, so it needs real rows.
    DB::run("DELETE FROM users WHERE username LIKE 'citest_%'");
    @unlink($cookieJar);
    echo "\n  test users removed\n";
} else {
    echo "\n  test user kept: {$username}\n";
}

echo "\n";
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
