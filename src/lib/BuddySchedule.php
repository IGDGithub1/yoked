<?php
declare(strict_types=1);

/**
 * The two schedules (SPEC-coaching §10.1a, §10.3, §10.3a, §10.3b).
 *
 * A paired user has an individual availability grid and a buddy schedule. The buddy schedule
 * is the priority; the individual grid is the fallback and the filler. This file is the one
 * place that knows how to combine them, and everything else asks it rather than deciding for
 * itself.
 *
 * WHY THAT MATTERS MORE THAN USUAL. Three separate consumers need the same answer to "can
 * this user train on this day, and under what conditions": Safety validating a generated
 * plan, Plans building the prompt, and the UI explaining the week. If any two of them
 * disagree, a plan is either rejected for a day the user agreed to or generated for a day
 * they cannot make. So `effective()` is the single authority and the others are thin.
 *
 * THE PRECEDENCE RULE, stated once:
 *
 *   A buddy-schedule day wins on its own terms. It supplies its own minutes and access,
 *   because it may be a day the user never offered in their own grid (§10.3a) and the grid
 *   therefore says nothing useful about it — often literally can_train = no.
 *
 *   Every other day falls through to the individual grid, unchanged.
 *
 * Nothing here writes to `availability`. §10.1a: the grid is the record of what this person
 * said about their own week, and a day they took on for a buddy is a different fact. Keeping
 * them separate is also what makes falling back possible when a buddy goes quiet (§10.5).
 */
final class BuddySchedule
{
    /** ISO weekday names, for anything user-facing. */
    public const DAY_NAMES = [
        1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
        5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
    ];

    /** A grid value that means "yes, or maybe". §10.3 counts `sometimes` as free. */
    private const TRAINABLE = ['yes', 'sometimes'];

    // ---- the combined answer -----------------------------------------------

    /**
     * What days this user can train, and under what conditions.
     *
     * Returns weekday => ['can_train', 'minutes', 'access', 'shared', 'origin'] for all seven
     * days. `shared` is true when the day comes from the buddy schedule, which is what
     * generation reads to know a session should be built for the pair.
     *
     * `$pairId` may be null, which is the unpaired case and also the fallback case: pass null
     * to get the individual grid alone, which is exactly what §10.5 wants when a buddy has
     * gone quiet.
     */
    public static function effective(int $userId, ?int $pairId = null): array
    {
        $grid = [];
        foreach (DB::all(
            'SELECT weekday, can_train, minutes, access FROM availability WHERE user_id = ?',
            [$userId]
        ) as $r) {
            $grid[(int) $r['weekday']] = [
                'can_train' => (string) $r['can_train'],
                'minutes'   => $r['minutes'] === null ? null : (int) $r['minutes'],
                'access'    => $r['access'],
                'shared'    => false,
                'origin'    => 'individual',
            ];
        }

        // Fill any weekday the grid never recorded. A missing row is not the same as a `no`,
        // but for planning purposes it has to resolve to something, and "not available" is
        // the safe reading of an unanswered day.
        for ($d = 1; $d <= 7; $d++) {
            $grid[$d] ??= [
                'can_train' => 'no', 'minutes' => null, 'access' => null,
                'shared' => false, 'origin' => 'unanswered',
            ];
        }

        if ($pairId === null) {
            ksort($grid);
            return $grid;
        }

        /*
         * The buddy schedule overwrites rather than merges.
         *
         * A shared day carries its own minutes and access, and they replace whatever the grid
         * said — including a `no`. That is the whole point of §10.3a: agreeing to a Wednesday
         * you never offered has to make Wednesday trainable, or the validator rejects the plan
         * the agreement was for.
         */
        foreach (DB::all(
            'SELECT weekday, minutes, access, origin FROM buddy_schedule_days
             WHERE buddy_pair_id = ?',
            [$pairId]
        ) as $r) {
            $wd = (int) $r['weekday'];
            $grid[$wd] = [
                'can_train' => 'yes',
                'minutes'   => $r['minutes'] === null ? null : (int) $r['minutes'],
                'access'    => $r['access'],
                'shared'    => true,
                'origin'    => (string) $r['origin'],
            ];
        }

        ksort($grid);
        return $grid;
    }

