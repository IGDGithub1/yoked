<?php
declare(strict_types=1);

/**
 * Training logs and the daily check-in.
 */

/** GET /api/training/day/{date} — prescribed sessions, plus whatever is logged. */
$router->add('GET', 'training/day/{date}', function (array $p): void {
    $user = Auth::require();
    $date = Validate::date($p['date'] ?? null);
    if ($date === null) {
        Response::error('Give a date as YYYY-MM-DD.', 422);
    }
    Response::json(Training::day((int) $user['id'], $date));
});

/**
 * POST /api/training/sessions — log a session and its exercises together.
 *
 * Body: {
 *   date, status, prescribed_session_id?, session_type?, actual_minutes?,
 *   session_rpe?, notes?, trained_with_buddy?,
 *   exercises: [{slug|exercise_id, sets_completed, actual_reps,
 *                actual_weight_kg, actual_seconds?, actual_distance_m?,
 *                rpe, skipped?, notes?}, ...]
 * }
 *
 * session_type applies only when prescribed_session_id is absent — a free-logged
 * session (baseline week 1, or "I just went for a run") carries its own type,
 * while a prescribed one reads it off the plan.
 *
 * One request, not a session POST followed by N exercise POSTs: the user taps
 * "done" once, and a half-written session after a dropped connection is worse
 * than no session at all.
 *
 * Re-posting the same prescribed_session_id replaces the previous log rather
 * than adding a second — correcting a typo is common, and two rows for one
 * session would double-count adherence.
 */
$router->add('POST', 'training/sessions', function (): void {
    $user = Auth::require();
    $b    = Response::body();

    $date = Validate::date($b['date'] ?? null);
    if ($date === null) {
        Response::error('A date is required.', 422);
    }

    $r = Training::logSession((int) $user['id'], $date, $b);
    if (!$r['ok']) {
        Response::error((string) $r['error'], 422);
    }
    Response::json($r, 201);
});

/** DELETE /api/training/sessions/{id} */
$router->add('DELETE', 'training/sessions/{id}', function (array $p): void {
    $user = Auth::require();
    $id   = Validate::id($p['id'] ?? null);
    if ($id === null) {
        Response::error('Bad session id.', 422);
    }

    $r = Training::deleteSession((int) $user['id'], $id);
    if (!$r['ok']) {
        Response::error((string) $r['error'], 404);
    }
    Response::json($r);
});

/**
 * GET /api/training/exercises?q=… — typeahead for free-logging.
 *
 * Needed because a user logging a workout that was never prescribed has to be
 * able to FIND an exercise: POST /training/sessions resolves an exact slug,
 * alias, or name, which is no help to someone typing "leg pr".
 *
 * Not rate limited — it is one indexed LIKE against a 90-row table, unlike food
 * search which is a paid model call.
 */
$router->add('GET', 'training/exercises', function (): void {
    Auth::require();
    $q = (string) ($_GET['q'] ?? '');

    // An empty query returns nothing rather than the whole library: this feeds a
    // typeahead, and 90 rows on first focus is a wall, not a suggestion.
    Response::json(['exercises' => Training::searchExercises($q)]);
});

/**
 * GET /api/training/history/{exercise} — recent load for one exercise.
 *
 * Accepts an id, a slug, or an alias, because the client knows whichever it was
 * given. Shown next to the input so last week's numbers are visible while
 * entering this week's, and read by the progression logic.
 */
$router->add('GET', 'training/history/{exercise}', function (array $p): void {
    $user = Auth::require();
    $key  = (string) ($p['exercise'] ?? '');

    $row = ctype_digit($key)
        ? DB::one('SELECT id, slug, name FROM exercises WHERE id = ?', [(int) $key])
        : DB::one('SELECT id, slug, name FROM exercises WHERE slug = ?', [$key]);

    if ($row === null && !ctype_digit($key)) {
        // Fall through to the alias table — the library ships 53 aliases and a
        // client that has only the user's wording should still resolve.
        $row = DB::one(
            'SELECT e.id, e.slug, e.name
             FROM exercise_aliases a JOIN exercises e ON e.id = a.exercise_id
             WHERE a.alias = ?',
            [$key]
        );
    }
    if ($row === null) {
        Response::notFound('No such exercise.');
    }

    Response::json([
        'exercise' => ['id' => (int) $row['id'], 'slug' => $row['slug'], 'name' => $row['name']],
        'history'  => Training::history((int) $user['id'], (int) $row['id']),
    ]);
});

/**
 * PUT /api/checkin/{date} — the daily check-in (SPEC-coaching §4.1).
 *
 * Body: any of {energy, sleep_hours, sleep_quality, soreness, mood, notes}
 *
 * Four taps, read as a delta against the profile baselines rather than as
 * absolute values. Partial saves are fine: someone who only wants to say they
 * slept badly should not have to rate four other things first.
 */
$router->add('PUT', 'checkin/{date}', function (array $p): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];

    $date = Validate::date($p['date'] ?? null);
    if ($date === null) {
        Response::error('Give a date as YYYY-MM-DD.', 422);
    }

    $b = Response::body();

    /*
     * 1..5 scales throughout, which is what the columns are sized for.
     *
     * An explicit null CLEARS the rating. A rating you can change but never undo
     * is a trap: the check-in is optional by design, and a mis-tap otherwise
     * becomes permanent data the coach reads as fact. The columns are all
     * nullable, so "answered 3" and "did not answer" were always distinct
     * states — there just was no way back to the second one.
     *
     * array_key_exists, not isset: isset() is false for null, which is exactly
     * the case being handled here.
     */
    $fields = [];
    foreach (['energy', 'sleep_quality', 'soreness', 'mood'] as $k) {
        if (!array_key_exists($k, $b)) {
            continue;
        }
        if ($b[$k] === null) {
            $fields[$k] = null;
            continue;
        }
        $v = Validate::intRange($b[$k], 1, 5);
        if ($v === null) {
            Response::error("{$k} must be a whole number from 1 to 5, or null to clear it.", 422);
        }
        $fields[$k] = $v;
    }
    if (array_key_exists('sleep_hours', $b)) {
        if ($b['sleep_hours'] === null) {
            $fields['sleep_hours'] = null;
        } else {
            $v = Validate::floatRange($b['sleep_hours'], 0, 24);
            if ($v === null) {
                Response::error('sleep_hours must be between 0 and 24, or null to clear it.', 422);
            }
            $fields['sleep_hours'] = round($v, 1);
        }
    }
    if (array_key_exists('notes', $b)) {
        $fields['notes'] = Validate::str($b['notes'], 1, 2000);
    }

    if ($fields === []) {
        Response::error('Send at least one of: energy, sleep_hours, sleep_quality, '
            . 'soreness, mood, notes.', 422);
    }

    $dayId = Nutrition::dayId($userId, $date);

    // checked_in_at is what distinguishes "not asked yet" from "asked and
    // answered", which the nudge logic needs (§4.2) — an all-null check-in is
    // still a check-in if the user chose to skip the ratings.
    //
    // So clearing every rating deliberately does NOT un-check-in. "I opened this
    // and chose not to answer" is a different fact from "nobody asked me", and
    // only the second one deserves a nudge. Stamping it on a clear is what keeps
    // the app from pestering someone who already engaged with it today.
    $sets   = [];
    $params = [];
    foreach ($fields as $col => $val) {
        $sets[]   = "{$col} = ?";
        $params[] = $val;
    }
    $sets[]   = 'checked_in_at = NOW()';
    $params[] = $dayId;

    DB::run('UPDATE logged_days SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

    Response::json(['ok' => true, 'day' => Nutrition::day($userId, $date)]);
});
