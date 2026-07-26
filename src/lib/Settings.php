<?php
declare(strict_types=1);

/**
 * The settings that were never questions.
 *
 * Onboarding asks about tone, nudges, units and the rest, and §9 of the quiz already edits
 * all of those — a second editor for them would be two implementations of one field, which
 * is how they drift. This file deliberately covers only what the quiz does NOT ask:
 *
 *   coaching_paused           the kill switch on six cron jobs
 *   checkin_weekday/hour      when the weekly check-in opens
 *   plan_generation_*         when next week gets written
 *   review_hour               the Next Day Review, 0 meaning off
 *   core_emphasis             how much core work every session carries
 *
 * Every one of those is read at runtime and every one had a database default and no way to
 * change it. `coaching_paused` is the worst of them: a user on holiday or hurt had no way to
 * stop the app writing plans and chasing them for answers, short of abandoning the account.
 *
 * ALSO HERE: turning a soft constraint off.
 *
 * That belongs with settings rather than with onboarding because of what it is NOT allowed
 * to do. SPEC-safety §6: constraints change only through deliberate profile edits, and a
 * hard one is a limit the user set for a reason. So a soft preference gets a switch, and a
 * hard constraint does not — it changes by re-answering the question that created it, which
 * is the friction the spec is asking for. deactivate() rejects a hard row outright rather
 * than trusting a caller to check first.
 */
final class Settings
{
    /**
     * Weekday numbering is ISO: 1 = Monday, 7 = Sunday.
     *
     * Matches Schedule and the cron jobs. Worth stating because MySQL's DAYOFWEEK is
     * 1 = Sunday, and mixing the two produced a "next Monday" that was the Sunday before
     * for all seven weekdays.
     */
    private const MIN_WEEKDAY = 1;
    private const MAX_WEEKDAY = 7;

    /** The evening spread the schedule slots are allowed to occupy. */
    private const MIN_HOUR = 0;
    private const MAX_HOUR = 23;

    private const CORE_EMPHASIS = ['off', 'light', 'standard', 'heavy'];

    /** Read the settings, with the labels the UI needs to render them. */
    public static function forUser(int $userId): array
    {
        $p = DB::one(
            'SELECT coaching_paused, checkin_weekday, checkin_hour,
                    plan_generation_weekday, plan_generation_hour, review_hour,
                    core_emphasis, timezone, units
             FROM profiles WHERE user_id = ?',
            [$userId]
        );
        if ($p === null) {
            return ['error' => 'No profile yet.'];
        }

        return [
            'error'           => null,
            'coaching_paused' => (bool) $p['coaching_paused'],
            'checkin'         => [
                'weekday' => (int) $p['checkin_weekday'],
                'hour'    => (int) $p['checkin_hour'],
            ],
            'plan'            => [
                'weekday' => (int) $p['plan_generation_weekday'],
                'hour'    => (int) $p['plan_generation_hour'],
            ],
            // 0 is a real value here, not a missing one: it means the review is off.
            'review_hour'     => (int) $p['review_hour'],
            'core_emphasis'   => (string) $p['core_emphasis'],
            /*
             * Read-only, both of them, and included anyway.
             *
             * The timezone is detected by the browser and corrects itself after a move, so
             * an editor would be a worse source of truth than the device. Units are a §9
             * quiz answer. But both change how every other line on this screen reads: "18:00"
             * means nothing without knowing which zone it is in.
             */
            'timezone'        => $p['timezone'],
            'units'           => (string) $p['units'],
        ];
    }

