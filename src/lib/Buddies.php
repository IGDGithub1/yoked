<?php
declare(strict_types=1);

/**
 * Buddy pairing (SPEC-coaching §10).
 *
 * "Unpaid accountability is the most effective adherence mechanism the app has." Two people
 * who train together pair up, and each can see whether the other actually showed up.
 *
 * WHAT THIS DOES AND DOES NOT DO, stated plainly because the difference matters.
 *
 * It does the handshake: invite, accept, decline, unpair. It computes the availability
 * intersection (§10.3) so a pair can see which days they are both free. And an active pair is
 * what Visibility reads to let a buddy see training — that gate already existed.
 *
 * It does NOT sync the inside of a session. §10.6 wants the shared skeleton generated once
 * per PAIR and each user's prescriptions written against it, which is a change to how
 * generation works rather than a flag on it. Until that exists, both plans are generated
 * independently and the honest claim is "you both train Tuesday", not "you are doing the same
 * session". The prompt used to carry an instruction about matching core blocks across a pair;
 * it was removed, because generation sees one user at a time and cannot know what the other
 * was told, so the instruction read as synced without being synced.
 *
 * FRIENDSHIP FIRST (§10.1). Pairing requires an accepted friendship, checked here rather than
 * in the route so it holds for every caller. Unfriending or blocking ends the pair, which
 * Friends already does.
 *
 * ONE PAIR AT A TIME. The spec describes a pair, not a group, and the schema agrees: one
 * unique row per (lo, hi) with no notion of a set. Someone already paired must unpair first,
 * and being asked by a third person while paired is refused with a reason rather than
 * silently queued.
 */
final class Buddies
{
    /** ISO weekday names, for the shared-day list the UI shows. */
    private const DAY_NAMES = [
        1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
        5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
    ];

    // ---- reading -----------------------------------------------------------

    /**
     * This user's pairing state.
     *
     * Returns ['status', 'buddy', 'shared_days', 'invitable'] where status is one of
     * none | pending_out | pending_in | active.
     *
     * `invitable` is the friends who could be asked: accepted friends who are not already
     * paired with somebody. Computed server-side so the client cannot offer an invitation the
     * API will refuse.
     */
    public static function forUser(int $userId): array
    {
        $row = self::pairRow($userId);

        if ($row === null) {
            return [
                'status'      => 'none',
                'buddy'       => null,
                'shared_days' => [],
                'invitable'   => self::invitable($userId),
            ];
        }

        $otherId = (int) $row['user_lo'] === $userId
            ? (int) $row['user_hi']
            : (int) $row['user_lo'];

        $other = DB::one(
            'SELECT id, username, display_name FROM users WHERE id = ?', [$otherId]
        );

        $status = (string) $row['status'] === 'active'
            ? 'active'
            : ((int) $row['requested_by'] === $userId ? 'pending_out' : 'pending_in');

        return [
            'status'      => $status,
            'buddy'       => $other === null ? null : [
                'id'           => (int) $other['id'],
                'username'     => (string) $other['username'],
                'display_name' => (string) $other['display_name'],
            ],
            /*
             * Only for an ACTIVE pair. Showing the intersection while an invitation is still
             * unanswered would leak one user's availability grid to someone who has not yet
             * agreed to anything.
             */
            'shared_days' => $status === 'active' ? self::sharedDays($userId, $otherId) : [],
            'invitable'   => [],
        ];
    }

    /**
     * The pending or active pair this user is in, if any.
     *
     * 'ended' rows are excluded and kept: they are the record that two people used to train
     * together, and Friends relies on ending rather than deleting so unpairing is auditable.
     */
    private static function pairRow(int $userId): ?array
    {
        return DB::one(
            'SELECT id, user_lo, user_hi, status, requested_by
             FROM buddy_pairs
             WHERE (user_lo = ? OR user_hi = ?) AND status <> "ended"
             ORDER BY id DESC LIMIT 1',
            [$userId, $userId]
        );
    }

