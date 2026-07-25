<?php
declare(strict_types=1);

/**
 * Migration runner.
 *
 * Applies pending .sql files from database/migrations in filename order and
 * records each in schema_migrations. Refuses to re-run an applied file.
 *
 * Friendspace had numbered migrations but no applied-log, and they were not
 * idempotent — which is how migration 010 got skipped in a deploy and nobody
 * noticed until something broke. This exists so that cannot happen here.
 *
 *   php bin/migrate.php            apply pending
 *   php bin/migrate.php --status   list applied/pending, apply nothing
 *   php bin/migrate.php --dry-run  show what would run
 *
 * Bootstrap 001 is special: schema_migrations doesn't exist until it runs, so
 * a missing tracker table is treated as "nothing applied yet" rather than an
 * error.
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$migrationsDir = __DIR__ . '/../database/migrations';

$args    = array_slice($argv, 1);
$status  = in_array('--status', $args, true);
$dryRun  = in_array('--dry-run', $args, true);

$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);   // 001_, 002_, … — zero-padded, so string sort is correct

if (!$files) {
    fwrite(STDERR, "No migration files found in {$migrationsDir}\n");
    exit(1);
}

/** Applied filenames, or [] if the tracker table doesn't exist yet. */
function appliedSet(): array
{
    try {
        $rows = DB::all('SELECT filename FROM schema_migrations');
    } catch (PDOException $e) {
        // 42S02 = base table not found: 001 hasn't run yet.
        if (($e->errorInfo[0] ?? '') === '42S02') {
            return [];
        }
        throw $e;
    }
    return array_column($rows, 'filename');
}

/**
 * Split a migration file into individual statements.
 *
 * Deliberately simple: strips -- line comments, splits on semicolons. Our
 * migrations are DDL only — no triggers, no stored procedures, no semicolons
 * inside string literals. If that ever changes, this needs a real parser
 * rather than a quiet bug.
 */
function splitStatements(string $sql): array
{
    $lines = [];
    foreach (explode("\n", $sql) as $line) {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '--')) {
            continue;
        }
        $lines[] = $line;
    }
    $clean = implode("\n", $lines);

    $out = [];
    foreach (explode(';', $clean) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $out[] = $stmt;
        }
    }
    return $out;
}

$applied = appliedSet();
$pending = [];
foreach ($files as $path) {
    if (!in_array(basename($path), $applied, true)) {
        $pending[] = $path;
    }
}

if ($status) {
    echo "Applied (" . count($applied) . "):\n";
    foreach ($applied as $f) {
        echo "  ✓ {$f}\n";
    }
    echo "Pending (" . count($pending) . "):\n";
    foreach ($pending as $p) {
        echo "  · " . basename($p) . "\n";
    }
    exit(0);
}

if (!$pending) {
    echo "Nothing to do — all " . count($applied) . " migration(s) applied.\n";
    exit(0);
}

foreach ($pending as $path) {
    $name = basename($path);
    $sql  = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, "Cannot read {$name}\n");
        exit(1);
    }

    $statements = splitStatements($sql);

    if ($dryRun) {
        echo "would apply {$name} (" . count($statements) . " statements)\n";
        continue;
    }

    echo "applying {$name} … ";

    // No transaction: MySQL DDL is not transactional, so a mid-file failure
    // leaves the earlier statements in place. That is why a failure here exits
    // immediately and loudly — the tracker is NOT written, so a re-run would
    // retry the whole file and hit "table already exists". Fix forward: either
    // drop the partial objects or split the file. Silently continuing would be
    // far worse than stopping.
    try {
        foreach ($statements as $i => $stmt) {
            try {
                DB::pdo()->exec($stmt);
            } catch (PDOException $e) {
                throw new RuntimeException(
                    "statement " . ($i + 1) . " of {$name} failed: " . $e->getMessage()
                    . "\n--- SQL ---\n" . substr($stmt, 0, 400),
                    0,
                    $e
                );
            }
        }
        DB::run('INSERT INTO schema_migrations (filename) VALUES (?)', [$name]);
        echo "ok (" . count($statements) . " statements)\n";
    } catch (Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, "\n" . $e->getMessage() . "\n\n");
        fwrite(STDERR, "Database may be partially migrated. Resolve before re-running.\n");
        exit(1);
    }
}

echo "Done.\n";
