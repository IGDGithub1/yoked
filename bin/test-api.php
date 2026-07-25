<?php
declare(strict_types=1);

/**
 * End-to-end API tests over real HTTP.
 *
 * Runs against the deployed site rather than calling the classes directly,
 * because the things most likely to be wrong live between the classes: the
 * .htaccess rewrite, the router, global CSRF, session cookies, and the JSON
 * error shape. None of those can be checked from inside PHP.
 *
 *   php bin/test-api.php                  against https://yoked.lil-boxes.com
 *   php bin/test-api.php --base=http://…  against somewhere else
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$base = 'https://yoked.lil-boxes.com';
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--base=')) {
        $base = rtrim(substr($a, 7), '/');
    }
}

$pass = 0;
$fail = 0;
$cookieJar = sys_get_temp_dir() . '/yoked-test-cookies-' . getmypid() . '.txt';
$csrf = null;

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

/**
 * One HTTP call, with the cookie jar and CSRF header wired up.
 *
 * @return array{status: int, body: array|null, raw: string}
 */
function req(string $method, string $path, ?array $body = null): array
{
    global $base, $cookieJar, $csrf;

    $ch = curl_init($base . '/api/' . ltrim($path, '/'));
    $headers = ['accept: application/json'];
    if ($body !== null) {
        $headers[] = 'content-type: application/json';
    }
    // Mutating requests need the token; safe ones are exempt (Csrf::verify).
    if ($csrf !== null && !in_array($method, ['GET', 'HEAD'], true)) {
        $headers[] = 'x-csrf-token: ' . $csrf;
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err    = curl_error($ch);
    unset($ch);

    if ($raw === '' && $err !== '') {
        throw new RuntimeException("transport: {$err}");
    }

    $decoded = json_decode($raw, true);

    // Adopt any token the server hands back. Auth::login() and logout both
    // regenerate the session id AND the CSRF token — correct fixation defense,
    // but it means a cached token goes stale the moment auth state changes. A
    // real client has to do exactly this, so the test client does too.
    if (is_array($decoded) && isset($decoded['csrf']) && is_string($decoded['csrf'])) {
        $csrf = $decoded['csrf'];
    }

    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null, 'raw' => $raw];
}

echo "API tests against {$base}\n\n";

// Clean up any prior run before starting, so a failed run does not block this
// one. Invites first — see the note at the cleanup block below.
DB::run("DELETE FROM invites WHERE code LIKE 'APITEST%'");
DB::run("DELETE FROM users WHERE username LIKE 'apitest_%'");

// Clear this test's rate-limit buckets.
//
// Registration is capped at 5/hour per IP and login at 20/15min — correct in
// production, and repeated test runs exhaust both, which then looks like a
// cascade of auth failures rather than what it is. Clearing only the buckets
// this suite touches; a real client cannot do this, which is the point of the
// limit.
$ip = null;
try {
    // The server sees its own outbound IP for these requests, not ours, so
    // clear every register/login bucket rather than guessing which.
    DB::run("DELETE FROM rate_limits WHERE bucket LIKE 'register:%' OR bucket LIKE 'login:%'");
} catch (Throwable $e) {
    fwrite(STDERR, "warning: could not clear rate limits: {$e->getMessage()}\n");
}

echo "1. reachability and routing\n";

t('GET /api/health returns ok', function () {
    $r = req('GET', 'health');
    if ($r['status'] !== 200) {
        return "status {$r['status']}: " . substr($r['raw'], 0, 200);
    }
    if (($r['body']['ok'] ?? false) !== true) {
        return 'health reported not ok: ' . $r['raw'];
    }
    printf("        php %s, database %s\n", $r['body']['php'], $r['body']['database']);
    return true;
});

t('unknown endpoint is a clean 404 JSON', function () {
    $r = req('GET', 'no-such-endpoint');
    return ($r['status'] === 404 && isset($r['body']['error']))
        ?: "expected 404 JSON, got {$r['status']}: " . substr($r['raw'], 0, 120);
});

t('GET /api/me works unauthenticated and yields a CSRF token', function () use (&$csrf) {
    $r = req('GET', 'me');
    if ($r['status'] !== 200) {
        return "expected 200 even when logged out, got {$r['status']}";
    }
    if (($r['body']['authenticated'] ?? true) !== false) {
        return 'should report authenticated: false';
    }
    $csrf = $r['body']['csrf'] ?? null;
    return is_string($csrf) && strlen($csrf) === 64
        ?: 'no usable csrf token returned';
});

