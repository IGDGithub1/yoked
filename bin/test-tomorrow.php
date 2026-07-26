<?php
declare(strict_types=1);

/**
 * Tests for the Next Day Review (SPEC-coaching §4.1a).
 *
 * What is really under test is the SILENCE. §4.1a's load-bearing sentence is "Optional and
 * dismissible; it must not become the noise the user was promised they'd be spared", so
 * most of these assert that the card does NOT appear: before the evening hour, once
 * dismissed, when tomorrow has nothing prescribed, and when the user has turned it off.
 *
 * The review-hour cases are driven by moving the user's TIMEZONE rather than by mocking a
 * clock. A zone where it is currently 22:00 and one where it is 06:00 both exist at every
 * instant, so "is the window open" can be tested for real against the same code path cron
 * and the API use, with no seam to get wrong.
 *
 *   php bin/test-tomorrow.php
 *   php bin/test-tomorrow.php --keep
 */

require __DIR__ . '/../src/bootstrap_cli.php';
require YK_SRC . '/lib/Response.php';
require YK_SRC . '/lib/RateLimit.php';
require YK_SRC . '/lib/Goals.php';
require YK_SRC . '/lib/Claude.php';
require YK_SRC . '/lib/PlanSchema.php';
require YK_SRC . '/lib/Safety.php';
require YK_SRC . '/lib/Plans.php';
require YK_SRC . '/lib/Nutrition.php';
require YK_SRC . '/lib/Tomorrow.php';

$keep = in_array('--keep', array_slice($argv, 1), true);

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
        } elseif ($r === null) {
            printf("  skip  %s\n", $label);
        } else {
            printf("  FAIL  %s — %s\n", $label, is_string($r) ? $r : 'false');
            $fail++;
        }
    } catch (Throwable $e) {
        printf("  FAIL  %s — %s\n", $label, $e->getMessage());
        $fail++;
    }
}

DB::run("DELETE FROM users WHERE username LIKE 'tmwtest_%'");

/**
 * Find a timezone where the local hour is currently within a range.
 *
 * This is what lets the evening window be tested without mocking time: at any instant
 * there is a zone where it is late evening and another where it is morning. Returns null
 * if none matches, and the caller skips rather than failing — a suite that reports a
 * failure because of the hour it ran at is worse than one that says "not now".
 */
function zoneWhereHourBetween(int $lo, int $hi): ?string
{
    // A spread of offsets, not every zone: enough to cover the clock.
    foreach ([
        'Pacific/Kiritimati', 'Pacific/Auckland', 'Australia/Sydney', 'Asia/Tokyo',
        'Asia/Shanghai', 'Asia/Kolkata', 'Asia/Dubai', 'Europe/Moscow',
        'Europe/Berlin', 'Europe/London', 'Atlantic/Azores', 'America/Sao_Paulo',
        'America/New_York', 'America/Chicago', 'America/Denver',
        'America/Los_Angeles', 'Pacific/Honolulu', 'Pacific/Midway',
    ] as $tz) {
        $h = (int) Schedule::now($tz)->format('G');
        if ($h >= $lo && $h <= $hi) {
            return $tz;
        }
    }
    return null;
}

