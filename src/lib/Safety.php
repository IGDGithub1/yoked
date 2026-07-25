<?php
declare(strict_types=1);

/**
 * Hard-constraint enforcement (SPEC-safety.md §5).
 *
 * Soft constraints live in the prompt — Claude may propose them with a reason
 * and the user accepts or vetoes. HARD constraints are validated here, in code,
 * after generation. A violation is not surfaced to the user as an apology: it
 * names itself in a retry prompt and the plan is regenerated.
 *
 * The point of doing this in code rather than trusting the prompt: an LLM that
 * can be talked out of a constraint has no constraints. Nothing here consults
 * the model's opinion.
 *
 * Constraints are DATA (user_constraints), never hardcoded rules. There is no
 * line in this file that says "diabetics may not have carbs" — that would be
 * the exact mistake the keto app made in four files.
 */
final class Safety
{
    /**
     * Load a user's active constraints, split by tier.
     *
     * @return array{hard: list<array>, soft: list<array>}
     */
    public static function forUser(int $userId): array
    {
        $rows = DB::all(
            'SELECT kind, tier, subject, reason, guidance, floor_value, progression
             FROM user_constraints
             WHERE user_id = ? AND active = 1
             ORDER BY tier, kind, subject',
            [$userId]
        );

        $out = ['hard' => [], 'soft' => []];
        foreach ($rows as $r) {
            $out[$r['tier']][] = $r;
        }
        return $out;
    }

    /**
     * Validate a generated plan against a user's hard constraints.
     *
     * Returns a list of human-readable violation strings — these go straight
     * into the retry prompt, so they must be specific enough for the model to
     * act on. "Invalid plan" produces another invalid plan; "Thursday dinner
     * contains peanuts (hard allergy)" produces a fix.
     *
     * @return list<string> empty means the plan is clean
     */
    public static function validatePlan(array $plan, int $userId): array
    {
        $constraints = self::forUser($userId);
        $hard = $constraints['hard'];

        $violations = [];

        // Index hard constraints by kind for cheap lookup.
        $foodBans     = [];
        $movementBans = [];
        $cardioBans   = [];
        $floors       = [];
        foreach ($hard as $c) {
            $subject = strtolower((string) $c['subject']);
            switch ($c['kind']) {
                case 'food':      $foodBans[$subject]     = $c; break;
                case 'movement':  $movementBans[$subject] = $c; break;
                case 'cardio':    $cardioBans[$subject]   = $c; break;
                case 'target_floor':
                    $floors[$subject] = (float) $c['floor_value'];
                    break;
                // 'condition' constraints are MODIFIERS, not blocks — they
                // carry guidance text into the prompt and have nothing to
                // validate here. 'equipment' is handled via availability.
            }
        }

        $violations = array_merge(
            $violations,
            self::checkMeals($plan, $foodBans),
            self::checkExercises($plan, $movementBans, $cardioBans),
            self::checkFloors($plan, $floors),
            self::checkAvailability($plan, $userId),
            self::checkCommittedCount($plan, $userId),
            self::checkExerciseLibrary($plan),
            self::checkCoreBlocks($plan)
        );

        return array_values(array_unique($violations));
    }

    /**
     * Allergen categories expand to their members.
     *
     * A constraint recorded as "shellfish" must catch "shrimp" — plain
     * substring matching runs the wrong way for a category and would let it
     * straight through. This is the difference between a validator that reads
     * safe and one that is.
     *
     * Not exhaustive, and deliberately so: it covers the common allergen
     * categories, and anything unlisted still gets exact substring matching.
     * Add a category when a user needs it rather than trying to enumerate food.
     */
    private const FOOD_CATEGORIES = [
        'shellfish' => ['shrimp', 'prawn', 'crab', 'lobster', 'crayfish', 'langoustine',
                        'scallop', 'clam', 'mussel', 'oyster', 'squid', 'calamari',
                        'octopus', 'krill'],
        'tree nuts' => ['almond', 'cashew', 'walnut', 'pecan', 'pistachio', 'hazelnut',
                        'macadamia', 'brazil nut', 'pine nut'],
        'tree_nuts' => ['almond', 'cashew', 'walnut', 'pecan', 'pistachio', 'hazelnut',
                        'macadamia', 'brazil nut', 'pine nut'],
        'nuts'      => ['almond', 'cashew', 'walnut', 'pecan', 'pistachio', 'hazelnut',
                        'macadamia', 'peanut', 'pine nut'],
        'dairy'     => ['milk', 'cheese', 'butter', 'cream', 'yogurt', 'yoghurt',
                        'whey', 'casein', 'ghee', 'custard'],
        'gluten'    => ['wheat', 'barley', 'rye', 'bread', 'pasta', 'flour', 'couscous',
                        'seitan', 'farro', 'bulgur', 'panko', 'breadcrumb'],
        'fish'      => ['salmon', 'tuna', 'cod', 'haddock', 'halibut', 'tilapia',
                        'sardine', 'anchovy', 'mackerel', 'trout', 'bass', 'snapper'],
        'soy'       => ['soy', 'soya', 'tofu', 'edamame', 'tempeh', 'miso', 'tamari'],
        'eggs'      => ['egg', 'mayonnaise', 'meringue', 'aioli'],
        'egg'       => ['egg', 'mayonnaise', 'meringue', 'aioli'],
        'sesame'    => ['sesame', 'tahini', 'hummus'],
        'pork'      => ['pork', 'bacon', 'ham', 'prosciutto', 'chorizo', 'pancetta',
                        'sausage', 'lard'],
        'red meat'  => ['beef', 'steak', 'lamb', 'mutton', 'venison', 'mince'],
    ];

