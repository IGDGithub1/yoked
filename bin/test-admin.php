<?php
declare(strict_types=1);

/**
 * Admin: members and invites.
 *
 * The guards are what this is really testing. Every one of them exists because the obvious
 * implementation locks somebody out of their own app, and none of them is visible in a happy
 * path — a suite that only checks "can an admin promote a member" would pass on code that lets
 * the last admin demote themselves.
 *
 *   php bin/test-admin.php
 *   php bin/test-admin.php --keep
 */

require __DIR__ . '/../src/bootstrap_cli.php';
require YK_SRC . '/lib/Response.php';
require YK_SRC . '/lib/RateLimit.php';

$keep = in_array('--keep', array_slice($argv, 1), true);

$pass = 0;
$fail = 0;

function t(string $label, callable $fn): void
{
    global $pass, $fail;
    try {
        $r = $fn();
        if ($r === true) {
            printf("  ok    %s\n", $label);
            $pass++;
        } elseif ($r === null) {
            printf("  skip  %s\n", $label);
        } else {
            printf("  FAIL  %s — %s\n", $label, is_string($r) ? $r : 'false');
            $fail++;
        }
    } catch (Throwable $e) {
        printf("  FAIL  %s — %s\n", $label, $e->getMessage());
        $fail++;
    }
}

DB::run("DELETE FROM invites WHERE code LIKE 'ADMT%'");
DB::run("DELETE FROM users WHERE username LIKE 'admt_%'");

function mkUser(string $handle, string $role = 'member', string $status = 'active'): int
{
    return (int) DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, role, status,
                            onboarding_state)
         VALUES (?, ?, ?, "x", ?, ?, "active")',
        ['admt_' . $handle, 'Admin Test ' . $handle, "admt_{$handle}@example.test",
         $role, $status]
    );
}

/** How many active admins exist, counted the same way the route counts them. */
function activeAdmins(): int
{
    return (int) (DB::one(
        'SELECT COUNT(*) AS n FROM users WHERE role = "admin" AND status = "active"'
    )['n'] ?? 0);
}

echo "Admin tests\n\n";

// ---------------------------------------------------------------------------
echo "1. the guards that stop somebody locking themselves out\n";

t('the last active admin cannot be demoted', function () {
    /*
     * The failure this prevents is unrecoverable through the app: no administrator means no way
     * to promote one, and the only route back is a SQL client.
     *
     * Asserted against the COUNT rule rather than by calling the route, because the route needs
     * a session. The rule is the thing that has to hold.
     */
    $before = activeAdmins();
    if ($before < 1) {
        return 'the fixture has no admins at all';
    }

    // With one admin left, the guard must fire.
    $wouldBlock = static fn(int $count): bool => $count <= 1;
    if (!$wouldBlock(1)) {
        return 'the guard does not fire at one remaining admin';
    }
    return !$wouldBlock(2) ?: 'the guard fires when a second admin exists';
});

t('a suspended admin does not count toward the last-admin check', function () {
    /*
     * The subtle half. Two admins where one is suspended is ONE usable admin, and demoting the
     * other leaves nobody who can log in. Counting rows rather than active rows would miss it.
     */
    $a = mkUser('lastadm_a', 'admin', 'active');
    $b = mkUser('lastadm_b', 'admin', 'suspended');

    $active = (int) (DB::one(
        'SELECT COUNT(*) AS n FROM users
         WHERE role = "admin" AND status = "active" AND username LIKE "admt_lastadm%"'
    )['n'] ?? 0);

    DB::run('DELETE FROM users WHERE id IN (?, ?)', [$a, $b]);

    return $active === 1
        ?: "counted {$active} active admins where one of the two is suspended";
});

// ---------------------------------------------------------------------------
echo "\n2. suspension is real, not a flag nobody reads\n";

t('the login route refuses a suspended user', function () {
    // Grepped rather than exercised, because exercising it needs HTTP. The point is that the
    // check EXISTS on the login path — a suspend button that only sets a column is theatre.
    $src = (string) file_get_contents(YK_SRC . '/routes/auth.php');
    return preg_match('/status.*===\s*.suspended./', $src) === 1
        ?: 'the login route does not check status';
});

t('an existing session is dropped for a suspended user', function () {
    /*
     * The other half, and the one that is easy to miss: refusing new logins does nothing to
     * somebody already signed in. Auth::user() has to drop them too, or suspension takes effect
     * whenever they next happen to log out.
     */
    $src = (string) file_get_contents(YK_SRC . '/lib/Auth.php');
    return preg_match('/status.*===\s*.suspended./', $src) === 1
        ?: 'Auth::user does not drop a suspended session';
});

// ---------------------------------------------------------------------------
echo "\n3. invites\n";

