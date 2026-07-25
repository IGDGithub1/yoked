<?php
declare(strict_types=1);

/**
 * Bootstrap for API requests. Included once by public_html/api/index.php.
 *
 * Mirrors bootstrap_cli.php but adds sessions and routes errors to a JSON
 * response instead of stderr.
 */

define('YK_ROOT', dirname(__DIR__));
define('YK_SRC', __DIR__);

// All server-side time is UTC; the client renders in the viewer's zone.
date_default_timezone_set('UTC');

$configFile = YK_SRC . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server is not configured.']);
    exit;
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

// ---- Error handling --------------------------------------------------------

error_reporting(E_ALL);
// Never render errors into the response body — that is how stack traces and
// credentials leak. They go to the log; the client gets JSON.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(function (Throwable $e): void {
    error_log('[yoked] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $body = ['error' => 'Something went wrong on our end.'];
    if (yk_config('env') !== 'production') {
        $body['detail'] = $e->getMessage();
        $body['where']  = $e->getFile() . ':' . $e->getLine();
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
});

// A fatal error bypasses the exception handler entirely, so without this the
// client gets a blank 200 and no clue what happened.
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    error_log('[yoked] FATAL ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Something went wrong on our end.']);
    }
});

// ---- Sessions --------------------------------------------------------------

session_name((string) yk_config('session.name', 'yk_session'));
// Keep server-side session data alive as long as the cookie. The remember-me
// token is the real durability mechanism, but this avoids premature GC.
ini_set('session.gc_maxlifetime', (string) yk_config('session.lifetime', 2592000));
session_set_cookie_params([
    'lifetime' => (int) yk_config('session.lifetime', 2592000),
    'path'     => '/',
    'domain'   => '',
    'secure'   => (bool) yk_config('session.secure', true),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// ---- Core libs -------------------------------------------------------------

require YK_SRC . '/lib/DB.php';
require YK_SRC . '/lib/Response.php';
require YK_SRC . '/lib/Validate.php';
require YK_SRC . '/lib/Schedule.php';
require YK_SRC . '/lib/Baseline.php';
require YK_SRC . '/lib/Tone.php';
require YK_SRC . '/lib/Auth.php';
require YK_SRC . '/lib/Csrf.php';
require YK_SRC . '/lib/RateLimit.php';
require YK_SRC . '/lib/Goals.php';
require YK_SRC . '/lib/Claude.php';
require YK_SRC . '/lib/PlanSchema.php';
require YK_SRC . '/lib/Safety.php';
require YK_SRC . '/lib/Plans.php';
require YK_SRC . '/lib/Onboarding.php';
require YK_SRC . '/lib/Nutrition.php';
require YK_SRC . '/lib/FoodSearch.php';
require YK_SRC . '/lib/Training.php';
require YK_SRC . '/lib/CheckIn.php';
require YK_SRC . '/lib/Notify.php';
require YK_SRC . '/lib/Drift.php';
require YK_SRC . '/lib/Nudge.php';
