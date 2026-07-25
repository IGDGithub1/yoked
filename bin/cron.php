<?php
declare(strict_types=1);

/**
 * Yoked maintenance cron. Runs every 15 minutes from SiteGround.
 *
 *   /usr/local/bin/php /home/customer/www/yoked.lil-boxes.com/bin/cron.php
 *
 * Jobs, each independently guarded:
 *   1. weekly_plan     — generate next week's plan for users who are due
 *   2. weekly_checkin  — open a check-in for the week that just ended
 *
 * Design constraints that came from actually running this:
 *
 *   * Generation takes MINUTES. No user waits on it — cron does the work and
 *     the plan is simply there when they look. That is why this exists.
 *
 *   * One user's failure must never stop the sweep. Every job is wrapped, and
 *     a thrown error is recorded against that user and the loop continues.
 *
 *   * wait_timeout on this host is 60 SECONDS, so the DB connection dies
 *     during every generation. DB.php reconnects, but cron must not assume a
 *     live connection across a slow call — see DB::ensureConnected().
 *
 *   * Firing every 15 minutes means a job could run four times an hour. The
 *     cron_runs unique key makes double-running structurally impossible: the
 *     row is claimed BEFORE the work starts, so a second invocation's INSERT
 *     fails and it skips.
 *
 * Flags:
 *   --dry-run        report what would run, change nothing, call no API
 *   --job=<name>     run one job only
 *   --user=<id>      restrict to one user (for testing)
 *   --force          ignore the cron_runs guard (re-runs a claimed period)
 *   --quiet          only print when something happened or failed
 *   --ignore-schedule  treat every eligible user as due now, whatever their
 *                      configured weekday/hour. For testing the sweep without
 *                      waiting for their slot to come round.
 */

require __DIR__ . '/../src/bootstrap_cli.php';
require YK_SRC . '/lib/Response.php';
require YK_SRC . '/lib/RateLimit.php';
require YK_SRC . '/lib/Goals.php';
require YK_SRC . '/lib/Claude.php';
require YK_SRC . '/lib/PlanSchema.php';
require YK_SRC . '/lib/Safety.php';
require YK_SRC . '/lib/Plans.php';

$args   = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$force  = in_array('--force', $args, true);
$quiet  = in_array('--quiet', $args, true);
$ignoreSchedule = in_array('--ignore-schedule', $args, true);

$onlyJob  = null;
$onlyUser = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--job=')) {
        $onlyJob = substr($a, 6);
    }
    if (str_starts_with($a, '--user=')) {
        $onlyUser = (int) substr($a, 7);
    }
}

/** Log a line. Timestamped because these end up in a shared log file. */
function say(string $msg, bool $always = true): void
{
    global $quiet;
    if ($always || !$quiet) {
        printf("[%s] %s\n", gmdate('Y-m-d H:i:s'), $msg);
    }
}

$startedAll = microtime(true);
say('cron start' . ($dryRun ? ' (DRY RUN)' : ''), !$quiet);

// ---------------------------------------------------------------------------
// Run guard
// ---------------------------------------------------------------------------

/**
 * Claim a job for a period, or return false if it is already claimed.
 *
 * The claim is an INSERT against a unique key, so it is atomic — two
 * overlapping cron invocations cannot both win. Claiming BEFORE the work
 * matters: a job that takes four minutes would otherwise be picked up again by
 * the next cron tick while still running.
 */
function claim(string $job, ?int $userId, string $period): int|false
{
    global $force;

    if ($force) {
        DB::run(
            'DELETE FROM cron_runs WHERE job = ? AND user_id <=> ? AND period = ?',
            [$job, $userId, $period]
        );
    }

    try {
        return DB::insert(
            'INSERT INTO cron_runs (job, user_id, period, status) VALUES (?, ?, ?, "running")',
            [$job, $userId, $period]
        );
    } catch (PDOException $e) {
        // Duplicate key: already claimed, by an earlier tick or a concurrent
        // one. Not an error — this is the guard doing its job.
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return false;
        }
        throw $e;
    }
}

