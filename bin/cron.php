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
require YK_SRC . '/lib/Nutrition.php';
require YK_SRC . '/lib/CheckIn.php';

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

        /*
         * Did they answer the check-in for the week that is ending?
         *
         * This is the whole reason the check-in moved to Saturday. When it is
         * answered the plan is built from it (§7.2: the check-in produces next
         * week's plan). When it is not, the plan generates anyway from logs and
         * history rather than stalling — a user who goes quiet still gets a week.
         */
        $endingWeek = Schedule::weekStart($tz);
        $answered   = $isBaseline ? null : CheckIn::answeredFor($userId, $endingWeek);

        $reason = $isBaseline
            ? 'provisional'
            : ($answered !== null
                ? 'check_in'
                : (Plans::hasEverHadPlan($userId) ? 'check_in' : 'initial'));

        $extra = [];
        if ($answered !== null) {
            // Handed to the generator explicitly. It already reads adherence from
            // logged_days, but the user's own words and their emphasis request are
            // not in any table it looks at.
            $extra['check_in'] = [
                'week'             => $endingWeek,
                'self_report'      => $answered['self_report'],
                'emphasis_request' => $answered['emphasis_request'],
                'weight_kg'        => $answered['weight_kg'],
                'review'           => $answered['claude_review'],
            ];
        }

        $note = $answered !== null ? ' with check-in' : ' without a check-in';

        if ($dryRun) {
            say("  {$name}: WOULD generate week of {$week} (reason={$reason}){$note}");
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
        say("  {$name}: generating week of {$week} (reason={$reason}){$note} …");

        try {
            $result = Plans::generateWeek($userId, $week, $reason, $extra);

            if ($result['ok']) {
                $secs = round(microtime(true) - $started, 1);
                say("  {$name}: ok — plan_version {$result['plan_version_id']} ({$secs}s)");
                finish($runId, 'ok', "plan_version {$result['plan_version_id']}", $started);
                $results['generated']++;

                // Record which plan came out of this check-in, so the history can
                // answer "what did that conversation produce" without guessing.
                if ($answered !== null) {
                    CheckIn::linkPlan((int) $answered['id'], (int) $result['plan_version_id']);
                }
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
 * Open a check-in for the week that is ending, at the user's Saturday slot.
 *
 * The timing is the whole point. This used to have no schedule at all, so it fired
 * on the first sweep after midnight Monday — SIX HOURS AFTER the Sunday 18:00 plan
 * it is supposed to inform. SPEC-coaching §7.2 says the check-in produces next
 * week's plan, and it could not, because the plan was already live.
 *
 * Now: Saturday 18:00 local, a day ahead of the plan slot, so the user has roughly
 * 24 hours to say something that will actually shape the coming week.
 *
 * The week it covers is the CURRENT one, which is not quite over. Saturday evening
 * is enough of a picture, and a user with something to add about Sunday can say so
 * in the written report.
 *
 * Cron creates these rather than waiting for the user to open the app: a quiet user
 * would otherwise never get re-planned, which is the failure mode the check-in
 * exists to catch.
 */
function jobWeeklyCheckin(?int $onlyUser, bool $dryRun): array
{
    $results = ['created' => 0, 'skipped' => 0, 'failed' => 0];

    $sql = 'SELECT u.id, u.display_name,
                   p.checkin_weekday, p.checkin_hour, p.timezone
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

    global $ignoreSchedule;

    foreach (DB::all($sql, $params) as $user) {
        $userId = (int) $user['id'];
        $name   = $user['display_name'];
        $tz     = $user['timezone'] ?? null;

        // The week ENDING, in the user's own zone. On a Saturday that is the
        // Monday five days back.
        $week = Schedule::weekStart($tz);

        $wantDay  = (int) $user['checkin_weekday'];
        $wantHour = (int) $user['checkin_hour'];
        if (!$ignoreSchedule && !Schedule::slotPassed($tz, $wantDay, $wantHour)) {
            say("  {$name}: check-in slot not reached (wants day {$wantDay} hour {$wantHour} "
                . ($tz ?? 'UTC') . ')', false);
            $results['skipped']++;
            continue;
        }

        $existing = CheckIn::find($userId, $week);
        if ($existing !== null) {
            say("  {$name}: check-in for {$week} already exists ({$existing['status']})", false);
            $results['skipped']++;
            continue;
        }

        // Only worth asking if they actually did something that week — otherwise
        // the check-in is a form nobody has anything to put in.
        $logged = (int) (DB::one(
            'SELECT COUNT(*) AS n FROM logged_days
             WHERE user_id = ? AND log_date >= ? AND log_date < DATE_ADD(?, INTERVAL 7 DAY)',
            [$userId, $week, $week]
        )['n'] ?? 0);

        if ($logged === 0) {
            say("  {$name}: nothing logged for {$week}; no check-in opened", false);
            $results['skipped']++;
            continue;
        }

        if ($dryRun) {
            say("  {$name}: WOULD open a check-in for {$week} ({$logged} days logged)");
            $results['created']++;
            continue;
        }

        $runId = claim('weekly_checkin', $userId, $week);
        if ($runId === false) {
            $results['skipped']++;
            continue;
        }

        $started = microtime(true);
        try {
            CheckIn::open($userId, $week);
            say("  {$name}: opened check-in for {$week}");
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
// Job 4 — review answered check-ins, and act on late ones
// ---------------------------------------------------------------------------

/**
 * Send answered check-ins to Claude, and alter the plan when a late one warrants it.
 *
 * Split from the answer itself so the user is not made to wait on a model call
 * while submitting a form. They submit, they get an acknowledgement, and the review
 * appears when it is ready.
 *
 * For a LATE check-in (answers arrived after the plan generated) Claude also decides
 * whether the plan has to change. Deliberately not a keyword heuristic: "broke my
 * leg" is obvious, but "knee felt off" and "work is getting busier" are judgment
 * calls, and the app does not get to decide what counts as serious.
 */
function jobCheckinReview(?int $onlyUser, bool $dryRun): array
{
    $results = ['reviewed' => 0, 'altered' => 0, 'banked' => 0, 'failed' => 0];

    // Answered but not yet reviewed. claude_review IS NULL is the marker, which is
    // also what makes a failed review retry on the next sweep.
    $sql = 'SELECT c.id, c.user_id, c.week_start, c.answered_late, u.display_name, p.timezone
            FROM weekly_checkins c
            JOIN users u    ON u.id = c.user_id
            JOIN profiles p ON p.user_id = c.user_id
            WHERE c.status = "completed"
              AND c.claude_review IS NULL
              AND u.status = "active"
              AND p.coaching_paused = 0';
    $params = [];
    if ($onlyUser !== null) {
        $sql .= ' AND c.user_id = ?';
        $params[] = $onlyUser;
    }
    $sql .= ' ORDER BY c.answered_at LIMIT 20';

    foreach (DB::all($sql, $params) as $row) {
        $checkinId = (int) $row['id'];
        $userId    = (int) $row['user_id'];
        $name      = $row['display_name'];
        $tz        = $row['timezone'] ?? null;
        $week      = (string) $row['week_start'];
        $late      = (int) $row['answered_late'] === 1;

        if ($dryRun) {
            say("  {$name}: WOULD review check-in for {$week}" . ($late ? ' (late)' : ''));
            $results['reviewed']++;
            continue;
        }

        // Period keyed on the check-in's week, so a retry next sweep is allowed
        // only after the previous claim is released or finished.
        $runId = claim('checkin_review', $userId, $week);
        if ($runId === false) {
            continue;
        }

        $started = microtime(true);
        try {
            $r = CheckIn::review($userId, $checkinId, $tz);
            if (!$r['ok']) {
                say("  {$name}: review FAILED — {$r['error']}");
                finish($runId, 'failed', (string) $r['error'], $started);
                $results['failed']++;
                continue;
            }

            $results['reviewed']++;
            say("  {$name}: reviewed check-in for {$week}");

            if (!$late) {
                finish($runId, 'ok', 'reviewed', $started);
                continue;
            }

            // A late check-in either changes the plan or does not. Both outcomes
            // are recorded: "we looked and it was fine" is information.
            if (!$r['alter_plan']) {
                CheckIn::recordLateOutcome($checkinId, 'banked', null);
                say("  {$name}: late check-in banked; the plan stands");
                finish($runId, 'ok', 'late, banked', $started);
                $results['banked']++;
                continue;
            }

            $planWeek = date('Y-m-d', strtotime($week . ' +7 days'));
            say("  {$name}: late check-in alters the plan for {$planWeek} …");

            $gen = Plans::generateWeek($userId, $planWeek, 'check_in', [
                // The specific fact Claude named, handed to the generator as an
                // instruction rather than making it re-read the whole check-in.
                'check_in_change' => (string) ($r['alter_reason'] ?? ''),
            ]);

            if ($gen['ok']) {
                CheckIn::recordLateOutcome($checkinId, 'altered', (int) $gen['plan_version_id']);
                say("  {$name}: plan superseded — plan_version {$gen['plan_version_id']}");
                finish($runId, 'ok', "altered, plan_version {$gen['plan_version_id']}", $started);
                $results['altered']++;
            } else {
                // The review stands even though the regeneration failed: the user
                // still gets their read on the week, and the outcome stays NULL so
                // the next sweep retries.
                $detail = (string) ($gen['error'] ?? 'generation failed');
                say("  {$name}: plan alteration FAILED — {$detail}");
                finish($runId, 'failed', $detail, $started);
                $results['failed']++;
            }
        } catch (Throwable $e) {
            say("  {$name}: ERROR — " . $e->getMessage());
            error_log('[yoked cron] check-in review failed for #' . $checkinId
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
// Job 5 — nudge unanswered check-ins
// ---------------------------------------------------------------------------

/**
 * Chase a pending check-in (§ resolved item 2: "cron creates it, nudges if
 * unanswered").
 *
 * Without this a user who ignores the check-in is silently re-planned without it
 * forever, which is the exact failure the check-in was added to prevent.
 *
 * No Claude call: it stamps a counter that the app reads. Escalation intensity is
 * the user's own `nudge_intensity` setting, and 'leave_me_alone' means exactly
 * that — one nudge and then silence, because "I hate noisy apps" is in the scoping
 * and a nudge that keeps coming is how an app gets deleted.
 */
function jobCheckinNudge(?int $onlyUser, bool $dryRun): array
{
    $results = ['nudged' => 0, 'quiet' => 0];

    // Per-intensity ceiling on how many times we will ask.
    $maxNudges = [
        'leave_me_alone' => 1,
        'gentle'         => 2,
        'persistent'     => 4,
        'relentless'     => 7,
    ];

    $sql = 'SELECT c.id, c.user_id, c.week_start, c.nudge_count, c.last_nudged_at,
                   u.display_name, p.nudge_intensity, p.timezone
            FROM weekly_checkins c
            JOIN users u    ON u.id = c.user_id
            JOIN profiles p ON p.user_id = c.user_id
            WHERE c.status = "pending"
              AND u.status = "active"
              AND p.coaching_paused = 0';
    $params = [];
    if ($onlyUser !== null) {
        $sql .= ' AND c.user_id = ?';
        $params[] = $onlyUser;
    }

    foreach (DB::all($sql, $params) as $row) {
        $name      = $row['display_name'];
        $count     = (int) $row['nudge_count'];
        $intensity = (string) ($row['nudge_intensity'] ?? 'gentle');
        $ceiling   = $maxNudges[$intensity] ?? 2;

        if ($count >= $ceiling) {
            $results['quiet']++;
            continue;
        }

        // At most one a day. A form asked about twice in an afternoon is nagging,
        // and the whole nudge design is built around not being that.
        if ($row['last_nudged_at'] !== null
            && strtotime((string) $row['last_nudged_at']) > time() - 20 * 3600) {
            $results['quiet']++;
            continue;
        }

        if ($dryRun) {
            say("  {$name}: WOULD nudge check-in for {$row['week_start']} "
                . '(' . ($count + 1) . " of {$ceiling}, {$intensity})");
            $results['nudged']++;
            continue;
        }

        DB::run(
            'UPDATE weekly_checkins
                SET nudge_count = nudge_count + 1, last_nudged_at = NOW()
              WHERE id = ? AND status = "pending"',
            [(int) $row['id']]
        );
        say("  {$name}: nudged for {$row['week_start']} (" . ($count + 1) . " of {$ceiling})");
        $results['nudged']++;
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
/*
 * Order matters here, and it is not alphabetical.
 *
 *   graduation  first, so a user whose two weeks ended overnight is 'active' by the
 *               time plan generation looks at them and gets their real plan in the
 *               same sweep rather than waiting a week.
 *   checkin     BEFORE plan, so a Saturday-opened check-in exists to be answered
 *               before Sunday's generation reads it.
 *   review      before plan, so an answered check-in has been through Claude and
 *               any emphasis grant is on file when the plan is built.
 *   plan        then generation, with whatever input arrived.
 *   nudge       last: it is bookkeeping, and chasing a check-in that this sweep
 *               just answered would be absurd.
 */
$jobs = [
    'baseline_graduation' => fn() => jobBaselineGraduation($onlyUser, $dryRun),
    'weekly_checkin'      => fn() => jobWeeklyCheckin($onlyUser, $dryRun),
    'checkin_review'      => fn() => jobCheckinReview($onlyUser, $dryRun),
    'weekly_plan'         => fn() => jobWeeklyPlan($onlyUser, $dryRun),
    'checkin_nudge'       => fn() => jobCheckinNudge($onlyUser, $dryRun),
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
