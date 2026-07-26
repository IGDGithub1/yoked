<?php
declare(strict_types=1);

/**
 * Visibility tests (SPEC-coaching §10.4, SPEC-onboarding 9.5/9.6).
 *
 * `hide_photos` and `hide_measurements` were written by onboarding from the beginning and
 * read by nothing: the toggle said "keep private" and did nothing. This is the file that
 * makes them mean something, and most of what follows is an attempt to get at data that
 * should be refused.
 *
 * The rule being asserted, from §10.4: a buddy sees whether sessions were completed and how
 * shared work went, and does NOT see weight, measurements or photos, because "pairing up to
 * train is not consent to share body metrics".
 *
 *   php bin/test-visibility.php
 *   php bin/test-visibility.php --keep     leave the fixtures behind
 */

require __DIR__ . '/../src/bootstrap_cli.php';
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

DB::run("DELETE FROM users WHERE username LIKE 'vistest_%'");

/** A user, with both privacy flags settable. */
function seedUser(string $suffix, int $hidePhotos = 1, int $hideMeasurements = 1): int
{
    $userId = (int) DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
         VALUES (?, ?, ?, "x", "active")',
        ['vistest_' . $suffix, 'Vis ' . $suffix, "vistest_{$suffix}@example.test"]
    );
    DB::run(
        'INSERT INTO profiles (user_id, timezone, hide_photos, hide_measurements)
         VALUES (?, "UTC", ?, ?)',
        [$userId, $hidePhotos, $hideMeasurements]
    );
    return $userId;
}

function pair(int $a, int $b, string $status = 'active'): void
{
    DB::run(
        'INSERT INTO buddy_pairs (user_lo, user_hi, status, requested_by)
         VALUES (?, ?, ?, ?)',
        [min($a, $b), max($a, $b), $status, $a]
    );
}

echo "Visibility tests\n\n";

// ---------------------------------------------------------------------------
echo "1. a stranger sees nothing\n";

$me = seedUser('me', 0, 0);          // both flags OFF: maximally permissive
$stranger = seedUser('stranger');

t('a stranger cannot see the user at all', function () use ($stranger, $me) {
    return Visibility::canSeeUser($stranger, $me) === false ?: 'a stranger can see them';
});

t('a stranger cannot see training', function () use ($stranger, $me) {
    return Visibility::canSeeTraining($stranger, $me) === false ?: 'leaked training';
});

t('a stranger cannot see measurements, even with the flags off', function () use ($stranger, $me) {
    /*
     * The flags are OFF on this fixture, which is the permissive setting. It must still be
     * false: "not hidden" is not the same as "shared with anyone who asks". Sharing needs a
     * relationship first and a flag second, in that order.
     */
    return Visibility::canSeeMeasurements($stranger, $me) === false
        ?: 'a stranger saw measurements because the flag was off';
});

t('a stranger cannot see photos, even with the flags off', function () use ($stranger, $me) {
    return Visibility::canSeePhotos($stranger, $me) === false ?: 'leaked photos';
});

// ---------------------------------------------------------------------------
echo "\n2. you can always see yourself\n";

t('a user sees their own everything, flags notwithstanding', function () {
    /*
     * Both flags ON, which is the default. Turning on "keep private" and then being unable to
     * see your own weight would be absurd, and it is exactly what a naive flag check produces
     * when it forgets the self case.
     */
    $u = seedUser('self', 1, 1);
    foreach ([
        ['canSeeUser', 'user'],
        ['canSeeTraining', 'training'],
        ['canSeeMeasurements', 'measurements'],
        ['canSeePhotos', 'photos'],
    ] as [$method, $what]) {
        if (Visibility::$method($u, $u) !== true) {
            return "a user cannot see their own {$what}";
        }
    }
    return true;
});

t('areBuddies is false for a user and themselves', function () {
    // Not a relationship. Left true, canSeeUser would still be right by luck and any future
    // caller reasoning about pairs would be wrong.
    $u = seedUser('selfpair');
    return Visibility::areBuddies($u, $u) === false ?: 'a user is their own buddy';
});

// ---------------------------------------------------------------------------
echo "\n3. a buddy sees training and nothing about the body\n";

$a = seedUser('buddy_a');
$b = seedUser('buddy_b');            // both flags default to hidden
pair($a, $b);

t('the pair is recognised in both directions', function () use ($a, $b) {
    // buddy_pairs stores (lo, hi) with a CHECK, so a lookup that only tried one order would
    // work for exactly half of all pairs.
    return (Visibility::areBuddies($a, $b) && Visibility::areBuddies($b, $a))
        ?: 'the pair is only visible one way';
});

t('a buddy sees training, which is the point of pairing', function () use ($a, $b) {
    // §10.4: "your buddy trained Monday, you didn't" is the most effective nudge in the app.
    return Visibility::canSeeTraining($a, $b) === true ?: 'a buddy cannot see training';
});

t('a buddy does NOT see measurements by default', function () use ($a, $b) {
    // §10.4: "pairing up to train is not consent to share body metrics."
    return Visibility::canSeeMeasurements($a, $b) === false
        ?: 'pairing leaked body measurements';
});

t('a buddy does NOT see photos by default', function () use ($a, $b) {
    return Visibility::canSeePhotos($a, $b) === false ?: 'pairing leaked progress photos';
});

