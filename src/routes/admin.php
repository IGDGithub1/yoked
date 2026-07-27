<?php
declare(strict_types=1);

/**
 * Admin: members and invites.
 *
 * Yoked is invite-only with no self-serve registration, so somebody has to issue the codes.
 * That was a CLI job (bin/make-invite.php) and two API routes with no screen — this gives it a
 * front end and adds the member management that goes with it.
 *
 * WHAT IS DELIBERATELY ABSENT: deleting a member. The cascade reaches plans, logged days,
 * photos, buddy pairs and check-ins, and there is no undo. Suspension is reversible and covers
 * the real case, which is "stop this account working" rather than "erase the person".
 *
 * THREE GUARDS, all of which exist because the obvious implementation locks somebody out:
 *
 *   The last active admin cannot be demoted or suspended. Otherwise the app reaches a state
 *   with no administrator and no way back in short of SQL.
 *
 *   An admin cannot suspend themselves. Auth::user() kills the session of a suspended user, so
 *   the click would log them out mid-action with no obvious cause.
 *
 *   An admin cannot demote themselves. Same shape: the next request 403s and the screen they
 *   are on stops working.
 *
 * Suspension is genuinely enforced rather than cosmetic: the login route refuses a suspended
 * user and Auth::user() drops an existing session, so the effect is immediate.
 */

/** Load a member by username, or 404. Never leaks whether the name exists via a 403. */
function adminTarget(string $username): array
{
    $u = DB::one(
        'SELECT id, username, display_name, role, status FROM users WHERE username = ?',
        [$username]
    );
    if ($u === null) {
        Response::error('No such member.', 404);
    }
    return $u;
}

/** How many active admins remain. The guard against locking everyone out. */
function adminCount(): int
{
    return (int) (DB::one(
        'SELECT COUNT(*) AS n FROM users WHERE role = "admin" AND status = "active"'
    )['n'] ?? 0);
}

/* ---- members --------------------------------------------------------------- */

/**
 * GET /api/admin/members — everyone, with enough context to act.
 *
 * onboarding_state is included because it is the single most useful column here: a member stuck
 * at 'pending' never answered the quiz, and one at 'baseline' is mid-fortnight and has no plan
 * yet. Without it "why has this person done nothing" needs a database session to answer.
 */
$router->add('GET', 'admin/members', function (): void {
    Auth::requireAdmin();

    $rows = DB::all(
        'SELECT u.id, u.username, u.display_name, u.email, u.role, u.status,
                u.onboarding_state, u.created_at, u.last_seen_at,
                p.coaching_paused,
                (SELECT COUNT(*) FROM plan_versions pv WHERE pv.user_id = u.id) AS plans,
                (SELECT COUNT(*) FROM logged_days ld WHERE ld.user_id = u.id)   AS logged_days
         FROM users u
         LEFT JOIN profiles p ON p.user_id = u.id
         ORDER BY u.created_at ASC'
    );

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'               => (int) $r['id'],
            'username'         => (string) $r['username'],
            'display_name'     => (string) $r['display_name'],
            'email'            => (string) $r['email'],
            'role'             => (string) $r['role'],
            'status'           => (string) $r['status'],
            'onboarding_state' => (string) $r['onboarding_state'],
            'created_at'       => (string) $r['created_at'],
            'last_seen_at'     => $r['last_seen_at'],
            // Not a login indicator: it is whether the cron will spend money on them.
            'coaching_paused'  => (int) ($r['coaching_paused'] ?? 0) === 1,
            'plans'            => (int) $r['plans'],
            'logged_days'      => (int) $r['logged_days'],
        ];
    }

    Response::json([
        'members'      => $out,
        // So the client can grey out the controls that would fail, rather than offering an
        // action and then refusing it.
        'admin_count'  => adminCount(),
        'me'           => (int) Auth::user()['id'],
    ]);
});

/**
 * PUT /api/admin/members/{username}/role — promote or demote.
 *
 * Body: {role: member|admin}
 */
$router->add('PUT', 'admin/members/{username}/role', function (array $p): void {
    $admin = Auth::requireAdmin();
    $target = adminTarget((string) $p['username']);

    $role = Validate::enum(Response::body()['role'] ?? null, ['member', 'admin']);
    if ($role === null) {
        Response::error('Role has to be member or admin.', 422);
    }

    if ($role === (string) $target['role']) {
        Response::json(['ok' => true, 'role' => $role]);
    }

    if ($role === 'member') {
        // Demoting yourself breaks the screen you are standing on: the next request 403s.
        if ((int) $target['id'] === (int) $admin['id']) {
            Response::error('You cannot remove your own admin access.', 422);
        }
        // And the app must never reach a state with no administrator.
        if ((string) $target['role'] === 'admin'
            && (string) $target['status'] === 'active'
            && adminCount() <= 1) {
            Response::error('That is the only admin left.', 422);
        }
    }

    DB::run('UPDATE users SET role = ? WHERE id = ?', [$role, (int) $target['id']]);
    Response::json(['ok' => true, 'role' => $role]);
});

