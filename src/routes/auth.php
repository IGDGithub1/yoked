<?php
declare(strict_types=1);

/**
 * Registration, login, logout, invites.
 *
 * Yoked is invite-only, so registration consumes an invite code. Rate limits
 * are on every endpoint that can be brute-forced or used for enumeration.
 */

/**
 * GET /api/csrf — fetch a token.
 *
 * The SPA needs one before it can POST anything, including login. Separate from
 * /api/me so a login screen does not have to interpret a user payload it will
 * not get.
 */
$router->add('GET', 'csrf', function (): void {
    Response::json(['csrf' => Csrf::token()]);
});

/** POST /api/register — create an account from an invite code. */
$router->add('POST', 'register', function (): void {
    // Tight limit: this endpoint both creates accounts and reveals whether an
    // invite code is valid.
    RateLimit::check('register:' . RateLimit::ip(), 5, 3600);

    $b = Response::body();

    $code = Validate::str($b['invite'] ?? null, 1, 40);
    if ($code === null) {
        Response::error('An invite code is required.', 422);
    }

    $username = Validate::str($b['username'] ?? null, 3, 30);
    if ($username === null || !Validate::username($username)) {
        Response::error('Username must be 3-30 characters, starting with a letter, '
            . 'using only letters, numbers and underscores.', 422);
    }

    $email = Validate::str($b['email'] ?? null, 3, 255);
    if ($email === null || !Validate::email($email)) {
        Response::error('That email address does not look valid.', 422);
    }

    $password = Validate::password($b['password'] ?? null);
    if ($password === null) {
        Response::error('Password must be at least 10 characters '
            . '(and no more than 72 bytes).', 422);
    }

    $displayName = Validate::str($b['display_name'] ?? null, 1, 60) ?? $username;

    $invite = DB::one(
        'SELECT id, used_by, expires_at FROM invites WHERE code = ?', [$code]
    );
    // One message for every invite failure, so this cannot be used to probe
    // which codes exist.
    if ($invite === null
        || $invite['used_by'] !== null
        || ($invite['expires_at'] !== null && strtotime((string) $invite['expires_at']) < time())) {
        Response::error('That invite code is not valid.', 403);
    }

    try {
        $userId = DB::tx(function () use ($username, $displayName, $email, $password, $invite): int {
            $id = DB::insert(
                'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
                 VALUES (?, ?, ?, ?, "pending")',
                [$username, $displayName, $email, password_hash($password, PASSWORD_DEFAULT)]
            );

            // Claim the invite inside the transaction, guarding on used_by so two
            // simultaneous registrations cannot both consume it.
            $claimed = DB::run(
                'UPDATE invites SET used_by = ?, used_at = NOW()
                 WHERE id = ? AND used_by IS NULL',
                [$id, (int) $invite['id']]
            )->rowCount();

            if ($claimed !== 1) {
                throw new RuntimeException('invite already claimed');
            }

            DB::run('INSERT INTO profiles (user_id) VALUES (?)', [$id]);
            return $id;
        });
    } catch (RuntimeException $e) {
        Response::error('That invite code is not valid.', 403);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            // Unique violation. Which field is safe to say — the user is
            // choosing it, so this is not enumeration of anyone else's data.
            $taken = DB::one('SELECT id FROM users WHERE username = ?', [$username]) !== null
                ? 'username' : 'email address';
            Response::error("That {$taken} is already taken.", 409);
        }
        throw $e;
    }

    Auth::login($userId);
    $user = Auth::user();

    Response::json([
        'authenticated' => true,
        'csrf'          => Csrf::token(),
        'user' => [
            'id'               => (int) $user['id'],
            'username'         => $user['username'],
            'display_name'     => $user['display_name'],
            'onboarding_state' => $user['onboarding_state'],
        ],
        'next' => Onboarding::nextStep($userId, (string) $user['onboarding_state']),
    ], 201);
});

/** POST /api/login */
$router->add('POST', 'login', function (): void {
    // Per-IP and per-identifier: the first stops spraying many accounts, the
    // second stops grinding one account from many IPs.
    RateLimit::check('login:ip:' . RateLimit::ip(), 20, 900);

    $b = Response::body();

    $identifier = Validate::str($b['identifier'] ?? $b['username'] ?? null, 1, 255);
    $password   = $b['password'] ?? null;

    if ($identifier === null || !is_string($password) || $password === '') {
        Response::error('Enter your username or email, and your password.', 422);
    }

    RateLimit::check('login:id:' . strtolower($identifier), 10, 900);

    $user = DB::one(
        'SELECT id, password_hash, status FROM users WHERE username = ? OR email = ?',
        [$identifier, $identifier]
    );

    // Hash a dummy when the user is absent, so a missing account and a wrong
    // password take the same time and cannot be told apart.
    $hash = $user['password_hash']
        ?? '$2y$12$usesomesillystringfoereallybadhashingasaplaceholder';

    if (!password_verify($password, $hash) || $user === null) {
        Response::error('Those credentials do not match.', 401);
    }

    if (($user['status'] ?? 'active') === 'suspended') {
        Response::error('That account is suspended.', 403);
    }

    // Transparently upgrade the hash if the default cost has moved on.
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        DB::run('UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
    }

    Auth::login((int) $user['id']);
    $me = Auth::user();

    Response::json([
        'authenticated' => true,
        'csrf'          => Csrf::token(),
        'user' => [
            'id'               => (int) $me['id'],
            'username'         => $me['username'],
            'display_name'     => $me['display_name'],
            'onboarding_state' => $me['onboarding_state'],
        ],
        'next' => Onboarding::nextStep((int) $me['id'], (string) $me['onboarding_state']),
    ]);
});

/** POST /api/logout */
$router->add('POST', 'logout', function (): void {
    Auth::logout();
    Response::json(['authenticated' => false]);
});

/**
 * POST /api/invites — mint an invite code. Admin only.
 *
 * Invite-only means someone has to issue the invites; there is no self-serve
 * path by design.
 */
$router->add('POST', 'invites', function (): void {
    $admin = Auth::requireAdmin();
    $b = Response::body();

    $days = Validate::intRange($b['expires_days'] ?? 30, 1, 365) ?? 30;

    // Unambiguous alphabet: no O/0, I/1, so a code read aloud or copied by hand
    // does not fail for the wrong reason.
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 12; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    DB::run(
        'INSERT INTO invites (code, created_by, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))',
        [$code, (int) $admin['id'], $days]
    );

    Response::json(['code' => $code, 'expires_in_days' => $days], 201);
});

/** GET /api/invites — list invites and their state. Admin only. */
$router->add('GET', 'invites', function (): void {
    Auth::requireAdmin();

    Response::json(['invites' => DB::all(
        'SELECT i.code, i.created_at, i.expires_at, i.used_at,
                u.username AS used_by_username
         FROM invites i
         LEFT JOIN users u ON u.id = i.used_by
         ORDER BY i.created_at DESC
         LIMIT 100'
    )]);
});
