<?php
declare(strict_types=1);

/**
 * End-to-end tests for logging: food, training, check-ins.
 *
 * Over real HTTP, like test-api.php, because the interesting failures live
 * between the classes — routing, CSRF, session, and the JSON shapes the SPA
 * will actually consume.
 *
 * Seeds its own user and a hand-built plan version rather than generating one:
 * a real generation costs minutes and money, and none of these assertions are
 * about the model. The plan just has to exist so "ate as planned" and adherence
 * have something to measure against.
 *
 *   php bin/test-logging.php
 *   php bin/test-logging.php --base=http://…
 *   php bin/test-logging.php --keep      leave the test user in place
 */

require __DIR__ . '/../src/bootstrap_cli.php';
require YK_SRC . '/lib/Response.php';
require YK_SRC . '/lib/RateLimit.php';
require YK_SRC . '/lib/Goals.php';

$base = 'https://yoked.lil-boxes.com';
$keep = false;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--base=')) {
        $base = rtrim(substr($a, 7), '/');
    } elseif ($a === '--keep') {
        $keep = true;
    }
}

$pass = 0;
$fail = 0;
$cookieJar = sys_get_temp_dir() . '/yoked-log-cookies-' . getmypid() . '.txt';
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

/** @return array{status:int, body:?array, raw:string} */
function req(string $method, string $path, ?array $body = null): array
{
    global $base, $cookieJar, $csrf;

    $ch = curl_init($base . '/api/' . ltrim($path, '/'));
    $headers = ['accept: application/json'];
    if ($body !== null) {
        $headers[] = 'content-type: application/json';
    }
    if ($csrf !== null && !in_array($method, ['GET', 'HEAD'], true)) {
        $headers[] = 'x-csrf-token: ' . $csrf;
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
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
    $d = json_decode($raw, true);
    if (is_array($d) && isset($d['csrf']) && is_string($d['csrf'])) {
        $csrf = $d['csrf'];
    }
    return ['status' => $status, 'body' => is_array($d) ? $d : null, 'raw' => $raw];
}

/** The slot's entry in a day payload. */
function slot(array $day, string $name): ?array
{
    foreach ($day['meals'] ?? [] as $m) {
        if (($m['slot'] ?? '') === $name) {
            return $m;
        }
    }
    return null;
}

/** One logged entry, by id, from anywhere in a day payload. */
function findEntry(array $day, int $id): ?array
{
    foreach ($day['meals'] ?? [] as $m) {
        foreach ($m['entries'] ?? [] as $e) {
            if ((int) ($e['id'] ?? 0) === $id) {
                return $e;
            }
        }
    }
    return null;
}

echo "Logging tests against {$base}\n\n";

// ---- clean slate -----------------------------------------------------------

DB::run("DELETE FROM invites WHERE code LIKE 'LOGTEST%'");
DB::run("DELETE FROM users WHERE username LIKE 'logtest_%'");
DB::run("DELETE FROM rate_limits WHERE bucket LIKE 'register:%' OR bucket LIKE 'login:%'");

// ---- seed a user with a plan ------------------------------------------------

echo "1. seeding a user with a live plan\n";

$username = 'logtest_' . substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'a-long-enough-passphrase';
$monday   = date('Y-m-d', strtotime('monday this week'));
$today    = $monday;   // deterministic: always the Monday of the plan week

$seed = DB::tx(function () use ($username, $password, $monday): array {
    $userId = DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
         VALUES (?, ?, ?, ?, "active")',
        [$username, 'Log Test', $username . '@example.test',
         password_hash($password, PASSWORD_DEFAULT)]
    );
    DB::run('INSERT INTO profiles (user_id) VALUES (?)', [$userId]);

    // An active goal, pointed at a seeded preset. Constraints live on
    // goal_presets, not on goals — the goal only references one.
    //
    // The verdict this suite asserts on actually comes from the constraints
    // frozen onto prescribed_days below, which is deliberate: a later change to
    // the user's goal must not retroactively re-judge logged history.
    $preset = DB::one("SELECT id FROM goal_presets WHERE slug = 'recomp'");
    DB::run(
        'INSERT INTO goals (user_id, primary_goal, goal_preset_id, success_statement, status)
         VALUES (?, ?, ?, ?, "active")',
        [$userId, 'recomp', $preset === null ? null : (int) $preset['id'], 'Test goal.']
    );

    $planId = DB::insert(
        'INSERT INTO plan_versions (user_id, week_start, version, reason, summary)
         VALUES (?, ?, 1, "initial", ?)',
        [$userId, $monday, 'Seeded for logging tests.']
    );

    // One prescribed day with targets and three meals.
    $dayId = DB::insert(
        'INSERT INTO prescribed_days
            (plan_version_id, day_date, target_calories, target_protein_g,
             target_fat_g, target_carbs_g, constraints)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$planId, $monday, 2400, 180.0, 80.0, 220.0, json_encode([
            'protein'  => ['mode' => 'at_least'],
            'calories' => ['mode' => 'range_pct', 'lo' => 0.85, 'hi' => 1.05],
            'fat'      => ['mode' => 'ignore'],
            'carbs'    => ['mode' => 'ignore'],
        ])]
    );

    $meals = [
        ['breakfast', 'Eggs and oats',      600, 45.0, 22.0, 50.0, 6.0],
        ['lunch',     'Chicken and rice',   800, 70.0, 22.0, 70.0, 4.0],
        ['dinner',    'Beef and potatoes', 1000, 65.0, 36.0, 100.0, 8.0],
    ];
    $mealIds = [];
    foreach ($meals as [$s, $n, $cal, $pro, $fat, $carb, $fib]) {
        $mealIds[$s] = DB::insert(
            'INSERT INTO prescribed_meals
                (prescribed_day_id, slot, kind, name, calories, protein_g, fat_g,
                 carbs_g, fiber_g, prep_minutes, ingredients)
             VALUES (?, ?, "specified", ?, ?, ?, ?, ?, ?, ?, ?)',
            [$dayId, $s, $n, $cal, $pro, $fat, $carb, $fib, 15,
             json_encode([['item' => 'test food', 'household' => '1 cup']])]
        );
    }

    // Two sessions on the same date: one committed, one optional. That pair is
    // what proves optional work never counts against adherence (§3.3a).
    $legPress = DB::one("SELECT id FROM exercises WHERE slug = 'leg-press'");
    $plank    = DB::one("SELECT id FROM exercises WHERE slug = 'plank'");

    $committed = DB::insert(
        'INSERT INTO prescribed_sessions
            (plan_version_id, session_date, session_type, focus, is_committed,
             target_minutes, location, warmup_minutes, warmup_detail, rationale)
         VALUES (?, ?, "strength", "full", 1, 55, "full_gym", 10, ?, ?)',
        [$planId, $monday, 'Bike five minutes, then ramp.', 'Seeded session.']
    );
    $optional = DB::insert(
        'INSERT INTO prescribed_sessions
            (plan_version_id, session_date, session_type, focus, is_committed,
             target_minutes, location)
         VALUES (?, ?, "cardio", "conditioning", 0, 30, "outdoors")',
        [$planId, $monday]
    );

    $pex = [];
    foreach ([[$committed, $legPress, 'main'], [$committed, $plank, 'core']] as [$sid, $ex, $block]) {
        if ($ex === null) {
            continue;
        }
        $pex[] = DB::insert(
            'INSERT INTO prescribed_exercises
                (session_id, exercise_id, block, sets, target_reps, target_rpe)
             VALUES (?, ?, ?, 3, "10", 7)',
            [$sid, (int) $ex['id'], $block]
        );
    }

    return [
        'user_id'   => $userId,
        'plan_id'   => $planId,
        'meals'     => $mealIds,
        'committed' => $committed,
        'optional'  => $optional,
        'leg_press' => $legPress === null ? null : (int) $legPress['id'],
        'pex'       => $pex,
    ];
});

