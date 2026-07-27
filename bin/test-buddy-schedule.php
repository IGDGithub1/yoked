<?php
declare(strict_types=1);

/**
 * The two-schedule model (SPEC-coaching §10.1a, §10.3, §10.3a, §10.3b).
 *
 * What this file is really about:
 *
 * THE INDIVIDUAL GRID IS NEVER TOUCHED. §10.1a. A day conceded for a buddy must not rewrite
 * the answer the user gave about their own week, because that answer is the fallback when the
 * buddy goes quiet (§10.5) and there would be no way to tell a conceded day from an original
 * one afterwards.
 *
 * A CONCEDED DAY IS LEGAL. The other half of the same point: Safety rejects a session on a day
 * the grid says is unavailable, so an agreement the pair explicitly made has to override that
 * or every plan built from it gets rejected.
 *
 * THE SURPLUS CHOICE IS THE USER'S. §10.3b. Three answers, all defensible, and the app asks
 * rather than picking — including not picking silently by defaulting.
 *
 *   php bin/test-buddy-schedule.php
 *   php bin/test-buddy-schedule.php --keep
 */

require __DIR__ . '/../src/bootstrap_cli.php';
require YK_SRC . '/lib/Response.php';
require YK_SRC . '/lib/RateLimit.php';
require YK_SRC . '/lib/Goals.php';
require YK_SRC . '/lib/Claude.php';
require YK_SRC . '/lib/PlanSchema.php';
require YK_SRC . '/lib/Notify.php';
require YK_SRC . '/lib/Friends.php';
require YK_SRC . '/lib/BuddySchedule.php';
require YK_SRC . '/lib/Safety.php';
require YK_SRC . '/lib/Buddies.php';
require YK_SRC . '/lib/Plans.php';
require YK_SRC . '/lib/ConstraintLabel.php';
require YK_SRC . '/lib/Settings.php';
require YK_SRC . '/lib/Drift.php';         // BuddyAbsence reads lastLoggedDate
require YK_SRC . '/lib/BuddyAbsence.php';  // Plans::gatherContext reads it
require YK_SRC . '/lib/BuddySkeleton.php';  // Plans::gatherContext and Plans::persist read it
require YK_SRC . '/lib/Training.php';       // §10.4 asserts on the day payload's is_shared

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

DB::run("DELETE FROM users WHERE username LIKE 'bsch_%'");

function seedUser(string $handle, int $committed = 3): int
{
    $id = (int) DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
         VALUES (?, ?, ?, "x", "active")',
        ['bsch_' . $handle, 'Sched ' . $handle, "bsch_{$handle}@example.test"]
    );
    DB::run(
        'INSERT INTO profiles (user_id, timezone, committed_days_per_week)
         VALUES (?, "UTC", ?)',
        [$id, $committed]
    );
    return $id;
}

/** $days maps weekday => [minutes, access]; everything else becomes can_train = no. */
function grid(int $userId, array $days): void
{
    for ($d = 1; $d <= 7; $d++) {
        [$mins, $access] = $days[$d] ?? [null, null];
        DB::run(
            'INSERT INTO availability (user_id, weekday, can_train, minutes, access)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE can_train = VALUES(can_train),
                 minutes = VALUES(minutes), access = VALUES(access)',
            [$userId, $d, isset($days[$d]) ? 'yes' : 'no', $mins, $access]
        );
    }
}

/** Pair two users properly, through the real path. */
function pairUp(int $a, int $b): int
{
    Friends::request($a, $b);
    Friends::respond($b, $a, true);
    Buddies::invite($a, $b);
    Buddies::respond($b, true);
    $pair = BuddySchedule::activePair($a);
    return (int) $pair['id'];
}

echo "Buddy schedule tests\n\n";

// ---------------------------------------------------------------------------
echo "1. seeding from the natural overlap\n";

t('pairing seeds every day both already had free', function () {
    // §10.3: the common case needs no negotiation. Two compatible weeks are just paired up.
    $a = seedUser('seed_a');
    $b = seedUser('seed_b');
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym'], 5 => [60, 'full_gym']]);
    grid($b, [3 => [45, 'full_gym'], 5 => [45, 'home_gym'], 7 => [60, 'full_gym']]);

    $pairId = pairUp($a, $b);
    return BuddySchedule::agreedDays($pairId) === [3, 5]
        ?: 'agreed days are ' . implode(',', BuddySchedule::agreedDays($pairId));
});

t('the shared duration is the shorter of the two', function () {
    // A shared session cannot outlast whichever of them has to leave (§10.3).
    $a = seedUser('min_a');
    $b = seedUser('min_b');
    grid($a, [3 => [90, 'full_gym']]);
    grid($b, [3 => [45, 'full_gym']]);
    $pairId = pairUp($a, $b);

    $eff = BuddySchedule::effective($a, $pairId);
    return $eff[3]['minutes'] === 45
        ?: 'shared minutes are ' . var_export($eff[3]['minutes'], true);
});

t('the shared facility is the MORE capable of the two, not the least', function () {
    /*
     * This assertion used to be the exact opposite, and the opposite was wrong.
     *
     * A pair trains in the same place, physically. Resolving full_gym against bodyweight down
     * to bodyweight does not name a place both can attend — it names a capability tier they
     * happen to share, in two different locations, and the only question that mattered (whose
     * gym?) was never asked. It also compromised in one direction only, so pairing could cost
     * you equipment and never gain you any.
     *
     * The guess is now that the better-equipped venue wins and the other person travels, which
     * is what most pairs actually do. It stays a GUESS — see the unconfirmed-days test below.
     */
    $a = seedUser('acc_a');
    $b = seedUser('acc_b');
    grid($a, [2 => [60, 'full_gym']]);
    grid($b, [2 => [60, 'bodyweight']]);
    $pairId = pairUp($a, $b);

    $eff = BuddySchedule::effective($a, $pairId);
    if ($eff[2]['access'] !== 'full_gym') {
        return 'shared access is ' . var_export($eff[2]['access'], true);
    }
    // And the less-equipped user sees the same shared day, since it is one session.
    $theirs = BuddySchedule::effective($b, $pairId);
    return $theirs[2]['access'] === 'full_gym'
        ?: 'the two halves of the pair disagree about where they are training';
});

t('the guess is marked unconfirmed, and says what each of them said', function () {
    /*
     * The app cannot know whose car works, who has a guest pass, or who would rather not have
     * company at home. So it guesses, and then says it guessed rather than acting quietly.
     */
    $a = seedUser('accq_a');
    $b = seedUser('accq_b');
    grid($a, [2 => [60, 'full_gym']]);
    grid($b, [2 => [60, 'home_gym']]);
    pairUp($a, $b);

    $rows = BuddySchedule::unconfirmedDays($a);
    if (count($rows) !== 1) {
        return count($rows) . ' unconfirmed days, expected 1';
    }
    $r = $rows[0];
    if ($r['assumed'] !== 'full_gym') {
        return "assumed {$r['assumed']}, expected full_gym";
    }
    // Both sides are reported so the UI can offer only what one of them actually has.
    return ($r['yours'] === 'full_gym' && $r['theirs'] === 'home_gym')
        ?: "the two answers came back as {$r['yours']}/{$r['theirs']}";
});

t('agreeing facilities is not asked when they already match', function () {
    // Nothing to settle, so nothing to nag about.
    $a = seedUser('accm_a');
    $b = seedUser('accm_b');
    grid($a, [2 => [60, 'full_gym']]);
    grid($b, [2 => [60, 'full_gym']]);
    pairUp($a, $b);

    return BuddySchedule::unconfirmedDays($a) === []
        ?: 'the app asked where to train when both said the same thing';
});

t('either user can settle it, and it stops being a guess', function () {
    $a = seedUser('accs_a');
    $b = seedUser('accs_b');
    grid($a, [2 => [60, 'full_gym']]);
    grid($b, [2 => [60, 'home_gym']]);
    $pairId = pairUp($a, $b);

    // The LESS equipped user decides they would rather host, which is the case the
    // most-capable guess gets wrong and the reason this is settleable at all.
    $r = BuddySchedule::setDayAccess($b, 2, 'home_gym');
    if (!$r['ok']) {
        return 'setting it failed: ' . (string) $r['error'];
    }

    $eff = BuddySchedule::effective($a, $pairId);
    if ($eff[2]['access'] !== 'home_gym') {
        return 'the other user still sees ' . var_export($eff[2]['access'], true);
    }
    return BuddySchedule::unconfirmedDays($a) === []
        ?: 'the day is still listed as a guess after being settled';
});

t('a settled facility survives a grid edit', function () {
    /*
     * Re-seeding happens whenever either grid changes, and it must not throw away an agreement
     * between two people because somebody edited an unrelated Tuesday. Minutes still refresh —
     * those come straight from the grid — but the facility is a negotiated thing.
     */
    $a = seedUser('accp_a');
    $b = seedUser('accp_b');
    grid($a, [2 => [60, 'full_gym'], 4 => [60, 'full_gym']]);
    grid($b, [2 => [60, 'home_gym'], 4 => [60, 'home_gym']]);
    $pairId = pairUp($a, $b);

    BuddySchedule::setDayAccess($b, 2, 'home_gym');

    // A grid edit, which re-seeds the intersection.
    grid($a, [2 => [45, 'full_gym'], 4 => [60, 'full_gym']]);
    BuddySchedule::seedFromIntersection($pairId, $a, $b);

    $eff = BuddySchedule::effective($a, $pairId);
    if ($eff[2]['access'] !== 'home_gym') {
        return 'the agreement was overwritten by re-seeding';
    }
    // The duration DID refresh, because that is what the grid is for.
    return $eff[2]['minutes'] === 45
        ?: 'the shorter window did not take effect: ' . var_export($eff[2]['minutes'], true);
});

t('a shared full gym does NOT leak into the individual days', function () {
    /*
     * The boundary that makes this safe. Visiting a full gym with your buddy on Wednesday does
     * not mean you have one on Tuesday — §10.1a: the individual grid is never rewritten.
     */
    $a = seedUser('accl_a', 3);
    $b = seedUser('accl_b', 3);
    grid($a, [2 => [60, 'full_gym'], 4 => [60, 'full_gym']]);
    // B shares Tuesday with A and trains alone on Thursday.
    grid($b, [2 => [60, 'home_gym'], 4 => [60, 'home_gym']]);
    $pairId = pairUp($a, $b);
    BuddySchedule::dropDay($b, 4);

    $eff = BuddySchedule::effective($b, $pairId);
    if ($eff[2]['access'] !== 'full_gym') {
        return 'the shared day is not the better facility';
    }
    if ($eff[4]['access'] !== 'home_gym') {
        return "the solo Thursday became {$eff[4]['access']}";
    }
    // And their own grid row is untouched, which is what the solo fallback reads.
    $own = BuddySchedule::effective($b, null);
    return $own[2]['access'] === 'home_gym'
        ?: 'the individual grid was rewritten to ' . var_export($own[2]['access'], true);
});

t('"sometimes" counts as free', function () {
    // The grid vocabulary makes it a maybe, not a no. Excluding it reports no shared days for
    // exactly the chaotic-schedule pair that most needs the accountability.
    $a = seedUser('some_a');
    $b = seedUser('some_b');
    grid($a, [4 => [60, 'full_gym']]);
    DB::run('UPDATE availability SET can_train = "sometimes" WHERE user_id = ? AND weekday = 4',
        [$a]);
    grid($b, [4 => [60, 'full_gym']]);
    $pairId = pairUp($a, $b);
    return BuddySchedule::agreedDays($pairId) === [4]
        ?: 'a "sometimes" day was not shared';
});

// ---------------------------------------------------------------------------
echo "\n2. the individual grid is never rewritten (§10.1a)\n";

