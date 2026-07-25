<?php
declare(strict_types=1);

/**
 * Session auth with persistent remember-me tokens.
 *
 * Carried over from Friendspace with the Yoked cookie name and one addition:
 * onboardingState(), since Yoked gates plan generation on onboarding progress
 * in a way Friendspace had no equivalent for.
 *
 * The remember-me design is selector:validator with the validator stored only
 * as a hash — so a leaked auth_tokens table does not yield usable cookies. A
 * selector match with a validator mismatch is treated as theft and the token is
 * destroyed rather than merely rejected.
 */
final class Auth
{
    private static ?array $userCache = null;

    private const REMEMBER_COOKIE = 'yk_remember';
    private const REMEMBER_TTL    = 60 * 60 * 24 * 60;   // 60 days

    /** The logged-in user row, or null. */
    public static function user(): ?array
    {
        if (self::$userCache !== null) {
            return self::$userCache;
        }

        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            // No session — try to restore from a persistent token. This is what
            // makes the installed PWA feel native: open it and you are in.
            $id = self::tryRememberLogin();
            if (!$id) {
                return null;
            }
        }

        $user = DB::one(
            'SELECT id, username, display_name, email, avatar_media_id, role,
                    status, onboarding_state, created_at
             FROM users WHERE id = ?',
            [$id]
        );

        if ($user === null) {
            // Session points at a deleted user.
            self::logout();
            return null;
        }
        if (($user['status'] ?? 'active') === 'suspended') {
            // A suspended account must not keep working off an existing session.
            self::logout();
            return null;
        }

        self::$userCache = $user;
        return $user;
    }

    /** Guard: require a logged-in user, return them. */
    public static function require(): array
    {
        $user = self::user();
        if ($user === null) {
            Response::error('You need to be logged in.', 401);
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::require();
        if ($user['role'] !== 'admin') {
            Response::error('Admin access required.', 403);
        }
        return $user;
    }

    /**
     * Guard: require onboarding to have reached at least $state.
     *
     * Yoked-specific. Plan generation is meaningless before the profile exists,
     * so routes that depend on it say so rather than failing deeper in with a
     * confusing error.
     */
    public static function requireOnboarding(string $atLeast = 'baseline'): array
    {
        $user  = self::require();
        $order = ['pending' => 0, 'in_progress' => 1, 'baseline' => 2, 'active' => 3];

        $have = $order[$user['onboarding_state']] ?? 0;
        $want = $order[$atLeast] ?? 0;

        if ($have < $want) {
            Response::error('Finish setting up your profile first.', 403, [
                'onboarding_state' => $user['onboarding_state'],
                'required_state'   => $atLeast,
            ]);
        }
        return $user;
    }

    public static function login(int $userId): void
    {
        // New session id on privilege change — standard fixation defense.
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['csrf']    = bin2hex(random_bytes(32));
        self::$userCache = null;

        DB::run('UPDATE users SET last_seen_at = NOW() WHERE id = ?', [$userId]);
        self::issueRememberToken($userId);
    }

    public static function logout(): void
    {
        self::clearRememberToken();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$userCache = null;
    }

    /* ---- persistent remember-me (selector : validator) ------------------- */

    private static function rememberCookieParams(int $expires): array
    {
        return [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (bool) yk_config('session.secure', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    public static function issueRememberToken(int $userId): void
    {
        $selector  = bin2hex(random_bytes(16));   // 32 chars
        $validator = bin2hex(random_bytes(32));   // 64 chars
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::REMEMBER_TTL);

        // Only the hash is stored. A dumped auth_tokens table yields nothing
        // usable on its own.
        DB::run(
            'INSERT INTO auth_tokens (user_id, selector, token_hash, expires_at)
             VALUES (?, ?, ?, ?)',
            [$userId, $selector, hash('sha256', $validator), $expiresAt]
        );

        setcookie(self::REMEMBER_COOKIE, $selector . ':' . $validator,
            self::rememberCookieParams(time() + self::REMEMBER_TTL));
    }

    /**
     * Validate the remember cookie and re-establish the session.
     *
     * Sliding expiry, no rotation: rotating on every use races with the
     * parallel requests an app makes on cold load, and would log the user out
     * at random.
     */
    public static function tryRememberLogin(): ?int
    {
        $raw = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if ($raw === '' || !str_contains($raw, ':')) {
            return null;
        }

        [$selector, $validator] = explode(':', $raw, 2);
        if (strlen($selector) !== 32 || strlen($validator) !== 64) {
            self::forgetRememberCookie();
            return null;
        }

        $row = DB::one('SELECT * FROM auth_tokens WHERE selector = ?', [$selector]);
        if ($row === null) {
            self::forgetRememberCookie();
            return null;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            DB::run('DELETE FROM auth_tokens WHERE id = ?', [(int) $row['id']]);
            self::forgetRememberCookie();
            return null;
        }
        if (!hash_equals((string) $row['token_hash'], hash('sha256', $validator))) {
            // Selector matched, validator did not: someone is guessing, or an
            // old cookie is being replayed. Destroy the token either way.
            DB::run('DELETE FROM auth_tokens WHERE id = ?', [(int) $row['id']]);
            self::forgetRememberCookie();
            return null;
        }

        $userId = (int) $row['user_id'];

        $newExpiry = gmdate('Y-m-d H:i:s', time() + self::REMEMBER_TTL);
        DB::run(
            'UPDATE auth_tokens SET expires_at = ?, last_used_at = NOW() WHERE id = ?',
            [$newExpiry, (int) $row['id']]
        );
        setcookie(self::REMEMBER_COOKIE, $raw,
            self::rememberCookieParams(time() + self::REMEMBER_TTL));

        // Fixation defense on restore, without minting a new token.
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['csrf']    = bin2hex(random_bytes(32));

        return $userId;
    }

    public static function clearRememberToken(): void
    {
        $raw = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if ($raw !== '' && str_contains($raw, ':')) {
            [$selector] = explode(':', $raw, 2);
            DB::run('DELETE FROM auth_tokens WHERE selector = ?', [$selector]);
        }
        self::forgetRememberCookie();
    }

    /** Invalidate every token for a user — suspend, delete, or a security event. */
    public static function revokeAllForUser(int $userId): void
    {
        DB::run('DELETE FROM auth_tokens WHERE user_id = ?', [$userId]);
    }

    private static function forgetRememberCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', self::rememberCookieParams(time() - 42000));
    }

    /** Touch last_seen_at at most once a minute. */
    public static function touch(int $userId): void
    {
        DB::run(
            'UPDATE users SET last_seen_at = NOW()
             WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL 1 MINUTE)',
            [$userId]
        );
    }
}
