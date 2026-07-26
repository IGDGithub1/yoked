<?php
declare(strict_types=1);

/**
 * The Next Day Review (SPEC-coaching §4.1a).
 *
 * Tomorrow's session and meals, late each evening, with a chance to say something before
 * the day arrives.
 */

/**
 * GET /api/tomorrow — the review, or null.
 *
 * Always 200. Null is the answer most of the time — before the evening hour, after it
 * was dismissed, or when tomorrow has nothing prescribed — and none of those are errors.
 *
 * The "should this appear" decision is deliberately the SERVER's: the schedule is stored
 * per user in their own timezone, and a client computing it would be a second
 * implementation of the same rule, differing at exactly the boundary that matters.
 */
$router->add('GET', 'tomorrow', function (): void {
    $user = Auth::require();
    $tz   = Baseline::timezoneOf((int) $user['id']);

    Response::json([
        'review' => Tomorrow::review($user, $tz),
    ]);
});

/**
 * POST /api/tomorrow/dismiss — not tonight.
 *
 * §4.1a: "Optional and dismissible; it must not become the noise the user was promised
 * they'd be spared." Dismissing is how that promise is kept, and it lasts for the day
 * being reviewed rather than forever: tomorrow's review is a different day and appears
 * again.
 */
$router->add('POST', 'tomorrow/dismiss', function (): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];
    $tz     = Baseline::timezoneOf($userId);

    // The date is derived, never taken from the client: a client could otherwise dismiss
    // an arbitrary day, and the only day it has any business dismissing is the one it was
    // just shown.
    $tomorrow = Schedule::now($tz)->modify('+1 day')->format('Y-m-d');

    Tomorrow::dismiss($userId, $tomorrow);
    Response::json(['ok' => true, 'dismissed' => $tomorrow]);
});

/**
 * POST /api/tomorrow/circumstance — "something's up".
 *
 * Body: {kind, detail, ends_on?, open_ended?}
 *
 * Records a FACT. §6.1 is structural about this: "The user's message never edits the
 * plan. It is recorded as a stated circumstance. Claude evaluates it. Only Claude's
 * decision produces a new plan version." There is no code path from here to a plan
 * mutation, which is what makes "chat that can be talked into anything" a failure mode
 * that does not exist rather than one guarded against by prompt wording.
 *
 * Today the fact is stored and read at the next generation. The full §6 loop — evaluate
 * now, revise or decline with reasoning — is separate work, and the response says so
 * plainly rather than implying tonight's note rewrote tomorrow.
 */
$router->add('POST', 'tomorrow/circumstance', function (): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];
    $tz     = Baseline::timezoneOf($userId);

    $tomorrow = Schedule::now($tz)->modify('+1 day')->format('Y-m-d');

    $r = Tomorrow::noteCircumstance($userId, $tomorrow, Response::body());
    if (!$r['ok']) {
        Response::error((string) $r['error'], 422);
    }

    Response::json([
        'ok'      => true,
        'id'      => $r['id'],
        // Honest about what happened. "Your plan has been updated" would be a lie until
        // §6 lands, and an app that overstates what it did is worse than one that waits.
        'message' => 'Noted. Your coach will take this into account.',
        'review'  => Tomorrow::review($user, $tz),
    ]);
});
