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
 *   php bin/migrate.php --reset --i-mean-it
 *                                  DROP every table, then re-apply from
 *                                  scratch. Development only — it destroys
 *                                  all data and refuses to run when
 *                                  config env is 'production' unless
 *                                  YOKED_ALLOW_RESET=1 is also set.
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
$reset   = in_array('--reset', $args, true);
$confirm = in_array('--i-mean-it', $args, true);

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
 * A single-pass character scanner, because the naive version (strip lines
 * beginning with --, explode on ';') breaks on a TRAILING comment that
 * contains a semicolon:
 *
 *     height_cm DECIMAL(5,1) NULL,   -- canonical metric; UI converts
 *                                                       ^ splits here
 *
 * That produced a 1064 on a perfectly valid CREATE TABLE. Handled here:
 *   * -- and # line comments, inline or full-line
 *   * C-style block comments
 *   * single- and double-quoted strings, with backslash escapes
 *   * backtick-quoted identifiers
 * Semicolons inside any of those are not statement terminators.
 *
 * Still DDL-only: no DELIMITER support, so triggers and stored procedures
 * would need more than this.
 */
function splitStatements(string $sql): array
{
    $statements = [];
    $buf   = '';
    $len   = strlen($sql);
    $quote = null;      // ', " or ` when inside a quoted run
    $i     = 0;

    while ($i < $len) {
        $ch   = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($quote !== null) {
            // Inside a quoted run: copy verbatim until the matching close.
            $buf .= $ch;
            if ($ch === '\\' && $quote !== '`') {
                // Escaped character: consume the next one too.
                if ($next !== '') {
                    $buf .= $next;
                    $i += 2;
                    continue;
                }
            } elseif ($ch === $quote) {
                $quote = null;
            }
            $i++;
            continue;
        }

        // -- comment (SQL requires whitespace after --, but be lenient) or #
        if (($ch === '-' && $next === '-') || $ch === '#') {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            $buf .= ' ';   // keep tokens either side apart
            continue;
        }

        // /* block comment */
        if ($ch === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i   = $end === false ? $len : $end + 2;
            $buf .= ' ';
            continue;
        }

        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $buf  .= $ch;
            $i++;
            continue;
        }

        if ($ch === ';') {
            $stmt = trim($buf);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buf = '';
            $i++;
            continue;
        }

        $buf .= $ch;
        $i++;
    }

    // Trailing statement with no terminating semicolon.
    $stmt = trim($buf);
    if ($stmt !== '') {
        $statements[] = $stmt;
    }

    return $statements;
}

// ---- reset (development only) ----------------------------------------------

if ($reset) {
    if (!$confirm) {
        fwrite(STDERR, "--reset destroys every table. Add --i-mean-it to confirm.\n");
        exit(1);
    }
    if (yk_config('env') === 'production' && getenv('YOKED_ALLOW_RESET') !== '1') {
        fwrite(STDERR, "Refusing to reset: env is 'production'.\n");
        fwrite(STDERR, "Set YOKED_ALLOW_RESET=1 if this really is a throwaway database.\n");
        exit(1);
    }

    // SHOW TABLES returns one column whose name varies by database, so read
    // the first value of each row. array_map('reset', ...) would work but
    // warns under PHP 8.4 — reset() needs a reference.
    $tables = array_map(static fn(array $row): string => (string) current($row),
                        DB::all('SHOW TABLES'));
    if ($tables) {
        echo "dropping " . count($tables) . " table(s)\n";
        // FK checks off so drop order doesn't matter.
        DB::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            DB::pdo()->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $t) . '`');
        }
        DB::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    } else {
        echo "no tables to drop\n";
    }
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
