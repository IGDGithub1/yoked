<?php
declare(strict_types=1);

/**
 * Buddy pairing tests (SPEC-coaching §10).
 *
 * Two things this file is really about.
 *
 * FRIENDSHIP IS THE GATE (§10.1). Pairing grants training visibility, so a pair between two
 * people who never connected is a data leak dressed as a feature. Asserted at invite time AND
 * at accept time, because an invitation can sit unanswered while the friendship is removed
 * underneath it.
 *
 * UNPAIRING NEVER STRANDS ANYONE (§10.5). Either side, any time, no reason. "Pairing is an
 * enhancement to a complete single-user plan, never a dependency of one", which is what makes
 * it safe to allow unconditionally.
 *
 *   php bin/test-buddies.php
 *   php bin/test-buddies.php --keep     leave the fixtures behind
 */

require __DIR__ . '/../src/bootstrap_cli.php';
require YK_SRC . '/lib/Response.php';
require YK_SRC . '/lib/RateLimit.php';
require YK_SRC . '/lib/Notify.php';
require YK_SRC . '/lib/Friends.php';
require YK_SRC . '/lib/BuddySchedule.php';
require YK_SRC . '/lib/Buddies.php';
require YK_SRC . '/lib/Visibility.php';
require YK_SRC . '/lib/Drift.php';         // BuddyAbsence reads lastLoggedDate
require YK_SRC . '/lib/BuddyAbsence.php';  // Plans::gatherContext reads it
require YK_SRC . '/lib/BuddySkeleton.php';  // Plans::gatherContext and Plans::persist read it

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

DB::run("DELETE FROM users WHERE username LIKE 'bud_%'");

function seedUser(string $handle): int
{
    return (int) DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
         VALUES (?, ?, ?, "x", "active")',
        ['bud_' . $handle, 'Buddy ' . $handle, "bud_{$handle}@example.test"]
    );
}

/** Make two users accepted friends, the way the app would. */
function befriend(int $a, int $b): void
{
    Friends::request($a, $b);
    Friends::respond($b, $a, true);
}

/** An availability grid. $days maps weekday => [can_train, minutes]. */
function seedAvailability(int $userId, array $days): void
{
    for ($d = 1; $d <= 7; $d++) {
        [$can, $mins] = $days[$d] ?? ['no', null];
        DB::run(
            'INSERT INTO availability (user_id, weekday, can_train, minutes, access)
             VALUES (?, ?, ?, ?, "full_gym")',
            [$userId, $d, $can, $mins]
        );
    }
}

echo "Buddy pairing tests\n\n";

// ---------------------------------------------------------------------------
echo "1. friendship is the gate\n";

t('a stranger cannot be invited', function () {
    /*
     * The load-bearing assertion. §10.1: "Pairing requires an existing friendship." A pair
     * grants training visibility through Visibility::areBuddies, so pairing with someone you
     * never connected to hands them your sessions.
     */
    $a = seedUser('gate_a');
    $b = seedUser('gate_b');
    $r = Buddies::invite($a, $b);
    if ($r['ok'] !== false) {
        return 'paired with a stranger';
    }
    return Buddies::forUser($a)['status'] === 'none' ?: 'a pair row was created anyway';
});

t('a pending friend request is not a friendship', function () {
    // 'pending' is not 'accepted'. Easy to get wrong in a WHERE clause, and the consequence
    // is pairing with someone who never agreed to be connected at all.
    $a = seedUser('pend_a');
    $b = seedUser('pend_b');
    Friends::request($a, $b);
    return Buddies::invite($a, $b)['ok'] === false
        ?: 'paired on an unanswered friend request';
});

t('the check is in the library, not just the route', function () {
    // So it holds for every caller, including a future cron or admin tool.
    $src = (string) file_get_contents(YK_SRC . '/lib/Buddies.php');
    return str_contains($src, 'Friends::areFriends($userId, $targetId)')
        ?: 'invite() does not check the friendship itself';
});

t('a friend CAN be invited', function () {
    $a = seedUser('ok_a');
    $b = seedUser('ok_b');
    befriend($a, $b);
    $r = Buddies::invite($a, $b);
    if (!$r['ok'] || $r['status'] !== 'pending_out') {
        return json_encode($r);
    }
    return Buddies::forUser($b)['status'] === 'pending_in'
        ?: 'the recipient does not see a pending invitation';
});

