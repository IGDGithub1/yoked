<?php
declare(strict_types=1);

/**
 * Vetoes (SPEC-coaching §5).
 *
 * POST records the refusal and returns. Cron decides. Same split as chat, and for the same
 * two reasons: an accepted veto regenerates the whole week, which takes minutes, and a
 * write path that cannot itself change a plan is one fewer way for a plan to change without
 * Claude's judgment.
 *
 * The reason is required here (§5.1) rather than merely encouraged by the UI, because the
 * UI is not the only caller and "no bare rejection" is a rule about the data, not the form.
 */

/**
 * GET /api/vetoes — this week's refusals and what came of them.
 *
 * Includes DECLINED ones. §5.4 wants the pattern visible: a user who was told no needs to
 * see that they were told no, and the record of four Thursdays in a row is only signal if
 * nobody quietly swept it away.
 */
$router->add('GET', 'vetoes', function (): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];
    $tz     = Baseline::timezoneOf($userId);

    Response::json([
        'vetoes'  => Vetoes::forWeek($userId, Schedule::weekStart($tz)),
        'pending' => Vetoes::hasPending($userId),
    ]);
});

/**
 * POST /api/vetoes — turn something down.
 *
 * Body: {subject_type, subject_id, reason_code, reason_text?, scope?}
 *
 * `subject_id` is validated against the caller's own live plan inside Vetoes::raise, not
 * trusted: a client could otherwise refuse an arbitrary row, and the only prescriptions it
 * has any business refusing are the ones it was just shown.
 *
 * Returns 202 rather than 201. The row exists, but the thing the user actually asked for —
 * a different Thursday dinner — has not happened yet and may not: Claude can decline. A 201
 * would read as "done".
 */
$router->add('POST', 'vetoes', function (): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];

    $r = Vetoes::raise($userId, Response::body());
    if (!$r['ok']) {
        $status = str_contains((string) $r['error'], 'lot of vetoes') ? 429 : 422;
        Response::error((string) $r['error'], $status);
    }

    Response::json([
        'ok'       => true,
        'veto_id'  => $r['veto_id'],
        // Honest about what has and has not happened. Not "your plan has been updated".
        'message'  => 'Noted. Your coach will look at this and swap it out if it stacks up.',
        'vetoes'   => Vetoes::forWeek($userId, Schedule::weekStart(Baseline::timezoneOf($userId))),
        'pending'  => true,
    ], 202);
});