/** Close out a claimed run. */
function finish(int $runId, string $status, ?string $detail, float $started): void
{
    // The connection is very likely dead if this follows a generation, so make
    // sure it is alive before writing the result. Losing the result of work
    // that already happened is the worst outcome available.
    DB::ensureConnected();
    DB::run(
        'UPDATE cron_runs SET status = ?, detail = ?, duration_ms = ?, finished_at = NOW()
         WHERE id = ?',
        [
            $status,
            $detail !== null ? substr($detail, 0, 500) : null,
            (int) round((microtime(true) - $started) * 1000),
            $runId,
        ]
    );
}

/**
 * Release a stale claim.
 *
 * A cron invocation killed mid-job (deploy, OOM, shared-host reap) leaves a
 * 'running' row behind, and the unique key then blocks that job forever. Any
 * run still 'running' well past the longest plausible generation is treated as
 * dead and cleared so the next tick can retry.
 */
function releaseStaleClaims(): int
{
    $stale = DB::run(
        'UPDATE cron_runs
         SET status = "failed",
             detail = "abandoned — cron died mid-job; released for retry",
             finished_at = NOW()
         WHERE status = "running" AND started_at < (NOW() - INTERVAL 30 MINUTE)'
    )->rowCount();

    if ($stale > 0) {
        // Clearing status is not enough: the unique key is on
        // (job, user_id, period), so the row must go for a retry to claim it.
        DB::run(
            'DELETE FROM cron_runs
             WHERE status = "failed"
               AND detail LIKE "abandoned%"
               AND finished_at >= (NOW() - INTERVAL 1 MINUTE)'
        );
    }
    return $stale;
}

// ---------------------------------------------------------------------------
// Job 1 — weekly plan generation
// ---------------------------------------------------------------------------

/**
 * Users whose next week should be generated now.
 *
 * Due when: onboarding is past 'pending', coaching is not paused, it is at or
 * after their configured generation weekday+hour, and next week has no plan.
 */
function usersDueForPlan(?int $onlyUser): array
{
    $sql = 'SELECT u.id, u.display_name, u.onboarding_state,
                   u.baseline_starts_on, u.baseline_ends_on,
                   p.plan_generation_weekday, p.plan_generation_hour, p.timezone
            FROM users u
            JOIN profiles p ON p.user_id = u.id
            WHERE u.status = "active"
              AND u.onboarding_state IN ("baseline", "active")
              AND p.coaching_paused = 0';
    $params = [];
    if ($onlyUser !== null) {
        $sql .= ' AND u.id = ?';
        $params[] = $onlyUser;
    }
    return DB::all($sql, $params);
}

/**
 * The Monday of the week a plan generated now should cover, in the user's zone.
 *
 * Per-user rather than global because the answer differs across zones: at
 * 2026-07-26 23:00 UTC it is already Monday in Sydney, so "next monday" there is
 * a week later than it is in London. Getting this from UTC would hand an
 * Australian user a plan for the week after the one they are about to start.
 */
function targetWeekStart(?string $tz): string
{
    // Generation runs late in the week for the week ahead. On a Monday through
    // Saturday run this is still "next Monday"; on a Sunday run it is tomorrow.
    return Schedule::nextMonday($tz);
}

