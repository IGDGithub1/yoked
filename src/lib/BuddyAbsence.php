<?php
declare(strict_types=1);

/**
 * When a buddy is away (SPEC-coaching §10.5).
 *
 * "A buddy who travels, gets ill, or unpairs must never leave the other waiting."
 *
 * THREE CASES, ONE QUESTION. The spec describes planned travel, mid-week illness and silent
 * disappearance as genuinely different things, and they are — for what the PARTNER is told, and
 * for whether a week already built gets left alone. But at generation time they collapse into
 * one question: is my buddy going to be there for the week I am about to plan? So
 * `availableFor()` answers that, and everything else here is about telling people.
 *
 * Two of the cases are declared and live in `buddy_absences`. The third needs nothing stored:
 * Drift::lastLoggedDate already knows when somebody last logged anything, and persisting a
 * derived fact would be a second source of truth that goes stale.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO. It never rewrites a week that is already built. §10.5:
 * "reshuffling a week someone is halfway through is worse than letting them finish it." A
 * mid-week illness notifies and nothing else; the effect lands on the NEXT generation. That is
 * why declaring on Tuesday and declaring on Saturday behave differently, and why the kind is
 * stored rather than inferred from the dates.
 */
final class BuddyAbsence
{
    private const KINDS = ['travel', 'illness', 'other'];

    /**
     * How many days of silence make a buddy count as gone.
     *
     * Reuses the user's own nudge window rather than a constant: somebody who set
     * `nudge_after_days` to 7 has told us that a week of quiet is normal for them, and treating
     * them as vanished after 3 would strand their partner for no reason. Floored so a very
     * short setting cannot make a single missed day look like quitting.
     */
    private const MIN_SILENCE_DAYS = 4;

    // ---- the question generation asks --------------------------------------

    /**
     * Will this user's buddy be training with them during the given week?
     *
     * Returns ['available', 'reason', 'buddy_name', 'returns_on'] where reason is one of
     * unpaired | available | declared_travel | declared_illness | declared_other | silent.
     *
     * `available` false means the partner's week should be built from their own individual
     * grid — which is exactly what BuddySchedule::effective(userId, null) returns, so the
     * fallback needs no special generation path.
     *
     * @param string $weekStart a Monday, YYYY-MM-DD
     */
    public static function availableFor(int $userId, string $weekStart): array
    {
        $pair = BuddySchedule::activePair($userId);
        if ($pair === null) {
            return self::answer(true, 'unpaired');
        }

        $buddyId = (int) $pair['user_lo'] === $userId
            ? (int) $pair['user_hi']
            : (int) $pair['user_lo'];

        $buddy = DB::one('SELECT display_name FROM users WHERE id = ?', [$buddyId]);
        $name  = (string) ($buddy['display_name'] ?? 'Your buddy');

        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

        /*
         * A DECLARED absence overlapping the week.
         *
         * Overlap rather than containment: someone away Wednesday to the following Tuesday is
         * away for parts of two weeks, and both partners' weeks should be solo. An open-ended
         * absence (returns_on NULL) overlaps every week from its start.
         */
        $declared = DB::one(
            'SELECT kind, starts_on, returns_on FROM buddy_absences
             WHERE user_id = ? AND cancelled_at IS NULL
               AND starts_on <= ?
               AND (returns_on IS NULL OR returns_on >= ?)
             ORDER BY starts_on DESC LIMIT 1',
            [$buddyId, $weekEnd, $weekStart]
        );

        if ($declared !== null) {
            return self::answer(
                false,
                'declared_' . (string) $declared['kind'],
                $name,
                $declared['returns_on']
            );
        }

        /*
         * UNDECLARED silence (§10.5's safety net).
         *
         * Not the primary path, and deliberately conservative: it uses the buddy's own nudge
         * window, so somebody who told us a week of quiet is normal is not written off after
         * three days. A user with no logs at all is treated as silent only once their account
         * is older than the window — otherwise a brand-new buddy reads as a quitter on day one.
         */
        if (self::hasGoneQuiet($buddyId)) {
            return self::answer(false, 'silent', $name);
        }

        return self::answer(true, 'available', $name);
    }

    /** Has this user logged nothing for longer than their own nudge window? */
    private static function hasGoneQuiet(int $userId): bool
    {
        $days = (int) (DB::one(
            'SELECT nudge_after_days FROM profiles WHERE user_id = ?', [$userId]
        )['nudge_after_days'] ?? 3);
        $days = max($days, self::MIN_SILENCE_DAYS);

        $last = Drift::lastLoggedDate($userId);
        if ($last === null) {
            /*
             * Never logged anything. Only counts as silence once the account has existed longer
             * than the window — otherwise somebody who paired up on the day they joined is read
             * as having already quit, and their partner trains alone from the start.
             */
            $created = DB::one('SELECT created_at FROM users WHERE id = ?', [$userId]);
            if ($created === null) {
                return false;
            }
            return strtotime((string) $created['created_at']) < strtotime("-{$days} days");
        }

        return strtotime($last) < strtotime("-{$days} days");
    }

    private static function answer(
        bool $available,
        string $reason,
        ?string $name = null,
        ?string $returnsOn = null
    ): array {
        return [
            'available'  => $available,
            'reason'     => $reason,
            'buddy_name' => $name,
            'returns_on' => $returnsOn,
        ];
    }

    // ---- declaring ----------------------------------------------------------

