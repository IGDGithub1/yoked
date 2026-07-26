<?php
declare(strict_types=1);

/**
 * Yoked maintenance cron. Runs every 15 minutes from SiteGround.
 *
 *   /usr/local/bin/php /home/customer/www/yoked.lil-boxes.com/bin/cron.php
 *
 * Jobs, each independently guarded, in the order the sweep runs them:
 *   1. baseline_graduation — move finished two-week baselines to 'active'
 *   2. weekly_checkin      — open a check-in at the user's Saturday slot
 *   3. checkin_review      — send answered check-ins to Claude; act on late ones
 *   4. weekly_plan         — generate next week, using the check-in if answered
 *   5. drift_sweep         — classify recent days; ask questions only on real drift
 *   6. chat_replies        — answer what users have said (§6)
 *   7. vetoes              — decide what users have turned down (§5)
 *   8. checkin_nudge       — chase an unanswered check-in
 *
 * The order is load-bearing and commented at the $jobs array.
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
require YK_SRC . '/lib/BuddySchedule.php';   // Safety::checkAvailability reads it
require YK_SRC . '/lib/Safety.php';
require YK_SRC . '/lib/Plans.php';
require YK_SRC . '/lib/Nutrition.php';
require YK_SRC . '/lib/CheckIn.php';
require YK_SRC . '/lib/Notify.php';
require YK_SRC . '/lib/Drift.php';
require YK_SRC . '/lib/Nudge.php';
require YK_SRC . '/lib/Chat.php';
/*
 * Vetoes, for jobVetoes.
 *
 * Named explicitly because bootstrap_cli.php loads only the low-level libs — the
 * request-path bootstrap.php is what pulls in the rest, and cron does not use it. Without
 * this line the dry run still passes (it finds no pending rows and never names the class)
 * and the first real veto dies on an undefined class inside the sweep.
 */
require YK_SRC . '/lib/Vetoes.php';

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

    $sql = 'SELECT u.id, u.display_name, u.onboarding_state,
                   u.baseline_starts_on, u.baseline_ends_on,
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

        /*
         * No check-in during the observation week.
         *
         * A user whose baseline has not started, or is still in week 1, has nothing to
         * report ON: there is no plan to have adhered to and no targets to have hit.
         * Asking anyway produced a real "Your coach on last week" card for an account
         * created the same day, reviewing a week the user was not present for. The
         * same guard already governs plan generation, and it belongs here too.
         */
        if (Baseline::inObservationWeek($user, $tz)) {
            $p = Baseline::progress($user, $tz);
            $where = ($p !== null && !$p['started'])
                ? "baseline opens {$p['starts_on']}"
                : 'baseline week 1';
            say("  {$name}: observing ({$where}); no check-in yet", false);
            $results['skipped']++;
            continue;
        }

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
// Job 5 — daily drift sweep and absence nudges
// ---------------------------------------------------------------------------

/**
 * Classify every active user's recent days, and act only when it is warranted.
 *
 * This is the main cost-control mechanism in the app (SPEC-coaching §4.2), and the
 * important thing about it is how OFTEN it does nothing. An on-track user produces no
 * model call, no notification, and no row. A user with minor drift produces the same
 * nothing, deliberately: one missed session aggregates for the weekly check-in rather
 * than becoming a conversation now.
 *
 * Only two states act:
 *   significant  → Claude asks a question (§7.1), once per rough patch
 *   absent       → a nudge, escalating per the user's own settings (§9)
 *
 * Claimed per user per DAY rather than per week, since this is the daily pass. The
 * claim is what stops a sweep running every 15 minutes from asking 96 times.
 */
