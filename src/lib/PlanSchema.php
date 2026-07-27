<?php
declare(strict_types=1);

/**
 * The JSON Schema a generated week must conform to, plus the exercise-library
 * vocabulary the model is allowed to draw on.
 *
 * Kept separate from Plans.php because it is data, not logic, and because the
 * schema is the contract: `additionalProperties: false` everywhere means the
 * model cannot invent fields, and required-field lists mean it cannot omit the
 * ones validation depends on.
 *
 * Two constraints from the specs are enforced structurally here rather than
 * hoped for in the prompt:
 *   - meals carry STRUCTURED ingredients, never prose (SPEC-coaching §3.4).
 *     Prose cannot be validated against an allergy.
 *   - every session declares is_committed (SPEC-coaching §3.3a), so adherence
 *     can count committed sessions only.
 */
final class PlanSchema
{
    /**
     * Walk a schema and report anything structured outputs will reject.
     *
     * Exists because the API's own error is precise but arrives only at request
     * time, after the prompt has been built and a call attempted. Catching it in
     * a test is free; catching it in production costs a user's plan.
     *
     * Three rules bite:
     *   1. every object needs additionalProperties: false
     *   2. numeric/string constraints (minimum, maxLength, …) are unsupported
     *   3. at most 24 OPTIONAL parameters across the whole schema
     *
     * (3) is the one that surprises: a schema can be perfectly valid JSON
     * Schema and still be rejected for having too many optional fields. The fix
     * is almost always to require the ones the app actually needs rather than
     * to delete fields.
     *
     * @return list<string> empty means the schema is acceptable
     */
    public const MAX_OPTIONAL_PARAMS = 24;

    public static function lint(?array $schema = null, string $path = '$'): array
    {
        $isRoot   = $schema === null;
        $schema ??= self::build();
        $problems = [];

        if ($isRoot) {
            $optional = self::countOptional($schema);
            if ($optional > self::MAX_OPTIONAL_PARAMS) {
                $problems[] = sprintf(
                    '$: %d optional parameters, limit is %d. Mark the ones the app '
                    . 'needs as required rather than deleting fields.',
                    $optional,
                    self::MAX_OPTIONAL_PARAMS
                );
            }
        }

        $type = $schema['type'] ?? null;

        if ($type === 'object') {
            if (!array_key_exists('additionalProperties', $schema)) {
                $problems[] = "{$path}: object is missing additionalProperties (must be false)";
            } elseif ($schema['additionalProperties'] !== false) {
                $problems[] = "{$path}: additionalProperties must be false, got "
                    . json_encode($schema['additionalProperties']);
            }
            foreach ($schema['properties'] ?? [] as $name => $sub) {
                if (is_array($sub)) {
                    $problems = array_merge($problems, self::lint($sub, "{$path}.{$name}"));
                }
            }
        }

        if ($type === 'array' && isset($schema['items']) && is_array($schema['items'])) {
            $problems = array_merge($problems, self::lint($schema['items'], "{$path}[]"));
        }

        // Unsupported keywords. Silently ignored at best, a 400 at worst — and
        // either way the constraint isn't enforced, so relying on one is a bug.
        foreach (['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum',
                  'multipleOf', 'minLength', 'maxLength', 'pattern',
                  'minItems', 'maxItems', 'uniqueItems'] as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                $problems[] = "{$path}: '{$keyword}' is not supported by structured outputs";
            }
        }