    /** Accepted friends who are free to pair. */
    private static function invitable(int $userId): array
    {
        $rows = DB::all(
            'SELECT u.id, u.username, u.display_name
             FROM friendships f
             JOIN users u
               ON u.id = IF(f.user_lo = ?, f.user_hi, f.user_lo)
             WHERE f.status = "accepted"
               AND (f.user_lo = ? OR f.user_hi = ?)
               AND u.status = "active"
               -- Not already in a pair of their own. Asking someone who is spoken for is a
               -- request that can only be declined, so it is not offered.
               AND NOT EXISTS (
                   SELECT 1 FROM buddy_pairs bp
                   WHERE bp.status <> "ended"
                     AND (bp.user_lo = u.id OR bp.user_hi = u.id)
               )
             ORDER BY u.display_name',
            [$userId, $userId, $userId]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'           => (int) $r['id'],
                'username'     => (string) $r['username'],
                'display_name' => (string) $r['display_name'],
            ];
        }
        return $out;
    }

    /**
     * Days both users can train (§10.3).
     *
     * "Synced days are days BOTH users are free, computed from each user's §7.1 grid."
     *
     * `sometimes` counts as available: the grid's own vocabulary treats it as a maybe rather
     * than a no, and a pair whose only overlap is two "sometimes" days still has somewhere to
     * start. Excluding it would report no shared days for users with chaotic schedules, which
     * is exactly the pair that most needs the accountability.
     *
     * Returns a list of ['weekday', 'name', 'minutes'] where minutes is the SMALLER of the
     * two — a shared session cannot outlast whichever of them has to leave.
     */
    public static function sharedDays(int $a, int $b): array
    {
        $rows = DB::all(
            'SELECT x.weekday, LEAST(x.minutes, y.minutes) AS minutes
             FROM availability x
             JOIN availability y ON y.weekday = x.weekday AND y.user_id = ?
             WHERE x.user_id = ?
               AND x.can_train IN ("yes", "sometimes")
               AND y.can_train IN ("yes", "sometimes")
             ORDER BY x.weekday',
            [$b, $a]
        );

        $out = [];
        foreach ($rows as $r) {
            $wd = (int) $r['weekday'];
            $out[] = [
                'weekday' => $wd,
                'name'    => self::DAY_NAMES[$wd] ?? (string) $wd,
                // Null when either user left it blank. The UI shows the day without a
                // duration rather than inventing one.
                'minutes' => $r['minutes'] === null ? null : (int) $r['minutes'],
            ];
        }
        return $out;
    }

    /** Is this user in an active pair? */
    public static function isPaired(int $userId): bool
    {
        $row = self::pairRow($userId);
        return $row !== null && (string) $row['status'] === 'active';
    }

    // ---- the handshake -----------------------------------------------------

    /**
     * Ask a friend to pair up.
     *
     * Returns ['ok', 'error', 'status'].
     *
     * Idempotent in the same two ways Friends::request is: asking twice is not an error, and
     * asking someone who has already asked YOU accepts instead, so two people tapping at the
     * same moment end up paired rather than deadlocked.
     */
    public static function invite(int $userId, int $targetId): array
    {
        if ($userId === $targetId) {
            return self::fail('You cannot pair with yourself.');
        }

        // §10.1, and the reason this check is here rather than in the route: it has to hold
        // for every caller, including a future cron or an admin tool.
        if (!Friends::areFriends($userId, $targetId)) {
            return self::fail('You can only train with someone you are friends with.');
        }

        $existing = self::pairRow($userId);
        if ($existing !== null) {
            $otherId = (int) $existing['user_lo'] === $userId
                ? (int) $existing['user_hi']
                : (int) $existing['user_lo'];

            if ($otherId !== $targetId) {
                // One pair at a time. Named plainly, because "nothing happened" would leave
                // the user tapping a button that silently does nothing.
                return self::fail((string) $existing['status'] === 'active'
                    ? 'You already have a training buddy. Unpair first.'
                    : 'You already have an invitation outstanding.');
            }

            if ((string) $existing['status'] === 'active') {
                return ['ok' => true, 'error' => null, 'status' => 'active'];
            }
            if ((int) $existing['requested_by'] === $userId) {
                return ['ok' => true, 'error' => null, 'status' => 'pending_out'];
            }
            // They asked first. Read it as acceptance.
            return self::respond($userId, true);
        }

        // The target may be free of a pair with US and still spoken for by someone else.
        if (self::pairRow($targetId) !== null) {
            return self::fail('They already have a training buddy.');
        }

        $lo = min($userId, $targetId);
        $hi = max($userId, $targetId);

        /*
         * INSERT ... ON DUPLICATE KEY, because the unique key is on (user_lo, user_hi) and an
         * ENDED row from a previous pairing already occupies it. Two people who unpaired and
         * later want to train together again must not be blocked by their own history.
         */
        DB::run(
            'INSERT INTO buddy_pairs (user_lo, user_hi, status, requested_by)
             VALUES (?, ?, "pending", ?)
             ON DUPLICATE KEY UPDATE
                 status = "pending", requested_by = VALUES(requested_by), ended_at = NULL',
            [$lo, $hi, $userId]
        );

        $me = DB::one('SELECT display_name FROM users WHERE id = ?', [$userId]);
        Notify::create(
            $targetId,
            'buddy_request',
            ((string) ($me['display_name'] ?? 'Someone')) . ' wants to train with you.',
            'user',
            $userId,
            // No dedupe: an invitation is a one-off, and suppressing a second one after an
            // unpair-and-retry would silently swallow it.
            null
        );

        return ['ok' => true, 'error' => null, 'status' => 'pending_out'];
    }

    /**
     * Accept or decline the invitation made TO this user.
     *
     * Declining marks the row 'ended' rather than deleting it, so the invitation cannot be
     * re-sent by the same person immediately and the record survives. invite() clears
     * ended_at on a fresh ask, so it is not a permanent bar.
     */
    public static function respond(int $userId, bool $accept): array
    {
        $row = self::pairRow($userId);
        if ($row === null || (string) $row['status'] !== 'pending') {
            return self::fail('There is no invitation to answer.');
        }
        if ((int) $row['requested_by'] === $userId) {
            // Otherwise inviting and then accepting pairs you with anyone.
            return self::fail('You cannot accept your own invitation.');
        }

        $otherId = (int) $row['user_lo'] === $userId
            ? (int) $row['user_hi']
            : (int) $row['user_lo'];

        if (!$accept) {
            DB::run(
                'UPDATE buddy_pairs SET status = "ended", ended_at = NOW() WHERE id = ?',
                [(int) $row['id']]
            );
            return ['ok' => true, 'error' => null, 'status' => 'none'];
        }

        /*
         * Re-check the friendship at ACCEPT time, not just at invite time.
         *
         * An invitation can sit unanswered while the friendship is removed underneath it.
         * Accepting then would create a pair between two people who are no longer connected,
         * which §10.1 forbids and which grants training visibility neither agreed to.
         */
        if (!Friends::areFriends($userId, $otherId)) {
            DB::run(
                'UPDATE buddy_pairs SET status = "ended", ended_at = NOW() WHERE id = ?',
                [(int) $row['id']]
            );
            return self::fail('You are no longer friends, so that invitation has lapsed.');
        }

        DB::run(
            'UPDATE buddy_pairs SET status = "active", ended_at = NULL WHERE id = ?',
            [(int) $row['id']]
        );

        $me = DB::one('SELECT display_name FROM users WHERE id = ?', [$userId]);
        Notify::create(
            $otherId,
            'buddy_accepted',
            ((string) ($me['display_name'] ?? 'Someone')) . ' is training with you now.',
            'user',
            $userId,
            null
        );

        return ['ok' => true, 'error' => null, 'status' => 'active'];
    }

    /**
     * Unpair. Either side, at any time, no reason required (§10.1).
     *
     * §10.5 is what makes this safe to allow unconditionally: "a buddy who travels, gets ill,
     * or unpairs must never strand the other. Unsynced days generate normally as solo
     * sessions. Pairing is an enhancement to a complete single-user plan, never a dependency
     * of one." So there is no plan surgery to do here, and no confirmation to extract.
     */
    public static function unpair(int $userId): array
    {
        $row = self::pairRow($userId);
        if ($row === null) {
            return ['ok' => true, 'error' => null, 'status' => 'none'];
        }

        $otherId = (int) $row['user_lo'] === $userId
            ? (int) $row['user_hi']
            : (int) $row['user_lo'];
        $wasActive = (string) $row['status'] === 'active';

        DB::run(
            'UPDATE buddy_pairs SET status = "ended", ended_at = NOW() WHERE id = ?',
            [(int) $row['id']]
        );

        /*
         * Tell them, but only if the pair was live.
         *
         * Someone whose invitation was withdrawn before they answered does not need a
         * notification about a thing that never started. Someone who has been training with
         * you for a month does: their buddy's sessions are about to stop appearing, and
         * silence would read as a bug.
         */
        if ($wasActive) {
            $me = DB::one('SELECT display_name FROM users WHERE id = ?', [$userId]);
            Notify::create(
                $otherId,
                'buddy_ended',
                ((string) ($me['display_name'] ?? 'Your buddy'))
                    . ' is no longer training with you. Your own plan is unchanged.',
                'user',
                $userId,
                null
            );
        }

        return ['ok' => true, 'error' => null, 'status' => 'none'];
    }

    private static function fail(string $why): array
    {
        return ['ok' => false, 'error' => $why, 'status' => null];
    }
}