    /** Just the trainable weekdays, for callers that only need the day numbers. */
    public static function trainableDays(int $userId, ?int $pairId = null): array
    {
        $out = [];
        foreach (self::effective($userId, $pairId) as $wd => $day) {
            if (in_array($day['can_train'], self::TRAINABLE, true)) {
                $out[] = $wd;
            }
        }
        return $out;
    }

    // ---- overlap and negotiation -------------------------------------------

    /**
     * How well two users' own grids line up, and whether that is enough.
     *
     * Returns ['overlap', 'a_only', 'b_only', 'committed_a', 'committed_b', 'needed',
     * 'thin', 'agreed'].
     *
     * `needed` is the SMALLER of the two committed counts, per §10.3a: the pair needs enough
     * shared days to cover whoever asked for fewer, because beyond that point the other
     * user's surplus is individual anyway and there is nothing to negotiate about.
     *
     * `thin` is the trigger for the negotiation prompt.
     */
    public static function analyse(int $a, int $b, ?int $pairId = null): array
    {
        // Deliberately the users' OWN grids, not their effective schedules: this answers "how
        // do these two weeks line up naturally", which is the question the negotiation is
        // about. Reading the buddy schedule here would make an agreed day look like a natural
        // overlap and the prompt would stop appearing once answered.
        $aDays = self::trainableDays($a);
        $bDays = self::trainableDays($b);

        $overlap = array_values(array_intersect($aDays, $bDays));
        $needed  = min(self::committed($a), self::committed($b));

        $agreed = $pairId === null ? [] : self::agreedDays($pairId);

        return [
            'overlap'     => $overlap,
            'a_only'      => array_values(array_diff($aDays, $bDays)),
            'b_only'      => array_values(array_diff($bDays, $aDays)),
            'committed_a' => self::committed($a),
            'committed_b' => self::committed($b),
            'needed'      => $needed,
            /*
             * §10.3a: "too thin" is an intersection covering fewer days than the smaller of
             * the two committed counts. One user wanting M/W/F/Sa and the other Tu/Th/Sa/Sun
             * overlap only on Saturday, which is not a training partnership.
             *
             * Measured against what is AGREED where a negotiation has already happened, so
             * the prompt stops once the pair has sorted it out.
             */
            'thin'        => count($agreed !== [] ? $agreed : $overlap) < $needed,
            'agreed'      => $agreed,
        ];
    }

    /** The stated committed capacity. Defaults to 3, as the column does. */
    private static function committed(int $userId): int
    {
        $row = DB::one(
            'SELECT committed_days_per_week FROM profiles WHERE user_id = ?', [$userId]
        );
        return $row === null ? 3 : (int) $row['committed_days_per_week'];
    }

    /** Weekdays the pair has agreed to train together. */
    public static function agreedDays(int $pairId): array
    {
        $out = [];
        foreach (DB::all(
            'SELECT weekday FROM buddy_schedule_days WHERE buddy_pair_id = ? ORDER BY weekday',
            [$pairId]
        ) as $r) {
            $out[] = (int) $r['weekday'];
        }
        return $out;
    }

    /**
     * Seed the buddy schedule from the natural intersection.
     *
     * Called when a pair goes active. Every day both users already had free becomes a shared
     * day with `origin = intersection`, so the common case needs no negotiation at all: two
     * people with compatible weeks are simply paired up and done.
     *
     * Idempotent, and it never touches a NEGOTIATED day. Re-seeding after someone edits their
     * grid must not silently discard a day the pair agreed to — that agreement is the more
     * considered of the two facts.
     */
    public static function seedFromIntersection(int $pairId, int $a, int $b): int
    {
        $aGrid = self::effective($a);
        $bGrid = self::effective($b);

        $negotiated = [];
        foreach (DB::all(
            'SELECT weekday FROM buddy_schedule_days
             WHERE buddy_pair_id = ? AND origin = "negotiated"',
            [$pairId]
        ) as $r) {
            $negotiated[] = (int) $r['weekday'];
        }

        $added = 0;
        for ($wd = 1; $wd <= 7; $wd++) {
            if (in_array($wd, $negotiated, true)) {
                continue;
            }
            $aDay = $aGrid[$wd];
            $bDay = $bGrid[$wd];
            $bothFree = in_array($aDay['can_train'], self::TRAINABLE, true)
                     && in_array($bDay['can_train'], self::TRAINABLE, true);

            if (!$bothFree) {
                // No longer a natural overlap: drop an intersection day rather than leave a
                // stale one. A grid edit is the user saying they cannot make that day.
                DB::run(
                    'DELETE FROM buddy_schedule_days
                     WHERE buddy_pair_id = ? AND weekday = ? AND origin = "intersection"',
                    [$pairId, $wd]
                );
                continue;
            }

            /*
             * The access is a GUESS until the pair confirms it, and re-seeding must not
             * overwrite one they already settled.
             *
             * Minutes always refresh, because they come straight from the grids and a grid edit
             * is the user saying their window changed. The facility is different: it is an
             * agreement between two people about where to meet, and a Tuesday grid edit is no
             * reason to throw it away and re-guess. Hence the IF on access_agreed rather than a
             * plain VALUES().
             */
            DB::run(
                'INSERT INTO buddy_schedule_days
                    (buddy_pair_id, weekday, minutes, access, access_agreed, origin)
                 VALUES (?, ?, ?, ?, 0, "intersection")
                 ON DUPLICATE KEY UPDATE
                    minutes = VALUES(minutes),
                    access  = IF(access_agreed = 1, access, VALUES(access))',
                [$pairId, $wd, self::sharedMinutes($aDay, $bDay),
                 self::sharedAccess($aDay, $bDay)]
            );
            $added++;
        }
        return $added;
    }

