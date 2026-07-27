<?php
declare(strict_types=1);

/**
 * Import exercises_categorized.json into the exercise library.
 *
 * DRY RUN BY DEFAULT. Writes nothing until --commit, and prints an ambiguities report first,
 * because the two ways this can go wrong are both silent:
 *
 *   1. ORPHANING LOAD HISTORY. logged_exercises and prescribed_exercises reference exercises.id
 *      with ON DELETE RESTRICT. Our 90 rows mostly do not name-match the file — it has "Barbell
 *      Squat" where we have back-squat — so importing blind would leave the model prescribing
 *      barbell-squat while every logged set sits under back-squat. Progression data detaches and
 *      nothing reports it. Existing rows are therefore CANONICAL: a file entry that matches one
 *      becomes an alias, never a new row.
 *
 *   2. A WRONG EQUIPMENT ARRAY. The home-gym filter reads it. A bad value does not fail a test,
 *      it hands somebody a squat rack they do not own — exactly the bug just fixed. Their
 *      vocabulary is 13 coarse values against our 32 fine ones, so anything uncertain is marked
 *      gym-only rather than guessed home-friendly. Under-offering is recoverable.
 *
 *   php bin/import-exercises.php              dry run, with the ambiguities report
 *   php bin/import-exercises.php --report     the ambiguities report only, in full
 *   php bin/import-exercises.php --commit     actually write
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$args    = array_slice($argv, 1);
$commit  = in_array('--commit', $args, true);
$reportOnly = in_array('--report', $args, true);

$path = dirname(__DIR__) . '/exercises_categorized.json';
if (!is_file($path)) {
    exit("exercises_categorized.json not found at {$path}\n");
}
$rows = json_decode((string) file_get_contents($path), true);
if (!is_array($rows)) {
    exit("could not parse the JSON\n");
}
printf("read %d rows from exercises_categorized.json\n\n", count($rows));

// ---------------------------------------------------------------------------
// Mapping tables
// ---------------------------------------------------------------------------

/** Their five categories to ours. */
const CATEGORY = [
    'Strength Training'   => 'strength',
    'Core Strength'       => 'core',
    'Cardio'              => 'cardio',
    'Stretching/Mobility' => 'mobility',
    'General Fitness'     => 'activity',
];

/**
 * Their equipment vocabulary to ours.
 *
 * null means "cannot be decided from this field alone" and sends the row to the ambiguities
 * report. 'other' and the blank value are both genuine grab-bags: blank is mostly bodyweight
 * and stretching, 'other' runs from Ab Roller to Atlas Stones.
 */
const EQUIPMENT = [
    'body only'     => [],
    'barbell'       => ['barbell'],
    'dumbbell'      => ['dumbbell'],
    'kettlebells'   => ['kettlebell'],
    'cable'         => ['cable_tower'],
    'bands'         => ['resistance_band'],
    'medicine ball' => ['medicine_ball'],
    'exercise ball' => ['exercise_ball'],
    'foam roll'     => ['foam_roller'],
    'e-z curl bar'  => ['barbell'],
    // Genuinely undecidable from the field: our library splits machines four ways
    // (selectorized, plate_loaded, hack_squat, leg_press) and they do not.
    'machine'       => null,
    'other'         => null,
    ''              => null,
];

/**
 * Their force/mechanic/name to our movement pattern.
 *
 * Pattern is required and NOT in the source, so it is inferred from the name. Anything that
 * cannot be inferred confidently goes to the report rather than being guessed — a wrong pattern
 * puts a row in the wrong place in the vocabulary, which is how a "vertical pull" day ends up
 * prescribing a curl.
 */
