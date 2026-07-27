<?php
declare(strict_types=1);

/**
 * Fill in primary_muscle and secondary_muscles for the whole library.
 *
 * Two populations, matched differently:
 *
 *   THE 918 IMPORTED ROWS match the source file by name, because that is where their names came
 *   from. Straightforward.
 *
 *   THE 90 ORIGINALS mostly do not — the file says "Barbell Squat" where we say back-squat.
 *   They are matched through exercise_aliases first (the import seeded those), then by a
 *   normalised name, and anything left is assigned from its pattern, which is a decent proxy:
 *   a squat pattern works quadriceps whatever it is called.
 *
 * DRY RUN BY DEFAULT. A wrong muscle is not visible until a balance check reports nonsense or
 * the isolation cap keeps the wrong exercises, so the report comes first.
 *
 *   php bin/backfill-muscles.php            dry run
 *   php bin/backfill-muscles.php --commit   write
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$commit = in_array('--commit', array_slice($argv, 1), true);

$path = dirname(__DIR__) . '/exercises_categorized.json';
$rows = json_decode((string) file_get_contents($path), true);
if (!is_array($rows)) {
    exit("could not read exercises_categorized.json\n");
}

/** The source's muscle names to our ENUM. Only 'middle back' and 'lower back' differ. */
const MUSCLE = [
    'quadriceps'  => 'quadriceps',
    'hamstrings'  => 'hamstrings',
    'glutes'      => 'glutes',
    'calves'      => 'calves',
    'adductors'   => 'adductors',
    'abductors'   => 'abductors',
    'chest'       => 'chest',
    'lats'        => 'lats',
    'middle back' => 'middle_back',
    'lower back'  => 'lower_back',
    'traps'       => 'traps',
    'shoulders'   => 'shoulders',
    'biceps'      => 'biceps',
    'triceps'     => 'triceps',
    'forearms'    => 'forearms',
    'abdominals'  => 'abdominals',
    'neck'        => 'neck',
];

/**
 * The muscle a movement pattern mainly works.
 *
 * The fallback for our 90 originals when no name match exists. Coarse but defensible — a hinge
 * works the posterior chain whatever the implement — and far better than leaving them NULL,
 * which would exclude them from every muscle-aware feature.
 *
 * isolation is deliberately absent: it is the one pattern that says nothing about anatomy,
 * which is the whole reason this column exists.
 */
const PATTERN_MUSCLE = [
    'squat'                => 'quadriceps',
    'hinge'                => 'hamstrings',
    'lunge'                => 'quadriceps',
    'horizontal_push'      => 'chest',
    'vertical_push'        => 'shoulders',
    'horizontal_pull'      => 'middle_back',
    'vertical_pull'        => 'lats',
    'carry'                => 'forearms',
    'anti_rotation'        => 'abdominals',
    'anti_extension'       => 'abdominals',
    'anti_lateral_flexion' => 'abdominals',
    'flexion'              => 'abdominals',
    'extension'            => 'lower_back',
    'plyometric'           => 'quadriceps',
];

/**
 * Our original abbreviated slugs, which no name-match reaches.
 *
 * `db-curl` and `leg-extension` predate the import and the source calls them "Dumbbell Bicep
 * Curl" and "Leg Extensions". They are all isolation, which has no pattern fallback on purpose —
 * isolation is the one pattern that says nothing about anatomy, which is why this column exists.
 *
 * They matter more than their count suggests: these twelve are among the most commonly
 * prescribed accessories in the library, and the isolation cap works by muscle, so leaving them
 * NULL would drop them out of every capped vocabulary.
 */
const SLUG_MUSCLE = [
    'cable-curl'           => 'biceps',
    'db-curl'              => 'biceps',
    'hammer-curl'          => 'biceps',
    'cable-fly'            => 'chest',
    'cable-lateral-raise'  => 'shoulders',
    'db-lateral-raise'     => 'shoulders',
    'overhead-extension'   => 'triceps',
    'rope-pushdown'        => 'triceps',
    'leg-curl'             => 'hamstrings',
    'leg-extension'        => 'quadriceps',
    'standing-calf-raise'  => 'calves',
    'hip-abduction'        => 'abductors',
];

/** Normalise a name for matching, the same way the import did. */
function nameKey(string $s): string
{
    $s = strtolower($s);
    $s = preg_replace('/\b(alternate|alternating|the|with|a|an)\b/', ' ', $s) ?? $s;
    $s = preg_replace('/\bdb\b/', 'dumbbell', $s) ?? $s;
    return (string) preg_replace('/[^a-z0-9]+/', '', $s);
}

// Index the source by both exact and normalised name.
$byName = [];
$byKey  = [];
foreach ($rows as $r) {
    $n = (string) ($r['name'] ?? '');
    if ($n === '') {
        continue;
    }
    $byName[strtolower($n)] = $r;
    $byKey[nameKey($n)] ??= $r;
}

