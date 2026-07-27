<?php
declare(strict_types=1);

/**
 * The shared session skeleton (SPEC-coaching §10.6, §10.1, §10.2a).
 *
 * "Both users are at the same rack doing the same movement in the same order — and one is
 * goblet squatting 50 lb for 3 x 10 while the other back squats 95 lb for 4 x 8. This is how a
 * competent trainer handles a mismatched pair: one session, two prescriptions."
 *
 * HOW THIS WORKS, AND WHY IT IS NOT A THIRD GENERATION CALL.
 *
 * §10.6 describes generating a skeleton for the pair and then each user's prescriptions against
 * it. Read literally that is three model calls per pair per week. But the Sunday cron already
 * generates both paired users in the same sweep, minutes apart, each with its own claim — so
 * the FIRST to generate becomes the leader, its shared-day sessions are stamped with a skeleton
 * key, and the SECOND reads that skeleton and matches it.
 *
 * Two calls rather than three, no new orchestration, and every existing call site keeps working
 * untouched. The cost is that the skeleton is derived from whoever went first rather than being
 * neutral between them — acceptable, because §10.0 says the pairing outranks individual
 * optimisation anyway, and a genuinely neutral skeleton would still have to favour the more
 * constrained user to be safe.
 *
 * WHAT IS SHARED AND WHAT IS NOT. §10.1's table: day, slot, session type, focus, movement
 * patterns and order, warm-up timing and equipment context are shared. Loads, rep ranges, rest,
 * exercise VARIANT and set counts stay individual. §10.2a adds the core block, in full.
 *
 * DIVERGENCE IS NORMAL (§10.2). Where matching would require prescribing something the
 * follower's hard constraints forbid, that exercise changes for them — same slot, same time.
 * The follower's plan is still validated on its own, so a skeleton can never talk anybody into
 * a movement they must not do.
 */
