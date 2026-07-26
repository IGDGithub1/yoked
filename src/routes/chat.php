<?php
declare(strict_types=1);

/**
 * Interjections (SPEC-coaching §6).
 *
 * POST records what the user said and returns immediately. The evaluation is a model call
 * that can take real seconds — longer when it ends in a plan revision, which is minutes —
 * and holding a request open for it would make the app feel broken. Cron picks the turn up,
 * and the view polls.
 *
 * That split is also the structural half of §6.1: "There is no code path from user text to
 * plan mutation that bypasses Claude's judgment." The write path here literally cannot
 * touch a plan.
 */

/** GET /api/chat — the conversation, plus whether the coach is still thinking. */
$router->add('GET', 'chat', function (): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];

    Response::json([
        'turns'   => Chat::history($userId),
        // Drives the "thinking" state. A user who has just sent something needs to see
        // that it landed, and the reply is not instant.
        'pending' => Chat::hasPending($userId),
    ]);
});

/**
 * POST /api/chat — say something.
 *
 * Body: {message}
 *
 * Returns the turn immediately. Nothing here evaluates and nothing here can change a plan.
 */
$router->add('POST', 'chat', function (): void {
    $user = Auth::require();
    $b    = Response::body();

    $r = Chat::send((int) $user['id'], (string) ($b['message'] ?? ''));
    if (!$r['ok']) {
        // 429 for the rate limit so the client can tell "slow down" from "bad input".
        $status = str_contains((string) $r['error'], 'lot of messages') ? 429 : 422;
        Response::error((string) $r['error'], $status);
    }

    Response::json([
        'ok'      => true,
        'turn_id' => $r['turn_id'],
        'turns'   => Chat::history((int) $user['id']),
        'pending' => true,
    ], 201);
});
