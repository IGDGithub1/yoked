<?php
declare(strict_types=1);

/**
 * The Next Day Review (SPEC-coaching §4.1a).
 *
 * Late each evening, tomorrow's session and meals, with a chance to call an audible
 * before the day arrives.
 *
 * The reason it exists is worth keeping in view: "the Sunday plan cannot know
 * everything. Travel discovered on Wednesday for a Friday session has no other path
 * into the plan — the plan was built before the fact existed, and waiting until Friday
 * morning wastes the day."
 *
 * The constraint that shapes every decision here is the last bullet: "Optional and
 * dismissible; it must not become the noise the user was promised they'd be spared." So
 * it appears in an evening window, once, and stays gone once dismissed. A card that
 * reappears on every page load would be exactly the thing §9 spends its whole design
 * avoiding.
 *
 * Assembled on read from prescribed_sessions and prescribed_meals rather than stored:
 * there is no "review" entity, only a view of tomorrow.
 */
final class Tomorrow
{
    /**
     * Prep minutes past which a meal is worth flagging tonight.
     *
     * §4.1a gives the example "tomorrow's dinner needs 40 minutes". 30 rather than 40 so
     * the example itself lands comfortably inside the threshold rather than exactly on
     * it, and because the useful signal is "this needs planning", which starts before
     * forty minutes.
     */
    public const PREP_HEAVY_MINUTES = 30;

    /**
     * Is the review window open, and is there anything in it?
     *
     * Returns null when the card should not appear at all, which is most of the time:
     * before the evening hour, after it was dismissed, or when tomorrow has nothing
     * prescribed. Returning null rather than an empty structure keeps the "should this
     * render" decision on the server, where the schedule lives.
     */
    public static function review(array $user, ?string $tz = null): ?array
    {
        $userId = (int) $user['id'];

        $hour = (int) (DB::one(
            'SELECT review_hour FROM profiles WHERE user_id = ?', [$userId]
        )['review_hour'] ?? 20);

        // 0 means the user has turned it off. A user who does not want to think about
        // tomorrow tonight is precisely who §4.1a promises not to nag.
        if ($hour === 0) {
            return null;
        }

        $now      = Schedule::now($tz);
        $today    = $now->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');

        // Evening only. Before the hour there is nothing to say: the day being reviewed
        // has not finished.
        if ((int) $now->format('G') < $hour) {
            return null;
        }

        if (self::isDismissed($userId, $tomorrow)) {
            return null;
        }

        $sessions = self::sessionsFor($userId, $tomorrow);
        $meals    = self::mealsFor($userId, $tomorrow);

        // Nothing prescribed. Common during the baseline, where there is deliberately no
        // plan, and a card saying "tomorrow: nothing" is noise by any definition.
        if ($sessions === [] && $meals === []) {
            return null;
        }

        return [
            'date'      => $tomorrow,
            'reviewed_on' => $today,
            'sessions'  => $sessions,
            'meals'     => $meals,
            // Pulled out rather than left for the client to compute: the threshold is a
            // coaching decision, not a display one.
            'prep_flags' => self::prepFlags($meals),
            // What the user already told us about tomorrow, so the card does not invite
            // them to say it twice.
            'circumstances' => self::circumstancesFor($userId, $tomorrow),
        ];
    }

    /** Tomorrow's prescribed sessions, with their exercises. */
    private static function sessionsFor(int $userId, string $date): array
    {
        $out = [];
        foreach (DB::all(
            'SELECT ps.id, ps.session_type, ps.focus, ps.focus_detail, ps.is_committed,
                    ps.target_minutes, ps.location, ps.warmup_minutes, ps.warmup_detail,
                    ps.warmup_required, ps.rationale
             FROM prescribed_sessions ps
             JOIN plan_versions pv ON pv.id = ps.plan_version_id
             WHERE pv.user_id = ? AND pv.superseded_at IS NULL AND ps.session_date = ?
             ORDER BY ps.is_committed DESC, ps.sort_order, ps.id',
            [$userId, $date]
        ) as $s) {
            $out[] = [
                'prescribed_session_id' => (int) $s['id'],
                'session_type'    => (string) $s['session_type'],
                'focus'           => $s['focus'],
                'focus_detail'    => $s['focus_detail'],
                'is_committed'    => (bool) $s['is_committed'],
                'target_minutes'  => self::intOrNull($s['target_minutes']),
                'location'        => $s['location'],
                'warmup_minutes'  => self::intOrNull($s['warmup_minutes']),
                'warmup_detail'   => $s['warmup_detail'],
                'warmup_required' => (bool) $s['warmup_required'],
                'rationale'       => $s['rationale'],
                // Names only. The review is a heads-up, not the logging screen — a full
                // set-and-rep table the night before is detail nobody acts on yet.
                'exercises'       => self::exerciseNames((int) $s['id']),
            ];
        }
        return $out;
    }