    /**
     * Say you will be away.
     *
     * Named record() rather than declare(): `declare` is a PHP language construct and a method
     * of that name will not parse.
     *
     * Returns ['ok', 'error', 'id', 'notified'].
     *
     * The partner is told immediately in every case, but WHAT they are told differs, and that
     * is the whole point of §10.5's three cases:
     *
     *   - Declared before the coming week is generated, they hear that next week is theirs
     *     alone. Nothing is built yet, so nothing is disrupted.
     *   - Declared mid-week, they hear that their buddy has dropped out of the week already in
     *     progress. The plan STAYS as built, because reshuffling a week someone is halfway
     *     through is worse than letting them finish it.
     */
    public static function record(
        int $userId,
        string $kind,
        string $startsOn,
        ?string $returnsOn
    ): array {
        $kind = Validate::enum($kind, self::KINDS);
        if ($kind === null) {
            return self::fail('Say whether it is travel, illness or something else.');
        }

        $start = Validate::date($startsOn);
        if ($start === null) {
            return self::fail('When does it start?');
        }
        $return = $returnsOn === null || $returnsOn === ''
            ? null
            : Validate::date($returnsOn);
        if ($returnsOn !== null && $returnsOn !== '' && $return === null) {
            return self::fail('That return date is not a date.');
        }
        if ($return !== null && $return < $start) {
            return self::fail('You cannot be back before you leave.');
        }

        $pair = BuddySchedule::activePair($userId);

        // Replace rather than stack: a second declaration is a correction, not a second
        // absence, and two overlapping rows would make "when are they back" ambiguous.
        DB::run(
            'UPDATE buddy_absences SET cancelled_at = NOW()
             WHERE user_id = ? AND cancelled_at IS NULL',
            [$userId]
        );

        $id = (int) DB::insert(
            'INSERT INTO buddy_absences (user_id, buddy_pair_id, kind, starts_on, returns_on)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $pair === null ? null : (int) $pair['id'], $kind, $start, $return]
        );

        $notified = false;
        if ($pair !== null) {
            $notified = self::tellPartner($userId, $pair, $kind, $start, $return);
        }

        return ['ok' => true, 'error' => null, 'id' => $id, 'notified' => $notified];
    }

    /**
     * Tell the other half of the pair.
     *
     * The message names the return date when there is one, because "back on the 4th" is the
     * difference between adjusting and wondering. Open-ended says so plainly rather than
     * inventing a date.
     */
    private static function tellPartner(
        int $userId,
        array $pair,
        string $kind,
        string $startsOn,
        ?string $returnsOn
    ): bool {
        $partnerId = (int) $pair['user_lo'] === $userId
            ? (int) $pair['user_hi']
            : (int) $pair['user_lo'];

        $me   = DB::one('SELECT display_name FROM users WHERE id = ?', [$userId]);
        $name = (string) ($me['display_name'] ?? 'Your buddy');

        $tz    = Baseline::timezoneOf($partnerId);
        $today = Schedule::today($tz);

        /*
         * Is the absence starting inside a week the partner has already been given?
         *
         * That is what separates "next week is yours alone" from "your buddy has just dropped
         * out of this week". Measured against the partner's own week rather than the declarer's
         * date, because the notification is for them.
         */
        $midWeek = $startsOn <= date('Y-m-d', strtotime(Schedule::weekStart($tz) . ' +6 days'))
                && $startsOn >= Schedule::weekStart($tz)
                && $startsOn <= $today;

        $back = $returnsOn === null
            ? 'They have not said when they will be back.'
            : 'They expect to be back on ' . date('j F', strtotime($returnsOn)) . '.';

        $reason = $kind === 'illness' ? 'is ill' : ($kind === 'travel' ? 'is away' : 'is out');

        $body = $midWeek
            // §10.5: the week stays built. Say so, or the user wonders why their plan still
            // has a shared Thursday on it.
            ? "{$name} {$reason} and will not be training with you for now. {$back} "
              . 'Your week is unchanged, so carry on with it.'
            : "{$name} {$reason} next week, so your next plan will be yours alone. {$back}";

        Notify::create(
            $partnerId,
            'buddy_away',
            $body,
            'user',
            $userId,
            // No dedupe: a corrected date or a second absence is news, and suppressing it would
            // leave the partner acting on a stale return date.
            null
        );
        return true;
    }

    /** Back early, or it turned out to be nothing. */
    public static function cancel(int $userId): array
    {
        $n = DB::run(
            'UPDATE buddy_absences SET cancelled_at = NOW()
             WHERE user_id = ? AND cancelled_at IS NULL',
            [$userId]
        )->rowCount();

        if ($n === 0) {
            return self::fail('You have not said you are away.');
        }

        $pair = BuddySchedule::activePair($userId);
        if ($pair !== null) {
            $partnerId = (int) $pair['user_lo'] === $userId
                ? (int) $pair['user_hi']
                : (int) $pair['user_lo'];
            $me = DB::one('SELECT display_name FROM users WHERE id = ?', [$userId]);
            Notify::create(
                $partnerId,
                'buddy_back',
                ((string) ($me['display_name'] ?? 'Your buddy'))
                    . ' is back and training with you again.',
                'user',
                $userId,
                null
            );
        }

        return ['ok' => true, 'error' => null, 'id' => null, 'notified' => $pair !== null];
    }

    /** This user's own live absence, if any. Drives the buddy card. */
    public static function mine(int $userId): ?array
    {
        $row = DB::one(
            'SELECT id, kind, starts_on, returns_on FROM buddy_absences
             WHERE user_id = ? AND cancelled_at IS NULL
               AND (returns_on IS NULL OR returns_on >= CURDATE())
             ORDER BY starts_on DESC LIMIT 1',
            [$userId]
        );
        if ($row === null) {
            return null;
        }
        return [
            'id'         => (int) $row['id'],
            'kind'       => (string) $row['kind'],
            'starts_on'  => (string) $row['starts_on'],
            'returns_on' => $row['returns_on'],
        ];
    }

    private static function fail(string $why): array
    {
        return ['ok' => false, 'error' => $why, 'id' => null, 'notified' => false];
    }
}