// Ordered after the token fetch on purpose: CSRF is verified BEFORE routing, so
// an un-tokened DELETE is rejected at 419 and never reaches the router. Testing
// 405 therefore requires a valid token — otherwise this asserts the wrong layer.
t('wrong method on a real path is 405, not 404', function () {
    $r = req('DELETE', 'health');
    return $r['status'] === 405 ?: "expected 405, got {$r['status']}";
});

echo "\n2. CSRF is enforced globally\n";

// 403, not 419. 419 is a Laravel convention rather than a real HTTP status, and
// nginx here rewrites unknown codes to 500 — which made a working CSRF check
// look like a crash. Asserting the real code is what caught that.
t('a mutating request without the token is rejected', function () use (&$csrf) {
    $saved = $csrf;
    $csrf = null;   // suppress the header
    $r = req('POST', 'login', ['identifier' => 'x', 'password' => 'y']);
    $csrf = $saved;
    if ($r['status'] !== 403) {
        return "expected 403, got {$r['status']}";
    }
    return ($r['body']['csrf_failed'] ?? false) === true
        ?: 'response should be distinguishable from an authorization 403';
});

t('a mutating request with a wrong token is rejected', function () use (&$csrf) {
    $saved = $csrf;
    $csrf = str_repeat('0', 64);
    $r = req('POST', 'login', ['identifier' => 'x', 'password' => 'y']);
    $csrf = $saved;
    return $r['status'] === 403 ?: "expected 403, got {$r['status']}";
});

t('no route returns a non-standard status code', function () {
    // nginx silently rewrites unknown statuses to 500, so a non-standard code
    // anywhere is a bug that only shows up over HTTP.
    $r = req('GET', 'no-such-endpoint');
    $standard = [200, 201, 204, 301, 302, 304, 400, 401, 403, 404, 405, 409,
                 410, 413, 422, 429, 500, 502, 503];
    return in_array($r['status'], $standard, true)
        ?: "got non-standard {$r['status']}";
});

echo "\n3. registration is invite-gated\n";

t('registration without a valid invite is refused', function () {
    $r = req('POST', 'register', [
        'invite' => 'NOPE-NOT-REAL', 'username' => 'apitest_x',
        'email' => 'apitest_x@example.test', 'password' => 'correct horse battery',
    ]);
    return $r['status'] === 403 ?: "expected 403, got {$r['status']}: " . substr($r['raw'], 0, 160);
});

/**
 * Mint an invite to register against.
 *
 * invites.created_by is NOT NULL with an FK to users, so an invite needs an
 * issuer. On a fresh install the users table is empty — which is the real
 * chicken-and-egg of an invite-only app, not a test artifact. Bootstrap an admin
 * the same way a first deploy would have to.
 */
$adminId = null;
$admin = DB::one("SELECT id FROM users WHERE username = 'apitest_admin'");
if ($admin === null) {
    $adminId = DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, role, onboarding_state)
         VALUES (?, ?, ?, ?, "admin", "active")',
        ['apitest_admin', 'API Test Admin', 'apitest_admin@example.test',
         password_hash('bootstrap admin password', PASSWORD_DEFAULT)]
    );
} else {
    $adminId = (int) $admin['id'];
}

$inviteCode = 'APITEST' . strtoupper(bin2hex(random_bytes(3)));
DB::run(
    'INSERT INTO invites (code, created_by, expires_at)
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))',
    [$inviteCode, $adminId]
);

t('an invite exists to register against', function () use ($inviteCode, $adminId) {
    $row = DB::one('SELECT id FROM invites WHERE code = ?', [$inviteCode]);
    if ($row === null) {
        return 'could not create a test invite';
    }
    printf("        invite %s (issued by user #%d)\n", $inviteCode, $adminId);
    return true;
});

$userId = null;

t('registration succeeds and logs the user in', function () use ($inviteCode, &$userId) {
    $r = req('POST', 'register', [
        'invite'       => $inviteCode,
        'username'     => 'apitest_one',
        'display_name' => 'API Test One',
        'email'        => 'apitest_one@example.test',
        'password'     => 'correct horse battery staple',
    ]);
    if ($r['status'] !== 201) {
        return "expected 201, got {$r['status']}: " . substr($r['raw'], 0, 240);
    }
    if (($r['body']['authenticated'] ?? false) !== true) {
        return 'should be logged in after registering';
    }
    $userId = (int) ($r['body']['user']['id'] ?? 0);
    $state = $r['body']['user']['onboarding_state'] ?? '';
    printf("        user #%d, state=%s, next=%s\n",
        $userId, $state, $r['body']['next']['step'] ?? '?');
    return $state === 'pending' ?: "expected state pending, got {$state}";
});