/** A user with a plan covering tomorrow in their own zone. */
function seedUser(string $suffix, string $tz, int $reviewHour = 20, bool $withPlan = true): array
{
    $userId = DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
         VALUES (?, ?, ?, "x", "active")',
        ['tmwtest_' . $suffix, 'TMW ' . $suffix, "tmwtest_{$suffix}@example.test"]
    );
    DB::run(
        'INSERT INTO profiles (user_id, timezone, review_hour) VALUES (?, ?, ?)',
        [$userId, $tz, $reviewHour]
    );

    $tomorrow = Schedule::now($tz)->modify('+1 day')->format('Y-m-d');

    if ($withPlan) {
        $planId = DB::insert(
            'INSERT INTO plan_versions (user_id, week_start, version, reason, summary)
             VALUES (?, ?, 1, "initial", "Seeded for review tests.")',
            [$userId, Schedule::weekStart($tz)]
        );
        $dayId = DB::insert(
            'INSERT INTO prescribed_days
                (plan_version_id, day_date, target_calories, target_protein_g,
                 target_fat_g, target_carbs_g)
             VALUES (?, ?, 2400, 180, 80, 220)',
            [$planId, $tomorrow]
        );
        // One quick meal and one prep-heavy one, so the flagging has both cases.
        DB::run(
            'INSERT INTO prescribed_meals
                (prescribed_day_id, slot, kind, name, calories, protein_g, fat_g,
                 carbs_g, prep_minutes)
             VALUES (?, "breakfast", "specified", "Overnight oats", 500, 30, 15, 60, 5)',
            [$dayId]
        );
        DB::run(
            'INSERT INTO prescribed_meals
                (prescribed_day_id, slot, kind, name, calories, protein_g, fat_g,
                 carbs_g, prep_minutes, method)
             VALUES (?, "dinner", "specified", "Slow-braised beef", 900, 60, 40, 50, 45,
                     "Brown, then braise for 40 minutes.")',
            [$dayId]
        );
        $sid = DB::insert(
            'INSERT INTO prescribed_sessions
                (plan_version_id, session_date, session_type, focus, is_committed,
                 target_minutes, location, rationale)
             VALUES (?, ?, "strength", "push", 1, 50, "full_gym", "Heavy day.")',
            [$planId, $tomorrow]
        );
        $ex = DB::one("SELECT id FROM exercises WHERE slug = 'leg-press'");
        if ($ex !== null) {
            DB::run(
                'INSERT INTO prescribed_exercises
                    (session_id, exercise_id, block, sets, target_reps)
                 VALUES (?, ?, "main", 3, "10")',
                [$sid, (int) $ex['id']]
            );
        }
    }

    return [
        'row' => DB::one('SELECT id, onboarding_state FROM users WHERE id = ?', [$userId]),
        'id'  => $userId,
        'tz'  => $tz,
        'tomorrow' => $tomorrow,
    ];
}

echo "Next Day Review tests\n\n";

$evening = zoneWhereHourBetween(20, 23);
$morning = zoneWhereHourBetween(6, 15);

printf("        evening zone: %s   morning zone: %s\n\n",
    $evening ?? '(none right now)', $morning ?? '(none right now)');

// ---- the window ------------------------------------------------------------

echo "1. when the review appears\n";

t('in the evening, with a plan, the review appears', function () use ($evening) {
    if ($evening === null) {
        return null;
    }
    $u = seedUser('evening', $evening);
    $r = Tomorrow::review($u['row'], $u['tz']);
    if ($r === null) {
        return 'no review in the evening window';
    }
    return $r['date'] === $u['tomorrow']
        ?: "reviewed {$r['date']}, expected {$u['tomorrow']}";
});

t('before the review hour it does NOT appear', function () use ($morning) {
    if ($morning === null) {
        return null;
    }
    // §4.1a is "late each evening". At 9am there is nothing to say: the day being
    // reviewed has not finished, and a card about tomorrow at breakfast is the noise the
    // user was promised they would be spared.
    $u = seedUser('morning', $morning);
    return Tomorrow::review($u['row'], $u['tz']) === null
        ?: 'a review appeared before the review hour';
});

t('review_hour 0 turns it off entirely', function () use ($evening) {
    if ($evening === null) {
        return null;
    }
    // A user who does not want to think about tomorrow tonight is precisely who §4.1a
    // promises not to nag, and that should not need a second flag.
    $u = seedUser('off', $evening, 0);
    return Tomorrow::review($u['row'], $u['tz']) === null
        ?: 'a review appeared for a user who turned it off';
});

t('a custom hour is honoured, not a hardcoded one', function () {
    // Someone who wants it at 22:00 should not get it at 20:00. Find a zone where it is
    // exactly in between.
    $mid = zoneWhereHourBetween(20, 21);
    if ($mid === null) {
        return null;
    }
    $u = seedUser('late', $mid, 23);
    return Tomorrow::review($u['row'], $u['tz']) === null
        ?: 'a 23:00 review appeared at ' . Schedule::now($mid)->format('G') . ':00';
});

t('nothing prescribed means no card', function () use ($evening) {
    if ($evening === null) {
        return null;
    }
    // Common during the baseline, where there is deliberately no plan. "Tomorrow:
    // nothing" is noise by any definition.
    $u = seedUser('noplan', $evening, 20, false);
    return Tomorrow::review($u['row'], $u['tz']) === null
        ?: 'a review appeared with nothing prescribed';
});

// ---- what it contains ------------------------------------------------------

echo "\n2. what the review shows\n";