    /** @return list<string> */
    private static function exerciseNames(int $sessionId): array
    {
        $out = [];
        foreach (DB::all(
            'SELECT e.name
             FROM prescribed_exercises pe
             JOIN exercises e ON e.id = pe.exercise_id
             WHERE pe.session_id = ?
             ORDER BY FIELD(pe.block, \'warmup\', \'main\', \'core\', \'cooldown\'),
                      pe.sort_order, pe.id',
            [$sessionId]
        ) as $r) {
            $out[] = (string) $r['name'];
        }
        return $out;
    }

    /** Tomorrow's prescribed meals, in eating order. */
    private static function mealsFor(int $userId, string $date): array
    {
        $order = "FIELD(pm.slot, 'breakfast','snack_am','lunch','snack_pm','dinner','snack_eve')";

        $out = [];
        foreach (DB::all(
            "SELECT pm.id, pm.slot, pm.kind, pm.name, pm.calories, pm.protein_g,
                    pm.fat_g, pm.carbs_g, pm.prep_minutes, pm.method, pm.ingredients,
                    pm.target_note
             FROM prescribed_meals pm
             JOIN prescribed_days pd ON pd.id = pm.prescribed_day_id
             JOIN plan_versions pv   ON pv.id = pd.plan_version_id
             WHERE pv.user_id = ? AND pv.superseded_at IS NULL AND pd.day_date = ?
             ORDER BY {$order}, pm.id",
            [$userId, $date]
        ) as $m) {
            $ing = $m['ingredients'] === null ? null : json_decode((string) $m['ingredients'], true);
            $out[] = [
                'prescribed_meal_id' => (int) $m['id'],
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

    /**
     * Meals worth knowing about tonight.
     *
     * The whole point of surfacing prep in the evening: "tomorrow's dinner needs 40
     * minutes" is actionable at 8pm and useless at 6pm tomorrow. Flagged server-side so
     * the threshold is one coaching decision rather than a number in a component.
     */
    private static function prepFlags(array $meals): array
    {
        $out = [];
        foreach ($meals as $m) {
            $mins = $m['prep_minutes'];
            if ($mins !== null && $mins >= self::PREP_HEAVY_MINUTES) {
                $out[] = [
                    'slot'         => $m['slot'],
                    'name'         => $m['name'],
                    'prep_minutes' => $mins,
                ];
            }
        }
        return $out;
    }

    /**
     * Circumstances already in force for tomorrow.
     *
     * Shown so the card does not invite the user to report travel they already reported.
     * Same expiry logic as generation uses: a circumstance with an end date stops
     * applying after it.
     */
    private static function circumstancesFor(int $userId, string $date): array
    {
        $out = [];
        foreach (DB::all(
            'SELECT id, kind, detail, starts_on, ends_on
             FROM circumstances
             WHERE user_id = ? AND active = 1
               AND (starts_on IS NULL OR starts_on <= ?)
               AND (ends_on IS NULL OR ends_on >= ?)
             ORDER BY created_at DESC',
            [$userId, $date, $date]
        ) as $c) {
            $out[] = [
                'id'        => (int) $c['id'],
                'kind'      => (string) $c['kind'],
                'detail'    => (string) $c['detail'],
                'starts_on' => $c['starts_on'],
                'ends_on'   => $c['ends_on'],
            ];
        }
        return $out;
    }

    /**
     * Record a fact about tomorrow.
     *
     * §6's rule, and it is structural rather than a matter of prompt wording: "The
     * user's message NEVER edits the plan. It is recorded as a stated circumstance."
     * There is no code path from this method to a plan mutation, so "chat that can be
     * talked into anything" is not a failure mode that exists.
     *
     * What this does today is record the fact and stop. Claude reads it at the next
     * generation, which is honest about the promise: the user is heard and the plan is
     * informed. The full §6 loop — evaluate now, revise or decline with reasoning — is
     * its own piece of work, and pretending tonight's note rewrites tomorrow before that
     * exists would be a lie in the UI.
     *
     * @return array{ok:bool, error:?string, id:?int}
     */
    public static function noteCircumstance(int $userId, string $date, array $body): array
    {
        $kinds = ['travel', 'illness', 'injury', 'schedule', 'equipment', 'other'];
        $kind  = Validate::enum($body['kind'] ?? null, $kinds);
        if ($kind === null) {
            return ['ok' => false, 'error' => 'Pick what kind of thing this is.', 'id' => null];
        }

        $detail = Validate::str($body['detail'] ?? null, 1, 500);
        if ($detail === null) {
            return ['ok' => false, 'error' => 'Say what is going on, in a few words.', 'id' => null];
        }

        /*
         * Scoped to tomorrow by default, because that is what the card is about.
         *
         * §6.4: circumstances expire. "Travelling this week" has an end date; "I hate
         * salmon" is permanent. A note made from the Next Day Review is about a specific
         * day unless the user says otherwise, and defaulting to open-ended would have the
         * app reshuffling around a trip that ended weeks ago.
         */
        $endsOn = $date;
        if (($body['open_ended'] ?? false) === true) {
            $endsOn = null;
        } elseif (Validate::date($body['ends_on'] ?? null) !== null) {
            $endsOn = Validate::date($body['ends_on']);
        }

        $id = DB::insert(
            'INSERT INTO circumstances (user_id, kind, detail, starts_on, ends_on)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $kind, $detail, $date, $endsOn]
        );

        return ['ok' => true, 'error' => null, 'id' => $id];
    }

    // ---- dismissal ----------------------------------------------------------

    public static function isDismissed(int $userId, string $reviewDate): bool
    {
        return DB::one(
            'SELECT 1 AS x FROM review_dismissals WHERE user_id = ? AND review_date = ?',
            [$userId, $reviewDate]
        ) !== null;
    }

    /**
     * Dismiss tomorrow's review.
     *
     * Idempotent by the unique key: dismissing twice is a no-op, and the day after is a
     * different row so the review appears again. That is the entire mechanism, and it is
     * why this is a table rather than a timestamp needing a nightly reset.
     */
    public static function dismiss(int $userId, string $reviewDate): bool
    {
        try {
            DB::insert(
                'INSERT INTO review_dismissals (user_id, review_date) VALUES (?, ?)',
                [$userId, $reviewDate]
            );
            return true;
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return false;   // already dismissed
            }
            throw $e;
        }
    }

    private static function intOrNull($v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
