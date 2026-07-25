<?php
declare(strict_types=1);

/**
 * CSRF tokens. Carried over from Friendspace unchanged.
 *
 * Verified GLOBALLY in api/index.php before any routing, so a new route cannot
 * forget to check it. That placement is the whole point — per-route checks are
 * one omission away from a hole.
 */
final class Csrf
{
    /** Current token, creating one if the session lacks it. */
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /**
     * Verify the X-CSRF-Token header on mutating requests.
     * Safe methods pass through untouched.
     */
    public static function verify(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $real = $_SESSION['csrf'] ?? '';
        // hash_equals, not ===, to avoid leaking the token through timing.
        if ($real === '' || !hash_equals($real, $sent)) {
            // 403, not 419. 419 is a Laravel convention, not a real HTTP status,
            // and nginx in front of PHP-FPM here rewrites unknown codes to 500 —
            // the body passed through while the status became a lie. Verified on
            // this host; do not "restore" 419.
            Response::error('Invalid or missing CSRF token. Fetch a new one from '
                . '/api/csrf and retry.', 403, ['csrf_failed' => true]);
        }
    }
}
