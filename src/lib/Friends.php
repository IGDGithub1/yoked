<?php
declare(strict_types=1);

/**
 * The social graph (SPEC-coaching §10.1).
 *
 * A prerequisite rather than a feature in its own right: buddy pairing "requires an existing
 * friendship", and pairing is the thing that actually helps a user show up. So this stays
 * deliberately small. There is no feed, no profile to browse, no mutual-friend graph to
 * explore. Two people connect, and that unlocks the ability to train together.
 *
 * SEARCH IS THE WHOLE PRIVACY SURFACE, and it is asymmetric on purpose.
 *
 * Username and display name match on a prefix, because those are self-chosen handles and
 * being findable by one is what they are for. Email matches ONLY in full. The difference is
 * that a partial email match answers "is this address registered here?" for any address
 * somebody cares to try, and lets a determined person harvest the membership list a prefix
 * at a time. An invite-only app's user list is not public information, and an email is not a
 * handle its owner chose to publish.
 *
 * The same reasoning caps results and rate-limits the endpoint: a search that returns
 * everything is a directory.
 */
final class Friends
{
    /** Enough to find who you meant, few enough that paging is not a browse. */
    private const MAX_RESULTS = 8;

    /**
     * A prefix shorter than this returns nothing.
     *
     * Two characters would let someone walk the alphabet in 676 queries and read out the
     * whole user base. Three is still short enough to find "ian" and long enough that
     * enumeration stops being casual.
     */
    private const MIN_QUERY = 3;

    private const RATE_MAX    = 30;
    private const RATE_WINDOW = 300;

    // ---- finding people --------------------------------------------------------

    /**
     * Search for someone to add.
     *
     * Returns a list of ['id', 'display_name', 'username', 'relationship'] — never an email,
     * even when the email is what matched. Echoing it back would turn one confirmed address
     * into a readable one.
     *
     * `relationship` is what the UI renders its button from: none | pending_out | pending_in
     * | friends | self. Computed here so the client cannot offer "add" for someone already
     * added, and so a blocked pair is simply absent from the results.
     */
    public static function search(int $viewerId, string $query): array
    {
        $q = trim($query);
        if (mb_strlen($q) < self::MIN_QUERY) {
            return ['ok' => true, 'results' => [], 'error' => null];
        }

        if (!RateLimit::allow('friendsearch:' . $viewerId, self::RATE_MAX, self::RATE_WINDOW)) {
            return ['ok' => false, 'results' => [],
                    'error' => 'That is a lot of searching. Try again in a few minutes.'];
        }

        /*
         * Email is matched whole, everything else by prefix.
         *
         * LIKE 'x%' rather than '%x%': a leading wildcard would match an email's domain from
         * a username search ("gmail" finding every Gmail user), and it cannot use an index.
         */
        $like = self::escapeLike($q) . '%';

        $rows = DB::all(
            'SELECT u.id, u.username, u.display_name, u.created_at
             FROM users u
             WHERE u.status = "active"
               AND u.id <> ?
               AND (u.username LIKE ? OR u.display_name LIKE ? OR u.email = ?)
             ORDER BY
               -- An exact handle first, then alphabetical. Someone who typed a full username
               -- meant that person, not whoever happens to sort first.
               (u.username = ?) DESC,
               u.display_name
             LIMIT ' . self::MAX_RESULTS,
            // The email is compared as-is: the column collates utf8mb4_unicode_ci, so the
            // match is already case-insensitive, and lowercasing here would only suggest
            // otherwise. Same comparison the login route makes.
            [$viewerId, $like, $like, $q, $q]
        );

        $out = [];
        foreach ($rows as $r) {
            $other = (int) $r['id'];
            $rel   = self::relationship($viewerId, $other);

            // A blocked pair does not appear at all. "No results" is the only honest answer:
            // saying "blocked" would confirm the account exists to someone told to go away.
            if ($rel === 'blocked') {
                continue;
            }

            $out[] = [
                'id'           => $other,
                'username'     => (string) $r['username'],
                'display_name' => (string) $r['display_name'],
                'relationship' => $rel,
            ];
        }

        return ['ok' => true, 'results' => $out, 'error' => null];
    }

