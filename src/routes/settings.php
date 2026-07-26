<?php
declare(strict_types=1);

/**
 * Settings and preferences.
 *
 * The settings here are the ones the quiz never asked: when things happen, how much core
 * work, and whether the coach is running at all. §9 of the quiz owns tone, nudges and units,
 * and this deliberately does not touch them — one editor per field.
 *
 * The constraints endpoints are an EDITOR, which is what separates them from
 * GET /api/onboarding/constraints. That one is a display list for the post-quiz screen and
 * withholds row ids on purpose. These return ids, include inactive rows, and can switch a
 * soft one off.
 */

/** GET /api/settings — the settings, plus the timezone and units they are read against. */
$router->add('GET', 'settings', function (): void {
    $user = Auth::require();

    $s = Settings::forUser((int) $user['id']);
    if ($s['error'] !== null) {
        Response::error((string) $s['error'], 409);
    }

    Response::json(['settings' => $s]);
});

/**
 * PUT /api/settings — change some of them.
 *
 * Partial: an absent key means "leave it alone", not "clear it". That matters because these
 * are independent toggles rather than a form submitted whole, and a user pausing coaching
 * must not silently reset the day their check-in opens.
 */
$router->add('PUT', 'settings', function (): void {
    $user = Auth::require();

    $r = Settings::save((int) $user['id'], Response::body());
    if (!$r['ok']) {
        Response::error((string) $r['error'], 422);
    }

    Response::json([
        'ok'       => true,
        'changed'  => $r['changed'],
        // The saved state, so the client renders from the server rather than from what it
        // hoped it sent.
        'settings' => Settings::forUser((int) $user['id']),
    ]);
});

/**
 * GET /api/constraints — what the coach is working around, including what is switched off.
 *
 * Every row carries `switchable`, decided by the server. The client renders a control from
 * that flag rather than from its own reading of the tier, so the rule lives in one place and
 * the UI cannot offer something the API will refuse.
 */
$router->add('GET', 'constraints', function (): void {
    $user = Auth::require();

    Response::json(['constraints' => Settings::constraints((int) $user['id'])]);
});

/**
 * PATCH /api/constraints/{id} — switch a soft preference off, or back on.
 *
 * Body: {active: bool}
 *
 * SOFT ONLY, and the refusal is in Settings::setConstraintActive rather than here. A hard
 * constraint is a limit the user set for a reason, and SPEC-safety §6 wants it to change
 * only by re-answering the question behind it. Putting the check in the library means it
 * holds for every caller, not just this route.
 *
 * 409 rather than 403 for the hard-tier case: nothing is forbidden about the request, the
 * resource is simply not in a state where it can be done, and the error text says what to
 * do instead.
 */
$router->add('PATCH', 'constraints/{id}', function (array $p): void {
    $user = Auth::require();

    $id = Validate::id($p['id'] ?? null);
    if ($id === null) {
        Response::error('Which preference?', 422);
    }

    $active = Validate::bool(Response::body()['active'] ?? null);
    if ($active === null) {
        Response::error('Send "active" as true or false.', 422);
    }

    $r = Settings::setConstraintActive((int) $user['id'], $id, $active);
    if (!$r['ok']) {
        $status = str_contains((string) $r['error'], 'hard limit') ? 409 : 404;
        Response::error((string) $r['error'], $status);
    }

    Response::json([
        'ok'          => true,
        'changed'     => $r['changed'],
        'constraints' => Settings::constraints((int) $user['id']),
    ]);
});