function jobDriftSweep(?int $onlyUser, bool $dryRun): array
{
    $results = ['on_track' => 0, 'minor' => 0, 'asked' => 0, 'nudged' => 0,
                'quiet' => 0, 'failed' => 0];

    $sql = 'SELECT u.id, u.display_name, u.onboarding_state,
                   u.baseline_starts_on, u.baseline_ends_on, p.timezone
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
        $tz     = $user['timezone'] ?? null;

        try {
            $a = Drift::assess($user, $tz);

            // The quiet majority. Reported only in verbose mode, because a line per
            // user per sweep is how a log becomes unreadable.
            if ($a['state'] === Drift::ON_TRACK) {
                say("  {$name}: on track ({$a['reason']})", false);
                $results['on_track']++;
                continue;
            }

            if ($a['state'] === Drift::MINOR) {
                // Real, noted, and deliberately not acted on: §4.2 aggregates minor
                // drift for the weekly check-in. The check-in prompt reads the same
                // logged_days rows, so nothing has to be stored for it to land.
                say("  {$name}: minor drift ({$a['reason']}) — noted for the check-in", false);
                $results['minor']++;
                continue;
            }

            if ($a['state'] === Drift::ABSENT) {
                if ($dryRun) {
                    say("  {$name}: WOULD nudge — {$a['reason']}");
                    $results['nudged']++;
                    continue;
                }
                $id = Nudge::forAbsence($userId, $a);
                if ($id === null) {
                    // Below their threshold, past their ceiling, or already sent
                    // today. All three are correct silences.
                    say("  {$name}: absent ({$a['reason']}) but no nudge due", false);
                    $results['quiet']++;
                } else {
                    say("  {$name}: nudged — {$a['reason']}");
                    $results['nudged']++;
                }
                continue;
            }

            // Significant. The one path that spends money.
            if (Drift::alreadyAsked($userId)) {
                say("  {$name}: significant drift, already asked this week", false);
                $results['quiet']++;
                continue;
            }

            if ($dryRun) {
                say("  {$name}: WOULD ask about drift — {$a['reason']}");
                $results['asked']++;
                continue;
            }

            $period = Schedule::today($tz);
            $runId  = claim('drift_sweep', $userId, $period);
            if ($runId === false) {
                $results['quiet']++;
                continue;
            }

            $started = microtime(true);
            $turnId  = Drift::ask($userId, $a);
            if ($turnId === null) {
                say("  {$name}: drift question FAILED to generate");
                finish($runId, 'failed', 'generation failed', $started);
                $results['failed']++;
            } else {
                say("  {$name}: asked about drift — {$a['reason']}");
                finish($runId, 'ok', "chat_turn {$turnId}: {$a['reason']}", $started);
                $results['asked']++;
            }
        } catch (Throwable $e) {
            say("  {$name}: ERROR — " . $e->getMessage());
            error_log('[yoked cron] drift sweep failed for user ' . $userId
                . ': ' . $e->getMessage());
            $results['failed']++;
        }
    }

    return $results;
}

// ---------------------------------------------------------------------------
// Job 6 — answer interjections
// ---------------------------------------------------------------------------

/**
 * Reply to what users have said (SPEC-coaching §6).
 *
 * Split from the POST so a user is not made to wait on a model call while sending a
 * message — and the call can be minutes when the outcome is a plan revision.
 *
 * This is also the structural boundary §6.1 asks for. The write path in routes/chat.php
 * cannot touch a plan; only Chat::evaluate() can, and only through a decision the model
 * returned as an enum that PHP then gates.
 *
 * Oldest first, and a few per sweep rather than all of them: a user who typed five things
 * gets five replies in order, and a fifteen-minute cadence means the queue drains fast
 * enough without one user's backlog holding up the rest of the sweep.
 */