    /** LIKE metacharacters, escaped so a query of "%" is not a wildcard. */
    private static function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    // ---- the relationship ------------------------------------------------------

    /**
     * How these two stand, from the viewer's side.
     *
     * self | friends | pending_out | pending_in | blocked | none
     *
     * Direction matters for the two pending states: "you asked them" and "they asked you"
     * need different buttons, and the row stores only who requested it.
     */
    public static function relationship(int $viewerId, int $otherId): string
    {
        if ($viewerId === $otherId) {
            return 'self';
        }

        $row = self::row($viewerId, $otherId);
        if ($row === null) {
            return 'none';
        }

        return match ((string) $row['status']) {
            'accepted' => 'friends',
            'blocked'  => 'blocked',
            'pending'  => (int) $row['requester_id'] === $viewerId
                ? 'pending_out'
                : 'pending_in',
            default    => 'none',
        };
    }

    /**
     * The friendship row for a pair, in either direction.
     *
     * friendships is stored ordered (user_lo < user_hi) with a CHECK enforcing it, so this
     * normalises rather than trying both column orders — which would also work and would
     * quietly tolerate a row that broke the ordering.
     */
    private static function row(int $a, int $b): ?array
    {
        return DB::one(
            'SELECT id, user_lo, user_hi, requester_id, status
             FROM friendships WHERE user_lo = ? AND user_hi = ?',
            [min($a, $b), max($a, $b)]
        );
    }

    // ---- asking ------------------------------------------------------------

    /**
     * Ask someone to connect.
     *
     * Returns ['ok', 'error', 'status'] where status is the resulting relationship.
     *
     * Idempotent where it can be: asking twice is not an error, and asking someone who has
     * already asked YOU accepts instead. That second case is the one worth handling — two
     * people reaching for each other at the same time should end up friends, not with two
     * requests neither can resolve.
     */
    public static function request(int $viewerId, int $targetId): array
    {
        if ($viewerId === $targetId) {
            return self::fail('You cannot add yourself.');
        }

        $target = DB::one(
            'SELECT id FROM users WHERE id = ? AND status = "active"', [$targetId]
        );
        if ($target === null) {
            return self::fail('No such person.');
        }

        $existing = self::row($viewerId, $targetId);

        if ($existing !== null) {
            $status = (string) $existing['status'];

            if ($status === 'accepted') {
                return ['ok' => true, 'error' => null, 'status' => 'friends'];
            }
            if ($status === 'blocked') {
                /*
                 * Deliberately the same message as a nonexistent user.
                 *
                 * Telling the sender they are blocked confirms the account exists and tells
                 * them what happened, which is the opposite of what blocking is for.
                 */
                return self::fail('No such person.');
            }
            // pending
            if ((int) $existing['requester_id'] === $viewerId) {
                return ['ok' => true, 'error' => null, 'status' => 'pending_out'];
            }
            // They asked first. Take it as acceptance rather than a second request.
            return self::respond($viewerId, $targetId, true);
        }

        DB::run(
            'INSERT INTO friendships (user_lo, user_hi, requester_id, status)
             VALUES (?, ?, ?, "pending")',
            [min($viewerId, $targetId), max($viewerId, $targetId), $viewerId]
        );

        // Tell them. A request nobody knows about is a request that never gets answered, and
        // unlike a nudge this one is another person waiting.
        $me = DB::one('SELECT display_name FROM users WHERE id = ?', [$viewerId]);
        Notify::create(
            $targetId,
            'friend_request',
            ((string) ($me['display_name'] ?? 'Someone')) . ' wants to connect.',
            'user',
            $viewerId,
            // No dedupe window: a request is a one-off, and suppressing a second one after an
            // unfriend-and-retry would silently swallow it.
            null
        );

        return ['ok' => true, 'error' => null, 'status' => 'pending_out'];
    }