const PATTERN_HINTS = [
    /*
     * Plyometrics first, so a "squat jump" is a jump rather than a squat.
     *
     * They earned their own pattern (migration 020) because the buddy skeleton shares the
     * PATTERN between two users and lets each pick a variant. A pair told "squat" where one
     * back squats and the other does a depth jump are not training together in any useful
     * sense — different stimulus, different landing risk, different progression.
     */
    'plyometric'            => ['jump', 'hop', 'bound', 'box skip', 'plyo', 'depth ',
                                'skater', 'burpee', 'leap'],
    'squat'                 => ['squat', 'leg press', 'hack ', 'sissy'],
    'hinge'                 => ['deadlift', 'good morning', 'hip thrust', 'swing', 'romanian',
                                'glute bridge', 'back extension', 'hyperextension', 'pull-through'],
    'lunge'                 => ['lunge', 'split squat', 'step-up', 'step up'],
    'horizontal_push'       => ['bench press', 'push-up', 'push up', 'chest press', 'dip',
                                'chest fly', 'pec deck', 'floor press', 'decline press'],
    'vertical_push'         => ['overhead press', 'shoulder press', 'military press',
                                'handstand', 'push press', 'arnold'],
    // 'rear delt' is NOT here: a rear delt raise or fly is accessory shoulder work, not a row.
    // It sat in horizontal_pull and the audit flagged four of them.
    'horizontal_pull'       => ['row', 'face pull', 'inverted'],
    'vertical_pull'         => ['pull-up', 'pull up', 'chin-up', 'chin up', 'pulldown',
                                'pull-down', 'lat pull'],
    'carry'                 => ['carry', 'farmer', 'suitcase', 'yoke walk'],
    'anti_rotation'         => ['pallof', 'woodchop', 'wood chop', 'russian twist', 'bird dog',
                                'bird-dog'],
    'anti_extension'        => ['plank', 'ab wheel', 'rollout', 'dead bug', 'dead-bug',
                                'hollow'],
    'anti_lateral_flexion'  => ['side plank', 'side bend'],
    'flexion'               => ['crunch', 'sit-up', 'sit up', 'knee raise', 'leg raise',
                                'v-up', 'toe touch', 'mountain climber'],
    'extension'             => ['back extension', 'superman'],
    /*
     * Named isolation work the muscle+force fallback misses.
     *
     * Rotator cuff work (internal/external rotation), pullovers and lateral/front raises are
     * accessories whatever their implement. Listed explicitly because the source marks several
     * of them mechanic=compound, which sends them down the wrong branch.
     */
    'isolation'             => ['pullover', 'internal rotation', 'external rotation',
                                'rotator', 'chest squeeze', 'front raise', 'lateral raise',
                                'rear delt', 'back flye', 'hip flexion', 'shrug',
                                'dumbbell raise', 'shoulder raise', 'glute ham raise',
                                'calf press', 'lower back curl', 'bent press'],
];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Our slug convention: lowercase, hyphenated, no punctuation. */
function toSlug(string $name): string
{
    $s = strtolower(trim($name));
    $s = str_replace(['/', '&'], ['-', '-and-'], $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
    return trim((string) $s, '-');
}

/**
 * A key for matching two names that mean the SAME movement.
 *
 * DELIBERATELY CONSERVATIVE, because a false match is the expensive error: aliasing "Barbell
 * Curl" onto leg-curl would send every bicep set into the leg-curl load history and nothing
 * would report it. A missed match only creates a duplicate row, which is visible and fixable.
 *
 * The first version stripped the implement AND the body part — barbell, dumbbell, arm, leg,
 * seated, standing — which collapsed "Barbell Curl" and "Leg Curl" to the same key, along with
 * "Dumbbell Bench Press" onto barbell-bench-press and seated calf raises onto standing ones.
 * Every one of those is a different exercise with its own load history.
 *
 * So only pure noise is dropped: articles, "alternate", "alternating". The implement stays,
 * because a dumbbell press and a barbell press are not interchangeable, and the position stays,
 * because seated and standing are different movements.
 */
function matchKey(string $name): string
{
    $s = strtolower($name);
    $s = preg_replace('/\b(alternate|alternating|the|with|a|an)\b/', ' ', $s) ?? $s;
    // db is our abbreviation for dumbbell; normalise so the two conventions meet.
    $s = preg_replace('/\bdb\b/', 'dumbbell', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9]+/', '', $s) ?? $s;
    return (string) $s;
}

/**
 * Infer a movement pattern, or null when it genuinely cannot be told.
 *
 * THREE LAYERS, most specific first. The name is the best signal when it contains a known
 * movement word. Failing that, the source's own muscle + force + mechanic columns settle most
 * of the rest — biceps/pull/isolation is an isolation movement whatever it is called, and
 * chest/push/compound is a horizontal push.
 *
 * The fallback exists because the name-hint layer alone left 322 rows unresolved, which is not
 * a review list, it is a transcription job. What survives all three layers is real ambiguity
 * worth a human decision.
 */
function inferPattern(string $name, string $category, array $row = []): ?string
{
    $n = strtolower($name);

    // Longest hints first, so "side plank" beats "plank" and "back extension" beats "extension".
    $best = null;
    $bestLen = 0;
    foreach (PATTERN_HINTS as $pattern => $hints) {
        foreach ($hints as $hint) {
            if (str_contains($n, $hint) && strlen($hint) > $bestLen) {
                $best = $pattern;
                $bestLen = strlen($hint);
            }
        }
    }
    if ($best !== null) {
        return $best;
    }

    // Categories with a single sensible default.
    $byCategory = match ($category) {
        'cardio', 'activity' => 'cardio',
        'mobility'           => 'other',
        default              => null,
    };
    if ($byCategory !== null) {
        return $byCategory;
    }

    /*
     * Muscle + force, for strength and core.
     *
     * Isolation work is the bulk of what reaches here: curls, raises, extensions, shrugs. The
     * source's own `mechanic` column says isolation outright, and our library has an
     * 'isolation' pattern for exactly this, so trusting it is not a guess.
     */
    $muscle   = strtolower((string) ($row['primary_muscles'][0] ?? ''));
    $force    = strtolower((string) ($row['force'] ?? ''));
    $mechanic = strtolower((string) ($row['mechanic'] ?? ''));

    if ($category === 'core') {
        // Core patterns are about resisting motion, which the source does not model. Flexion is
        // the honest default for a named ab movement; the anti_* patterns need the name, and
        // those are caught by the hints above.
        return 'flexion';
    }

    if ($mechanic === 'isolation') {
        return 'isolation';
    }

    return match (true) {
        $muscle === 'chest'       && $force === 'push' => 'horizontal_push',
        $muscle === 'shoulders'   && $force === 'push' => 'vertical_push',
        $muscle === 'triceps'                          => 'isolation',
        $muscle === 'biceps'                           => 'isolation',
        $muscle === 'forearms'                         => 'isolation',
        $muscle === 'calves'                           => 'isolation',
        $muscle === 'traps'                            => 'isolation',
        $muscle === 'neck'                             => 'isolation',
        $muscle === 'abductors' || $muscle === 'adductors' => 'isolation',
        $muscle === 'lats'        && $force === 'pull' => 'vertical_pull',
        $muscle === 'middle back' && $force === 'pull' => 'horizontal_pull',
        $muscle === 'lower back'                       => 'hinge',
        $muscle === 'hamstrings'  && $force === 'pull' => 'hinge',
        $muscle === 'glutes'                           => 'hinge',
        $muscle === 'quadriceps'  && $force === 'push' => 'squat',
        default => null,
    };
}

/**
 * Movements deliberately left out of the library, and why.
 *
 * STRONGMAN. Atlas stones, tyre flips, sled work, yokes, kegs, log lifts. They need equipment
 * essentially nobody has, they are a specialist discipline rather than general training, and
 * the app's own users are a 52-year-old post-MI diabetic and a 21-year-old doing a recomp.
 * Prescribing a tyre flip to either is a liability, not a gap.
 *
 * OBSCURE ONE-OFFS. A wrist roller and a circus bell are real, but each needs a specific toy
 * and unlocks one exercise. They cost a vocabulary line on every generation and would be picked
 * approximately never.
 *
 * COMPENDIUM COMPOSITES. "Calisthenics (pushups, situps, pullups, lunges), moderate" is a MET
 * aggregate for calorie estimation, not an exercise a coach can prescribe.
 *
 * Matched on a lowercase substring of the name.
 */
const SKIP_NAMES = [
    // Strongman.
    'atlas stone', 'tire flip', 'keg ', 'sandbag', 'yoke walk', 'log lift', 'sled ',
    'sled-', 'backward drag', 'forward drag', 'bear crawl sled', 'car deadlift',
    'conan', 'rickshaw', 'power stairs', 'axle deadlift', 'chain press',
    'chain handle', 'heavy bag thrust', 'sledgehammer',
    // Obscure one-offs.
    'wrist roller', 'circus bell', 'london bridges', 'otis-up',

    /*
     * OLYMPIC LIFTS. Snatch, clean, and their variants.
     *
     * They need coaching to perform safely and do not fit any pattern we model — a
     * hinge-into-catch is not a hinge. Same judgement as strongman: the app's users are a
     * 52-year-old post-MI diabetic and a beginner, and an app that prescribes a snatch to
     * someone who has never done one is a hazard rather than a gap in the library.
     */
    'snatch', 'clean pull', 'hang clean', 'power clean', 'split clean', 'muscle clean',
    'clean from', 'clean and', 'rack delivery', 'kettlebell clean', 'jerk',

    /*
     * SPRINT AND TRACK DRILLS. Wall drills, claw series, arm drills.
     *
     * Specialist coaching cues rather than prescribable sessions, and no pattern fits.
     */
    'wall drill', 'claw series', 'arm drill', '3-part start', 'acceleration',
    'wind sprint', 'bench sprint', 'single-cone sprint',
    /*
     * Named as a compound of several movements, so no single pattern or dose fits.
     * "HIIT: burpees, mountain climbers, squat jumps" is a session, not an exercise.
     */
    'hiit:',
    // "Bodyweight Flyes" is a mis-titled barbell exercise in the source; the name and the
    // equipment contradict each other and there is no way to tell which is right.
    'bodyweight flyes',

    /*
     * COMPENDIUM AGGREGATES. MET entries for calorie estimation, not exercises.
     *
     * "Circuit training, moderate effort" and "Weight lifting, power lifting or body building,
     * vigorous" describe a session's intensity, not a movement a coach can prescribe.
     */
    'calisthenics (', 'circuit training', 'weight lifting, power lifting',
    'resistance training, multiple', 'body weight resistance exercises',
    'resistance training, squats', 'pilates, general',
];

/** Should this row be left out entirely? */
function isSkipped(string $name): bool
{
    $n = strtolower($name);
    foreach (SKIP_NAMES as $needle) {
        if (str_contains($n, $needle)) {
            return true;
        }
    }
    return false;
}

/**
 * Second pass at equipment, for the values the table cannot decide.
 *
 * BIASED TOWARDS GYM-ONLY. A wrong value here does not fail a test — it hands a home-gym user
 * a machine they do not own, which is the exact bug the home kit was built to fix. Marking
 * something gym-only when it was performable at home costs one missing exercise out of
 * hundreds; the other direction costs a plan the user cannot do.
 *
 * Returns null when even that cannot be said, and the row goes to the report.
 */
function inferEquipment(
    string $name,
    string $srcEq,
    string $category,
    ?string $pattern = null
): ?array {
    $n = strtolower($name);

    /*
     * Named implements, longest and most specific first.
     *
     * Order matters: 'lat pulldown' has to beat 'pulldown', and 'band assisted' has to beat
     * 'assisted', or the more general rule swallows the specific one.
     */
    /*
     * "Machine" anywhere in the name means a gym machine, and that beats everything below.
     *
     * Checked first because the specific-implement rules would otherwise claim it: "Machine
     * Bench Press" would match 'bench', "Calf-Machine Shoulder Shrug" would match 'shrug'. Both
     * would then be performable in a home gym that owns a bench, which is wrong.
     *
     * Named machines are still resolved specifically below — this only catches the generic
     * ones, and selectorized is the safe default since every machine token is gym-only anyway.
     */
    if (preg_match('/\bmachine/', $n) === 1
        && preg_match('/\b(smith|leg press|hack|leverage|assisted|cable|rowing|ski)\b/', $n) !== 1) {
        return ['selectorized'];
    }

    foreach ([
        // Machines, which is most of the 'machine' bucket.
        'leg press'      => ['leg_press'],
        // A barbell hack squat is done with a bar behind the legs, not on the machine — but a
        // plain "hack squat" is the machine. Checked in that order.
        'barbell hack'   => ['barbell'],
        'hack squat'     => ['hack_squat'],
        'smith'          => ['smith_machine'],
        'ski machine'    => ['elliptical'],
        'treadmill'      => ['treadmill'],
        'elliptical'     => ['elliptical'],
        // Rowing before 'stationary', or "Rowing, Stationary" becomes an exercise bike.
        'rowing'         => ['rower'],
        'ergometer'      => ['rower'],
        'stationary'     => ['upright_bike'],
        'lat pulldown'   => ['selectorized'],
        'pulldown'       => ['selectorized'],
        'leverage'       => ['plate_loaded'],
        'band assisted'  => ['resistance_band', 'pull_up_bar'],
        'assisted'       => ['assisted_machine'],
        'cable'          => ['cable_tower'],

        // Bars and frames.
        'parallel bars'  => ['dip_station'],
        'trap bar'       => ['trap_bar'],
        'hyperextension' => ['back_ext_bench'],
        'back extension' => ['back_ext_bench'],
        'pull-up'        => ['pull_up_bar'],
        'pull up'        => ['pull_up_bar'],
        'chin-up'        => ['pull_up_bar'],
        'chin up'        => ['pull_up_bar'],
        'chins'          => ['pull_up_bar'],
        'muscle up'      => ['pull_up_bar'],
        'muscle-up'      => ['pull_up_bar'],
        'hang'           => ['pull_up_bar'],

        // Straps and small kit.
        'with straps'    => ['trx'],
        'suspended'      => ['trx'],
        'trx'            => ['trx'],
        'suspension'     => ['trx'],
        'ab wheel'       => ['ab_wheel'],
        'ab roller'      => ['ab_wheel'],
        '-smr'           => ['foam_roller'],
        'foam roll'      => ['foam_roller'],
        'stability ball' => ['exercise_ball'],
        'swiss ball'     => ['exercise_ball'],
        'bosu'           => ['exercise_ball'],
        // Kept as full-gym: most gyms have one, few homes do.
        'balance board'  => ['balance_board'],
        'band'           => ['resistance_band'],
        'head harness'   => ['head_harness'],

        /*
         * Plates, boxes, ropes and rings — the last of the 'other' bucket.
         *
         * A loose plate is the one genuinely home-friendly item here, and it is already a token
         * our library uses. The rest are gym fixtures: a plyo box, a climbing rope, gymnastic
         * rings, a dip station.
         */
        'plate'          => ['plate'],
        'box jump'       => ['plyo_box'],
        'box squat'      => ['plyo_box'],
        'box shuffle'    => ['plyo_box'],
        'depth jump'     => ['plyo_box'],
        'step-up'        => ['plyo_box'],
        'push-off'       => ['plyo_box'],
        'rope climb'     => ['climbing_rope'],
        'rope jumping'   => ['jump_rope'],
        'jump rope'      => ['jump_rope'],
        'ring '          => ['gymnastic_rings'],
        'dip'            => ['dip_station'],
        'prowler'        => ['plate_loaded'],
        'hurdle'         => ['hurdle'],
        'cone'           => ['cone'],
        'slides'         => ['furniture_sliders'],
        'chin'           => ['pull_up_bar'],
    ] as $needle => $tokens) {
        /*
         * WORD-BOUNDARY MATCHING, not str_contains.
         *
         * "chin" matched "Ma-CHIN-e" and sent nine gym machines to pull_up_bar — Machine Bench
         * Press, Machine Bicep Curl, Ab Crunch Machine — every one of which would then have
         * been offered to a home user who owns a pull-up bar. That is precisely the bug the
         * home kit exists to prevent, reintroduced by a lazy substring test.
         *
         * The audit caught it; reading the sample did not, because the sample showed the
         * pattern column and this is wrong in the equipment column.
         */
        $pattern_re = '/\b' . preg_quote($needle, '/') . '/';
        if (preg_match($pattern_re, $n) === 1) {
            return $tokens;
        }
    }

    /*
     * Blank equipment on a stretch, a cardio activity or general fitness is genuinely nothing.
     *
     * These are the 71 stretches, 32 cardio entries and 112 compendium activities. "Ankle
     * Circles" and "Walking the dog" need no equipment, and the source left the field empty
     * because there is nothing to name rather than because it did not know.
     */
    if ($srcEq === '' && in_array($category, ['mobility', 'cardio', 'activity'], true)) {
        return [];
    }

    /*
     * An unnamed machine defaults to selectorized.
     *
     * The commonest gym machine type, and the choice is low-stakes: every machine token is
     * gym-only, so whichever we pick, the home filter excludes it either way. Getting
     * plate_loaded against selectorized wrong affects nothing a user can see.
     */
    if ($srcEq === 'machine') {
        return ['selectorized'];
    }

    /*
     * A stretch or mobility drill with no named implement needs nothing.
     *
     * The source uses 'other' for these when it has no better label — "Lying Hamstring",
     * "Overhead Lat" — and they are floor stretches.
     */
    if ($category === 'mobility') {
        return [];
    }

    /*
     * A plyometric with no named implement is a jump on the floor.
     *
     * "Quick Leap", "Side Hop-Sprint", "Single-Leg Lateral Hop" need nothing. The ones that DO
     * need a box, hurdle or cone say so in the name and were caught above, so reaching here
     * means there is nothing to stand on or jump over.
     */
    if ($pattern === 'plyometric') {
        return [];
    }

    /*
     * The last handful, named individually.
     *
     * Each is a real exercise the generic rules could not place. Listed here rather than
     * loosened into a rule, because every loosening so far has caught something it should not
     * have — this is the tail, and the tail is where a broad rule does the most damage.
     */
    $named = [
        'farmer'          => ['dumbbell'],          // or a trap bar, but dumbbells are the common case
        'svend press'     => ['plate'],
        'weighted squat'  => ['dumbbell'],
        'carioca'         => [],                    // an agility shuffle, needs nothing
        'inverted row'    => ['barbell', 'rack'],   // set up under a bar
        'glute-ham raise' => [],                    // floor version; the machine one says GHD
        'manual hamstring' => [],                   // needs a partner, not equipment
        'dumbbell raise'  => ['dumbbell'],
        'decline press'   => ['smith_machine'],     // Smith Machine Decline Press
        'pilates'         => [],
        'kettlebell swing' => ['kettlebell'],
        'skating'         => [],                    // the cardio activity, not the machine
        'battling rope'   => ['battle_ropes'],
        'off of a dumbbell' => ['dumbbell'],   // a push-up with one hand on a dumbbell
        'bench sprint'    => ['bench'],             // step-ups onto a bench, at speed
        'bicycling'       => ['upright_bike'],      // the indoor entry; outdoor is an activity
        'mid row'         => [],                    // bodyweight row on the floor
        'crucifix'        => ['plate'],             // holding plates out to the sides
        'donkey calf'     => [],                    // bodyweight, partner on the back
        'drop push'       => [],                    // an explosive push-up
        'climber'         => [],                    // "Mountain Climbers", plural
    ];
    foreach ($named as $needle => $tokens) {
        if (str_contains($n, $needle)) {
            return $tokens;
        }
    }

    /*
     * A bodyweight-named strength movement needs nothing.
     *
     * "Bodyweight Walking Lunge", "Decline Push-Up", "Mountain Climbers". The source left the
     * field blank rather than writing "body only", which is an omission on its part.
     */
    if ($srcEq === '' && $category === 'strength'
        && preg_match('/\b(bodyweight|push-up|push up|climber|lunge)\b/', $n) === 1) {
        return [];
    }

    // Anything still here is genuinely undecided and goes to the report.
    return null;
}

/**
 * How this exercise is loaded, which decides what the log screen asks for.
 *
 * Getting it wrong is visible immediately: a plank that asks for kilos, or a bench press with
 * no weight field. The schema's own comment calls it "which log columns are meaningful".
 *
 * Inferred from the pattern and equipment rather than the source, which has no equivalent
 * column. Conservative order: timed and distance work first, since those are the ones that look
 * absurd when mislabelled, then bodyweight, then weight as the default.
 */
function loadTypeFor(array $c): string
{
    $n = strtolower($c['name']);
    $eq = $c['equipment'];

    // Timed holds and stretches: seconds, not reps or load.
    if (preg_match('/\b(plank|hold|hang|stretch|isometric|wall sit|dead bug|bird dog)\b/', $n) === 1
        || $c['category'] === 'mobility') {
        return 'time';
    }

    // Carries and cardio: measured in distance or duration.
    if ($c['pattern'] === 'carry' || preg_match('/\b(carry|walk|sprint|run)\b/', $n) === 1) {
        return 'distance';
    }
    if ($c['category'] === 'cardio' || $c['category'] === 'activity') {
        return 'time';
    }

    // Assisted machines and bands take load off rather than adding it.
    if (in_array('assisted_machine', $eq, true)) {
        return 'assisted';
    }

    // Nothing to load it with.
    if ($eq === [] || $eq === ['pull_up_bar'] || $eq === ['dip_station']
        || $eq === ['trx'] || $eq === ['gymnastic_rings']) {
        return 'bodyweight';
    }

    return 'weight';
}

// ---------------------------------------------------------------------------
// Load what we already have — existing rows are CANONICAL
// ---------------------------------------------------------------------------

$existing = [];
$byMatch  = [];
foreach (DB::all('SELECT id, slug, name, category, pattern, equipment FROM exercises') as $e) {
    $existing[(string) $e['slug']] = $e;
    $byMatch[matchKey((string) $e['name'])][] = $e;
    // Our slugs are often abbreviated ("db-row"), so match on the slug's words too.
    $byMatch[matchKey(str_replace('-', ' ', (string) $e['slug']))][] = $e;
}
printf("%d exercises already in the library, all treated as canonical\n\n", count($existing));

$aliasesKnown = [];
foreach (DB::all('SELECT alias FROM exercise_aliases') as $a) {
    $aliasesKnown[strtolower((string) $a['alias'])] = true;
}

// ---------------------------------------------------------------------------
// Classify every row
// ---------------------------------------------------------------------------

$plan = [
    'merge'      => [],   // matches an existing row: becomes an alias
    'create'     => [],   // new, and confidently mapped
    'ambiguous'  => [],   // needs a human decision
    'skip'       => [],   // already present by slug
];

$slugsSeen = [];

foreach ($rows as $r) {
    $name = trim((string) ($r['name'] ?? ''));
    if ($name === '') {
        continue;
    }
    // Deliberately excluded: strongman, obscure one-offs, compendium composites.
    if (isSkipped($name)) {
        $plan['skip'][] = ['name' => $name, 'why' => 'excluded by decision'];
        continue;
    }

    $igd = (string) ($r['igd_category'] ?? '');
    $category = CATEGORY[$igd] ?? null;
    if ($category === null) {
        $plan['ambiguous'][] = ['name' => $name, 'why' => "unmapped category '{$igd}'"];
        continue;
    }

    /*
     * The source files heavy hinges under Core Strength, and they are not core work.
     *
     * Barbell Deadlift, Deficit Deadlift, Stiff Leg Good Morning — all tagged Core Strength
     * because the lower back is the primary mover. That is anatomically fair and programmatically
     * wrong: §3.3b puts 8-12 minutes of core work on every strength day, and filling it with a
     * deadlift is a different session from the one intended.
     *
     * The primary muscle separates them cleanly: 85 of the 103 Core Strength rows are
     * abdominals and are genuine core; the 16 lower-back ones are strength hinges. Checked as a
     * rule rather than a name list because the rule is the actual distinction.
     */
    $primary = strtolower((string) ($r['primary_muscles'][0] ?? ''));
    if ($category === 'core' && $primary === 'lower back') {
        $category = 'strength';
    }

    $slug = toSlug($name);

    // Duplicate names within the file itself.
    if (isset($slugsSeen[$slug])) {
        $plan['skip'][] = ['name' => $name, 'why' => 'duplicate slug within the file'];
        continue;
    }
    $slugsSeen[$slug] = true;

    // Already ours, by slug.
    if (isset($existing[$slug])) {
        $plan['skip'][] = ['name' => $name, 'why' => "slug {$slug} already exists"];
        continue;
    }

    // Matches an existing row by meaning: alias it rather than duplicating the movement.
    $key = matchKey($name);
    if (isset($byMatch[$key])) {
        $target = $byMatch[$key][0];
        $plan['merge'][] = [
            'name'   => $name,
            'slug'   => $slug,
            'onto'   => (string) $target['slug'],
            'onto_name' => (string) $target['name'],
            'same_category' => ((string) $target['category']) === $category,
        ];
        continue;
    }

    // Pattern first: the equipment fallback reads it (a plyometric with no named box needs
    // nothing but floor).
    $pattern = inferPattern($name, $category, $r);

    $srcEq = (string) ($r['equipment'] ?? '');
    $eq = array_key_exists($srcEq, EQUIPMENT) ? EQUIPMENT[$srcEq] : null;
    if ($eq === null) {
        $eq = inferEquipment($name, $srcEq, $category, $pattern);
    }

    /*
     * The name overrules the source's equipment field when the two disagree about a MACHINE.
     *
     * The source records "Smith Incline Shoulder Raise" as equipment=barbell, which is true in
     * the sense that a Smith machine holds a bar, and false in the sense that matters: a home
     * gym with a barbell has no Smith machine. Left alone, that row becomes home-performable.
     *
     * Only applied to machine names, and only in the restrictive direction. The reverse — the
     * name says bodyweight and the source names an implement — is left to the audit, because
     * widening access is the error that reaches a user.
     */
    if ($eq !== null && preg_match('/\b(smith|leverage)\b/i', $name) === 1) {
        $eq = preg_match('/\bsmith\b/i', $name) === 1
            ? ['smith_machine']
            : ['plate_loaded'];
    }

    $why = [];
    if ($eq === null) {
        $why[] = "equipment '{$srcEq}'";
    }
    if ($pattern === null) {
        $why[] = 'pattern not inferable';
    }

    if ($why !== []) {
        $plan['ambiguous'][] = [
            'name'     => $name,
            'slug'     => $slug,
            'category' => $category,
            'src_eq'   => $srcEq,
            'pattern'  => $pattern,
            'muscles'  => implode(', ', $r['primary_muscles'] ?? []),
            'why'      => implode(' + ', $why),
        ];
        continue;
    }

    $plan['create'][] = [
        'name'      => $name,
        'slug'      => $slug,
        'category'  => $category,
        'pattern'   => $pattern,
        'equipment' => $eq,
        'demo'      => $r['image_urls'][0] ?? null,
        // Carried for the audit and for the muscle-group pass that follows this import.
        'muscle'    => $primary,
        'secondary' => $r['secondary_muscles'] ?? [],
    ];
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

printf("PLAN\n");
printf("  create     %4d  new exercises, confidently mapped\n", count($plan['create']));
printf("  merge      %4d  alias onto an existing row (history preserved)\n", count($plan['merge']));
printf("  ambiguous  %4d  need a decision\n", count($plan['ambiguous']));
printf("  skip       %4d  already present or duplicated in the file\n", count($plan['skip']));

echo "\n=== WOULD CREATE, by category and pattern ===\n";
$grid = [];
foreach ($plan['create'] as $c) {
    $grid[$c['category']][$c['pattern']] = ($grid[$c['category']][$c['pattern']] ?? 0) + 1;
}
ksort($grid);
foreach ($grid as $cat => $pats) {
    ksort($pats);
    printf("  %s:\n", strtoupper($cat));
    foreach ($pats as $p => $n) {
        printf("    %-24s %d\n", $p, $n);
    }
}

/*
 * A sample of what each pattern would receive.
 *
 * "Confidently mapped" is the script's confidence, not a guarantee. A wrong pattern is invisible
 * until a session prescribes a curl where it wanted a row, so this prints a handful per pattern
 * to be eyeballed before anything is written.
 */
if (in_array('--sample', $args, true)) {
    echo "\n=== SAMPLE OF WHAT WOULD BE CREATED, per pattern ===\n";
    $byPattern = [];
    foreach ($plan['create'] as $c) {
        $byPattern[$c['category'] . '/' . $c['pattern']][] = $c;
    }
    ksort($byPattern);
    foreach ($byPattern as $key => $items) {
        printf("\n  %s  (%d)\n", $key, count($items));
        // Evenly spaced through the list rather than the first few, so an alphabetical
        // clump does not stand in for the whole set.
        $step = max(1, intdiv(count($items), 6));
        for ($i = 0; $i < count($items); $i += $step) {
            $c = $items[$i];
            printf("    %-48s %s\n", substr($c['name'], 0, 46),
                $c['equipment'] === [] ? '(none)' : implode('+', $c['equipment']));
        }
    }
}

/*
 * Targeted audit, rather than more random sampling.
 *
 * Eyeballing seven rows per pattern found three real misclassifications, which says the error
 * rate is high enough that a bigger random sample is the wrong tool — it would find more of
 * them one at a time. These are the shapes the errors took, checked exhaustively:
 *
 *   - a name whose words contradict its assigned pattern ("row" filed as a push)
 *   - equipment that contradicts the name ("barbell" on something called bodyweight)
 *   - a category that contradicts the primary muscle (a deadlift filed as core)
 *   - a home-performable token on something that is obviously a gym machine
 *
 * Each check is a rule about the DATA, so it scales to 920 rows where reading does not.
 */
if (in_array('--audit', $args, true)) {
    echo "\n=== AUDIT: rows whose name argues with their classification ===\n";

    /** Words that imply a pattern, for contradiction-checking. */
    $implies = [
        // A "rear delt row" is accessory shoulder work despite the name, so isolation is a
        // legitimate answer for anything with a row in it.
        'row'        => ['horizontal_pull', 'vertical_pull', 'isolation'],
        'curl'       => ['isolation'],
        'raise'      => ['isolation', 'flexion', 'plyometric'],
        // 'flexion' covers "Press Sit-Up", which is a sit-up holding a weight overhead;
        // 'squat' covers the leg press, which is the squat pattern on a machine.
        'press'      => ['horizontal_push', 'vertical_push', 'isolation', 'anti_rotation',
                         'flexion', 'squat'],
        // A leg press and a hack squat ARE the squat pattern: knee-dominant, quad-driven. The
        // implement differs, the movement does not.
        'squat'      => ['squat', 'plyometric', 'lunge', 'isolation'],
        'deadlift'   => ['hinge'],
        'lunge'      => ['lunge', 'plyometric'],
        // "Cable Crunch With Side Bends" is a combined movement; anti_lateral_flexion is the
        // side-bend half and is the harder of the two to substitute, so it wins.
        'crunch'     => ['flexion', 'anti_lateral_flexion'],
        'plank'      => ['anti_extension', 'anti_lateral_flexion'],
        'pull-up'    => ['vertical_pull'],
        'pulldown'   => ['vertical_pull'],
        'extension'  => ['isolation', 'extension', 'hinge'],
        'fly'        => ['isolation', 'horizontal_push'],
        'flye'       => ['isolation', 'horizontal_push'],
        'stretch'    => ['other', 'flexion', 'extension', 'lunge', 'squat'],
        'carry'      => ['carry'],
        'walk'       => ['carry', 'cardio', 'lunge', 'isolation', 'other', 'plyometric'],
    ];

    $flagged = 0;
    foreach ($plan['create'] as $c) {
        $n = strtolower($c['name']);
        // Activities are named after sports, not movements: "Curling" is not a bicep curl and
        // "Bowling" is not a row. Their pattern is always cardio and that is correct.
        if ($c['category'] === 'activity') {
            continue;
        }
        foreach ($implies as $word => $ok) {
            // Word boundary, so "press" does not match "compressed" and "row" does not match
            // "throw" — the same class of bug the equipment mapper had.
            if (preg_match('/\b' . preg_quote($word, '/') . '/', $n) !== 1) {
                continue;
            }
            if (!in_array($c['pattern'], $ok, true)) {
                printf("  PATTERN?  %-46s %s/%s\n",
                    substr($c['name'], 0, 44), $c['category'], $c['pattern']);
                $flagged++;
            }
            break;   // first matching word only, longest-name wins by list order
        }
    }
    printf("  %d pattern contradictions\n", $flagged);

    echo "\n=== AUDIT: equipment that argues with the name ===\n";
    $flagged = 0;
    foreach ($plan['create'] as $c) {
        $n  = strtolower($c['name']);
        $eq = $c['equipment'];
        $has = static fn(string $t): bool => in_array($t, $eq, true);

        $bad = null;
        if (str_contains($n, 'bodyweight') && $eq !== []) {
            $bad = 'named bodyweight but needs ' . implode('+', $eq);
        } elseif (str_contains($n, 'barbell') && !$has('barbell') && !$has('smith_machine')
                  && !$has('trap_bar') && !$has('back_ext_bench')) {
            $bad = 'named barbell but equipment is ' . ($eq === [] ? '(none)' : implode('+', $eq));
        } elseif (str_contains($n, 'dumbbell') && !$has('dumbbell')) {
            $bad = 'named dumbbell but equipment is ' . ($eq === [] ? '(none)' : implode('+', $eq));
        } elseif (str_contains($n, 'cable') && !$has('cable_tower')) {
            $bad = 'named cable but equipment is ' . ($eq === [] ? '(none)' : implode('+', $eq));
        } elseif (str_contains($n, 'kettlebell') && !$has('kettlebell')) {
            $bad = 'named kettlebell but equipment is ' . ($eq === [] ? '(none)' : implode('+', $eq));
        } elseif (str_contains($n, 'machine') && $eq === []) {
            $bad = 'named machine but needs nothing';
        }
        if ($bad !== null) {
            printf("  EQUIP?    %-46s %s\n", substr($c['name'], 0, 44), $bad);
            $flagged++;
        }
    }
    printf("  %d equipment contradictions\n", $flagged);

    echo "\n=== AUDIT: what a HOME user would now be offered ===\n";
    /*
     * The check that matters most, and the one no amount of reading catches.
     *
     * A wrong equipment array does not fail a test — it hands somebody a machine they do not
     * own, which is the bug the home kit was built to fix. So this asks the question directly:
     * of everything that would become available to a dumbbells-and-bench home gym, does any of
     * it look like gym equipment?
     */
    $homeTokens = ['dumbbell', 'bench', 'incline_bench', 'box', 'resistance_band',
                   'pull_up_bar', 'kettlebell', 'barbell', 'rack', 'trap_bar', 'plate'];
    $suspicious = ['machine', 'cable', 'leverage', 'smith', 'hack', 'leg press', 'prowler',
                   'selectorized', 'plate-loaded', 'lat pulldown', 'pulldown'];
    $flagged = 0;
    foreach ($plan['create'] as $c) {
        if ($c['equipment'] !== [] && array_diff($c['equipment'], $homeTokens) !== []) {
            continue;   // needs something a home gym does not have; correctly excluded
        }
        $n = strtolower($c['name']);
        foreach ($suspicious as $s) {
            if (str_contains($n, $s)) {
                printf("  HOME?     %-46s %s\n", substr($c['name'], 0, 44),
                    $c['equipment'] === [] ? '(none)' : implode('+', $c['equipment']));
                $flagged++;
                break;
            }
        }
    }
    printf("  %d rows performable at home whose name says otherwise\n", $flagged);

    echo "\n=== AUDIT: category against primary muscle ===\n";
    $flagged = 0;
    foreach ($plan['create'] as $c) {
        if ($c['category'] === 'core' && !in_array($c['muscle'] ?? '', ['abdominals', ''], true)) {
            printf("  CAT?      %-46s core, but primary muscle is %s\n",
                substr($c['name'], 0, 44), (string) $c['muscle']);
            $flagged++;
        }
    }
    printf("  %d category contradictions\n", $flagged);
}

echo "\n=== MERGES — review these, a wrong one loses load history ===\n";
$odd = array_values(array_filter($plan['merge'], static fn($m) => !$m['same_category']));
printf("  %d merges, %d of them across DIFFERENT categories (suspicious)\n",
    count($plan['merge']), count($odd));
foreach ($plan['merge'] as $i => $m) {
    if (!$reportOnly && $i >= 40) {
        printf("  ... and %d more (--report for all)\n", count($plan['merge']) - 40);
        break;
    }
    printf("  %-46s -> %-26s%s\n", $m['name'], $m['onto'],
        $m['same_category'] ? '' : '   ** CATEGORY MISMATCH **');
}

echo "\n=== AMBIGUOUS — grouped by reason ===\n";
$byWhy = [];
foreach ($plan['ambiguous'] as $a) {
    $byWhy[$a['why']][] = $a;
}
foreach ($byWhy as $why => $items) {
    printf("\n  %s  (%d)\n", $why, count($items));
    foreach ($items as $i => $a) {
        if (!$reportOnly && $i >= 15) {
            printf("    ... and %d more (--report for all)\n", count($items) - 15);
            break;
        }
        printf("    %-46s cat=%-9s pattern=%-18s muscles=%s\n",
            $a['name'], $a['category'] ?? '?', $a['pattern'] ?? '(none)', $a['muscles'] ?? '');
    }
}

if (!$commit) {
    echo "\nDRY RUN — nothing written. Re-run with --commit once the report is agreed.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Write
// ---------------------------------------------------------------------------

/*
 * REFUSES TO RUN WITH ANYTHING UNRESOLVED.
 *
 * An ambiguous row has no pattern or no equipment, and both are NOT NULL in the schema — so
 * this would either fail mid-write or, worse, insert a guess. The dry run exists to get this
 * to zero; if it is not zero, the decisions are not finished.
 */
if ($plan['ambiguous'] !== []) {
    printf("\nREFUSING: %d rows are still ambiguous. Resolve them first.\n",
        count($plan['ambiguous']));
    exit(1);
}

$before = (int) (DB::one('SELECT COUNT(*) AS n FROM exercises')['n'] ?? 0);
printf("\nwriting… (%d exercises before)\n", $before);

$created = 0;
$aliased = 0;
$failed  = [];

/*
 * One transaction.
 *
 * A half-imported library is worse than none: the vocabulary would carry exercises whose
 * aliases never landed, so a logged "DB Bench" would stop resolving. Either the whole library
 * moves or nothing does.
 */
DB::tx(function () use ($plan, &$created, &$aliased, &$failed, $existing): void {
    foreach ($plan['create'] as $c) {
        try {
            $id = DB::insert(
                'INSERT INTO exercises
                    (slug, name, category, pattern, equipment, load_type, demo_url, is_system)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
                [
                    $c['slug'],
                    $c['name'],
                    $c['category'],
                    $c['pattern'],
                    json_encode($c['equipment']),
                    /*
                     * load_type decides which log fields are meaningful, so it is inferred from
                     * the same signals the prescription uses rather than defaulted to 'weight'.
                     * A plank logged in kilos would be nonsense on the screen.
                     */
                    loadTypeFor($c),
                    $c['demo'],
                ]
            );
            $created++;

            /*
             * The source name as an alias for itself.
             *
             * The model is told to use slugs, but a user typing a free-form log writes the
             * name. resolveSlug checks aliases, so seeding the display name costs one row and
             * makes "Barbell Squat" resolve without a second thought.
             */
            if (strtolower($c['name']) !== strtolower($c['slug'])) {
                DB::run(
                    'INSERT IGNORE INTO exercise_aliases (alias, exercise_id) VALUES (?, ?)',
                    [$c['name'], $id]
                );
            }
        } catch (Throwable $e) {
            $failed[] = $c['slug'] . ': ' . $e->getMessage();
        }
    }

    /*
     * Merges become aliases, never new rows.
     *
     * This is the whole reason the dry run exists. logged_exercises and prescribed_exercises
     * reference exercises.id, so a "Dumbbell Bench Press" imported as its own row would leave
     * every set logged under db-bench-press orphaned from the exercise the model now
     * prescribes. An alias keeps one canonical id and lets both names resolve to it.
     */
    foreach ($plan['merge'] as $m) {
        $target = $existing[$m['onto']] ?? null;
        if ($target === null) {
            $failed[] = "merge {$m['name']}: target {$m['onto']} vanished";
            continue;
        }
        DB::run(
            'INSERT IGNORE INTO exercise_aliases (alias, exercise_id) VALUES (?, ?)',
            [$m['name'], (int) $target['id']]
        );
        $aliased++;
    }
});

$after = (int) (DB::one('SELECT COUNT(*) AS n FROM exercises')['n'] ?? 0);

printf("\ncreated  %d\n", $created);
printf("aliased  %d  (merges, plus %d display names)\n", $aliased, $created);
printf("library  %d -> %d\n", $before, $after);

if ($failed !== []) {
    printf("\n%d FAILED:\n", count($failed));
    foreach (array_slice($failed, 0, 20) as $f) {
        printf("  %s\n", $f);
    }
}

echo "\nverifying what a constrained user now sees:\n";
require_once YK_SRC . '/lib/PlanSchema.php';
foreach ([
    ['bodyweight, nothing at home', ['bodyweight'], []],
    ['home gym, dumbbells only',    ['home_gym'],   ['dumbbell']],
    ['full gym',                    ['full_gym'],   null],
] as [$label, $access, $kit]) {
    $v = PlanSchema::vocabulary(
        null, $access, $kit, PlanSchema::categoriesFor($access, true)
    );
    $n = 0;
    foreach ($v as $pats) {
        foreach ($pats as $slugs) {
            $n += count($slugs);
        }
    }
    printf("  %-30s %d exercises\n", $label, $n);
}

exit($failed === [] ? 0 : 1);