    /** Every term a food ban should match: the subject plus its category members. */
    private static function foodTerms(string $subject): array
    {
        $subject = strtolower(trim($subject));
        $terms   = [$subject];

        if (isset(self::FOOD_CATEGORIES[$subject])) {
            $terms = array_merge($terms, self::FOOD_CATEGORIES[$subject]);
        }
        // Singularise a trailing 's' so "peanuts" also matches "peanut butter".
        if (str_ends_with($subject, 's') && strlen($subject) > 3) {
            $terms[] = substr($subject, 0, -1);
        }
        return array_unique($terms);
    }

    /**
     * Every ingredient against every hard food constraint.
     *
     * Matching is deliberately blunt: "peanut" catches "peanut butter", "peanut
     * oil", and "roasted peanuts", and a category like "shellfish" expands to
     * its members. For an allergy a false positive costs one regeneration; a
     * false negative could hurt someone.
     */
    private static function checkMeals(array $plan, array $foodBans): array
    {
        if ($foodBans === []) {
            return [];
        }
        $violations = [];

        foreach ($plan['days'] ?? [] as $day) {
            $date = (string) ($day['date'] ?? '?');
            foreach ($day['meals'] ?? [] as $meal) {
                $slot = (string) ($meal['slot'] ?? '?');

                // The meal name and every ingredient. A recipe called "Peanut
                // Chicken" with anonymised ingredients still fails. Suggestions
                // count too — an offered alternative is still an offer.
                $haystacks = [(string) ($meal['name'] ?? '')];
                foreach ($meal['ingredients'] ?? [] as $ing) {
                    $haystacks[] = (string) ($ing['item'] ?? '');
                    $haystacks[] = (string) ($ing['note'] ?? '');
                }
                foreach ($meal['suggestions'] ?? [] as $s) {
                    $haystacks[] = (string) $s;
                }
                $haystacks[] = (string) ($meal['method'] ?? '');

                foreach ($foodBans as $banned => $c) {
                    $hit = null;
                    foreach (self::foodTerms((string) $banned) as $term) {
                        foreach ($haystacks as $h) {
                            if ($h !== '' && stripos($h, $term) !== false) {
                                $hit = $term;
                                break 2;
                            }
                        }
                    }
                    if ($hit !== null) {
                        $reason = $c['reason'] ? " ({$c['reason']})" : '';
                        // Name both the matched term and the constraint, so a
                        // category hit is comprehensible in the retry prompt.
                        $named = $hit === strtolower((string) $banned)
                            ? "'{$hit}'"
                            : "'{$hit}' (a form of '{$banned}')";
                        $violations[] = "{$date} {$slot} contains {$named}, which is "
                            . "a hard food constraint{$reason}. Replace the meal entirely.";
                    }
                }
            }
        }
        return $violations;
    }

