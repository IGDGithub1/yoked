<?php
declare(strict_types=1);

/**
 * Tests for Schedule — local-time slot firing and week alignment.
 *
 * Pure date arithmetic, no database and no API, so it runs anywhere and costs
 * nothing. Worth its own suite because this is where scheduling bugs hide: the
 * first attempt at "next Monday" in migration 009 was written with DAYOFWEEK()
 * (1=Sunday) instead of WEEKDAY() (0=Monday) and returned the wrong day for
 * every single weekday. It looked right.
 *
 *   php bin/test-schedule.php
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$pass = 0;
$fail = 0;

function t(string $label, callable $fn): void
{
    global $pass, $fail;
    try {
        $r = $fn();
        if ($r === true) {
            printf("  ok    %s\n", $label);
            $pass++;
        } else {
            printf("  FAIL  %s — %s\n", $label, is_string($r) ? $r : 'false');
            $fail++;
        }
    } catch (Throwable $e) {
        printf("  FAIL  %s — %s\n", $label, $e->getMessage());
        $fail++;
    }
}

echo "Schedule tests\n\n";

// ---- zones -----------------------------------------------------------------

echo "1. zones\n";

t('a valid IANA name resolves', function () {
    return Schedule::zone('America/New_York')->getName() === 'America/New_York';
});

t('null falls back to UTC rather than guessing', function () {
    return Schedule::zone(null)->getName() === 'UTC';
});

t('an empty string falls back to UTC', function () {
    return Schedule::zone('')->getName() === 'UTC';
});

t('a junk zone falls back to UTC instead of throwing', function () {
    // tzdata drops names occasionally. A cron sweep with other users to serve
    // must not die on one bad row.
    return Schedule::zone('Mars/Olympus_Mons')->getName() === 'UTC';
});

// ---- week alignment --------------------------------------------------------

echo "\n2. week alignment\n";

t('weekStart on a Wednesday is that Monday', function () {
    return Schedule::weekStart('UTC', '2026-07-22') === '2026-07-20'
        ?: Schedule::weekStart('UTC', '2026-07-22');
});

t('weekStart on a Monday is that same Monday', function () {
    return Schedule::weekStart('UTC', '2026-07-20') === '2026-07-20'
        ?: Schedule::weekStart('UTC', '2026-07-20');
});

t('weekStart on a Sunday is the Monday six days earlier', function () {
    // The trap: a Sunday belongs to the week that is ENDING, not the one
    // starting tomorrow.
    return Schedule::weekStart('UTC', '2026-07-26') === '2026-07-20'
        ?: Schedule::weekStart('UTC', '2026-07-26');
});

t('nextMonday from a Monday is the FOLLOWING Monday', function () {
    // Not the same day: the partial day someone signs up on should not count as
    // day one of a two-week observation window.
    return Schedule::nextMonday('UTC', '2026-07-20') === '2026-07-27'
        ?: Schedule::nextMonday('UTC', '2026-07-20');
});

t('nextMonday from a Thursday is the coming Monday', function () {
    return Schedule::nextMonday('UTC', '2026-07-23') === '2026-07-27'
        ?: Schedule::nextMonday('UTC', '2026-07-23');
});

t('nextMonday from a Sunday is tomorrow', function () {
    return Schedule::nextMonday('UTC', '2026-07-26') === '2026-07-27'
        ?: Schedule::nextMonday('UTC', '2026-07-26');
});

t('nextMonday is a Monday for every weekday', function () {
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("2026-07-20 +{$i} days"));
        $m = Schedule::nextMonday('UTC', $d);
        if (date('N', strtotime($m)) !== '1') {
            return "{$d} produced {$m}, which is not a Monday";
        }
        if ($m <= $d) {
            return "{$d} produced {$m}, which is not in the future";
        }
    }
    return true;
});

// ---- day counting ----------------------------------------------------------

echo "\n3. day counting\n";

t('a two-week window is 14 days', function () {
    return Schedule::daysBetween('2026-07-20', '2026-08-03') === 14
        ?: (string) Schedule::daysBetween('2026-07-20', '2026-08-03');
});

t('the same day is zero', function () {
    return Schedule::daysBetween('2026-07-20', '2026-07-20') === 0;
});

t('backwards is negative', function () {
    return Schedule::daysBetween('2026-08-03', '2026-07-20') === -14
        ?: (string) Schedule::daysBetween('2026-08-03', '2026-07-20');
});

t('a DST transition does not eat a day', function () {
    // US DST forward is 2026-03-08. Counted in a DST-observing zone this
    // interval is 23 hours short and rounds to 13 days.
    $n = Schedule::daysBetween('2026-03-01', '2026-03-15');
    return $n === 14 ? true : "got {$n}, expected 14";
});

// ---- slot firing, the whole point ------------------------------------------

echo "\n4. slot firing in local time\n";

/*
 * slotPassed() reads the real clock, so these assert the RELATIONSHIP between
 * zones rather than a fixed answer: at any single instant, a Saturday-18:00 slot
 * must have passed in more easterly zones first. Sydney is ahead of UTC is ahead
 * of Los Angeles, always.
 */
t('the weekday a zone reports is consistent with its offset', function () {
    $utc = Schedule::now('UTC');
    $syd = Schedule::now('Australia/Sydney');
    $la  = Schedule::now('America/Los_Angeles');

    // Same instant, so the timestamps match; only the wall clock differs.
    if ($utc->getTimestamp() !== $syd->getTimestamp()) {
        return 'zones disagreed about the current instant';
    }
    // Sydney is never behind UTC; LA is never ahead of it.
    if ($syd->getOffset() <= $utc->getOffset()) {
        return 'Sydney was not ahead of UTC';
    }
    if ($la->getOffset() >= $utc->getOffset()) {
        return 'Los Angeles was not behind UTC';
    }
    return true;
});

t('a slot on the current weekday at hour 0 has passed', function () {
    $dow = (int) Schedule::now('UTC')->format('N');
    return Schedule::slotPassed('UTC', $dow, 0) === true;
});

t('a slot later today has NOT passed', function () {
    $now = Schedule::now('UTC');
    $hour = (int) $now->format('G');
    if ($hour >= 23) {
        // Nothing is "later today" in the last hour; assert the boundary instead.
        return Schedule::slotPassed('UTC', (int) $now->format('N'), 23) === true;
    }
    return Schedule::slotPassed('UTC', (int) $now->format('N'), $hour + 1) === false;
});

t('a slot earlier in the week has passed', function () {
    $dow = (int) Schedule::now('UTC')->format('N');
    if ($dow === 1) {
        return true;   // nothing earlier than Monday within the week
    }
    return Schedule::slotPassed('UTC', $dow - 1, 23) === true;
});

t('a slot later in the week has NOT passed', function () {
    $dow = (int) Schedule::now('UTC')->format('N');
    if ($dow === 7) {
        return true;   // nothing later than Sunday within the week
    }
    return Schedule::slotPassed('UTC', $dow + 1, 0) === false;
});

t('the hour comparison is >= so a missed sweep still fires', function () {
    // The catch-up rule: cron runs every 15 minutes but a sweep can be lost to a
    // reboot or a deploy. Equality would silently drop that week's work.
    $now  = Schedule::now('UTC');
    $dow  = (int) $now->format('N');
    $hour = (int) $now->format('G');
    if ($hour === 0) {
        return true;   // no earlier hour today to test against
    }
    return Schedule::slotPassed('UTC', $dow, $hour - 1) === true;
});

echo "\n";
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