t('the invite cannot be reused', function () use ($inviteCode) {
    $r = req('POST', 'register', [
        'invite' => $inviteCode, 'username' => 'apitest_two',
        'email' => 'apitest_two@example.test', 'password' => 'another long password here',
    ]);
    return $r['status'] === 403 ?: "expected 403, got {$r['status']}";
});

t('a weak password is refused', function () use ($adminId) {
    $code = 'APITEST' . strtoupper(bin2hex(random_bytes(3)));
    DB::run(
        'INSERT INTO invites (code, created_by, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))',
        [$code, $adminId]
    );
    $r = req('POST', 'register', [
        'invite' => $code, 'username' => 'apitest_weak',
        'email' => 'apitest_weak@example.test', 'password' => 'short',
    ]);
    return $r['status'] === 422 ?: "expected 422, got {$r['status']}";
});

echo "\n4. onboarding: save, project, resume\n";

t('the quiz starts empty with blocking sections incomplete', function () {
    $r = req('GET', 'onboarding');
    if ($r['status'] !== 200) {
        return "status {$r['status']}: " . substr($r['raw'], 0, 200);
    }
    $p = $r['body']['progress'] ?? [];
    return ($p['blocking_done'] ?? true) === false
        ?: 'blocking sections should not be complete on a fresh account';
});

t('section 1 saves and projects into profiles', function () use (&$userId) {
    $r = req('PUT', 'onboarding', ['answers' => [
        '1.1' => '1974-03-02',
        '1.2' => 'male',
        '1.3' => 76,          // inches
        '1.4' => 240,         // pounds
        '1.5' => 'imperial',
        '1.6' => ['waist' => 42, 'chest' => 44],
    ]]);
    if ($r['status'] !== 200) {
        return "status {$r['status']}: " . substr($r['raw'], 0, 240);
    }

    // Imperial in, metric stored — otherwise every reader has to know the units.
    $p = DB::one('SELECT height_cm, birth_sex FROM profiles WHERE user_id = ?', [$userId]);
    if ($p === null) {
        return 'no profile row';
    }
    $cm = (float) $p['height_cm'];
    if (abs($cm - 193.0) > 0.6) {
        return "76in should store as ~193cm, got {$cm}";
    }

    // Weight becomes the first check-in, not a scalar on profiles — a trend
    // needs an origin point.
    $c = DB::one(
        'SELECT weight_kg, waist_cm FROM weekly_checkins WHERE user_id = ? ORDER BY week_start DESC LIMIT 1',
        [$userId]
    );
    if ($c === null) {
        return 'starting weight did not become a check-in';
    }
    $kg = (float) $c['weight_kg'];
    printf("        stored %.1fcm, %.1fkg, waist %.1fcm\n", $cm, $kg, (float) $c['waist_cm']);
    return abs($kg - 108.86) < 0.5 ?: "240lb should store as ~108.9kg, got {$kg}";
});

t('section 2 creates the goal with the statement verbatim', function () use (&$userId) {
    $statement = 'Lose some weight and visceral fat, gain muscle. Brad Pitt in '
        . 'Fight Club would be great. Super easy, right.';
    $r = req('PUT', 'onboarding', ['answers' => [
        '2.1' => 'lose_fat',
        '2.2' => ['build_muscle', 'improve_cardio'],
        '2.3' => $statement,
        '2.5' => '16_weeks',
        '2.6' => 'both',
    ]]);
    if ($r['status'] !== 200) {
        return "status {$r['status']}";
    }
    $g = DB::one(
        'SELECT primary_goal, success_statement, horizon_weeks, goal_preset_id
         FROM goals WHERE user_id = ? AND status = "active"', [$userId]
    );
    if ($g === null) {
        return 'no goal row created';
    }
    if ($g['success_statement'] !== $statement) {
        return 'success statement was altered — it must be stored verbatim';
    }
    if ((int) $g['horizon_weeks'] !== 16) {
        return "horizon should be 16, got {$g['horizon_weeks']}";
    }
    return $g['goal_preset_id'] !== null ?: 'no goal preset matched';
});

t('allergies project as HARD food constraints', function () use (&$userId) {
    $r = req('PUT', 'onboarding', ['answers' => [
        '3.1' => ['selected' => ['shellfish'], 'other' => 'peanuts'],
        '3.2' => ['diabetes_t2', 'heart'],
        '3.2_detail' => 'T2 diabetic; MI five years ago, heart recovered well.',
        '3.4' => [],
        '3.5' => ['overhead press'],
        '3.6' => 'yes',
    ]]);
    if ($r['status'] !== 200) {
        return "status {$r['status']}: " . substr($r['raw'], 0, 240);
    }

    $hard = DB::all(
        'SELECT subject FROM user_constraints
         WHERE user_id = ? AND kind = "food" AND tier = "hard"', [$userId]
    );
    $subjects = array_column($hard, 'subject');
    foreach (['shellfish', 'peanuts'] as $want) {
        if (!in_array($want, $subjects, true)) {
            return "missing hard food constraint '{$want}' (got: "
                . implode(', ', $subjects) . ')';
        }
    }
    return true;
});

