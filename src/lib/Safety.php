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
     * How many times one movement may appear in a week.
     *
     * Twice is ordinary — an upper/lower split hits chest twice by design — and a third is
     * defensible for a lagging lift. Four does not leave enough recovery between exposures and
     * crowds out everything else the week should cover.
     *
     * Deliberately a WITHIN-week limit. Repetition across weeks is a programme rather than a
     * flaw: progressive overload needs the same lift repeated, since you cannot measure progress
     * on a squat by squatting something else.
     */
    public const MAX_WEEKLY_FREQUENCY = 3;

    /**
     * Load a user's active constraints, split by tier.
     *
     * Includes anything INHERITED from a training buddy (SPEC-coaching §10.2b), which arrives
     * soft regardless of how the buddy holds it. See inherited().
     *
     * One loader for both validatePlan and promptBlock, deliberately: a constraint that
     * steers generation but is not enforced, or the reverse, is the kind of gap nobody
     * notices until a plan is wrong.
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
        $own = ['hard' => [], 'soft' => []];
        foreach ($rows as $r) {
            $out[$r['tier']][] = $r;
            // Remembered per tier so an inherited row cannot duplicate or weaken one of the
            // user's own. Keyed by kind+subject, lowercased, since that is what the ban maps
            // and foodTerms() match on.
            $own[$r['tier']][strtolower((string) $r['kind'] . '|' . $r['subject'])] = true;
        }

        foreach (self::inherited($userId) as $r) {
            $key = strtolower((string) $r['kind'] . '|' . $r['subject']);

            /*
             * Skip anything the user already holds, at EITHER tier.
             *
             * Already hard: adding a soft copy of the same subject would be noise in the
             * prompt and, worse, would read as a softening of a limit they set themselves.
             * Already soft: it is a duplicate.
             *
             * This is why the check is not just on the soft bucket. An inherited row must
             * never be able to make an existing constraint look weaker than it is.
             */
            if (isset($own['hard'][$key]) || isset($own['soft'][$key])) {
                continue;
            }

            $out['soft'][] = $r;
            $own['soft'][$key] = true;   // two buddies cannot happen, but be idempotent anyway
        }

        return $out;
    }

    /**
     * A training buddy's avoids, converted to soft rows for this user (§10.2b).
     *
     * "While paired, each user takes on their buddy's training avoids. If one cannot ski,
     * skiing is not suggested to either of them for as long as the pair lasts."
     *
     * THE TIER DOES NOT TRANSFER, and that is SPEC-safety §6 holding the line. A hard
     * constraint is a limit the user set for themselves, deliberately; nothing another person
     * does should create one. A plan rejected over somebody else's preference is a failure the
     * user cannot fix and cannot reason about. So an inherited limit arrives soft: the coach
     * steers away from it, may still propose it with a stated reason, and the user can veto it
     * like any other suggestion.
     *
     * TRAINING ONLY. Food, conditions, dietary patterns and target floors never transfer.
     * Nutrition is out of scope for v1 (§10.0), an allergy is not a preference to compromise
     * over, and a condition is a modifier carrying medical guidance that belongs to one body.
     *
     * Not persisted. These are a property of the pair, not of the user, so they vanish the
     * moment the pairing ends rather than needing to be cleaned up — and nothing writes to
     * user_constraints, which keeps SPEC-safety §7's "one automated write path" true.
     */
    private static function inherited(int $userId): array
    {
        $pair = BuddySchedule::activePair($userId);
        if ($pair === null) {
            return [];
        }

        $buddyId = (int) $pair['user_lo'] === $userId
            ? (int) $pair['user_hi']
            : (int) $pair['user_lo'];

        $rows = DB::all(
            'SELECT kind, subject, reason, tier
             FROM user_constraints
             WHERE user_id = ? AND active = 1
               -- Training only. The kinds are named rather than excluded so a future kind
               -- has to be considered deliberately instead of inheriting by default.
               AND kind IN ("movement", "cardio", "equipment")
             ORDER BY kind, subject',
            [$buddyId]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'kind'    => (string) $r['kind'],
                'tier'    => 'soft',
                'subject' => (string) $r['subject'],
                /*
                 * Says whose limit this is, in the prompt and on the profile.
                 *
                 * Without it the model sees "avoid burpees" with no explanation and the user
                 * sees a preference they never set. The buddy's own reason is deliberately NOT
                 * carried over: it may be medical, and their diagnosis is not their buddy's
                 * business (§10.4).
                 */
                'reason'  => 'Your training buddy avoids this',
                'guidance' => null,
                'floor_value' => null,
                'progression' => null,
                // Marks the row as not the user's own, for the profile UI. Nothing in
                // validatePlan reads it: soft constraints are never enforced there anyway.
                'inherited' => true,
            ];
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
        return array_values(array_unique(array_merge(
            self::validateTraining($plan, $userId),
            self::validateNutrition($plan, $userId)
        )));
    }

    /**
     * The training half only (sessions).
     *
     * SPLIT OUT BECAUSE GENERATION IS SPLIT. Training and nutrition are now asked for in two
     * calls, so each has to be judged on its own — a training week cannot be held back because
     * the food half came up short, which is exactly what used to happen.
     *
     * The division is clean rather than convenient: not one of the nine checks reads both
     * `sessions` and `days`. Five are training, four are nutrition, and none of them had to be
     * altered to be separated.
     */
    public static function validateTraining(array $plan, int $userId): array
    {
        $bans = self::hardBans($userId);

        return array_values(array_unique(array_merge(
            self::checkExercises($plan, $bans['movement'], $bans['cardio']),
            self::checkAvailability($plan, $userId),
            self::checkCommittedCount($plan, $userId),
            self::checkExerciseLibrary($plan),
            self::checkNoRepeats($plan),
            self::checkWeeklyFrequency($plan),
            self::checkCoreBlocks($plan)
        )));
    }

    /**
     * The nutrition half only (days and their meals).
     *
     * checkWeekIsWhole runs first, because everything below it assumes seven days are present
     * and reporting "Thursday dinner has no ingredients" about a plan with no Thursday is
     * noise. It is also the check that caught the failure this split exists to contain: a
     * one-day answer where seven were asked for.
     */
    public static function validateNutrition(array $plan, int $userId): array
    {
        $bans = self::hardBans($userId);

        return array_values(array_unique(array_merge(
            self::checkWeekIsWhole($plan),
            self::checkMeals($plan, $bans['food']),
            self::checkMealCompleteness($plan),
            self::checkFloors($plan, $bans['floors'])
        )));
    }

    /**
     * Which library slugs this user's hard bans actually cover.
     *
     * Used to MARK the exercise vocabulary in the prompt, so the model can see what is excluded
     * instead of picking something forbidden and having the plan rejected afterwards.
     *
     * LIVES HERE, NEXT TO checkExercises, AND USES ITS MATCHING RULE. If the annotation and the
     * check disagreed, the prompt would either mark something the validator allows — narrowing
     * the model's options for no reason — or fail to mark something it rejects, which is the
     * wasted generation this is meant to prevent. Two copies of a fuzzy text match would drift
     * within a month; one function cannot.
     *
     * @return array<string,true> keyed by slug, for isset() lookups
     */
    public static function bannedSlugs(int $userId): array
    {
        $bans = self::hardBans($userId);
        $all  = $bans['movement'] + $bans['cardio'];
        if ($all === []) {
            return [];
        }

        $out = [];
        foreach (DB::all('SELECT slug, name FROM exercises') as $e) {
            $slug = (string) $e['slug'];
            $name = (string) $e['name'];
            foreach ($all as $banned => $_c) {
                // The same two-way comparison checkExercises makes: a ban is recorded as
                // either a slug-ish string or a display name.
                if (stripos($slug, str_replace(' ', '-', (string) $banned)) !== false
                    || stripos($name, (string) $banned) !== false) {
                    $out[$slug] = true;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Hard constraints indexed by kind, for cheap lookup during validation.
     *
     * 'condition' constraints are MODIFIERS, not blocks — they carry guidance text into the
     * prompt and have nothing to validate here. 'equipment' is handled via availability.
     */
    private static function hardBans(int $userId): array
    {
        $out = ['food' => [], 'movement' => [], 'cardio' => [], 'floors' => []];

        foreach (self::forUser($userId)['hard'] as $c) {
            $subject = strtolower((string) $c['subject']);
            switch ($c['kind']) {
                case 'food':      $out['food'][$subject]     = $c; break;
                case 'movement':  $out['movement'][$subject] = $c; break;
                case 'cardio':    $out['cardio'][$subject]   = $c; break;
                case 'target_floor':
                    $out['floors'][$subject] = (float) $c['floor_value'];
                    break;
            }
        }
        return $out;
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
    /**
     * A week is seven days, or it is not a week.
     *
     * Guards against a partial answer being accepted as a plan. A retry that
     * returns a fragment — "here is the corrected breakfast" — can still satisfy
     * the schema and parse cleanly, and without this the fragment reaches the
     * checks below, which report something misleading about the one day present
     * rather than the six that are missing.
     *
     * Stated as a violation rather than a hard error so the retry prompt names
     * the real problem, which is the only way the model can act on it.
     */
    private static function checkWeekIsWhole(array $plan): array
    {
        $days = $plan['days'] ?? [];

        if (!is_array($days) || $days === []) {
            return ['The plan contains no days at all. Return the complete week: '
                    . 'seven day entries, each with its own targets and meals.'];
        }

        $dates = [];
        foreach ($days as $d) {
            $date = trim((string) ($d['date'] ?? ''));
            if ($date !== '') {
                $dates[$date] = true;
            }
        }
        $count = count($dates);

        if ($count !== 7) {
            $listed = $count > 0 ? ' Present: ' . implode(', ', array_keys($dates)) . '.' : '';
            return ["The plan has {$count} distinct day(s); a week needs 7. This is "
                    . 'usually a partial answer — return every day of the week in '
                    . 'full, not just the parts that changed.' . $listed];
        }

        // A day with no meals at all is the other shape a truncated or abbreviated
        // answer takes, and it is worth naming the day rather than the count.
        $violations = [];
        foreach ($days as $d) {
            $date = (string) ($d['date'] ?? '?');
            if (($d['meals'] ?? []) === []) {
                $violations[] = "{$date} has no meals. Every day needs its meals, "
                    . 'even on a rest day.';
            }
        }
        return $violations;
    }

    /**
     * A meal that claims to be fully specified must actually be.
     *
     * PlanSchema leaves `ingredients` optional on purpose — target_only and
     * unplanned slots have no recipe, and making it required would force empty
     * arrays onto them. The comment there promises this check compensates, and
     * for a while it did not: a run produced a breakfast with kind 'specified'
     * and no ingredients, which reaches the user as a meal name and nothing to
     * shop for or cook.
     *
     * Cheap to state as a violation, and a violation is a retry rather than a
     * failure, so the model gets told precisely what is missing.
     */
    private static function checkMealCompleteness(array $plan): array
    {
        $violations = [];

        foreach ($plan['days'] ?? [] as $day) {
            $date = (string) ($day['date'] ?? '?');
            foreach ($day['meals'] ?? [] as $meal) {
                if (($meal['kind'] ?? 'specified') !== 'specified') {
                    continue;   // target_only and unplanned carry no recipe
                }
                $slot = (string) ($meal['slot'] ?? '?');
                $ings = $meal['ingredients'] ?? [];

                if (!is_array($ings) || $ings === []) {
                    $violations[] = "{$date} {$slot} has kind 'specified' but no "
                        . 'ingredients. Either list the ingredients, each with an '
                        . "item and a household measure, or set kind to 'target_only' "
                        . 'and give a target_note.';
                    continue;
                }

                foreach ($ings as $i => $ing) {
                    if (!is_array($ing) || trim((string) ($ing['item'] ?? '')) === '') {
                        $violations[] = "{$date} {$slot} ingredient #" . ($i + 1)
                            . ' has no item name.';
                        continue;
                    }
                    // household is required by the schema, but a blank string
                    // satisfies the schema and not the user: someone without a
                    // food scale needs "1 cup", not "".
                    if (trim((string) ($ing['household'] ?? '')) === '') {
                        $item = (string) $ing['item'];
                        $violations[] = "{$date} {$slot}: '{$item}' has no household "
                            . 'measure. Give an everyday quantity such as "1 cup" or '
                            . '"6 oz", not grams alone.';
                    }
                }
            }
        }
        return $violations;
    }

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
        /*
         * The EFFECTIVE schedule, not the raw grid (SPEC-coaching §10.1a).
         *
         * A paired user has two schedules, and the buddy one wins for shared days. That
         * matters here more than anywhere: a day conceded in a negotiation (§10.3a) is one the
         * user's own grid says they cannot train, so reading the grid alone would reject every
         * plan built from an agreement the pair explicitly made. The conceded day also brings
         * its own minutes and access, since the grid has nothing useful to say about a day the
         * user never offered.
         *
         * Unpaired, this is exactly the grid — BuddySchedule::effective with a null pair id
         * returns it unchanged — so the solo path is untouched.
         */
        $pair   = BuddySchedule::activePair($userId);
        $grid   = BuddySchedule::effective($userId, $pair === null ? null : (int) $pair['id']);

        // An entirely unanswered grid still means "nothing to check against". effective()
        // fills every weekday, so the emptiness test is whether ANY day was actually recorded.
        $answered = false;
        foreach ($grid as $day) {
            if (($day['origin'] ?? '') !== 'unanswered') {
                $answered = true;
                break;
            }
        }
        if (!$answered) {
            return [];
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

        /*
         * The target, not the stated count (SPEC-coaching §10.3b).
         *
         * A paired user whose shared days do not cover their commitment chooses what happens
         * to the difference, and two of the three answers lower the committed count on purpose.
         * Checking against the stated figure would reject a plan that correctly honours the
         * choice the user made.
         *
         * Unpaired, or paired with no surplus, this returns the stated count unchanged — so
         * the rule that "under-prescribing quietly shrinks their week" still holds everywhere
         * it was holding before. What changed is that shrinking is now something the user can
         * ASK for, rather than something the app does behind their back.
         */
        $pair = BuddySchedule::activePair($userId);
        $want = (int) BuddySchedule::committedTarget(
            $userId,
            $pair === null ? null : (int) $pair['id']
        )['committed'];

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
    /**
     * No exercise twice in the same session.
     *
     * A live generation produced "Dumbbell Row @8kg", then again at 9kg, then again at 10kg:
     * three sets of one movement written as three exercises. It arose from a substitution — the
     * follower had no pull-up bar, swapped in a row, and the next slot already held one.
     *
     * WHY "NEVER" RATHER THAN A NARROWER RULE. The obvious softer version is to allow a repeat
     * when the prescriptions differ, which would let a deliberate cluster set or a
     * two-rep-scheme prescription through. It would also have PASSED the case above, since
     * 8/9/10kg differ. And nothing in the UI renders a repeated slug as anything but two
     * separate exercises, so even an intentional one reads as a bug to the person following it.
     *
     * The edge case this rejects is a hyper-focused session on one muscle group with limited
     * equipment, where duplication is arguably the only way to fill the time. Judged not worth
     * it: even bodyweight access leaves 19 exercises, and the hinge, squat and lunge patterns
     * cover a glute-focused hour without repeating anything.
     *
     * Scoped PER SESSION, not per block. The same movement in the warm-up and the main block
     * would still be two rows on one screen, which is the thing that reads as broken.
     */
    private static function checkNoRepeats(array $plan): array
    {
        $violations = [];

        foreach ($plan['sessions'] ?? [] as $session) {
            $date  = (string) ($session['date'] ?? '?');
            $seen  = [];
            $dupes = [];

            foreach ($session['exercises'] ?? [] as $ex) {
                $slug = trim((string) ($ex['slug'] ?? ''));
                if ($slug === '') {
                    continue;   // checkExerciseLibrary reports the missing slug
                }
                /*
                 * Compared on the RESOLVED slug, so "DB Bench" and "db-bench-press" count as
                 * the same movement. An alias pair is exactly the shape a duplicate hides in.
                 */
                $canonical = PlanSchema::resolveSlug($slug) ?? strtolower($slug);
                if (isset($seen[$canonical])) {
                    $dupes[$canonical] = true;
                }
                $seen[$canonical] = true;
            }

            foreach (array_keys($dupes) as $slug) {
                $violations[] = "{$date} lists '{$slug}' more than once in the same session. "
                    . 'Multiple sets are ONE entry with a set count, not repeated entries. '
                    . 'Replace the duplicate with a different exercise or remove it.';
            }
        }

        return $violations;
    }

    /**
     * No movement more than three times in a week.
     *
     * Repetition ACROSS weeks is not the problem — the same session every Monday for two months
     * is a programme, and progressive overload requires it: you cannot measure progress on a
     * squat by squatting something else. Repetition WITHIN a week is different. The same
     * movement six times in seven days does not leave enough recovery between exposures, and it
     * crowds out everything else the week should cover.
     *
     * Three is the ceiling because twice is ordinary — an upper body split hits chest twice a
     * week by design — and a third exposure is defensible for a lagging lift. Four is not.
     *
     * Counted on the resolved slug, so an alias cannot smuggle a fourth appearance past it, and
     * across ALL sessions including optional ones: a bonus session still costs recovery.
     */
    private static function checkWeeklyFrequency(array $plan): array
    {
        $counts = [];
        foreach ($plan['sessions'] ?? [] as $session) {
            foreach ($session['exercises'] ?? [] as $ex) {
                $slug = trim((string) ($ex['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }
                $canonical = PlanSchema::resolveSlug($slug) ?? strtolower($slug);
                $counts[$canonical] = ($counts[$canonical] ?? 0) + 1;
            }
        }

        $violations = [];
        foreach ($counts as $slug => $n) {
            if ($n > self::MAX_WEEKLY_FREQUENCY) {
                $violations[] = sprintf(
                    "'%s' appears %d times this week; the limit is %d. The same movement more "
                    . 'often than that does not leave enough recovery between sessions. Replace '
                    . 'the extra ones with a different exercise for the same muscle.',
                    $slug,
                    $n,
                    self::MAX_WEEKLY_FREQUENCY
                );
            }
        }
        return $violations;
    }

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