printf("        user #%d, plan #%d, week of %s\n", $seed['user_id'], $seed['plan_id'], $monday);

// A token before anything else: CSRF is verified on every mutating request
// including login, so the very first POST needs one already in hand. GET /api/me
// is the bootstrap a real client uses for exactly this reason.
t('a CSRF token can be fetched before logging in', function () use (&$csrf) {
    $r = req('GET', 'me');
    if ($r['status'] !== 200) {
        return "expected 200 even when logged out, got {$r['status']}";
    }
    $csrf = $r['body']['csrf'] ?? null;
    return is_string($csrf) && $csrf !== '' ?: 'no token in the response';
});

t('the seeded user can log in', function () use ($username, $password) {
    $r = req('POST', 'login', ['identifier' => $username, 'password' => $password]);
    return $r['status'] === 200 ?: "login returned {$r['status']}: {$r['raw']}";
});

// ---- the day view ----------------------------------------------------------

echo "\n2. the day view\n";

t('an untouched day returns every slot, empty', function () use ($today) {
    $r = req('GET', "nutrition/day/{$today}");
    if ($r['status'] !== 200) {
        return "status {$r['status']}: {$r['raw']}";
    }
    $d = $r['body'];
    // Six slots: breakfast, lunch, dinner, and three snacks.
    if (count($d['meals'] ?? []) !== 6) {
        return 'expected 6 slots, got ' . count($d['meals'] ?? []);
    }
    // An empty state the client does not have to special-case.
    return ($d['logged'] ?? true) === false
        && ($d['totals']['calories'] ?? null) === 0
        ?: 'expected logged=false and zero totals: ' . json_encode($d['totals'] ?? null);
});

t('the day carries the prescribed targets', function () use ($today) {
    $r = req('GET', "nutrition/day/{$today}");
    $tgt = $r['body']['target'] ?? null;
    return ($tgt['calories'] ?? null) == 2400 && ($tgt['protein'] ?? null) == 180
        ?: 'wrong target: ' . json_encode($tgt);
});