t('a buddy sees measurements once the user shares them', function () {
    // The flag has to work in both directions or it is not a control, just a lock.
    $x = seedUser('shares_m', 1, 0);
    $y = seedUser('sees_m');
    pair($x, $y);
    return Visibility::canSeeMeasurements($y, $x) === true
        ?: 'sharing measurements had no effect';
});

t('the two flags are independent', function () {
    // Sharing your weight is not sharing your photos. One switch governing both would be a
    // surprise in the worst direction.
    $x = seedUser('shares_p', 0, 1);
    $y = seedUser('sees_p');
    pair($x, $y);
    if (Visibility::canSeePhotos($y, $x) !== true) {
        return 'photos stayed hidden after being shared';
    }
    return Visibility::canSeeMeasurements($y, $x) === false
        ?: 'sharing photos also shared measurements';
});

t('visibility is per-user, not mutual', function () {
    /*
     * A shares, B does not. A common shortcut is to treat the pair as symmetric, which would
     * expose B's data the moment A opted in — a decision B never made.
     */
    $x = seedUser('open', 0, 0);
    $y = seedUser('closed', 1, 1);
    pair($x, $y);
    if (Visibility::canSeeMeasurements($y, $x) !== true) {
        return 'the sharing user is not visible to their buddy';
    }
    return Visibility::canSeeMeasurements($x, $y) === false
        ?: 'one user sharing exposed the other';
});

// ---------------------------------------------------------------------------
echo "\n4. only an ACTIVE pair counts\n";

t('a pending invitation grants nothing', function () {
    // An invitation nobody has accepted is not a relationship. This is the whole reason the
    // status column exists, and the easiest thing to forget in a WHERE clause.
    $x = seedUser('pend_a', 0, 0);
    $y = seedUser('pend_b');
    pair($x, $y, 'pending');
    if (Visibility::areBuddies($y, $x) !== false) {
        return 'a pending pair reads as buddies';
    }
    return Visibility::canSeeMeasurements($y, $x) === false
        ?: 'a pending invitation leaked measurements';
});

t('an ended pair grants nothing', function () {
    // Unpairing has to actually revoke. A relationship that ended still has its row.
    $x = seedUser('end_a', 0, 0);
    $y = seedUser('end_b');
    pair($x, $y, 'ended');
    if (Visibility::canSeeTraining($y, $x) !== false) {
        return 'an ended pair can still see training';
    }
    return Visibility::canSeeMeasurements($y, $x) === false
        ?: 'an ended pair can still see measurements';
});

// ---------------------------------------------------------------------------
echo "\n5. it fails closed\n";

t('a user with no profile row is treated as private', function () {
    /*
     * Fail closed, not open. A missing profile means the flag cannot be read, and the safe
     * answer to "may I show this person's weight" when you do not know is no.
     */
    $x = (int) DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
         VALUES (?, ?, ?, "x", "active")',
        ['vistest_noprofile', 'No Profile', 'vistest_noprofile@example.test']
    );
    $y = seedUser('sees_noprofile');
    pair($x, $y);
    if (Visibility::canSeeMeasurements($y, $x) !== false) {
        return 'a profileless user leaked measurements';
    }
    return Visibility::canSeePhotos($y, $x) === false ?: 'a profileless user leaked photos';
});

t('a nonexistent user is not visible', function () {
    $y = seedUser('sees_ghost');
    return Visibility::canSeeUser($y, 999999999) === false ?: 'a ghost is visible';
});

t('the null guard is explicit, not left to a cast', function () {
    /*
     * The columns are NOT NULL with a default, so a null is unreachable through the app
     * today. It is guarded anyway, and asserted here, because "unreachable" is a property of
     * the current schema rather than of this code: `(bool) null` is false, which would read
     * as NOT hidden and share the data. A migration that ever made the column nullable would
     * otherwise turn every unanswered profile into a public one.
     *
     * Checked by reading the guard rather than by forcing a null: doing that needs an ALTER
     * TABLE against the live database, and a dropped connection mid-test would leave the
     * column nullable for everyone.
     */
    $src = (string) file_get_contents(YK_SRC . '/lib/Visibility.php');
    return preg_match('/\$row === null \|\| \$row\[.v.\] === null/', $src) === 1
        ?: 'flag() does not guard a null explicitly before casting';
});

t('the flag reader will not accept an arbitrary column name', function () {
    /*
     * flag() interpolates its column into SQL, which is safe only because the two callers
     * pass literals. The allowlist keeps that true if a third caller ever passes something
     * from a request. Asserted through reflection because there is no way to reach it
     * otherwise, which is the point.
     */
    $ref = new ReflectionMethod(Visibility::class, 'flag');
    $ref->setAccessible(true);
    $u = seedUser('allowlist', 0, 0);
    // A column that exists and is not a privacy flag must still come back "hidden".
    return $ref->invoke(null, $u, 'coaching_paused') === true
        ?: 'flag() read a column outside its allowlist';
});

// ---------------------------------------------------------------------------

if (!$keep) {
    DB::run("DELETE FROM users WHERE username LIKE 'vistest_%'");
    echo "\nfixtures removed\n";
} else {
    echo "\nfixtures kept\n";
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
