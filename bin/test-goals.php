<?php
declare(strict_types=1);

/**
 * Tests for Goals.php.
 *
 * The load-bearing test is §1: the keto preset must reproduce Keto Tracker's
 * original hardcoded rule EXACTLY. If the data-driven evaluator cannot express
 * keto, the generalisation quietly lost something and every streak and award
 * built on it would be subtly wrong.
 *
 *   php bin/test-goals.php
 */

require __DIR__ . '/../src/bootstrap_cli.php';
require YK_SRC . '/lib/Goals.php';

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

/**
 * Keto Tracker's original rule, transcribed from reference-js so the port can
 * be checked against it rather than against my memory of it:
 *
 *   protein  >= target.protein
 *   fat      within +/-5%  (fatPct 0.95..1.05)
 *   carbs    <  target.carbs      (strictly under)
 *   calories 90..100%             (calPct 0.90..1.00)
 */
function originalKetoRule(array $totals, array $target): bool
{
    if ($target['calories'] <= 0 || $target['protein'] <= 0
        || $target['fat'] <= 0 || $target['carbs'] <= 0) {
        return false;
    }
    $calPct = $totals['calories'] / $target['calories'];
    $fatPct = $totals['fat'] / $target['fat'];

    return $totals['protein'] >= $target['protein']
        && $fatPct >= 0.95 && $fatPct <= 1.05
        && $totals['carbs'] < $target['carbs']
        && $calPct >= 0.90 && $calPct <= 1.00;
}

$KETO = [
    'protein'  => ['mode' => 'at_least'],
    'fat'      => ['mode' => 'within_pct', 'pct' => 0.05],
    'carbs'    => ['mode' => 'at_most'],
    'calories' => ['mode' => 'range_pct', 'lo' => 0.90, 'hi' => 1.00],
];

echo "Goals evaluator tests\n\n";
echo "1. keto parity — data-driven vs. the original hardcoded rule\n";

// Deliberately includes exact boundary values, which is where an off-by-one
// in a comparison operator would hide.
$target = ['calories' => 1420.0, 'protein' => 120.0, 'fat' => 100.0, 'carbs' => 22.0];
$cases = [
    'perfect day'            => ['calories' => 1400.0, 'protein' => 125.0, 'fat' => 100.0, 'carbs' => 18.0],
    'protein 1 under'        => ['calories' => 1400.0, 'protein' => 119.0, 'fat' => 100.0, 'carbs' => 18.0],
    'protein exactly at'     => ['calories' => 1400.0, 'protein' => 120.0, 'fat' => 100.0, 'carbs' => 18.0],
    'fat at 95%'             => ['calories' => 1400.0, 'protein' => 125.0, 'fat' =>  95.0, 'carbs' => 18.0],
    'fat at 94%'             => ['calories' => 1400.0, 'protein' => 125.0, 'fat' =>  94.0, 'carbs' => 18.0],
    'fat at 105%'            => ['calories' => 1400.0, 'protein' => 125.0, 'fat' => 105.0, 'carbs' => 18.0],
    'fat at 106%'            => ['calories' => 1400.0, 'protein' => 125.0, 'fat' => 106.0, 'carbs' => 18.0],
    'carbs exactly at'       => ['calories' => 1400.0, 'protein' => 125.0, 'fat' => 100.0, 'carbs' => 22.0],
    'carbs 1 under'          => ['calories' => 1400.0, 'protein' => 125.0, 'fat' => 100.0, 'carbs' => 21.0],
    'calories at 90%'        => ['calories' => 1278.0, 'protein' => 125.0, 'fat' => 100.0, 'carbs' => 18.0],
    'calories at 89%'        => ['calories' => 1263.0, 'protein' => 125.0, 'fat' => 100.0, 'carbs' => 18.0],
    'calories at 100%'       => ['calories' => 1420.0, 'protein' => 125.0, 'fat' => 100.0, 'carbs' => 18.0],
    'calories at 101%'       => ['calories' => 1434.0, 'protein' => 125.0, 'fat' => 100.0, 'carbs' => 18.0],
    'everything wrong'       => ['calories' => 2500.0, 'protein' =>  60.0, 'fat' =>  40.0, 'carbs' => 90.0],
    'zero day'               => ['calories' =>    0.0, 'protein' =>   0.0, 'fat' =>   0.0, 'carbs' =>  0.0],
];