function jobWeeklyPlan(?int $onlyUser, bool $dryRun): array
{
    global $ignoreSchedule;

    $due     = usersDueForPlan($onlyUser);
    $results = ['generated' => 0, 'skipped' => 0, 'observing' => 0, 'failed' => 0];

    foreach ($due as $user) {
        $userId = (int) $user['id'];
        $name   = $user['display_name'];
        $tz     = $user['timezone'] ?? null;

        // Everything below is asked in the USER's local time. 18:00 UTC is
        // Saturday lunchtime in Chicago and 06:00 Sunday in Sydney, so a slot
        // picked because it is "late in the weekend" only reads that way in
        // Europe.
        $week = targetWeekStart($tz);

        /*
         * Week 1 of the baseline gets NO plan (§9: "Week 1: pure observation. Log
         * food, activity, daily check-ins. No prescription.").
         *
         * This guard is the whole reason the baseline needed dates. Before it,
         * cron treated baseline and active users identically and a brand-new user
         * got a full prescribed week on their first Sunday, which is precisely
         * what the observation period exists to avoid.
         */
        if (Baseline::inObservationWeek($user, $tz)) {
            $p = Baseline::progress($user, $tz);
            $where = $p === null
                ? 'baseline, no dates'
                : ($p['started'] ? "baseline day {$p['day']} of {$p['total']}" : 'baseline not started');
            say("  {$name}: observing ({$where}); no plan yet", false);
            $results['observing']++;
            continue;
        }

        // Is it their slot yet, where they are?
        $wantDay  = (int) $user['plan_generation_weekday'];
        $wantHour = (int) $user['plan_generation_hour'];
        $isTime   = $ignoreSchedule || Schedule::slotPassed($tz, $wantDay, $wantHour);

        if (!$isTime) {
            say("  {$name}: not yet (wants day {$wantDay} hour {$wantHour} "
                . ($tz ?? 'UTC') . ')', false);
            $results['skipped']++;
            continue;
        }

        // Already have a live plan for that week? Nothing to do. This is the
        // cheap check; the claim below is the authoritative one.
        if (Plans::live($userId, $week) !== null) {
            say("  {$name}: already has a plan for {$week}", false);
            $results['skipped']++;
            continue;
        }

        /*
         * Which KIND of plan this is.
         *
         * 'provisional' for the week-2 plan a baseline user gets so they have
         * something to follow while the second week sharpens the picture (§9).
         * 'initial' for a user's genuine first real plan. The enum has had both
         * values since 003 and nothing ever wrote the second — every generation
         * was recorded as 'initial', so a week-5 plan was indistinguishable from
         * a week-1 one in the version history.
         *
         * Plans generated FROM a check-in are reason = 'check_in' and are not this
         * job's business; that path arrives with the check-in conversation.
         */
        $isBaseline = ($user['onboarding_state'] ?? '') === 'baseline';
        $reason     = $isBaseline
            ? 'provisional'
            : (Plans::hasEverHadPlan($userId) ? 'check_in' : 'initial');

        if ($dryRun) {
            say("  {$name}: WOULD generate week of {$week} (reason={$reason})");
            $results['generated']++;
            continue;
        }

        $runId = claim('weekly_plan', $userId, $week);
        if ($runId === false) {
            say("  {$name}: claimed by another run", false);
            $results['skipped']++;
            continue;
        }

        $started = microtime(true);
        say("  {$name}: generating week of {$week} (reason={$reason}) …");

        try {
            $result = Plans::generateWeek($userId, $week, $reason);

            if ($result['ok']) {
                $secs = round(microtime(true) - $started, 1);
                say("  {$name}: ok — plan_version {$result['plan_version_id']} ({$secs}s)");
                finish($runId, 'ok', "plan_version {$result['plan_version_id']}", $started);
                $results['generated']++;

                // Tell them it is ready. Notify::create() would be the home for
                // this once the notification layer lands; for now the plan
                // simply being there is the signal.
            } else {
                $detail = $result['error']
                    . ($result['violations'] ? ' | ' . implode('; ', $result['violations']) : '');
                say("  {$name}: FAILED — {$detail}");
                finish($runId, 'failed', $detail, $started);
                $results['failed']++;
            }
        } catch (Throwable $e) {
            // One user's failure must not stop the sweep.
            say("  {$name}: ERROR — " . $e->getMessage());
            error_log('[yoked cron] plan generation failed for user ' . $userId
                . ': ' . $e->getMessage());
            try {
                finish($runId, 'failed', $e->getMessage(), $started);
            } catch (Throwable $inner) {
                error_log('[yoked cron] could not record failure: ' . $inner->getMessage());
            }
            $results['failed']++;
        }
    }

    return $results;
}

// ---------------------------------------------------------------------------
// Job 2 — graduate finished baselines
// ---------------------------------------------------------------------------

/**
 * Move users whose two weeks are up from 'baseline' to 'active'.
 *
 * Nothing did this before: 'baseline' was a bare flag with no dates, so a user
 * who finished observing stayed in that state forever. Cron owns the transition
 * rather than the app doing it on next login, because the whole point of the
 * schedule is that it works for a user who has not opened the app.
 *
 * Runs BEFORE plan generation in the sweep order, deliberately: a user who
 * graduates this morning should have their first real plan generated by the same
 * sweep rather than waiting a week for the next one.
 *
 * No Claude call, no cron_runs claim. It is one guarded UPDATE whose WHERE clause
 * is its own idempotency — two concurrent sweeps cannot both win it.
 */