    /**
     * The shorter of two durations (§10.3).
     *
     * A shared session cannot outlast whichever of them has to leave. Null when neither
     * stated one; the other's figure when only one did, since a stated limit is better
     * information than no limit.
     */
    private static function sharedMinutes(array $a, array $b): ?int
    {
        $mins = array_values(array_filter(
            [$a['minutes'], $b['minutes']],
            static fn($m): bool => $m !== null
        ));
        return $mins === [] ? null : min($mins);
    }

    /**
     * What KIND of facility a shared session happens in.
     *
     * A buddy pair trains in the same place, physically. That is the whole feature — not two
     * people doing similar work in two buildings.
     *
     * THIS USED TO TAKE THE MORE RESTRICTIVE OF THE TWO, and that was wrong twice over.
     * Resolving full_gym against home_gym to home_gym does not name a place both can attend; it
     * names a capability tier they happen to share, in separate houses. And it compromised in
     * one direction only, so pairing could cost you equipment and never gain you any — when the
     * usual real answer is that whoever has the gym membership brings the other one along.
     *
     * So this returns the MORE CAPABLE tier, as an assumption that the less-equipped user
     * travels. It is only ever a guess: the app cannot know whose car works, who has a guest
     * pass, or who would rather not have company at home. So the guess is recorded as
     * unconfirmed (access_agreed = 0) and put in front of the pair to settle.
     *
     * THE APP NEVER STORES A PLACE. Users arrange where to meet between themselves; all this
     * needs to know is what equipment to prescribe against.
     *
     * A null on either side means unstated, and the other's value is used.
     */
    private static function sharedAccess(array $a, array $b): ?string
    {
        $vals = array_values(array_filter(
            [$a['access'], $b['access']],
            static fn($v): bool => $v !== null && isset(self::ACCESS_RANK[$v])
        ));
        if ($vals === []) {
            return null;
        }
        // Most capable last, so the last one is the answer.
        usort(
            $vals,
            static fn(string $x, string $y): int
                => self::ACCESS_RANK[$x] <=> self::ACCESS_RANK[$y]
        );
        return $vals[count($vals) - 1];
    }

    /**
     * What each facility tier can support, least to most.
     *
     * Shared rather than inlined because the ordering is now read in two directions — the
     * seeded guess wants the most capable, and anything asking "is this a step up for them"
     * wants to compare — and two copies of an ordering drift.
     */
    public const ACCESS_RANK = [
        'bodyweight' => 0,
        'outdoors'   => 1,
        'home_gym'   => 2,
        'full_gym'   => 3,
    ];

    // ---- offers -------------------------------------------------------------

