<?php
declare(strict_types=1);

/**
 * The goal evaluator — one place that decides whether a day hit its target.
 *
 * This replaces Keto Tracker's `isDayOnKetoTarget`, which was duplicated in
 * FOUR files (App.jsx, leaderboard.js, challenges/standings.js,
 * challenges/finalize.js) with the keto rule hardcoded into each. Every
 * streak, badge, award and challenge routed through those copies, which is
 * precisely why that app could not become a training app.
 *
 * Here the rule is DATA — a per-macro constraint set from goal_presets — and
 * this is the only code that interprets it.
 *
 * SERVER-SIDE ONLY. The client displays a verdict; it never computes one.
 * Keto Tracker awarded long-term badges from the browser, which was
 * trivially forgeable.
 *
 * See docs/SPEC-safety.md and keto-extract/specs/SPEC-targets.md.
 */
final class Goals
{
    /** Macros scored, in a fixed order so output is stable. */
    public const MACROS = ['calories', 'protein', 'fat', 'carbs'];

    public const MODES = ['at_least', 'at_most', 'within_pct', 'range_pct', 'ignore'];

    /**
     * Evaluate one day.
     *
     * @param array $totals  actual intake: ['calories'=>1850, 'protein'=>165, ...]
     * @param array $target  the day's targets, same keys
     * @param array $goal    constraint set: ['protein'=>['mode'=>'at_least'], ...]
     *
     * @return array{
     *     on_target: bool|null,
     *     failures: list<string>,
     *     passes: list<string>,
     *     ignored: list<string>,
     *     proximity: float|null,
     *     detail: array<string, array>,
     *     short_but_ok: bool
     * }
     *
     * on_target is NULL when every constraint is `ignore` — a misconfigured
     * goal must not silently mark every day perfect.
     */
    public static function evaluateDay(array $totals, array $target, array $goal): array
    {
        $failures = [];
        $passes   = [];
        $ignored  = [];
        $detail   = [];
        $distances = [];

        foreach (self::MACROS as $macro) {
            $rule = $goal[$macro] ?? ['mode' => 'ignore'];
            $mode = $rule['mode'] ?? 'ignore';

            if ($mode === 'ignore') {
                $ignored[] = $macro;
                $detail[$macro] = ['mode' => 'ignore'];
                continue;
            }

            $targetVal = self::num($target[$macro] ?? null);
            $actualVal = self::num($totals[$macro] ?? null);

            // A constraint against a missing or zero target is not scoreable.
            // Treated as ignored rather than failed: the fault is the target's,
            // and failing a user for our own missing data would be wrong.
            if ($targetVal === null || $targetVal <= 0.0) {
                $ignored[] = $macro;
                $detail[$macro] = ['mode' => $mode, 'note' => 'no usable target'];
                continue;
            }

            $actualVal ??= 0.0;
            $pct = $actualVal / $targetVal;

            [$ok, $dist] = self::apply($mode, $rule, $actualVal, $targetVal, $pct);

            $detail[$macro] = [
                'mode'     => $mode,
                'actual'   => round($actualVal, 1),
                'target'   => round($targetVal, 1),
                'pct'      => round($pct, 4),
                'ok'       => $ok,
                'distance' => round($dist, 4),
            ];

            $distances[] = $dist;
            if ($ok) {
                $passes[] = $macro;
            } else {
                $failures[] = $macro;
            }
        }

        // Every constraint ignored → no verdict. Deliberately null, not true.
        if ($distances === []) {
            return [
                'on_target'    => null,
                'failures'     => [],
                'passes'       => [],
                'ignored'      => $ignored,
                'proximity'    => null,
                'detail'       => $detail,
                'short_but_ok' => false,
            ];
        }

        // Averaged over SCORED constraints only. A hardcoded /4 would reward
        // goals that ignore macros, which is the bug the original had.
        $proximity = array_sum($distances) / count($distances);

        return [
            'on_target'    => $failures === [],
            'failures'     => $failures,
            'passes'       => $passes,
            'ignored'      => $ignored,
            'proximity'    => round($proximity, 4),
            'detail'       => $detail,
            'short_but_ok' => self::shortButOk($detail, $failures),
        ];
    }

