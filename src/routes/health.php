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
    } catch (Throwable) {
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
 * GET /api/runtime — what the WEB SAPI actually has.
 *
 * bin/envcheck.php can only report the CLI binary, and the two differ here:
 * imagick is absent from CLI PHP on this host. Since uploads are processed by
 * the web SAPI, that is the one whose answer matters, and this is the only way
 * to see it.
 *
 * Admin-only, and deliberately not folded into /api/health: that endpoint is
 * unauthenticated, and an extension inventory is reconnaissance.
 *
 * Named 'runtime' rather than 'env' because SiteGround's WAF blocks any path
 * ending in /env — it looks like a probe for a leaked .env file, which is a
 * reasonable rule to have. The request was rejected with an HTML 403 before
 * PHP ran at all, so the route appeared broken while being entirely correct.
 */
$router->add('GET', 'runtime', function (): void {
    $user = Auth::require();
    if (($user['role'] ?? '') !== 'admin') {
        Response::error('Not permitted.', 403);
    }

    $imagick = extension_loaded('imagick');
    $gd      = extension_loaded('gd');

    Response::json([
        'sapi'       => PHP_SAPI,
        'php'        => PHP_VERSION,
        'extensions' => [
            'imagick'  => $imagick,
            'gd'       => $gd,
            'curl'     => extension_loaded('curl'),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'mbstring' => extension_loaded('mbstring'),
        ],
        // The only thing the answer changes: whether uploads can be re-encoded
        // to strip EXIF and any embedded payload. Either library can do it.
        'can_reencode_uploads' => $imagick || $gd,
        'upload_max_filesize'  => ini_get('upload_max_filesize'),
        'post_max_size'        => ini_get('post_max_size'),
        'memory_limit'         => ini_get('memory_limit'),
    ]);
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

    $userId = (int) $user['id'];
    $tz     = Baseline::timezoneOf($userId);

    Response::json([
        'authenticated' => true,
        'csrf'          => Csrf::token(),
        'user' => [
            'id'               => $userId,
            'username'         => $user['username'],
            'display_name'     => $user['display_name'],
            'email'            => $user['email'],
            'role'             => $user['role'],
            'onboarding_state' => $user['onboarding_state'],
            // Echoed back so the client can tell whether the zone it detected is
            // the one on file, and skip the PUT when it already matches.
            'timezone'         => $tz,
        ],
        // The SPA routes on this: where to send the user next.
        'next' => Onboarding::nextStep($userId, $user['onboarding_state']),
        // Null unless the user is mid-baseline. Drives the countdown, and tells
        // the client why there are no targets yet.
        'baseline' => Baseline::progress($user, $tz),
    ]);
});

/**
 * PUT /api/timezone — the browser tells us where the user is.
 *
 * Not an onboarding question: the browser already knows this accurately and a
 * 10-section quiz does not need an eleventh thing to ask. Sent on every boot
 * rather than once, so it self-corrects when someone travels or moves.
 *
 * This is the only place a timezone changes behaviour rather than presentation.
 * Storage stays UTC everywhere; the zone decides WHEN a weekly slot fires, and
 * "Saturday 18:00" has to mean Saturday evening where the user actually is.
 */
$router->add('PUT', 'timezone', function (): void {
    $user = Auth::require();
    $b    = Response::body();

    $tz = Validate::timezone($b['timezone'] ?? null);
    if ($tz === null) {
        // A name PHP cannot construct a DateTimeZone from is no better than no
        // name at all, and storing it would break every schedule read later.
        Response::error('That is not a timezone this server recognises.', 422);
    }

    DB::run('UPDATE profiles SET timezone = ? WHERE user_id = ?', [$tz, (int) $user['id']]);
    Response::json(['ok' => true, 'timezone' => $tz]);
});