function jobChatReplies(?int $onlyUser, bool $dryRun): array
{
    $results = ['answered' => 0, 'revised' => 0, 'failed' => 0];

    $sql = 'SELECT c.id, c.user_id, c.body, u.display_name
            FROM chat_turns c
            JOIN users u    ON u.id = c.user_id
            JOIN profiles p ON p.user_id = c.user_id
            WHERE c.role = "user" AND c.answered_at IS NULL
              AND u.status = "active"
              AND p.coaching_paused = 0';
    $params = [];
    if ($onlyUser !== null) {
        $sql .= ' AND c.user_id = ?';
        $params[] = $onlyUser;
    }
    $sql .= ' ORDER BY c.id LIMIT 10';

    foreach (DB::all($sql, $params) as $row) {
        $turnId = (int) $row['id'];
        $userId = (int) $row['user_id'];
        $name   = $row['display_name'];
        $said   = mb_substr((string) $row['body'], 0, 60);

        if ($dryRun) {
            say("  {$name}: WOULD answer turn #{$turnId} — \"{$said}\"");
            $results['answered']++;
            continue;
        }

        /*
         * No cron_runs claim here, unlike every other job, and that is deliberate.
         *
         * The claim exists to stop two overlapping sweeps doing the same work. For this
         * job the turn's own `answered_at` already does that: the query selects only
         * unanswered turns, and Chat::evaluate stamps it inside a transaction. A claim
         * would add a cron_runs row per message — the table is a log of scheduled work,
         * and a chatty user would bury the weekly jobs in it.
         *
         * The cost of the race it does not guard: two sweeps 15 minutes apart could both
         * pick up a turn whose evaluation is still running, producing a duplicate reply.
         * A model call plus generation can exceed 15 minutes, so that is real — and the
         * guard is that generation is itself claimed per (user, week), so the second
         * attempt's revision fails and downgrades rather than writing a second plan.
         */
        try {
            $r = Chat::evaluate($userId, $turnId);
            if (!$r['ok']) {
                say("  {$name}: reply FAILED — {$r['error']}");
                $results['failed']++;
                continue;
            }

            $note = $r['outcome'];
            if ($r['plan_version_id'] !== null) {
                $note .= ", plan_version {$r['plan_version_id']}";
                $results['revised']++;
                // Worth telling them: a plan that changed under a user without a word is
                // the kind of surprise that costs trust.
                Notify::create(
                    $userId,
                    'plan_ready',
                    'Your coach changed this week based on what you said.',
                    'plan_version',
                    (int) $r['plan_version_id'],
                    2
                );
            }
            say("  {$name}: answered turn #{$turnId} ({$note})");
            $results['answered']++;
        } catch (Throwable $e) {
            say("  {$name}: ERROR — " . $e->getMessage());
            error_log('[yoked cron] chat reply failed for turn ' . $turnId . ': '
                . $e->getMessage());
            $results['failed']++;
        }
    }

    return $results;
}


// ---------------------------------------------------------------------------
// Job 7 — decide pending vetoes
// ---------------------------------------------------------------------------

/**
 * Work through vetoes waiting on a decision (SPEC-coaching §5).
 *
 * The route records a refusal and returns; this decides it. An accepted veto regenerates
 * the week, which is minutes, so it cannot happen in the request — same reason chat replies
 * are swept rather than answered inline.
 *
 * LIMIT 5 rather than chat's 10, because the expensive branch here is more likely: an
 * accepted veto always regenerates, whereas most chat turns end in an acknowledgement. Five
 * regenerations is already a long run for one sweep, and the rest keep until the next.
 *
 * No cron_runs claim, for the same reason jobChatReplies has none: `outcome` moving off
 * 'pending' inside Vetoes::evaluate's transaction is itself the claim, and a row per veto
 * would bury the weekly jobs in a table that logs scheduled work.
 */