t('a conceded day does not touch the grid', function () {
    /*
     * The load-bearing assertion of the model. If agreeing to a Wednesday edited the grid,
     * there would be no way to tell a conceded day from an original one — and falling back
     * when the buddy goes quiet (§10.5) would fall back to the compromise rather than to what
     * the user actually said about their own week.
     */
    $a = seedUser('keep_a');
    $b = seedUser('keep_b');
    grid($a, [1 => [60, 'full_gym'], 6 => [60, 'full_gym']]);
    grid($b, [2 => [60, 'full_gym'], 6 => [60, 'full_gym']]);
    $pairId = pairUp($a, $b);

    // B offers Monday, which is not in B's own grid. A accepts.
    BuddySchedule::offerDay($b, 1, 45, 'full_gym');
    $offer = DB::one(
        'SELECT id FROM buddy_day_offers WHERE buddy_pair_id = ? AND weekday = 1', [$pairId]
    );
    BuddySchedule::respondToOffer($a, (int) $offer['id'], true);

    // B's own grid still says Monday is a no.
    $row = DB::one(
        'SELECT can_train FROM availability WHERE user_id = ? AND weekday = 1', [$b]
    );
    if ((string) $row['can_train'] !== 'no') {
        return 'the grid was rewritten to ' . (string) $row['can_train'];
    }
    // And the effective schedule says yes, which is the point.
    return BuddySchedule::effective($b, $pairId)[1]['can_train'] === 'yes'
        ?: 'the conceded day is not effective';
});

t('the fallback is the grid, not the compromise', function () {
    // §10.5: passing a null pair id is what a buddy going quiet looks like, and it must give
    // back the user's own week rather than the negotiated one.
    $b = (int) DB::one('SELECT id FROM users WHERE username = "bsch_keep_b"')['id'];
    return BuddySchedule::effective($b, null)[1]['can_train'] === 'no'
        ?: 'the fallback kept the conceded day';
});

t('a conceded day is legal availability', function () {
    /*
     * The other half. Safety rejects a session on a day the grid calls unavailable, so without
     * the overlay every plan built from an agreement the pair made would be rejected.
     */
    $b = (int) DB::one('SELECT id FROM users WHERE username = "bsch_keep_b"')['id'];
    $monday = date('Y-m-d', strtotime('next monday'));

    $plan = ['sessions' => [[
        'date' => $monday, 'session_type' => 'strength', 'is_committed' => true,
        'target_minutes' => 45, 'location' => 'full_gym',
    ]]];

    $ref = new ReflectionMethod(Safety::class, 'checkAvailability');
    $ref->setAccessible(true);
    $violations = $ref->invoke(null, $plan, $b);

    return $violations === []
        ?: 'a conceded day was rejected: ' . implode(' | ', $violations);
});

t('a day nobody agreed to is still rejected', function () {
    // The overlay must not make every day legal. Tuesday is in neither the grid nor the
    // schedule for this user.
    $b = (int) DB::one('SELECT id FROM users WHERE username = "bsch_keep_b"')['id'];
    $tuesday = date('Y-m-d', strtotime('next tuesday'));

    $plan = ['sessions' => [[
        'date' => $tuesday, 'session_type' => 'strength', 'is_committed' => true,
        'target_minutes' => 45, 'location' => 'full_gym',
    ]]];

    $ref = new ReflectionMethod(Safety::class, 'checkAvailability');
    $ref->setAccessible(true);
    // Tuesday IS in B's grid from the fixture above, so use a day that is in neither.
    $thursday = date('Y-m-d', strtotime('next thursday'));
    $plan['sessions'][0]['date'] = $thursday;
    $violations = $ref->invoke(null, $plan, $b);

    return $violations !== []
        ?: 'an unavailable day passed validation';
});

// ---------------------------------------------------------------------------
echo "\n3. thin overlap is detected (§10.3a)\n";

t('a thin overlap is flagged', function () {
    /*
     * §10.3a: too thin is an intersection covering fewer days than the SMALLER committed
     * count. M/W/F/Sa against Tu/Th/Sa/Sun overlap only on Saturday, which is not a training
     * partnership.
     */
    $a = seedUser('thin_a', 4);
    $b = seedUser('thin_b', 4);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym'], 5 => [60, 'full_gym'], 6 => [60, 'full_gym']]);
    grid($b, [2 => [60, 'full_gym'], 4 => [60, 'full_gym'], 6 => [60, 'full_gym'], 7 => [60, 'full_gym']]);

    $an = BuddySchedule::analyse($a, $b);
    if ($an['overlap'] !== [6]) {
        return 'overlap is ' . implode(',', $an['overlap']);
    }
    if ($an['needed'] !== 4) {
        return 'needed is ' . $an['needed'];
    }
    return $an['thin'] === true ?: 'a one-day overlap was not flagged as thin';
});

t('a healthy overlap is not flagged', function () {
    $a = seedUser('fat_a', 3);
    $b = seedUser('fat_b', 3);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym'], 5 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym'], 5 => [60, 'full_gym']]);
    return BuddySchedule::analyse($a, $b)['thin'] === false
        ?: 'a full overlap was flagged as thin';
});

t('needed is the SMALLER of the two counts', function () {
    // A 5-day user paired with a 3-day user needs three shared days, not five: beyond that the
    // surplus is individual anyway and there is nothing to negotiate about.
    $a = seedUser('need_a', 5);
    $b = seedUser('need_b', 3);
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    return BuddySchedule::analyse($a, $b)['needed'] === 3
        ?: 'needed is ' . BuddySchedule::analyse($a, $b)['needed'];
});

t('once negotiated, the prompt stops', function () {
    // Measured against what is AGREED, so a pair that sorted it out is not nagged forever.
    $a = seedUser('done_a', 2);
    $b = seedUser('done_b', 2);
    grid($a, [1 => [60, 'full_gym'], 6 => [60, 'full_gym']]);
    grid($b, [2 => [60, 'full_gym'], 6 => [60, 'full_gym']]);
    $pairId = pairUp($a, $b);

    if (BuddySchedule::analyse($a, $b, $pairId)['thin'] !== true) {
        return 'a one-day overlap against a two-day need was not thin';
    }

    BuddySchedule::offerDay($b, 1, 60, 'full_gym');
    $offer = DB::one(
        'SELECT id FROM buddy_day_offers WHERE buddy_pair_id = ? AND weekday = 1', [$pairId]
    );
    BuddySchedule::respondToOffer($a, (int) $offer['id'], true);

    return BuddySchedule::analyse($a, $b, $pairId)['thin'] === false
        ?: 'still thin after agreeing a second day';
});

// ---------------------------------------------------------------------------
echo "\n4. offers\n";

t('you cannot accept your own offer', function () {
    // Otherwise one user sets the shared schedule unilaterally.
    $a = seedUser('own_a');
    $b = seedUser('own_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    $pairId = pairUp($a, $b);

    BuddySchedule::offerDay($a, 4, 60, 'full_gym');
    $offer = DB::one(
        'SELECT id FROM buddy_day_offers WHERE buddy_pair_id = ? AND weekday = 4', [$pairId]
    );
    $r = BuddySchedule::respondToOffer($a, (int) $offer['id'], true);
    if ($r['ok'] !== false) {
        return 'accepted own offer';
    }
    return !in_array(4, BuddySchedule::agreedDays($pairId), true)
        ?: 'the day was agreed anyway';
});

t('both offering the same day resolves rather than queueing', function () {
    // Two people reaching for the same compromise at once should end up agreed.
    $a = seedUser('both_a');
    $b = seedUser('both_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    $pairId = pairUp($a, $b);

    BuddySchedule::offerDay($a, 4, 60, 'full_gym');
    $r = BuddySchedule::offerDay($b, 4, 45, 'full_gym');
    if (($r['status'] ?? '') !== 'agreed') {
        return 'the second offer returned ' . var_export($r['status'] ?? null, true);
    }
    return in_array(4, BuddySchedule::agreedDays($pairId), true)
        ?: 'the day was not agreed';
});

t('offering an already-shared day is a no-op, not an error', function () {
    // The user tapped a day that is already agreed. Saying "done" beats complaining.
    $a = seedUser('noop_a');
    $b = seedUser('noop_b');
    grid($a, [3 => [60, 'full_gym']]);
    grid($b, [3 => [60, 'full_gym']]);
    pairUp($a, $b);
    $r = BuddySchedule::offerDay($a, 3, 60, 'full_gym');
    return ($r['ok'] && $r['status'] === 'already_shared') ?: json_encode($r);
});

t('an offer can be withdrawn by its author only', function () {
    $a = seedUser('wd_a');
    $b = seedUser('wd_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    $pairId = pairUp($a, $b);

    BuddySchedule::offerDay($a, 5, 60, 'full_gym');
    $offer = (int) DB::one(
        'SELECT id FROM buddy_day_offers WHERE buddy_pair_id = ? AND weekday = 5', [$pairId]
    )['id'];

    if (BuddySchedule::withdrawOffer($b, $offer)['ok'] !== false) {
        return 'the recipient withdrew someone elses offer';
    }
    return BuddySchedule::withdrawOffer($a, $offer)['ok'] === true
        ?: 'the author could not withdraw';
});

t('a dropped day leaves the schedule', function () {
    $a = seedUser('drop_a');
    $b = seedUser('drop_b');
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    $pairId = pairUp($a, $b);

    BuddySchedule::dropDay($a, 1);
    return BuddySchedule::agreedDays($pairId) === [3]
        ?: 'agreed days are ' . implode(',', BuddySchedule::agreedDays($pairId));
});

// ---------------------------------------------------------------------------
echo "\n5. the surplus choice is the user's (§10.3b)\n";

t('no surplus means no question', function () {
    $a = seedUser('nos_a', 2);
    $b = seedUser('nos_b', 2);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    $pairId = pairUp($a, $b);

    $tgt = BuddySchedule::committedTarget($a, $pairId);
    if ($tgt['needs_choice'] !== false) {
        return 'asked a question with no surplus';
    }
    return $tgt['committed'] === 2 ?: 'committed is ' . $tgt['committed'];
});

t('a surplus asks, and does NOT silently shrink the week', function () {
    /*
     * The compromise I pushed back on and the user resolved: until they answer, the honest
     * default is the commitment they made. Silently dropping to the shared days would cost
     * them training they never agreed to give up.
     */
    $a = seedUser('surp_a', 5);
    $b = seedUser('surp_b', 3);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym'], 5 => [60, 'full_gym'],
              2 => [60, 'full_gym'], 4 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym'], 5 => [60, 'full_gym']]);
    $pairId = pairUp($a, $b);

    $tgt = BuddySchedule::committedTarget($a, $pairId);
    if ($tgt['surplus'] !== 2) {
        return 'surplus is ' . $tgt['surplus'];
    }
    if ($tgt['needs_choice'] !== true) {
        return 'the question was not raised';
    }
    return $tgt['committed'] === 5
        ?: 'the week shrank to ' . $tgt['committed'] . ' before they answered';
});

t('keep_commitment keeps the stated count', function () {
    $a = (int) DB::one('SELECT id FROM users WHERE username = "bsch_surp_a"')['id'];
    $pairId = (int) BuddySchedule::activePair($a)['id'];
    BuddySchedule::setSurplusMode($a, 'keep_commitment');
    $tgt = BuddySchedule::committedTarget($a, $pairId);
    return ($tgt['committed'] === 5 && $tgt['fill_individual'] === true)
        ?: json_encode($tgt);
});

t('extras_optional drops the count but still fills days', function () {
    $a = (int) DB::one('SELECT id FROM users WHERE username = "bsch_surp_a"')['id'];
    $pairId = (int) BuddySchedule::activePair($a)['id'];
    BuddySchedule::setSurplusMode($a, 'extras_optional');
    $tgt = BuddySchedule::committedTarget($a, $pairId);
    return ($tgt['committed'] === 3 && $tgt['fill_individual'] === true)
        ?: json_encode($tgt);
});

t('match_buddy drops the count and generates nothing extra', function () {
    $a = (int) DB::one('SELECT id FROM users WHERE username = "bsch_surp_a"')['id'];
    $pairId = (int) BuddySchedule::activePair($a)['id'];
    BuddySchedule::setSurplusMode($a, 'match_buddy');
    $tgt = BuddySchedule::committedTarget($a, $pairId);
    return ($tgt['committed'] === 3 && $tgt['fill_individual'] === false)
        ?: json_encode($tgt);
});

t('the validator honours the chosen mode', function () {
    /*
     * Otherwise the choice is decorative: Safety would reject a three-session plan from a
     * five-day user who asked to match their buddy.
     */
    $a = (int) DB::one('SELECT id FROM users WHERE username = "bsch_surp_a"')['id'];
    BuddySchedule::setSurplusMode($a, 'match_buddy');

    $monday = strtotime('next monday');
    $sessions = [];
    foreach ([0, 2, 4] as $offset) {   // Mon, Wed, Fri — the three shared days
        $sessions[] = [
            'date' => date('Y-m-d', strtotime("+{$offset} days", $monday)),
            'session_type' => 'strength', 'is_committed' => true,
            'target_minutes' => 60, 'location' => 'full_gym',
        ];
    }

    $ref = new ReflectionMethod(Safety::class, 'checkCommittedCount');
    $ref->setAccessible(true);
    $violations = $ref->invoke(null, ['sessions' => $sessions], $a);
    return $violations === []
        ?: 'three sessions rejected for a match_buddy user: ' . implode(' | ', $violations);
});

t('an unpaired user is unaffected by any of this', function () {
    // The whole solo path has to be untouched. A stated count is still exactly the count.
    $solo = seedUser('solo', 4);
    grid($solo, [1 => [60, 'full_gym'], 2 => [60, 'full_gym'],
                 3 => [60, 'full_gym'], 4 => [60, 'full_gym']]);
    $tgt = BuddySchedule::committedTarget($solo, null);
    if ($tgt['committed'] !== 4 || $tgt['needs_choice'] !== false) {
        return json_encode($tgt);
    }
    // And the effective schedule is exactly the grid.
    $eff = BuddySchedule::effective($solo, null);
    return ($eff[1]['can_train'] === 'yes' && $eff[5]['can_train'] === 'no')
        ?: 'the solo grid was altered';
});

t('a schedule change re-asks the surplus question', function () {
    // §10.3b: "re-asked if the shared schedule changes." A stale answer describes a week that
    // no longer exists.
    $a = seedUser('reask_a', 4);
    $b = seedUser('reask_b', 4);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym'], 5 => [60, 'full_gym'], 6 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym'], 5 => [60, 'full_gym']]);
    $pairId = pairUp($a, $b);

    BuddySchedule::setSurplusMode($a, 'match_buddy');
    if (BuddySchedule::surplusMode($a) !== 'match_buddy') {
        return 'the mode did not save';
    }

    BuddySchedule::dropDay($b, 1);
    return BuddySchedule::surplusMode($a) === null
        ?: 'the stale answer survived a schedule change';
});