    /** Prescribed exercises against hard movement and cardio constraints. */
    private static function checkExercises(array $plan, array $movementBans, array $cardioBans): array
    {
        if ($movementBans === [] && $cardioBans === []) {
            return [];
        }
        $violations = [];
        $bans = $movementBans + $cardioBans;

        foreach ($plan['sessions'] ?? [] as $session) {
            $date = (string) ($session['date'] ?? '?');
            foreach ($session['exercises'] ?? [] as $ex) {
                $slug = (string) ($ex['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                // Compare against slug and resolved display name — a ban is
                // recorded as either.
                $name = DB::one('SELECT name FROM exercises WHERE slug = ?', [$slug])['name'] ?? $slug;

                foreach ($bans as $banned => $c) {
                    if (stripos($slug, str_replace(' ', '-', $banned)) !== false
                        || stripos((string) $name, $banned) !== false) {
                        $reason = $c['reason'] ? " ({$c['reason']})" : '';
                        $violations[] = "{$date} prescribes '{$name}', which is a hard "
                            . "constraint{$reason}. Substitute a different movement.";
                    }
                }
            }
        }
        return $violations;
    }

    /**
     * Target floors — a minimum Claude may not go below.
     *
     * Claude proposes these and the user confirms, so a floor is user-owned
     * data. Subject is the macro key: 'protein_g', 'calories'.
     */
    private static function checkFloors(array $plan, array $floors): array
    {
        if ($floors === []) {
            return [];
        }
        $violations = [];
        $map = [
            'calories'  => 'target_calories',
            'protein_g' => 'target_protein_g',
            'fat_g'     => 'target_fat_g',
            'carbs_g'   => 'target_carbs_g',
        ];

        foreach ($plan['days'] ?? [] as $day) {
            $date = (string) ($day['date'] ?? '?');
            foreach ($floors as $subject => $floor) {
                $field = $map[$subject] ?? null;
                if ($field === null || !isset($day[$field])) {
                    continue;
                }
                $value = (float) $day[$field];
                if ($value < $floor) {
                    $violations[] = "{$date} sets {$subject} to {$value}, below the "
                        . "hard floor of {$floor}. Raise it to at least {$floor}.";
                }
            }
        }
        return $violations;
    }

    /**
     * Sessions must fit the day's stated availability.
     *
     * This is why "equipment not available" is a hard constraint rather than a
     * preference: a barbell squat on a bodyweight-only Saturday is not a
     * suggestion the user can be talked into.
     */
    private static function checkAvailability(array $plan, int $userId): array
    {
        $grid = [];
        foreach (DB::all(
            'SELECT weekday, can_train, minutes, access FROM availability WHERE user_id = ?',
            [$userId]
        ) as $row) {
            $grid[(int) $row['weekday']] = $row;
        }
        if ($grid === []) {
            return [];   // no grid recorded; nothing to check against
        }

        $violations = [];
        foreach ($plan['sessions'] ?? [] as $session) {
            $date = (string) ($session['date'] ?? '');
            $type = (string) ($session['session_type'] ?? '');
            if ($date === '' || $type === 'rest') {
                continue;
            }

            $ts = strtotime($date);
            if ($ts === false) {
                $violations[] = "Session has an unparseable date '{$date}'.";
                continue;
            }
            $weekday = (int) date('N', $ts);   // 1=Mon .. 7=Sun
            $day = $grid[$weekday] ?? null;
            if ($day === null) {
                continue;
            }

            if ($day['can_train'] === 'no') {
                $violations[] = "{$date} (" . date('D', $ts) . ') is marked '
                    . 'unavailable in the availability grid, but a '
                    . "{$type} session is scheduled. Move or drop it.";
                continue;
            }

            $available = $day['minutes'] !== null ? (int) $day['minutes'] : null;
            $target    = isset($session['target_minutes']) ? (int) $session['target_minutes'] : null;
            if ($available !== null && $target !== null && $target > $available) {
                $violations[] = "{$date} schedules {$target} minutes but only "
                    . "{$available} are available. Shorten the session.";
            }

            // Location must match what that day actually has.
            $access   = $day['access'];
            $location = $session['location'] ?? null;
            if ($access !== null && $location !== null && $location !== $access) {
                $violations[] = "{$date} is a '{$access}' day but the session is "
                    . "set to '{$location}'. Use '{$access}' and only exercises "
                    . 'performable there.';
            }
        }
        return $violations;
    }

    /**
     * Committed sessions must equal the user's stated capacity (§3.3a).
     *
     * Over-prescribing manufactures a failure the user never agreed to;
     * under-prescribing quietly shrinks their week. Active recovery never
     * counts against the budget.
     */
    private static function checkCommittedCount(array $plan, int $userId): array
    {
        $row = DB::one(
            'SELECT committed_days_per_week FROM profiles WHERE user_id = ?',
            [$userId]
        );
        if ($row === null) {
            return [];
        }
        $want = (int) $row['committed_days_per_week'];

        $committed = 0;
        foreach ($plan['sessions'] ?? [] as $s) {
            if (($s['session_type'] ?? '') === 'active_recovery'
                || ($s['session_type'] ?? '') === 'rest') {
                continue;
            }
            if (($s['is_committed'] ?? false) === true) {
                $committed++;
            }
        }

        if ($committed !== $want) {
            return ["The plan has {$committed} committed sessions but the user "
                . "committed to {$want} per week. Mark exactly {$want} as "
                . 'committed (active recovery does not count); anything beyond '
                . 'that should be is_committed: false.'];
        }
        return [];
    }

    /**
     * Every prescribed slug must resolve against the exercise library.
     *
     * A slug that doesn't resolve isn't fatal in principle — the library grows
     * by promotion — but it can't be persisted as a prescription without a row
     * to point at. Reported as a violation so the retry either uses a known
     * slug or proposes the new exercise properly.
     */
    private static function checkExerciseLibrary(array $plan): array
    {
        $violations = [];
        $unknown = [];

        foreach ($plan['sessions'] ?? [] as $session) {
            foreach ($session['exercises'] ?? [] as $ex) {
                $slug = (string) ($ex['slug'] ?? '');
                if ($slug === '') {
                    $violations[] = 'An exercise is missing its slug.';
                    continue;
                }
                if (PlanSchema::resolveSlug($slug) === null) {
                    $unknown[$slug] = true;
                }
            }
        }

        if ($unknown !== []) {
            $violations[] = 'These exercise slugs are not in the library: '
                . implode(', ', array_keys($unknown))
                . '. Use only slugs from the provided vocabulary.';
        }
        return $violations;
    }

    /**
     * Core on every strength day (§3.3b) — on by default, so its absence is a
     * spec violation rather than a stylistic choice.
     */
    private static function checkCoreBlocks(array $plan): array
    {
        $violations = [];
        foreach ($plan['sessions'] ?? [] as $session) {
            if (($session['session_type'] ?? '') !== 'strength') {
                continue;
            }
            $date = (string) ($session['date'] ?? '?');

            $hasCore = false;
            foreach ($session['exercises'] ?? [] as $ex) {
                if (($ex['block'] ?? '') === 'core') {
                    $hasCore = true;
                    break;
                }
            }
            if (!$hasCore) {
                $violations[] = "{$date} is a strength session with no core block. "
                    . 'Every strength day carries 8-12 minutes of core work, '
                    . 'placed after the main work.';
            }
        }
        return $violations;
    }

    /**
     * Render constraints for the generation prompt.
     *
     * Hard constraints are stated as absolute. Soft ones are stated as strong
     * preferences Claude may propose against WITH a reason — that distinction
     * is the whole tier system, so the wording matters.
     */
    public static function promptBlock(int $userId): string
    {
        $c = self::forUser($userId);
        $lines = [];

        if ($c['hard'] !== []) {
            $lines[] = 'HARD CONSTRAINTS — never violate these. A plan that does is rejected:';
            foreach ($c['hard'] as $h) {
                $line = "  - [{$h['kind']}] {$h['subject']}";
                if ($h['reason']) {
                    $line .= " — {$h['reason']}";
                }
                // Spell out a category's members. "Avoid shellfish" is not
                // enough on its own — validation rejects shrimp, so the prompt
                // has to say shrimp.
                if ($h['kind'] === 'food') {
                    $terms = self::foodTerms((string) $h['subject']);
                    if (count($terms) > 1) {
                        $line .= "\n      This covers: " . implode(', ', $terms);
                    }
                }
                if ($h['kind'] === 'condition' && $h['guidance']) {
                    // Conditions are MODIFIERS, not blocks: diabetes means carb
                    // timing matters, not that carbs are banned.
                    $line .= "\n      Guidance: {$h['guidance']}";
                }
                if ($h['kind'] === 'target_floor' && $h['floor_value'] !== null) {
                    $line .= " — minimum {$h['floor_value']}, never go below";
                }
                $lines[] = $line;
            }
        }

        if ($c['soft'] !== []) {
            $lines[] = '';
            $lines[] = 'SOFT CONSTRAINTS — strongly avoid. You may include one only '
                     . 'if you state why in its rationale:';
            foreach ($c['soft'] as $s) {
                $line = "  - [{$s['kind']}] {$s['subject']}";
                if ($s['reason']) {
                    $line .= " — {$s['reason']}";
                }
                $prog = json_decode((string) ($s['progression'] ?? ''), true);
                if (is_array($prog) && ($prog['status'] ?? '') === 'working_toward') {
                    // The anti-staleness mechanism: scaffold toward the target
                    // rather than excluding it forever.
                    $line .= "\n      Working toward: {$prog['target']}. Prescribe "
                           . 'intermediate progressions that build to it.';
                }
                $lines[] = $line;
            }
        }

        return $lines === [] ? '' : implode("\n", $lines);
    }
}
