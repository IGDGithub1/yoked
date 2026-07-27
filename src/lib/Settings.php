<?php
declare(strict_types=1);

/**
 * The settings that were never questions.
 *
 * Two groups, and both are about how the app BEHAVES rather than who the user is.
 *
 * Never asked anywhere, and every one live with a database default and no way to reach it:
 *
 *   coaching_paused           the kill switch on six cron jobs
 *   checkin_weekday/hour      when the weekly check-in opens
 *   plan_generation_*         when next week gets written
 *   review_hour               the Next Day Review, 0 meaning off
 *
 * `core_emphasis` was briefly here too, as a None/Light/Standard/Heavy dial. Removed:
 * §3.3b says core work is "built in by default, not asked as a preference", and the dial
 * contradicted that in both directions — it let a user switch core work off entirely, and it
 * turned a structural programming decision into a preference. The column keeps its
 * 'standard' default and nothing reads it.
 *
 * `coaching_paused` was the worst of them: a user on holiday or hurt had no way to stop the
 * app writing plans and chasing them for answers, short of abandoning the account.
 *
 * And the six that USED to be section 9 of the quiz:
 *
 *   tone, explanation_depth   the voice, and how much it explains
 *   nudge_intensity, _days    how hard it chases silence
 *   hide_photos, _measurements  privacy, for sharing that does not exist yet
 *
 * Those moved because they were never really questions about the user — they are controls
 * over the app, and controls belong where you go to change your mind rather than in a
 * one-way corridor answered once before you have used the thing. The quiz now asks nine
 * sections about the person; this file owns how the app behaves.
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

    /* ---- what used to be section 9 ---------------------------------------- */

    private const TONES = [
        'friendly_encouraging', 'direct_no_fluff', 'high_school_coach',
        'sarcastic_hardass', 'motivational_speaker', 'funny_positive',
    ];
    private const DEPTHS     = ['just_tell_me', 'brief', 'explain'];
    private const INTENSITIES = ['leave_me_alone', 'gentle', 'persistent', 'relentless'];

    /** Days of silence before the app says something. */
    private const MIN_NUDGE_DAYS = 1;
    private const MAX_NUDGE_DAYS = 30;

    /** Read the settings, with the labels the UI needs to render them. */
    public static function forUser(int $userId): array
    {
        $p = DB::one(
            'SELECT coaching_paused, checkin_weekday, checkin_hour,
                    plan_generation_weekday, plan_generation_hour, review_hour,
                    timezone, units,
                    tone, explanation_depth, nudge_intensity, nudge_after_days,
                    hide_photos, hide_measurements
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
            /*
             * Read-only, both of them, and included anyway.
             *
             * The timezone is detected by the browser and corrects itself after a move, so
             * an editor would be a worse source of truth than the device. Units are asked in
             * §1, with height and weight, where the choice actually matters. But both change
             * how the rest of this screen reads: "18:00" means nothing without a zone.
             */
            'timezone'        => $p['timezone'],
            'units'           => (string) $p['units'],

            // The former section 9.
            'tone'              => (string) $p['tone'],
            'explanation_depth' => (string) $p['explanation_depth'],
            'nudge_intensity'   => (string) $p['nudge_intensity'],
            'nudge_after_days'  => (int) $p['nudge_after_days'],
            'hide_photos'       => (bool) $p['hide_photos'],
            'hide_measurements' => (bool) $p['hide_measurements'],

            /*
             * What is in the home gym, editable because it changes.
             *
             * People buy a bench, or move house and lose the garage rack. Onboarding asks once;
             * this is where it gets corrected without re-running the quiz.
             *
             * NULL when never asked, which the client shows differently from an empty list —
             * "we have not asked" against "you told us nothing". The distinction drives whether
             * the plan narrows to bodyweight on home days.
             */
            'home_equipment'    => self::homeEquipment($userId),
            'home_kit_options'  => array_keys(PlanSchema::HOME_KIT),
        ];
    }

    /** The user's home gym kit, or null if the question has never been answered. */
    public static function homeEquipment(int $userId): ?array
    {
        $row = DB::one(
            'SELECT home_equipment FROM training_preferences WHERE user_id = ?', [$userId]
        );
        if ($row === null || $row['home_equipment'] === null) {
            return null;
        }
        $decoded = json_decode((string) $row['home_equipment'], true);
        return is_array($decoded) ? array_values($decoded) : null;
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

        /*
         * The former section 9.
         *
         * Same partial treatment as everything above, which is the improvement over how the
         * quiz wrote them: projectCoachingStyle updated all six columns on every §9 save, so
         * an unanswered question overwrote a stored value with its default. Tolerable when a
         * whole section is submitted at once, wrong for six independent switches.
         */
        foreach ([
            ['tone', self::TONES],
            ['explanation_depth', self::DEPTHS],
            ['nudge_intensity', self::INTENSITIES],
        ] as [$key, $allowed]) {
            if (array_key_exists($key, $body)) {
                $v = Validate::enum($body[$key], $allowed);
                if ($v === null) {
                    return self::fail('That is not one of the choices.');
                }
                $put($key, $v);
            }
        }

        if (array_key_exists('nudge_after_days', $body)) {
            $v = Validate::intRange(
                $body['nudge_after_days'], self::MIN_NUDGE_DAYS, self::MAX_NUDGE_DAYS
            );
            if ($v === null) {
                return self::fail('Pick between 1 and 30 days.');
            }
            $put('nudge_after_days', $v);
        }

        foreach (['hide_photos', 'hide_measurements'] as $key) {
            if (array_key_exists($key, $body)) {
                $v = Validate::bool($body[$key]);
                if ($v === null) {
                    return self::fail('That has to be true or false.');
                }
                $put($key, $v ? 1 : 0);
            }
        }

        /*
         * The home gym kit, written separately because it lives on training_preferences.
         *
         * $put builds an UPDATE against profiles, so this cannot go through it. Handled before
         * the "nothing to change" guard below, since a request that changes ONLY the kit is a
         * real request — someone who just bought a bench.
         */
        $kitChanged = false;
        if (array_key_exists('home_equipment', $body)) {
            $kit = $body['home_equipment'];
            if (!is_array($kit)) {
                return self::fail('Tell us what you have as a list, even an empty one.');
            }
            $clean = [];
            foreach ($kit as $item) {
                $item = trim((string) $item);
                // Only the six we offer. Anything else is a client bug or someone poking the
                // API, and storing it would quietly widen the vocabulary filter.
                if (!isset(PlanSchema::HOME_KIT[$item])) {
                    return self::fail('That is not one of the equipment choices.');
                }
                $clean[$item] = true;
            }

            DB::run(
                'INSERT INTO training_preferences (user_id, home_equipment)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE home_equipment = VALUES(home_equipment)',
                [$userId, json_encode(array_keys($clean))]
            );
            $kitChanged = true;
        }

        if ($sets === []) {
            if ($kitChanged) {
                return ['ok' => true, 'error' => null, 'changed' => ['home_equipment']];
            }
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

        if ($kitChanged) {
            $changed[] = 'home_equipment';
        }
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
            $kind    = (string) $r['kind'];
            $subject = (string) $r['subject'];
            /*
             * The label rides ALONGSIDE the subject, never replacing it.
             *
             * Safety::promptBlock matches on the raw subject to expand a food category into
             * its members ("shellfish" -> shrimp, prawn, crab), by exact lowercase key. And
             * the client sends the subject back when confirming a tier. Overwriting it here
             * would break both.
             */
            $facet = ConstraintLabel::facet($kind, $subject);

            $out[] = [
                'id'       => (int) $r['id'],
                'kind'     => $kind,
                'tier'     => (string) $r['tier'],
                'subject'  => $subject,
                // Their own. Inherited rows are appended below with this false.
                'inherited' => false,
                'label'    => ConstraintLabel::of($kind, $subject),
                // avoid | manage | eating | floor. What KIND of thing this is, which the
                // client groups by: a condition is not something being avoided.
                'facet'    => $facet,
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
                'meaning'  => ConstraintLabel::meaning($facet, (string) $r['tier']),
            ];
        }

        /*
         * Then anything inherited from a training buddy (SPEC-coaching §10.2b).
         *
         * Shown because it is real: it steers this user's plan, so a preference they cannot
         * find in their profile would look like the coach inventing things. Safety::forUser is
         * the source, so the list and the prompt cannot disagree.
         *
         * NOT switchable, and no id. It is a property of the pair rather than of the user, so
         * there is no row of theirs to turn off — unpairing is what removes it. Offering a
         * switch would imply otherwise.
         */
        $pair = BuddySchedule::activePair($userId);
        if ($pair !== null) {
            $buddyId = (int) $pair['user_lo'] === $userId
                ? (int) $pair['user_hi']
                : (int) $pair['user_lo'];
            $buddy = DB::one('SELECT display_name FROM users WHERE id = ?', [$buddyId]);
            $name  = (string) ($buddy['display_name'] ?? 'Your buddy');

            foreach (Safety::forUser($userId)['soft'] as $c) {
                if (($c['inherited'] ?? false) !== true) {
                    continue;
                }
                $kind    = (string) $c['kind'];
                $subject = (string) $c['subject'];
                $out[] = [
                    // No id: there is nothing here for the user to edit.
                    'id'        => null,
                    'kind'      => $kind,
                    'tier'      => 'soft',
                    'subject'   => $subject,
                    'inherited' => true,
                    'label'     => ConstraintLabel::of($kind, $subject),
                    'facet'     => ConstraintLabel::facet($kind, $subject),
                    // Names the person, which the prompt's generic wording deliberately does
                    // not need to. On screen it is the difference between "why is this here"
                    // and "ah, that is Sam's knee".
                    'reason'    => "{$name} avoids this",
                    'guidance'  => null,
                    'floor'     => null,
                    'source'    => 'buddy',
                    'active'    => true,
                    'switchable' => false,
                    'meaning'   => 'Your coach steers around this while you are training '
                                 . 'together. It goes away if you stop.',
                ];
            }
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