    /**
     * Offer to train on a day, usually one outside your own grid (§10.3a).
     *
     * Returns ['ok', 'error', 'status'].
     *
     * The offerer states their own minutes and access for that day, because the app has no
     * other source for them: their grid says they cannot train then. If the offer is accepted
     * those combine with the other user's grid to set the shared day.
     */
    public static function offerDay(
        int $userId,
        int $weekday,
        ?int $minutes,
        ?string $access
    ): array {
        $pair = self::activePair($userId);
        if ($pair === null) {
            return self::fail('You do not have a training buddy.');
        }
        if ($weekday < 1 || $weekday > 7) {
            return self::fail('Pick a day of the week.');
        }

        // Already shared: nothing to offer. Not an error — the user tapped a day that is
        // already agreed, and saying "done" is more useful than a complaint.
        if (in_array($weekday, self::agreedDays((int) $pair['id']), true)) {
            return ['ok' => true, 'error' => null, 'status' => 'already_shared'];
        }

        $otherId = self::otherId($pair, $userId);

        /*
         * Did the OTHER user already offer this day? Then accepting is what this is.
         *
         * Two people reaching for the same compromise at the same time should resolve, not
         * queue two offers neither can action. Same shape as the mutual friend request and
         * the mutual buddy invite.
         */
        $theirs = DB::one(
            'SELECT id FROM buddy_day_offers
             WHERE buddy_pair_id = ? AND weekday = ? AND offered_by = ? AND status = "pending"',
            [(int) $pair['id'], $weekday, $otherId]
        );
        if ($theirs !== null) {
            return self::respondToOffer($userId, (int) $theirs['id'], true);
        }

        DB::run(
            'INSERT INTO buddy_day_offers
                (buddy_pair_id, weekday, offered_by, minutes, access, status)
             VALUES (?, ?, ?, ?, ?, "pending")
             ON DUPLICATE KEY UPDATE
                 minutes = VALUES(minutes), access = VALUES(access),
                 status = "pending", responded_at = NULL',
            [(int) $pair['id'], $weekday, $userId, $minutes, $access]
        );

        $me = DB::one('SELECT display_name FROM users WHERE id = ?', [$userId]);
        Notify::create(
            $otherId,
            'buddy_day_offer',
            sprintf(
                '%s offered to train with you on %s.',
                (string) ($me['display_name'] ?? 'Your buddy'),
                self::DAY_NAMES[$weekday] ?? "day {$weekday}"
            ),
            'user',
            $userId,
            null
        );

        return ['ok' => true, 'error' => null, 'status' => 'offered'];
    }

    /**
     * Accept or decline an offer made to you.
     *
     * Accepting writes the shared day with `origin = negotiated`, combining the offerer's
     * stated minutes and access with the accepter's own grid for that day. If the accepter
     * cannot train then either, their side of the offer is what they supplied when they made
     * a counter-offer — and if neither has a figure, the day is shared with no stated limit,
     * which is honest rather than invented.
     */
    public static function respondToOffer(int $userId, int $offerId, bool $accept): array
    {
        $pair = self::activePair($userId);
        if ($pair === null) {
            return self::fail('You do not have a training buddy.');
        }

        $offer = DB::one(
            'SELECT id, buddy_pair_id, weekday, offered_by, minutes, access, status
             FROM buddy_day_offers WHERE id = ? AND buddy_pair_id = ?',
            [$offerId, (int) $pair['id']]
        );
        if ($offer === null || (string) $offer['status'] !== 'pending') {
            return self::fail('There is no offer to answer.');
        }
        if ((int) $offer['offered_by'] === $userId) {
            // Otherwise offering and then accepting sets the schedule unilaterally.
            return self::fail('You cannot accept your own offer.');
        }

        if (!$accept) {
            DB::run(
                'UPDATE buddy_day_offers SET status = "declined", responded_at = NOW()
                 WHERE id = ?',
                [$offerId]
            );
            return ['ok' => true, 'error' => null, 'status' => 'declined'];
        }

        $wd      = (int) $offer['weekday'];
        $mine    = self::effective($userId)[$wd];
        $theirs  = [
            'minutes' => $offer['minutes'] === null ? null : (int) $offer['minutes'],
            'access'  => $offer['access'],
        ];

        DB::tx(function () use ($offerId, $pair, $wd, $mine, $theirs, $userId, $offer): void {
            DB::run(
                'UPDATE buddy_day_offers SET status = "accepted", responded_at = NOW()
                 WHERE id = ?',
                [$offerId]
            );
            /*
             * An accepted offer settles the facility, so this day is agreed rather than guessed.
             *
             * One person named a day AND what they can train with; the other said yes to both.
             * That is the confirmation the seeded intersection days are missing, and it is the
             * reason this path does not need to nag anybody afterwards.
             */
            DB::run(
                'INSERT INTO buddy_schedule_days
                    (buddy_pair_id, weekday, minutes, access, access_agreed, origin)
                 VALUES (?, ?, ?, ?, 1, "negotiated")
                 ON DUPLICATE KEY UPDATE
                     minutes = VALUES(minutes), access = VALUES(access),
                     access_agreed = 1, origin = "negotiated"',
                [(int) $pair['id'], $wd,
                 self::sharedMinutes($mine, $theirs), self::sharedAccess($mine, $theirs)]
            );

            /*
             * The schedule changed, so both users' surplus answers are stale (§10.3b: "re-asked
             * if the shared schedule changes"). Cleared rather than recomputed, because the
             * whole point is that the user decides.
             */
            self::clearSurplusMode((int) $pair['user_lo']);
            self::clearSurplusMode((int) $pair['user_hi']);
        });

        $me = DB::one('SELECT display_name FROM users WHERE id = ?', [$userId]);
        Notify::create(
            (int) $offer['offered_by'],
            'buddy_day_agreed',
            sprintf(
                '%s agreed to train with you on %s.',
                (string) ($me['display_name'] ?? 'Your buddy'),
                self::DAY_NAMES[$wd] ?? "day {$wd}"
            ),
            'user',
            $userId,
            null
        );

        return ['ok' => true, 'error' => null, 'status' => 'agreed'];
    }