    /**
     * Apply one constraint. Returns [passed, distance-from-ideal].
     *
     * Distance is directional and mode-specific, which is the whole point:
     * at_least penalises only under, at_most only over, and the banded modes
     * penalise distance outside the band in either direction. 0.0 = ideal.
     */
    private static function apply(
        string $mode,
        array $rule,
        float $actual,
        float $target,
        float $pct
    ): array {
        switch ($mode) {
            case 'at_least':
                return [$actual >= $target, max(0.0, 1.0 - $pct)];

            case 'at_most':
                // Strictly under, matching the original keto rule for carbs.
                return [$actual < $target, max(0.0, $pct - 1.0)];

            case 'within_pct':
                $p  = self::num($rule['pct'] ?? null) ?? 0.05;
                $lo = 1.0 - $p;
                $hi = 1.0 + $p;
                return [$pct >= $lo && $pct <= $hi, self::bandDistance($pct, $lo, $hi)];

            case 'range_pct':
                $lo = self::num($rule['lo'] ?? null) ?? 0.0;
                $hi = self::num($rule['hi'] ?? null) ?? PHP_FLOAT_MAX;
                return [$pct >= $lo && $pct <= $hi, self::bandDistance($pct, $lo, $hi)];

            default:
                // Unknown mode: do not silently pass. Treated as a failure so a
                // typo in a preset surfaces instead of quietly disabling a rule.
                return [false, 1.0];
        }
    }

    /** How far outside [lo, hi] a ratio sits. 0.0 when inside. */
    private static function bandDistance(float $pct, float $lo, float $hi): float
    {
        if ($pct < $lo) {
            return $lo - $pct;
        }
        if ($pct > $hi) {
            return $pct - $hi;
        }
        return 0.0;
    }

    /**
     * "Calories short, but the macros landed."
     *
     * Per SPEC-safety.md §8: for someone building intake up from a low base, a
     * day where protein is on target and the only miss is being UNDER on
     * calories is adherent with a note, not a failure. Coming from a 500-700
     * kcal history, a normal intake looks enormous, and punishing an honest
     * attempt is how the app loses that user.
     *
     * This flags the case; it does not overturn the verdict. Callers decide
     * how to present it, and a preset with a generous lower bound (see
     * 'recomp-building-intake') keeps such days on target in the first place.
     */
    private static function shortButOk(array $detail, array $failures): bool
    {
        if ($failures === []) {
            return false;   // already on target; nothing to soften
        }
        if ($failures !== ['calories']) {
            return false;   // something other than calories missed
        }
        // Protein must have been scored AND passed.
        if (($detail['protein']['ok'] ?? null) !== true) {
            return false;
        }
        // And the calorie miss must be UNDER, not over.
        return ($detail['calories']['pct'] ?? 1.0) < 1.0;
    }

    /** Number of violated constraints, 0..4. */
    public static function countFailures(array $verdict): int
    {
        return count($verdict['failures'] ?? []);
    }

    /**
     * Load a user's constraint set, falling back to the preset that suits
     * their primary goal, then to general-health.
     */
    public static function forUser(int $userId): array
    {
        $row = DB::one(
            'SELECT gp.slug, gp.constraints
             FROM goals g
             LEFT JOIN goal_presets gp ON gp.id = g.goal_preset_id
             WHERE g.user_id = ? AND g.status = "active"
             ORDER BY g.created_at DESC
             LIMIT 1',
            [$userId]
        );

        if ($row !== null && $row['constraints'] !== null) {
            return self::decode($row['constraints']);
        }

        // No explicit preset: match on primary_goal.
        $goal = DB::one(
            'SELECT primary_goal FROM goals
             WHERE user_id = ? AND status = "active"
             ORDER BY created_at DESC LIMIT 1',
            [$userId]
        );
        if ($goal !== null) {
            $byGoal = DB::one(
                'SELECT constraints FROM goal_presets
                 WHERE JSON_CONTAINS(suits, JSON_QUOTE(?)) LIMIT 1',
                [$goal['primary_goal']]
            );
            if ($byGoal !== null) {
                return self::decode($byGoal['constraints']);
            }
        }

        $fallback = DB::one("SELECT constraints FROM goal_presets WHERE slug = 'general-health'");
        return $fallback !== null ? self::decode($fallback['constraints']) : [];
    }

    /** Load a preset's constraints by slug. */
    public static function preset(string $slug): ?array
    {
        $row = DB::one('SELECT constraints FROM goal_presets WHERE slug = ?', [$slug]);
        return $row === null ? null : self::decode($row['constraints']);
    }

    /**
     * Validate a constraint set before it is stored. Returns a list of
     * problems; empty means valid.
     *
     * Worth doing at write time: a malformed preset is otherwise discovered as
     * a run of oddly-scored days.
     */
    public static function validate(array $goal): array
    {
        $errors = [];
        $scored = 0;

        foreach ($goal as $macro => $rule) {
            if (!in_array($macro, self::MACROS, true)) {
                $errors[] = "unknown macro '{$macro}'";
                continue;
            }
            if (!is_array($rule) || !isset($rule['mode'])) {
                $errors[] = "{$macro}: missing mode";
                continue;
            }
            $mode = $rule['mode'];
            if (!in_array($mode, self::MODES, true)) {
                $errors[] = "{$macro}: unknown mode '{$mode}'";
                continue;
            }
            if ($mode !== 'ignore') {
                $scored++;
            }
            if ($mode === 'within_pct') {
                $p = self::num($rule['pct'] ?? null);
                if ($p === null || $p <= 0 || $p >= 1) {
                    $errors[] = "{$macro}: within_pct needs pct between 0 and 1";
                }
            }
            if ($mode === 'range_pct') {
                $lo = self::num($rule['lo'] ?? null);
                $hi = self::num($rule['hi'] ?? null);
                if ($lo === null || $hi === null) {
                    $errors[] = "{$macro}: range_pct needs lo and hi";
                } elseif ($lo > $hi) {
                    $errors[] = "{$macro}: range_pct lo ({$lo}) exceeds hi ({$hi})";
                }
            }
        }

        if ($scored === 0) {
            $errors[] = 'every constraint is ignore — no day could ever be scored';
        }
        return $errors;
    }

