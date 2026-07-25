<?php
declare(strict_types=1);

/**
 * Report seed-data state, and optionally clear it so migration 005 can be
 * re-applied cleanly.
 *
 * Exists because MySQL DDL/DML in a migration is not transactional: a
 * migration that fails partway leaves earlier statements applied but records
 * nothing in schema_migrations, so a re-run collides with its own rows. That
 * is documented in docs/DEPLOY.md; this is the fix-forward tool for it.
 *
 *   php bin/seedcheck.php               report
 *   php bin/seedcheck.php --clear       delete seed rows + un-record 005
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$clear = in_array('--clear', array_slice($argv, 1), true);

/** Row count, or null when the table does not exist yet. */
function tally(string $table): ?int
{
    try {
        return (int) (DB::one("SELECT COUNT(*) AS n FROM `{$table}`")['n'] ?? 0);
    } catch (PDOException $e) {
        return null;
    }
}

$tables = ['exercises', 'exercise_aliases', 'goal_presets'];

if ($clear) {
    echo "clearing seed data\n";
    DB::tx(function () use ($tables): void {
        // goals.goal_preset_id references goal_presets; null it first so the
        // delete is not blocked by the FK.
        try {
            DB::run('UPDATE goals SET goal_preset_id = NULL WHERE goal_preset_id IS NOT NULL');
        } catch (PDOException $e) {
            // Column only exists once 005 has added it; nothing to clear.
        }
        // Aliases and prescriptions reference exercises. Prescriptions use
        // ON DELETE RESTRICT deliberately, so bail loudly rather than
        // cascading away real plan data.
        $inUse = tally('prescribed_exercises');
        if ($inUse !== null && $inUse > 0) {
            throw new RuntimeException(
                "refusing to clear: {$inUse} prescribed_exercises row(s) reference the library"
            );
        }
        DB::run('DELETE FROM exercise_aliases');
        DB::run('DELETE FROM exercises');
        try {
            DB::run('DELETE FROM goal_presets');
        } catch (PDOException $e) {
            // Table may not exist if 005 failed before creating it.
        }
        DB::run("DELETE FROM schema_migrations WHERE filename = '005_seed.sql'");
    });
    echo "cleared. re-run: php bin/migrate.php\n\n";
}

printf("%-22s %s\n", 'table', 'rows');
foreach ($tables as $t) {
    $n = tally($t);
    printf("%-22s %s\n", $t, $n === null ? '(no such table)' : (string) $n);
}

// 005 also adds goals.goal_preset_id — worth reporting separately, since the
// table can exist while the ALTER never ran.
$hasCol = DB::one(
    "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goals'
       AND COLUMN_NAME = 'goal_preset_id'"
);
printf("\n%-22s %s\n", 'goals.goal_preset_id',
    (int) ($hasCol['n'] ?? 0) > 0 ? 'present' : 'MISSING');

$recorded = DB::one("SELECT applied_at FROM schema_migrations WHERE filename = '005_seed.sql'");
printf("%-22s %s\n", '005 recorded',
    $recorded === null ? 'no' : 'yes (' . $recorded['applied_at'] . ')');

if ($recorded === null && (tally('exercises') ?? 0) > 0) {
    echo "\nInconsistent: seed rows exist but 005 is not recorded — a previous run\n";
    echo "failed partway. Run: php bin/seedcheck.php --clear\n";
    exit(1);
}
echo "\nOK\n";