foreach ($cases as $label => $totals) {
    t("keto: {$label}", function () use ($totals, $target, $KETO) {
        $mine = Goals::evaluateDay($totals, $target, $KETO)['on_target'];
        $orig = originalKetoRule($totals, $target);
        return $mine === $orig
            ?: sprintf('evaluator=%s original=%s',
                var_export($mine, true), var_export($orig, true));
    });
}

echo "\n2. the ignore rule\n";

t('all-ignore returns null, not true', function () {
    $v = Goals::evaluateDay(
        ['calories' => 9999.0, 'protein' => 0.0, 'fat' => 0.0, 'carbs' => 0.0],
        ['calories' => 2000.0, 'protein' => 150.0, 'fat' => 60.0, 'carbs' => 200.0],
        ['calories' => ['mode' => 'ignore'], 'protein' => ['mode' => 'ignore'],
         'fat' => ['mode' => 'ignore'], 'carbs' => ['mode' => 'ignore']]
    );
    return $v['on_target'] === null && $v['proximity'] === null
        ?: 'a misconfigured goal marked a 9999 kcal day perfect';
});

t('ignored macros are neither pass nor failure', function () {
    $v = Goals::evaluateDay(
        ['calories' => 2000.0, 'protein' => 150.0, 'fat' => 999.0, 'carbs' => 999.0],
        ['calories' => 2000.0, 'protein' => 150.0, 'fat' => 60.0,  'carbs' => 200.0],
        ['calories' => ['mode' => 'within_pct', 'pct' => 0.05],
         'protein'  => ['mode' => 'at_least'],
         'fat'      => ['mode' => 'ignore'],
         'carbs'    => ['mode' => 'ignore']]
    );
    return $v['on_target'] === true
        && $v['failures'] === []
        && count($v['ignored']) === 2
        ?: 'wild fat/carbs should not affect a goal that ignores them';
});

t('proximity averages scored constraints only', function () {
    // One scored macro, 10% under. Averaging over 4 would give 0.025.
    $v = Goals::evaluateDay(
        ['calories' => 1800.0, 'protein' => 0.0, 'fat' => 0.0, 'carbs' => 0.0],
        ['calories' => 2000.0, 'protein' => 150.0, 'fat' => 60.0, 'carbs' => 200.0],
        ['calories' => ['mode' => 'at_least'], 'protein' => ['mode' => 'ignore'],
         'fat' => ['mode' => 'ignore'], 'carbs' => ['mode' => 'ignore']]
    );
    return abs($v['proximity'] - 0.1) < 0.0001
        ?: "expected 0.1, got {$v['proximity']} (averaged over 4 instead of 1?)";
});

echo "\n3. distance is directional\n";

t('at_least does not penalise going over', function () {
    $v = Goals::evaluateDay(['protein' => 300.0], ['protein' => 150.0],
        ['protein' => ['mode' => 'at_least']]);
    return $v['detail']['protein']['distance'] === 0.0 && $v['on_target'] === true
        ?: 'double protein should be distance 0';
});

t('at_most does not penalise going under', function () {
    $v = Goals::evaluateDay(['carbs' => 5.0], ['carbs' => 100.0],
        ['carbs' => ['mode' => 'at_most']]);
    return $v['detail']['carbs']['distance'] === 0.0 && $v['on_target'] === true
        ?: 'well under a carb cap should be distance 0';
});

t('within_pct penalises both directions', function () {
    $lo = Goals::evaluateDay(['fat' => 80.0], ['fat' => 100.0],
        ['fat' => ['mode' => 'within_pct', 'pct' => 0.05]]);
    $hi = Goals::evaluateDay(['fat' => 120.0], ['fat' => 100.0],
        ['fat' => ['mode' => 'within_pct', 'pct' => 0.05]]);
    return $lo['on_target'] === false && $hi['on_target'] === false
        && $lo['detail']['fat']['distance'] > 0 && $hi['detail']['fat']['distance'] > 0
        ?: 'a two-sided band must penalise both sides';
});