function jobVetoes(?int $onlyUser, bool $dryRun): array
{
    $results = ['decided' => 0, 'accepted' => 0, 'declined' => 0, 'failed' => 0];

    $sql = 'SELECT v.id, v.user_id, v.subject_type, v.reason_code, v.scope,
                   u.display_name
            FROM vetoes v
            JOIN users u    ON u.id = v.user_id
            JOIN profiles p ON p.user_id = v.user_id
            WHERE v.outcome = "pending"
              AND u.status = "active"
              AND p.coaching_paused = 0';
    $params = [];
    if ($onlyUser !== null) {
        $sql .= ' AND v.user_id = ?';
        $params[] = $onlyUser;
    }
    $sql .= ' ORDER BY v.id LIMIT 5';

    foreach (DB::all($sql, $params) as $row) {
        $vetoId = (int) $row['id'];
        $userId = (int) $row['user_id'];
        $name   = $row['display_name'];
        $what   = "{$row['subject_type']} [{$row['reason_code']}, {$row['scope']}]";

        if ($dryRun) {
            say("  {$name}: WOULD decide veto #{$vetoId} — {$what}");
            $results['decided']++;
            continue;
        }

        try {
            $r = Vetoes::evaluate($userId, $vetoId);
            if (!$r['ok']) {
                say("  {$name}: veto #{$vetoId} FAILED — {$r['error']}");
                $results['failed']++;
                continue;
            }

            $note = (string) $r['outcome'];
            if ($r['constraint_id'] !== null) {
                // §5.2. Worth logging separately: this is the one automated path that
                // writes a constraint, so it should never be invisible in the cron output.
                $note .= ", promoted to soft constraint {$r['constraint_id']}";
            }
            if ($r['plan_version_id'] !== null) {
                $note .= ", plan_version {$r['plan_version_id']}";
                /*
                 * Tell them. A plan that changed under a user without a word is the kind of
                 * surprise that costs trust, and unlike chat there is no transcript here for
                 * them to find the answer in.
                 */
                Notify::create(
                    $userId,
                    'plan_ready',
                    'Your coach swapped out what you turned down.',
                    'plan_version',
                    (int) $r['plan_version_id'],
                    2
                );
            } elseif ($r['outcome'] === 'declined') {
                /*
                 * A decline needs telling too, and more than an acceptance does: the user
                 * asked for something and did not get it, and silence would read as the
                 * request being lost rather than answered. §5.4 holds the line; it does not
                 * hide behind not-answering.
                 */
                Notify::create(
                    $userId,
                    'veto_decided',
                    'Your coach had a think about what you turned down.',
                    'veto',
                    $vetoId,
                    3
                );
            }

            say("  {$name}: veto #{$vetoId} {$note}");
            $results['decided']++;
            $results[$r['outcome'] === 'accepted' ? 'accepted' : 'declined']++;
        } catch (Throwable $e) {
            say("  {$name}: ERROR — " . $e->getMessage());
            error_log('[yoked cron] veto decision failed for veto ' . $vetoId . ': '
                . $e->getMessage());
            $results['failed']++;
        }
    }

    return $results;
}

// ---------------------------------------------------------------------------
// Job 8 — nudge unanswered check-ins
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
        // Shared with the absence ladder rather than a second copy of the numbers:
        // a user who asked to be left alone means it for both kinds of chasing.
        $ceiling   = Tone::nudgeCeiling($intensity);

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

        // A real notification now that Notify exists, rather than only a counter the
        // UI had to infer from. Plain rather than generated: unlike an absence nudge
        // this is a reminder about a form, and a tone-matched line about paperwork is
        // effort spent in the wrong place.
        Notify::create(
            (int) $row['user_id'],
            'checkin_open',
            'Your weekly check-in is waiting. It shapes next week, so it is worth two '
            . 'minutes.',
            'weekly_checkin',
            (int) $row['id'],
            20
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
 *   drift       after plan, so a user who just received a week is assessed against
 *               the week they actually lived rather than one that arrived seconds ago.
 *   chat        after drift, so a reply to a question the sweep just asked is not
 *               generated in the same pass that asked it.
 *   nudge       last: it is bookkeeping, and chasing a check-in that this sweep
 *               just answered would be absurd.
 */
$jobs = [
    'baseline_graduation' => fn() => jobBaselineGraduation($onlyUser, $dryRun),
    'weekly_checkin'      => fn() => jobWeeklyCheckin($onlyUser, $dryRun),
    'checkin_review'      => fn() => jobCheckinReview($onlyUser, $dryRun),
    'weekly_plan'         => fn() => jobWeeklyPlan($onlyUser, $dryRun),
    'drift_sweep'         => fn() => jobDriftSweep($onlyUser, $dryRun),
    'chat_replies'        => fn() => jobChatReplies($onlyUser, $dryRun),
    // After chat_replies: an interjection and a veto can both regenerate the same week, and
    // running them in a stable order makes a doubled-up sweep reproducible rather than a
    // coin toss over which revision lands last.
    'vetoes'              => fn() => jobVetoes($onlyUser, $dryRun),
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
