<?php
declare(strict_types=1);

/**
 * Food logging.
 *
 * Every mutating route returns the whole day, not just the row it changed. The
 * client's next render needs recomputed totals and a fresh verdict anyway, and
 * a second GET to fetch them is a second chance to be half-updated. It also
 * means the goal verdict is only ever computed server-side.
 */

/** GET /api/nutrition/day/{date} — meals, entries, totals, targets, verdict. */
$router->add('GET', 'nutrition/day/{date}', function (array $p): void {
    $user = Auth::require();
    $date = Validate::date($p['date'] ?? null);
    if ($date === null) {
        Response::error('Give a date as YYYY-MM-DD.', 422);
    }
    Response::json(Nutrition::day((int) $user['id'], $date));
});

/**
 * GET /api/nutrition/week/{start} — seven days at once.
 *
 * The week view would otherwise be seven requests, and on a phone that is seven
 * chances for one to fail and leave a gap in the grid.
 */
$router->add('GET', 'nutrition/week/{start}', function (array $p): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];

    $start = Validate::date($p['start'] ?? null);
    if ($start === null) {
        Response::error('Give a start date as YYYY-MM-DD.', 422);
    }

    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime($start . " +{$i} days"));
        $days[] = Nutrition::day($userId, $d);
    }
    Response::json(['week_start' => $start, 'days' => $days]);
});

/**
 * POST /api/nutrition/entries — add a food to a meal.
 *
 * Body: {date, slot, name, calories, protein, fat, total_carbs, fiber, ...}
 * Net carbs are derived server-side at intake (SPEC-nutrition.md §2).
 */
$router->add('POST', 'nutrition/entries', function (): void {
    $user = Auth::require();
    $b    = Response::body();

    $date = Validate::date($b['date'] ?? null);
    $slot = Validate::enum($b['slot'] ?? null, Nutrition::SLOTS);
    if ($date === null || $slot === null) {
        Response::error('A date and a meal slot are required.', 422);
    }

    $r = Nutrition::addEntry((int) $user['id'], $date, $slot, $b);
    if (!$r['ok']) {
        Response::error((string) $r['error'], 422);
    }
    Response::json($r, 201);
});

/** PATCH /api/nutrition/entries/{id} — edit an entry. Omitted fields are left alone. */
$router->add('PATCH', 'nutrition/entries/{id}', function (array $p): void {
    $user = Auth::require();
    $id   = Validate::id($p['id'] ?? null);
    if ($id === null) {
        Response::error('Bad entry id.', 422);
    }

    $r = Nutrition::updateEntry((int) $user['id'], $id, Response::body());
    if (!$r['ok']) {
        Response::error((string) $r['error'], 404);
    }
    Response::json($r);
});

/** DELETE /api/nutrition/entries/{id} */
$router->add('DELETE', 'nutrition/entries/{id}', function (array $p): void {
    $user = Auth::require();
    $id   = Validate::id($p['id'] ?? null);
    if ($id === null) {
        Response::error('Bad entry id.', 422);
    }

    $r = Nutrition::deleteEntry((int) $user['id'], $id);
    if (!$r['ok']) {
        Response::error((string) $r['error'], 404);
    }
    Response::json($r);
});

/**
 * POST /api/nutrition/as-planned — the one-tap log (SPEC-coaching §4.4).
 *
 * Body: {date, slot}
 */
$router->add('POST', 'nutrition/as-planned', function (): void {
    $user = Auth::require();
    $b    = Response::body();

    $date = Validate::date($b['date'] ?? null);
    $slot = Validate::enum($b['slot'] ?? null, Nutrition::SLOTS);
    if ($date === null || $slot === null) {
        Response::error('A date and a meal slot are required.', 422);
    }

    $r = Nutrition::logAsPlanned((int) $user['id'], $date, $slot);
    if (!$r['ok']) {
        Response::error((string) $r['error'], 422);
    }
    Response::json($r);
});