t('the day carries what was prescribed to eat', function () use ($today) {
    $r = req('GET', "nutrition/day/{$today}");
    $p = $r['body']['prescribed'] ?? [];
    return count($p) === 3 && ($p[0]['ingredients'][0]['item'] ?? null) === 'test food'
        ?: 'expected 3 prescribed meals with ingredients, got ' . json_encode($p);
});

t('a date that is not a date is rejected', function () {
    $r = req('GET', 'nutrition/day/not-a-date');
    return $r['status'] === 422 ?: "expected 422, got {$r['status']}";
});

// ---- entries ---------------------------------------------------------------

echo "\n3. entries, and net carbs at intake\n";

$entryId = null;

t('adding a food derives net carbs from total minus fiber', function () use ($today, &$entryId) {
    $r = req('POST', 'nutrition/entries', [
        'date' => $today, 'slot' => 'breakfast',
        'name' => 'Oats', 'calories' => 380, 'protein' => 13,
        'fat' => 7, 'total_carbs' => 67, 'fiber' => 10,
    ]);
    if ($r['status'] !== 201) {
        return "status {$r['status']}: {$r['raw']}";
    }
    $entryId = $r['body']['entry_id'] ?? null;
    $e = slot($r['body']['day'], 'breakfast')['entries'][0] ?? null;
    // 67 - 10 = 57, computed server-side. The client never does this.
    return ($e['carbs'] ?? null) == 57.0 && ($e['total_carbs'] ?? null) == 67.0
        && ($e['fiber'] ?? null) == 10.0
        ?: 'net carbs wrong: ' . json_encode($e);
});

t('a food with no fiber figure treats total as net', function () use ($today) {
    $r = req('POST', 'nutrition/entries', [
        'date' => $today, 'slot' => 'lunch',
        'name' => 'Rice', 'calories' => 200, 'protein' => 4, 'fat' => 0,
        'total_carbs' => 45,
    ]);
    $e = slot($r['body']['day'], 'lunch')['entries'][0] ?? null;
    return ($e['carbs'] ?? null) == 45.0 ?: 'expected carbs 45, got ' . json_encode($e);
});

t('fiber exceeding carbs floors net at zero rather than going negative', function () use ($today) {
    $r = req('POST', 'nutrition/entries', [
        'date' => $today, 'slot' => 'snack_am',
        'name' => 'Psyllium', 'calories' => 30, 'protein' => 0, 'fat' => 0,
        'total_carbs' => 5, 'fiber' => 9,
    ]);
    $e = slot($r['body']['day'], 'snack_am')['entries'][0] ?? null;
    return ($e['carbs'] ?? null) == 0.0 ?: 'expected 0, got ' . json_encode($e);
});

t('day totals sum the entries', function () use ($today) {
    $r = req('GET', "nutrition/day/{$today}");
    $tot = $r['body']['totals'] ?? [];
    // 380 + 200 + 30 = 610 calories.
    return (int) round((float) ($tot['calories'] ?? 0)) === 610
        ?: 'expected 610 calories, got ' . json_encode($tot);
});

t('a rename does NOT wipe the macros', function () use (&$entryId, $today) {
    // The bug this exists for: the original app did
    // `calories: parseFloat(calories) ?? f.calories`, and parseFloat(undefined)
    // is NaN which ?? does not catch — so a rename-only PUT zeroed all four
    // macros (SPEC-nutrition.md §5). Presence of the key must be the test.
    if ($entryId === null) {
        return 'no entry id from the earlier test';
    }
    $r = req('PATCH', "nutrition/entries/{$entryId}", ['name' => 'Porridge']);
    if ($r['status'] !== 200) {
        return "status {$r['status']}: {$r['raw']}";
    }
    $e = slot($r['body']['day'], 'breakfast')['entries'][0] ?? null;
    return ($e['name'] ?? null) === 'Porridge'
        && ($e['calories'] ?? null) == 380 && ($e['protein'] ?? null) == 13
        && ($e['carbs'] ?? null) == 57
        ?: 'macros changed on a rename: ' . json_encode($e);
});

t('deleting an entry removes it from the totals', function () use (&$entryId, $today) {
    $r = req('DELETE', "nutrition/entries/{$entryId}");
    if ($r['status'] !== 200) {
        return "status {$r['status']}: {$r['raw']}";
    }
    // 610 - 380 = 230.
    return (int) round((float) ($r['body']['day']['totals']['calories'] ?? 0)) === 230
        ?: 'totals not recomputed: ' . json_encode($r['body']['day']['totals'] ?? null);
});

t('another user cannot touch this entry', function () use ($today, $username, $password) {
    // Add one, then try to read it as nobody.
    $r = req('POST', 'nutrition/entries', [
        'date' => $today, 'slot' => 'dinner', 'name' => 'Steak',
        'calories' => 500, 'protein' => 50, 'fat' => 30, 'total_carbs' => 0,
    ]);
    $id = $r['body']['entry_id'] ?? null;
    if ($id === null) {
        return 'could not create an entry to test with';
    }
    req('POST', 'logout');
    $anon = req('DELETE', "nutrition/entries/{$id}");
    // Log back in for the remaining tests. logout regenerated the session and
    // the token, so a fresh one has to be fetched before the login POST — the
    // same dance a real client does.
    req('GET', 'me');
    req('POST', 'login', ['identifier' => $username, 'password' => $password]);
    return in_array($anon['status'], [401, 403], true)
        ?: "an anonymous delete returned {$anon['status']}";
});