t('the friendship is re-checked when the invitation is ACCEPTED', function () {
    /*
     * An invitation can sit unanswered while the friendship is removed underneath it.
     * Accepting then would create a pair between two people no longer connected, granting
     * visibility neither agreed to. Checking only at invite time misses this entirely.
     */
    $a = seedUser('lapse_a');
    $b = seedUser('lapse_b');
    befriend($a, $b);
    Buddies::invite($a, $b);

    Friends::remove($a, $b);

    $r = Buddies::respond($b, true);
    if ($r['ok'] !== false) {
        return 'accepted an invitation after the friendship ended';
    }
    if (Buddies::isPaired($b)) {
        return 'the pair went active anyway';
    }
    // And the stale invitation is closed out rather than left to be accepted later.
    return Buddies::forUser($b)['status'] === 'none'
        ?: 'the lapsed invitation is still pending';
});

// ---------------------------------------------------------------------------
echo "\n2. the handshake\n";

t('accepting activates the pair, and both sides see it', function () {
    $a = seedUser('acc_a');
    $b = seedUser('acc_b');
    befriend($a, $b);
    Buddies::invite($a, $b);
    $r = Buddies::respond($b, true);
    if (!$r['ok'] || $r['status'] !== 'active') {
        return json_encode($r);
    }
    return (Buddies::isPaired($a) && Buddies::isPaired($b))
        ?: 'the pairing is not symmetric';
});

t('an active pair grants training visibility', function () {
    // The point of the whole feature, and the thing Visibility was written for. §10.4:
    // "your buddy trained Monday, you didn't."
    $a = seedUser('vis_a');
    $b = seedUser('vis_b');
    befriend($a, $b);
    Buddies::invite($a, $b);
    Buddies::respond($b, true);
    return Visibility::canSeeTraining($a, $b) ?: 'a paired buddy cannot see training';
});

t('an active pair does NOT grant body-metric visibility', function () {
    // §10.4: "Pairing up to train is not consent to share body metrics."
    $a = seedUser('vis2_a');
    $b = seedUser('vis2_b');
    DB::run('INSERT INTO profiles (user_id, timezone) VALUES (?, "UTC")', [$b]);
    befriend($a, $b);
    Buddies::invite($a, $b);
    Buddies::respond($b, true);
    if (Visibility::canSeeMeasurements($a, $b)) {
        return 'pairing leaked measurements';
    }
    return Visibility::canSeePhotos($a, $b) === false ?: 'pairing leaked photos';
});

t('the inviter cannot accept their own invitation', function () {
    // Without this, inviting and then accepting pairs you with anyone you are friends with.
    $a = seedUser('self_a');
    $b = seedUser('self_b');
    befriend($a, $b);
    Buddies::invite($a, $b);
    $r = Buddies::respond($a, true);
    if ($r['ok'] !== false) {
        return 'the inviter accepted their own invitation';
    }
    return Buddies::isPaired($a) === false ?: 'the pair went active anyway';
});

t('a mutual invitation pairs them rather than deadlocking', function () {
    // Both tap at the same moment. The second invite must be read as acceptance, or each is
    // left waiting on the other.
    $a = seedUser('mut_a');
    $b = seedUser('mut_b');
    befriend($a, $b);
    Buddies::invite($a, $b);
    $r = Buddies::invite($b, $a);
    if ($r['status'] !== 'active') {
        return 'the second invite returned ' . var_export($r['status'], true);
    }
    return Buddies::isPaired($a) ?: 'they are not paired';
});

t('inviting twice is not an error', function () {
    $a = seedUser('twice_a');
    $b = seedUser('twice_b');
    befriend($a, $b);
    Buddies::invite($a, $b);
    $r = Buddies::invite($a, $b);
    return ($r['ok'] && $r['status'] === 'pending_out') ?: json_encode($r);
});

t('declining closes it out without pairing', function () {
    $a = seedUser('dec_a');
    $b = seedUser('dec_b');
    befriend($a, $b);
    Buddies::invite($a, $b);
    $r = Buddies::respond($b, false);
    if (!$r['ok'] || $r['status'] !== 'none') {
        return json_encode($r);
    }
    return Buddies::isPaired($a) === false ?: 'declining paired them anyway';
});

t('a declined pair can be re-formed later', function () {
    /*
     * The unique key is on (user_lo, user_hi), so an ENDED row already occupies it. Two people
     * who unpaired and later want to train together again must not be blocked by their own
     * history — which a plain INSERT would do.
     */
    $a = seedUser('again_a');
    $b = seedUser('again_b');
    befriend($a, $b);
    Buddies::invite($a, $b);
    Buddies::respond($b, false);

    $r = Buddies::invite($a, $b);
    if (!$r['ok']) {
        return 'could not re-invite: ' . (string) $r['error'];
    }
    Buddies::respond($b, true);
    return Buddies::isPaired($a) ?: 'the re-formed pair is not active';
});

