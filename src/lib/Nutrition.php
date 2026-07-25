<?php
declare(strict_types=1);

/**
 * Food logging: days, meals, entries, favorites, barcode cache.
 *
 * Carries over the intake model from Keto Tracker (SPEC-nutrition.md), where it
 * survived real daily use by a group of friends. Two decisions from that app are
 * load-bearing and preserved deliberately:
 *
 *   1. Meal total = manual delta + SUM(entries). The delta is ADDITIVE, not an
 *      override. Log "chicken + broccoli" from search, then nudge +50 cal for
 *      the oil you cooked in, and the nudge survives adding or removing items.
 *      It was the most-used correction path in the original.
 *
 *   2. Net carbs are computed at INTAKE (total - fiber), and everything
 *      downstream reads `carbs` meaning net. total_carbs and fiber are kept on
 *      the row anyway: they cost nothing and a training app wants fiber.
 *
 * What is NOT carried over: the JSONB week blob, which made every keystroke
 * re-PUT the entire week. Rows mean a keystroke writes one row.
 */
final class Nutrition
{
    /** Meal slots, matching the logged_meals ENUM. */
    public const SLOTS = ['breakfast', 'lunch', 'dinner', 'snack_am', 'snack_pm', 'snack_eve'];

    /** How a logged meal related to what was prescribed. */
    public const ADHERENCE = ['as_planned', 'substituted', 'skipped', 'unplanned'];

    /** Where an entry's numbers came from. */
    public const SOURCES = ['manual', 'ai', 'barcode', 'favorite', 'as_planned'];

    // ---- days ---------------------------------------------------------------

