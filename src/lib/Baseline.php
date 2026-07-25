<?php
declare(strict_types=1);

/**
 * The baseline observation window, and where a user is inside it.
 *
 * SPEC-coaching §8 and §9: before there is a plan there are two weeks of watching
 * what the user actually does. Week 1 is pure observation with NO prescription at
 * all; a provisional plan arrives after it so there is something to follow while
 * week 2 sharpens the picture; the real plan comes at the end.
 *
 * That lifecycle needs dates, and until migration 009 there were none —
 * `onboarding_state = 'baseline'` was a bare flag. Which meant nothing could say
 * how far through a user was, nothing ever moved anyone to 'active', and cron
 * treated baseline and active users identically. A baseline user therefore got a
 * full prescribed week on their first Sunday, which is the opposite of what §9
 * asks for.
 *
 * The window is MONDAY-ALIGNED and does not begin on the day the user signs up.
 * Someone who onboards on a Thursday logs Thursday through Sunday as practice
 * that does not count, and their two weeks start the following Monday. Partial
 * weeks make the week-1/week-2 distinction meaningless, and week 1 is the one
 * with no plan.
 */
final class Baseline
{
    /** Two clean weeks, Monday to Monday. */
    public const DAYS = 14;

    /**
     * Where a user is in the window.
     *
     * Returns null when the user is not in baseline at all, so callers can treat
     * "no baseline" and "baseline finished" the same way.
     *
     * @return array{
     *     starts_on:string, ends_on:string, day:int, total:int,
     *     days_left:int, started:bool, week:int, finished:bool
     * }|null
     */
    public static function progress(array $user, ?string $tz = null): ?array
    {
        if (($user['onboarding_state'] ?? '') !== 'baseline') {
            return null;
        }
        $starts = $user['baseline_starts_on'] ?? null;
        $ends   = $user['baseline_ends_on'] ?? null;
        if ($starts === null || $ends === null) {
            return null;
        }

        $today = Schedule::today($tz);

        // Day 1 is the first day of the window, not day 0: a user on the opening
        // Monday is "day 1 of 14", which is how a person counts.
        $elapsed = Schedule::daysBetween((string) $starts, $today);
        $day     = $elapsed + 1;

        return [
            'starts_on' => (string) $starts,
            'ends_on'   => (string) $ends,
            // Clamped for display only. Before the window opens `day` is <= 0,
            // and the UI should say "starts Monday" rather than "day -2 of 14".
            'day'       => max(0, min(self::DAYS, $day)),
            'total'     => self::DAYS,
            'days_left' => max(0, Schedule::daysBetween($today, (string) $ends)),
            'started'   => $elapsed >= 0,
            // 1 during the first seven days, 2 after. Week 1 gets no plan at all;
            // week 2 runs against the provisional one.
            'week'      => $elapsed < 7 ? 1 : 2,
            'finished'  => $today >= (string) $ends,
        ];
    }

    /**
     * Is this user still inside week 1, where nothing is prescribed?
     *
     * The check cron needs before generating anything. An 'active' user is never
     * in week 1, and a baseline user with no dates (impossible after 009, but
     * cheap to be safe about) is treated as observing rather than plannable.
     */
    public static function inObservationWeek(array $user, ?string $tz = null): bool
    {
        if (($user['onboarding_state'] ?? '') !== 'baseline') {
            return false;
        }
        $p = self::progress($user, $tz);
        if ($p === null) {
            return true;
        }
        // Not started yet counts as observation: the practice days before the
        // window opens are not week 2.
        return !$p['started'] || $p['week'] === 1;
    }

    /** Has the window run its course, so the user should become 'active'? */
    public static function isComplete(array $user, ?string $tz = null): bool
    {
        $p = self::progress($user, $tz);
        return $p !== null && $p['finished'];
    }

    /**
     * Move a finished baseline user to 'active'.
     *
     * Guarded on the current state in the WHERE clause rather than checked first,
     * so two concurrent cron sweeps cannot both "win" this transition. Returns
     * true only for the run that actually changed the row.
     */
    public static function activate(int $userId): bool
    {
        /*
         * The window must actually be over, and that is enforced HERE rather than
         * left to the caller.
         *
         * Cron does check isComplete() before calling this, but a guard that lives
         * only at the call site is one new call site away from graduating someone
         * on their second day. Both conditions are in the WHERE clause so the
         * check and the write cannot be separated by a concurrent sweep.
         *
         * CURDATE() is UTC (bootstrap sets the connection to UTC), while the stored
         * dates are the user's local calendar. That is deliberately loose: it can
         * graduate someone up to a day early or late depending on their offset, and
         * a day either way on a two-week observation window is not worth carrying a
         * timezone into SQL for. The caller passing local `today` is what makes it
         * exact, which is what jobBaselineGraduation does.
         */
        return DB::run(
            'UPDATE users SET onboarding_state = "active"
             WHERE id = ? AND onboarding_state = "baseline"
               AND baseline_ends_on IS NOT NULL
               AND baseline_ends_on <= CURDATE()',
            [$userId]
        )->rowCount() > 0;
    }

    /** The user's stored timezone, or null if it has not been detected yet. */
    public static function timezoneOf(int $userId): ?string
    {
        $row = DB::one('SELECT timezone FROM profiles WHERE user_id = ?', [$userId]);
        $tz  = $row['timezone'] ?? null;
        return $tz === null || $tz === '' ? null : (string) $tz;
    }
}
