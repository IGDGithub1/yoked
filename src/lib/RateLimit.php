<?php
declare(strict_types=1);

/**
 * Fixed-window rate limiter, keyed by an arbitrary bucket string.
 *
 * Carried over from Friendspace with one addition: `allow()`, a non-throwing
 * variant. The original only had `check()`, which emits a 429 and exits — fine
 * inside a web request, wrong inside cron (bin/cron.php would die mid-sweep
 * instead of skipping one user and continuing).
 */
final class RateLimit
{
    /**
     * Throwing form: emits a 429 and exits when the bucket is over its limit.
     * For use inside HTTP request handlers.
     */
    public static function check(string $bucket, int $max, int $windowSeconds): void
    {
        if (!self::allow($bucket, $max, $windowSeconds)) {
            Response::error('Too many attempts. Try again in a few minutes.', 429);
        }
    }

    /**
     * Non-throwing form: returns whether the call is permitted, and counts it
     * if so. For CLI and cron, where exiting the process is the wrong response
     * to one user hitting a cap.
     */
    public static function allow(string $bucket, int $max, int $windowSeconds): bool
    {
        $row = DB::one('SELECT window_start, hits FROM rate_limits WHERE bucket = ?', [$bucket]);
        $now = time();

        // No row, or the window has rolled over: start a fresh one.
        if ($row === null || strtotime($row['window_start']) < $now - $windowSeconds) {
            DB::run(
                'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, NOW(), 1)
                 ON DUPLICATE KEY UPDATE window_start = NOW(), hits = 1',
                [$bucket]
            );
            return true;
        }

        if ((int) $row['hits'] >= $max) {
            return false;
        }

        DB::run('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$bucket]);
        return true;
    }

    /** Hits used in the current window, for surfacing remaining quota. */
    public static function used(string $bucket, int $windowSeconds): int
    {
        $row = DB::one('SELECT window_start, hits FROM rate_limits WHERE bucket = ?', [$bucket]);
        if ($row === null || strtotime($row['window_start']) < time() - $windowSeconds) {
            return 0;
        }
        return (int) $row['hits'];
    }

    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
