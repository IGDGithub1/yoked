<?php
declare(strict_types=1);

/**
 * Remove the seeded test users.
 *
 * Test users are seeded by bin/test-plans.php with usernames prefixed
 * `plantest_`. That test cleans up after itself unless run with --keep, so this
 * exists for the --keep case and for residue from an interrupted run.
 *
 * Deliberately narrow: it only ever matches the `plantest_%` prefix. A
 * "delete users" script that can take a broader argument is one typo away from
 * deleting a real account.
 *
 *   php bin/drop-test-users.php            report what would go
 *   php bin/drop-test-users.php --confirm   delete
 */

require __DIR__ . '/../src/bootstrap_cli.php';

const TEST_PREFIX = 'plantest_';

$confirm = in_array('--confirm', array_slice($argv, 1), true);

$users = DB::all(
    'SELECT id, username, display_name, onboarding_state, created_at
     FROM users WHERE username LIKE ? ORDER BY id',
    [TEST_PREFIX . '%']
);

if ($users === []) {
    echo "No test users present.\n";
    exit(0);
}

printf("%d test user(s):\n", count($users));
foreach ($users as $u) {
    // Show what goes with them, so the blast radius is visible before the
    // delete rather than inferred after it.
    $id = (int) $u['id'];
    $plans = (int) (DB::one(
        'SELECT COUNT(*) AS n FROM plan_versions WHERE user_id = ?', [$id]
    )['n'] ?? 0);
    $sessions = (int) (DB::one(
        'SELECT COUNT(*) AS n FROM prescribed_sessions ps
         JOIN plan_versions pv ON pv.id = ps.plan_version_id
         WHERE pv.user_id = ?', [$id]
    )['n'] ?? 0);
    $calls = (int) (DB::one(
        'SELECT COUNT(*) AS n FROM ai_calls WHERE user_id = ?', [$id]
    )['n'] ?? 0);

    printf("  #%-4d %-16s %-14s %d plan(s), %d session(s), %d ai_call(s)\n",
        $id, $u['username'], $u['onboarding_state'], $plans, $sessions, $calls);
}

if (!$confirm) {
    echo "\nRe-run with --confirm to delete. FK cascades will take profiles,\n";
    echo "goals, availability, constraints, plans, prescriptions, logs and\n";
    echo "cron_runs with them. ai_calls rows survive with user_id set to NULL,\n";
    echo "so the spend history stays intact.\n";
    exit(0);
}

$deleted = DB::run('DELETE FROM users WHERE username LIKE ?', [TEST_PREFIX . '%'])->rowCount();
printf("\nDeleted %d user(s).\n", $deleted);

// Confirm the cascade actually fired rather than assuming it did.
$orphans = [
    'plan_versions'       => 'SELECT COUNT(*) AS n FROM plan_versions pv
                              LEFT JOIN users u ON u.id = pv.user_id WHERE u.id IS NULL',
    'profiles'            => 'SELECT COUNT(*) AS n FROM profiles p
                              LEFT JOIN users u ON u.id = p.user_id WHERE u.id IS NULL',
    'user_constraints'    => 'SELECT COUNT(*) AS n FROM user_constraints c
                              LEFT JOIN users u ON u.id = c.user_id WHERE u.id IS NULL',
    'prescribed_sessions' => 'SELECT COUNT(*) AS n FROM prescribed_sessions ps
                              LEFT JOIN plan_versions pv ON pv.id = ps.plan_version_id
                              WHERE pv.id IS NULL',
];
$dirty = false;
foreach ($orphans as $label => $sql) {
    $n = (int) (DB::one($sql)['n'] ?? 0);
    if ($n > 0) {
        printf("  WARNING: %d orphaned %s row(s)\n", $n, $label);
        $dirty = true;
    }
}
echo $dirty ? "Cascade incomplete — investigate.\n" : "Cascade clean; no orphans.\n";