t('only one row exists for the pair, however many times they try', function () {
    $a = seedUser('onerow_a');
    $b = seedUser('onerow_b');
    befriend($a, $b);
    Buddies::invite($a, $b);
    Buddies::respond($b, false);
    Buddies::invite($a, $b);
    Buddies::respond($b, true);
    Buddies::unpair($a);
    Buddies::invite($b, $a);

    $n = (int) DB::one(
        'SELECT COUNT(*) AS n FROM buddy_pairs WHERE user_lo = ? AND user_hi = ?',
        [min($a, $b), max($a, $b)]
    )['n'];
    return $n === 1 ?: "{$n} rows for one pair";
});

// ---------------------------------------------------------------------------
echo "\n3. one pair at a time\n";

t('someone already paired cannot be invited by a third person', function () {
    $a = seedUser('tri_a');
    $b = seedUser('tri_b');
    $c = seedUser('tri_c');
    befriend($a, $b);
    befriend($c, $b);
    Buddies::invite($a, $b);
    Buddies::respond($b, true);

    $r = Buddies::invite($c, $b);
    if ($r['ok'] !== false) {
        return 'a third person paired with someone already paired';
    }
    // Named plainly rather than failing silently: a button that does nothing is worse than a
    // refusal that explains itself.
    return str_contains(strtolower((string) $r['error']), 'buddy')
        ?: 'the refusal does not say why: ' . (string) $r['error'];
});

t('a paired user cannot invite somebody else', function () {
    $a = seedUser('sw_a');
    $b = seedUser('sw_b');
    $c = seedUser('sw_c');
    befriend($a, $b);
    befriend($a, $c);
    Buddies::invite($a, $b);
    Buddies::respond($b, true);

    $r = Buddies::invite($a, $c);
    if ($r['ok'] !== false) {
        return 'a paired user started a second pairing';
    }
    return str_contains(strtolower((string) $r['error']), 'unpair')
        ?: 'the refusal does not say what to do instead: ' . (string) $r['error'];
});

t('an already-paired friend is not offered as invitable', function () {
    // The client renders its list from this, so offering someone spoken for would produce a
    // button whose only possible outcome is a refusal.
    $a = seedUser('inv_a');
    $b = seedUser('inv_b');
    $c = seedUser('inv_c');
    befriend($a, $b);
    befriend($a, $c);
    befriend($b, $c);
    Buddies::invite($b, $c);
    Buddies::respond($c, true);

    $names = array_column(Buddies::forUser($a)['invitable'], 'username');
    return $names === [] ?: 'offered: ' . implode(',', $names);
});

t('a free friend IS offered', function () {
    $a = seedUser('free_a');
    $b = seedUser('free_b');
    befriend($a, $b);
    $names = array_column(Buddies::forUser($a)['invitable'], 'username');
    return $names === ['bud_free_b'] ?: 'offered: ' . implode(',', $names);
});

t('a paired user is offered nobody', function () {
    // forUser returns an empty invitable list once you are in a pair, rather than listing
    // people you would immediately be refused for.
    $a = seedUser('paired_a');
    $b = seedUser('paired_b');
    $c = seedUser('paired_c');
    befriend($a, $b);
    befriend($a, $c);
    Buddies::invite($a, $b);
    Buddies::respond($b, true);
    return Buddies::forUser($a)['invitable'] === []
        ?: 'a paired user was offered somebody';
});

// ---------------------------------------------------------------------------
echo "\n4. unpairing never strands anyone (§10.5)\n";

t('either side can unpair', function () {
    foreach ([true, false] as $i => $byInviter) {
        $a = seedUser('un_a' . $i);
        $b = seedUser('un_b' . $i);
        befriend($a, $b);
        Buddies::invite($a, $b);
        Buddies::respond($b, true);

        // Once by the person who asked, once by the person who accepted.
        $r = Buddies::unpair($byInviter ? $a : $b);
        if (!$r['ok'] || $r['status'] !== 'none') {
            return ($byInviter ? 'inviter' : 'accepter') . ' could not unpair';
        }
        if (Buddies::isPaired($a) || Buddies::isPaired($b)) {
            return 'still paired after unpairing';
        }
    }
    return true;
});

t('unpairing revokes training visibility', function () {
    $a = seedUser('rev_a');
    $b = seedUser('rev_b');
    befriend($a, $b);
    Buddies::invite($a, $b);
    Buddies::respond($b, true);
    Buddies::unpair($b);
    return Visibility::canSeeTraining($a, $b) === false
        ?: 'an ended pair can still see training';
});