// ---- the additive delta ----------------------------------------------------

echo "\n4. the additive manual delta\n";

t('a delta adds on top of the entries', function () use ($today) {
    $before = req('GET', "nutrition/day/{$today}");
    $b = (float) (slot($before['body'], 'lunch')['total']['calories'] ?? 0);

    $r = req('PUT', "nutrition/meals/{$today}/lunch", [
        'delta' => ['calories' => 50, 'protein' => 2, 'fat' => 5, 'carbs' => 0],
    ]);
    if ($r['status'] !== 200) {
        return "status {$r['status']}: {$r['raw']}";
    }
    $after = (float) (slot($r['body']['day'], 'lunch')['total']['calories'] ?? 0);
    return (int) ($after - $b) === 50
        ?: "expected +50, went from {$b} to {$after}";
});

t('a delta is absolute against itself, not cumulative', function () use ($today) {
    // Sending the same delta twice must leave it at 50, not 100 — the client
    // owns the running value, which is what makes +/- nudge buttons behave.
    req('PUT', "nutrition/meals/{$today}/lunch", ['delta' => ['calories' => 50]]);
    $r = req('GET', "nutrition/day/{$today}");
    return (int) (slot($r['body'], 'lunch')['delta']['calories'] ?? 0) === 50
        ?: 'delta accumulated: ' . json_encode(slot($r['body'], 'lunch')['delta'] ?? null);
});

t('the delta survives adding another entry', function () use ($today) {
    // The whole point of additive-not-override: the nudge for cooking oil
    // outlives edits to the item list.
    req('POST', 'nutrition/entries', [
        'date' => $today, 'slot' => 'lunch', 'name' => 'Broccoli',
        'calories' => 55, 'protein' => 4, 'fat' => 0, 'total_carbs' => 11, 'fiber' => 5,
    ]);
    $r = req('GET', "nutrition/day/{$today}");
    return (int) (slot($r['body'], 'lunch')['delta']['calories'] ?? 0) === 50
        ?: 'delta lost when an entry was added';
});

t('a negative delta is allowed', function () use ($today) {
    $r = req('PUT', "nutrition/meals/{$today}/lunch", ['delta' => ['calories' => -30]]);
    return (int) (slot($r['body']['day'], 'lunch')['delta']['calories'] ?? 0) === -30
        ?: 'signed delta rejected';
});

t('meal notes save', function () use ($today) {
    $r = req('PUT', "nutrition/meals/{$today}/lunch", ['notes' => 'Ate out.']);
    return (slot($r['body']['day'], 'lunch')['notes'] ?? null) === 'Ate out.'
        ?: 'notes not saved';
});

t('an empty PUT is rejected rather than silently doing nothing', function () use ($today) {
    $r = req('PUT', "nutrition/meals/{$today}/lunch", []);
    return $r['status'] === 422 ?: "expected 422, got {$r['status']}";
});

// ---- as planned ------------------------------------------------------------

echo "\n5. the one-tap 'ate as planned'\n";

t('as-planned copies the prescribed macros in', function () use ($today) {
    $r = req('POST', 'nutrition/as-planned', ['date' => $today, 'slot' => 'breakfast']);
    if ($r['status'] !== 200) {
        return "status {$r['status']}: {$r['raw']}";
    }
    $m = slot($r['body']['day'], 'breakfast');
    return ($m['adherence'] ?? null) === 'as_planned'
        && (int) ($m['total']['calories'] ?? 0) === 600
        && ($m['entries'][0]['source'] ?? null) === 'as_planned'
        ?: 'as-planned wrong: ' . json_encode($m);
});

t('tapping as-planned twice does not double the meal', function () use ($today) {
    req('POST', 'nutrition/as-planned', ['date' => $today, 'slot' => 'breakfast']);
    $r = req('GET', "nutrition/day/{$today}");
    $m = slot($r['body'], 'breakfast');
    return count($m['entries'] ?? []) === 1 && (int) ($m['total']['calories'] ?? 0) === 600
        ?: 'meal doubled: ' . json_encode($m);
});

t('as-planned on a slot with nothing prescribed is refused', function () use ($today) {
    $r = req('POST', 'nutrition/as-planned', ['date' => $today, 'slot' => 'snack_eve']);
    return $r['status'] === 422 ?: "expected 422, got {$r['status']}";
});

t('a meal can be marked skipped', function () use ($today) {
    $r = req('PUT', "nutrition/meals/{$today}/snack_pm", ['adherence' => 'skipped']);
    return (slot($r['body']['day'], 'snack_pm')['adherence'] ?? null) === 'skipped'
        ?: 'not marked skipped';
});

