<?php
declare(strict_types=1);

/**
 * Remove coaching artifacts produced by bugs that are now fixed.
 *
 * Three bugs shipped between 2026-07-25 22:30 and the next deploy, and all three
 * wrote rows a user can see:
 *
 *   1. Drift counted days from before the account existed, so a same-day signup was
 *      told they had been silent for seven days.
 *   2. The weekly check-in job ignored the observation week, so a user still in
 *      baseline got a "your coach on last week" review for a week they were not
 *      present for.
 *   3. Review prompts rendered stored metric with hardcoded "kg"/"cm", so an imperial
 *      user's review quoted kilograms at them.
 *
 * The code fixes stop new ones. This clears what already landed, because a wrong
 * nudge sitting on the screen is indistinguishable from the bug still being there.
 *
 *   php bin/clear-stale.php --dry-run
 *   php bin/clear-stale.php
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

/*
 * Test fixtures are exempt.
 *
 * bin/seed-uitest.php deliberately seeds a nudge and a drift question against a
 * same-day account so the browser suite has something to assert on. Those are
 * correct-by-intent and deleting them would just break the suite on the next run.
 */
const SKIP_USERS = "u.username NOT LIKE 'uitest_%' AND u.username NOT LIKE '%test_%'";

/**
 * Drift questions and absence nudges raised against a user who had barely arrived.
 *
 * Scoped by comparing the artifact to the account it belongs to rather than by a
 * hardcoded date: anything claiming absence for a user who could not yet have been
 * absent that long is wrong by construction, whenever it was written.
 */
$badNotifications = DB::all(
    'SELECT n.id, n.user_id, u.username, n.type, n.created_at, n.body,
            DATEDIFF(DATE(n.created_at), DATE(u.created_at)) AS account_age_days
     FROM notifications n
     JOIN users u ON u.id = n.user_id
     WHERE n.type IN ("absence", "drift_question")
       AND DATEDIFF(DATE(n.created_at), DATE(u.created_at)) < 3
       AND ' . SKIP_USERS
);

echo "-- notifications raised against accounts younger than 3 days --\n";
foreach ($badNotifications as $n) {
    printf(
        "  #%-4d %-15s %-14s age=%dd  %s\n",
        $n['id'], $n['username'], $n['type'], $n['account_age_days'],
        mb_substr((string) $n['body'], 0, 70)
    );
}
if ($badNotifications === []) {
    echo "  (none)\n";
}

// The chat turns behind those questions, so the conversation does not resurface.
$badTurns = DB::all(
    'SELECT c.id, c.user_id, u.username, c.created_at
     FROM chat_turns c
     JOIN users u ON u.id = c.user_id
     WHERE c.drift_state IS NOT NULL
       AND DATEDIFF(DATE(c.created_at), DATE(u.created_at)) < 3
       AND ' . SKIP_USERS
);
echo "\n-- drift chat turns on accounts younger than 3 days --\n";
foreach ($badTurns as $t) {
    printf("  #%-4d %-15s %s\n", $t['id'], $t['username'], $t['created_at']);
}
if ($badTurns === []) {
    echo "  (none)\n";
}

/**
 * Check-ins opened for a week that ended before the user's baseline even started.
 *
 * Deleted rather than marked skipped: the review inside them was written about a week
 * the user was not there for, so it is not information worth keeping. A genuine
 * check-in will open at their next Saturday slot.
 */
$badCheckins = DB::all(
    'SELECT c.id, c.user_id, u.username, c.week_start, c.status, u.baseline_starts_on
     FROM weekly_checkins c
     JOIN users u ON u.id = c.user_id
     WHERE u.baseline_starts_on IS NOT NULL
       AND c.week_start < u.baseline_starts_on
       AND ' . SKIP_USERS
);
echo "\n-- check-ins for weeks before the baseline began --\n";
foreach ($badCheckins as $c) {
    printf(
        "  #%-4d %-15s week=%s status=%-10s baseline starts %s\n",
        $c['id'], $c['username'], $c['week_start'], $c['status'], $c['baseline_starts_on']
    );
}
if ($badCheckins === []) {
    echo "  (none)\n";
}

if ($dryRun) {
    echo "\n(dry run, nothing deleted)\n";
    exit(0);
}

$deleted = 0;
foreach ([['notifications', $badNotifications], ['chat_turns', $badTurns],
          ['weekly_checkins', $badCheckins]] as [$table, $rows]) {
    foreach ($rows as $r) {
        DB::run("DELETE FROM {$table} WHERE id = ?", [(int) $r['id']]);
        $deleted++;
    }
}

printf("\ndeleted %d row(s)\n", $deleted);