function jobBaselineGraduation(?int $onlyUser, bool $dryRun): array
{
    $results = ['graduated' => 0, 'waiting' => 0, 'failed' => 0];

    $sql = 'SELECT u.id, u.display_name, u.onboarding_state,
                   u.baseline_starts_on, u.baseline_ends_on, p.timezone
            FROM users u
            JOIN profiles p ON p.user_id = u.id
            WHERE u.status = "active"
              AND u.onboarding_state = "baseline"
              AND u.baseline_ends_on IS NOT NULL';
    $params = [];
    if ($onlyUser !== null) {
        $sql .= ' AND u.id = ?';
        $params[] = $onlyUser;
    }

    foreach (DB::all($sql, $params) as $user) {
        $userId = (int) $user['id'];
        $name   = $user['display_name'];
        $tz     = $user['timezone'] ?? null;

        // "Is it over?" is asked in the user's own zone: the window is stored as
        // local dates, so comparing against a UTC today would graduate an
        // Australian user a day early and an American one a day late.
        if (!Baseline::isComplete($user, $tz)) {
            $p = Baseline::progress($user, $tz);
            // "day 0 of 14" is not a thing. Before the window opens the honest
            // report is when it starts, not a clamped day count.
            $where = ($p !== null && !$p['started'])
                ? "baseline opens {$p['starts_on']}"
                : 'baseline day ' . ($p['day'] ?? '?') . ' of ' . ($p['total'] ?? '?');
            say("  {$name}: {$where}, not finished", false);
            $results['waiting']++;
            continue;
        }

        if ($dryRun) {
            say("  {$name}: WOULD graduate to active (baseline ended {$user['baseline_ends_on']})");
            $results['graduated']++;
            continue;
        }

        try {
            if (Baseline::activate($userId)) {
                say("  {$name}: baseline complete, now active");
                $results['graduated']++;
            } else {
                // Another sweep got there first, which is fine.
                $results['waiting']++;
            }
        } catch (Throwable $e) {
            say("  {$name}: ERROR — " . $e->getMessage());
            error_log('[yoked cron] graduation failed for user ' . $userId . ': ' . $e->getMessage());
            $results['failed']++;
        }
    }

    return $results;
}

// ---------------------------------------------------------------------------
// Job 3 — weekly check-in creation
// ---------------------------------------------------------------------------

/**
 * Open a check-in for the week that just ended.
 *
 * Cron creates these rather than waiting for the user to open the app: a quiet
 * user would otherwise never get re-planned, which is the whole failure mode
 * the check-in exists to catch (SPEC-coaching.md §7.2).
 */
function jobWeeklyCheckin(?int $onlyUser, bool $dryRun): array
{
    // The Monday of the week that just ended.
    $lastWeek = date('Y-m-d', strtotime('monday last week'));
    $results  = ['created' => 0, 'skipped' => 0, 'failed' => 0];

    $sql = 'SELECT u.id, u.display_name
            FROM users u
            JOIN profiles p ON p.user_id = u.id
            WHERE u.status = "active"
              AND u.onboarding_state IN ("baseline", "active")
              AND p.coaching_paused = 0';
    $params = [];
    if ($onlyUser !== null) {
        $sql .= ' AND u.id = ?';
        $params[] = $onlyUser;
    }

    foreach (DB::all($sql, $params) as $user) {
        $userId = (int) $user['id'];
        $name   = $user['display_name'];

        $existing = DB::one(
            'SELECT id, status FROM weekly_checkins WHERE user_id = ? AND week_start = ?',
            [$userId, $lastWeek]
        );
        if ($existing !== null) {
            say("  {$name}: check-in for {$lastWeek} already exists ({$existing['status']})", false);
            $results['skipped']++;
            continue;
        }

        // Only worth asking if they actually did something that week —
        // otherwise the check-in is a form nobody has anything to put in.
        $logged = (int) (DB::one(
            'SELECT COUNT(*) AS n FROM logged_days
             WHERE user_id = ? AND log_date >= ? AND log_date < DATE_ADD(?, INTERVAL 7 DAY)',
            [$userId, $lastWeek, $lastWeek]
        )['n'] ?? 0);

        if ($logged === 0) {
            say("  {$name}: nothing logged for {$lastWeek}; no check-in opened", false);
            $results['skipped']++;
            continue;
        }

        if ($dryRun) {
            say("  {$name}: WOULD open a check-in for {$lastWeek} ({$logged} days logged)");
            $results['created']++;
            continue;
        }

        $runId = claim('weekly_checkin', $userId, $lastWeek);
        if ($runId === false) {
            $results['skipped']++;
            continue;
        }

        $started = microtime(true);
        try {
            DB::run(
                'INSERT INTO weekly_checkins (user_id, week_start, status)
                 VALUES (?, ?, "pending")',
                [$userId, $lastWeek]
            );
            say("  {$name}: opened check-in for {$lastWeek}");
            finish($runId, 'ok', "{$logged} days logged", $started);
            $results['created']++;
        } catch (Throwable $e) {
            say("  {$name}: ERROR — " . $e->getMessage());
            error_log('[yoked cron] check-in creation failed for user ' . $userId
                . ': ' . $e->getMessage());
            finish($runId, 'failed', $e->getMessage(), $started);
            $results['failed']++;
        }
    }

    return $results;
}