// ---- the verdict -----------------------------------------------------------

echo "\n6. the goal verdict (server-side only)\n";

t('the day carries a verdict once food is logged', function () use ($today) {
    $r = req('GET', "nutrition/day/{$today}");
    $v = $r['body']['verdict'] ?? null;
    return is_array($v) && array_key_exists('on_target', $v)
        ?: 'no verdict on the day: ' . json_encode($v);
});

t('protein hit with calories short reads as short-but-ok, not a failure', function () use ($today, $seed) {
    // SPEC-safety §8, the User #2 rule. Target is 2400 cal / 180 g protein;
    // this logs the protein and deliberately undershoots the calories.
    $userId = (int) $seed['user_id'];
    $dayRow = DB::one('SELECT id FROM logged_days WHERE user_id = ? AND log_date = ?',
        [$userId, $today]);
    if ($dayRow === null) {
        return 'no logged day';
    }
    // Wipe the day and log one clean entry, so the assertion is about the rule
    // rather than about whatever the earlier tests left behind.
    DB::run('DELETE FROM logged_meals WHERE logged_day_id = ?', [(int) $dayRow['id']]);
    $r = req('POST', 'nutrition/entries', [
        'date' => $today, 'slot' => 'dinner', 'name' => 'Protein only',
        'calories' => 1500, 'protein' => 185, 'fat' => 40, 'total_carbs' => 100,
    ]);
    $v = $r['body']['day']['verdict'] ?? null;
    return ($v['short_but_ok'] ?? null) === true
        ?: 'expected short_but_ok: ' . json_encode($v);
});

// ---- correcting an entry ---------------------------------------------------

echo "\n6b. the serving correction\n";

/*
 * The correction the app shipped without.
 *
 * Search returns 100g of yellow mustard, nobody eats 100g of yellow mustard, and every
 * number downstream was wrong: the day, the drift detection, the check-in, and the menu
 * Claude writes off the back of it. PATCH existed; nothing called it, and it did not
 * rescale.
 */
$mustardId = null;

t('an entry can be logged with a serving', function () use ($today, &$mustardId) {
    $r = req('POST', 'nutrition/entries', [
        'date' => $today, 'slot' => 'lunch', 'name' => 'Yellow mustard',
        'serving_g' => 100, 'calories' => 60, 'protein' => 3.7, 'fat' => 3.4,
        'total_carbs' => 6.0, 'fiber' => 4.0,
    ]);
    if ($r['status'] !== 201) {
        return "status {$r['status']}: {$r['raw']}";
    }
    $mustardId = $r['body']['entry_id'] ?? null;
    return $mustardId !== null ?: 'no entry_id returned';
});

t('changing the serving rescales every macro', function () use (&$mustardId) {
    // 100g -> 5g is a factor of 0.05. A teaspoon of mustard, which is the real case.
    $r = req('PATCH', "nutrition/entries/{$mustardId}", ['serving_g' => 5]);
    if ($r['status'] !== 200) {
        return "status {$r['status']}: {$r['raw']}";
    }
    $e = findEntry($r['body']['day'] ?? [], (int) $mustardId);
    if ($e === null) {
        return 'the entry vanished from the day';
    }
    // calories round to whole, macros to 1dp. 60*.05 = 3, 3.7*.05 = 0.185 -> 0.2.
    $want = ['serving_g' => 5, 'calories' => 3.0, 'protein' => 0.2, 'fat' => 0.2];
    foreach ($want as $k => $v) {
        if (abs((float) $e[$k] - $v) > 0.051) {
            return "$k was {$e[$k]}, wanted $v: " . json_encode($e);
        }
    }
    return true;
});

t('net carbs stay derived after a rescale', function () use ($today, &$mustardId) {
    // 6.0 total - 4.0 fiber = 2.0 net at 100g, so 0.1 net at 5g. Scaling total and
    // fiber separately and re-deriving must not drift from scaling net directly.
    $r = req('GET', "nutrition/day/{$today}");
    $e = findEntry($r['body'] ?? [], (int) $mustardId);
    if ($e === null) {
        return 'the entry vanished';
    }
    return abs((float) $e['carbs'] - 0.1) < 0.051
        ?: "net carbs were {$e['carbs']}, wanted 0.1: " . json_encode($e);
});

t('an explicit macro wins over the rescale', function () use (&$mustardId) {
    /*
     * "34g, and I already know the real calories."
     *
     * The ratio would say 3 * (34/5) = 20.4. Sending calories explicitly must record
     * the serving without recomputing the figure from a ratio that no longer applies —
     * otherwise correcting a serving on an already-corrected entry is impossible.
     */
    $r = req('PATCH', "nutrition/entries/{$mustardId}", ['serving_g' => 34, 'calories' => 99]);
    if ($r['status'] !== 200) {
        return "status {$r['status']}: {$r['raw']}";
    }
    $e = findEntry($r['body']['day'] ?? [], (int) $mustardId);
    if ((int) $e['serving_g'] !== 34) {
        return "serving was {$e['serving_g']}, wanted 34";
    }
    if (abs((float) $e['calories'] - 99.0) > 0.51) {
        return "calories were {$e['calories']}, wanted the explicit 99";
    }
    // The macros NOT sent still scale, by 34/5.
    return abs((float) $e['protein'] - 1.4) < 0.11
        ?: "protein was {$e['protein']}, wanted ~1.4 (0.2 * 6.8)";
});

