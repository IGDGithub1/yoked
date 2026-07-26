<?php
declare(strict_types=1);

/**
 * Friend tests (SPEC-coaching §10.1).
 *
 * The social graph is small, so most of this file is about the SEARCH, which is the only
 * surface where one user learns anything about another without being invited to.
 *
 * The rule being asserted: username and display name match on a prefix because those are
 * self-chosen handles, and email matches only in full because a partial match answers "is
 * this address registered here?" for any address somebody cares to try. An invite-only app's
 * membership list is not public information.
 *
 *   php bin/test-friends.php
 *   php bin/test-friends.php --keep     leave the fixtures behind
 */

require __DIR__ . '/../src/bootstrap_cli.php';
require YK_SRC . '/lib/Response.php';
require YK_SRC . '/lib/RateLimit.php';
require YK_SRC . '/lib/Notify.php';
require YK_SRC . '/lib/Friends.php';
require YK_SRC . '/lib/Visibility.php';

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

DB::run("DELETE FROM users WHERE username LIKE 'fr_%'");
DB::run("DELETE FROM rate_limits WHERE bucket LIKE 'friendsearch:%'");

/** A user. Username, display name and email are all distinct so a match names its field. */
function seedUser(string $handle, string $display, string $email): int
{
    return (int) DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
         VALUES (?, ?, ?, "x", "active")',
        ['fr_' . $handle, $display, $email]
    );
}

/** Clear the search bucket so a test is not throttled by the one before it. */
function unthrottle(int $userId): void
{
    DB::run('DELETE FROM rate_limits WHERE bucket = ?', ['friendsearch:' . $userId]);
}

echo "Friend tests\n\n";

$me      = seedUser('me', 'Me Myself', 'me@example.test');
$ian     = seedUser('ianf', 'Ian Fleming', 'ipf@igdsolutions.test');
$iandaly = seedUser('iandaly', 'Ian Daly', 'iand@example.test');
$diana   = seedUser('diana', 'Diana Prince', 'dp@example.test');

// ---------------------------------------------------------------------------
echo "1. search finds people by handle and name\n";

t('a username prefix matches', function () use ($me) {
    unthrottle($me);
    $r = Friends::search($me, 'fr_ian');
    $names = array_column($r['results'], 'username');
    return (in_array('fr_ianf', $names, true) && in_array('fr_iandaly', $names, true))
        ?: 'got ' . implode(',', $names);
});

t('a display-name prefix matches', function () use ($me) {
    unthrottle($me);
    $r = Friends::search($me, 'Ian');
    return count($r['results']) >= 2 ?: count($r['results']) . ' results for "Ian"';
});

t('a display-name prefix matches case-insensitively', function () use ($me) {
    unthrottle($me);
    $r = Friends::search($me, 'diana');
    // The column collates utf8mb4_unicode_ci, so "diana" finds "Diana". Asserted because a
    // future migration to a _bin collation would silently break every lowercase search.
    return count($r['results']) === 1 ?: count($r['results']) . ' results for "diana"';
});

t('an exact username sorts first', function () use ($me) {
    unthrottle($me);
    $r = Friends::search($me, 'fr_ianf');
    // Someone who typed a whole handle meant that person, not whoever sorts first
    // alphabetically. "Ian Daly" would win on display_name.
    return ($r['results'][0]['username'] ?? '') === 'fr_ianf'
        ?: 'first result was ' . ($r['results'][0]['username'] ?? 'nothing');
});

t('you never find yourself', function () use ($me) {
    unthrottle($me);
    $r = Friends::search($me, 'Me My');
    return $r['results'] === [] ?: 'the searcher appeared in their own results';
});

// ---------------------------------------------------------------------------
echo "\n2. email is the exception, and matches only in full\n";

t('a full email finds the person', function () use ($me) {
    unthrottle($me);
    $r = Friends::search($me, 'ipf@igdsolutions.test');
    return (count($r['results']) === 1 && $r['results'][0]['username'] === 'fr_ianf')
        ?: 'a full email did not find them';
});

t('a partial email finds nobody', function () use ($me) {
    /*
     * The load-bearing privacy assertion. A partial match turns the search into an oracle
     * for "does this address have an account here", and lets someone read out the membership
     * list a prefix at a time.
     */
    unthrottle($me);
    foreach (['ipf@', 'ipf@igd', '@igdsolutions.test', 'igdsolutions'] as $frag) {
        unthrottle($me);
        $r = Friends::search($me, $frag);
        if ($r['results'] !== []) {
            return "\"{$frag}\" matched " . count($r['results']) . ' user(s)';
        }
    }
    return true;
});

t('a domain fragment does not enumerate everyone on it', function () use ($me) {
    unthrottle($me);
    $r = Friends::search($me, 'example.test');
    return $r['results'] === []
        ?: 'a domain matched ' . count($r['results']) . ' users';
});

