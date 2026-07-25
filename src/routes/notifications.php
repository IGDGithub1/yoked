<?php
declare(strict_types=1);

/**
 * Notifications, including absence nudges (SPEC-coaching §9).
 *
 * In-app only. §9 considered web push and rejected it: VAPID keys plus iOS
 * home-screen installation is real work for four users who open the app anyway.
 * So a nudge is a row the client reads on boot.
 */

/**
 * GET /api/notifications — unread, newest first.
 *
 * Also returns the passive absence indicator, which is NOT a notification. §9 has a
 * step before nudging: at two quiet days the app shows something quiet, without
 * addressing the user. Sending an actual message that early is the noise the design
 * is built to avoid, but showing nothing at all wastes the one cheap signal available.
 */
$router->add('GET', 'notifications', function (): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];
    $tz     = Baseline::timezoneOf($userId);

    $quiet = 0;
    $last  = Drift::lastLoggedDate($userId);
    if ($last !== null) {
        $quiet = max(0, Schedule::daysBetween($last, Schedule::today($tz)));
    }

    Response::json([
        'notifications' => Notify::unread($userId),
        // The passive indicator: how long since anything was logged. The client
        // renders it as a quiet line rather than a message, and only past the
        // threshold in §9.
        'quiet_days'    => $quiet,
        'show_passive'  => $quiet >= Nudge::PASSIVE_AFTER,
        'last_logged'   => $last,
    ]);
});

/**
 * POST /api/notifications/read — dismiss.
 *
 * Body: {ids: [1,2,3]} or {all: true}
 *
 * Scoped to the caller inside the UPDATE rather than checked first, because ids are
 * sequential and guessable: one user must not be able to dismiss another's.
 */
$router->add('POST', 'notifications/read', function (): void {
    $user = Auth::require();
    $b    = Response::body();

    $n = ($b['all'] ?? false) === true
        ? Notify::markAllRead((int) $user['id'])
        : Notify::markRead((int) $user['id'], is_array($b['ids'] ?? null) ? $b['ids'] : []);

    Response::json(['ok' => true, 'marked' => $n]);
});