/**
 * PUT /api/nutrition/meals/{date}/{slot} — the meal's delta, notes, or adherence.
 *
 * Body: any of {delta: {calories, protein, fat, carbs}, notes, adherence}
 *
 * The delta is ADDITIVE against the entries and ABSOLUTE against itself: sending
 * {calories: 50} twice leaves it at 50, not 100. The client owns the running
 * value, which is what makes the +/- nudge buttons behave predictably.
 */
$router->add('PUT', 'nutrition/meals/{date}/{slot}', function (array $p): void {
    $user   = Auth::require();
    $userId = (int) $user['id'];

    $date = Validate::date($p['date'] ?? null);
    $slot = Validate::enum($p['slot'] ?? null, Nutrition::SLOTS);
    if ($date === null || $slot === null) {
        Response::error('Bad date or meal slot.', 422);
    }

    $b = Response::body();
    $touched = false;
    $r = ['ok' => true];

    if (is_array($b['delta'] ?? null)) {
        $r = Nutrition::setDelta($userId, $date, $slot, $b['delta']);
        $touched = true;
    }
    if (array_key_exists('notes', $b)) {
        $r = Nutrition::setNotes($userId, $date, $slot, Validate::str($b['notes'], 1, 500));
        $touched = true;
    }
    if (array_key_exists('adherence', $b)) {
        $adherence = Validate::enum($b['adherence'], Nutrition::ADHERENCE);
        if ($adherence === null) {
            Response::error('adherence must be one of: '
                . implode(', ', Nutrition::ADHERENCE) . '.', 422);
        }
        $r = Nutrition::markMeal($userId, $date, $slot, $adherence);
        $touched = true;
    }

    if (!$touched) {
        Response::error('Send a delta, notes, or an adherence value.', 422);
    }
    Response::json($r);
});

/**
 * POST /api/nutrition/search — AI food search.
 *
 * Body: {query}. Rate limited per user: this is a paid external call.
 */
$router->add('POST', 'nutrition/search', function (): void {
    $user = Auth::require();
    $b    = Response::body();

    $r = FoodSearch::search((int) $user['id'], (string) ($b['query'] ?? ''));
    if (!$r['ok']) {
        Response::error((string) $r['error'], (int) ($r['status'] ?? 422));
    }
    Response::json($r);
});

/** GET /api/nutrition/barcode/{upc} — cache, then Open Food Facts, then AI. */
$router->add('GET', 'nutrition/barcode/{upc}', function (array $p): void {
    $user = Auth::require();

    $r = FoodSearch::barcode((int) $user['id'], (string) ($p['upc'] ?? ''));
    if (!$r['ok']) {
        Response::error((string) $r['error'], (int) ($r['status'] ?? 404));
    }
    Response::json($r);
});

// ---- favorites -------------------------------------------------------------

$router->add('GET', 'nutrition/favorites', function (): void {
    $user = Auth::require();
    Response::json(['favorites' => Nutrition::favorites((int) $user['id'])]);
});

$router->add('POST', 'nutrition/favorites', function (): void {
    $user = Auth::require();

    $r = Nutrition::addFavorite((int) $user['id'], Response::body());
    if (!$r['ok']) {
        Response::error((string) $r['error'], (int) ($r['status'] ?? 422));
    }
    Response::json($r, 201);
});

$router->add('PATCH', 'nutrition/favorites/{id}', function (array $p): void {
    $user = Auth::require();
    $id   = Validate::id($p['id'] ?? null);
    if ($id === null) {
        Response::error('Bad favorite id.', 422);
    }

    $r = Nutrition::updateFavorite((int) $user['id'], $id, Response::body());
    if (!$r['ok']) {
        Response::error((string) $r['error'], (int) ($r['status'] ?? 404));
    }
    Response::json($r);
});

$router->add('DELETE', 'nutrition/favorites/{id}', function (array $p): void {
    $user = Auth::require();
    $id   = Validate::id($p['id'] ?? null);
    if ($id === null) {
        Response::error('Bad favorite id.', 422);
    }

    $r = Nutrition::deleteFavorite((int) $user['id'], $id);
    if (!$r['ok']) {
        Response::error((string) $r['error'], 404);
    }
    Response::json($r);
});