final class BuddySkeleton
{
    /**
     * A stable key for one shared session.
     *
     * Derived from the pair and the date rather than random, so both users' rows carry the same
     * value without one having to read the other's id — and so a regenerated week re-links to
     * the same skeleton instead of orphaning it.
     *
     * CHAR(36) in the schema, so the shape is a UUID-ish hash rather than anything meaningful.
     */
    public static function keyFor(int $pairId, string $date): string
    {
        $h = hash('sha256', "buddy:{$pairId}:{$date}");
        // Formatted as a UUID so the column reads like what it is declared as.
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4),
            substr($h, 16, 4), substr($h, 20, 12)
        );
    }

    /**
     * The skeleton this user should follow, if there is one.
     *
     * Returns null when there is nothing to follow: unpaired, buddy away, no shared days, or the
     * buddy has not generated their week yet — in which case THIS user is the leader and simply
     * generates normally.
     *
     * Returns ['buddy_name', 'days' => [date => session shape]] otherwise.
     */
    public static function toFollow(int $userId, string $weekStart): ?array
    {
        $pair = BuddySchedule::activePair($userId);
        if ($pair === null) {
            return null;
        }

        // §10.5: an away buddy means a solo week, so there is nothing to match.
        if (BuddyAbsence::availableFor($userId, $weekStart)['available'] === false) {
            return null;
        }

        $pairId  = (int) $pair['id'];
        $buddyId = (int) $pair['user_lo'] === $userId
            ? (int) $pair['user_hi']
            : (int) $pair['user_lo'];

        $sharedDays = BuddySchedule::agreedDays($pairId);
        if ($sharedDays === []) {
            return null;
        }

        /*
         * The buddy's live plan for this week.
         *
         * Only a live one counts: a superseded version is what they used to be doing, and
         * matching a plan that has since been revised would put the pair back out of step.
         */
        $buddyPlan = DB::one(
            'SELECT id FROM plan_versions
             WHERE user_id = ? AND week_start = ? AND superseded_at IS NULL
             ORDER BY id DESC LIMIT 1',
            [$buddyId, $weekStart]
        );
        if ($buddyPlan === null) {
            // They have not generated yet, so this user leads.
            return null;
        }

        /*
         * Their sessions on the shared days, with the exercises that give the skeleton its
         * shape.
         *
         * Committed sessions only: an optional extra of theirs is a bonus they may or may not
         * do (§3.3a), and building the pair's shared session around it would be matching
         * something that might not happen.
         */
        $rows = DB::all(
            'SELECT ps.id, ps.session_date, ps.session_type, ps.focus, ps.focus_detail,
                    ps.target_minutes, ps.location, ps.warmup_minutes, ps.warmup_detail
             FROM prescribed_sessions ps
             WHERE ps.plan_version_id = ? AND ps.is_committed = 1
             ORDER BY ps.session_date, ps.sort_order',
            [(int) $buddyPlan['id']]
        );

        /*
         * The pair's resolved schedule, NOT the leader's raw session values.
         *
         * This distinction is load-bearing and getting it wrong produced a real failure. The
         * leader's row says what THEY were prescribed — 60 minutes at a full gym — while the
         * shared day resolves to the shorter window and the facility the pair agreed on, which
         * may be better OR worse than either user's own grid says (§10.3). Handing the follower
         * the leader's
         * numbers under a "COPY EXACTLY" instruction tells them to fit a 60-minute full-gym
         * session into their own 45-minute home window, and a measured run responded by pushing
         * the overflow onto days the follower had marked unavailable.
         *
         * So minutes and location come from effective(), which is the same resolution the
         * availability grid in the prompt already reflects. The SHAPE still comes from the
         * leader's session, which is the point of the skeleton.
         */
        $effective = BuddySchedule::effective($userId, $pairId);

        $days = [];
        foreach ($rows as $r) {
            $date    = (string) $r['session_date'];
            $weekday = (int) date('N', strtotime($date));
            if (!in_array($weekday, $sharedDays, true)) {
                continue;   // their own solo day, nothing to match
            }

            $eff = $effective[$weekday] ?? null;

            /*
             * The warm-up is scaled rather than copied: ten minutes out of the leader's 60 is a
             * sixth of the session, and the same ten out of 45 is nearly a quarter. Rounded to
             * the nearest minute and floored at 5, since a 2-minute warm-up is not one.
             */
            $leaderMin  = $r['target_minutes'] === null ? null : (int) $r['target_minutes'];
            $sharedMin  = $eff === null || $eff['minutes'] === null
                ? $leaderMin
                : (int) $eff['minutes'];
            $leaderWarm = $r['warmup_minutes'] === null ? null : (int) $r['warmup_minutes'];
            $sharedWarm = $leaderWarm;
            if ($leaderWarm !== null && $leaderMin !== null && $sharedMin !== null
                && $leaderMin > 0 && $sharedMin < $leaderMin) {
                $sharedWarm = max(5, (int) round($leaderWarm * $sharedMin / $leaderMin));
            }

            $days[$date] = [
                'session_type'   => (string) $r['session_type'],
                'focus'          => (string) $r['focus'],
                'focus_detail'   => $r['focus_detail'],
                'target_minutes' => $sharedMin,
                'location'       => $eff === null || $eff['access'] === null
                    ? $r['location']
                    : (string) $eff['access'],
                'warmup_minutes' => $sharedWarm,
                'warmup_detail'  => $r['warmup_detail'],
                // The shape that matters: pattern and order for the main work, and the core
                // block in full (§10.2a).
                'main'           => self::blockShape((int) $r['id'], ['main']),
                'core'           => self::blockShape((int) $r['id'], ['core']),
            ];
        }

        if ($days === []) {
            return null;
        }

        $buddy = DB::one('SELECT display_name FROM users WHERE id = ?', [$buddyId]);

        return [
            'pair_id'    => $pairId,
            'buddy_name' => (string) ($buddy['display_name'] ?? 'their buddy'),
            'days'       => $days,
        ];
    }

    /**
     * One block's shape: movement pattern and order, plus what it was for reference.
     *
     * The PATTERN is the shared thing, not the exercise. §10.1: "Movement patterns and order"
     * are shared while the "exercise variant" is individual — a hinge is a hinge whether it is a
     * trap-bar deadlift or a kettlebell swing, and that is what lets a mismatched pair train
     * side by side.
     *
     * For the CORE block, the exercise itself is shared too (§10.2a), so the name is given as
     * something to reproduce rather than merely as context. The caller decides which reading
     * applies; this just reports both.
     */
    private static function blockShape(int $sessionId, array $blocks): array
    {
        $in = implode(',', array_fill(0, count($blocks), '?'));
        $rows = DB::all(
            "SELECT e.name, e.slug, e.pattern, pe.sets, pe.target_reps, pe.target_seconds,
                    pe.target_distance_m
             FROM prescribed_exercises pe
             JOIN exercises e ON e.id = pe.exercise_id
             WHERE pe.session_id = ? AND pe.block IN ({$in})
             ORDER BY pe.sort_order",
            array_merge([$sessionId], $blocks)
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name'    => (string) $r['name'],
                'slug'    => (string) $r['slug'],
                'pattern' => (string) $r['pattern'],
                'sets'    => $r['sets'] === null ? null : (int) $r['sets'],
                'reps'    => $r['target_reps'],
                'seconds' => $r['target_seconds'] === null ? null : (int) $r['target_seconds'],
                /*
                 * Loaded carries are measured in METRES, and leaving this out meant a Farmer
                 * Carry reached the follower with no distance at all — so it could not
                 * reproduce a dose it was never told. §10.2a says the core block is identical,
                 * and a carry with no distance is not the same prescription.
                 */
                'distance_m' => $r['target_distance_m'] === null
                    ? null : (int) $r['target_distance_m'],
            ];
        }
        return $out;
    }

    /**
     * Render the skeleton as prompt text.
     *
     * Deliberately explicit about which parts to copy and which to decide for themselves,
     * because the failure modes run in both directions: a model told only "match your buddy"
     * copies the loads too, and one told only "you have a buddy" ignores the shape entirely.
     */
    public static function promptBlock(array $skeleton): string
    {
        $out = [];
        $out[] = '=== SHARED SESSIONS WITH ' . strtoupper($skeleton['buddy_name']) . ' ===';
        $out[] = 'These days are trained TOGETHER, and their plan is already written. Match its '
               . 'shape so the two of them can train side by side.';
        $out[] = '';
        $out[] = 'COPY EXACTLY: the day, the session type, the focus, and the ORDER of movement '
               . 'patterns in the main block.';
        /*
         * Said explicitly because it is the one place a "match your buddy" instruction can push
         * the model into an invalid plan rather than merely a suboptimal one.
         *
         * The length and location below are already the PAIR's resolved values — the shorter
         * window and the access both of them have — not the leader's. A measured run handed the
         * leader's 60-minute full-gym figures to a follower with a 45-minute home window, and
         * the model put the overflow on three days the follower had marked unavailable.
         */
        $out[] = 'The length and location given below are the SHARED ones, already narrowed to '
               . 'what suits both of them. Use exactly those. Never widen a session to match '
               . 'what their buddy is doing, and never add a day to fit work that will not fit.';
        $out[] = 'COPY THE CORE BLOCK IN FULL: same exercises, same sets, same reps or holds. '
               . 'It is mostly floor work, so there is no loading problem, and ten minutes of '
               . 'matched work side by side is where a pair actually talks.';
        /*
         * Scoped to the MAIN block, deliberately.
         *
         * This used to say "decide the variant and the set counts" without saying where, while
         * the core instruction below said to reproduce the core exactly. Two lines of the same
         * prompt telling the model opposite things about the same block, and a live run
         * resolved the contradiction by dropping a core exercise.
         */
        $out[] = 'DECIDE FOR THEM, IN THE MAIN BLOCK: the exercise VARIANT within each pattern, '
               . 'the loads, the rep ranges, the set counts, and the rest intervals. A hinge is '
               . 'a hinge whether it is a trap-bar deadlift or a kettlebell swing.';
        $out[] = '';
        /*
         * Divergence needs a CONSTRAINT, not a preference.
         *
         * This used to end "...or something that does not serve their goal", which is a
         * loophole wide enough to drive anything through — a beginner's goal can be argued to
         * not need any particular exercise, and a live run dropped a core exercise on exactly
         * that reasoning. §10.0 already settled the trade: the pairing outranks individual
         * optimisation. Divergence is for things the user CANNOT do, not for things that would
         * be marginally better done differently.
         *
         * Substitution also has to REPLACE rather than remove. "Change that movement" was read
         * as licence to drop one, which leaves the pair doing different amounts of work.
         */
        $out[] = 'DIVERGE ONLY WHERE YOU MUST: if matching a movement would mean prescribing '
               . 'something this user\'s hard constraints forbid, or equipment they do not have, '
               . 'REPLACE that one movement and keep the day, the slot and the count. Same gym, '
               . 'same hour, one different exercise. That is expected, not a failure.';
        $out[] = 'A movement being merely non-ideal for them is NOT a reason to change it. '
               . 'Training alongside their buddy is worth more than a marginally better '
               . 'exercise choice, and dropping a movement rather than swapping it leaves the '
               . 'two of them doing different amounts of work.';

        foreach ($skeleton['days'] as $date => $day) {
            $out[] = '';
            $name = date('D', strtotime((string) $date));
            $bits = [$day['session_type']];
            if (($day['focus'] ?? 'none') !== 'none') {
                $bits[] = $day['focus'];
            }
            if ($day['target_minutes'] !== null) {
                $bits[] = $day['target_minutes'] . ' min';
            }
            if ($day['location'] !== null) {
                $bits[] = (string) $day['location'];
            }
            $out[] = "  {$name} {$date}: " . implode(', ', $bits);

            if ($day['warmup_minutes'] !== null) {
                $out[] = "    warm-up: {$day['warmup_minutes']} min";
            }

            if ($day['main'] !== []) {
                $patterns = [];
                foreach ($day['main'] as $ex) {
                    // The pattern is what must match; their exercise is shown so the model can
                    // see what a sensible variant of it looks like.
                    $patterns[] = "{$ex['pattern']} (they do {$ex['name']})";
                }
                $out[] = '    main, in this order: ' . implode(' then ', $patterns);
                /*
                 * Said because a measured run did exactly this.
                 *
                 * The leader opened with a vertical_pull the follower had no bar for, so the
                 * follower substituted a dumbbell row — and then used a dumbbell row again for
                 * the row that was already in the next slot, listing the same movement twice as
                 * two exercises. Three sets of one exercise written as two entries is a broken
                 * plan on screen, and substitution is exactly when the temptation arises.
                 */
                $out[] = '      Each of these is a DIFFERENT exercise. If you substitute one, '
                       . 'pick something not already in this session — never list the same '
                       . 'movement twice.';
            }

            if ($day['core'] !== []) {
                /*
                 * The dose, spelled out so it cannot be copied as prose.
                 *
                 * A measured run rendered "Plank 3x40s hold" here — because the leader's
                 * target_reps was the free text "40s hold" and this took it verbatim — and the
                 * follower copied that string into ITS target_reps, which came back out of the
                 * database as "3xhold" with the number gone. A prescription that reads "3 x hold"
                 * is on somebody's screen, so the rendering is now explicit about which field is
                 * which.
                 *
                 * TIMED WORK IS DESCRIBED BY ITS SECONDS, never by the rep text: target_seconds
                 * is structured and target_reps is prose that may or may not repeat it. Reps are
                 * only mentioned when there is no seconds value, i.e. when the movement really is
                 * counted rather than held.
                 */
                $core = [];
                foreach ($day['core'] as $ex) {
                    $sets = $ex['sets'];
                    $spec = $ex['name'];

                    if (($ex['distance_m'] ?? null) !== null) {
                        // Carries first: a loaded carry has a distance and often a duration
                        // too, and the distance is the prescription.
                        $spec .= $sets === null
                            ? " {$ex['distance_m']} metres"
                            : " {$sets} sets of {$ex['distance_m']} metres";
                    } elseif ($ex['seconds'] !== null) {
                        $spec .= $sets === null
                            ? " {$ex['seconds']} seconds"
                            : " {$sets} sets of {$ex['seconds']} seconds";
                    } elseif ($ex['reps'] !== null && trim((string) $ex['reps']) !== '') {
                        // Strip any wording that is not the count; the model reproduces what it
                        // is shown, so showing it "10 each side" invites "each side" into a
                        // numeric field.
                        $reps = trim(preg_replace(
                            '/\b(each side|per side|each leg|per leg|hold|holds|carry|total)\b/i',
                            '',
                            (string) $ex['reps']
                        ) ?? '');
                        $reps = trim(preg_replace('/\s+/', ' ', $reps) ?? '');
                        if ($reps === '') {
                            $reps = (string) $ex['reps'];
                        }
                        $spec .= $sets === null
                            ? " {$reps} reps"
                            : " {$sets} sets of {$reps} reps";
                    }
                    $core[] = $spec;
                }
                $out[] = '    core, IDENTICAL — same exercises, same sets, same dose: '
                       . implode('; ', $core);
                /*
                 * The COUNT, said out loud.
                 *
                 * "Give every one of these" was already here and a measured run still returned
                 * one core exercise where the leader had two. A number is harder to skip past
                 * than a quantifier, and it gives the model something to check its own answer
                 * against before returning it.
                 */
                $n = count($core);
                $out[] = sprintf(
                    '      That is %d core %s. Return all %d, in that order. Do not shorten the '
                    . 'list, do not merge two into one, and do not substitute here — the core '
                    . 'block is floor work, so there is no equipment reason to change it.',
                    $n,
                    $n === 1 ? 'exercise' : 'exercises',
                    $n
                );
            }
        }

        return implode("\n", $out);
    }

    /**
     * Stamp the shared-day sessions of a freshly persisted plan.
     *
     * Called for BOTH users: the leader stamps so the follower can find the skeleton, and the
     * follower stamps so the two rows carry the same key and adherence can tell a shared session
     * from a coincidence.
     *
     * Keyed on (pair, date) rather than on a generated id, so both sides arrive at the same value
     * independently and a regenerated week re-links rather than orphaning.
     */
    public static function stamp(int $userId, int $planVersionId, string $weekStart): int
    {
        $pair = BuddySchedule::activePair($userId);
        if ($pair === null) {
            return 0;
        }
        $pairId = (int) $pair['id'];
        $shared = BuddySchedule::agreedDays($pairId);
        if ($shared === []) {
            return 0;
        }

        $stamped = 0;
        foreach (DB::all(
            'SELECT id, session_date FROM prescribed_sessions
             WHERE plan_version_id = ? AND is_committed = 1',
            [$planVersionId]
        ) as $r) {
            $date = (string) $r['session_date'];
            if (!in_array((int) date('N', strtotime($date)), $shared, true)) {
                continue;
            }
            DB::run(
                'UPDATE prescribed_sessions SET shared_skeleton_key = ? WHERE id = ?',
                [self::keyFor($pairId, $date), (int) $r['id']]
            );
            $stamped++;
        }
        return $stamped;
    }
}
