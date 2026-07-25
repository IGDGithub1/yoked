<?php
declare(strict_types=1);

/**
 * Clear rate-limit buckets.
 *
 * The login and registration limits are deliberately tight (5/hour), which is
 * right in production and a nuisance when testing against the live host — a few
 * scripted sign-ins exhaust the bucket and every later run gets a 429 that looks
 * like a bug in whatever you were actually testing.
 *
 * Usage:
 *   php bin/clear-limits.php            login + register buckets
 *   php bin/clear-limits.php --all      every bucket, including the AI call cap
 */

require dirname(__DIR__) . '/src/bootstrap_cli.php';

if (($argv[1] ?? '') === '--all') {
    $n = DB::run('DELETE FROM rate_limits')->rowCount();
    echo "Cleared {$n} bucket(s) — all of them.\n";
    exit(0);
}

// Auth buckets only by default: the AI call cap is a cost control, not a test
// nuisance, so clearing it should be deliberate.
$n = DB::run(
    "DELETE FROM rate_limits WHERE bucket LIKE 'login:%' OR bucket LIKE 'register:%'"
)->rowCount();

echo "Cleared {$n} auth bucket(s). Use --all to include the AI call cap.\n";