t('unpairing tells the other person, since their buddy vanishes', function () {
    $a = seedUser('tell_a');
    $b = seedUser('tell_b');
    befriend($a, $b);
    Buddies::invite($a, $b);
    Buddies::respond($b, true);
    Buddies::unpair($a);

    $n = DB::one(
        'SELECT body FROM notifications WHERE user_id = ? AND type = "buddy_ended"
         ORDER BY id DESC LIMIT 1',
        [$b]
    );
    if ($n === null) {
        return 'no notification when a live pair ended';
    }
    // §10.5 in one line: the point is that nothing about their own plan changes.
    return str_contains(strtolower((string) $n['body']), 'unchanged')
        ?: 'the notice does not reassure: ' . (string) $n['body'];
});

t('withdrawing an unanswered invitation notifies nobody', function () {
    // A thing that never started needs no announcement.
    $a = seedUser('quiet_a');
    $b = seedUser('quiet_b');
    befriend($a, $b);
    Buddies::invite($a, $b);
    Buddies::unpair($a);

    $n = (int) DB::one(
        'SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND type = "buddy_ended"',
        [$b]
    )['n'];
    return $n === 0 ?: 'a withdrawn invitation sent a buddy_ended notice';
});

t('unfriending ends the pair too', function () {
    // Friends::remove does this. Asserted from here as well, because §10.1 makes the
    // friendship the prerequisite and the two libraries have to agree.
    $a = seedUser('unf_a');
    $b = seedUser('unf_b');
    befriend($a, $b);
    Buddies::invite($a, $b);
    Buddies::respond($b, true);
    Friends::remove($a, $b);
    return Buddies::isPaired($a) === false ?: 'unfriending left the pair active';
});

// ---------------------------------------------------------------------------
echo "\n5. the availability intersection (§10.3)\n";

t('shared days are the days BOTH are free', function () {
    /*
     * §10.3: "Synced days are days both users are free, computed from each user's §7.1 grid."
     * A wants Mon/Wed/Fri, B wants Wed/Fri/Sat. The answer is Wed and Fri.
     */
    $a = seedUser('av_a');
    $b = seedUser('av_b');
    seedAvailability($a, [1 => ['yes', 60], 3 => ['yes', 60], 5 => ['yes', 60]]);
    seedAvailability($b, [3 => ['yes', 45], 5 => ['yes', 90], 6 => ['yes', 60]]);

    $days = BuddySchedule::analyse($a, $b)['overlap'];
    return $days === [3, 5] ?: 'shared days are ' . implode(',', $days);
});

t('the intersection is the same read from either side', function () {
    $a = seedUser('sym_a');
    $b = seedUser('sym_b');
    seedAvailability($a, [2 => ['yes', 60], 4 => ['yes', 60]]);
    seedAvailability($b, [4 => ['yes', 30], 6 => ['yes', 60]]);
    return BuddySchedule::analyse($a, $b)['overlap']
        == BuddySchedule::analyse($b, $a)['overlap']
        ?: 'the intersection differs by direction';
});

t('the shared duration is the shorter of the two', function () {
    // A shared session cannot outlast whichever of them has to leave.
    $a = seedUser('min_a');
    $b = seedUser('min_b');
    seedAvailability($a, [3 => ['yes', 90]]);
    seedAvailability($b, [3 => ['yes', 45]]);
    /*
     * The shorter figure is applied when the SCHEDULE is written, not when the overlap is
     * computed, so this pairs them for real rather than reading the raw grids.
     */
    befriend($a, $b);
    Buddies::invite($a, $b);
    Buddies::respond($b, true);
    $days = Buddies::forUser($a)['shared_days'];
    $d = $days[0] ?? null;
    return ($d['minutes'] ?? null) === 45
        ?: 'shared minutes are ' . var_export($d['minutes'] ?? null, true);
});

t('"sometimes" counts as available', function () {
    /*
     * The grid's own vocabulary treats it as a maybe, not a no. Excluding it would report no
     * shared days for users with chaotic schedules, which is exactly the pair that most needs
     * the accountability.
     */
    $a = seedUser('some_a');
    $b = seedUser('some_b');
    seedAvailability($a, [2 => ['sometimes', 60]]);
    seedAvailability($b, [2 => ['yes', 60]]);
    return count(BuddySchedule::analyse($a, $b)['overlap']) === 1
        ?: '"sometimes" was treated as unavailable';
});