    /** Withdraw your own pending offer. */
    public static function withdrawOffer(int $userId, int $offerId): array
    {
        $pair = self::activePair($userId);
        if ($pair === null) {
            return self::fail('You do not have a training buddy.');
        }
        $n = DB::run(
            'UPDATE buddy_day_offers SET status = "withdrawn", responded_at = NOW()
             WHERE id = ? AND buddy_pair_id = ? AND offered_by = ? AND status = "pending"',
            [$offerId, (int) $pair['id'], $userId]
        )->rowCount();

        return $n > 0
            ? ['ok' => true, 'error' => null, 'status' => 'withdrawn']
            : self::fail('There is no offer of yours to withdraw.');
    }

    /**
     * Drop an agreed day.
     *
     * Either side, and no distinction between an intersection day and a negotiated one: both
     * are days the pair currently trains together, and either user can say they cannot make
     * it any more. Re-seeding will restore an intersection day if both grids still allow it,
     * which is correct — that is what their own grids say.
     */
    public static function dropDay(int $userId, int $weekday): array
    {
        $pair = self::activePair($userId);
        if ($pair === null) {
            return self::fail('You do not have a training buddy.');
        }

        DB::tx(function () use ($pair, $weekday): void {
            DB::run(
                'DELETE FROM buddy_schedule_days WHERE buddy_pair_id = ? AND weekday = ?',
                [(int) $pair['id'], $weekday]
            );
            self::clearSurplusMode((int) $pair['user_lo']);
            self::clearSurplusMode((int) $pair['user_hi']);
        });

        return ['ok' => true, 'error' => null, 'status' => 'dropped'];
    }