t('a rename does not touch the serving or the macros', function () use (&$mustardId) {
    $r = req('PATCH', "nutrition/entries/{$mustardId}", ['name' => 'Mustard, yellow']);
    $e = findEntry($r['body']['day'] ?? [], (int) $mustardId);
    return $e['name'] === 'Mustard, yellow'
        && (int) $e['serving_g'] === 34
        && abs((float) $e['calories'] - 99.0) < 0.51
        ?: 'a rename changed something else: ' . json_encode($e);
});

t('an entry with no serving is left alone by a rescale', function () use ($today) {
    /*
     * A prescribed meal logged as-planned has no serving recorded, so there is no ratio
     * to scale FROM. The server must not invent one — and the UI does not offer the
     * control at all in that case, which is what keeps rescaling all-or-nothing.
     */
    $r = req('POST', 'nutrition/entries', [
        'date' => $today, 'slot' => 'lunch', 'name' => 'No serving recorded',
        'calories' => 200, 'protein' => 10, 'fat' => 5, 'total_carbs' => 20,
    ]);
    $id = $r['body']['entry_id'] ?? null;
    $r2 = req('PATCH', "nutrition/entries/{$id}", ['serving_g' => 50]);
    $e  = findEntry($r2['body']['day'] ?? [], (int) $id);
    // The serving is now recorded, but nothing was scaled from nothing.
    return (int) $e['serving_g'] === 50 && abs((float) $e['calories'] - 200.0) < 0.51
        ?: 'macros moved with no ratio to scale from: ' . json_encode($e);
});

t('an entry that is not mine cannot be corrected', function () {
    // Ownership is enforced by ownedEntry()'s join through logged_days.user_id, so a bad
    // id and someone else's id fail identically. This asserts the join is still there.
    $r = req('PATCH', 'nutrition/entries/99999999', ['serving_g' => 1]);
    return $r['status'] === 404 ?: "expected 404, got {$r['status']}: {$r['raw']}";
});

// ---- favorites -------------------------------------------------------------

echo "\n7. favorites\n";

$favId = null;

t('a favorite can be added', function () use (&$favId) {
    $r = req('POST', 'nutrition/favorites', [
        'name' => 'Greek yogurt', 'serving_g' => 170,
        'calories' => 100, 'protein' => 17, 'fat' => 0, 'carbs' => 6,
    ]);
    if ($r['status'] !== 201) {
        return "status {$r['status']}: {$r['raw']}";
    }
    $favId = $r['body']['id'] ?? null;
    return $favId !== null ?: 'no id returned';
});

t('the same name again is refused, case-insensitively', function () {
    $r = req('POST', 'nutrition/favorites', [
        'name' => 'GREEK YOGURT', 'calories' => 100, 'protein' => 17, 'fat' => 0, 'carbs' => 6,
    ]);
    // The unique key is case-insensitive by collation, which is what reproduces
    // the original app's isFavorited() dedupe.
    return $r['status'] === 409 ?: "expected 409, got {$r['status']}: {$r['raw']}";
});

t('renaming a favorite keeps its macros', function () use (&$favId) {
    // Same NaN-overwrite bug as entries, same fix.
    $r = req('PATCH', "nutrition/favorites/{$favId}", ['name' => 'Yogurt, Greek']);
    if ($r['status'] !== 200) {
        return "status {$r['status']}: {$r['raw']}";
    }
    foreach ($r['body']['favorites'] as $f) {
        if ((int) $f['id'] === (int) $favId) {
            return $f['name'] === 'Yogurt, Greek' && (int) $f['calories'] === 100
                && (float) $f['protein'] == 17.0
                ?: 'macros changed on rename: ' . json_encode($f);
        }
    }
    return 'favorite vanished';
});

t('a favorite can be deleted', function () use (&$favId) {
    $r = req('DELETE', "nutrition/favorites/{$favId}");
    return $r['status'] === 200 && count($r['body']['favorites'] ?? []) === 0
        ?: "delete returned {$r['status']}: {$r['raw']}";
});

// ---- training --------------------------------------------------------------

echo "\n8. training logs\n";

t('the training day lists prescribed sessions with exercises', function () use ($today) {
    $r = req('GET', "training/day/{$today}");
    if ($r['status'] !== 200) {
        return "status {$r['status']}: {$r['raw']}";
    }
    $s = $r['body']['sessions'] ?? [];
    if (count($s) !== 2) {
        return 'expected 2 sessions, got ' . count($s);
    }
    // Committed first, and it carries its exercises and warm-up.
    return ($s[0]['is_committed'] ?? null) === true
        && count($s[0]['exercises'] ?? []) === 2
        && ($s[0]['warmup_minutes'] ?? null) === 10
        ?: 'session shape wrong: ' . json_encode($s[0]);
});