t('tomorrow\'s session comes through with its rationale', function () use ($evening) {
    if ($evening === null) {
        return null;
    }
    $u = seedUser('session', $evening);
    $r = Tomorrow::review($u['row'], $u['tz']);
    if ($r === null || $r['sessions'] === []) {
        return 'no sessions in the review';
    }
    $s = $r['sessions'][0];
    if ($s['rationale'] !== 'Heavy day.') {
        return 'the rationale did not come through';
    }
    // Exercise NAMES only: a full set-and-rep table the night before is detail nobody
    // acts on yet.
    return $s['exercises'] !== [] ?: 'no exercise names';
});

t('meals come through in eating order, not id order', function () use ($evening) {
    if ($evening === null) {
        return null;
    }
    // Dinner was inserted second but breakfast must come first: the review is read top to
    // bottom as a day.
    $u = seedUser('order', $evening);
    $r = Tomorrow::review($u['row'], $u['tz']);
    $slots = array_column($r['meals'] ?? [], 'slot');
    return $slots === ['breakfast', 'dinner']
        ?: 'order was ' . implode(', ', $slots);
});

t('a prep-heavy meal is flagged and a quick one is not', function () use ($evening) {
    if ($evening === null) {
        return null;
    }
    /*
     * The reason the review is in the EVENING. "Tomorrow's dinner needs 40 minutes" is
     * actionable at 8pm and useless at 6pm tomorrow.
     */
    $u = seedUser('prep', $evening);
    $r = Tomorrow::review($u['row'], $u['tz']);
    $flags = $r['prep_flags'] ?? [];
    if (count($flags) !== 1) {
        return count($flags) . ' flags, expected 1 (the 45-minute dinner)';
    }
    if ($flags[0]['slot'] !== 'dinner') {
        return "flagged {$flags[0]['slot']} rather than dinner";
    }
    return $flags[0]['prep_minutes'] === 45 ?: 'wrong prep minutes';
});

t('the flagging threshold is a server decision', function () {
    // Not a number in a component: the client should not be able to disagree about what
    // counts as prep-heavy.
    return Tomorrow::PREP_HEAVY_MINUTES > 0 && Tomorrow::PREP_HEAVY_MINUTES <= 45;
});

// ---- dismissal -------------------------------------------------------------

echo "\n3. dismissible, and it stays dismissed\n";

t('dismissing hides it', function () use ($evening) {
    if ($evening === null) {
        return null;
    }
    $u = seedUser('dismiss', $evening);
    if (Tomorrow::review($u['row'], $u['tz']) === null) {
        return 'no review to dismiss';
    }
    Tomorrow::dismiss($u['id'], $u['tomorrow']);
    return Tomorrow::review($u['row'], $u['tz']) === null
        ?: 'the review survived being dismissed';
});

t('dismissing twice is a no-op rather than an error', function () use ($evening) {
    if ($evening === null) {
        return null;
    }
    $u = seedUser('dismiss2', $evening);
    $first  = Tomorrow::dismiss($u['id'], $u['tomorrow']);
    $second = Tomorrow::dismiss($u['id'], $u['tomorrow']);
    return $first === true && $second === false
        ?: 'dismiss() did not report first-vs-repeat correctly';
});

t('a dismissal is scoped to the day, so the next one still appears', function () use ($evening) {
    if ($evening === null) {
        return null;
    }
    /*
     * The whole reason dismissals are a table keyed on review_date rather than a
     * timestamp on profiles: tomorrow's review is a different row, so it comes back
     * without needing a nightly job to clear anything.
     */
    $u = seedUser('dismiss3', $evening);
    Tomorrow::dismiss($u['id'], $u['tomorrow']);
    $dayAfter = date('Y-m-d', strtotime($u['tomorrow'] . ' +1 day'));
    return Tomorrow::isDismissed($u['id'], $dayAfter) === false
        ?: 'dismissing one day dismissed the next as well';
});

// ---- the audible -----------------------------------------------------------

echo "\n4. calling an audible\n";

t('a circumstance records against tomorrow', function () use ($evening) {
    if ($evening === null) {
        return null;
    }
    $u = seedUser('circ', $evening);
    $r = Tomorrow::noteCircumstance($u['id'], $u['tomorrow'], [
        'kind'   => 'travel',
        'detail' => 'Flying out early, no gym.',
    ]);
    if (!$r['ok']) {
        return 'note failed: ' . $r['error'];
    }
    $row = DB::one('SELECT * FROM circumstances WHERE id = ?', [$r['id']]);
    if ((string) $row['starts_on'] !== $u['tomorrow']) {
        return "starts_on was {$row['starts_on']}";
    }
    // §6.4: circumstances expire. A note from the review is about a specific day unless
    // the user says otherwise, or the app reshuffles around a trip that ended weeks ago.
    return (string) $row['ends_on'] === $u['tomorrow']
        ?: "ends_on was " . var_export($row['ends_on'], true) . ', expected tomorrow';
});