t('the email is never echoed back, even when it matched', function () use ($me) {
    unthrottle($me);
    $r = Friends::search($me, 'ipf@igdsolutions.test');
    $json = json_encode($r['results']);
    // Confirming one address is one thing; handing it back in the payload makes it readable.
    return !str_contains((string) $json, 'igdsolutions')
        ?: 'the result payload contains the email';
});

// ---------------------------------------------------------------------------
echo "\n3. search is not a directory\n";

t('a query shorter than three characters returns nothing', function () use ($me) {
    unthrottle($me);
    foreach (['', 'a', 'ia', '  '] as $q) {
        $r = Friends::search($me, $q);
        if ($r['results'] !== []) {
            return 'a ' . mb_strlen(trim($q)) . '-character query returned results';
        }
    }
    return true;
});

t('results are capped', function () use ($me) {
    // Twelve users sharing a prefix, against a cap of eight.
    for ($i = 0; $i < 12; $i++) {
        seedUser("bulk{$i}", "Bulk Person {$i}", "bulk{$i}@example.test");
    }
    unthrottle($me);
    $r = Friends::search($me, 'Bulk');
    return count($r['results']) <= 8 ?: count($r['results']) . ' results, cap is 8';
});

t('a wildcard is not a wildcard', function () use ($me) {
    // Unescaped, "%" would match every user in one query.
    unthrottle($me);
    foreach (['%%%', '___', '%_%'] as $q) {
        unthrottle($me);
        $r = Friends::search($me, $q);
        if ($r['results'] !== []) {
            return "\"{$q}\" behaved as a wildcard and returned " . count($r['results']);
        }
    }
    return true;
});

t('searching is rate limited', function () {
    $u = seedUser('flood', 'Flood Er', 'flood@example.test');
    $refused = 0;
    for ($i = 0; $i < 40; $i++) {
        $r = Friends::search($u, 'Bulk');
        if (!$r['ok']) {
            $refused++;
        }
    }
    return $refused > 0 ?: 'the search rate limit never engaged over 40 queries';
});

t('a suspended account is not findable', function () use ($me) {
    $gone = seedUser('suspended', 'Suspended Sam', 'sus@example.test');
    DB::run('UPDATE users SET status = "suspended" WHERE id = ?', [$gone]);
    unthrottle($me);
    $r = Friends::search($me, 'Suspended');
    return $r['results'] === [] ?: 'a suspended account is searchable';
});

// ---------------------------------------------------------------------------
echo "\n4. asking, accepting, declining\n";

t('a request lands as pending in both directions', function () use ($me, $ian) {
    $r = Friends::request($me, $ian);
    if (!$r['ok'] || $r['status'] !== 'pending_out') {
        return 'request returned ' . json_encode($r);
    }
    // Direction matters: the two sides need different buttons, and the row only stores who
    // asked.
    if (Friends::relationship($me, $ian) !== 'pending_out') {
        return 'the sender does not see pending_out';
    }
    return Friends::relationship($ian, $me) === 'pending_in'
        ?: 'the recipient does not see pending_in';
});

t('asking twice is not an error', function () use ($me, $ian) {
    $r = Friends::request($me, $ian);
    return ($r['ok'] && $r['status'] === 'pending_out') ?: json_encode($r);
});

t('only one row exists for the pair', function () use ($me, $ian) {
    $n = (int) DB::one(
        'SELECT COUNT(*) AS n FROM friendships WHERE user_lo = ? AND user_hi = ?',
        [min($me, $ian), max($me, $ian)]
    )['n'];
    return $n === 1 ?: "{$n} rows for one pair";
});

t('the requester cannot accept their own request', function () use ($me, $ian) {
    // Without this check, asking and then accepting makes you anyone's friend.
    $r = Friends::respond($me, $ian, true);
    if ($r['ok'] !== false) {
        return 'the sender accepted their own request';
    }
    return Friends::areFriends($me, $ian) === false ?: 'they are now friends';
});

t('the recipient can accept', function () use ($me, $ian) {
    $r = Friends::respond($ian, $me, true);
    if (!$r['ok'] || $r['status'] !== 'friends') {
        return json_encode($r);
    }
    return (Friends::areFriends($me, $ian) && Friends::areFriends($ian, $me))
        ?: 'the friendship is not symmetric';
});

t('accepting notifies the person who asked', function () use ($me, $ian) {
    $n = DB::one(
        'SELECT type FROM notifications WHERE user_id = ? AND type = "friend_accepted"
         ORDER BY id DESC LIMIT 1',
        [$me]
    );
    return $n !== null ?: 'no acceptance notification';
});

