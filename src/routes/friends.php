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

    $userId = (int) $user['id'];
    $tz     = Baseline::timezoneOf($userId);

    Response::json([
        'buddy' => Buddies::forUser($userId),
        /*
         * Absence, both directions (§10.5).
         *
         * `mine` drives the "I am away" control; `theirs` is what tells the partner why their
         * week is solo. Asked for the COMING week rather than today, because that is the week
         * the answer affects — a Sunday generation is about next Monday.
         */
        'away'  => [
            'mine'   => BuddyAbsence::mine($userId),
            'theirs' => BuddyAbsence::availableFor(
                $userId,
                date('Y-m-d', strtotime(Schedule::weekStart($tz) . ' +7 days'))
            ),
        ],
    ]);
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

/* ---- the buddy schedule (§10.1a, §10.3a, §10.3b) --------------------------- */

/**
 * GET /api/buddy/schedule — the agreed days, the overlap analysis, and any open offers.
 *
 * Separate from GET /api/buddy because it is heavier: two availability grids, the agreed
 * schedule, pending offers both ways, and the surplus question. The pairing card reads the
 * lighter endpoint; only the schedule panel asks for this.
 */
$router->add('GET', 'buddy/schedule', function (): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];

    $pair = BuddySchedule::activePair($userId);
    if ($pair === null) {
        // Not an error: most users have no buddy, and the client renders nothing.
        Response::json(['schedule' => null]);
    }

    $pairId  = (int) $pair['id'];
    $otherId = (int) $pair['user_lo'] === $userId
        ? (int) $pair['user_hi']
        : (int) $pair['user_lo'];

    $analysis = BuddySchedule::analyse($userId, $otherId, $pairId);

    Response::json([
        'schedule' => [
            'agreed'    => BuddySchedule::agreedDays($pairId),
            'day_names' => BuddySchedule::DAY_NAMES,
            /*
             * The natural overlap and each user's own days, so the negotiation UI can show
             * what there is to work with. Not a privacy leak: they are already paired, and
             * §10.3a cannot be explained without saying which days each person has free.
             */
            'overlap'   => $analysis['overlap'],
            'mine_only' => $analysis['a_only'],
            'theirs_only' => $analysis['b_only'],
            'needed'    => $analysis['needed'],
            // Drives the "you only overlap on Saturday, can either of you move?" prompt.
            'thin'      => $analysis['thin'],
            'offers'    => BuddySchedule::offers($userId),
            // §10.3b. `needs_choice` is what the UI prompts on.
            'surplus'   => BuddySchedule::committedTarget($userId, $pairId),
        ],
    ]);
});

/**
 * POST /api/buddy/schedule/offers — offer to train on a day (§10.3a).
 *
 * Body: {weekday, minutes?, access?}
 *
 * The offerer states their own minutes and access, because the app has no other source for
 * them: their grid says they cannot train that day. Offering a day the other person already
 * offered ACCEPTS it, so two people reaching for the same compromise resolve rather than
 * queueing two offers neither can action.
 */
$router->add('POST', 'buddy/schedule/offers', function (): void {
    $user = Auth::require();
    $b    = Response::body();

    $weekday = Validate::intRange($b['weekday'] ?? null, 1, 7);
    if ($weekday === null) {
        Response::error('Which day?', 422);
    }

    $r = BuddySchedule::offerDay(
        (int) $user['id'],
        $weekday,
        Validate::intRange($b['minutes'] ?? null, 10, 480),
        Validate::enum($b['access'] ?? null, ['full_gym', 'home_gym', 'bodyweight', 'outdoors'])
    );
    if (!$r['ok']) {
        Response::error((string) $r['error'], 422);
    }

    Response::json(['ok' => true, 'status' => $r['status']], 201);
});

/**
 * PATCH /api/buddy/schedule/offers/{id} — answer or withdraw an offer.
 *
 * Body: {action: accept | decline | withdraw}
 *
 * Only the person who was OFFERED to can accept; only the offerer can withdraw. Both checks
 * live in BuddySchedule so they hold for every caller.
 */