t('a session logs with its exercises in one request', function () use ($today, $seed) {
    $r = req('POST', 'training/sessions', [
        'date'   => $today,
        'prescribed_session_id' => $seed['committed'],
        'status' => 'completed',
        'actual_minutes' => 52,
        'session_rpe'    => 8,
        'exercises' => [
            ['slug' => 'leg-press', 'sets_completed' => 3, 'actual_reps' => '10',
             'actual_weight_kg' => 100, 'rpe' => 7],
            ['slug' => 'plank', 'sets_completed' => 3, 'actual_seconds' => 45, 'rpe' => 6],
        ],
    ]);
    if ($r['status'] !== 201) {
        return "status {$r['status']}: {$r['raw']}";
    }
    foreach ($r['body']['day']['sessions'] as $s) {
        if (($s['prescribed_session_id'] ?? null) === $seed['committed']) {
            return count($s['logged']['exercises'] ?? []) === 2
                && ($s['logged']['session_rpe'] ?? null) === 8
                ?: 'log not attached: ' . json_encode($s['logged']);
        }
    }
    return 'logged session not found on the day';
});

t('an exercise resolves by alias, not just slug', function () use ($today) {
    $alias = DB::one(
        'SELECT a.alias FROM exercise_aliases a
         JOIN exercises e ON e.id = a.exercise_id LIMIT 1'
    );
    if ($alias === null) {
        return null;   // no aliases seeded; nothing to prove
    }
    $r = req('POST', 'training/sessions', [
        'date' => $today, 'status' => 'completed',
        'exercises' => [['slug' => $alias['alias'], 'sets_completed' => 1, 'actual_reps' => '5']],
    ]);
    return $r['status'] === 201 ?: "alias '{$alias['alias']}' rejected: {$r['raw']}";
});

t('an unknown exercise is refused rather than silently dropped', function () use ($today) {
    $r = req('POST', 'training/sessions', [
        'date' => $today, 'status' => 'completed',
        'exercises' => [['slug' => 'not-a-real-exercise-xyz']],
    ]);
    return $r['status'] === 422 ?: "expected 422, got {$r['status']}";
});

t('re-logging the same session replaces it rather than adding a second', function () use ($today, $seed) {
    req('POST', 'training/sessions', [
        'date' => $today, 'prescribed_session_id' => $seed['committed'],
        'status' => 'partial', 'actual_minutes' => 30,
    ]);
    $n = (int) (DB::one(
        'SELECT COUNT(*) AS n FROM logged_sessions ls
         JOIN logged_days ld ON ld.id = ls.logged_day_id
         WHERE ld.user_id = ? AND ld.log_date = ? AND ls.prescribed_session_id = ?',
        [$seed['user_id'], $today, $seed['committed']]
    )['n'] ?? 0);
    return $n === 1 ?: "expected 1 row for that session, found {$n}";
});

t('another user\'s session cannot be logged against', function () use ($today, $seed) {
    $other = DB::one(
        'SELECT ps.id FROM prescribed_sessions ps
         JOIN plan_versions pv ON pv.id = ps.plan_version_id
         WHERE pv.user_id <> ? LIMIT 1',
        [(int) $seed['user_id']]
    );
    if ($other === null) {
        return null;   // nobody else has a plan; nothing to test
    }
    $r = req('POST', 'training/sessions', [
        'date' => $today, 'prescribed_session_id' => (int) $other['id'], 'status' => 'completed',
    ]);
    return $r['status'] === 422 ?: "expected 422, got {$r['status']}";
});

// ---- adherence -------------------------------------------------------------

echo "\n9. adherence counts committed sessions only (SPEC-coaching §3.3a)\n";

t('the committed session counts toward the day', function () use ($today, $seed) {
    req('POST', 'training/sessions', [
        'date' => $today, 'prescribed_session_id' => $seed['committed'],
        'status' => 'completed', 'actual_minutes' => 52,
    ]);
    $d = DB::one(
        'SELECT sessions_prescribed, sessions_completed FROM logged_days
         WHERE user_id = ? AND log_date = ?', [$seed['user_id'], $today]
    );
    return (int) $d['sessions_prescribed'] === 1 && (int) $d['sessions_completed'] === 1
        ?: 'counts wrong: ' . json_encode($d);
});

t('an optional session does not inflate the completed count', function () use ($today, $seed) {
    // The asymmetry that matters: optional work is a bonus, never a debt, and
    // must not be able to paper over a missed committed session.
    req('POST', 'training/sessions', [
        'date' => $today, 'prescribed_session_id' => $seed['optional'],
        'status' => 'completed', 'actual_minutes' => 30,
    ]);
    $d = DB::one(
        'SELECT sessions_prescribed, sessions_completed FROM logged_days
         WHERE user_id = ? AND log_date = ?', [$seed['user_id'], $today]
    );
    return (int) $d['sessions_prescribed'] === 1 && (int) $d['sessions_completed'] === 1
        ?: 'optional session leaked into the counts: ' . json_encode($d);
});