    /**
     * Write whatever was sent, and only that.
     *
     * PARTIAL BY DESIGN. The onboarding projectors update every column of their section
     * unconditionally, so a null answer overwrites with null; that is tolerable when a whole
     * section is submitted at once and wrong here, where a user toggling "pause" must not
     * silently reset their check-in day. So each field is applied only when present, and an
     * absent key means "leave it alone" rather than "clear it".
     *
     * Returns ['ok', 'error', 'changed' => list of field names].
     */
    public static function save(int $userId, array $body): array
    {
        $sets    = [];
        $params  = [];
        $changed = [];

        /** Queue one column, or return the reason it cannot be queued. */
        $put = function (string $column, $value) use (&$sets, &$params, &$changed): void {
            $sets[]    = "{$column} = ?";
            $params[]  = $value;
            $changed[] = $column;
        };

        if (array_key_exists('coaching_paused', $body)) {
            $v = Validate::bool($body['coaching_paused']);
            if ($v === null) {
                return self::fail('Pause has to be true or false.');
            }
            $put('coaching_paused', $v ? 1 : 0);
        }

        foreach ([
            ['checkin_weekday', 'checkin_weekday'],
            ['plan_generation_weekday', 'plan_generation_weekday'],
        ] as [$key, $column]) {
            if (array_key_exists($key, $body)) {
                $v = Validate::intRange($body[$key], self::MIN_WEEKDAY, self::MAX_WEEKDAY);
                if ($v === null) {
                    return self::fail('Pick a day of the week.');
                }
                $put($column, $v);
            }
        }

        foreach ([
            ['checkin_hour', 'checkin_hour'],
            ['plan_generation_hour', 'plan_generation_hour'],
        ] as [$key, $column]) {
            if (array_key_exists($key, $body)) {
                $v = Validate::intRange($body[$key], self::MIN_HOUR, self::MAX_HOUR);
                if ($v === null) {
                    return self::fail('Pick an hour between 0 and 23.');
                }
                $put($column, $v);
            }
        }

        if (array_key_exists('review_hour', $body)) {
            // 0 is legal and means off, which is why this is not folded into the loop
            // above with a 1..23 floor.
            $v = Validate::intRange($body['review_hour'], 0, self::MAX_HOUR);
            if ($v === null) {
                return self::fail('Pick an hour, or 0 to turn the preview off.');
            }
            $put('review_hour', $v);
        }

        if (array_key_exists('core_emphasis', $body)) {
            $v = Validate::enum($body['core_emphasis'], self::CORE_EMPHASIS);
            if ($v === null) {
                return self::fail('Core work has to be off, light, standard or heavy.');
            }
            $put('core_emphasis', $v);
        }

        if ($sets === []) {
            return ['ok' => false, 'error' => 'Nothing to change.', 'changed' => []];
        }

        /*
         * The check-in has to come BEFORE the plan it feeds, or it is pointless.
         *
         * §4: the check-in opens 24 hours before generation so there is time to answer. A
         * user who moved generation to Saturday morning while the check-in stayed at
         * Saturday evening would get a plan built from data that had not been collected yet,
         * and would never understand why their answers seemed ignored.
         *
         * Checked against the MERGED result, not the submitted fields: either one can move
         * independently, so validating only what was sent would miss the case where the new
         * value collides with the stored one.
         */
        $merged = self::mergedSchedule($userId, $body);
        if ($merged !== null) {
            [$ci, $pg] = $merged;
            if ($ci >= $pg) {
                return self::fail(
                    'Your check-in needs to open before your plan is written, so there is '
                    . 'time to answer it. Move one of them.'
                );
            }
        }

        DB::run(
            'UPDATE profiles SET ' . implode(', ', $sets) . ' WHERE user_id = ?',
            [...$params, $userId]
        );

        return ['ok' => true, 'error' => null, 'changed' => $changed];
    }

    /**
     * The two schedule slots as comparable "hours into the week" figures.
     *
     * Returns null when the profile is missing. Both slots are converted to a single
     * number so "Saturday 18:00 before Sunday 18:00" is an integer comparison rather than
     * a pair of conditionals that get the wraparound wrong.
     */
    private static function mergedSchedule(int $userId, array $body): ?array
    {
        $p = DB::one(
            'SELECT checkin_weekday, checkin_hour,
                    plan_generation_weekday, plan_generation_hour
             FROM profiles WHERE user_id = ?',
            [$userId]
        );
        if ($p === null) {
            return null;
        }

        $pick = function (string $key, string $column) use ($body, $p): int {
            return array_key_exists($key, $body)
                ? (int) $body[$key]
                : (int) $p[$column];
        };

        $ciDay  = $pick('checkin_weekday', 'checkin_weekday');
        $ciHour = $pick('checkin_hour', 'checkin_hour');
        $pgDay  = $pick('plan_generation_weekday', 'plan_generation_weekday');
        $pgHour = $pick('plan_generation_hour', 'plan_generation_hour');

        return [$ciDay * 24 + $ciHour, $pgDay * 24 + $pgHour];
    }