t('a bad mode is refused', function () {
    $a = seedUser('badmode', 3);
    return BuddySchedule::setSurplusMode($a, 'whatever')['ok'] === false
        ?: 'an unknown surplus mode was accepted';
});

// ---------------------------------------------------------------------------
echo "\n6. the prompt says what is true, and no more\n";

t('shared days are marked in the availability block', function () {
    $a = seedUser('prm_a', 3);
    $b = seedUser('prm_b', 3);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym'], 5 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym'], 5 => [60, 'full_gym']]);
    DB::run(
        'INSERT INTO goals (user_id, primary_goal, success_statement, requested_timeline,
                            horizon_weeks, scale_vs_feel, status)
         VALUES (?, "lose_fat", "x", "16_weeks", 16, "both", "active")', [$a]
    );
    pairUp($a, $b);

    $gather = new ReflectionMethod(Plans::class, 'gatherContext');
    $gather->setAccessible(true);
    $ctx = $gather->invoke(null, $a, date('Y-m-d', strtotime('next monday')));
    if (($ctx['error'] ?? null) !== null) {
        return 'context failed: ' . (string) $ctx['error'];
    }

    /*
     * BOTH halves of the prompt, because the two pieces live in different ones and reading
     * only userPrompt reported a missing marker that was present all along.
     *
     * The availability grid is in the SYSTEM prompt, which is the cached prefix — the right
     * place for it, since the grid changes rarely. The shared-day instruction is in the USER
     * prompt, which is per-request. Anthropic keys the cache on the literal prefix, so a
     * changed grid simply misses and re-reads rather than serving a stale week.
     */
    $sys = new ReflectionMethod(Plans::class, 'systemPrompt');
    $sys->setAccessible(true);
    $system = (string) $sys->invoke(null, $ctx);

    $prompt = new ReflectionMethod(Plans::class, 'userPrompt');
    $prompt->setAccessible(true);
    $text = (string) $prompt->invoke(null, $ctx, date('Y-m-d', strtotime('next monday')), []);

    if (!str_contains($system, '[SHARED with their buddy]')) {
        return 'no shared-day marker in the system prompt';
    }
    return str_contains($text, 'PUT A COMMITTED SESSION ON EVERY SHARED DAY')
        ?: 'the user prompt does not insist on the shared days';
});

t('the LEADER is not told the sessions match', function () {
    /*
     * §10.6. The follower is told to match, and that is now true, because the buddy's plan is
     * written and being read back. The LEADER is a different case: nothing exists to match yet,
     * so any claim that the sessions agree would be a guess dressed as an instruction.
     *
     * This is the same test that used to assert the claim was absent unconditionally. That was
     * right while §10.6 was unbuilt; the invariant now is narrower and this fixture is the
     * leading case (neither user has a plan for the week).
     */
    /*
     * Checked against the RENDERED prompt, not the source.
     *
     * Grepping the file matched the code comment that explains why the claim was removed,
     * which is the opposite of a regression. What matters is what the model is actually told.
     */
    $a = seedUser('claim_a', 3);
    $b = seedUser('claim_b', 3);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym'], 5 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym'], 5 => [60, 'full_gym']]);
    DB::run(
        'INSERT INTO goals (user_id, primary_goal, success_statement, requested_timeline,
                            horizon_weeks, scale_vs_feel, status)
         VALUES (?, "lose_fat", "x", "16_weeks", 16, "both", "active")', [$a]
    );
    pairUp($a, $b);

    $gather = new ReflectionMethod(Plans::class, 'gatherContext');
    $gather->setAccessible(true);
    $ctx = $gather->invoke(null, $a, date('Y-m-d', strtotime('next monday')));
    if (($ctx['error'] ?? null) !== null) {
        return 'context failed: ' . (string) $ctx['error'];
    }

    $sys = new ReflectionMethod(Plans::class, 'systemPrompt');
    $sys->setAccessible(true);
    $prompt = new ReflectionMethod(Plans::class, 'userPrompt');
    $prompt->setAccessible(true);
    $all = (string) $sys->invoke(null, $ctx)
         . (string) $prompt->invoke(null, $ctx, date('Y-m-d', strtotime('next monday')), []);

    // No plan exists for either user, so this user leads and there is no skeleton to follow.
    if (($ctx['skeleton'] ?? null) !== null) {
        return 'a skeleton appeared for a pair where neither user has a plan';
    }

    foreach (['identical between them', 'same exercises, same sets',
              'SHARED SESSIONS WITH'] as $claim) {
        if (str_contains($all, $claim)) {
            return "the leader is told the sessions are synced: \"{$claim}\"";
        }
    }
    return str_contains($all, 'do not assume the two sessions match')
        ?: 'the leader is not warned against assuming the sessions match';
});

// ---------------------------------------------------------------------------
echo "\n7. inherited training limits (§10.2b)\n";

/** Give a user a constraint. Returns its id. */
function constrain(int $userId, string $kind, string $tier, string $subject,
                   ?string $reason = null): int
{
    return (int) DB::insert(
        'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
         VALUES (?, ?, ?, ?, ?, "onboarding")',
        [$userId, $kind, $tier, $subject, $reason]
    );
}

/** Is this subject present at this tier, for this user? */
function hasConstraint(int $userId, string $tier, string $subject): bool
{
    foreach (Safety::forUser($userId)[$tier] as $c) {
        if (strtolower((string) $c['subject']) === strtolower($subject)) {
            return true;
        }
    }
    return false;
}