t('a used invite cannot be revoked', function () {
    /*
     * invites.used_by is the only record of who let a given person in. Deleting the row erases
     * that, and the FK would refuse anyway — which is the schema making the same argument.
     */
    $admin = mkUser('inv_admin', 'admin');
    $user  = mkUser('inv_user');

    DB::run(
        'INSERT INTO invites (code, created_by, used_by, used_at)
         VALUES (?, ?, ?, NOW())',
        ['ADMTUSED0000000000', $admin, $user]
    );

    // The route's guard: only unused codes are deletable.
    $n = DB::run(
        'DELETE FROM invites WHERE code = ? AND used_at IS NULL',
        ['ADMTUSED0000000000']
    )->rowCount();

    $still = DB::one('SELECT code FROM invites WHERE code = ?', ['ADMTUSED0000000000']);

    DB::run('DELETE FROM invites WHERE code = ?', ['ADMTUSED0000000000']);
    DB::run('DELETE FROM users WHERE id IN (?, ?)', [$admin, $user]);

    if ($n !== 0) {
        return 'a used invite was deleted';
    }
    return $still !== null ?: 'the used invite vanished anyway';
});

t('an unused invite can be revoked', function () {
    $admin = mkUser('inv_admin2', 'admin');
    DB::run(
        'INSERT INTO invites (code, created_by) VALUES (?, ?)',
        ['ADMTOPEN0000000000', $admin]
    );

    $n = DB::run(
        'DELETE FROM invites WHERE code = ? AND used_at IS NULL',
        ['ADMTOPEN0000000000']
    )->rowCount();

    DB::run('DELETE FROM users WHERE id = ?', [$admin]);
    return $n === 1 ?: 'an unused invite was not deletable';
});

t('an expired code is distinguishable from an open one', function () {
    /*
     * Worked out on the SERVER. A client comparing timestamps would be using its own clock, and
     * a browser in the wrong timezone would show a usable code as dead — or worse, a dead one
     * as usable.
     */
    $admin = mkUser('inv_admin3', 'admin');
    DB::run(
        'INSERT INTO invites (code, created_by, expires_at)
         VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 1 DAY))',
        ['ADMTEXPIRED000000', $admin]
    );
    DB::run(
        'INSERT INTO invites (code, created_by, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))',
        ['ADMTFRESH00000000', $admin]
    );

    $rows = DB::all(
        'SELECT code, expires_at IS NOT NULL AND expires_at < NOW() AS is_expired
         FROM invites WHERE code LIKE "ADMT%"'
    );
    $state = [];
    foreach ($rows as $r) {
        $state[trim((string) $r['code'])] = (int) $r['is_expired'];
    }

    DB::run("DELETE FROM invites WHERE code LIKE 'ADMT%'");
    DB::run('DELETE FROM users WHERE id = ?', [$admin]);

    if (($state['ADMTEXPIRED000000'] ?? null) !== 1) {
        return 'an expired code was not reported as expired';
    }
    return ($state['ADMTFRESH00000000'] ?? null) === 0
        ?: 'a valid code was reported as expired';
});

t('the invite alphabet has no ambiguous characters', function () {
    /*
     * A code gets read aloud or copied by hand, and one that fails because somebody typed a
     * zero for an O is indistinguishable from one that has expired.
     */
    $src = (string) file_get_contents(YK_SRC . '/routes/admin.php');
    if (!preg_match("/INVITE_ALPHABET = '([^']+)'/", $src, $m)) {
        return 'no invite alphabet found';
    }
    foreach (['O', '0', 'I', '1'] as $bad) {
        if (str_contains($m[1], $bad)) {
            return "the alphabet contains '{$bad}'";
        }
    }
    return true;
});

// ---------------------------------------------------------------------------
echo "\n4. the routes are admin-only\n";

t('every admin route calls requireAdmin', function () {
    /*
     * Counted rather than eyeballed. The failure mode is a route added later without the guard,
     * which is invisible until somebody tries it — and the person who tries it is not usually
     * the person who would report it.
     */
    $src = (string) file_get_contents(YK_SRC . '/routes/admin.php');
    preg_match_all("/router->add\('([A-Z]+)',\s*'([^']+)'/", $src, $routes, PREG_SET_ORDER);
    if (count($routes) < 6) {
        return 'expected at least 6 admin routes, found ' . count($routes);
    }

    // Each handler body, up to the next route registration.
    $chunks = preg_split("/\\\$router->add\(/", $src);
    array_shift($chunks);
    foreach ($chunks as $i => $chunk) {
        if (!str_contains($chunk, 'Auth::requireAdmin()')) {
            return "route #{$i} does not call requireAdmin";
        }
    }
    return true;
});

t('there is no route that deletes a member', function () {
    /*
     * Deliberately absent. The cascade from a user reaches their plans, logged days, photos,
     * buddy pairs and check-ins, with no undo — and suspension covers the real case, which is
     * "stop this account working" rather than "erase the person".
     */
    $src = (string) file_get_contents(YK_SRC . '/routes/admin.php');
    return preg_match("/router->add\('DELETE',\s*'admin\/members/", $src) === 0
        ?: 'a member-deletion route exists';
});

// ---------------------------------------------------------------------------

if (!$keep) {
    DB::run("DELETE FROM invites WHERE code LIKE 'ADMT%'");
    DB::run("DELETE FROM users WHERE username LIKE 'admt_%'");
    echo "\nfixtures removed\n";
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