echo "\n4. short-but-ok (SPEC-safety.md §8)\n";

t('protein hit, calories under → flagged', function () {
    $v = Goals::evaluateDay(
        ['calories' => 1500.0, 'protein' => 130.0, 'fat' => 50.0, 'carbs' => 120.0],
        ['calories' => 2000.0, 'protein' => 120.0, 'fat' => 60.0, 'carbs' => 200.0],
        ['protein' => ['mode' => 'at_least'],
         'calories' => ['mode' => 'range_pct', 'lo' => 0.90, 'hi' => 1.05],
         'fat' => ['mode' => 'ignore'], 'carbs' => ['mode' => 'ignore']]
    );
    return $v['on_target'] === false && $v['short_but_ok'] === true
        ?: 'an honest short day with protein on target should be flagged';
});

t('calories OVER is not short-but-ok', function () {
    $v = Goals::evaluateDay(
        ['calories' => 2600.0, 'protein' => 130.0, 'fat' => 50.0, 'carbs' => 120.0],
        ['calories' => 2000.0, 'protein' => 120.0, 'fat' => 60.0, 'carbs' => 200.0],
        ['protein' => ['mode' => 'at_least'],
         'calories' => ['mode' => 'range_pct', 'lo' => 0.90, 'hi' => 1.05],
         'fat' => ['mode' => 'ignore'], 'carbs' => ['mode' => 'ignore']]
    );
    return $v['short_but_ok'] === false ?: 'overeating must not be softened';
});

t('protein missed too → not short-but-ok', function () {
    $v = Goals::evaluateDay(
        ['calories' => 1500.0, 'protein' => 60.0, 'fat' => 50.0, 'carbs' => 120.0],
        ['calories' => 2000.0, 'protein' => 120.0, 'fat' => 60.0, 'carbs' => 200.0],
        ['protein' => ['mode' => 'at_least'],
         'calories' => ['mode' => 'range_pct', 'lo' => 0.90, 'hi' => 1.05],
         'fat' => ['mode' => 'ignore'], 'carbs' => ['mode' => 'ignore']]
    );
    return $v['short_but_ok'] === false ?: 'missing protein as well is a real miss';
});

t('the building-intake preset keeps a short day ON target', function () {
    $g = Goals::preset('recomp-building-intake');
    if ($g === null) {
        return 'preset missing — did migration 005 run?';
    }
    // 82% of calories: fails a 0.90 floor, passes the 0.80 one.
    $v = Goals::evaluateDay(
        ['calories' => 1640.0, 'protein' => 130.0, 'fat' => 55.0, 'carbs' => 140.0],
        ['calories' => 2000.0, 'protein' => 120.0, 'fat' => 60.0, 'carbs' => 180.0],
        $g
    );
    return $v['on_target'] === true
        ?: 'the generous lower bound should keep this day on target';
});

echo "\n5. edge cases\n";

t('zero target is ignored, not failed', function () {
    $v = Goals::evaluateDay(['carbs' => 50.0], ['carbs' => 0.0],
        ['carbs' => ['mode' => 'at_most']]);
    return $v['on_target'] === null && in_array('carbs', $v['ignored'], true)
        ?: 'a missing target is our fault, not the user\'s';
});

t('missing actual counts as zero', function () {
    $v = Goals::evaluateDay([], ['protein' => 150.0],
        ['protein' => ['mode' => 'at_least']]);
    return $v['on_target'] === false ?: 'an empty day cannot be on target';
});

t('unknown mode fails loudly rather than passing', function () {
    $v = Goals::evaluateDay(['protein' => 200.0], ['protein' => 150.0],
        ['protein' => ['mode' => 'at_most_probably']]);
    return $v['on_target'] === false
        ?: 'a typo in a preset must not silently disable the rule';
});

t('countFailures matches the failure list', function () {
    $v = Goals::evaluateDay(
        ['calories' => 3000.0, 'protein' => 10.0, 'fat' => 200.0, 'carbs' => 400.0],
        ['calories' => 2000.0, 'protein' => 150.0, 'fat' => 60.0, 'carbs' => 100.0],
        ['calories' => ['mode' => 'range_pct', 'lo' => 0.9, 'hi' => 1.0],
         'protein' => ['mode' => 'at_least'],
         'fat' => ['mode' => 'within_pct', 'pct' => 0.05],
         'carbs' => ['mode' => 'at_most']]
    );
    return Goals::countFailures($v) === 4 ?: 'expected all four to fail';
});

