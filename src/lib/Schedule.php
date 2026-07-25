<?php
declare(strict_types=1);

/**
 * When a scheduled slot fires, in the USER's local time.
 *
 * Everything in this app is stored UTC and converted for display. This is the one
 * place where a timezone changes *behaviour* rather than presentation: "Saturday
 * at 18:00" has to mean Saturday evening where the user lives, or it means
 * Saturday lunchtime in Chicago and early Sunday in Sydney. A weekend slot chosen
 * because it is when someone has time is worthless if it lands mid-morning.
 *
 * So the conversion happens here, once, and cron asks questions in local terms.
 *
 * A NULL timezone falls back to UTC rather than guessing. A wrong guess fires the
 * weekend slot on the wrong day; UTC at least fires predictably, and the
 * fallback disappears the first time the user opens the app (the SPA reports its
 * zone on sign-in).
 */
final class Schedule
{
    /** Slots are stored as weekday 1..7 (Mon..Sun) to match PHP's 'N'. */
    public const MON = 1;
    public const SAT = 6;
    public const SUN = 7;

    /** A DateTimeZone for a stored identifier, falling back to UTC. */
    public static function zone(?string $tz): DateTimeZone
    {
        if ($tz === null || $tz === '') {
            return new DateTimeZone('UTC');
        }
        try {
            return new DateTimeZone($tz);
        } catch (Exception $e) {
            // A zone name that was valid when stored can be removed from the
            // tzdata PHP ships. Falling back beats a fatal in a cron sweep that
            // still has other users to serve.
            error_log("[yoked] unknown timezone '{$tz}', falling back to UTC");
            return new DateTimeZone('UTC');
        }
    }

    /** "Now", as the user experiences it. */
    public static function now(?string $tz): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::zone($tz));
    }

    /** Today's date in the user's zone, as YYYY-MM-DD. */
    public static function today(?string $tz): string
    {
        return self::now($tz)->format('Y-m-d');
    }

    /**
     * Has the user's weekly slot passed this week?
     *
     * `>=` rather than `==` on purpose: cron sweeps every 15 minutes, but a sweep
     * can be missed (host reboot, a job that overran, a deploy). Comparing for
     * equality would silently drop that week's work. So a slot that has passed
     * stays passed until the week rolls over, and the caller's own idempotency
     * check — a cron_runs claim keyed on the period, or "does this week already
     * have a plan" — is what stops it firing twice.
     *
     * @param int $weekday 1..7, Monday..Sunday
     * @param int $hour    0..23, in the user's local reckoning
     */
    public static function slotPassed(?string $tz, int $weekday, int $hour): bool
    {
        $now = self::now($tz);
        $dow = (int) $now->format('N');

        if ($dow > $weekday) {
            return true;
        }
        if ($dow < $weekday) {
            return false;
        }
        return (int) $now->format('G') >= $hour;
    }

    /**
     * The Monday of the week containing a date, in the user's zone.
     *
     * Weeks are Monday-based throughout: plan_versions.week_start, the baseline
     * clock, and the check-in period key all assume it.
     */
    public static function weekStart(?string $tz, ?string $date = null): string
    {
        $d = $date === null
            ? self::now($tz)
            : new DateTimeImmutable($date, self::zone($tz));

        // 'monday this week' is Monday-based in PHP regardless of locale, and on
        // a Monday it returns that same day, which is what is wanted here.
        return $d->modify('monday this week')->format('Y-m-d');
    }

    /**
     * The next Monday strictly after a date, in the user's zone.
     *
     * Used to align the baseline clock. A Monday signup gets the FOLLOWING
     * Monday, not the same day: the partial day they signed up on should not
     * count as day one of a two-week observation window.
     */
    public static function nextMonday(?string $tz, ?string $date = null): string
    {
        $d = $date === null
            ? self::now($tz)
            : new DateTimeImmutable($date, self::zone($tz));

        return $d->modify('next monday')->format('Y-m-d');
    }

    /** Whole days from $from to $to, signed. Both YYYY-MM-DD. */
    public static function daysBetween(string $from, string $to): int
    {
        $a = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone('UTC'));
        $b = new DateTimeImmutable($to . ' 00:00:00', new DateTimeZone('UTC'));
        // UTC on both sides deliberately: these are calendar dates, not instants,
        // and constructing them in a DST-observing zone makes a 24-hour interval
        // occasionally 23 or 25 hours, which rounds a day away.
        return (int) $a->diff($b)->format('%r%a');
    }
}
