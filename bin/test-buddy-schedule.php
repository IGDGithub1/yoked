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

t('the shared access is the more restrictive of the two', function () {
    /*
     * Both users have to be able to train there. Somebody with only bodyweight at home cannot
     * join a full-gym session, so the pair trains bodyweight — not the other way round.
     */
    $a = seedUser('acc_a');
    $b = seedUser('acc_b');
    grid($a, [2 => [60, 'full_gym']]);
    grid($b, [2 => [60, 'bodyweight']]);
    $pairId = pairUp($a, $b);

    $eff = BuddySchedule::effective($a, $pairId);
    return $eff[2]['access'] === 'bodyweight'
        ?: 'shared access is ' . var_export($eff[2]['access'], true);
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

t('the prompt does not claim the sessions match', function () {
    /*
     * §10.6 is unbuilt, so generation cannot coordinate the inside of a session. An
     * instruction to match core blocks was removed for exactly this reason and must not creep
     * back through the shared-day block.
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

    foreach (['identical between them', 'same exercises, same sets',
              'same sets, same reps'] as $claim) {
        if (str_contains($all, $claim)) {
            return "the prompt claims synced sessions: \"{$claim}\"";
        }
    }
    return true;
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