    /**
     * Settle what kind of facility a shared day happens in (§10.3, §10.1).
     *
     * WHY THIS EXISTS. A pair trains in the same place, and the app cannot work out which
     * place. When their grids disagree — one has a full gym, one has dumbbells at home — the
     * seeded day assumes the better-equipped venue and the less-equipped user travelling,
     * because that is the usual arrangement and because compromising downward by default meant
     * pairing could only ever cost somebody equipment. It is still a guess, so it is marked
     * unconfirmed until somebody says otherwise here.
     *
     * NO ADDRESS IS STORED. Which gym, whose garage, and who drives are for the two of them to
     * arrange. All the app needs is the kind of facility, so it can prescribe equipment that
     * will actually be there.
     *
     * EITHER USER MAY SET IT, and the other is told.
     *
     * A confirm-both handshake was the obvious design and is the wrong one: it leaves days
     * unconfirmed whenever one person is slow to answer, and an unconfirmed day is exactly what
     * this is for. Somebody changing it to a tier their buddy cannot manage will hear about it
     * at the gym, which is the same feedback loop the rest of the pairing runs on. What matters
     * is that the app stops guessing silently.
     *
     * Only affects the SHARED day. The user's own grid is never rewritten (§10.1a), so training
     * at a full gym on Wednesday does not give anybody a full gym on Tuesday.
     */
    public static function setDayAccess(int $userId, int $weekday, string $access): array
    {
        $pair = self::activePair($userId);
        if ($pair === null) {
            return self::fail('You do not have a training buddy.');
        }
        if (!isset(self::ACCESS_RANK[$access])) {
            return self::fail('Pick a kind of place you can both train.');
        }

        $n = DB::run(
            'UPDATE buddy_schedule_days SET access = ?, access_agreed = 1
             WHERE buddy_pair_id = ? AND weekday = ?',
            [$access, (int) $pair['id'], $weekday]
        )->rowCount();

        if ($n === 0) {
            // Either no such shared day, or it already said this. Distinguished so the second
            // case is not reported as an error to somebody confirming the existing guess.
            $row = DB::one(
                'SELECT access FROM buddy_schedule_days
                 WHERE buddy_pair_id = ? AND weekday = ?',
                [(int) $pair['id'], $weekday]
            );
            if ($row === null) {
                return self::fail('You do not train together on that day.');
            }
            DB::run(
                'UPDATE buddy_schedule_days SET access_agreed = 1
                 WHERE buddy_pair_id = ? AND weekday = ?',
                [(int) $pair['id'], $weekday]
            );
        }

        $me    = DB::one('SELECT display_name FROM users WHERE id = ?', [$userId]);
        $other = self::otherId($pair, $userId);
        Notify::create(
            $other,
            'buddy_day_agreed',
            sprintf(
                '%s set your %s session to %s.',
                (string) ($me['display_name'] ?? 'Your buddy'),
                self::DAY_NAMES[$weekday] ?? "day {$weekday}",
                self::ACCESS_LABELS[$access] ?? $access
            )
        );

        return ['ok' => true, 'error' => null, 'status' => $access];
    }

    /** Plain-English facility names, for notifications and the API. */
    public const ACCESS_LABELS = [
        'bodyweight' => 'bodyweight only',
        'outdoors'   => 'outdoors',
        'home_gym'   => 'a home gym',
        'full_gym'   => 'a full gym',
    ];

    /**
     * Shared days whose facility is still a guess.
     *
     * Surfaced so the app can say so rather than quietly acting on an assumption about where
     * two people are going to meet. Only interesting when the two grids DISAGREED — if both
     * users said full_gym there is nothing to settle and nothing to nag about.
     */
    public static function unconfirmedDays(int $userId): array
    {
        $pair = self::activePair($userId);
        if ($pair === null) {
            return [];
        }
        $pairId = (int) $pair['id'];
        $other  = self::otherId($pair, $userId);

        /*
         * Compared against the INDIVIDUAL grids, not the effective schedule.
         *
         * The shared row already holds the resolved guess, and effective() overlays it, so
         * comparing the two users' effective values would find them identical every time and
         * report nothing. What matters is whether their OWN stated facilities disagree.
         */
        $out = [];
        foreach (DB::all(
            'SELECT weekday, access FROM buddy_schedule_days
             WHERE buddy_pair_id = ? AND access_agreed = 0
             ORDER BY weekday',
            [$pairId]
        ) as $r) {
            $wd = (int) $r['weekday'];

            $aOwn = DB::one(
                'SELECT access FROM availability WHERE user_id = ? AND weekday = ?',
                [$userId, $wd]
            );
            $bOwn = DB::one(
                'SELECT access FROM availability WHERE user_id = ? AND weekday = ?',
                [$other, $wd]
            );
            $x = $aOwn['access'] ?? null;
            $y = $bOwn['access'] ?? null;

            // Nothing to settle when they already match, or when neither stated anything.
            if ($x === $y || ($x === null && $y === null)) {
                continue;
            }

            $out[] = [
                'weekday'  => $wd,
                'day'      => self::DAY_NAMES[$wd] ?? "day {$wd}",
                'assumed'  => $r['access'],
                'label'    => self::ACCESS_LABELS[$r['access']] ?? $r['access'],
                'yours'    => $x,
                'theirs'   => $y,
            ];
        }
        return $out;
    }