    /**
     * Accept or decline a request that was made TO the viewer.
     *
     * Declining deletes the row rather than marking it, so the pair returns to 'none' and
     * either side can ask again later. A declined-and-remembered state would be a soft block
     * nobody asked for.
     */
    public static function respond(int $viewerId, int $otherId, bool $accept): array
    {
        $row = self::row($viewerId, $otherId);
        if ($row === null || (string) $row['status'] !== 'pending') {
            return self::fail('There is no request to answer.');
        }

        // Only the person who was ASKED can answer. Without this, the requester could accept
        // their own request.
        if ((int) $row['requester_id'] === $viewerId) {
            return self::fail('You cannot answer your own request.');
        }

        if (!$accept) {
            DB::run('DELETE FROM friendships WHERE id = ?', [(int) $row['id']]);
            return ['ok' => true, 'error' => null, 'status' => 'none'];
        }

        DB::run(
            'UPDATE friendships SET status = "accepted", responded_at = NOW() WHERE id = ?',
            [(int) $row['id']]
        );

        $me = DB::one('SELECT display_name FROM users WHERE id = ?', [$viewerId]);
        Notify::create(
            (int) $row['requester_id'],
            'friend_accepted',
            ((string) ($me['display_name'] ?? 'Someone')) . ' accepted your request.',
            'user',
            $viewerId,
            null
        );

        return ['ok' => true, 'error' => null, 'status' => 'friends'];
    }

    /**
     * Unfriend.
     *
     * Deletes the row. Also ends any buddy pair, because §10.1 makes friendship the
     * prerequisite for pairing and leaving a pair alive after the friendship ended would
     * keep two people sharing training data with someone they just disconnected from.
     */
    public static function remove(int $viewerId, int $otherId): array
    {
        $row = self::row($viewerId, $otherId);
        if ($row === null) {
            return ['ok' => true, 'error' => null, 'status' => 'none'];
        }

        DB::tx(function () use ($row, $viewerId, $otherId): void {
            DB::run('DELETE FROM friendships WHERE id = ?', [(int) $row['id']]);
            self::endBuddyPair($viewerId, $otherId);
        });

        return ['ok' => true, 'error' => null, 'status' => 'none'];
    }

    /**
     * Block.
     *
     * Keeps the row, so the pair cannot be re-requested, and records who did it in
     * requester_id — the column is reused as "the actor" for this status, because a blocked
     * pair has no requester and the alternative is a migration for one column.
     *
     * Ends the buddy pair for the same reason unfriending does.
     */
    public static function block(int $viewerId, int $otherId): array
    {
        if ($viewerId === $otherId) {
            return self::fail('You cannot block yourself.');
        }

        $lo = min($viewerId, $otherId);
        $hi = max($viewerId, $otherId);

        DB::tx(function () use ($lo, $hi, $viewerId, $otherId): void {
            DB::run(
                'INSERT INTO friendships (user_lo, user_hi, requester_id, status, responded_at)
                 VALUES (?, ?, ?, "blocked", NOW())
                 ON DUPLICATE KEY UPDATE
                     status = "blocked", requester_id = VALUES(requester_id),
                     responded_at = NOW()',
                [$lo, $hi, $viewerId]
            );
            self::endBuddyPair($viewerId, $otherId);
        });

        return ['ok' => true, 'error' => null, 'status' => 'blocked'];
    }

    /** Undo a block, back to no relationship at all. Only the blocker may. */
    public static function unblock(int $viewerId, int $otherId): array
    {
        $row = self::row($viewerId, $otherId);
        if ($row === null || (string) $row['status'] !== 'blocked') {
            return self::fail('That person is not blocked.');
        }
        if ((int) $row['requester_id'] !== $viewerId) {
            // The other party did the blocking. They do not get to be un-blocked by the
            // person they blocked.
            return self::fail('That person is not blocked.');
        }

        DB::run('DELETE FROM friendships WHERE id = ?', [(int) $row['id']]);
        return ['ok' => true, 'error' => null, 'status' => 'none'];
    }

    /**
     * End any buddy pair between two users.
     *
     * Called from unfriend and block. §10.5: "a buddy who travels, gets ill, or unpairs must
     * never strand the other" — unsynced days generate normally as solo sessions, so ending
     * a pair is safe at any moment and needs no plan surgery.
     */
    private static function endBuddyPair(int $a, int $b): void
    {
        DB::run(
            'UPDATE buddy_pairs SET status = "ended", ended_at = NOW()
             WHERE user_lo = ? AND user_hi = ? AND status <> "ended"',
            [min($a, $b), max($a, $b)]
        );
    }