t('a buddy movement avoid is inherited', function () {
    /*
     * §10.2b: "While paired, each user takes on their buddy's training avoids. If one cannot
     * ski, skiing is not suggested to either of them for as long as the pair lasts."
     */
    $a = seedUser('inh_a');
    $b = seedUser('inh_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    constrain($b, 'movement', 'soft', 'burpees', 'hates them');
    pairUp($a, $b);

    return hasConstraint($a, 'soft', 'burpees')
        ?: 'the buddy movement avoid was not inherited';
});

t('THE TIER DOES NOT TRANSFER: a hard avoid arrives soft', function () {
    /*
     * The load-bearing assertion of this section, and SPEC-safety §6 holding the line.
     *
     * A hard constraint is a limit the user set for themselves, deliberately. Nothing another
     * person does should create one: a plan rejected over somebody else's preference is a
     * failure the user cannot fix and cannot reason about.
     */
    $a = seedUser('tier_a');
    $b = seedUser('tier_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    constrain($b, 'movement', 'hard', 'back squat', 'ACL reconstruction');
    pairUp($a, $b);

    if (hasConstraint($a, 'hard', 'back squat')) {
        return 'a buddy HARD constraint became hard for the other user';
    }
    return hasConstraint($a, 'soft', 'back squat')
        ?: 'the buddy hard constraint was not inherited at all';
});

t('an inherited limit cannot reject a plan', function () {
    /*
     * The consequence of the tier rule, checked at the validator rather than inferred. Soft
     * constraints are never enforced in validatePlan, so this asserts the two agree.
     */
    $a = seedUser('rej_a');
    $b = seedUser('rej_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    constrain($b, 'movement', 'hard', 'back squat', 'ACL');
    pairUp($a, $b);

    $ex = DB::one('SELECT id, slug FROM exercises WHERE slug LIKE "%squat%" LIMIT 1');
    if ($ex === null) {
        return null;   // no seeded exercise library to build a plan against
    }

    /*
     * Through validatePlan, not checkExercises directly.
     *
     * checkExercises takes pre-built ban maps rather than a user id, so calling it with an id
     * proves nothing about how forUser splits the tiers — which is the thing under test. The
     * public entry point exercises the real path.
     *
     * Filtered to movement violations: an otherwise-empty plan trips a dozen unrelated rules
     * (no meals, wrong committed count), and this test is about ONE of them.
     */
    $monday = date('Y-m-d', strtotime('next monday'));
    $plan = ['sessions' => [[
        'date' => $monday, 'session_type' => 'strength', 'is_committed' => true,
        'target_minutes' => 60, 'location' => 'full_gym',
        'exercises' => [['slug' => $ex['slug'], 'block' => 'main', 'sets' => 3,
                         'reps' => '8']],
    ]]];

    // "which is a hard constraint" is the wording checkExercises emits for a banned movement.
    $banned = array_filter(
        Safety::validatePlan($plan, $a),
        static fn(string $v): bool => str_contains($v, 'Substitute a different movement')
    );
    return $banned === []
        ?: 'an inherited limit rejected a plan: ' . implode(' | ', $banned);
});

t('the same limit still rejects a plan for the person who OWNS it', function () {
    // The other side of the same coin: softening it for the buddy must not soften it for them.
    $b = (int) DB::one('SELECT id FROM users WHERE username = "bsch_rej_b"')['id'];
    $ex = DB::one('SELECT slug FROM exercises WHERE slug LIKE "%squat%" LIMIT 1');
    if ($ex === null) {
        return null;
    }

    // Name the ban exactly as the owner holds it, so the substring match fires.
    DB::run('UPDATE user_constraints SET subject = ? WHERE user_id = ? AND tier = "hard"',
        [str_replace('-', ' ', (string) $ex['slug']), $b]);

    $monday = date('Y-m-d', strtotime('next monday'));
    $plan = ['sessions' => [[
        'date' => $monday, 'session_type' => 'strength', 'is_committed' => true,
        'target_minutes' => 60, 'location' => 'full_gym',
        'exercises' => [['slug' => $ex['slug'], 'block' => 'main', 'sets' => 3,
                         'reps' => '8']],
    ]]];

    $banned = array_filter(
        Safety::validatePlan($plan, $b),
        static fn(string $v): bool => str_contains($v, 'Substitute a different movement')
    );
    return $banned !== []
        ?: 'the owner of a hard movement ban had it ignored';
});

t('FOOD NEVER TRANSFERS', function () {
    /*
     * §10.2b: "Training only. Food constraints, allergies, dietary patterns and conditions
     * never transfer." Nutrition is out of scope for v1, and an allergy is not a preference to
     * compromise over — inheriting one would put a plan in front of someone that reads as
     * medically informed and is not.
     */
    $a = seedUser('food_a');
    $b = seedUser('food_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    constrain($b, 'food', 'hard', 'peanuts', 'anaphylaxis');
    constrain($b, 'food', 'soft', 'mushrooms', 'dislikes them');
    pairUp($a, $b);

    foreach (['peanuts', 'mushrooms'] as $subject) {
        if (hasConstraint($a, 'hard', $subject) || hasConstraint($a, 'soft', $subject)) {
            return "a food constraint transferred: {$subject}";
        }
    }
    return true;
});

t('a CONDITION never transfers', function () {
    // A condition is a modifier carrying medical guidance that belongs to one body, and the
    // buddy's diagnosis is not their partner's business (§10.4).
    $a = seedUser('cond_a');
    $b = seedUser('cond_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    constrain($b, 'condition', 'hard', 'diabetes_t2', 'reported at onboarding');
    pairUp($a, $b);

    return !hasConstraint($a, 'soft', 'diabetes_t2')
        && !hasConstraint($a, 'hard', 'diabetes_t2')
        ?: 'a medical condition transferred to the buddy';
});

t('an inherited limit never weakens one the user already holds', function () {
    /*
     * Both hold "back squat", the user as HARD. Adding a soft copy would put the same subject
     * in both buckets, which reads in the prompt as a limit that is somehow negotiable — and
     * it is not, because it is theirs.
     */
    $a = seedUser('dup_a');
    $b = seedUser('dup_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    constrain($a, 'movement', 'hard', 'back squat', 'my own ACL');
    constrain($b, 'movement', 'soft', 'back squat', 'just dislikes it');
    pairUp($a, $b);

    if (!hasConstraint($a, 'hard', 'back squat')) {
        return 'the user lost their own hard constraint';
    }
    return !hasConstraint($a, 'soft', 'back squat')
        ?: 'a soft copy was added alongside the hard one';
});

t('a duplicate soft limit is not listed twice', function () {
    $a = seedUser('dup2_a');
    $b = seedUser('dup2_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    constrain($a, 'cardio', 'soft', 'running', 'hates it');
    constrain($b, 'cardio', 'soft', 'running', 'also hates it');
    pairUp($a, $b);

    $n = 0;
    foreach (Safety::forUser($a)['soft'] as $c) {
        if (strtolower((string) $c['subject']) === 'running') {
            $n++;
        }
    }
    return $n === 1 ?: "running appears {$n} times";
});

t('the buddy REASON is not carried over', function () {
    /*
     * §10.4: pairing is not consent to share medical detail. "ACL reconstruction 2024" is a
     * diagnosis, and it is not the partner's business. The inherited row says only that the
     * buddy avoids it.
     */
    $a = seedUser('reason_a');
    $b = seedUser('reason_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    constrain($b, 'movement', 'hard', 'deadlift', 'herniated disc, L4-L5');
    pairUp($a, $b);

    foreach (Safety::forUser($a)['soft'] as $c) {
        if (strtolower((string) $c['subject']) === 'deadlift') {
            return !str_contains(strtolower((string) $c['reason']), 'herniated')
                ?: 'the buddy medical reason leaked: ' . (string) $c['reason'];
        }
    }
    return 'the constraint was not inherited at all';
});

t('inherited limits reach the prompt', function () {
    // Steering generation is the whole point. promptBlock and validatePlan share forUser, so
    // this confirms the shared loader carries them.
    $a = (int) DB::one('SELECT id FROM users WHERE username = "bsch_inh_a"')['id'];
    return str_contains(Safety::promptBlock($a), 'burpees')
        ?: 'the inherited limit does not reach the prompt';
});

t('they vanish on unpairing', function () {
    /*
     * "Inherited limits vanish on unpairing. They are a property of the pair, not of the user."
     * Nothing was persisted, so this is structural rather than a cleanup step — which is the
     * reason it was built that way.
     */
    $a = (int) DB::one('SELECT id FROM users WHERE username = "bsch_inh_a"')['id'];
    if (!hasConstraint($a, 'soft', 'burpees')) {
        return 'the fixture was not inheriting to begin with';
    }
    Buddies::unpair($a);
    return !hasConstraint($a, 'soft', 'burpees')
        ?: 'an inherited limit survived unpairing';
});

t('nothing is written to user_constraints', function () {
    /*
     * SPEC-safety §7: veto promotion is "the one automated write path". Inheritance must not
     * become a second one — a row written on pairing would need cleaning up on unpairing, and
     * a missed cleanup is a constraint the user can never explain or remove.
     */
    $a = seedUser('nowrite_a');
    $b = seedUser('nowrite_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    constrain($b, 'movement', 'soft', 'box jumps', 'knees');
    $before = (int) DB::one(
        'SELECT COUNT(*) AS n FROM user_constraints WHERE user_id = ?', [$a]
    )['n'];

    pairUp($a, $b);
    Safety::forUser($a);          // reading must not write
    Safety::promptBlock($a);

    $after = (int) DB::one(
        'SELECT COUNT(*) AS n FROM user_constraints WHERE user_id = ?', [$a]
    )['n'];
    return $before === $after
        ?: "inheritance wrote {$after} rows where there were {$before}";
});

t('an unpaired user inherits nothing', function () {
    // The solo path has to be untouched.
    $solo = seedUser('solo_inh');
    grid($solo, [1 => [60, 'full_gym']]);
    constrain($solo, 'movement', 'soft', 'lunges', 'dislikes');
    $soft = Safety::forUser($solo)['soft'];
    if (count($soft) !== 1) {
        return count($soft) . ' soft constraints for an unpaired user, expected 1';
    }
    return ($soft[0]['inherited'] ?? false) === false
        ?: 'an unpaired user has an inherited row';
});

t('the profile lists an inherited limit, without a switch', function () {
    /*
     * It steers this user's plan, so a preference they cannot find in their profile would look
     * like the coach inventing things. But there is no row of theirs to turn off — unpairing is
     * what removes it — so offering a switch would imply otherwise.
     */
    $a = seedUser('prof_a');
    $b = seedUser('prof_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    constrain($b, 'cardio', 'soft', 'stair-machine', 'refused');
    pairUp($a, $b);

    $found = null;
    foreach (Settings::constraints($a) as $c) {
        if (($c['inherited'] ?? false) === true) {
            $found = $c;
        }
    }
    if ($found === null) {
        return 'no inherited row on the profile';
    }
    if ($found['switchable'] !== false) {
        return 'an inherited limit is offered as switchable';
    }
    if ($found['id'] !== null) {
        return 'an inherited row carries an id it does not have';
    }
    // Readable, not the raw slug.
    return $found['label'] === 'Stair machine'
        ?: 'the label is ' . var_export($found['label'], true);
});

// ---------------------------------------------------------------------------
echo "\n8. absence never strands the other user (§10.5)\n";

/** Next Monday, which is the week generation is usually about. */
function nextWeek(): string
{
    return date('Y-m-d', strtotime('next monday'));
}

t('with both present, the buddy is available', function () {
    $a = seedUser('av_present_a');
    $b = seedUser('av_present_b');
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    pairUp($a, $b);

    $r = BuddyAbsence::availableFor($a, nextWeek());
    return ($r['available'] === true && $r['reason'] === 'available')
        ?: json_encode($r);
});

t('an unpaired user is always "available"', function () {
    // Nothing to be absent from. The reason distinguishes it so a caller can tell "no buddy"
    // from "buddy present".
    $solo = seedUser('av_solo');
    grid($solo, [1 => [60, 'full_gym']]);
    $r = BuddyAbsence::availableFor($solo, nextWeek());
    return ($r['available'] === true && $r['reason'] === 'unpaired') ?: json_encode($r);
});

t('declared travel over the coming week makes the buddy unavailable', function () {
    $a = seedUser('trav_a');
    $b = seedUser('trav_b');
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    pairUp($a, $b);

    $week = nextWeek();
    $r = BuddyAbsence::record($b, 'travel', $week,
        date('Y-m-d', strtotime($week . ' +9 days')));
    if (!$r['ok']) {
        return 'declaring failed: ' . (string) $r['error'];
    }

    $q = BuddyAbsence::availableFor($a, $week);
    if ($q['available'] !== false) {
        return 'the buddy still reads as available';
    }
    if ($q['reason'] !== 'declared_travel') {
        return 'reason is ' . (string) $q['reason'];
    }
    // The return date is the useful part: "back on the 4th" beats "away".
    return $q['returns_on'] !== null ?: 'no return date reported';
});

t('an absence is measured by OVERLAP, not containment', function () {
    /*
     * Someone away Wednesday to the following Tuesday is away for parts of two weeks, and both
     * partners' weeks should be solo. Containment would miss the second week entirely.
     */
    $a = seedUser('ovl_a');
    $b = seedUser('ovl_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    pairUp($a, $b);

    $week = nextWeek();
    // Starts mid-week, ends mid the NEXT week.
    BuddyAbsence::record($b, 'travel',
        date('Y-m-d', strtotime($week . ' +2 days')),
        date('Y-m-d', strtotime($week . ' +9 days')));

    if (BuddyAbsence::availableFor($a, $week)['available'] !== false) {
        return 'the first week was not affected';
    }
    return BuddyAbsence::availableFor($a, date('Y-m-d', strtotime($week . ' +7 days')))
        ['available'] === false
        ?: 'the second week was not affected';
});

t('a week outside the absence is unaffected', function () {
    // The absence must not leak into weeks it does not cover, or a solo week becomes permanent.
    $a = (int) DB::one('SELECT id FROM users WHERE username = "bsch_ovl_a"')['id'];
    $week = nextWeek();
    return BuddyAbsence::availableFor($a, date('Y-m-d', strtotime($week . ' +21 days')))
        ['available'] === true
        ?: 'a distant week was treated as covered by the absence';
});

t('an open-ended absence covers every week from its start', function () {
    // Illness with no known return. Treated as "away until they say otherwise" rather than as
    // a single week, and never as forever-in-the-past.
    $a = seedUser('open_a');
    $b = seedUser('open_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    pairUp($a, $b);

    BuddyAbsence::record($b, 'illness', nextWeek(), null);

    foreach ([0, 7, 28] as $offset) {
        $week = date('Y-m-d', strtotime(nextWeek() . " +{$offset} days"));
        if (BuddyAbsence::availableFor($a, $week)['available'] !== false) {
            return "week +{$offset} was not covered";
        }
    }
    return true;
});

t('cancelling brings the buddy back', function () {
    $a = (int) DB::one('SELECT id FROM users WHERE username = "bsch_open_a"')['id'];
    $b = (int) DB::one('SELECT id FROM users WHERE username = "bsch_open_b"')['id'];

    $r = BuddyAbsence::cancel($b);
    if (!$r['ok']) {
        return 'cancelling failed: ' . (string) $r['error'];
    }
    return BuddyAbsence::availableFor($a, nextWeek())['available'] === true
        ?: 'the buddy is still away after cancelling';
});

t('a cancelled absence is kept, not deleted', function () {
    // "I was away that week" survives as an explanation for a quiet stretch.
    $b = (int) DB::one('SELECT id FROM users WHERE username = "bsch_open_b"')['id'];
    $n = (int) DB::one(
        'SELECT COUNT(*) AS n FROM buddy_absences
         WHERE user_id = ? AND cancelled_at IS NOT NULL', [$b]
    )['n'];
    return $n > 0 ?: 'the cancelled absence was deleted';
});

t('a second declaration replaces the first rather than stacking', function () {
    /*
     * A corrected date is a correction, not a second absence. Two live rows would make "when
     * are they back" ambiguous, and availableFor would answer from whichever sorted first.
     */
    $a = seedUser('twice_a');
    $b = seedUser('twice_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    pairUp($a, $b);

    $week = nextWeek();
    BuddyAbsence::record($b, 'travel', $week, date('Y-m-d', strtotime($week . ' +3 days')));
    BuddyAbsence::record($b, 'travel', $week, date('Y-m-d', strtotime($week . ' +10 days')));

    $live = (int) DB::one(
        'SELECT COUNT(*) AS n FROM buddy_absences
         WHERE user_id = ? AND cancelled_at IS NULL', [$b]
    )['n'];
    if ($live !== 1) {
        return "{$live} live absences, expected 1";
    }
    // And it is the corrected one.
    $mine = BuddyAbsence::mine($b);
    return $mine['returns_on'] === date('Y-m-d', strtotime($week . ' +10 days'))
        ?: 'the return date is ' . var_export($mine['returns_on'] ?? null, true);
});

t('you cannot be back before you leave', function () {
    $b = (int) DB::one('SELECT id FROM users WHERE username = "bsch_twice_b"')['id'];
    $week = nextWeek();
    return BuddyAbsence::record($b, 'travel', $week,
        date('Y-m-d', strtotime($week . ' -3 days')))['ok'] === false
        ?: 'a return date before the start was accepted';
});

t('a bad kind is refused', function () {
    $b = (int) DB::one('SELECT id FROM users WHERE username = "bsch_twice_b"')['id'];
    return BuddyAbsence::record($b, 'abducted', nextWeek(), null)['ok'] === false
        ?: 'an unknown absence kind was accepted';
});

// ---------------------------------------------------------------------------
echo "\n9. undeclared silence is the safety net\n";

t('a buddy who has logged nothing for longer than their window reads as silent', function () {
    /*
     * §10.5's third case. Not the primary path, and deliberately conservative: it uses the
     * buddy's OWN nudge window, so somebody who told us a week of quiet is normal is not
     * written off after three days.
     */
    $a = seedUser('sil_a');
    $b = seedUser('sil_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    pairUp($a, $b);

    // Age the buddy's account past their window so "never logged" counts.
    DB::run('UPDATE users SET created_at = ? WHERE id = ?',
        [date('Y-m-d H:i:s', strtotime('-30 days')), $b]);

    $r = BuddyAbsence::availableFor($a, nextWeek());
    if ($r['available'] !== false) {
        return 'a long-silent buddy still reads as available';
    }
    return $r['reason'] === 'silent' ?: 'reason is ' . (string) $r['reason'];
});

t('a brand-new buddy is NOT read as a quitter', function () {
    /*
     * The failure this guards. Somebody who pairs up on the day they join has logged nothing
     * yet, and treating that as silence would strand their partner from the very first week —
     * for a buddy who has done nothing wrong.
     */
    $a = seedUser('new_a');
    $b = seedUser('new_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    pairUp($a, $b);
    // created_at defaults to now, so no ageing.

    return BuddyAbsence::availableFor($a, nextWeek())['available'] === true
        ?: 'a brand-new buddy was treated as having quit';
});

t('a buddy who logged recently is available', function () {
    $a = seedUser('rec_a');
    $b = seedUser('rec_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    pairUp($a, $b);
    DB::run('UPDATE users SET created_at = ? WHERE id = ?',
        [date('Y-m-d H:i:s', strtotime('-90 days')), $b]);

    // A logged day with real content, which is what Drift::lastLoggedDate looks for.
    $dayId = (int) DB::insert(
        'INSERT INTO logged_days (user_id, log_date) VALUES (?, CURDATE())', [$b]
    );
    $mealId = (int) DB::insert(
        'INSERT INTO logged_meals (logged_day_id, slot) VALUES (?, "breakfast")', [$dayId]
    );
    DB::run(
        'INSERT INTO logged_entries (logged_meal_id, name, calories, protein_g, fat_g, carbs_g)
         VALUES (?, "eggs", 200, 12, 14, 2)',
        [$mealId]
    );

    return BuddyAbsence::availableFor($a, nextWeek())['available'] === true
        ?: 'a buddy who logged today reads as silent';
});

t('a longer nudge window buys a buddy more rope', function () {
    // Somebody who set nudge_after_days to 14 has told us a fortnight of quiet is normal for
    // them. Writing them off after 4 days would contradict their own setting.
    $a = seedUser('rope_a');
    $b = seedUser('rope_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    pairUp($a, $b);
    DB::run('UPDATE users SET created_at = ? WHERE id = ?',
        [date('Y-m-d H:i:s', strtotime('-90 days')), $b]);
    DB::run('UPDATE profiles SET nudge_after_days = 14 WHERE user_id = ?', [$b]);

    // Last logged 8 days ago: past a default window, inside a 14-day one.
    $dayId = (int) DB::insert(
        'INSERT INTO logged_days (user_id, log_date) VALUES (?, ?)',
        [$b, date('Y-m-d', strtotime('-8 days'))]
    );
    $mealId = (int) DB::insert(
        'INSERT INTO logged_meals (logged_day_id, slot) VALUES (?, "breakfast")', [$dayId]
    );
    DB::run(
        'INSERT INTO logged_entries (logged_meal_id, name, calories, protein_g, fat_g, carbs_g)
         VALUES (?, "eggs", 200, 12, 14, 2)',
        [$mealId]
    );

    return BuddyAbsence::availableFor($a, nextWeek())['available'] === true
        ?: 'a buddy with a 14-day window was written off after 8';
});

// ---------------------------------------------------------------------------
echo "\n10. the partner keeps a complete week\n";

t('an away buddy drops the shared days from the effective schedule', function () {
    /*
     * §10.5: "Pairing is an enhancement to a complete single-user plan, never a dependency of
     * one." The fallback is the individual grid, which is what effective() returns with no
     * pair — so generation needs no separate solo path.
     */
    $a = seedUser('fall_a', 2);
    $b = seedUser('fall_b', 2);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    $pairId = pairUp($a, $b);

    // Paired and present: both days shared.
    $shared = 0;
    foreach (BuddySchedule::effective($a, $pairId) as $d) {
        if ($d['shared']) { $shared++; }
    }
    if ($shared !== 2) {
        return "{$shared} shared days before the absence, expected 2";
    }

    // The fallback: no pair id, which is what gatherContext passes when the buddy is away.
    $soloShared = 0;
    $solo = BuddySchedule::effective($a, null);
    foreach ($solo as $d) {
        if ($d['shared']) { $soloShared++; }
    }
    if ($soloShared !== 0) {
        return 'the fallback still reports shared days';
    }
    // And the user keeps their own days — a complete week, not an empty one.
    return ($solo[1]['can_train'] === 'yes' && $solo[3]['can_train'] === 'yes')
        ?: 'the fallback lost the user own days';
});

t('generation drops the pair when the buddy is away', function () {
    /*
     * The wiring, checked through gatherContext rather than inferred. This is the line that
     * makes the solo fallback real: the pair id is discarded, so the availability block has no
     * SHARED markers and the week is solo by construction.
     */
    $a = seedUser('gen_a', 2);
    $b = seedUser('gen_b', 2);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    DB::run(
        'INSERT INTO goals (user_id, primary_goal, success_statement, requested_timeline,
                            horizon_weeks, scale_vs_feel, status)
         VALUES (?, "lose_fat", "x", "16_weeks", 16, "both", "active")', [$a]
    );
    pairUp($a, $b);

    $week = nextWeek();
    BuddyAbsence::record($b, 'travel', $week, date('Y-m-d', strtotime($week . ' +6 days')));

    $gather = new ReflectionMethod(Plans::class, 'gatherContext');
    $gather->setAccessible(true);
    $ctx = $gather->invoke(null, $a, $week);
    if (($ctx['error'] ?? null) !== null) {
        return 'context failed: ' . (string) $ctx['error'];
    }

    if (($ctx['buddy_away'] ?? null) === null) {
        return 'the context does not report the buddy as away';
    }
    foreach ($ctx['availability'] as $day) {
        if (($day['shared'] ?? false) === true) {
            return 'a shared day survived into a solo week';
        }
    }
    return true;
});

t('the prompt says WHY the week is solo', function () {
    /*
     * The model can see the user has a buddy, so an unexplained absence of shared days invites
     * it to guess — and a guess here reads as "invent something buddy-ish". Saying it plainly
     * also stops the week being framed as a setback, which it is not.
     */
    $a = (int) DB::one('SELECT id FROM users WHERE username = "bsch_gen_a"')['id'];
    $week = nextWeek();

    $gather = new ReflectionMethod(Plans::class, 'gatherContext');
    $gather->setAccessible(true);
    $ctx = $gather->invoke(null, $a, $week);

    $prompt = new ReflectionMethod(Plans::class, 'userPrompt');
    $prompt->setAccessible(true);
    $text = (string) $prompt->invoke(null, $ctx, $week, []);

    if (!str_contains($text, 'buddy is away this week')) {
        return 'the prompt does not explain the solo week';
    }
    return str_contains($text, 'must not be framed as one')
        ?: 'the prompt does not say this is not a setback';
});

t('the partner is told, and the mid-week case says the plan is unchanged', function () {
    /*
     * §10.5: "reshuffling a week someone is halfway through is worse than letting them finish
     * it." So a mid-week illness notifies and changes nothing — but the notice has to SAY the
     * plan is unchanged, or a shared Thursday still on screen looks like a bug.
     */
    $a = seedUser('tell_a');
    $b = seedUser('tell_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    pairUp($a, $b);

    // Starting TODAY, which is inside the week the partner already has.
    $today = date('Y-m-d');
    BuddyAbsence::record($b, 'illness', $today, date('Y-m-d', strtotime('+5 days')));

    $n = DB::one(
        'SELECT body FROM notifications WHERE user_id = ? AND type = "buddy_away"
         ORDER BY id DESC LIMIT 1', [$a]
    );
    if ($n === null) {
        return 'the partner was not told';
    }
    $body = strtolower((string) $n['body']);
    if (!str_contains($body, 'unchanged')) {
        return 'the mid-week notice does not say the plan is unchanged: ' . $body;
    }
    // And it names the return date, which is the difference between adjusting and wondering.
    return str_contains($body, 'back on') ?: 'no return date in the notice: ' . $body;
});

t('a future absence is announced as next week being solo', function () {
    // Declared before generation, nothing is built yet, so nothing is disrupted — and the
    // wording should reflect that rather than talking about the current week.
    $a = seedUser('fut_a');
    $b = seedUser('fut_b');
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    pairUp($a, $b);

    BuddyAbsence::record($b, 'travel', date('Y-m-d', strtotime('+10 days')), null);

    $n = DB::one(
        'SELECT body FROM notifications WHERE user_id = ? AND type = "buddy_away"
         ORDER BY id DESC LIMIT 1', [$a]
    );
    if ($n === null) {
        return 'the partner was not told';
    }
    $body = strtolower((string) $n['body']);
    if (str_contains($body, 'unchanged')) {
        return 'a future absence was announced as a mid-week drop-out';
    }
    // Open-ended, so it must not invent a return date.
    return str_contains($body, 'not said when')
        ?: 'an open-ended absence did not say the return is unknown: ' . $body;
});

t('cancelling tells the partner too', function () {
    $a = (int) DB::one('SELECT id FROM users WHERE username = "bsch_fut_a"')['id'];
    $b = (int) DB::one('SELECT id FROM users WHERE username = "bsch_fut_b"')['id'];
    BuddyAbsence::cancel($b);
    $n = DB::one(
        'SELECT 1 AS x FROM notifications WHERE user_id = ? AND type = "buddy_back" LIMIT 1',
        [$a]
    );
    return $n !== null ?: 'the partner was not told their buddy is back';
});

t('an absence never rewrites the buddy schedule', function () {
    /*
     * The agreed days survive an absence: they are what the pair agreed, and the person is
     * coming back. Deleting them would mean renegotiating from scratch after every holiday.
     */
    $a = seedUser('keepsched_a', 2);
    $b = seedUser('keepsched_b', 2);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    $pairId = pairUp($a, $b);

    BuddyAbsence::record($b, 'travel', nextWeek(), null);
    return BuddySchedule::agreedDays($pairId) === [1, 3]
        ?: 'the agreed days are ' . implode(',', BuddySchedule::agreedDays($pairId));
});

// ---------------------------------------------------------------------------
echo "\n11. the shared skeleton (§10.6)\n";

/**
 * Write a plan for a user with one session per given weekday.
 *
 * Direct inserts rather than Plans::generateWeek, which would cost a model call per fixture and
 * take minutes. What is under test is the READING of a written plan, so a written plan is all
 * that is needed — and hand-writing it is the only way to assert on a KNOWN shape.
 */
function leaderPlan(int $userId, string $week, array $days, bool $committed = true): int
{
    $planId = (int) DB::insert(
        'INSERT INTO plan_versions (user_id, week_start, version, reason, summary)
         VALUES (?, ?, 1, "initial", "Seeded for skeleton tests.")',
        [$userId, $week]
    );

    // Two real system exercises with distinct patterns, plus a core one. Looked up rather than
    // inserted: the seeded catalogue is what production reads, and a fixture exercise would not
    // exercise the JOIN in blockShape.
    $squat = DB::one('SELECT id FROM exercises WHERE pattern = "squat"  AND is_system = 1 LIMIT 1');
    $hinge = DB::one('SELECT id FROM exercises WHERE pattern = "hinge"  AND is_system = 1 LIMIT 1');
    $core  = DB::one('SELECT id FROM exercises WHERE category = "core" AND is_system = 1 LIMIT 1');
    if ($squat === null || $hinge === null || $core === null) {
        throw new RuntimeException('the exercise catalogue is not seeded');
    }

    foreach ($days as $i => $weekday) {
        $date = date('Y-m-d', strtotime($week . ' +' . ($weekday - 1) . ' days'));
        $sid = (int) DB::insert(
            'INSERT INTO prescribed_sessions
             (plan_version_id, session_date, session_type, focus, is_committed,
              target_minutes, location, warmup_minutes, warmup_required, sort_order)
             VALUES (?, ?, "strength", "lower", ?, 60, "full_gym", 10, 1, ?)',
            [$planId, $date, $committed ? 1 : 0, $i]
        );
        // Order matters: squat then hinge is part of what the follower must reproduce.
        DB::run(
            'INSERT INTO prescribed_exercises (session_id, exercise_id, block, sort_order, sets, target_reps)
             VALUES (?, ?, "main", 0, 4, "8")',
            [$sid, (int) $squat['id']]
        );
        DB::run(
            'INSERT INTO prescribed_exercises (session_id, exercise_id, block, sort_order, sets, target_reps)
             VALUES (?, ?, "main", 1, 3, "10")',
            [$sid, (int) $hinge['id']]
        );
        DB::run(
            'INSERT INTO prescribed_exercises (session_id, exercise_id, block, sort_order, sets, target_seconds)
             VALUES (?, ?, "core", 0, 3, 45)',
            [$sid, (int) $core['id']]
        );
    }
    return $planId;
}

t('with no buddy plan written, there is nothing to follow', function () {
    // The leading case. Going first is not a mode; it is the absence of a skeleton.
    $a = seedUser('skel_lead_a', 2);
    $b = seedUser('skel_lead_b', 2);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    pairUp($a, $b);

    return BuddySkeleton::toFollow($a, nextWeek()) === null
        ?: 'a skeleton was returned for a pair with no plans';
});

t('an unpaired user never has a skeleton', function () {
    $solo = seedUser('skel_solo', 3);
    grid($solo, [1 => [60, 'full_gym']]);
    return BuddySkeleton::toFollow($solo, nextWeek()) === null
        ?: 'an unpaired user was given a skeleton to follow';
});

t('the follower reads the shared days of the leader plan', function () {
    /*
     * §10.6. The whole point: the second user to generate sees the first user's written sessions
     * on the days they share.
     */
    $a = seedUser('skel_read_a', 2);
    $b = seedUser('skel_read_b', 2);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    pairUp($a, $b);

    $week = nextWeek();
    leaderPlan($a, $week, [1, 3]);

    $skel = BuddySkeleton::toFollow($b, $week);
    if ($skel === null) {
        return 'the follower sees no skeleton although the leader has a plan';
    }
    if (count($skel['days']) !== 2) {
        return 'the skeleton covers ' . count($skel['days']) . ' days, expected 2';
    }

    $mon = date('Y-m-d', strtotime($week));
    $day = $skel['days'][$mon] ?? null;
    if ($day === null) {
        return 'the shared Monday is missing from the skeleton';
    }
    if ($day['session_type'] !== 'strength' || $day['focus'] !== 'lower') {
        return "the session shape did not survive: {$day['session_type']}/{$day['focus']}";
    }
    // Pattern AND order, which is the part that lets a mismatched pair train side by side.
    $patterns = array_column($day['main'], 'pattern');
    if ($patterns !== ['squat', 'hinge']) {
        return 'the main patterns are ' . implode(',', $patterns) . ', expected squat,hinge';
    }
    return count($day['core']) === 1
        ?: 'the core block did not come through';
});

t('the skeleton reports the SHARED window, not the leader own', function () {
    /*
     * The bug this exists to prevent, found by a live run rather than by reasoning.
     *
     * The leader's session row says 75 minutes, because that is what THEY were prescribed. The
     * shared day resolves to the SHORTER window (§10.3) — a shared session cannot outlast
     * whichever of them has to leave. Handing the follower the leader's figure under a "copy
     * exactly" instruction told them to fit 75 minutes into a 45-minute window, and the model
     * answered by scheduling the overflow on three days the follower had marked unavailable.
     * Three validation failures and a wasted generation.
     *
     * The FACILITY is settled the other way and deliberately so: the pair meets in one place,
     * and the guess is the better-equipped one with the other person travelling. The two
     * resolve in opposite directions because they answer different questions — how long can
     * both of them stay, versus where can they both train.
     */
    $a = seedUser('skel_win_a', 3);
    $b = seedUser('skel_win_b', 3);
    // A has 75 minutes and a full gym; B has 45 at home. They share Mon and Wed.
    grid($a, [1 => [75, 'full_gym'], 3 => [75, 'full_gym']]);
    grid($b, [1 => [45, 'home_gym'], 3 => [45, 'home_gym']]);
    pairUp($a, $b);

    $week   = nextWeek();
    $planId = leaderPlan($a, $week, [1, 3]);
    // leaderPlan writes 60 minutes and full_gym, standing in for the leader's own prescription.
    DB::run(
        'UPDATE prescribed_sessions SET target_minutes = 75, location = "full_gym"
         WHERE plan_version_id = ?',
        [$planId]
    );

    $skel = BuddySkeleton::toFollow($b, $week);
    if ($skel === null) {
        return 'no skeleton';
    }
    $mon = date('Y-m-d', strtotime($week));
    $day = $skel['days'][$mon];

    if ($day['target_minutes'] !== 45) {
        return "the skeleton offers {$day['target_minutes']} minutes against a 45 minute window";
    }
    // The agreed facility, which here is the gym the pair is assumed to be meeting at.
    if ($day['location'] !== 'full_gym') {
        return "the skeleton says {$day['location']}, not the facility the pair resolved to";
    }
    // And the warm-up is scaled to the shorter session rather than copied whole.
    if ($day['warmup_minutes'] !== null && $day['warmup_minutes'] > 10) {
        return "the warm-up is {$day['warmup_minutes']} min inside a 45 min session";
    }

    // The prompt must not tell them to copy a location, since that is the thing they cannot.
    $text = BuddySkeleton::promptBlock($skel);
    if (preg_match('/COPY EXACTLY:[^\n]*location/i', $text)) {
        return 'the prompt still tells the follower to copy the location';
    }
    return str_contains($text, 'SHARED ones')
        ?: 'the prompt does not say the length and location are already the shared ones';
});

t('a timed core hold is rendered by its seconds, not by prose', function () {
    /*
     * Found by a live run, twice over.
     *
     * The leader's target_reps for a plank is free text — "40s hold" — and target_seconds is 40.
     * Rendering the prose verbatim produced "Plank 3x40s hold" in the prompt, the follower
     * copied that string into its OWN target_reps, and what came back out of the database was
     * "3xhold" with the number gone. "3 x hold" is a prescription on somebody's screen.
     *
     * So a timed movement is described by its seconds, which are structured, and never by the
     * rep text, which is prose that may or may not repeat them.
     */
    $a = seedUser('skel_rep_a', 2);
    $b = seedUser('skel_rep_b', 2);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    pairUp($a, $b);

    $week   = nextWeek();
    $planId = leaderPlan($a, $week, [1, 3]);

    // Reproduce the real shape: a timed hold carrying prose in target_reps.
    DB::run(
        'UPDATE prescribed_exercises pe
         JOIN prescribed_sessions ps ON ps.id = pe.session_id
         SET pe.target_reps = "40s hold", pe.target_seconds = 40
         WHERE ps.plan_version_id = ? AND pe.block = "core"',
        [$planId]
    );

    $skel = BuddySkeleton::toFollow($b, $week);
    if ($skel === null) {
        return 'no skeleton';
    }
    $text = BuddySkeleton::promptBlock($skel);

    // The number has to survive, and the prose must not be handed over to be copied.
    if (!preg_match('/3 sets of 40 seconds/', $text)) {
        return 'the timed hold is not described by its seconds';
    }
    if (str_contains($text, '3x40s hold') || preg_match('/\dx.*hold/', $text)) {
        return 'the prompt still renders the rep prose verbatim';
    }
    return true;
});

t('the core block is presented as a list that must not be shortened', function () {
    /*
     * §10.2a says identical, and a live follower returned ONE core exercise where the leader had
     * two — Side Plank without the Overhead Plate Hold, Farmer Carry without the Pallof Press.
     * An instruction to match a list is not the same as an instruction to reproduce all of it.
     */
    $a = seedUser('skel_list_a', 2);
    $b = seedUser('skel_list_b', 2);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    pairUp($a, $b);

    $week = nextWeek();
    leaderPlan($a, $week, [1, 3]);

    $skel = BuddySkeleton::toFollow($b, $week);
    $text = BuddySkeleton::promptBlock($skel);

    if (!str_contains($text, 'Do not shorten the list')) {
        return 'nothing forbids dropping a core exercise';
    }
    /*
     * The COUNT, stated explicitly.
     *
     * "Give every one of these" was already present when a live follower returned one core
     * exercise where the leader had two. A number gives the model something to check its answer
     * against, which a quantifier does not.
     */
    if (!preg_match('/That is \d+ core exercise/', $text)) {
        return 'the number of core exercises is not stated';
    }
    // And the main block must forbid the duplicate substitution a live run produced.
    return str_contains($text, 'never list the same')
        ?: 'nothing forbids listing one movement twice after a substitution';
});

t('a loaded carry keeps its distance', function () {
    /*
     * blockShape did not select target_distance_m, so a Farmer Carry reached the follower with
     * no distance at all — and a follower cannot reproduce a dose it was never told. The live
     * run showed the leader on "3x30m" and the follower on a carry with nothing.
     *
     * §10.2a says the core block is identical, and a carry with no distance is not the same
     * prescription as a 30-metre one.
     */
    $a = seedUser('skel_dist_a', 2);
    $b = seedUser('skel_dist_b', 2);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    pairUp($a, $b);

    $week   = nextWeek();
    $planId = leaderPlan($a, $week, [1, 3]);

    // Turn the seeded core work into a loaded carry, which is where distance matters.
    $carry = DB::one('SELECT id FROM exercises WHERE pattern = "carry" AND is_system = 1 LIMIT 1');
    if ($carry === null) {
        return null;   // no carry in the catalogue; nothing this test can say
    }
    DB::run(
        'UPDATE prescribed_exercises pe
         JOIN prescribed_sessions ps ON ps.id = pe.session_id
         SET pe.exercise_id = ?, pe.target_seconds = NULL, pe.target_reps = NULL,
             pe.target_distance_m = 30
         WHERE ps.plan_version_id = ? AND pe.block = "core"',
        [(int) $carry['id'], $planId]
    );

    $skel = BuddySkeleton::toFollow($b, $week);
    if ($skel === null) {
        return 'no skeleton';
    }
    $mon = date('Y-m-d', strtotime($week));
    $core = $skel['days'][$mon]['core'][0] ?? null;
    if ($core === null) {
        return 'the core block is empty';
    }
    if (($core['distance_m'] ?? null) !== 30) {
        return 'the skeleton lost the carry distance: '
             . var_export($core['distance_m'] ?? null, true);
    }
    return str_contains(BuddySkeleton::promptBlock($skel), '30 metres')
        ?: 'the prompt does not state the carry distance';
});

t('a solo day of the leader is NOT in the skeleton', function () {
    /*
     * §10.1: only the shared days are shared. A day the leader trains alone is theirs to shape,
     * and copying it would sync a session the two of them are not attending together.
     */
    $a = seedUser('skel_own_a', 3);
    $b = seedUser('skel_own_b', 2);
    // A trains Mon/Wed/Fri, B only Mon/Wed, so Friday is A's alone.
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym'], 5 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    pairUp($a, $b);

    $week = nextWeek();
    leaderPlan($a, $week, [1, 3, 5]);

    $skel = BuddySkeleton::toFollow($b, $week);
    if ($skel === null) {
        return 'no skeleton at all';
    }
    $friday = date('Y-m-d', strtotime($week . ' +4 days'));
    return !isset($skel['days'][$friday])
        ?: 'the leader own Friday leaked into the shared skeleton';
});

t('an OPTIONAL session of the leader is not a shared skeleton', function () {
    /*
     * §3.3a. An optional extra is a bonus they may or may not do. Building the pair's shared
     * session around it would mean matching something that might never happen.
     */
    $a = seedUser('skel_opt_a', 2);
    $b = seedUser('skel_opt_b', 2);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    pairUp($a, $b);

    $week = nextWeek();
    leaderPlan($a, $week, [1, 3], false);   // every session optional

    return BuddySkeleton::toFollow($b, $week) === null
        ?: 'an optional session became a shared skeleton';
});

t('a superseded plan is not followed', function () {
    /*
     * A superseded version is what the buddy USED to be doing. Matching a plan that has since
     * been revised would put the pair back out of step, which is the opposite of the point.
     */
    $a = seedUser('skel_sup_a', 2);
    $b = seedUser('skel_sup_b', 2);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    pairUp($a, $b);

    $week   = nextWeek();
    $planId = leaderPlan($a, $week, [1, 3]);
    DB::run('UPDATE plan_versions SET superseded_at = NOW() WHERE id = ?', [$planId]);

    return BuddySkeleton::toFollow($b, $week) === null
        ?: 'a superseded plan was followed';
});

t('an away buddy leaves nothing to follow', function () {
    /*
     * §10.5. The week is solo by construction, so a skeleton would contradict the plan being
     * generated. Their plan may well exist — they wrote it before declaring.
     */
    $a = seedUser('skel_away_a', 2);
    $b = seedUser('skel_away_b', 2);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    pairUp($a, $b);

    $week = nextWeek();
    leaderPlan($a, $week, [1, 3]);
    if (BuddySkeleton::toFollow($b, $week) === null) {
        return 'no skeleton even before the absence';
    }

    BuddyAbsence::record($a, 'travel', $week, null);
    return BuddySkeleton::toFollow($b, $week) === null
        ?: 'an away buddy still supplied a skeleton';
});

t('persisting a plan stamps the shared days, and only those', function () {
    $a = seedUser('skel_stamp_a', 3);
    $b = seedUser('skel_stamp_b', 2);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym'], 5 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    $pairId = pairUp($a, $b);

    $week   = nextWeek();
    $planId = leaderPlan($a, $week, [1, 3, 5]);
    $n = BuddySkeleton::stamp($a, $planId, $week);
    if ($n !== 2) {
        return "{$n} sessions stamped, expected 2 (Mon and Wed, not the solo Fri)";
    }

    $mon = date('Y-m-d', strtotime($week));
    $row = DB::one(
        'SELECT shared_skeleton_key FROM prescribed_sessions
         WHERE plan_version_id = ? AND session_date = ?',
        [$planId, $mon]
    );
    if (($row['shared_skeleton_key'] ?? null) !== BuddySkeleton::keyFor($pairId, $mon)) {
        return 'the Monday key is not the pair key for that date';
    }

    $friday = date('Y-m-d', strtotime($week . ' +4 days'));
    $solo = DB::one(
        'SELECT shared_skeleton_key FROM prescribed_sessions
         WHERE plan_version_id = ? AND session_date = ?',
        [$planId, $friday]
    );
    return ($solo['shared_skeleton_key'] ?? null) === null
        ?: 'the solo Friday was stamped as shared';
});

t('BOTH halves of a pair end up on the same key', function () {
    /*
     * This is what makes the link real rather than decorative: adherence can tell a genuinely
     * shared session from two people who happened to train the same day.
     */
    $a = seedUser('skel_both_a', 2);
    $b = seedUser('skel_both_b', 2);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    pairUp($a, $b);

    $week = nextWeek();
    $pa = leaderPlan($a, $week, [1, 3]);
    $pb = leaderPlan($b, $week, [1, 3]);
    BuddySkeleton::stamp($a, $pa, $week);
    BuddySkeleton::stamp($b, $pb, $week);

    $mon  = date('Y-m-d', strtotime($week));
    $keys = DB::all(
        'SELECT DISTINCT shared_skeleton_key AS k FROM prescribed_sessions
         WHERE plan_version_id IN (?, ?) AND session_date = ?',
        [$pa, $pb, $mon]
    );
    if (count($keys) !== 1) {
        return 'the two plans carry ' . count($keys) . ' distinct keys for the shared Monday';
    }
    return $keys[0]['k'] !== null ?: 'both sides are unstamped';
});

t('an unpaired plan is never stamped', function () {
    $solo = seedUser('skel_nostamp', 3);
    grid($solo, [1 => [60, 'full_gym']]);
    $planId = leaderPlan($solo, nextWeek(), [1]);

    if (BuddySkeleton::stamp($solo, $planId, nextWeek()) !== 0) {
        return 'stamp() wrote something for an unpaired user';
    }
    $row = DB::one(
        'SELECT COUNT(*) AS n FROM prescribed_sessions
         WHERE plan_version_id = ? AND shared_skeleton_key IS NOT NULL',
        [$planId]
    );
    return (int) ($row['n'] ?? 0) === 0
        ?: 'a solo plan has skeleton keys';
});

t('the follower is told to match the shape and decide the loads', function () {
    /*
     * The failure modes run in both directions, so both are asserted. Told only "match your
     * buddy", a model copies the loads too — which is exactly what §10.1 keeps individual, and
     * what would put a beginner under an experienced lifter's working weight.
     */
    $a = seedUser('skel_prm_a', 2);
    $b = seedUser('skel_prm_b', 2);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    DB::run(
        'INSERT INTO goals (user_id, primary_goal, success_statement, requested_timeline,
                            horizon_weeks, scale_vs_feel, status)
         VALUES (?, "lose_fat", "x", "16_weeks", 16, "both", "active")', [$b]
    );
    pairUp($a, $b);

    $week = nextWeek();
    leaderPlan($a, $week, [1, 3]);

    $gather = new ReflectionMethod(Plans::class, 'gatherContext');
    $gather->setAccessible(true);
    $ctx = $gather->invoke(null, $b, $week);
    if (($ctx['error'] ?? null) !== null) {
        return 'context failed: ' . (string) $ctx['error'];
    }
    if (($ctx['skeleton'] ?? null) === null) {
        return 'gatherContext did not pick up the skeleton';
    }

    $prompt = new ReflectionMethod(Plans::class, 'userPrompt');
    $prompt->setAccessible(true);
    $text = (string) $prompt->invoke(null, $ctx, $week, []);

    foreach (['SHARED SESSIONS WITH', 'COPY EXACTLY', 'COPY THE CORE BLOCK IN FULL',
              'DECIDE FOR THEM', 'DIVERGE WHERE YOU MUST'] as $needed) {
        if (!str_contains($text, $needed)) {
            return "the follower block is missing \"{$needed}\"";
        }
    }
    // The leading-case warning must be gone now that there IS something to match.
    if (str_contains($text, 'do not assume the two sessions match')) {
        return 'the follower is still told not to assume the sessions match';
    }
    // The patterns must be named, not just gestured at.
    return str_contains($text, 'squat') && str_contains($text, 'hinge')
        ?: 'the skeleton block does not name the movement patterns';
});

t('divergence on a hard constraint is explicitly allowed', function () {
    /*
     * §10.2. A skeleton must never be able to talk somebody into a movement they must not do.
     * Belt and braces: the follower's plan is validated on its own regardless, but the prompt
     * has to SAY that changing an exercise is expected rather than a failure, or the model
     * treats matching as the harder requirement.
     */
    $a = seedUser('skel_div_a', 2);
    $b = seedUser('skel_div_b', 2);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    DB::run(
        'INSERT INTO goals (user_id, primary_goal, success_statement, requested_timeline,
                            horizon_weeks, scale_vs_feel, status)
         VALUES (?, "lose_fat", "x", "16_weeks", 16, "both", "active")', [$b]
    );
    pairUp($a, $b);

    // B cannot squat, and the leader's shared day opens with one.
    constrain($b, 'movement', 'hard', 'squat', 'knee surgery');

    $week = nextWeek();
    leaderPlan($a, $week, [1, 3]);

    $gather = new ReflectionMethod(Plans::class, 'gatherContext');
    $gather->setAccessible(true);
    $ctx = $gather->invoke(null, $b, $week);

    $sys = new ReflectionMethod(Plans::class, 'systemPrompt');
    $sys->setAccessible(true);
    $system = (string) $sys->invoke(null, $ctx);

    $prompt = new ReflectionMethod(Plans::class, 'userPrompt');
    $prompt->setAccessible(true);
    $text = (string) $prompt->invoke(null, $ctx, $week, []);

    /*
     * The user's own limit is stated BEFORE the skeleton asks them to match anything.
     *
     * Across the two prompts rather than within one: constraints are in the SYSTEM prompt (the
     * cached prefix, which is where a rarely-changing hard limit belongs) and the skeleton is in
     * the USER prompt, which is per-request. The system prompt precedes the user prompt in the
     * request, so the ordering is structural. An earlier version of this test measured strpos
     * within userPrompt alone and reported a violation that could not happen.
     */
    if (!str_contains($system, 'HARD CONSTRAINTS')) {
        return 'the hard constraint is not in the system prompt';
    }
    if (!str_contains($system, 'squat')) {
        return 'the squat limit did not reach the prompt at all';
    }
    if (str_contains($system, 'SHARED SESSIONS WITH')) {
        return 'the skeleton leaked into the cached system prefix';
    }
    if (!str_contains($text, 'SHARED SESSIONS WITH')) {
        return 'no skeleton block in the user prompt';
    }
    return str_contains($text, 'hard constraints forbid')
        ?: 'nothing tells the model it may diverge for a hard constraint';
});

// ---------------------------------------------------------------------------
echo "\n12. is the pairing actually working? (§10.4)\n";

/**
 * Log a session against a prescribed one.
 *
 * $together is the answer to "did you train with your buddy", which is the whole datum §10.4
 * turns on.
 */
function logAgainst(int $userId, int $prescribedId, string $date,
                    string $status, bool $together): void
{
    $dayId = DB::one(
        'SELECT id FROM logged_days WHERE user_id = ? AND log_date = ?', [$userId, $date]
    );
    $dayId = $dayId === null
        ? (int) DB::insert(
            'INSERT INTO logged_days (user_id, log_date) VALUES (?, ?)', [$userId, $date]
        )
        : (int) $dayId['id'];

    DB::run(
        'INSERT INTO logged_sessions
            (logged_day_id, user_id, prescribed_session_id, session_type, status,
             actual_minutes, trained_with_buddy)
         VALUES (?, ?, ?, "strength", ?, 45, ?)',
        [$dayId, $userId, $prescribedId, $status, $together ? 1 : 0]
    );
}

/** The §10.4 signal for a user, via reflection since it is private. */
function adherenceFor(int $userId, string $week): ?array
{
    $m = new ReflectionMethod(Plans::class, 'buddyAdherence');
    $m->setAccessible(true);
    return $m->invoke(null, $userId, $week);
}

t('an unpaired user has no pairing signal at all', function () {
    // Not zeroes: silence. A solo user's prompt must not carry a buddy section saying 0 of 0.
    $solo = seedUser('ba_solo', 3);
    grid($solo, [1 => [60, 'full_gym']]);
    return adherenceFor($solo, nextWeek()) === null
        ?: 'an unpaired user was given a pairing signal';
});

t('a pair with no shared sessions yet reports nothing', function () {
    /*
     * Paired on Monday but the week has not come round. Reporting "0 of 0 done" would read as
     * a failure to the coach when nothing has happened yet, and §10.5 is clear that a pairing
     * must never make somebody look behind.
     */
    $a = seedUser('ba_new_a', 3);
    $b = seedUser('ba_new_b', 3);
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    pairUp($a, $b);

    return adherenceFor($a, nextWeek()) === null
        ?: 'a pairing with no history reported a score';
});

t('shared and solo days are counted separately', function () {
    /*
     * The comparison is the point. "3 of 4 shared done" means nothing on its own; "3 of 4
     * shared and 1 of 4 solo" says the pairing is carrying them, and the reverse says it is
     * not. §10.0 traded precision for adherence, and this is the only place the app can check
     * whether that trade paid.
     */
    $a = seedUser('ba_mix_a', 4);
    $b = seedUser('ba_mix_b', 4);
    grid($a, [1 => [60, 'full_gym'], 2 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    $pairId = pairUp($a, $b);

    // A week in the PAST, since the signal looks back 28 days from the given week start.
    $past = date('Y-m-d', strtotime('monday -2 weeks'));
    $week = date('Y-m-d', strtotime('next monday'));

    $planId = (int) DB::insert(
        'INSERT INTO plan_versions (user_id, week_start, version, reason)
         VALUES (?, ?, 1, "initial")',
        [$a, $past]
    );

    // Mon and Wed are shared; Tue is A's own.
    $ids = [];
    foreach ([1 => true, 2 => false, 3 => true] as $wd => $shared) {
        $date = date('Y-m-d', strtotime($past . ' +' . ($wd - 1) . ' days'));
        $ids[$wd] = [(int) DB::insert(
            'INSERT INTO prescribed_sessions
                (plan_version_id, session_date, session_type, focus, is_committed,
                 target_minutes, shared_skeleton_key, sort_order)
             VALUES (?, ?, "strength", "lower", 1, 60, ?, ?)',
            [$planId, $date, $shared ? BuddySkeleton::keyFor($pairId, $date) : null, $wd]
        ), $date];
    }

    // Both shared days done, and both actually together. The solo Tuesday skipped.
    logAgainst($a, $ids[1][0], $ids[1][1], 'completed', true);
    logAgainst($a, $ids[3][0], $ids[3][1], 'completed', true);
    logAgainst($a, $ids[2][0], $ids[2][1], 'skipped', false);

    $r = adherenceFor($a, $week);
    if ($r === null) {
        return 'no signal despite two shared sessions';
    }
    if ($r['shared_prescribed'] !== 2 || $r['shared_completed'] !== 2) {
        return "shared: {$r['shared_completed']} of {$r['shared_prescribed']}, expected 2 of 2";
    }
    if ($r['shared_together'] !== 2) {
        return "only {$r['shared_together']} of the shared sessions were together";
    }
    return ($r['solo_prescribed'] === 1 && $r['solo_completed'] === 0)
        ?: "solo: {$r['solo_completed']} of {$r['solo_prescribed']}, expected 0 of 1";
});

t('a session done ALONE on a shared day is counted, but not as together', function () {
    /*
     * The distinction the checkbox exists for. Somebody who trains on the agreed day without
     * their buddy has still trained — that is adherence — but the PAIRING did not happen, and
     * a coach reading "shared days all done" would draw the wrong conclusion about why.
     */
    $a = seedUser('ba_alone_a', 3);
    $b = seedUser('ba_alone_b', 3);
    grid($a, [1 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    $pairId = pairUp($a, $b);

    $past = date('Y-m-d', strtotime('monday -2 weeks'));
    $week = date('Y-m-d', strtotime('next monday'));

    $planId = (int) DB::insert(
        'INSERT INTO plan_versions (user_id, week_start, version, reason)
         VALUES (?, ?, 1, "initial")',
        [$a, $past]
    );
    $sid = (int) DB::insert(
        'INSERT INTO prescribed_sessions
            (plan_version_id, session_date, session_type, focus, is_committed,
             target_minutes, shared_skeleton_key, sort_order)
         VALUES (?, ?, "strength", "lower", 1, 60, ?, 0)',
        [$planId, $past, BuddySkeleton::keyFor($pairId, $past)]
    );
    logAgainst($a, $sid, $past, 'completed', false);

    $r = adherenceFor($a, $week);
    if ($r === null) {
        return 'no signal';
    }
    if ($r['shared_completed'] !== 1) {
        return 'the session was not counted as done';
    }
    return $r['shared_together'] === 0
        ?: 'a solo session on a shared day was counted as trained together';
});

t('the prompt reports counts, and refuses to shame them with it', function () {
    /*
     * §10.4: buddy pressure is gentle regardless of tone. Somebody who picked sarcastic hardass
     * chose that for the app's voice, not for having a friend's attendance held over them —
     * "your buddy showed up and you did not" is the app doing something a real friend would
     * not.
     *
     * The numbers go to the COACH so it can adjust the week. They are not a stick.
     */
    $a = seedUser('ba_prm_a', 3);
    $b = seedUser('ba_prm_b', 3);
    grid($a, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym'], 3 => [60, 'full_gym']]);
    DB::run(
        'INSERT INTO goals (user_id, primary_goal, success_statement, requested_timeline,
                            horizon_weeks, scale_vs_feel, status)
         VALUES (?, "lose_fat", "x", "16_weeks", 16, "both", "active")', [$a]
    );
    $pairId = pairUp($a, $b);

    $past = date('Y-m-d', strtotime('monday -2 weeks'));
    $week = date('Y-m-d', strtotime('next monday'));
    $planId = (int) DB::insert(
        'INSERT INTO plan_versions (user_id, week_start, version, reason)
         VALUES (?, ?, 1, "initial")',
        [$a, $past]
    );
    $sid = (int) DB::insert(
        'INSERT INTO prescribed_sessions
            (plan_version_id, session_date, session_type, focus, is_committed,
             target_minutes, shared_skeleton_key, sort_order)
         VALUES (?, ?, "strength", "lower", 1, 60, ?, 0)',
        [$planId, $past, BuddySkeleton::keyFor($pairId, $past)]
    );
    logAgainst($a, $sid, $past, 'completed', true);

    $gather = new ReflectionMethod(Plans::class, 'gatherContext');
    $gather->setAccessible(true);
    $ctx = $gather->invoke(null, $a, $week);
    if (($ctx['buddy_adherence'] ?? null) === null) {
        return 'gatherContext did not pick up the signal';
    }

    $prompt = new ReflectionMethod(Plans::class, 'userPrompt');
    $prompt->setAccessible(true);
    $text = (string) $prompt->invoke(null, $ctx, $week, []);

    if (!str_contains($text, 'SHARED sessions done')) {
        return 'the counts are not in the prompt';
    }
    if (!str_contains($text, 'trained together')) {
        return 'the together count is not in the prompt';
    }
    /*
     * The instruction that keeps this a coaching signal rather than a stick.
     *
     * Asserted on both halves of it, because they fail differently: without the first the model
     * narrates the buddy's attendance back at the user, and without the second it can use
     * "your buddy showed up" as leverage. Either turns a measurement into a nudge, which is the
     * thing this deliberately is not.
     */
    if (!preg_match('/do not comment on what their buddy did/i', $text)) {
        return 'nothing stops the coach reporting on the buddy';
    }
    return preg_match('/shame them into training/i', $text) === 1
        ?: 'nothing stops the coach using the buddy as leverage';
});

t('an unpaired user prompt says nothing about pairing adherence', function () {
    $solo = seedUser('ba_qui', 3);
    grid($solo, [1 => [60, 'full_gym']]);
    DB::run(
        'INSERT INTO goals (user_id, primary_goal, success_statement, requested_timeline,
                            horizon_weeks, scale_vs_feel, status)
         VALUES (?, "lose_fat", "x", "16_weeks", 16, "both", "active")', [$solo]
    );

    $gather = new ReflectionMethod(Plans::class, 'gatherContext');
    $gather->setAccessible(true);
    $ctx = $gather->invoke(null, $solo, nextWeek());

    $prompt = new ReflectionMethod(Plans::class, 'userPrompt');
    $prompt->setAccessible(true);
    $text = (string) $prompt->invoke(null, $ctx, nextWeek(), []);

    return !str_contains($text, 'SHARED sessions done')
        ?: 'a solo user prompt talks about shared sessions';
});

t('a shared session is flagged to the client, and a solo one is not', function () {
    /*
     * What decides whether the "trained with your buddy?" question appears at all. Asking it on
     * a solo Tuesday, or of somebody with no buddy, is the noise that teaches people to ignore
     * a checkbox.
     */
    $a = seedUser('ba_ui_a', 3);
    $b = seedUser('ba_ui_b', 3);
    grid($a, [1 => [60, 'full_gym'], 2 => [60, 'full_gym']]);
    grid($b, [1 => [60, 'full_gym']]);
    $pairId = pairUp($a, $b);

    $week   = date('Y-m-d', strtotime('next monday'));
    $mon    = $week;
    $tue    = date('Y-m-d', strtotime($week . ' +1 days'));
    $planId = (int) DB::insert(
        'INSERT INTO plan_versions (user_id, week_start, version, reason)
         VALUES (?, ?, 1, "initial")',
        [$a, $week]
    );
    DB::run(
        'INSERT INTO prescribed_sessions
            (plan_version_id, session_date, session_type, focus, is_committed,
             target_minutes, shared_skeleton_key, sort_order)
         VALUES (?, ?, "strength", "lower", 1, 60, ?, 0)',
        [$planId, $mon, BuddySkeleton::keyFor($pairId, $mon)]
    );
    DB::run(
        'INSERT INTO prescribed_sessions
            (plan_version_id, session_date, session_type, focus, is_committed,
             target_minutes, shared_skeleton_key, sort_order)
         VALUES (?, ?, "strength", "upper", 1, 60, NULL, 1)',
        [$planId, $tue]
    );

    $shared = Training::day($a, $mon);
    $solo   = Training::day($a, $tue);

    if (($shared['sessions'][0]['is_shared'] ?? null) !== true) {
        return 'the shared Monday is not flagged';
    }
    if (($solo['sessions'][0]['is_shared'] ?? null) !== false) {
        return 'the solo Tuesday is flagged as shared';
    }
    // And the pairing flag, which is what lets a free-form log ask the question at all.
    return ($shared['paired'] ?? null) === true
        ?: 'the day payload does not say the user is paired';
});

t('an unpaired user day payload says so', function () {
    $solo = seedUser('ba_ui_solo', 3);
    grid($solo, [1 => [60, 'full_gym']]);
    return (Training::day($solo, date('Y-m-d', strtotime('next monday')))['paired'] ?? null) === false
        ?: 'an unpaired user is reported as paired';
});

// ---------------------------------------------------------------------------

if (!$keep) {
    DB::run("DELETE FROM users WHERE username LIKE 'bsch_%'");
    echo "\nfixtures removed\n";
} else {
    echo "\nfixtures kept\n";
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