    /** Pending offers on this pair, both directions. */
    public static function offers(int $userId): array
    {
        $pair = self::activePair($userId);
        if ($pair === null) {
            return ['incoming' => [], 'outgoing' => []];
        }

        $in = [];
        $out = [];
        foreach (DB::all(
            'SELECT id, weekday, offered_by, minutes, access FROM buddy_day_offers
             WHERE buddy_pair_id = ? AND status = "pending" ORDER BY weekday',
            [(int) $pair['id']]
        ) as $r) {
            $entry = [
                'id'      => (int) $r['id'],
                'weekday' => (int) $r['weekday'],
                'name'    => self::DAY_NAMES[(int) $r['weekday']] ?? '',
                'minutes' => $r['minutes'] === null ? null : (int) $r['minutes'],
                'access'  => $r['access'],
            ];
            if ((int) $r['offered_by'] === $userId) {
                $out[] = $entry;
            } else {
                $in[] = $entry;
            }
        }
        return ['incoming' => $in, 'outgoing' => $out];
    }

    // ---- the surplus choice (§10.3b) ---------------------------------------

    private const SURPLUS_MODES = ['keep_commitment', 'extras_optional', 'match_buddy'];

    /**
     * How many committed sessions this user should actually get.
     *
     * The whole point of §10.3b. Unpaired, or with no surplus, it is simply their stated
     * count. Paired with fewer shared days than that count, the answer depends on what THEY
     * chose:
     *
     *   keep_commitment  their stated count, shared days plus individual ones
     *   extras_optional  the shared days only; individual days generate as optional
     *   match_buddy      the shared days only, and nothing extra is generated
     *
     * A null mode means they have not been asked yet, and the honest default until they
     * answer is their original commitment — never silently less than they asked for.
     */
    public static function committedTarget(int $userId, ?int $pairId = null): array
    {
        $stated = self::committed($userId);
        if ($pairId === null) {
            return ['committed' => $stated, 'mode' => null, 'surplus' => 0,
                    'fill_individual' => true, 'needs_choice' => false];
        }

        $shared  = count(self::agreedDays($pairId));
        $surplus = max(0, $stated - $shared);

        if ($surplus === 0) {
            // Nothing to decide: the shared days already cover the commitment.
            return ['committed' => $stated, 'mode' => null, 'surplus' => 0,
                    'fill_individual' => true, 'needs_choice' => false];
        }

        $mode = self::surplusMode($userId);

        return match ($mode) {
            'extras_optional' => ['committed' => $shared, 'mode' => $mode,
                                  'surplus' => $surplus, 'fill_individual' => true,
                                  'needs_choice' => false],
            'match_buddy'     => ['committed' => $shared, 'mode' => $mode,
                                  'surplus' => $surplus, 'fill_individual' => false,
                                  'needs_choice' => false],
            'keep_commitment' => ['committed' => $stated, 'mode' => $mode,
                                  'surplus' => $surplus, 'fill_individual' => true,
                                  'needs_choice' => false],
            // Not asked yet. Keep the commitment they made and flag that the question is
            // outstanding, rather than picking on their behalf.
            default           => ['committed' => $stated, 'mode' => null,
                                  'surplus' => $surplus, 'fill_individual' => true,
                                  'needs_choice' => true],
        };
    }

    public static function surplusMode(int $userId): ?string
    {
        $row = DB::one(
            'SELECT buddy_surplus_mode FROM profiles WHERE user_id = ?', [$userId]
        );
        return $row === null ? null : $row['buddy_surplus_mode'];
    }

    public static function setSurplusMode(int $userId, string $mode): array
    {
        if (!in_array($mode, self::SURPLUS_MODES, true)) {
            return self::fail('Pick one of the three options.');
        }
        DB::run(
            'UPDATE profiles SET buddy_surplus_mode = ? WHERE user_id = ?',
            [$mode, $userId]
        );
        return ['ok' => true, 'error' => null, 'status' => $mode];
    }

    private static function clearSurplusMode(int $userId): void
    {
        DB::run(
            'UPDATE profiles SET buddy_surplus_mode = NULL WHERE user_id = ?', [$userId]
        );
    }

    // ---- helpers ------------------------------------------------------------

    /** The user's active pair row, or null. */
    public static function activePair(int $userId): ?array
    {
        return DB::one(
            'SELECT id, user_lo, user_hi FROM buddy_pairs
             WHERE status = "active" AND (user_lo = ? OR user_hi = ?) LIMIT 1',
            [$userId, $userId]
        );
    }

    private static function otherId(array $pair, int $userId): int
    {
        return (int) $pair['user_lo'] === $userId
            ? (int) $pair['user_hi']
            : (int) $pair['user_lo'];
    }

    private static function fail(string $why): array
    {
        return ['ok' => false, 'error' => $why, 'status' => null];
    }
}