echo "\n6. validation\n";

t('valid preset passes', fn() => Goals::validate([
    'protein' => ['mode' => 'at_least'],
    'calories' => ['mode' => 'range_pct', 'lo' => 0.9, 'hi' => 1.0],
]) === [] ?: 'a valid set should not error');

t('all-ignore is rejected', fn() => count(Goals::validate([
    'protein' => ['mode' => 'ignore'], 'calories' => ['mode' => 'ignore'],
])) > 0 ?: 'an unscoreable goal must be rejected at write time');

t('inverted range is rejected', fn() => count(Goals::validate([
    'calories' => ['mode' => 'range_pct', 'lo' => 1.2, 'hi' => 0.8],
])) > 0 ?: 'lo > hi must be caught');

t('unknown macro is rejected', fn() => count(Goals::validate([
    'protein' => ['mode' => 'at_least'], 'sodium' => ['mode' => 'at_most'],
])) > 0 ?: 'unknown macros must be caught');

echo "\n7. seeded presets\n";

foreach (['cut', 'recomp', 'recomp-building-intake', 'bulk', 'performance',
          'lower-carb', 'keto', 'general-health'] as $slug) {
    t("preset '{$slug}' exists and validates", function () use ($slug) {
        $g = Goals::preset($slug);
        if ($g === null) {
            return 'not found — did migration 005 run?';
        }
        $errs = Goals::validate($g);
        return $errs === [] ?: implode('; ', $errs);
    });
}

t("the seeded keto preset matches the original rule on every case",
    function () use ($cases, $target) {
        $g = Goals::preset('keto');
        if ($g === null) {
            return 'keto preset missing';
        }
        foreach ($cases as $label => $totals) {
            $mine = Goals::evaluateDay($totals, $target, $g)['on_target'];
            if ($mine !== originalKetoRule($totals, $target)) {
                return "diverged on '{$label}'";
            }
        }
        return true;
    });

echo "\n8. exercise library\n";

t('library is seeded', function () {
    $n = (int) (DB::one('SELECT COUNT(*) AS n FROM exercises')['n'] ?? 0);
    return $n >= 90 ?: "only {$n} exercises";
});

t('every pattern the generator needs is populated', function () {
    $needed = ['squat', 'hinge', 'horizontal_push', 'horizontal_pull',
               'vertical_push', 'vertical_pull', 'lunge', 'carry',
               'anti_rotation', 'anti_extension', 'cardio'];
    $missing = [];
    foreach ($needed as $p) {
        $n = (int) (DB::one('SELECT COUNT(*) AS n FROM exercises WHERE pattern = ?', [$p])['n'] ?? 0);
        if ($n === 0) {
            $missing[] = $p;
        }
    }
    return $missing === [] ?: 'no exercises for: ' . implode(', ', $missing);
});

t('aliases resolve to canonical rows', function () {
    foreach (['DB Bench' => 'db-bench-press',
              'OHP' => 'overhead-press',
              'Squat' => 'back-squat',
              'Farmers Walk' => 'farmer-carry'] as $alias => $slug) {
        $row = DB::one(
            'SELECT e.slug FROM exercise_aliases a
             JOIN exercises e ON e.id = a.exercise_id WHERE a.alias = ?',
            [$alias]
        );
        if (($row['slug'] ?? null) !== $slug) {
            return "'{$alias}' resolved to " . ($row['slug'] ?? 'nothing') . ", expected {$slug}";
        }
    }
    return true;
});

t('a bodyweight-only day has usable exercises', function () {
    // Proves the equipment JSON is queryable, which is what makes
    // "equipment not available" enforceable as a hard constraint.
    $n = (int) (DB::one(
        "SELECT COUNT(*) AS n FROM exercises
         WHERE JSON_LENGTH(equipment) = 0 AND category IN ('strength','core')"
    )['n'] ?? 0);
    return $n >= 6 ?: "only {$n} equipment-free strength/core exercises";
});

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
