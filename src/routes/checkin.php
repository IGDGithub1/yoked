<?php
declare(strict_types=1);

/**
 * The weekly check-in (SPEC-coaching §7.2).
 *
 * Distinct from PUT /api/checkin/{date}, which is the DAILY four-tap check-in and
 * lives in routes/training.php. Same word, different thing: the daily one is
 * energy and sleep, this one is weight, measurements, and the user's read on the
 * week that just ended.
 *
 * Answering does NOT wait on Claude. The review is a model call that takes real
 * seconds, and holding a form submission open for it would make the app feel
 * broken; cron picks the answered check-in up on the next sweep. So the response
 * says "saved" and the review appears when it is ready.
 *
 * ROUTE ORDER: these must be registered BEFORE routes/training.php's
 * `PUT checkin/{date}`, or a request to `PUT checkin/weekly` matches that pattern
 * with date="weekly" and answers with a date-validation error instead. The router
 * takes the first pattern that matches, so this file is required first in
 * api/index.php. The two-segment paths could never collide, but the one-segment
 * one can, and the failure would read as a bug in the wrong place.
 */

/**
 * GET /api/checkin/weekly — the check-in awaiting an answer, if any.
 *
 * Always 200. "Nothing to answer" is the normal state for five days out of seven,
 * not an error, and the client polls this on boot to decide whether to surface
 * the form at all.
 */
$router->add('GET', 'checkin/weekly', function (): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];

    // Read once, used for both the form's labels and the history conversion.
    $units  = (string) (DB::one('SELECT units FROM profiles WHERE user_id = ?', [$userId])['units']
                        ?? 'imperial');
    $metric = $units === 'metric';

    $pending = CheckIn::current($userId);

    // The most recent reviewed one, so a user who already answered can still read
    // what their coach said back. Without this the review is written and never
    // seen.
    $reviewed = DB::one(
        'SELECT id, week_start, claude_review, answered_late, late_outcome, completed_at
         FROM weekly_checkins
         WHERE user_id = ? AND claude_review IS NOT NULL
         ORDER BY week_start DESC LIMIT 1',
        [$userId]
    );

    Response::json([
        'pending' => $pending === null ? null : [
            'id'         => (int) $pending['id'],
            'week_start' => (string) $pending['week_start'],
            // Shown so the user knows which week they are reporting on: the form
            // opens on Saturday, so "this week" is ambiguous without the dates.
            'week_end'   => date('Y-m-d', strtotime((string) $pending['week_start'] . ' +6 days')),
            'nudges'     => (int) $pending['nudge_count'],
            // Whether answering now can still change the coming week's plan. The
            // form says so plainly rather than letting the user assume.
            'can_shape_plan' => Plans::live(
                $userId,
                date('Y-m-d', strtotime((string) $pending['week_start'] . ' +7 days'))
            ) === null,
        ],
        'last_review' => $reviewed === null ? null : [
            'week_start'   => (string) $reviewed['week_start'],
            'review'       => (string) $reviewed['claude_review'],
            'was_late'     => (int) $reviewed['answered_late'] === 1,
            'late_outcome' => $reviewed['late_outcome'],
        ],
        // The form labels itself with these and sends raw numbers; the server
        // converts to metric on the way in, exactly as onboarding does. A client
        // that converted would be a second place to get it wrong.
        'units' => $units,

        // Trend, not points (§7.2). The client draws no conclusions from it; it is
        // shown so the user can see their own history while filling the form in.
        // Stored metric, converted here for display so the client never divides.
        'history' => array_map(
            static fn(array $h): array => [
                'week_start' => (string) $h['week_start'],
                'weight'     => $h['weight_kg'] === null ? null : round(
                    $metric ? (float) $h['weight_kg'] : (float) $h['weight_kg'] / 0.45359237, 1
                ),
                'waist'      => $h['waist_cm'] === null ? null : round(
                    $metric ? (float) $h['waist_cm'] : (float) $h['waist_cm'] / 2.54, 1
                ),
            ],
            DB::all(
                'SELECT week_start, weight_kg, waist_cm
                 FROM weekly_checkins
                 WHERE user_id = ? AND status = "completed" AND weight_kg IS NOT NULL
                 ORDER BY week_start DESC LIMIT 12',
                [$userId]
            )
        ),
    ]);
});

/**
 * PUT /api/checkin/weekly/{id} — answer it.
 *
 * Body: any of {weight_kg, waist_cm, hips_cm, chest_cm, arm_cm, thigh_cm,
 *               neck_cm, self_report, emphasis_request}
 *
 * Every field optional. A check-in that says only "knee felt off on Thursday" is
 * worth more than one nobody filled in, and demanding six measurements weekly is
 * how you get zero check-ins by week four.
 */
$router->add('PUT', 'checkin/weekly/{id}', function (array $p): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];

    $id = Validate::id($p['id'] ?? null);
    if ($id === null) {
        Response::error('Bad check-in id.', 422);
    }

    $tz = Baseline::timezoneOf($userId);
    $r  = CheckIn::answer($userId, $id, Response::body(), $tz);

    if (!$r['ok']) {
        Response::error((string) $r['error'], 422);
    }

    Response::json([
        'ok'      => true,
        'late'    => $r['late'],
        'checkin' => [
            'id'         => (int) $r['checkin']['id'],
            'week_start' => (string) $r['checkin']['week_start'],
            'status'     => (string) $r['checkin']['status'],
        ],
        // Told plainly, because the two cases mean different things to the user and
        // guessing which one happened is not their job.
        'message' => $r['late']
            ? 'Saved. Next week\'s plan was already built, so your coach will read '
              . 'this and change it only if something in it needs changing.'
            : 'Saved. Your coach will use this to build next week.',
    ]);
});

/**
 * POST /api/checkin/weekly/{id}/skip — a deliberate pass.
 *
 * Distinct from ignoring it: this stops the nudges. A user who does not want to
 * report a week should be able to say so once rather than being chased for it.
 */
$router->add('POST', 'checkin/weekly/{id}/skip', function (array $p): void {
    $user = Auth::require();

    $id = Validate::id($p['id'] ?? null);
    if ($id === null) {
        Response::error('Bad check-in id.', 422);
    }

    if (!CheckIn::skip((int) $user['id'], $id)) {
        Response::error('That check-in cannot be skipped.', 422);
    }
    Response::json(['ok' => true]);
});
