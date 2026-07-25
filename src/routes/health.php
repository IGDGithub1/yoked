<?php
declare(strict_types=1);

/**
 * Health and session bootstrap.
 *
 * GET /api/health — unauthenticated liveness check.
 * GET /api/me     — who am I, plus the CSRF token the SPA needs before it can
 *                   make any mutating request.
 */

/** GET /api/health — is the API up and can it reach the database. */
$router->add('GET', 'health', function (): void {
    $db = 'ok';
    try {
        DB::one('SELECT 1');
    } catch (Throwable $e) {
        // Report degraded rather than throwing: a health endpoint that 500s
        // tells a monitor less than one that says which part is down.
        $db = 'unreachable';
    }

    Response::json([
        'ok'       => $db === 'ok',
        'database' => $db,
        'php'      => PHP_VERSION,
        'time'     => gmdate('c'),
    ], $db === 'ok' ? 200 : 503);
});

/**
 * GET /api/me — current session state.
 *
 * Always 200, even when logged out: the SPA calls this on boot to decide what
 * to render, and it also needs the CSRF token before it can log in. A 401 here
 * would make "not logged in" indistinguishable from "request failed".
 */
$router->add('GET', 'me', function (): void {
    $user = Auth::user();

    if ($user === null) {
        Response::json([
            'authenticated' => false,
            'csrf'          => Csrf::token(),
        ]);
    }

    Auth::touch((int) $user['id']);

    Response::json([
        'authenticated' => true,
        'csrf'          => Csrf::token(),
        'user' => [
            'id'               => (int) $user['id'],
            'username'         => $user['username'],
            'display_name'     => $user['display_name'],
            'email'            => $user['email'],
            'role'             => $user['role'],
            'onboarding_state' => $user['onboarding_state'],
        ],
        // The SPA routes on this: where to send the user next.
        'next' => Onboarding::nextStep((int) $user['id'], $user['onboarding_state']),
    ]);
});