t('a day either one cannot train is not shared', function () {
    $a = seedUser('no_a');
    $b = seedUser('no_b');
    seedAvailability($a, [1 => ['yes', 60], 2 => ['no', null]]);
    seedAvailability($b, [1 => ['no', null], 2 => ['yes', 60]]);
    return BuddySchedule::analyse($a, $b)['overlap'] === []
        ?: 'a day one of them cannot train was reported as shared';
});

t('no overlap is an empty list, not an error', function () {
    // §10.3: where availability does not overlap, the surplus days generate solo. So zero
    // shared days is a normal state rather than a failure.
    $a = seedUser('none_a');
    $b = seedUser('none_b');
    seedAvailability($a, [1 => ['yes', 60]]);
    seedAvailability($b, [2 => ['yes', 60]]);
    return BuddySchedule::analyse($a, $b)['overlap'] === [] ?: 'expected no shared days';
});

t('the intersection is withheld until the pair is ACTIVE', function () {
    /*
     * Showing it while an invitation is unanswered would leak one user's availability grid to
     * someone who has not agreed to anything yet.
     */
    $a = seedUser('hold_a');
    $b = seedUser('hold_b');
    seedAvailability($a, [3 => ['yes', 60]]);
    seedAvailability($b, [3 => ['yes', 60]]);
    befriend($a, $b);
    Buddies::invite($a, $b);

    if (Buddies::forUser($b)['shared_days'] !== []) {
        return 'the grid leaked on a pending invitation';
    }
    Buddies::respond($b, true);
    return Buddies::forUser($b)['shared_days'] !== []
        ?: 'no shared days once active';
});

// ---------------------------------------------------------------------------
echo "\n6. generation is not claiming to be synced\n";

t('the buddy prompt block claims days, not sessions', function () {
    /*
     * There IS a buddy block again, and that is correct — it tells the model to put a committed
     * session on every shared day (§10.0: the pairing outranks a marginally better split).
     * What it must not do is claim the two sessions match, which generation cannot deliver
     * while it runs per-user: the model sees one person and cannot know what the other was
     * told, so "identical between them" would agree only by coincidence.
     *
     * The earlier version of this test asserted the whole block was ABSENT, which was right
     * when it had just been deleted and became wrong the moment an honest version came back.
     * Asserting the claim rather than the section is what actually protects §10.6.
     *
     * It has since moved on again. The sessions DO match now, because §10.6 is built: the
     * follower reads the leader's written plan. So the "do not assume they match" warning is
     * conditional on there being no skeleton, and that condition is what this now checks.
     *
     * Checked against the source here because the rendered form needs a paired fixture with a
     * goal and a grid; bin/test-buddy-schedule.php does that properly against both halves of
     * the real prompt.
     */
    $src = (string) file_get_contents(YK_SRC . '/lib/Plans.php');
    if (!str_contains($src, 'PUT A COMMITTED SESSION ON EVERY SHARED DAY')) {
        return 'the buddy block no longer insists on the shared days';
    }
    if (!str_contains($src, 'do not assume the two')) {
        return 'nothing warns against assuming synced sessions when there is no skeleton';
    }
    // The warning must be reachable only when leading. Unconditional, it would contradict the
    // skeleton block sitting a few lines below it.
    return preg_match('/skeleton\'\]\s*\?\?\s*null\)\s*===\s*null/', $src) === 1
        ?: 'the no-skeleton warning is not gated on the absence of a skeleton';
});

t('the skeleton key is written, and both halves of a pair agree on it', function () {
    /*
     * §10.6. This replaces a test that asserted the column was NEVER written, which was honest
     * bookkeeping for as long as the synced path did not exist. It does now.
     *
     * The property worth protecting is not "something writes it" but that the two users arrive
     * at the SAME value independently. It is derived from (pair, date) precisely so neither side
     * has to read the other's row, and so a regenerated week re-links instead of orphaning.
     */
    $a = BuddySkeleton::keyFor(41, '2026-08-03');
    $b = BuddySkeleton::keyFor(41, '2026-08-03');
    if ($a !== $b) {
        return 'the same pair and date produced two different keys';
    }
    if (strlen($a) !== 36) {
        return 'the key is ' . strlen($a) . ' chars; the column is CHAR(36)';
    }
    if ($a === BuddySkeleton::keyFor(41, '2026-08-05')) {
        return 'two different days in a week share one key';
    }
    return $a !== BuddySkeleton::keyFor(42, '2026-08-03')
        ?: 'two different pairs share one key';
});

// ---------------------------------------------------------------------------

if (!$keep) {
    DB::run("DELETE FROM users WHERE username LIKE 'bud_%'");
    echo "\nfixtures removed\n";
} else {
    echo "\nfixtures kept\n";
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
