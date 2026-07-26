<?php
declare(strict_types=1);

/**
 * Friends (SPEC-coaching §10.1).
 *
 * A prerequisite for buddy pairing rather than a social feature: two people connect, and
 * that unlocks training together. There is no feed and no browsable profile.
 *
 * Search is the whole privacy surface. Username and display name match on a prefix; email
 * matches only in full, because a partial email match answers "is this address registered
 * here?" for any address somebody cares to try. The rule lives in Friends::search rather
 * than here, so it holds for every caller.
 *
 * ROUTE ORDER MATTERS HERE. The router turns {id} into [^/]+, which matches the literal
 * string "search", and it takes the first pattern that matches before it checks the method.
 * `friends/search` is registered BEFORE `friends/{id}` so the literal always wins. Today the
 * two differ by method as well (GET vs PATCH) so the order is not load-bearing, but that is
 * luck rather than design, and a future `GET friends/{id}` would silently shadow search.
 */

/** GET /api/friends — the list, the requests waiting, and anyone blocked. */
$router->add('GET', 'friends', function (): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];

    Response::json([
        'friends' => Friends::forUser($userId),
        // Drives the badge. Cheap enough to include rather than making the client count.
        'pending' => Friends::pendingCount($userId),
    ]);
});

/**
 * GET /api/friends/search?q= — find someone to add.
 *
 * Rate limited inside the library, and capped at a handful of results: a search that returns
 * everything is a directory, and in an invite-only app the membership list is not public.
 * Below three characters it returns nothing rather than erroring, because the user is still
 * typing and an error on every keystroke is noise.
 */
$router->add('GET', 'friends/search', function (): void {
    $user = Auth::require();

    $r = Friends::search((int) $user['id'], (string) ($_GET['q'] ?? ''));
    if (!$r['ok']) {
        Response::error((string) $r['error'], 429);
    }

    Response::json(['results' => $r['results']]);
});

/**
 * POST /api/friends — ask someone to connect.
 *
 * Body: {user_id}
 *
 * Idempotent: asking twice is not an error, and asking someone who already asked YOU accepts
 * instead, so two people reaching for each other at once end up friends rather than stuck
 * with two requests neither can resolve.
 */
$router->add('POST', 'friends', function (): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];

    $targetId = Validate::id(Response::body()['user_id'] ?? null);
    if ($targetId === null) {
        Response::error('Who do you want to add?', 422);
    }

    $r = Friends::request($userId, $targetId);
    if (!$r['ok']) {
        // 404 for "no such person", which is also what a blocked pair returns: telling the
        // sender they were blocked confirms the account exists and says what happened.
        $status = str_contains((string) $r['error'], 'No such person') ? 404 : 422;
        Response::error((string) $r['error'], $status);
    }

    Response::json([
        'ok'      => true,
        'status'  => $r['status'],
        'friends' => Friends::forUser($userId),
    ], 201);
});

/**
 * PATCH /api/friends/{id} — answer a request, or change a relationship.
 *
 * Body: {action: accept | decline | remove | block | unblock}
 *
 * One route rather than five, because they are all "change how I stand with this person" and
 * the client sends the same shape every time. The library decides what each is allowed to
 * do; notably only the person who was ASKED can accept, and only the blocker can unblock.
 */
$router->add('PATCH', 'friends/{id}', function (array $p): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];

    $otherId = Validate::id($p['id'] ?? null);
    if ($otherId === null) {
        Response::error('Which person?', 422);
    }

    $action = Validate::enum(
        Response::body()['action'] ?? null,
        ['accept', 'decline', 'remove', 'block', 'unblock']
    );
    if ($action === null) {
        Response::error('Send an action: accept, decline, remove, block or unblock.', 422);
    }

    $r = match ($action) {
        'accept'  => Friends::respond($userId, $otherId, true),
        'decline' => Friends::respond($userId, $otherId, false),
        'remove'  => Friends::remove($userId, $otherId),
        'block'   => Friends::block($userId, $otherId),
        'unblock' => Friends::unblock($userId, $otherId),
    };

    if (!$r['ok']) {
        Response::error((string) $r['error'], 422);
    }

    Response::json([
        'ok'      => true,
        'status'  => $r['status'],
        'friends' => Friends::forUser($userId),
    ]);
});

/* ---- buddy pairing (§10) --------------------------------------------------- */

/**
 * GET /api/buddy — the pairing state, and who could be asked.
 *
 * Separate from GET /api/friends rather than folded into it: the friends list is read on
 * every boot for the nav badge, and the pairing state carries an availability intersection
 * that nothing else needs. One endpoint would make every page load pay for a join it does
 * not use.
 */
$router->add('GET', 'buddy', function (): void {
    $user = Auth::require();

    Response::json(['buddy' => Buddies::forUser((int) $user['id'])]);
});

/**
 * POST /api/buddy — ask a friend to train together.
 *
 * Body: {user_id}
 *
 * §10.1 requires an accepted friendship, checked in Buddies::invite so it holds for every
 * caller. Idempotent the same two ways a friend request is: asking twice is fine, and asking
 * someone who already asked you accepts instead.
 */
$router->add('POST', 'buddy', function (): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];

    $targetId = Validate::id(Response::body()['user_id'] ?? null);
    if ($targetId === null) {
        Response::error('Who do you want to train with?', 422);
    }

    $r = Buddies::invite($userId, $targetId);
    if (!$r['ok']) {
        Response::error((string) $r['error'], 422);
    }

    Response::json(['ok' => true, 'status' => $r['status'],
                    'buddy' => Buddies::forUser($userId)], 201);
});

/**
 * PATCH /api/buddy — answer an invitation, or end the pairing.
 *
 * Body: {action: accept | decline | unpair}
 *
 * No id in the path, unlike the friends equivalent, because a user has at most one pair: the
 * spec describes a pair rather than a group, and the schema has no notion of a set. Taking an
 * id here would imply a choice the data cannot represent.
 */
$router->add('PATCH', 'buddy', function (): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];

    $action = Validate::enum(
        Response::body()['action'] ?? null,
        ['accept', 'decline', 'unpair']
    );
    if ($action === null) {
        Response::error('Send an action: accept, decline or unpair.', 422);
    }

    $r = match ($action) {
        'accept'  => Buddies::respond($userId, true),
        'decline' => Buddies::respond($userId, false),
        'unpair'  => Buddies::unpair($userId),
    };

    if (!$r['ok']) {
        Response::error((string) $r['error'], 422);
    }

    Response::json(['ok' => true, 'status' => $r['status'],
                    'buddy' => Buddies::forUser($userId)]);
});