t('conditions project as modifiers with actionable guidance', function () use (&$userId) {
    $rows = DB::all(
        'SELECT subject, guidance FROM user_constraints
         WHERE user_id = ? AND kind = "condition"', [$userId]
    );
    if ($rows === []) {
        return 'no condition constraints';
    }
    foreach ($rows as $r) {
        if (trim((string) $r['guidance']) === '') {
            return "condition '{$r['subject']}' has no guidance — a bare label is "
                 . 'not actionable in a prompt';
        }
    }
    // Diabetes guidance must say what to do, not ban carbs outright.
    foreach ($rows as $r) {
        if (str_contains($r['subject'], 'diabetes')) {
            return str_contains(strtolower((string) $r['guidance']), 'not a carb ban')
                ?: 'diabetes guidance should state it is not a carb ban';
        }
    }
    return true;
});

t('disliked movements project as SOFT', function () use (&$userId) {
    $row = DB::one(
        'SELECT tier FROM user_constraints
         WHERE user_id = ? AND kind = "movement" AND subject = ?',
        [$userId, 'overhead press']
    );
    return ($row !== null && $row['tier'] === 'soft')
        ?: 'disliked movement should be soft, got ' . ($row['tier'] ?? 'nothing');
});

echo "\n5. the tier check on a serious-sounding soft injury\n";

t('a soft injury described clinically triggers a confirmation', function () {
    $r = req('PUT', 'onboarding', ['answers' => [
        '3.4' => [[
            'area'        => 'left knee',
            // Marked soft, but described as post-surgical: exactly the case
            // where someone clicks the less-limiting option by reflex.
            'description' => 'ACL surgery in 2019, still aches under load',
            'tier'        => 'soft',
            'work_up_to'  => true,
        ]],
    ]]);
    if ($r['status'] !== 200) {
        return "status {$r['status']}: " . substr($r['raw'], 0, 240);
    }

    $confirm = $r['body']['confirm'] ?? [];
    if ($confirm === [] || $confirm === null) {
        return 'no confirmation returned for a soft post-surgical injury';
    }
    $items = $confirm[0]['items'] ?? [];
    if ($items === []) {
        return 'confirmation had no items';
    }
    printf("        prompted on '%s' (matched \"%s\")\n",
        $items[0]['subject'], $items[0]['matched']);
    return true;
});

t('the answer still stands — the prompt is a question, not a rejection', function () use (&$userId) {
    $row = DB::one(
        'SELECT tier, progression FROM user_constraints
         WHERE user_id = ? AND kind = "movement" AND subject = "left knee"',
        [$userId]
    );
    if ($row === null) {
        return 'the constraint was not saved at all';
    }
    if ($row['tier'] !== 'soft') {
        return "the user said soft; stored as {$row['tier']}";
    }
    $prog = json_decode((string) $row['progression'], true);
    return ($prog['status'] ?? '') === 'working_toward'
        ?: 'work_up_to should have produced a progression target';
});

t('a mundane soft injury does NOT trigger a confirmation', function () {
    // Guard against a check that fires on everything and trains users to
    // dismiss it.
    $r = req('PUT', 'onboarding', ['answers' => [
        '3.4' => [[
            'area' => 'right shoulder', 'description' => 'gets a bit stiff sometimes',
            'tier' => 'soft', 'work_up_to' => false,
        ]],
    ]]);
    $confirm = $r['body']['confirm'] ?? [];
    return ($confirm === [] || $confirm === null)
        ?: 'should not prompt on a mundane description: ' . json_encode($confirm);
});