/**
 * PUT /api/admin/members/{username}/status — suspend or reactivate.
 *
 * Body: {status: active|suspended}
 *
 * Suspension takes effect immediately and everywhere: the login route refuses a suspended user
 * and Auth::user() drops any session they already hold. It is not a flag somebody has to
 * remember to check.
 */
$router->add('PUT', 'admin/members/{username}/status', function (array $p): void {
    $admin = Auth::requireAdmin();
    $target = adminTarget((string) $p['username']);

    $status = Validate::enum(Response::body()['status'] ?? null, ['active', 'suspended']);
    if ($status === null) {
        Response::error('Status has to be active or suspended.', 422);
    }

    if ($status === (string) $target['status']) {
        Response::json(['ok' => true, 'status' => $status]);
    }

    if ($status === 'suspended') {
        // Suspending yourself logs you out on the next request, with nothing on screen to
        // explain why.
        if ((int) $target['id'] === (int) $admin['id']) {
            Response::error('You cannot suspend yourself.', 422);
        }
        if ((string) $target['role'] === 'admin' && adminCount() <= 1) {
            Response::error('That is the only admin left.', 422);
        }
    }

    DB::run('UPDATE users SET status = ? WHERE id = ?', [$status, (int) $target['id']]);
    Response::json(['ok' => true, 'status' => $status]);
});

/* ---- invites --------------------------------------------------------------- */

/**
 * The invite alphabet: no O/0 and no I/1.
 *
 * A code gets read aloud or copied by hand, and a code that fails because somebody typed a zero
 * for an O is indistinguishable from one that has expired.
 */
const INVITE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/**
 * POST /api/admin/invites — mint one.
 *
 * Body: {expires_days?: 1-365}. Absent means 30.
 *
 * SUPERSEDES POST /api/invites, which did the same thing without a screen. That route stays for
 * now so nothing depending on it breaks, and both write the same shape.
 */
$router->add('POST', 'admin/invites', function (): void {
    $admin = Auth::requireAdmin();

    $days = Validate::intRange(Response::body()['expires_days'] ?? 30, 1, 365) ?? 30;

    /*
     * Sixteen characters, up from the twelve the old route used.
     *
     * The column is CHAR(20) and CHAR right-pads, so length has never mattered for storage or
     * comparison — but an invite is the only thing standing between a stranger and an account,
     * and 16 of a 32-character alphabet is 80 bits. Twelve was 60, which is fine and this is
     * free.
     */
    $code = '';
    for ($i = 0; $i < 16; $i++) {
        $code .= INVITE_ALPHABET[random_int(0, strlen(INVITE_ALPHABET) - 1)];
    }

    DB::run(
        'INSERT INTO invites (code, created_by, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))',
        [$code, (int) $admin['id'], $days]
    );

    Response::json(['ok' => true, 'code' => $code, 'expires_in_days' => $days], 201);
});

/**
 * GET /api/admin/invites — the list, with each code's state worked out here.
 *
 * The client should not be deriving "expired" from a timestamp comparison: server time is the
 * only clock that decides whether a code still works, and a client in the wrong timezone would
 * show a usable code as dead.
 */
$router->add('GET', 'admin/invites', function (): void {
    Auth::requireAdmin();

    $rows = DB::all(
        'SELECT i.code, i.created_at, i.expires_at, i.used_at,
                i.expires_at IS NOT NULL AND i.expires_at < NOW() AS is_expired,
                c.username AS created_by_username,
                u.username AS used_by_username
         FROM invites i
         LEFT JOIN users c ON c.id = i.created_by
         LEFT JOIN users u ON u.id = i.used_by
         ORDER BY i.created_at DESC
         LIMIT 100'
    );

    $out = [];
    foreach ($rows as $r) {
        $used = $r['used_at'] !== null;
        $out[] = [
            // Trimmed: the column is CHAR(20) and right-pads anything shorter.
            'code'       => trim((string) $r['code']),
            'created_at' => (string) $r['created_at'],
            'expires_at' => $r['expires_at'],
            'used_at'    => $r['used_at'],
            'used_by'    => $r['used_by_username'],
            'created_by' => $r['created_by_username'],
            'state'      => $used
                ? 'used'
                : ((int) $r['is_expired'] === 1 ? 'expired' : 'open'),
        ];
    }

    Response::json(['invites' => $out]);
});

/**
 * DELETE /api/admin/invites/{code} — revoke an unused one.
 *
 * A used invite is NOT deletable. invites.used_by is the only record of who let a given person
 * in, and deleting the row would erase that — the FK would refuse anyway, which is the schema
 * making the same point.
 */
$router->add('DELETE', 'admin/invites/{code}', function (array $p): void {
    Auth::requireAdmin();

    $code = strtoupper(trim((string) ($p['code'] ?? '')));
    if ($code === '') {
        Response::error('Which code?', 422);
    }

    $row = DB::one('SELECT code, used_at FROM invites WHERE code = ?', [$code]);
    if ($row === null) {
        Response::error('No such code.', 404);
    }
    if ($row['used_at'] !== null) {
        Response::error('That code has been used, so it is a record of who joined.', 422);
    }

    DB::run('DELETE FROM invites WHERE code = ? AND used_at IS NULL', [$code]);
    Response::json(['ok' => true]);
});