    // ---- reading -----------------------------------------------------------

    /**
     * Everyone connected to this user, plus the requests waiting on them.
     *
     * One call rather than three, because the UI shows all of it on one screen and three
     * round trips on a phone is a visible wait for no reason.
     */
    public static function forUser(int $userId): array
    {
        $rows = DB::all(
            'SELECT f.id, f.status, f.requester_id, f.created_at,
                    u.id AS other_id, u.username, u.display_name, u.created_at AS joined
             FROM friendships f
             JOIN users u
               ON u.id = IF(f.user_lo = ?, f.user_hi, f.user_lo)
             WHERE (f.user_lo = ? OR f.user_hi = ?)
               AND u.status = "active"
             ORDER BY u.display_name',
            [$userId, $userId, $userId]
        );

        $friends  = [];
        $incoming = [];
        $outgoing = [];
        $blocked  = [];

        foreach ($rows as $r) {
            $entry = [
                'id'           => (int) $r['other_id'],
                'username'     => (string) $r['username'],
                'display_name' => (string) $r['display_name'],
            ];

            switch ((string) $r['status']) {
                case 'accepted':
                    $friends[] = $entry;
                    break;
                case 'blocked':
                    // Only shown to whoever did the blocking. The blocked party sees nothing,
                    // which is the point.
                    if ((int) $r['requester_id'] === $userId) {
                        $blocked[] = $entry;
                    }
                    break;
                case 'pending':
                    if ((int) $r['requester_id'] === $userId) {
                        $outgoing[] = $entry;
                    } else {
                        /*
                         * A little context for the decision, and only on an INCOMING request.
                         * When they joined and whether anyone you both know vouches for them
                         * is the difference between an informed accept and a blind one.
                         *
                         * Not on outgoing or on search results: a stranger should not learn
                         * anything by being looked up, only by being asked.
                         */
                        $entry['joined']  = substr((string) $r['joined'], 0, 10);
                        $entry['mutuals'] = self::mutualCount($userId, (int) $r['other_id']);
                        $incoming[] = $entry;
                    }
                    break;
            }
        }

        return [
            'friends'  => $friends,
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'blocked'  => $blocked,
        ];
    }

    /**
     * How many accepted friends these two share.
     *
     * A count, never the names: "one mutual friend" is enough to decide, and listing them
     * would tell a stranger who you know before you have agreed to anything.
     */
    private static function mutualCount(int $a, int $b): int
    {
        $row = DB::one(
            'SELECT COUNT(*) AS n FROM (
                 SELECT IF(user_lo = ?, user_hi, user_lo) AS friend_id
                 FROM friendships
                 WHERE status = "accepted" AND (user_lo = ? OR user_hi = ?)
             ) AS mine
             JOIN (
                 SELECT IF(user_lo = ?, user_hi, user_lo) AS friend_id
                 FROM friendships
                 WHERE status = "accepted" AND (user_lo = ? OR user_hi = ?)
             ) AS theirs ON theirs.friend_id = mine.friend_id',
            [$a, $a, $a, $b, $b, $b]
        );
        return (int) ($row['n'] ?? 0);
    }

    /** Are these two accepted friends? The gate buddy pairing will read. */
    public static function areFriends(int $a, int $b): bool
    {
        if ($a === $b) {
            return false;
        }
        $row = self::row($a, $b);
        return $row !== null && (string) $row['status'] === 'accepted';
    }

    /** Is anything waiting on this user? Drives the badge. */
    public static function pendingCount(int $userId): int
    {
        $row = DB::one(
            'SELECT COUNT(*) AS n FROM friendships
             WHERE status = "pending" AND requester_id <> ?
               AND (user_lo = ? OR user_hi = ?)',
            [$userId, $userId, $userId]
        );
        return (int) ($row['n'] ?? 0);
    }

    private static function fail(string $why): array
    {
        return ['ok' => false, 'error' => $why, 'status' => null];
    }
}