// Aliases give us a second route for the originals: the import seeded the source name as an
// alias of the canonical row.
$aliasTo = [];
foreach (DB::all(
    'SELECT a.alias, e.slug FROM exercise_aliases a JOIN exercises e ON e.id = a.exercise_id'
) as $a) {
    $aliasTo[(string) $a['slug']][] = (string) $a['alias'];
}

$plan   = [];
$counts = ['by_name' => 0, 'by_alias' => 0, 'by_key' => 0, 'by_pattern' => 0,
           'by_slug' => 0, 'none' => 0];

foreach (DB::all('SELECT id, slug, name, category, pattern FROM exercises') as $e) {
    $how = null;

    // 1. Exact name.
    $src = $byName[strtolower((string) $e['name'])] ?? null;
    if ($src !== null) {
        $how = 'by_name';
    }

    // 2. Any of its aliases.
    if ($src === null) {
        foreach ($aliasTo[(string) $e['slug']] ?? [] as $alias) {
            if (isset($byName[strtolower($alias)])) {
                $src = $byName[strtolower($alias)];
                $how = 'by_alias';
                break;
            }
        }
    }

    // 3. Normalised name.
    if ($src === null) {
        $src = $byKey[nameKey((string) $e['name'])] ?? null;
        if ($src !== null) {
            $how = 'by_key';
        }
    }

    $primary   = null;
    $secondary = [];

    if ($src !== null) {
        $p = strtolower((string) ($src['primary_muscles'][0] ?? ''));
        $primary = MUSCLE[$p] ?? null;
        foreach ($src['secondary_muscles'] ?? [] as $s) {
            $mapped = MUSCLE[strtolower((string) $s)] ?? null;
            if ($mapped !== null) {
                $secondary[] = $mapped;
            }
        }
    }

    /*
     * Fall back to the pattern, but ONLY for real exercises.
     *
     * An activity — golf, kayaking — has no primary muscle and inventing one would be worse
     * than NULL: it would put "Billiards" into a quadriceps balance check.
     */
    if ($primary === null && $e['category'] !== 'activity') {
        $primary = PATTERN_MUSCLE[(string) $e['pattern']] ?? null;
        if ($primary !== null) {
            $how = 'by_pattern';
        }
    }

    // Our own abbreviated slugs, which nothing above reaches.
    if ($primary === null && isset(SLUG_MUSCLE[(string) $e['slug']])) {
        $primary = SLUG_MUSCLE[(string) $e['slug']];
        $how = 'by_slug';
    }

    if ($primary === null) {
        $counts['none']++;
    } else {
        $counts[$how]++;
    }

    $plan[] = [
        'id'        => (int) $e['id'],
        'slug'      => (string) $e['slug'],
        'category'  => (string) $e['category'],
        'pattern'   => (string) $e['pattern'],
        'primary'   => $primary,
        'secondary' => array_values(array_unique($secondary)),
        'how'       => $how,
    ];
}

printf("%d exercises\n\n", count($plan));
printf("matched by name     %4d\n", $counts['by_name']);
printf("matched by alias    %4d\n", $counts['by_alias']);
printf("matched by key      %4d\n", $counts['by_key']);
printf("assigned by pattern %4d\n", $counts['by_pattern']);
printf("assigned by slug    %4d\n", $counts['by_slug']);
printf("left NULL           %4d\n", $counts['none']);

echo "\n=== resulting muscle distribution ===\n";
$dist = [];
foreach ($plan as $p) {
    $dist[$p['primary'] ?? '(null)'] = ($dist[$p['primary'] ?? '(null)'] ?? 0) + 1;
}
arsort($dist);
foreach ($dist as $m => $n) {
    printf("  %-14s %4d\n", $m, $n);
}

echo "\n=== what stayed NULL, by category (should be activities only) ===\n";
$nullCat = [];
foreach ($plan as $p) {
    if ($p['primary'] === null) {
        $nullCat[$p['category']] = ($nullCat[$p['category']] ?? 0) + 1;
    }
}
foreach ($nullCat as $c => $n) {
    printf("  %-10s %d\n", $c, $n);
}

echo "\n=== isolation, by muscle — the reason this exists ===\n";
$iso = [];
foreach ($plan as $p) {
    if ($p['pattern'] === 'isolation') {
        $iso[$p['primary'] ?? '(null)'] = ($iso[$p['primary'] ?? '(null)'] ?? 0) + 1;
    }
}
arsort($iso);
foreach ($iso as $m => $n) {
    printf("  %-14s %3d\n", $m, $n);
}

if (!$commit) {
    echo "\nDRY RUN — nothing written. Re-run with --commit.\n";
    exit(0);
}

$written = 0;
DB::tx(function () use ($plan, &$written): void {
    foreach ($plan as $p) {
        DB::run(
            'UPDATE exercises SET primary_muscle = ?, secondary_muscles = ? WHERE id = ?',
            [$p['primary'], json_encode($p['secondary']), $p['id']]
        );
        $written++;
    }
});
printf("\nwrote %d rows\n", $written);
