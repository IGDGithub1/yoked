<?php
declare(strict_types=1);

/**
 * CLI bootstrap — for bin/migrate.php and bin/cron.php.
 *
 * No sessions, no output buffering, errors to stderr. Exits non-zero on
 * failure so cron and CI can detect it.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

define('YK_ROOT', dirname(__DIR__));
define('YK_SRC', __DIR__);

// All server-side time is UTC; clients render in their own zone.
date_default_timezone_set('UTC');

$configFile = YK_SRC . '/config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "Not configured: copy src/config.example.php to src/config.php.\n");
    exit(1);
}
$GLOBALS['yk_config'] = require $configFile;

/** Dot-path config lookup: yk_config('db.host'). */
function yk_config(string $key, $default = null)
{
    $parts = explode('.', $key);
    $val = $GLOBALS['yk_config'];
    foreach ($parts as $p) {
        if (!is_array($val) || !array_key_exists($p, $val)) {
            return $default;
        }
        $val = $val[$p];
    }
    return $val;
}

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
ini_set('log_errors', '1');

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, '[yoked] ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
});

require YK_SRC . '/lib/DB.php';
// Scheduling is needed by cron and by every suite that reasons about a user's
// week, and it has no dependencies of its own.
require YK_SRC . '/lib/Validate.php';
require YK_SRC . '/lib/Schedule.php';
require YK_SRC . '/lib/Baseline.php';
require YK_SRC . '/lib/Tone.php';