        return $problems;
    }

    /** Every property not listed in its object's `required`, recursively. */
    public static function countOptional(?array $schema = null): int
    {
        $schema ??= self::build();
        $count = 0;

        if (($schema['type'] ?? null) === 'object') {
            $required = $schema['required'] ?? [];
            foreach ($schema['properties'] ?? [] as $name => $sub) {
                if (!in_array($name, $required, true)) {
                    $count++;
                }
                if (is_array($sub)) {
                    $count += self::countOptional($sub);
                }
            }
        }

        if (($schema['type'] ?? null) === 'array' && is_array($schema['items'] ?? null)) {
            $count += self::countOptional($schema['items']);
        }

        return $count;
    }

    /**
     * The whole week in one document.
     *
     * KEPT, BUT NO LONGER WHAT GENERATION USES. Plans::generateWeek now asks for training and
     * nutrition in two separate calls (see training() and nutrition() below), because one
     * document meant a short answer on the food half destroyed a perfectly good training week.
     *
     * Still here because the merge helper, the validator and several tests reason about a
     * combined plan, and because the two halves are combined again before persisting. Treat
     * this as the SHAPE of a plan, not as a request.
     */
    public static function build(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['summary', 'expectations', 'sessions', 'days'],
            'properties'           => [
                // Claude's own read on the week, in the user's tone. Shown to
                // the user, so it is part of the deliverable, not metadata.
                'summary' => ['type' => 'string'],

                // Stated plainly so week two doesn't read as failure
                // (SPEC-safety.md — expectation-setting, not a safety rail).
                'expectations' => ['type' => 'string'],

                'sessions' => [
                    'type'  => 'array',
                    'items' => self::session(),
                ],
                'days' => [
                    'type'  => 'array',
                    'items' => self::day(),
                ],
            ],
        ];
    }

    /**
     * The training half, on its own.
     *
     * WHY THE SPLIT EXISTS. A week used to be one request: seven days of sessions AND seven
     * days of meals with structured ingredients, 22k-31k output tokens. Measured over six live
     * buddy generations, three of them came back with one day of nutrition instead of seven —
     * and because the two halves shared a document, the complete and valid training week died
     * with the food. Every leader succeeded; every failure was the second user of a pair, whose
     * prompt carries the shared-session skeleton on top of everything else.
     *
     * Asking for half as much in each call makes that failure mode structurally smaller, and
     * makes it survivable: a short answer on food now costs food.
     *
     * The summary belongs here rather than in both. It is the coach's read on the WEEK, and
     * two independently written summaries would contradict each other.
     */
    public static function training(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['summary', 'expectations', 'sessions'],
            'properties'           => [
                'summary'      => ['type' => 'string'],
                'expectations' => ['type' => 'string'],
                'sessions'     => [
                    'type'  => 'array',
                    'items' => self::session(),
                ],
            ],
        ];
    }

    /**
     * The nutrition half, on its own.
     *
     * No summary: the training call writes it, and this one is told what the week's training
     * looks like so the food can suit it. Asking for a second summary would produce two views
     * of one week that disagree.
     */
    public static function nutrition(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['days'],
            'properties'           => [
                'days' => [
                    'type'  => 'array',
                    'items' => self::day(),
                ],
            ],
        ];
    }

    private static function session(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            // Nearly everything is required. The API caps a schema at 24
            // OPTIONAL parameters, and marking fields optional "just in case"
            // is what blows that budget — it also invites a plan with holes in
            // it. A field the app needs should be required; a field it does not
            // need should not be in the schema at all.
            'required' => [
                'date', 'session_type', 'focus', 'is_committed', 'target_minutes',
                'location', 'warmup_minutes', 'warmup_required', 'warmup_detail',
                'rationale', 'exercises',
            ],
            'properties' => [
                'date'         => ['type' => 'string'],   // YYYY-MM-DD
                'session_type' => ['type' => 'string', 'enum' => [
                    'strength', 'cardio', 'hybrid', 'mobility', 'active_recovery', 'rest',
                ]],
                'focus' => ['type' => 'string', 'enum' => [
                    'upper', 'lower', 'full', 'push', 'pull', 'core', 'conditioning', 'none',
                ]],
                // 'squat' | 'hinge' | 'horizontal' | 'vertical' — drives the
                // core-block pattern match (SPEC-coaching §3.3b). Genuinely
                // optional: only strength days have one.
                'focus_detail' => ['type' => 'string'],

                // §3.3a. The week is the committed sessions; optional ones are
                // bonus and never count against adherence.
                'is_committed'   => ['type' => 'boolean'],
                'target_minutes' => ['type' => 'integer'],
                'location'       => ['type' => 'string', 'enum' => [
                    'full_gym', 'home_gym', 'bodyweight', 'outdoors',
                ]],

                // Prescribed, not left to the user — so required, with
                // warmup_required flagging where a medical modifier applies.
                'warmup_minutes'  => ['type' => 'integer'],
                'warmup_required' => ['type' => 'boolean'],
                'warmup_detail'   => ['type' => 'string'],

                'rationale' => ['type' => 'string'],
                'exercises' => [
                    'type'  => 'array',
                    'items' => self::exercise(),
                ],
            ],
        ];
    }

    private static function exercise(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required' => ['slug', 'block', 'sets', 'target_reps', 'rest_seconds'],
            'properties' => [
                // Must resolve against the exercise library (canonical slug or
                // a known alias). Validation rejects anything else rather than
                // silently creating a new exercise and fragmenting load history.
                'slug'  => ['type' => 'string'],
                'block' => ['type' => 'string', 'enum' => ['warmup', 'main', 'core', 'cooldown']],

                'sets' => ['type' => 'integer'],
                /*
                 * Text, not integer: "8", "8-10", "12/side" and "AMRAP" are all real.
                 *
                 * REQUIRED, so a timed or distance movement still has to put something here,
                 * and the convention has to be stated or the model invents one. A live run
                 * produced "0" for a 45-second plank — which reaches the screen as "3 × 0" and
                 * reads as a broken prescription rather than a hold.
                 *
                 * The description travels with the schema to the model, unlike the comment
                 * above it, which is why this moved out of a PHP comment.
                 */
                'target_reps'  => [
                    'type'        => 'string',
                    'description' => 'Reps as text: "8", "8-10", "12/side", "AMRAP". For a '
                                   . 'timed hold or a loaded carry there are no reps — use "-" '
                                   . 'and put the real dose in target_seconds or '
                                   . 'target_distance_m. Never "0".',
                ],
                'rest_seconds' => ['type' => 'integer'],

                // Genuinely optional — which of these applies depends on the
                // exercise's load_type, so requiring them all would force
                // meaningless zeros onto every row.
                'target_weight_kg'  => ['type' => 'number'],
                'is_per_side'       => ['type' => 'boolean'],
                'target_seconds'    => ['type' => 'integer'],
                'target_distance_m' => ['type' => 'integer'],
                'target_rpe'        => ['type' => 'integer'],

                // Cardio prescription, flattened rather than nested. A nested
                // object would need additionalProperties:false plus its own
                // optional fields, and the schema's optional-parameter budget
                // is 24 total. Flat strings cost one slot each.
                //   "25 min steady, RPE 5-6"
                //   "8 rounds: 60s hard RPE 8 / 90s easy"
                'cardio_prescription' => ['type' => 'string'],

                // Per-exercise "why", for substitutions that would otherwise
                // look arbitrary — "trap bar rather than straight bar; at 6'4"
                // the higher neutral handle is kinder to your lower back."
                'rationale' => ['type' => 'string'],
            ],
        ];
    }

    private static function day(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required' => ['date', 'target_calories', 'target_protein_g',
                           'target_fat_g', 'target_carbs_g', 'meals'],
            'properties' => [
                'date' => ['type' => 'string'],
                // Per-day, not per-week: training and rest days differ.
                'target_calories'  => ['type' => 'integer'],
                'target_protein_g' => ['type' => 'number'],
                'target_fat_g'     => ['type' => 'number'],
                'target_carbs_g'   => ['type' => 'number'],
                'meals' => [
                    'type'  => 'array',
                    'items' => self::meal(),
                ],
                'notes' => ['type' => 'string'],
            ],
        ];
    }

    private static function meal(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            // Macros are required on every meal — a slot the app cannot total
            // is a slot it cannot score, and "adherent" has to mean something.
            'required' => ['slot', 'kind', 'name', 'calories', 'protein_g', 'fat_g', 'carbs_g'],
            'properties' => [
                'slot' => ['type' => 'string', 'enum' => [
                    'breakfast', 'lunch', 'dinner', 'snack_am', 'snack_pm', 'snack_eve',
                ]],
                // 'specified'   = full recipe
                // 'target_only' = macros, user chooses
                // 'unplanned'   = deliberately free (the negotiated eat-out count)
                'kind' => ['type' => 'string', 'enum' => ['specified', 'target_only', 'unplanned']],
                'name' => ['type' => 'string'],

                'calories'  => ['type' => 'integer'],
                'protein_g' => ['type' => 'number'],
                'fat_g'     => ['type' => 'number'],
                'carbs_g'   => ['type' => 'number'],   // NET carbs

                // NON-NEGOTIABLE (SPEC-coaching §3.4): structured, not prose.
                // SPEC-safety §5 validates every ingredient against hard food
                // constraints, and prose cannot be validated. It also makes
                // "ate as planned" a one-tap log.
                //
                // Optional at the schema level only because target_only and
                // unplanned slots have no recipe; a 'specified' meal without
                // ingredients is caught by the validator instead.
                'ingredients' => [
                    'type'  => 'array',
                    'items' => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        // household is required so a user without a food scale
                        // is never handed gram weights they cannot measure.
                        'required'             => ['item', 'household'],
                        'properties' => [
                            'item'      => ['type' => 'string'],
                            'household' => ['type' => 'string'],  // "1 cup", "6 oz"
                            'grams'     => ['type' => 'number'],
                        ],
                    ],
                ],
                'method'       => ['type' => 'string'],
                'prep_minutes' => ['type' => 'integer'],
                'fiber_g'      => ['type' => 'number'],
                'target_note'  => ['type' => 'string'],   // target_only slots
            ],
        ];
    }

    /**
     * The exercise vocabulary the model may use, grouped by pattern.
     *
     * Passed in the prompt rather than as a schema enum: an enum of 90 slugs
     * across every exercise would bloat the schema and, more importantly, the
     * library GROWS by promotion (SPEC-coaching) — Claude may propose something
     * new. Validation resolves slugs against the library and reports unknowns
     * as violations, which is a retry, not a hard stop.
     *
     * @param ?string $access Filter to what a location actually has. A
     *                        bodyweight-only Saturday must not be offered a
     *                        barbell.
     */
    /**
     * The exercise library the model may choose from.
     *
     * @param string|null $access      a single access level, or null for everything
     * @param list<string> $accessSet  the access levels present in the week; the vocabulary is
     *                                 the UNION of what is performable at any of them
     *
     * WHY A UNION AND NOT ONE LEVEL. Access is per DAY, not per user: somebody can have a full
     * gym on Monday and bodyweight on Saturday. Filtering to one level would either hide the
     * barbell work they can do midweek or offer machines on the day they are in a park. The
     * union keeps everything they can do SOMEWHERE that week, and the availability grid — which
     * states the access per day — is what tells the model where each one belongs.
     *
     * This exists because the parameter was written and never passed. Every caller used the
     * bare form, so a bodyweight-only user was shown all 90 exercises, picked a barbell lift,
     * and had the whole plan rejected by checkAvailability afterwards. That is a wasted
     * generation caused by offering a choice that was always going to be refused.
     */
    public static function vocabulary(
        ?string $access = null,
        array $accessSet = [],
        ?array $homeKit = null
    ): array {
        $rows = DB::all(
            'SELECT slug, name, category, pattern, equipment, load_type
             FROM exercises
             ORDER BY category, pattern, slug'
        );

        $out = [];
        foreach ($rows as $r) {
            if ($accessSet !== []) {
                $ok = false;
                foreach ($accessSet as $level) {
                    if (self::availableAt($r, $level, $homeKit)) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) {
                    continue;
                }
            } elseif ($access !== null && !self::availableAt($r, $access, $homeKit)) {
                continue;
            }
            $out[$r['category']][$r['pattern']][] = $r['slug'];
        }
        return $out;
    }

    /**
     * The kit a home-gym question offers, and what each item covers in the library.
     *
     * Six items rather than the library's 32 equipment tokens. Twenty of those unlock exactly
     * one exercise each — pool, battle ropes, dowel, foam roller — so asking about them is a
     * chore that buys nothing, and a long checklist is where people tick everything without
     * reading. Measured against the seeded library: bodyweight alone is 19 exercises, adding
     * dumbbells reaches 29, and all six reach 38 of 90.
     *
     * A 'rack' implies the bench and bar work that goes with it, which is why barbell brings
     * three tokens: someone who says they have a barbell and rack has the setup, and asking
     * separately about a trap bar is the granularity this list exists to avoid.
     */
    public const HOME_KIT = [
        'dumbbell'        => ['dumbbell', 'incline_bench', 'box'],
        'bench'           => ['bench'],
        'resistance_band' => ['resistance_band'],
        'pull_up_bar'     => ['pull_up_bar'],
        'kettlebell'      => ['kettlebell'],
        'barbell'         => ['barbell', 'rack', 'trap_bar', 'plate'],
    ];

    /**
     * Is this exercise performable at the given access level?
     *
     * @param list<string>|null $homeKit  what the user has at home, from
     *                                    training_preferences.home_equipment. NULL means they
     *                                    were never asked, which is NOT the same as owning
     *                                    nothing — see below.
     */
    private static function availableAt(
        array $exercise,
        string $access,
        ?array $homeKit = null
    ): bool {
        $equipment = json_decode((string) ($exercise['equipment'] ?? '[]'), true);
        if (!is_array($equipment) || $equipment === []) {
            return true;   // needs nothing
        }

        return match ($access) {
            'full_gym'   => true,
            'bodyweight' => false,
            'outdoors'   => false,
            /*
             * A home gym is whatever the user says it is.
             *
             * This used to return true unconditionally, making home_gym a synonym for full_gym:
             * somebody with two dumbbells in a spare room was offered a cable tower, a hack
             * squat and a pool. Nothing caught it, because checkAvailability compares the
             * SESSION location against the day, not exercises against equipment — so the user
             * simply got a plan they could not perform.
             *
             * NULL is deliberately permissive. It means the question predates them, and
             * silently taking away their barbell because we never asked would be worse than the
             * bug this fixes. An empty ARRAY is a real answer: nothing, bodyweight only.
             */
            'home_gym'   => $homeKit === null || self::kitCovers($equipment, $homeKit),
            default      => true,
        };
    }

    /** Does this kit satisfy everything the exercise needs? */
    private static function kitCovers(array $needs, array $kit): bool
    {
        $have = [];
        foreach ($kit as $item) {
            foreach (self::HOME_KIT[$item] ?? [$item] as $token) {
                $have[$token] = true;
            }
        }
        foreach ($needs as $token) {
            if (!isset($have[$token])) {
                return false;
            }
        }
        return true;
    }

    /** Canonical slug for a name or alias, or null if unknown to the library. */
    public static function resolveSlug(string $nameOrSlug): ?string
    {
        $needle = trim($nameOrSlug);
        if ($needle === '') {
            return null;
        }

        $row = DB::one('SELECT slug FROM exercises WHERE slug = ?', [$needle]);
        if ($row !== null) {
            return (string) $row['slug'];
        }

        // Aliases collapse "DB Bench" onto "db-bench-press" so load history
        // doesn't fragment across spellings.
        $row = DB::one(
            'SELECT e.slug FROM exercise_aliases a
             JOIN exercises e ON e.id = a.exercise_id
             WHERE a.alias = ?',
            [$needle]
        );
        if ($row !== null) {
            return (string) $row['slug'];
        }

        // Last try: match the display name.
        $row = DB::one('SELECT slug FROM exercises WHERE name = ?', [$needle]);
        return $row !== null ? (string) $row['slug'] : null;
    }
}
