<?php
declare(strict_types=1);

/**
 * Collapse the six duplicate pairs, and correct two mis-assigned muscles.
 *
 * NOT A PRUNE. A full prune was considered and rejected on the numbers: only 6 near-identical
 * pairs exist in 1008 exercises, and deleting all six would save ~24 tokens of 3,340. The
 * "49 bicep curls" that prompted the idea turned out to be variations — incline, preacher, drag,
 * spider — not duplicates, and the isolation cap had already taken that bucket from the largest
 * to the second-largest.
 *
 * ALIASED RATHER THAN DELETED. Same effect on the prompt, since an alias is not a library row
 * and never renders. But an exercise is also what a user logs against, and a deleted one breaks
 * that log permanently the first time somebody types the plural. exercise_aliases exists for
 * exactly this, and the losing name keeps resolving.
 *
 * The pairs are our original slugs colliding with the import's plurals: leg-extension against
 * leg-extensions. The singular wins, being both shorter and ours.
 *
 *   php bin/fix-duplicates.php            dry run
 *   php bin/fix-duplicates.php --commit   write
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$commit = in_array('--commit', array_slice($argv, 1), true);

/**
 * keep => drop.
 *
 * The kept slug is the shorter, plainer form in every case, which matches how the vocabulary
 * orders exercises for the isolation cap.
 */
const PAIRS = [
    'hammer-curl'         => 'hammer-curls',
    'kettlebell-swing'    => 'kettlebell-swings',
    'leg-extension'       => 'leg-extensions',
    'seated-cable-row'    => 'seated-cable-rows',
    'squat-with-bands'    => 'squats-with-bands',
    'standing-calf-raise' => 'standing-calf-raises',
];

/**
 * Muscles the backfill got wrong, spotted in the capped vocabulary output.
 *
 * cable-hip-adduction was filed under quadriceps: it is an adductor movement and the name says
 * so. balance-board was isolation/calves, which is a stretch in both senses — it is a balance
 * drill, so it moves to `other` with no isolation claim.
 */
const MUSCLE_FIXES = [
    'cable-hip-adduction' => ['muscle' => 'adductors', 'pattern' => null],
    'balance-board'       => ['muscle' => 'calves',    'pattern' => 'other'],
];

echo "=== duplicate pairs ===\n";
$actions = [];
foreach (PAIRS as $keep => $drop) {
    $k = DB::one('SELECT id, slug, name FROM exercises WHERE slug = ?', [$keep]);
    $d = DB::one('SELECT id, slug, name FROM exercises WHERE slug = ?', [$drop]);

    if ($k === null || $d === null) {
        printf("  SKIP  %s / %s — one of them is not in the library\n", $keep, $drop);
        continue;
    }

    /*
     * Anything already pointing at the loser has to move first.
     *
     * Both tables use ON DELETE RESTRICT, so a referenced row cannot be deleted — but this
     * REPOINTS rather than deletes, and a prescription or a logged set that referenced the
     * plural must end up on the singular or the history splits in two.
     */
    $refs = (int) (DB::one(
        'SELECT COUNT(*) AS n FROM prescribed_exercises WHERE exercise_id = ?', [$d['id']]
    )['n'] ?? 0)
    + (int) (DB::one(
        'SELECT COUNT(*) AS n FROM logged_exercises WHERE exercise_id = ?', [$d['id']]
    )['n'] ?? 0);

    printf("  %-22s <- %-24s (%d references to move)\n", $keep, $drop, $refs);
    $actions[] = ['keep' => $k, 'drop' => $d, 'refs' => $refs];
}

echo "\n=== muscle corrections ===\n";
foreach (MUSCLE_FIXES as $slug => $fix) {
    $e = DB::one('SELECT slug, primary_muscle, pattern FROM exercises WHERE slug = ?', [$slug]);
    if ($e === null) {
        printf("  SKIP  %s — not in the library\n", $slug);
        continue;
    }
    printf(
        "  %-22s muscle %s -> %s%s\n",
        $slug,
        (string) $e['primary_muscle'],
        $fix['muscle'],
        $fix['pattern'] === null
            ? ''
            : sprintf(", pattern %s -> %s", (string) $e['pattern'], $fix['pattern'])
    );
}

if (!$commit) {
    echo "\nDRY RUN — nothing written. Re-run with --commit.\n";
    exit(0);
}

$merged = 0;
$fixed  = 0;

DB::tx(function () use ($actions, &$merged, &$fixed): void {
    foreach ($actions as $a) {
        $keepId = (int) $a['keep']['id'];
        $dropId = (int) $a['drop']['id'];

        // Move anything that referenced the loser.
        DB::run(
            'UPDATE prescribed_exercises SET exercise_id = ? WHERE exercise_id = ?',
            [$keepId, $dropId]
        );
        DB::run(
            'UPDATE logged_exercises SET exercise_id = ? WHERE exercise_id = ?',
            [$keepId, $dropId]
        );

        /*
         * Repoint the loser's aliases before deleting it.
         *
         * exercise_aliases cascades on delete, so its rows would vanish with the exercise —
         * and those aliases are the display names people type. Moved first, then the loser's
         * own name and slug are added, so every way of writing it still resolves.
         */
        DB::run(
            'UPDATE IGNORE exercise_aliases SET exercise_id = ? WHERE exercise_id = ?',
            [$keepId, $dropId]
        );
        foreach ([(string) $a['drop']['name'], (string) $a['drop']['slug']] as $alias) {
            DB::run(
                'INSERT IGNORE INTO exercise_aliases (alias, exercise_id) VALUES (?, ?)',
                [$alias, $keepId]
            );
        }

        DB::run('DELETE FROM exercises WHERE id = ?', [$dropId]);
        $merged++;
    }

    foreach (MUSCLE_FIXES as $slug => $fix) {
        if ($fix['pattern'] === null) {
            DB::run(
                'UPDATE exercises SET primary_muscle = ? WHERE slug = ?',
                [$fix['muscle'], $slug]
            );
        } else {
            DB::run(
                'UPDATE exercises SET primary_muscle = ?, pattern = ? WHERE slug = ?',
                [$fix['muscle'], $fix['pattern'], $slug]
            );
        }
        $fixed++;
    }
});

printf("\nmerged %d pairs, corrected %d muscles\n", $merged, $fixed);

echo "\nverifying the losers still resolve:\n";
require_once YK_SRC . '/lib/PlanSchema.php';
foreach (PAIRS as $keep => $drop) {
    printf("  %-24s -> %s\n", $drop, PlanSchema::resolveSlug($drop) ?? 'UNRESOLVED');
}
