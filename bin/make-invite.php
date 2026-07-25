<?php
declare(strict_types=1);

/**
 * Mint an invite code, and bootstrap the first account.
 *
 * Registration is invite-gated with no self-serve path and no first-user
 * exception — which is right for a four-user app, but means the very first
 * account cannot be created through the API at all. That bootstrap belongs on
 * the CLI, behind SSH, rather than as a hole in the register route.
 *
 * Usage:
 *   php bin/make-invite.php                      issue a code (owner is issuer)
 *   php bin/make-invite.php <username>           issue a code from that user
 *   php bin/make-invite.php --owner              create the first account
 *   php bin/make-invite.php --list               show users and unused invites
 *   php bin/make-invite.php --clean-bootstrap    remove the placeholder
 *   php bin/make-invite.php --drop <username>    delete a user and their data
 *
 * The owner's password is not taken as an argument — arguments land in shell
 * history and `ps`. A one-time setup code is printed instead; the user sets
 * their own password by registering with it.
 */

require dirname(__DIR__) . '/src/bootstrap_cli.php';

/** Ambiguous characters excluded: these get read aloud and typed by hand. */
function yk_code(int $len = 10): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

$args = array_slice($argv, 1);

// ---- bootstrap the first account -------------------------------------------

/**
 * The placeholder's identity is fixed rather than derived from the owner's.
 * Deriving it would take the username and email the owner actually wants, and
 * the unique keys would then block their real registration — and appending a
 * suffix risks overflowing username's VARCHAR(30).
 */
const YK_BOOTSTRAP_USER = '_bootstrap';

if (($args[0] ?? '') === '--owner') {
    if (DB::one('SELECT id FROM users LIMIT 1') !== null) {
        fwrite(STDERR, "Users already exist — issue a normal invite instead:\n"
            . "  php bin/make-invite.php\n");
        exit(1);
    }

    // An unusable hash, not a known password: the account exists only so it can
    // own an invite row, and can never be logged into.
    $placeholder = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $code = yk_code(10);

    DB::tx(function () use ($placeholder, $code): void {
        $id = DB::insert(
            'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
             VALUES (?, ?, ?, ?, "pending")',
            [YK_BOOTSTRAP_USER, 'Bootstrap', 'bootstrap@invalid', $placeholder]
        );
        DB::run('INSERT INTO profiles (user_id) VALUES (?)', [$id]);
        DB::run('INSERT INTO invites (code, created_by) VALUES (?, ?)', [$code, $id]);
    });

    echo "Bootstrapped.\n\n";
    echo "  invite code: {$code}\n\n";
    echo "Register at / with that code, using whatever username and email you want.\n";
    echo "Then remove the placeholder:\n";
    echo "  php bin/make-invite.php --clean-bootstrap\n";
    exit(0);
}

// ---- list who exists -------------------------------------------------------

if (($args[0] ?? '') === '--list') {
    $users = DB::all('SELECT id, username, email, onboarding_state, created_at
                      FROM users ORDER BY id');
    echo "users (" . count($users) . "):\n";
    foreach ($users as $u) {
        printf("  #%-4d %-22s %-14s %s\n",
            $u['id'], $u['username'], $u['onboarding_state'], $u['email']);
    }

    $invites = DB::all('SELECT code, created_by FROM invites WHERE used_by IS NULL');
    echo "\nunused invites (" . count($invites) . "):\n";
    foreach ($invites as $i) {
        echo "  {$i['code']}  (from #{$i['created_by']})\n";
    }
    exit(0);
}

// ---- drop a single user ----------------------------------------------------

if (($args[0] ?? '') === '--drop') {
    $name = $args[1] ?? null;
    if ($name === null) {
        fwrite(STDERR, "Usage: php bin/make-invite.php --drop <username>\n");
        exit(1);
    }
    $u = DB::one('SELECT id, username FROM users WHERE username = ?', [$name]);
    if ($u === null) {
        fwrite(STDERR, "No such user: {$name}\n");
        exit(1);
    }

    $id = (int) $u['id'];

    // Most user data cascades from users.id, but both invites FKs are RESTRICT,
    // so those rows block the delete until they are dealt with — and the two
    // directions need opposite treatment:
    //   created_by  → the row must go; it cannot outlive its issuer.
    //   used_by     → only detached, because the row may have been created by
    //                 someone who still exists, and deleting it would destroy
    //                 their record rather than the dropped user's.
    DB::tx(function () use ($id): void {
        DB::run('UPDATE invites SET used_by = NULL, used_at = NULL
                 WHERE used_by = ? AND created_by <> ?', [$id, $id]);
        DB::run('DELETE FROM invites WHERE created_by = ?', [$id]);
        DB::run('DELETE FROM users WHERE id = ?', [$id]);
    });
    echo "Dropped {$u['username']} (#{$id}).\n";
    exit(0);
}

// ---- remove the placeholder once a real account exists ----------------------

if (($args[0] ?? '') === '--clean-bootstrap') {
    $row = DB::one('SELECT id FROM users WHERE username = ?', [YK_BOOTSTRAP_USER]);
    if ($row === null) {
        echo "No bootstrap placeholder to remove.\n";
        exit(0);
    }

    // Only safe once a real account exists: the placeholder owns the invite row,
    // so removing it early takes the unused code with it and leaves no way in.
    $others = (int) DB::one(
        'SELECT COUNT(*) AS n FROM users WHERE username <> ?', [YK_BOOTSTRAP_USER]
    )['n'];
    if ($others === 0) {
        fwrite(STDERR, "Refusing: no real account exists yet, so the invite would go with it.\n"
            . "Register with the code first.\n");
        exit(1);
    }

    // invites.created_by is an ON DELETE RESTRICT foreign key, so the invite row
    // has to go first or the user delete fails outright. It has served its
    // purpose by now — the real account exists.
    $id = (int) $row['id'];
    DB::tx(function () use ($id): void {
        DB::run('DELETE FROM invites WHERE created_by = ?', [$id]);
        DB::run('DELETE FROM users WHERE id = ?', [$id]);
    });
    echo "Removed the bootstrap placeholder (#{$id}) and its spent invite.\n";
    exit(0);
}

// ---- issue an invite -------------------------------------------------------

$who = $args[0] ?? null;

$issuer = $who !== null
    ? DB::one('SELECT id, username FROM users WHERE username = ?', [$who])
    : DB::one('SELECT id, username FROM users ORDER BY id LIMIT 1');

if ($issuer === null) {
    fwrite(STDERR, $who !== null
        ? "No such user: {$who}\n"
        : "No users exist yet. Create the first account:\n"
          . "  php bin/make-invite.php --owner <username> <email>\n");
    exit(1);
}

$code = yk_code(10);
DB::run('INSERT INTO invites (code, created_by) VALUES (?, ?)', [$code, (int) $issuer['id']]);

echo "invite: {$code}\n";
echo "issued by: {$issuer['username']} (#{$issuer['id']})\n";