$router->add('PATCH', 'buddy/schedule/offers/{id}', function (array $p): void {
    $user = Auth::require();

    $offerId = Validate::id($p['id'] ?? null);
    if ($offerId === null) {
        Response::error('Which offer?', 422);
    }

    $action = Validate::enum(
        Response::body()['action'] ?? null,
        ['accept', 'decline', 'withdraw']
    );
    if ($action === null) {
        Response::error('Send an action: accept, decline or withdraw.', 422);
    }

    $userId = (int) $user['id'];
    $r = match ($action) {
        'accept'   => BuddySchedule::respondToOffer($userId, $offerId, true),
        'decline'  => BuddySchedule::respondToOffer($userId, $offerId, false),
        'withdraw' => BuddySchedule::withdrawOffer($userId, $offerId),
    };

    if (!$r['ok']) {
        Response::error((string) $r['error'], 422);
    }

    Response::json(['ok' => true, 'status' => $r['status']]);
});

/**
 * DELETE /api/buddy/schedule/days/{weekday} — drop an agreed day.
 *
 * Either side, and no distinction between a natural overlap and a negotiated day: both are
 * days the pair currently trains together, and either user can say they cannot make it any
 * more. Re-seeding restores a natural overlap if both grids still allow it, which is correct
 * — that is what their own grids say.
 */
$router->add('DELETE', 'buddy/schedule/days/{weekday}', function (array $p): void {
    $user = Auth::require();

    $weekday = Validate::intRange($p['weekday'] ?? null, 1, 7);
    if ($weekday === null) {
        Response::error('Which day?', 422);
    }

    $r = BuddySchedule::dropDay((int) $user['id'], $weekday);
    if (!$r['ok']) {
        Response::error((string) $r['error'], 422);
    }

    Response::json(['ok' => true, 'status' => $r['status']]);
});

/**
 * PUT /api/buddy/schedule/surplus — answer the surplus question (§10.3b).
 *
 * Body: {mode: keep_commitment | extras_optional | match_buddy}
 *
 * Per-user, not per-pair: two people in one pair will usually answer differently, because the
 * 5-day user has a surplus to think about and the 3-day user does not.
 */
$router->add('PUT', 'buddy/schedule/surplus', function (): void {
    $user = Auth::require();

    $mode = Validate::str(Response::body()['mode'] ?? null, 1, 40);
    if ($mode === null) {
        Response::error('Pick one of the three options.', 422);
    }

    $r = BuddySchedule::setSurplusMode((int) $user['id'], $mode);
    if (!$r['ok']) {
        Response::error((string) $r['error'], 422);
    }

    Response::json(['ok' => true, 'mode' => $r['status']]);
});

/* ---- buddy absence (§10.5) -------------------------------------------------- */

/**
 * POST /api/buddy/away — say you will not be training with your buddy for a while.
 *
 * Body: {kind: travel|illness|other, starts_on, returns_on?}
 *
 * The partner is told immediately, and what they are told depends on whether the absence
 * starts inside a week they have already been given: "next week is yours alone" before
 * generation, "your buddy has dropped out, your week is unchanged" after. §10.5 is emphatic
 * that a week someone is halfway through is left alone.
 *
 * `returns_on` may be omitted, which means open-ended — honest for an illness nobody can put a
 * date on, and better than making someone invent one.
 */
$router->add('POST', 'buddy/away', function (): void {
    $user = Auth::require();
    $b    = Response::body();

    $r = BuddyAbsence::record(
        (int) $user['id'],
        (string) ($b['kind'] ?? ''),
        (string) ($b['starts_on'] ?? ''),
        isset($b['returns_on']) && $b['returns_on'] !== '' ? (string) $b['returns_on'] : null
    );
    if (!$r['ok']) {
        Response::error((string) $r['error'], 422);
    }

    Response::json(['ok' => true, 'notified' => $r['notified']], 201);
});

/**
 * DELETE /api/buddy/away — back early, or it turned out to be nothing.
 *
 * Cancels rather than deletes, so "I was away that week" survives as an explanation for a
 * quiet stretch when the coach reviews it later.
 */
$router->add('DELETE', 'buddy/away', function (): void {
    $user = Auth::require();

    $r = BuddyAbsence::cancel((int) $user['id']);
    if (!$r['ok']) {
        Response::error((string) $r['error'], 422);
    }

    Response::json(['ok' => true]);
});