t('an open-ended circumstance has no end date', function () use ($evening) {
    if ($evening === null) {
        return null;
    }
    // "I hate salmon" is permanent; "travelling this week" is not.
    $u = seedUser('circopen', $evening);
    $r = Tomorrow::noteCircumstance($u['id'], $u['tomorrow'], [
        'kind' => 'injury', 'detail' => 'Shoulder is done.', 'open_ended' => true,
    ]);
    $row = DB::one('SELECT ends_on FROM circumstances WHERE id = ?', [$r['id']]);
    return $row['ends_on'] === null ?: 'an open-ended note got an end date';
});

t('a circumstance needs both a kind and a detail', function () use ($evening) {
    if ($evening === null) {
        return null;
    }
    $u = seedUser('circbad', $evening);
    $noKind = Tomorrow::noteCircumstance($u['id'], $u['tomorrow'], ['detail' => 'Something']);
    $noText = Tomorrow::noteCircumstance($u['id'], $u['tomorrow'], ['kind' => 'travel']);
    return $noKind['ok'] === false && $noText['ok'] === false
        ?: 'an incomplete circumstance was accepted';
});

t('noting a circumstance does NOT create a plan version', function () use ($evening) {
    if ($evening === null) {
        return null;
    }
    /*
     * §6.1, and it is structural rather than a matter of prompt wording: "The user's
     * message never edits the plan. It is recorded as a stated circumstance. Claude
     * evaluates it. Only Claude's decision produces a new plan version."
     *
     * This is the assertion that makes "chat that can be talked into anything" a failure
     * mode that does not exist, so it is worth testing directly rather than trusting the
     * absence of a call site.
     */
    $u = seedUser('circplan', $evening);
    $before = (int) DB::one('SELECT COUNT(*) AS n FROM plan_versions WHERE user_id = ?',
        [$u['id']])['n'];
    Tomorrow::noteCircumstance($u['id'], $u['tomorrow'], [
        'kind' => 'schedule', 'detail' => 'Move my session, drop the cardio, less volume.',
    ]);
    $after = (int) DB::one('SELECT COUNT(*) AS n FROM plan_versions WHERE user_id = ?',
        [$u['id']])['n'];
    return $after === $before
        ? true
        : "plan_versions went from {$before} to {$after} on a user message";
});

t('a noted circumstance shows on the review, so it is not reported twice', function () use ($evening) {
    if ($evening === null) {
        return null;
    }
    $u = seedUser('circshow', $evening);
    Tomorrow::noteCircumstance($u['id'], $u['tomorrow'], [
        'kind' => 'travel', 'detail' => 'Hotel gym only.',
    ]);
    $r = Tomorrow::review($u['row'], $u['tz']);
    $details = array_column($r['circumstances'] ?? [], 'detail');
    return in_array('Hotel gym only.', $details, true)
        ?: 'the note did not come back on the review';
});

t('an expired circumstance does not show', function () use ($evening) {
    if ($evening === null) {
        return null;
    }
    // §6.4 again: without expiry the app reshuffles forever around a trip that ended.
    $u = seedUser('circold', $evening);
    DB::run(
        'INSERT INTO circumstances (user_id, kind, detail, starts_on, ends_on)
         VALUES (?, "travel", "Last month\'s trip.", ?, ?)',
        [$u['id'],
         date('Y-m-d', strtotime($u['tomorrow'] . ' -30 days')),
         date('Y-m-d', strtotime($u['tomorrow'] . ' -20 days'))]
    );
    $r = Tomorrow::review($u['row'], $u['tz']);
    $details = array_column($r['circumstances'] ?? [], 'detail');
    return !in_array("Last month's trip.", $details, true)
        ?: 'an expired circumstance was shown';
});

// ---- cleanup ---------------------------------------------------------------

if (!$keep) {
    DB::run("DELETE FROM users WHERE username LIKE 'tmwtest_%'");
    echo "\n  fixtures removed\n";
} else {
    echo "\n  fixtures kept\n";
}

echo "\n";
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
