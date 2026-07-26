<?php
declare(strict_types=1);

/**
 * Who may see what about whom.
 *
 * WHY THIS EXISTS BEFORE THERE IS ANYTHING TO SEE.
 *
 * `hide_photos` and `hide_measurements` have been written by onboarding since the beginning
 * and read by nothing. The quiz toggle said "keep private" and did nothing at all, which is
 * a promise the app was not keeping — noted honestly on the Profile today, but a dead switch
 * all the same.
 *
 * The reason they were dead is that there was nobody to hide from. SPEC-coaching §10.4 is
 * where they finally bite: a buddy sees "whether sessions were completed, and shared-session
 * performance", and explicitly NOT weight, measurements or photos, because "pairing up to
 * train is not consent to share body metrics".
 *
 * So the decision gets one home, written before the first caller rather than after. The
 * alternative is what usually happens: each new surface reads the flag itself, one of them
 * forgets, and the leak is discovered by the person whose weight showed up on someone else's
 * screen. A default-deny function that every read path must go through cannot be forgotten,
 * because a surface that does not call it has no data to render.
 *
 * DEFAULT DENY, AND SELF ALWAYS ALLOWED. Every method answers false unless it can find a
 * reason to say true. `$viewerId === $subjectId` is the first check in each: a user looking
 * at their own profile is not a visibility question, and making it one produces the absurd
 * case where turning on "keep private" hides your measurements from you.
 */
final class Visibility
{
    /**
     * Can the viewer see the subject at all?
     *
     * Today: only yourself, and an active buddy. There is no friend model and no public
     * profile, so this is the whole surface. When friendship lands it goes here and nowhere
     * else.
     */
    public static function canSeeUser(int $viewerId, int $subjectId): bool
    {
        if ($viewerId === $subjectId) {
            return true;
        }
        return self::areBuddies($viewerId, $subjectId);
    }

    /**
     * Sessions: done or not done, and how the shared work went.
     *
     * The one thing a buddy IS entitled to (§10.4), because it is the entire point of
     * pairing: "your buddy trained Monday, you didn't" is the most effective nudge in the
     * app and it costs nothing. Not gated on a privacy flag, because there is no flag for it
     * and adding one would defeat the feature.
     */
    public static function canSeeTraining(int $viewerId, int $subjectId): bool
    {
        return self::canSeeUser($viewerId, $subjectId);
    }

    /**
     * Weight and body measurements.
     *
     * Gated on `hide_measurements`, which defaults to 1. §10.4: not covered by pairing.
     */
    public static function canSeeMeasurements(int $viewerId, int $subjectId): bool
    {
        if ($viewerId === $subjectId) {
            return true;
        }
        if (!self::areBuddies($viewerId, $subjectId)) {
            return false;
        }
        return !self::flag($subjectId, 'hide_measurements');
    }

    /** Progress photos. Gated on `hide_photos`, which defaults to 1. */
    public static function canSeePhotos(int $viewerId, int $subjectId): bool
    {
        if ($viewerId === $subjectId) {
            return true;
        }
        if (!self::areBuddies($viewerId, $subjectId)) {
            return false;
        }
        return !self::flag($subjectId, 'hide_photos');
    }

    /**
     * An ACTIVE buddy pair in either direction.
     *
     * buddy_pairs stores the pair ordered (user_lo < user_hi) with a CHECK enforcing it, so
     * the lookup normalises rather than trying both columns — which would also work and
     * would quietly accept a row that violated the ordering.
     *
     * 'pending' is not 'active': an invitation nobody has accepted grants nothing. That is
     * the whole reason the status column exists.
     */
    public static function areBuddies(int $a, int $b): bool
    {
        if ($a === $b) {
            return false;
        }
        $lo = min($a, $b);
        $hi = max($a, $b);

        return DB::one(
            'SELECT 1 AS x FROM buddy_pairs
             WHERE user_lo = ? AND user_hi = ? AND status = "active" LIMIT 1',
            [$lo, $hi]
        ) !== null;
    }

    /**
     * Read one privacy flag, defaulting to HIDDEN.
     *
     * The default matters and runs the safe way: a missing profile row, a null, or a user
     * who never answered all resolve to hidden. Sharing a body metric should be a choice
     * somebody made, never one they discover they made.
     */
    private static function flag(int $userId, string $column): bool
    {
        // The column name is chosen from a closed set by the callers above and never comes
        // from a request, but the allowlist is here anyway so that stays true.
        if (!in_array($column, ['hide_photos', 'hide_measurements'], true)) {
            return true;
        }

        $row = DB::one("SELECT {$column} AS v FROM profiles WHERE user_id = ?", [$userId]);
        if ($row === null || $row['v'] === null) {
            return true;
        }
        return (bool) $row['v'];
    }
}