t('a partial session still counts as showing up', function () use ($today, $seed) {
    req('POST', 'training/sessions', [
        'date' => $today, 'prescribed_session_id' => $seed['committed'],
        'status' => 'partial', 'actual_minutes' => 25,
    ]);
    $d = DB::one(
        'SELECT sessions_completed FROM logged_days WHERE user_id = ? AND log_date = ?',
        [$seed['user_id'], $today]
    );
    // Grading a short session as a miss teaches people not to log short sessions.
    return (int) $d['sessions_completed'] === 1
        ?: 'a partial session was not counted: ' . json_encode($d);
});

t('a skipped session drops the completed count to zero', function () use ($today, $seed) {
    req('POST', 'training/sessions', [
        'date' => $today, 'prescribed_session_id' => $seed['committed'],
        'status' => 'skipped',
    ]);
    $d = DB::one(
        'SELECT sessions_prescribed, sessions_completed FROM logged_days
         WHERE user_id = ? AND log_date = ?', [$seed['user_id'], $today]
    );
    return (int) $d['sessions_prescribed'] === 1 && (int) $d['sessions_completed'] === 0
        ?: 'skip not reflected: ' . json_encode($d);
});

// ---- history ---------------------------------------------------------------

echo "\n10. load history\n";

t('history returns the logged load for an exercise', function () use ($seed, $today) {
    // Log a completed session so there is something to read back.
    req('POST', 'training/sessions', [
        'date' => $today, 'prescribed_session_id' => $seed['committed'],
        'status' => 'completed',
        'exercises' => [['slug' => 'leg-press', 'sets_completed' => 3,
                         'actual_reps' => '10', 'actual_weight_kg' => 100, 'rpe' => 7]],
    ]);
    $r = req('GET', 'training/history/leg-press');
    if ($r['status'] !== 200) {
        return "status {$r['status']}: {$r['raw']}";
    }
    $h = $r['body']['history'] ?? [];
    return $h !== [] && (float) ($h[0]['weight_kg'] ?? 0) == 100.0 && (int) ($h[0]['rpe'] ?? 0) === 7
        ?: 'history wrong: ' . json_encode($h);
});

t('history for an unknown exercise is a 404', function () {
    $r = req('GET', 'training/history/definitely-not-an-exercise');
    return $r['status'] === 404 ?: "expected 404, got {$r['status']}";
});

// ---- the daily check-in ----------------------------------------------------

echo "\n11. the daily check-in\n";

t('a check-in saves', function () use ($today) {
    $r = req('PUT', "checkin/{$today}", [
        'energy' => 4, 'sleep_hours' => 7.5, 'sleep_quality' => 3,
        'soreness' => 2, 'mood' => 4, 'notes' => 'Felt fine.',
    ]);
    if ($r['status'] !== 200) {
        return "status {$r['status']}: {$r['raw']}";
    }
    $c = $r['body']['day']['checkin'] ?? null;
    return ($c['energy'] ?? null) === 4 && (float) ($c['sleep_hours'] ?? 0) == 7.5
        && ($c['notes'] ?? null) === 'Felt fine.'
        ?: 'check-in wrong: ' . json_encode($c);
});

t('a partial check-in is allowed', function () use ($today) {
    // Someone who only wants to say they slept badly should not have to rate
    // four other things first.
    $r = req('PUT', "checkin/{$today}", ['sleep_quality' => 1]);
    return ($r['body']['day']['checkin']['sleep_quality'] ?? null) === 1
        && ($r['body']['day']['checkin']['energy'] ?? null) === 4   // untouched
        ?: 'partial check-in clobbered other fields: '
           . json_encode($r['body']['day']['checkin'] ?? null);
});

t('an out-of-range rating is rejected', function () use ($today) {
    $r = req('PUT', "checkin/{$today}", ['energy' => 9]);
    return $r['status'] === 422 ?: "expected 422, got {$r['status']}";
});

t('an empty check-in is rejected', function () use ($today) {
    $r = req('PUT', "checkin/{$today}", []);
    return $r['status'] === 422 ?: "expected 422, got {$r['status']}";
});

// ---- the week view ---------------------------------------------------------

echo "\n12. the week view\n";

t('the week returns seven days', function () use ($monday) {
    $r = req('GET', "nutrition/week/{$monday}");
    if ($r['status'] !== 200) {
        return "status {$r['status']}: {$r['raw']}";
    }
    return count($r['body']['days'] ?? []) === 7
        ?: 'expected 7 days, got ' . count($r['body']['days'] ?? []);
});

// ---- cleanup ---------------------------------------------------------------

if (!$keep) {
    DB::run('DELETE FROM users WHERE id = ?', [$seed['user_id']]);
    echo "\n  test user removed\n";
} else {
    printf("\n  kept: user #%d (%s / %s)\n", $seed['user_id'], $username, $password);
}
@unlink($cookieJar);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