    private static function fail(string $why): array
    {
        return ['ok' => false, 'error' => $why, 'changed' => []];
    }

    // ---- constraints ---------------------------------------------------------

    /**
     * Everything the coach is working around, for the UI.
     *
     * Includes INACTIVE rows, unlike the onboarding endpoint. A user who switched a
     * preference off needs to see that it is off and be able to put it back; hiding it would
     * make the switch feel like a delete and leave them no way to undo a mistake.
     *
     * Returns the row id, which the onboarding endpoint deliberately does not — that one is
     * a display list and this one is an editor.
     */
    public static function constraints(int $userId): array
    {
        $rows = DB::all(
            'SELECT id, kind, tier, subject, reason, guidance, floor_value, source, active
             FROM user_constraints
             WHERE user_id = ?
             ORDER BY active DESC, FIELD(tier, "hard", "soft"), kind, subject',
            [$userId]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'       => (int) $r['id'],
                'kind'     => (string) $r['kind'],
                'tier'     => (string) $r['tier'],
                'subject'  => (string) $r['subject'],
                'reason'   => $r['reason'],
                'guidance' => $r['guidance'],
                'floor'    => $r['floor_value'] === null ? null : (float) $r['floor_value'],
                'source'   => (string) $r['source'],
                'active'   => (bool) $r['active'],
                /*
                 * Can the user switch this one off here?
                 *
                 * Sent by the server rather than derived on the client, so the rule lives in
                 * one place and the UI cannot offer a control the API will refuse. Soft only:
                 * a hard constraint changes by re-answering the question behind it.
                 */
                'switchable' => (string) $r['tier'] === 'soft',
                'meaning'  => (string) $r['tier'] === 'hard'
                    ? 'Never prescribed. Enforced in code; a plan that breaks it is rejected.'
                    : 'Strongly avoided. Can be suggested with a reason, and you can turn it down.',
            ];
        }
        return $out;
    }

    /**
     * Turn a soft constraint off, or back on.
     *
     * THE HARD-TIER REFUSAL IS HERE, not in the route, and not on the client. SPEC-safety §6
     * lists the ways a constraint must not change and the reason: "an LLM that can be argued
     * out of a constraint has no constraints." The same logic applies to a UI that can be
     * tapped out of one. A hard row is rejected by the WHERE clause itself, so no caller can
     * reach it by passing the right id.
     *
     * Reversible on purpose. This sets `active`, it does not DELETE: the row is the record of
     * something the user told us, and a preference switched off last month is still the
     * answer to "why did you stop suggesting salmon".
     */
    public static function setConstraintActive(int $userId, int $id, bool $active): array
    {
        $c = DB::one(
            'SELECT id, tier, subject, active, source FROM user_constraints
             WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
        if ($c === null) {
            return ['ok' => false, 'error' => 'No such preference.', 'changed' => false];
        }

        if ((string) $c['tier'] !== 'soft') {
            /*
             * Named plainly, and pointing at the way that DOES work.
             *
             * A refusal with no route forward reads as a bug. This one is a deliberate
             * design decision, so the message says what to do instead.
             */
            return ['ok' => false, 'changed' => false,
                    'error' => 'That one is a hard limit, so it does not get switched off '
                             . 'here. Change the answer it came from in your profile '
                             . 'sections and it will follow.'];
        }

        if ((bool) $c['active'] === $active) {
            return ['ok' => true, 'error' => null, 'changed' => false];
        }

        DB::tx(function () use ($c, $userId, $active): void {
            DB::run(
                'UPDATE user_constraints SET active = ? WHERE id = ?',
                [$active ? 1 : 0, (int) $c['id']]
            );

            // Audited, like the tier change is. SPEC-safety §6: every change is audited so a
            // plan can always be explained after the fact.
            DB::run(
                'INSERT INTO user_constraint_audit
                    (constraint_id, user_id, action, old_value, new_value)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    (int) $c['id'], $userId,
                    $active ? 'reactivate' : 'deactivate',
                    json_encode(['active' => (bool) $c['active']]),
                    json_encode(['active' => $active, 'via' => 'profile settings']),
                ]
            );
        });

        return ['ok' => true, 'error' => null, 'changed' => true];
    }
}