t('a mutual reach-out becomes a friendship, not two requests', function () {
    /*
     * Both people tap "add" before either has answered. The second request must be read as
     * acceptance, or they sit in a stalemate where each is waiting on the other.
     */
    $a = seedUser('mutual_a', 'Mutual A', 'ma@example.test');
    $b = seedUser('mutual_b', 'Mutual B', 'mb@example.test');
    Friends::request($a, $b);
    $r = Friends::request($b, $a);
    if ($r['status'] !== 'friends') {
        return 'the second request returned ' . (string) $r['status'];
    }
    return Friends::areFriends($a, $b) ?: 'they are not friends';
});

t('declining removes the row so either side can ask again', function () {
    // A remembered decline would be a soft block nobody asked for.
    $a = seedUser('dec_a', 'Dec A', 'da@example.test');
    $b = seedUser('dec_b', 'Dec B', 'db@example.test');
    Friends::request($a, $b);
    Friends::respond($b, $a, false);
    if (Friends::relationship($a, $b) !== 'none') {
        return 'the pair is not back to none';
    }
    $again = Friends::request($a, $b);
    return $again['status'] === 'pending_out' ?: 'they cannot ask again';
});

t('you cannot add yourself', function () use ($me) {
    return Friends::request($me, $me)['ok'] === false ?: 'self-friending works';
});

t('you cannot add a user that does not exist', function () use ($me) {
    return Friends::request($me, 999999999)['ok'] === false ?: 'added a ghost';
});

// ---------------------------------------------------------------------------
echo "\n5. blocking\n";

t('blocking hides the pair from search', function () {
    $a = seedUser('blk_a', 'Blocker A', 'ba@example.test');
    $b = seedUser('blk_b', 'Blocked B', 'bb@example.test');
    Friends::block($a, $b);
    unthrottle($a);
    $r = Friends::search($a, 'Blocked B');
    return $r['results'] === [] ?: 'a blocked user still appears in search';
});

t('a blocked person cannot request, and is not told why', function () {
    /*
     * The message has to be identical to "no such person". Saying "you are blocked" confirms
     * the account exists and tells them what happened, which is the opposite of the point.
     */
    $a = seedUser('blk2_a', 'Blocker2 A', 'b2a@example.test');
    $b = seedUser('blk2_b', 'Blocked2 B', 'b2b@example.test');
    Friends::block($a, $b);
    $r = Friends::request($b, $a);
    if ($r['ok'] !== false) {
        return 'a blocked user sent a request';
    }
    return str_contains((string) $r['error'], 'No such person')
        ?: 'the refusal reveals the block: ' . (string) $r['error'];
});

t('only the blocker sees the block in their list', function () {
    $a = seedUser('blk3_a', 'Blocker3 A', 'b3a@example.test');
    $b = seedUser('blk3_b', 'Blocked3 B', 'b3b@example.test');
    Friends::block($a, $b);
    $mine   = Friends::forUser($a);
    $theirs = Friends::forUser($b);
    if (count($mine['blocked']) !== 1) {
        return 'the blocker cannot see who they blocked';
    }
    // The blocked party must see nothing at all, in any list.
    foreach (['friends', 'incoming', 'outgoing', 'blocked'] as $k) {
        if ($theirs[$k] !== []) {
            return "the blocked user sees the pair under {$k}";
        }
    }
    return true;
});

t('the blocked party cannot unblock themselves', function () {
    $a = seedUser('blk4_a', 'Blocker4 A', 'b4a@example.test');
    $b = seedUser('blk4_b', 'Blocked4 B', 'b4b@example.test');
    Friends::block($a, $b);
    $r = Friends::unblock($b, $a);
    if ($r['ok'] !== false) {
        return 'the blocked user lifted their own block';
    }
    return Friends::relationship($a, $b) === 'blocked' ?: 'the block was lifted anyway';
});

t('the blocker can unblock', function () {
    $a = seedUser('blk5_a', 'Blocker5 A', 'b5a@example.test');
    $b = seedUser('blk5_b', 'Blocked5 B', 'b5b@example.test');
    Friends::block($a, $b);
    $r = Friends::unblock($a, $b);
    return ($r['ok'] && Friends::relationship($a, $b) === 'none')
        ?: 'unblocking left the pair at ' . Friends::relationship($a, $b);
});

// ---------------------------------------------------------------------------
echo "\n6. unfriending takes the buddy pair with it\n";