    /** Sum a day's logged intake: manual deltas plus entries. */
    public static function dayTotals(int $loggedDayId): array
    {
        // The delta is ADDITIVE, not an override (SPEC-nutrition.md §1): log
        // items from search, then nudge +50 cal for cooking oil, and the nudge
        // survives adding or removing items.
        $row = DB::one(
            'SELECT
                COALESCE(SUM(lm.delta_calories), 0)  AS d_cal,
                COALESCE(SUM(lm.delta_protein_g), 0) AS d_pro,
                COALESCE(SUM(lm.delta_fat_g), 0)     AS d_fat,
                COALESCE(SUM(lm.delta_carbs_g), 0)   AS d_carb
             FROM logged_meals lm WHERE lm.logged_day_id = ?',
            [$loggedDayId]
        ) ?? [];

        $items = DB::one(
            'SELECT
                COALESCE(SUM(le.calories), 0)  AS cal,
                COALESCE(SUM(le.protein_g), 0) AS pro,
                COALESCE(SUM(le.fat_g), 0)     AS fat,
                COALESCE(SUM(le.carbs_g), 0)   AS carb
             FROM logged_entries le
             JOIN logged_meals lm ON lm.id = le.logged_meal_id
             WHERE lm.logged_day_id = ?',
            [$loggedDayId]
        ) ?? [];

        return [
            'calories' => (float) ($row['d_cal'] ?? 0)  + (float) ($items['cal'] ?? 0),
            'protein'  => (float) ($row['d_pro'] ?? 0)  + (float) ($items['pro'] ?? 0),
            'fat'      => (float) ($row['d_fat'] ?? 0)  + (float) ($items['fat'] ?? 0),
            'carbs'    => (float) ($row['d_carb'] ?? 0) + (float) ($items['carb'] ?? 0),
        ];
    }

    /**
     * Evaluate a logged day against its prescribed targets and cache the
     * verdict on the row. Returns the verdict, or null if the day has no
     * prescribed targets (baseline week 1, by design).
     */
    public static function evaluateAndCache(int $loggedDayId): ?array
    {
        $day = DB::one(
            'SELECT ld.id, ld.user_id, ld.log_date, ld.plan_version_id
             FROM logged_days ld WHERE ld.id = ?',
            [$loggedDayId]
        );
        if ($day === null) {
            return null;
        }

        $pd = DB::one(
            'SELECT target_calories, target_protein_g, target_fat_g, target_carbs_g, constraints
             FROM prescribed_days
             WHERE plan_version_id = ? AND day_date = ?',
            [$day['plan_version_id'], $day['log_date']]
        );
        if ($pd === null) {
            return null;   // nothing prescribed — not a failure
        }

        $target = [
            'calories' => (float) $pd['target_calories'],
            'protein'  => (float) $pd['target_protein_g'],
            'fat'      => (float) $pd['target_fat_g'],
            'carbs'    => (float) $pd['target_carbs_g'],
        ];

        // Prefer the constraints frozen onto the prescribed day, so a later
        // change to the user's goal does not retroactively re-judge history.
        $goal = self::decode($pd['constraints']);
        if ($goal === []) {
            $goal = self::forUser((int) $day['user_id']);
        }

        $verdict = self::evaluateDay(self::dayTotals($loggedDayId), $target, $goal);

        DB::run(
            'UPDATE logged_days
             SET macro_on_target = ?, macro_short_but_ok = ?, failure_count = ?, proximity = ?
             WHERE id = ?',
            [
                $verdict['on_target'] === null ? null : (int) $verdict['on_target'],
                (int) $verdict['short_but_ok'],
                count($verdict['failures']),
                $verdict['proximity'],
                $loggedDayId,
            ]
        );

        return $verdict;
    }

    /** Decode a JSON constraint column to an array. */
    private static function decode($json): array
    {
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || $json === '') {
            return [];
        }
        $d = json_decode($json, true);
        return is_array($d) ? $d : [];
    }

    /** Coerce to float, or null when genuinely absent. */
    private static function num($v): ?float
    {
        if ($v === null || $v === '' || is_bool($v)) {
            return null;
        }
        return is_numeric($v) ? (float) $v : null;
    }
}