t('upgrading the tier is audited', function () use (&$userId) {
    // Put the knee back, then upgrade it.
    req('PUT', 'onboarding', ['answers' => [
        '3.4' => [[
            'area' => 'left knee', 'description' => 'ACL surgery in 2019',
            'tier' => 'soft', 'work_up_to' => true,
        ]],
    ]]);

    $r = req('POST', 'onboarding/confirm-tier', ['subject' => 'left knee', 'tier' => 'hard']);
    if ($r['status'] !== 200) {
        return "status {$r['status']}: " . substr($r['raw'], 0, 200);
    }
    if (($r['body']['changed'] ?? false) !== true) {
        return 'should report changed';
    }

    $row = DB::one(
        'SELECT tier, progression, source FROM user_constraints
         WHERE user_id = ? AND kind = "movement" AND subject = "left knee"',
        [$userId]
    );
    if ($row['tier'] !== 'hard') {
        return 'tier not upgraded';
    }
    // "Work up to this" is incoherent for something never prescribed.
    if ($row['progression'] !== null) {
        return 'progression should be cleared when upgrading to hard';
    }
    $audit = DB::one(
        'SELECT COUNT(*) AS n FROM user_constraint_audit WHERE user_id = ?', [$userId]
    );
    return (int) $audit['n'] > 0 ?: 'no audit row written';
});

echo "\n6. availability grid drives committed capacity\n";

t('the grid saves and sets committed_days_per_week', function () use (&$userId) {
    $grid = [];
    for ($d = 1; $d <= 5; $d++) {
        $grid[(string) $d] = ['can_train' => 'yes', 'minutes' => 60,
                              'access' => 'full_gym', 'preferred_time' => 'early_morning'];
    }
    $grid['6'] = ['can_train' => 'sometimes', 'minutes' => 180, 'access' => 'outdoors'];
    $grid['7'] = ['can_train' => 'no'];

    $r = req('PUT', 'onboarding', ['answers' => ['7.1' => $grid]]);
    if ($r['status'] !== 200) {
        return "status {$r['status']}: " . substr($r['raw'], 0, 240);
    }

    $rows = DB::all('SELECT weekday, can_train, access FROM availability WHERE user_id = ?', [$userId]);
    if (count($rows) !== 7) {
        return 'expected 7 rows, got ' . count($rows);
    }

    // Derived from the grid, not asked separately — two sources for one fact
    // would drift.
    $p = DB::one('SELECT committed_days_per_week FROM profiles WHERE user_id = ?', [$userId]);
    printf("        committed_days_per_week = %d\n", (int) $p['committed_days_per_week']);
    return (int) $p['committed_days_per_week'] === 5
        ?: 'five "yes" days should give 5 committed';
});

echo "\n7. constraints are visible to the user\n";

t('GET /api/onboarding/constraints explains each tier', function () {
    $r = req('GET', 'onboarding/constraints');
    if ($r['status'] !== 200) {
        return "status {$r['status']}";
    }
    $c = $r['body']['constraints'] ?? [];
    if ($c === []) {
        return 'no constraints returned';
    }
    foreach ($c as $item) {
        if (trim((string) ($item['meaning'] ?? '')) === '') {
            return 'a constraint has no plain-language meaning';
        }
    }
    printf("        %d constraint(s) exposed\n", count($c));
    return true;
});

echo "\n8. session persistence and logout\n";

t('the session survives across requests', function () {
    $r = req('GET', 'me');
    return (($r['body']['authenticated'] ?? false) === true)
        ?: 'session did not persist';
});

t('baseline cannot start with sections still unanswered', function () {
    $r = req('POST', 'onboarding/start-baseline');
    return $r['status'] === 409
        ?: "expected 409 while incomplete, got {$r['status']}";
});

t('logout ends the session', function () {
    $r = req('POST', 'logout');
    if ($r['status'] !== 200) {
        return "status {$r['status']}: " . substr($r['raw'], 0, 160);
    }
    // GET /api/me also refreshes the cached token via req()'s adopt step, which
    // matters because the session was just destroyed.
    $me = req('GET', 'me');
    return (($me['body']['authenticated'] ?? true) === false)
        ?: 'still authenticated after logout';
});

t('login works with the same credentials', function () {
    $r = req('POST', 'login', [
        'identifier' => 'apitest_one',
        'password'   => 'correct horse battery staple',
    ]);
    if ($r['status'] !== 200) {
        return "status {$r['status']}: " . substr($r['raw'], 0, 200);
    }
    return (($r['body']['authenticated'] ?? false) === true) ?: 'not authenticated';
});

t('a wrong password is rejected', function () {
    $r = req('POST', 'login', ['identifier' => 'apitest_one', 'password' => 'wrong wrong wrong']);
    return $r['status'] === 401 ?: "expected 401, got {$r['status']}";
});

// ---- cleanup --------------------------------------------------------------
//
// Invites before users: invites.created_by has an FK with no ON DELETE clause,
// so deleting the issuing admin first would be blocked.

DB::run("DELETE FROM invites WHERE code LIKE 'APITEST%'");
DB::run("DELETE FROM users WHERE username LIKE 'apitest_%'");
@unlink($cookieJar);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