t('unfriending ends an active buddy pair', function () {
    /*
     * §10.1 makes friendship the prerequisite for pairing, so a pair outliving the friendship
     * would keep two people sharing training data with someone they just disconnected from.
     * §10.5 makes this safe: unsynced days generate normally as solo sessions.
     */
    $a = seedUser('unf_a', 'Unfriend A', 'ua@example.test');
    $b = seedUser('unf_b', 'Unfriend B', 'ub@example.test');
    Friends::request($a, $b);
    Friends::respond($b, $a, true);
    DB::run(
        'INSERT INTO buddy_pairs (user_lo, user_hi, status, requested_by)
         VALUES (?, ?, "active", ?)',
        [min($a, $b), max($a, $b), $a]
    );
    if (!Visibility::areBuddies($a, $b)) {
        return 'the fixture pair is not active';
    }

    Friends::remove($a, $b);

    if (Friends::areFriends($a, $b)) {
        return 'still friends after removal';
    }
    // The real assertion: visibility is revoked, not just the friendship row.
    return Visibility::areBuddies($a, $b) === false
        ?: 'the buddy pair survived the unfriending';
});

t('blocking ends an active buddy pair too', function () {
    $a = seedUser('blkbud_a', 'BlkBud A', 'bba@example.test');
    $b = seedUser('blkbud_b', 'BlkBud B', 'bbb@example.test');
    Friends::request($a, $b);
    Friends::respond($b, $a, true);
    DB::run(
        'INSERT INTO buddy_pairs (user_lo, user_hi, status, requested_by)
         VALUES (?, ?, "active", ?)',
        [min($a, $b), max($a, $b), $a]
    );
    Friends::block($a, $b);
    return Visibility::areBuddies($a, $b) === false
        ?: 'blocking left the buddy pair active';
});

// ---------------------------------------------------------------------------
echo "\n7. the list, and what a request reveals\n";

t('an incoming request carries context, an outgoing one does not', function () {
    /*
     * A little context helps the person deciding: when they joined, and whether anyone you
     * both know vouches for them. Deliberately NOT on outgoing requests or search results —
     * a stranger should learn something by being ASKED, not by being looked up.
     */
    $a = seedUser('ctx_a', 'Ctx A', 'ca@example.test');
    $b = seedUser('ctx_b', 'Ctx B', 'cb@example.test');
    Friends::request($a, $b);

    $recipient = Friends::forUser($b);
    $sender    = Friends::forUser($a);

    $in = $recipient['incoming'][0] ?? null;
    if ($in === null) {
        return 'no incoming request';
    }
    if (!isset($in['joined'], $in['mutuals'])) {
        return 'the incoming request carries no context';
    }
    $out = $sender['outgoing'][0] ?? null;
    return !isset($out['joined'])
        ?: 'an outgoing request leaks context about the recipient';
});

t('mutual friends are counted, never named', function () {
    // "One mutual friend" is enough to decide. Listing them tells a stranger who you know
    // before you have agreed to anything.
    $shared = seedUser('mut_shared', 'Shared Friend', 'sf@example.test');
    $a      = seedUser('mut_a', 'Mut A', 'mua@example.test');
    $b      = seedUser('mut_b', 'Mut B', 'mub@example.test');

    foreach ([$a, $b] as $u) {
        Friends::request($u, $shared);
        Friends::respond($shared, $u, true);
    }
    Friends::request($a, $b);

    $in = Friends::forUser($b)['incoming'][0] ?? null;
    if ($in === null) {
        return 'no incoming request';
    }
    if (($in['mutuals'] ?? 0) !== 1) {
        return 'mutual count is ' . var_export($in['mutuals'] ?? null, true) . ', expected 1';
    }
    return !str_contains(json_encode($in) ?: '', 'Shared Friend')
        ?: 'the mutual friend is named in the payload';
});

t('the pending badge counts only what is waiting on you', function () {
    $a = seedUser('badge_a', 'Badge A', 'bga@example.test');
    $b = seedUser('badge_b', 'Badge B', 'bgb@example.test');
    $c = seedUser('badge_c', 'Badge C', 'bgc@example.test');

    Friends::request($b, $a);   // waiting on A
    Friends::request($a, $c);   // waiting on C, not A

    if (Friends::pendingCount($a) !== 1) {
        return 'A has a badge of ' . Friends::pendingCount($a) . ', expected 1';
    }
    return Friends::pendingCount($c) === 1
        ?: 'C has a badge of ' . Friends::pendingCount($c);
});

t('search reports the relationship so the UI cannot offer a bad button', function () use ($me, $ian) {
    unthrottle($me);
    $r = Friends::search($me, 'fr_ianf');
    $rel = $r['results'][0]['relationship'] ?? null;
    // These two were made friends in section 4.
    return $rel === 'friends' ?: 'relationship reported as ' . var_export($rel, true);
});

// ---------------------------------------------------------------------------

if (!$keep) {
    DB::run("DELETE FROM users WHERE username LIKE 'fr_%'");
    DB::run("DELETE FROM rate_limits WHERE bucket LIKE 'friendsearch:%'");
    echo "\nfixtures removed\n";
} else {
    echo "\nfixtures kept\n";
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
