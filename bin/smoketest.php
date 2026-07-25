<?php
declare(strict_types=1);

/**
 * Schema smoke test. Proves the schema BEHAVES, not just that it exists —
 * a declared-but-unenforced constraint is worse than none, because it looks
 * safe.
 *
 * Everything runs inside a transaction that is always rolled back, so this is
 * safe against a database with real data in it.
 *
 *   php bin/smoketest.php
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$pass = 0;
$fail = 0;

function check(string $label, callable $fn): void
{
    global $pass, $fail;
    try {
        $result = $fn();
        if ($result === true) {
            printf("  ok    %s\n", $label);
            $pass++;
        } else {
            printf("  FAIL  %s — %s\n", $label, is_string($result) ? $result : 'returned false');
            $fail++;
        }
    } catch (Throwable $e) {
        printf("  FAIL  %s — %s\n", $label, $e->getMessage());
        $fail++;
    }
}

/** Assert that $fn throws — used to prove a constraint actually rejects. */
function rejects(callable $fn): bool|string
{
    try {
        $fn();
        return 'expected rejection, but it was accepted';
    } catch (PDOException $e) {
        return true;
    }
}

echo "schema smoke test\n\n";

$pdo = DB::pdo();
$pdo->beginTransaction();

try {
    // ---- identity ----------------------------------------------------------

    $userId = 0;
    check('insert user', function () use (&$userId): bool {
        $userId = DB::insert(
            'INSERT INTO users (username, display_name, email, password_hash)
             VALUES (?, ?, ?, ?)',
            ['smoke_u1', 'Smoke One', 'smoke1@example.test', 'x']
        );
        return $userId > 0;
    });

    check('username is unique', fn() => rejects(fn() => DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash)
         VALUES (?, ?, ?, ?)',
        ['smoke_u1', 'Dupe', 'other@example.test', 'x']
    )));

    check('onboarding_state defaults to pending', function () use (&$userId): bool|string {
        $row = DB::one('SELECT onboarding_state FROM users WHERE id = ?', [$userId]);
        return $row['onboarding_state'] === 'pending'
            ?: "got {$row['onboarding_state']}";
    });

    // ---- FK enforcement ----------------------------------------------------

    check('profiles rejects an unknown user_id', fn() => rejects(fn() => DB::insert(
        'INSERT INTO profiles (user_id) VALUES (?)', [999999999]
    )));

    check('insert profile', function () use (&$userId): bool {
        DB::run('INSERT INTO profiles (user_id, height_cm, tone) VALUES (?, ?, ?)',
            [$userId, 193.0, 'sarcastic_hardass']);
        return true;
    });

    check('committed_days_per_week default is 3', function () use (&$userId): bool|string {
        $row = DB::one('SELECT committed_days_per_week FROM profiles WHERE user_id = ?', [$userId]);
        return (int) $row['committed_days_per_week'] === 3 ?: "got {$row['committed_days_per_week']}";
    });

    // ---- CHECK constraints -------------------------------------------------

    check('availability rejects weekday 8', fn() => rejects(fn() => DB::run(
        'INSERT INTO availability (user_id, weekday, can_train) VALUES (?, ?, ?)',
        [$userId, 8, 'yes']
    )));

    check('availability accepts the 7-day grid', function () use (&$userId): bool {
        for ($d = 1; $d <= 7; $d++) {
            DB::run(
                'INSERT INTO availability (user_id, weekday, can_train, minutes, access)
                 VALUES (?, ?, ?, ?, ?)',
                [$userId, $d, $d <= 5 ? 'yes' : 'sometimes', 60, $d <= 5 ? 'full_gym' : 'outdoors']
            );
        }
        return count(DB::all('SELECT 1 FROM availability WHERE user_id = ?', [$userId])) === 7;
    });

    // ---- constraints as data ----------------------------------------------

    check('hard + soft constraints coexist', function () use (&$userId): bool {
        DB::run('INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
                 VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, 'food', 'hard', 'peanuts', 'anaphylaxis', 'onboarding']);
        DB::run('INSERT INTO user_constraints (user_id, kind, tier, subject, reason, progression, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, 'movement', 'soft', 'back squat', 'left knee',
             json_encode(['target' => 'back squat', 'status' => 'working_toward']), 'onboarding']);
        return count(DB::all(
            'SELECT 1 FROM user_constraints WHERE user_id = ? AND active = 1', [$userId]
        )) === 2;
    });

    check('progression JSON round-trips', function () use (&$userId): bool|string {
        $row = DB::one("SELECT progression FROM user_constraints
                        WHERE user_id = ? AND subject = 'back squat'", [$userId]);
        $j = json_decode((string) $row['progression'], true);
        return ($j['status'] ?? null) === 'working_toward' ?: 'JSON did not survive';
    });

    // ---- plan versioning ---------------------------------------------------

    $planId = 0;
    check('insert plan version', function () use (&$userId, &$planId): bool {
        $planId = DB::insert(
            'INSERT INTO plan_versions (user_id, week_start, version, reason)
             VALUES (?, ?, ?, ?)',
            [$userId, '2026-07-27', 1, 'initial']
        );
        return $planId > 0;
    });

    check('(user, week, version) is unique', fn() => rejects(fn() => DB::insert(
        'INSERT INTO plan_versions (user_id, week_start, version, reason)
         VALUES (?, ?, ?, ?)',
        [$userId, '2026-07-27', 1, 'veto']
    )));

    check('a second version of the same week is allowed', function () use (&$userId): bool {
        $id = DB::insert(
            'INSERT INTO plan_versions (user_id, week_start, version, reason)
             VALUES (?, ?, ?, ?)',
            [$userId, '2026-07-27', 2, 'veto']
        );
        return $id > 0;
    });

    check('live version lookup finds exactly one', function () use (&$userId): bool|string {
        DB::run('UPDATE plan_versions SET superseded_at = NOW()
                 WHERE user_id = ? AND week_start = ? AND version = 1',
            [$userId, '2026-07-27']);
        $rows = DB::all('SELECT version FROM plan_versions
                         WHERE user_id = ? AND week_start = ? AND superseded_at IS NULL',
            [$userId, '2026-07-27']);
        return count($rows) === 1 && (int) $rows[0]['version'] === 2
            ?: 'expected exactly version 2 live, got ' . count($rows) . ' row(s)';
    });

    // ---- prescriptions -----------------------------------------------------

    $exId = 0;
    check('insert exercise', function () use (&$exId): bool {
        $exId = DB::insert(
            'INSERT INTO exercises (slug, name, category, pattern, load_type, is_system)
             VALUES (?, ?, ?, ?, ?, 1)',
            ['trap-bar-deadlift', 'Trap Bar Deadlift', 'strength', 'hinge', 'weight']
        );
        return $exId > 0;
    });

    check('exercise slug is unique', fn() => rejects(fn() => DB::insert(
        'INSERT INTO exercises (slug, name, category, pattern)
         VALUES (?, ?, ?, ?)',
        ['trap-bar-deadlift', 'Dupe', 'strength', 'hinge']
    )));

    check('committed and optional sessions coexist', function () use (&$planId, &$exId): bool {
        $s1 = DB::insert(
            'INSERT INTO prescribed_sessions
             (plan_version_id, session_date, session_type, focus, is_committed,
              target_minutes, warmup_minutes, warmup_required)
             VALUES (?, ?, ?, ?, 1, 60, 10, 1)',
            [$planId, '2026-07-27', 'strength', 'lower']
        );
        DB::insert(
            'INSERT INTO prescribed_sessions
             (plan_version_id, session_date, session_type, focus, is_committed)
             VALUES (?, ?, ?, ?, 0)',
            [$planId, '2026-07-31', 'strength', 'upper']
        );
        // main + core blocks on the same session
        DB::run('INSERT INTO prescribed_exercises
                 (session_id, exercise_id, block, sets, target_reps, target_weight_kg, target_rpe)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$s1, $exId, 'main', 3, '8', 84.0, 7]);
        DB::run('INSERT INTO prescribed_exercises
                 (session_id, exercise_id, block, sets, target_seconds)
                 VALUES (?, ?, ?, ?, ?)',
            [$s1, $exId, 'core', 3, 45]);
        $committed = DB::one(
            'SELECT COUNT(*) AS n FROM prescribed_sessions
             WHERE plan_version_id = ? AND is_committed = 1', [$planId]
        );
        return (int) $committed['n'] === 1;
    });

    check('structured meal ingredients round-trip', function () use (&$planId): bool|string {
        $dayId = DB::insert(
            'INSERT INTO prescribed_days
             (plan_version_id, day_date, target_calories, target_protein_g,
              target_fat_g, target_carbs_g, constraints)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$planId, '2026-07-27', 2100, 180.0, 70.0, 180.0,
             json_encode([
                 'protein'  => ['mode' => 'at_least'],
                 'calories' => ['mode' => 'range_pct', 'lo' => 0.85, 'hi' => 1.05],
             ])]
        );
        DB::run(
            'INSERT INTO prescribed_meals
             (prescribed_day_id, slot, kind, name, ingredients, calories, protein_g)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$dayId, 'dinner', 'specified', 'Chicken and rice',
             json_encode([['item' => 'chicken breast', 'grams' => 170]]), 620, 52.0]
        );
        $row = DB::one("SELECT ingredients FROM prescribed_meals
                        WHERE prescribed_day_id = ? AND slot = 'dinner'", [$dayId]);
        $j = json_decode((string) $row['ingredients'], true);
        return ($j[0]['item'] ?? null) === 'chicken breast' ?: 'ingredients JSON lost';
    });

    // ---- logging + cascade -------------------------------------------------

    check('log a day with the short-but-ok flag', function () use (&$userId, &$planId): bool {
        $dayId = DB::insert(
            'INSERT INTO logged_days
             (user_id, log_date, plan_version_id, energy, mood, macro_on_target,
              macro_short_but_ok, sessions_prescribed, sessions_completed)
             VALUES (?, ?, ?, ?, ?, 1, 1, 5, 5)',
            [$userId, '2026-07-27', $planId, 3, 4]
        );
        $mealId = DB::insert(
            'INSERT INTO logged_meals (logged_day_id, slot, adherence, delta_calories)
             VALUES (?, ?, ?, ?)',
            [$dayId, 'dinner', 'as_planned', -50]
        );
        DB::run(
            'INSERT INTO logged_entries (logged_meal_id, name, calories, protein_g, carbs_g)
             VALUES (?, ?, ?, ?, ?)',
            [$mealId, 'Chicken breast', 280.0, 52.0, 0.0]
        );
        return true;
    });

    check('negative meal delta survives (signed column)', function () use (&$userId): bool|string {
        $row = DB::one(
            'SELECT lm.delta_calories FROM logged_meals lm
             JOIN logged_days ld ON ld.id = lm.logged_day_id
             WHERE ld.user_id = ? AND lm.slot = "dinner"', [$userId]
        );
        return (int) $row['delta_calories'] === -50 ?: "got {$row['delta_calories']}";
    });

    check('deleting a user cascades to logs', function () use (&$userId): bool|string {
        DB::run('DELETE FROM users WHERE id = ?', [$userId]);
        foreach (['profiles', 'availability', 'user_constraints', 'logged_days', 'plan_versions'] as $t) {
            $n = DB::one("SELECT COUNT(*) AS n FROM {$t} WHERE user_id = ?", [$userId]);
            if ((int) $n['n'] !== 0) {
                return "{$t} still has {$n['n']} row(s)";
            }
        }
        // logged_entries hangs off logged_meals -> logged_days: two levels down.
        $orphans = DB::one('SELECT COUNT(*) AS n FROM logged_entries le
                            LEFT JOIN logged_meals lm ON lm.id = le.logged_meal_id
                            WHERE lm.id IS NULL');
        return (int) $orphans['n'] === 0 ?: "{$orphans['n']} orphaned entries";
    });

} finally {
    $pdo->rollBack();
}

printf("\n%d passed, %d failed\n", $pass, $fail);

// Prove the rollback worked — this script must leave nothing behind.
$left = DB::one("SELECT COUNT(*) AS n FROM users WHERE username LIKE 'smoke_%'");
printf("residue: %d row(s)%s\n", (int) $left['n'],
    (int) $left['n'] === 0 ? ' (clean)' : ' ** ROLLBACK FAILED **');

exit($fail === 0 ? 0 : 1);