    /**
     * The logged_days row for a date, created if absent.
     *
     * Stamped with the plan version live at log time, so adherence stays
     * meaningful after the plan changes mid-week (SPEC-coaching §2). During
     * baseline week 1 there is deliberately no prescription, and the column
     * stays NULL rather than being back-filled later.
     */
    public static function dayId(int $userId, string $date): int
    {
        $row = DB::one(
            'SELECT id FROM logged_days WHERE user_id = ? AND log_date = ?',
            [$userId, $date]
        );
        if ($row !== null) {
            return (int) $row['id'];
        }

        $live = DB::one(
            'SELECT id FROM plan_versions
             WHERE user_id = ? AND superseded_at IS NULL
               AND ? BETWEEN week_start AND DATE_ADD(week_start, INTERVAL 6 DAY)
             ORDER BY id DESC LIMIT 1',
            [$userId, $date]
        );

        try {
            return DB::insert(
                'INSERT INTO logged_days (user_id, log_date, plan_version_id) VALUES (?, ?, ?)',
                [$userId, $date, $live === null ? null : (int) $live['id']]
            );
        } catch (PDOException $e) {
            // Two writes for the same day can race — the unique key is the
            // arbiter, and the loser just reads the winner's row.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            $row = DB::one(
                'SELECT id FROM logged_days WHERE user_id = ? AND log_date = ?',
                [$userId, $date]
            );
            if ($row === null) {
                throw $e;
            }
            return (int) $row['id'];
        }
    }

    /** A day with its meals, entries, totals, targets and cached verdict. */
    public static function day(int $userId, string $date): array
    {
        $day = DB::one(
            'SELECT id, log_date, plan_version_id, energy, sleep_hours, sleep_quality,
                    soreness, mood, checked_in_at, notes,
                    macro_on_target, macro_short_but_ok, failure_count, proximity,
                    sessions_prescribed, sessions_completed
             FROM logged_days WHERE user_id = ? AND log_date = ?',
            [$userId, $date]
        );

        if ($day === null) {
            // Not an error: a day nobody has touched yet. Return the shape the
            // client expects with nothing in it, so the UI has no empty-state
            // special case.
            return [
                'date'      => $date,
                'logged'    => false,
                'meals'     => self::emptySlots(),
                'totals'    => ['calories' => 0.0, 'protein' => 0.0, 'fat' => 0.0, 'carbs' => 0.0],
                'target'    => self::target($userId, $date),
                'checkin'   => null,
                'verdict'   => null,
                'prescribed' => self::prescribedMeals($userId, $date),
            ];
        }

        $dayId = (int) $day['id'];

        return [
            'date'   => $date,
            'logged' => true,
            'meals'  => self::meals($dayId),
            'totals' => Goals::dayTotals($dayId),
            'target' => self::target($userId, $date),
            'checkin' => $day['checked_in_at'] === null ? null : [
                'energy'        => self::intOrNull($day['energy']),
                'sleep_hours'   => $day['sleep_hours'] === null ? null : (float) $day['sleep_hours'],
                'sleep_quality' => self::intOrNull($day['sleep_quality']),
                'soreness'      => self::intOrNull($day['soreness']),
                'mood'          => self::intOrNull($day['mood']),
                'notes'         => $day['notes'],
                'at'            => $day['checked_in_at'],
            ],
            // The client displays the verdict and never computes it: the goal
            // vocabulary lives server-side so history cannot be re-judged by an
            // old client.
            'verdict' => $day['macro_on_target'] === null ? null : [
                'on_target'     => (bool) $day['macro_on_target'],
                'short_but_ok'  => (bool) $day['macro_short_but_ok'],
                'failure_count' => self::intOrNull($day['failure_count']),
                'proximity'     => $day['proximity'] === null ? null : (float) $day['proximity'],
            ],
            'sessions' => [
                'prescribed' => self::intOrNull($day['sessions_prescribed']),
                'completed'  => self::intOrNull($day['sessions_completed']),
            ],
            'prescribed' => self::prescribedMeals($userId, $date),
        ];
    }

    /** The day's macro targets, from the live plan. Null when nothing is prescribed. */
    public static function target(int $userId, string $date): ?array
    {
        $row = DB::one(
            'SELECT pd.target_calories, pd.target_protein_g, pd.target_fat_g, pd.target_carbs_g
             FROM prescribed_days pd
             JOIN plan_versions pv ON pv.id = pd.plan_version_id
             WHERE pv.user_id = ? AND pv.superseded_at IS NULL AND pd.day_date = ?
             LIMIT 1',
            [$userId, $date]
        );
        if ($row === null) {
            return null;
        }
        return [
            'calories' => (float) $row['target_calories'],
            'protein'  => (float) $row['target_protein_g'],
            'fat'      => (float) $row['target_fat_g'],
            'carbs'    => (float) $row['target_carbs_g'],
        ];
    }

    // ---- meals --------------------------------------------------------------

    /** Every slot for a day, in eating order, each with its entries and total. */
    public static function meals(int $dayId): array
    {
        $rows = DB::all(
            'SELECT id, slot, adherence, prescribed_meal_id,
                    delta_calories, delta_protein_g, delta_fat_g, delta_carbs_g, notes
             FROM logged_meals WHERE logged_day_id = ?',
            [$dayId]
        );

        $bySlot = [];
        foreach ($rows as $r) {
            $bySlot[(string) $r['slot']] = $r;
        }

        $entries = [];
        if ($rows !== []) {
            $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            foreach (DB::all(
                "SELECT id, logged_meal_id, name, serving_g, calories, protein_g, fat_g,
                        carbs_g, fiber_g, total_carbs_g, source, source_ref, favorite_id
                 FROM logged_entries WHERE logged_meal_id IN ({$in}) ORDER BY id",
                $ids
            ) as $e) {
                $entries[(int) $e['logged_meal_id']][] = self::shapeEntry($e);
            }
        }

        $out = [];
        foreach (self::SLOTS as $slot) {
            $m = $bySlot[$slot] ?? null;
            if ($m === null) {
                $out[] = self::emptySlot($slot);
                continue;
            }
            $id    = (int) $m['id'];
            $items = $entries[$id] ?? [];
            $delta = [
                'calories' => (float) $m['delta_calories'],
                'protein'  => (float) $m['delta_protein_g'],
                'fat'      => (float) $m['delta_fat_g'],
                'carbs'    => (float) $m['delta_carbs_g'],
            ];
            $out[] = [
                'id'        => $id,
                'slot'      => $slot,
                'adherence' => (string) $m['adherence'],
                'prescribed_meal_id' => self::intOrNull($m['prescribed_meal_id']),
                'delta'     => $delta,
                'notes'     => $m['notes'],
                'entries'   => $items,
                // Delta plus items, so the client never has to know the rule.
                'total'     => self::mealTotal($delta, $items),
            ];
        }
        return $out;
    }

    /** The logged_meals row for a slot, created if absent. */
    public static function mealId(int $dayId, string $slot): int
    {
        $row = DB::one(
            'SELECT id FROM logged_meals WHERE logged_day_id = ? AND slot = ?',
            [$dayId, $slot]
        );
        if ($row !== null) {
            return (int) $row['id'];
        }
        try {
            return DB::insert(
                'INSERT INTO logged_meals (logged_day_id, slot) VALUES (?, ?)',
                [$dayId, $slot]
            );
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            $row = DB::one(
                'SELECT id FROM logged_meals WHERE logged_day_id = ? AND slot = ?',
                [$dayId, $slot]
            );
            if ($row === null) {
                throw $e;
            }
            return (int) $row['id'];
        }
    }

    /**
     * "Ate as planned" — the one-tap path (SPEC-coaching §4.4).
     *
     * Copies the prescribed meal's macros in as a single entry rather than
     * pointing at the prescription, because a plan version can be superseded and
     * the log must still say what was eaten. Idempotent: tapping twice does not
     * double the meal.
     */
    public static function logAsPlanned(int $userId, string $date, string $slot): array
    {
        $prescribed = DB::one(
            'SELECT pm.id, pm.name, pm.calories, pm.protein_g, pm.fat_g, pm.carbs_g, pm.fiber_g
             FROM prescribed_meals pm
             JOIN prescribed_days pd ON pd.id = pm.prescribed_day_id
             JOIN plan_versions pv   ON pv.id = pd.plan_version_id
             WHERE pv.user_id = ? AND pv.superseded_at IS NULL
               AND pd.day_date = ? AND pm.slot = ?
             LIMIT 1',
            [$userId, $date, $slot]
        );
        if ($prescribed === null) {
            return ['ok' => false, 'error' => 'Nothing was prescribed for that meal.'];
        }

        $dayId = self::dayId($userId, $date);

        DB::tx(function () use ($dayId, $slot, $prescribed): void {
            $mealId = self::mealId($dayId, $slot);
            // Replace rather than append: tapping "as planned" twice should not
            // log two dinners.
            DB::run('DELETE FROM logged_entries WHERE logged_meal_id = ?', [$mealId]);
            DB::run(
                'UPDATE logged_meals
                 SET adherence = ?, prescribed_meal_id = ?,
                     delta_calories = 0, delta_protein_g = 0, delta_fat_g = 0, delta_carbs_g = 0
                 WHERE id = ?',
                ['as_planned', (int) $prescribed['id'], $mealId]
            );
            DB::run(
                'INSERT INTO logged_entries
                    (logged_meal_id, name, calories, protein_g, fat_g, carbs_g, fiber_g, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $mealId,
                    (string) $prescribed['name'],
                    (float) $prescribed['calories'],
                    (float) $prescribed['protein_g'],
                    (float) $prescribed['fat_g'],
                    // Prescribed carbs are already net (PlanSchema), so they are
                    // copied straight across without re-deriving.
                    (float) $prescribed['carbs_g'],
                    $prescribed['fiber_g'] === null ? null : (float) $prescribed['fiber_g'],
                    'as_planned',
                ]
            );
        });

        Goals::evaluateAndCache($dayId);
        return ['ok' => true, 'day' => self::day($userId, $date)];
    }

    /** Record that a prescribed meal was skipped, without logging food. */
    public static function markMeal(int $userId, string $date, string $slot, string $adherence): array
    {
        $dayId  = self::dayId($userId, $date);
        $mealId = self::mealId($dayId, $slot);
        DB::run('UPDATE logged_meals SET adherence = ? WHERE id = ?', [$adherence, $mealId]);
        Goals::evaluateAndCache($dayId);
        return ['ok' => true, 'day' => self::day($userId, $date)];
    }

    /**
     * Set a meal's manual delta.
     *
     * Additive against the entries, and absolute against itself: sending
     * {calories: 50} twice leaves the delta at 50, not 100. The client owns the
     * running value, which is what makes the +/- nudge buttons behave.
     */
    public static function setDelta(int $userId, string $date, string $slot, array $delta): array
    {
        $dayId  = self::dayId($userId, $date);
        $mealId = self::mealId($dayId, $slot);

        DB::run(
            'UPDATE logged_meals
             SET delta_calories = ?, delta_protein_g = ?, delta_fat_g = ?, delta_carbs_g = ?
             WHERE id = ?',
            [
                (int) round((float) ($delta['calories'] ?? 0)),
                round((float) ($delta['protein'] ?? 0), 1),
                round((float) ($delta['fat'] ?? 0), 1),
                round((float) ($delta['carbs'] ?? 0), 1),
                $mealId,
            ]
        );

        Goals::evaluateAndCache($dayId);
        return ['ok' => true, 'day' => self::day($userId, $date)];
    }

    /** Set a meal's free-text note. */
    public static function setNotes(int $userId, string $date, string $slot, ?string $notes): array
    {
        $dayId  = self::dayId($userId, $date);
        $mealId = self::mealId($dayId, $slot);
        DB::run('UPDATE logged_meals SET notes = ? WHERE id = ?', [$notes, $mealId]);
        return ['ok' => true, 'day' => self::day($userId, $date)];
    }

    // ---- entries ------------------------------------------------------------

    /**
     * Add a food to a meal.
     *
     * Net carbs are derived here, at intake, per SPEC-nutrition.md §2. Callers
     * pass total_carbs and fiber; everything downstream reads `carbs` and means
     * net.
     */
    public static function addEntry(int $userId, string $date, string $slot, array $food): array
    {
        $name = Validate::str($food['name'] ?? null, 1, 200);
        if ($name === null) {
            return ['ok' => false, 'error' => 'A food needs a name.'];
        }

        $macros = self::normaliseMacros($food);
        $dayId  = self::dayId($userId, $date);

        $entryId = DB::tx(function () use ($dayId, $slot, $name, $macros, $food): int {
            $mealId = self::mealId($dayId, $slot);

            // Adding real food to a slot means it was not eaten as planned,
            // unless the caller says otherwise. Left alone if already
            // substituted/skipped — the user's own classification wins.
            DB::run(
                "UPDATE logged_meals SET adherence = 'substituted'
                 WHERE id = ? AND adherence IN ('as_planned', 'unplanned')",
                [$mealId]
            );

            return DB::insert(
                'INSERT INTO logged_entries
                    (logged_meal_id, name, serving_g, calories, protein_g, fat_g,
                     carbs_g, fiber_g, total_carbs_g, source, source_ref, favorite_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $mealId, $name,
                    $macros['serving_g'],
                    $macros['calories'], $macros['protein'], $macros['fat'],
                    $macros['carbs'], $macros['fiber'], $macros['total_carbs'],
                    Validate::enum($food['source'] ?? 'manual', self::SOURCES) ?? 'manual',
                    Validate::str($food['source_ref'] ?? null, 1, 80),
                    Validate::id($food['favorite_id'] ?? null),
                ]
            );
        });

        Goals::evaluateAndCache($dayId);
        return ['ok' => true, 'entry_id' => $entryId, 'day' => self::day($userId, $date)];
    }

    /** Edit an entry. Only the fields present in $food are touched. */
    public static function updateEntry(int $userId, int $entryId, array $food): array
    {
        $existing = self::ownedEntry($userId, $entryId);
        if ($existing === null) {
            return ['ok' => false, 'error' => 'No such entry.'];
        }

        // A PATCH that omits macros must not zero them. The original app had
        // exactly this bug — `parseFloat(undefined) ?? f.calories` yields NaN,
        // and ?? does not catch NaN, so a rename-only PUT wiped all four macros
        // (SPEC-nutrition.md §5). Presence of the key is the test, not falsiness.
        $pick = static function (string $key, $fallback) use ($food) {
            return array_key_exists($key, $food) ? $food[$key] : $fallback;
        };

        $merged = self::normaliseMacros([
            'calories'    => $pick('calories', $existing['calories']),
            'protein'     => $pick('protein', $existing['protein_g']),
            'fat'         => $pick('fat', $existing['fat_g']),
            // The stored column is net. If the caller sends total_carbs we
            // re-derive; if it sends carbs we take it as net directly.
            'carbs'       => $pick('carbs', $existing['carbs_g']),
            'total_carbs' => $pick('total_carbs', $existing['total_carbs_g']),
            'fiber'       => $pick('fiber', $existing['fiber_g']),
            'serving_g'   => $pick('serving_g', $existing['serving_g']),
        ]);

        $name = array_key_exists('name', $food)
            ? Validate::str($food['name'], 1, 200)
            : (string) $existing['name'];
        if ($name === null) {
            return ['ok' => false, 'error' => 'A food needs a name.'];
        }

        DB::run(
            'UPDATE logged_entries
             SET name = ?, serving_g = ?, calories = ?, protein_g = ?, fat_g = ?,
                 carbs_g = ?, fiber_g = ?, total_carbs_g = ?
             WHERE id = ?',
            [
                $name, $merged['serving_g'],
                $merged['calories'], $merged['protein'], $merged['fat'],
                $merged['carbs'], $merged['fiber'], $merged['total_carbs'],
                $entryId,
            ]
        );

        Goals::evaluateAndCache((int) $existing['logged_day_id']);
        return ['ok' => true, 'day' => self::day($userId, (string) $existing['log_date'])];
    }

    /** Remove an entry. */
    public static function deleteEntry(int $userId, int $entryId): array
    {
        $existing = self::ownedEntry($userId, $entryId);
        if ($existing === null) {
            return ['ok' => false, 'error' => 'No such entry.'];
        }
        DB::run('DELETE FROM logged_entries WHERE id = ?', [$entryId]);
        Goals::evaluateAndCache((int) $existing['logged_day_id']);
        return ['ok' => true, 'day' => self::day($userId, (string) $existing['log_date'])];
    }

    // ---- favorites ----------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public static function favorites(int $userId): array
    {
        $out = [];
        foreach (DB::all(
            'SELECT id, name, serving_g, calories, protein_g, fat_g, carbs_g,
                    fiber_g, total_carbs_g
             FROM favorite_foods WHERE user_id = ? ORDER BY name',
            [$userId]
        ) as $f) {
            $out[] = [
                'id'        => (int) $f['id'],
                'name'      => $f['name'],
                'serving_g' => self::intOrNull($f['serving_g']),
                'calories'  => (float) $f['calories'],
                'protein'   => (float) $f['protein_g'],
                'fat'       => (float) $f['fat_g'],
                // `carbs` is NET, as everywhere else in this API. fiber and
                // total_carbs come along (008) so re-logging a favorite produces
                // an entry identical to the one it was starred from — before
                // that, starring a food silently discarded its fiber.
                'carbs'     => (float) $f['carbs_g'],
                'fiber'     => $f['fiber_g'] === null ? null : (float) $f['fiber_g'],
                'total_carbs' => $f['total_carbs_g'] === null
                                 ? null : (float) $f['total_carbs_g'],
            ];
        }
        return $out;
    }

    public static function addFavorite(int $userId, array $food): array
    {
        $name = Validate::str($food['name'] ?? null, 1, 200);
        if ($name === null) {
            return ['ok' => false, 'error' => 'A favorite needs a name.'];
        }
        $m = self::normaliseMacros($food);

        try {
            $id = DB::insert(
                'INSERT INTO favorite_foods
                    (user_id, name, serving_g, calories, protein_g, fat_g, carbs_g,
                     fiber_g, total_carbs_g)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId, $name, $m['serving_g'], $m['calories'],
                    $m['protein'], $m['fat'], $m['carbs'],
                    // normaliseMacros() has already derived net from
                    // total - fiber; these keep the inputs so the favorite can
                    // reproduce the entry rather than only its net result.
                    $m['fiber'],
                    // No total given (a hand-typed favorite, say): net IS total.
                    $m['total_carbs'] ?? $m['carbs'],
                ]
            );
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            // The unique key is case-insensitive by collation, which is what
            // gives us the case-insensitive dedupe the original did in JS.
            return ['ok' => false, 'error' => 'That food is already a favorite.', 'status' => 409];
        }
        return ['ok' => true, 'id' => $id, 'favorites' => self::favorites($userId)];
    }

    public static function updateFavorite(int $userId, int $favId, array $food): array
    {
        $existing = DB::one(
            'SELECT * FROM favorite_foods WHERE id = ? AND user_id = ?', [$favId, $userId]
        );
        if ($existing === null) {
            return ['ok' => false, 'error' => 'No such favorite.'];
        }

        // Same key-presence rule as updateEntry, for the same reason.
        $pick = static fn(string $k, $fallback) => array_key_exists($k, $food) ? $food[$k] : $fallback;

        /*
         * Carbs need care here (008).
         *
         * normaliseMacros() derives net from total - fiber when BOTH are present,
         * and the stored carbs_g is already net. So handing it the existing net
         * carbs alongside a fiber figure would subtract the fiber a second time
         * and quietly shrink the favorite on every edit.
         *
         * So: fall back on the stored TOTAL, which is the pre-netting input, and
         * only fall back to net when there is no total (a row from before 008).
         */
        $carbsIn = array_key_exists('total_carbs', $food)
            ? ['total_carbs' => $food['total_carbs'], 'fiber' => $pick('fiber', $existing['fiber_g'])]
            : (array_key_exists('carbs', $food)
                // An explicit net carbs value wins outright, and passing fiber
                // with it would re-net it.
                ? ['carbs' => $food['carbs']]
                : [
                    'total_carbs' => $existing['total_carbs_g'] ?? $existing['carbs_g'],
                    'fiber'       => $pick('fiber', $existing['fiber_g']),
                ]);

        $m = self::normaliseMacros([
            'calories'  => $pick('calories', $existing['calories']),
            'protein'   => $pick('protein', $existing['protein_g']),
            'fat'       => $pick('fat', $existing['fat_g']),
            'serving_g' => $pick('serving_g', $existing['serving_g']),
        ] + $carbsIn);
        $name = array_key_exists('name', $food)
            ? Validate::str($food['name'], 1, 200)
            : (string) $existing['name'];
        if ($name === null) {
            return ['ok' => false, 'error' => 'A favorite needs a name.'];
        }

        try {
            DB::run(
                'UPDATE favorite_foods
                 SET name = ?, serving_g = ?, calories = ?, protein_g = ?, fat_g = ?,
                     carbs_g = ?, fiber_g = ?, total_carbs_g = ?
                 WHERE id = ? AND user_id = ?',
                [$name, $m['serving_g'], $m['calories'], $m['protein'], $m['fat'],
                 $m['carbs'], $m['fiber'], $m['total_carbs'] ?? $m['carbs'],
                 $favId, $userId]
            );
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return ['ok' => false, 'error' => 'Another favorite already has that name.',
                        'status' => 409];
            }
            throw $e;
        }
        return ['ok' => true, 'favorites' => self::favorites($userId)];
    }

    public static function deleteFavorite(int $userId, int $favId): array
    {
        $n = DB::run(
            'DELETE FROM favorite_foods WHERE id = ? AND user_id = ?', [$favId, $userId]
        )->rowCount();
        if ($n === 0) {
            return ['ok' => false, 'error' => 'No such favorite.'];
        }
        // logged_entries.favorite_id is ON DELETE SET NULL, so history survives
        // un-favoriting; the star just stops showing.
        return ['ok' => true, 'favorites' => self::favorites($userId)];
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * Coerce a food payload into stored units, deriving net carbs.
     *
     * Accepts either `carbs` (already net) or `total_carbs` + `fiber`. When both
     * a total and a fiber figure are present, net is derived and wins — that is
     * the intake-time rule, and honouring a caller's stale `carbs` over it would
     * silently store the wrong number.
     */
    private static function normaliseMacros(array $f): array
    {
        $num = static function ($v): ?float {
            if ($v === null || $v === '') {
                return null;
            }
            return is_numeric($v) ? (float) $v : null;
        };

        $total = $num($f['total_carbs'] ?? null);
        $fiber = $num($f['fiber'] ?? null);
        $net   = $num($f['carbs'] ?? null);

        if ($total !== null && $fiber !== null) {
            $net = max(0.0, $total - $fiber);   // fiber can exceed carbs in bad data
        } elseif ($net === null && $total !== null) {
            $net = $total;                      // no fiber figure: total IS net
        }
        // An explicit net `carbs` with a fiber figure and NO total means "this is
        // already net, and here is the fiber for the record". Keeping the fiber
        // while leaving net alone is the point: deriving net - fiber here would
        // subtract it twice, which is exactly how a 50g net breakfast became 44g
        // when it was starred as a favorite. Prescribed carbs arrive this way,
        // because a prescribed meal's carbs_g is already net.

        if ($total === null && $net !== null && $fiber !== null) {
            $total = $net + $fiber;   // reconstruct, so the row is self-consistent
        }

        return [
            'calories'    => round($num($f['calories'] ?? null) ?? 0.0),
            'protein'     => round($num($f['protein'] ?? null) ?? 0.0, 1),
            'fat'         => round($num($f['fat'] ?? null) ?? 0.0, 1),
            'carbs'       => round($net ?? 0.0, 1),
            'fiber'       => $fiber === null ? null : round($fiber, 1),
            'total_carbs' => $total === null ? null : round($total, 1),
            'serving_g'   => ($s = $num($f['serving_g'] ?? null)) === null ? null : (int) round($s),
        ];
    }

    /** Meal total: the additive delta plus every entry. */
    private static function mealTotal(array $delta, array $entries): array
    {
        $t = [
            'calories' => (float) $delta['calories'],
            'protein'  => (float) $delta['protein'],
            'fat'      => (float) $delta['fat'],
            'carbs'    => (float) $delta['carbs'],
        ];
        foreach ($entries as $e) {
            $t['calories'] += (float) $e['calories'];
            $t['protein']  += (float) $e['protein'];
            $t['fat']      += (float) $e['fat'];
            $t['carbs']    += (float) $e['carbs'];
        }
        return [
            'calories' => round($t['calories']),
            'protein'  => round($t['protein'], 1),
            'fat'      => round($t['fat'], 1),
            'carbs'    => round($t['carbs'], 1),
        ];
    }

    /** An entry plus the day it belongs to, only if this user owns it. */
    private static function ownedEntry(int $userId, int $entryId): ?array
    {
        return DB::one(
            'SELECT le.*, lm.logged_day_id, ld.log_date
             FROM logged_entries le
             JOIN logged_meals lm ON lm.id = le.logged_meal_id
             JOIN logged_days  ld ON ld.id = lm.logged_day_id
             WHERE le.id = ? AND ld.user_id = ?',
            [$entryId, $userId]
        );
    }

    private static function shapeEntry(array $e): array
    {
        return [
            'id'          => (int) $e['id'],
            'name'        => $e['name'],
            'serving_g'   => self::intOrNull($e['serving_g']),
            'calories'    => (float) $e['calories'],
            'protein'     => (float) $e['protein_g'],
            'fat'         => (float) $e['fat_g'],
            'carbs'       => (float) $e['carbs_g'],
            'fiber'       => $e['fiber_g'] === null ? null : (float) $e['fiber_g'],
            'total_carbs' => $e['total_carbs_g'] === null ? null : (float) $e['total_carbs_g'],
            'source'      => (string) $e['source'],
            'source_ref'  => $e['source_ref'],
            'favorite_id' => self::intOrNull($e['favorite_id']),
        ];
    }

    /** What the live plan says to eat on a date, for the "as planned" tap. */
    private static function prescribedMeals(int $userId, string $date): array
    {
        $out = [];
        foreach (DB::all(
            'SELECT pm.id, pm.slot, pm.kind, pm.name, pm.calories, pm.protein_g,
                    pm.fat_g, pm.carbs_g, pm.prep_minutes, pm.method, pm.ingredients,
                    pm.target_note
             FROM prescribed_meals pm
             JOIN prescribed_days pd ON pd.id = pm.prescribed_day_id
             JOIN plan_versions pv   ON pv.id = pd.plan_version_id
             WHERE pv.user_id = ? AND pv.superseded_at IS NULL AND pd.day_date = ?
             ORDER BY pm.id',
            [$userId, $date]
        ) as $m) {
            $ing = $m['ingredients'] === null ? null : json_decode((string) $m['ingredients'], true);
            $out[] = [
                'id'           => (int) $m['id'],
                'slot'         => (string) $m['slot'],
                'kind'         => (string) $m['kind'],
                'name'         => $m['name'],
                'calories'     => (float) $m['calories'],
                'protein'      => (float) $m['protein_g'],
                'fat'          => (float) $m['fat_g'],
                'carbs'        => (float) $m['carbs_g'],
                'prep_minutes' => self::intOrNull($m['prep_minutes']),
                'method'       => $m['method'],
                'ingredients'  => is_array($ing) ? $ing : [],
                'target_note'  => $m['target_note'],
            ];
        }
        return $out;
    }

    private static function emptySlots(): array
    {
        return array_map(static fn(string $s): array => self::emptySlot($s), self::SLOTS);
    }

    private static function emptySlot(string $slot): array
    {
        $zero = ['calories' => 0.0, 'protein' => 0.0, 'fat' => 0.0, 'carbs' => 0.0];
        return [
            'id'        => null,
            'slot'      => $slot,
            'adherence' => 'unplanned',
            'prescribed_meal_id' => null,
            'delta'     => $zero,
            'notes'     => null,
            'entries'   => [],
            'total'     => $zero,
        ];
    }

    private static function intOrNull($v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