// ---------------------------------------------------------------------------
// Sweep
// ---------------------------------------------------------------------------

// Sanity-check config before doing anything: a cron that silently no-ops for
// weeks because of a missing key is worse than one that fails loudly.
if ((string) yk_config('anthropic.api_key', '') === '') {
    say('FATAL: anthropic.api_key is not configured');
    exit(1);
}

try {
    DB::ensureConnected();
} catch (Throwable $e) {
    say('FATAL: database unreachable — ' . $e->getMessage());
    exit(1);
}

$released = releaseStaleClaims();
if ($released > 0) {
    say("released {$released} stale claim(s) from a cron that died mid-job");
}

/*
 * Order matters here, and it is not alphabetical.
 *
 * Graduation runs FIRST so a user whose two weeks ended overnight is 'active' by
 * the time plan generation looks at them, and gets their first real plan in the
 * same sweep rather than waiting a week for the next one.
 */
$jobs = [
    'baseline_graduation' => fn() => jobBaselineGraduation($onlyUser, $dryRun),
    'weekly_plan'         => fn() => jobWeeklyPlan($onlyUser, $dryRun),
    'weekly_checkin'      => fn() => jobWeeklyCheckin($onlyUser, $dryRun),
];

$exit = 0;
foreach ($jobs as $jobName => $runner) {
    if ($onlyJob !== null && $onlyJob !== $jobName) {
        continue;
    }
    say("{$jobName}:", !$quiet);
    try {
        $r = $runner();
        $parts = [];
        foreach ($r as $k => $v) {
            if ($v > 0) {
                $parts[] = "{$k}={$v}";
            }
        }
        say("  {$jobName} done" . ($parts !== [] ? ' — ' . implode(' ', $parts) : ' — nothing to do'),
            $parts !== [] || !$quiet);
        if (($r['failed'] ?? 0) > 0) {
            $exit = 1;
        }
    } catch (Throwable $e) {
        // A whole job blowing up must not take the remaining jobs with it.
        say("  {$jobName} ABORTED — " . $e->getMessage());
        error_log("[yoked cron] job {$jobName} aborted: " . $e->getMessage());
        $exit = 1;
    }
}

// Cost visibility: a runaway loop shows up here before it shows up on a bill.
if (!$dryRun && !$quiet) {
    try {
        DB::ensureConnected();
        $today = Claude::usageSummary(1);
        say(sprintf('spend today: ~$%s across %d purpose group(s)',
            number_format((float) $today['est_total'], 4), count($today['by_purpose'])));
    } catch (Throwable $e) {
        // Reporting must never fail the run.
        error_log('[yoked cron] usage summary failed: ' . $e->getMessage());
    }
}

$elapsed = round(microtime(true) - $startedAll, 1);
say("cron done in {$elapsed}s", !$quiet || $exit !== 0);
exit($exit);
