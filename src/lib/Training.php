<?php
declare(strict_types=1);

/**
 * Training logs: what was actually done, against what was prescribed.
 *
 * Logging is per-EXERCISE, not per-set (SPEC-coaching §4.4): actual weight,
 * actual reps, one RPE. About three taps. Per-set logging is more accurate and
 * gets abandoned by week three, and abandoned logging ends the app.
 *
 * Adherence counts COMMITTED sessions only (§3.3a). An optional session is a
 * bonus and never a debt — ignoring every optional day is still a perfect week.
 * That asymmetry is the whole reason the flag exists, so it is enforced here
 * rather than left to whoever reads the numbers.
 */
final class Training
{
    public const STATUSES = ['completed', 'partial', 'skipped', 'substituted'];

    /**
     * What a FREE-LOGGED session can be (007).
     *
     * No 'rest': you do not log a rest day, the absence is the record. A session
     * logged against a prescription takes its type from the plan instead, so
     * this only applies when prescribed_session_id is null.
     */
    public const TYPES = ['strength', 'cardio', 'hybrid', 'mobility', 'active_recovery'];

    /** Today's prescribed sessions plus anything already logged against them. */
    public static function day(int $userId, string $date): array
    {
        $prescribed = DB::all(
            'SELECT ps.id, ps.session_type, ps.focus, ps.focus_detail, ps.is_committed,
                    ps.target_minutes, ps.location, ps.warmup_minutes, ps.warmup_detail,
                    ps.warmup_required, ps.rationale
             FROM prescribed_sessions ps
             JOIN plan_versions pv ON pv.id = ps.plan_version_id
             WHERE pv.user_id = ? AND pv.superseded_at IS NULL AND ps.session_date = ?
             ORDER BY ps.is_committed DESC, ps.sort_order, ps.id',
            [$userId, $date]
        );

        $sessions = [];
        foreach ($prescribed as $p) {
            $sessions[] = [
                'prescribed_session_id' => (int) $p['id'],
                'session_type'    => (string) $p['session_type'],
                'focus'           => $p['focus'],
                'is_committed'    => (bool) $p['is_committed'],
                'target_minutes'  => self::intOrNull($p['target_minutes']),
                'location'        => $p['location'],
                'warmup_minutes'  => self::intOrNull($p['warmup_minutes']),
                'warmup_detail'   => $p['warmup_detail'],
                'warmup_required' => (bool) $p['warmup_required'],
                'focus_detail'    => $p['focus_detail'],
                // The coach's "why" for this session. Shown to the user: a
                // substitution without a reason reads as arbitrary (§3.3).
                'rationale'       => $p['rationale'],
                'exercises'       => self::prescribedExercises((int) $p['id']),
                'logged'          => null,
            ];
        }

        // Attach logs. Keyed on prescribed_session_id, with unprescribed sessions
        // appended — someone who just went for a run should see it recorded.
        $dayRow = DB::one(
            'SELECT id FROM logged_days WHERE user_id = ? AND log_date = ?', [$userId, $date]
        );
        if ($dayRow !== null) {
            foreach (self::loggedSessions((int) $dayRow['id']) as $log) {
                $matched = false;
                foreach ($sessions as &$s) {
                    if ($s['prescribed_session_id'] === $log['prescribed_session_id']) {
                        $s['logged'] = $log;
                        $matched = true;
                        break;
                    }
                }
                unset($s);
                if (!$matched) {
                    $sessions[] = [
                        'prescribed_session_id' => null,
                        // The row's own type (007). Pre-migration rows have
                        // none, and 'strength' would be a guess dressed as a
                        // fact — the client shows a neutral label instead.
                        'session_type'   => $log['session_type'] ?? null,
                        'focus'          => null,
                        // An unprescribed session cannot be a commitment, so it
                        // never counts toward or against adherence.
                        'is_committed'   => false,
                        'target_minutes' => null,
                        'location'       => null,
                        'warmup_minutes' => null,
                        'warmup_detail'  => null,
                        'warmup_required' => false,
                        'focus_detail'   => null,
                        'rationale'      => null,
                        'exercises'      => [],
                        'logged'         => $log,
                    ];
                }
            }
        }

        return ['date' => $date, 'sessions' => $sessions];
    }

    /**
     * Log a session and its exercises in one write.
     *
     * One call rather than a session POST followed by N exercise POSTs: the user
     * taps "done" once, and a half-written session after a dropped connection is
     * worse than none.
     */
    public static function logSession(int $userId, string $date, array $body): array
    {
        $status = Validate::enum($body['status'] ?? null, self::STATUSES);
        if ($status === null) {
            return ['ok' => false, 'error' => 'A session needs a status: '
                                              . implode(', ', self::STATUSES) . '.'];
        }

        $prescribedId = Validate::id($body['prescribed_session_id'] ?? null);
        if ($prescribedId !== null && !self::ownsPrescribedSession($userId, $prescribedId)) {
            return ['ok' => false, 'error' => 'That session is not in your plan.'];
        }

        $dayId = Nutrition::dayId($userId, $date);

        $exercises = is_array($body['exercises'] ?? null) ? $body['exercises'] : [];
        $resolved  = [];
        foreach ($exercises as $ex) {
            if (!is_array($ex)) {
                continue;
            }
            $exerciseId = self::resolveExercise($ex);
            if ($exerciseId === null) {
                return ['ok' => false, 'error' => 'Unknown exercise: '
                        . (string) ($ex['slug'] ?? $ex['name'] ?? '?')];
            }
            $resolved[] = [$exerciseId, $ex];
        }

        $sessionId = DB::tx(function () use ($userId, $dayId, $prescribedId, $status, $body, $resolved): int {
            // Re-logging the same prescribed session replaces the earlier log
            // rather than adding a second one: correcting a mistake is common,
            // and two rows for one session would double-count adherence.
            if ($prescribedId !== null) {
                DB::run(
                    'DELETE FROM logged_sessions WHERE logged_day_id = ? AND prescribed_session_id = ?',
                    [$dayId, $prescribedId]
                );
            }

            $sid = DB::insert(
                'INSERT INTO logged_sessions
                    (user_id, logged_day_id, prescribed_session_id, session_type, status,
                     actual_minutes, session_rpe, notes, trained_with_buddy)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId, $dayId, $prescribedId,
                    // Only a free-logged session carries its own type; one
                    // logged against a prescription reads it off the plan, and
                    // storing a second copy is a second thing to disagree.
                    $prescribedId === null
                        ? Validate::enum($body['session_type'] ?? null, self::TYPES)
                        : null,
                    $status,
                    Validate::intRange($body['actual_minutes'] ?? null, 1, 600),
                    Validate::intRange($body['session_rpe'] ?? null, 1, 10),
                    Validate::str($body['notes'] ?? null, 1, 2000),
                    (int) (Validate::bool($body['trained_with_buddy'] ?? null) ?? false),
                ]
            );

            foreach ($resolved as [$exerciseId, $ex]) {
                DB::run(
                    'INSERT INTO logged_exercises
                        (logged_session_id, exercise_id, prescribed_exercise_id, sets_completed,
                         actual_reps, actual_weight_kg, actual_seconds, actual_distance_m,
                         rpe, skipped, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $sid, $exerciseId,
                        Validate::id($ex['prescribed_exercise_id'] ?? null),
                        Validate::intRange($ex['sets_completed'] ?? null, 0, 50),
                        Validate::str($ex['actual_reps'] ?? null, 1, 20),
                        Validate::floatRange($ex['actual_weight_kg'] ?? null, 0, 1000),
                        Validate::intRange($ex['actual_seconds'] ?? null, 0, 65535),
                        Validate::intRange($ex['actual_distance_m'] ?? null, 0, 65535),
                        Validate::intRange($ex['rpe'] ?? null, 1, 10),
                        (int) (Validate::bool($ex['skipped'] ?? null) ?? false),
                        Validate::str($ex['notes'] ?? null, 1, 300),
                    ]
                );
            }

            return $sid;
        });

        self::recountSessions($userId, $date);

        return ['ok' => true, 'logged_session_id' => $sessionId, 'day' => self::day($userId, $date)];
    }

    /** Remove a logged session. */
    public static function deleteSession(int $userId, int $sessionId): array
    {
        $row = DB::one(
            'SELECT ls.id, ld.log_date
             FROM logged_sessions ls
             JOIN logged_days ld ON ld.id = ls.logged_day_id
             WHERE ls.id = ? AND ls.user_id = ?',
            [$sessionId, $userId]
        );
        if ($row === null) {
            return ['ok' => false, 'error' => 'No such session.'];
        }
        DB::run('DELETE FROM logged_sessions WHERE id = ?', [$sessionId]);
        self::recountSessions($userId, (string) $row['log_date']);
        return ['ok' => true, 'day' => self::day($userId, (string) $row['log_date'])];
    }

    /**
     * Recompute the day's prescribed/completed counts.
     *
     * COMMITTED sessions only, on both sides of the ratio (§3.3a). Counting an
     * optional session as completed against a committed prescription would let
     * an optional day paper over a missed committed one, which is the opposite
     * of what the distinction is for.
     */
    public static function recountSessions(int $userId, string $date): void
    {
        $dayRow = DB::one(
            'SELECT id FROM logged_days WHERE user_id = ? AND log_date = ?', [$userId, $date]
        );
        if ($dayRow === null) {
            return;
        }
        $dayId = (int) $dayRow['id'];

        $prescribed = (int) (DB::one(
            "SELECT COUNT(*) AS n
             FROM prescribed_sessions ps
             JOIN plan_versions pv ON pv.id = ps.plan_version_id
             WHERE pv.user_id = ? AND pv.superseded_at IS NULL
               AND ps.session_date = ? AND ps.is_committed = 1
               AND ps.session_type <> 'rest'",
            [$userId, $date]
        )['n'] ?? 0);

        // 'partial' counts as completed: the user turned up and did the work.
        // Adherence is about showing up, and grading a short session as a miss
        // is how you teach someone not to log the short ones.
        $completed = (int) (DB::one(
            "SELECT COUNT(*) AS n
             FROM logged_sessions ls
             JOIN prescribed_sessions ps ON ps.id = ls.prescribed_session_id
             WHERE ls.logged_day_id = ? AND ps.is_committed = 1
               AND ls.status IN ('completed', 'partial', 'substituted')",
            [$dayId]
        )['n'] ?? 0);

        DB::run(
            'UPDATE logged_days SET sessions_prescribed = ?, sessions_completed = ? WHERE id = ?',
            [$prescribed, $completed, $dayId]
        );
    }

    /**
     * Find exercises by what the user typed.
     *
     * Free-logging needs this: resolveExercise() takes an exact slug, alias, or
     * name, which is fine for a client that already holds the id and useless for
     * a person typing "leg pr". The library is 90 exercises and 53 aliases, so
     * this is a small LIKE against an indexed column rather than anything clever.
     *
     * Aliases are searched too and reported under their canonical exercise —
     * someone who types "bench" should find "Barbell Bench Press", and the log
     * row must reference the canonical id either way.
     *
     * load_type comes back because the log form depends on it: a plank wants
     * seconds, a run wants distance, a press wants kilos. Asking for kg on a
     * plank is how you teach someone the app does not understand training.
     */
    public static function searchExercises(string $query, int $limit = 12): array
    {
        $q = Validate::str($query, 1, 80);
        if ($q === null) {
            return [];
        }

        // Escape the LIKE metacharacters. Without this a user typing "100%"
        // matches everything, which reads as the search being broken.
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $lim  = max(1, min(25, $limit));

        $rows = DB::all(
            "SELECT e.id, e.slug, e.name, e.category, e.load_type, e.pattern
             FROM exercises e
             WHERE e.name LIKE ? OR e.slug LIKE ?
                OR EXISTS (
                    SELECT 1 FROM exercise_aliases a
                    WHERE a.exercise_id = e.id AND a.alias LIKE ?
                )
             ORDER BY
                -- A prefix match is what the user meant; an interior match is a
                -- consolation. Without this ordering, typing 'press' surfaces
                -- 'Leg Press' above 'Press' on id alone.
                CASE WHEN e.name LIKE ? THEN 0 ELSE 1 END,
                CHAR_LENGTH(e.name),
                e.name
             LIMIT {$lim}",
            [$like, $like, $like, str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%']
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'exercise_id' => (int) $r['id'],
                'slug'        => (string) $r['slug'],
                'name'        => (string) $r['name'],
                'category'    => (string) $r['category'],
                // 'weight' | 'bodyweight' | 'assisted' | 'time' | 'distance'
                'load_type'   => (string) $r['load_type'],
                'pattern'     => (string) $r['pattern'],
            ];
        }
        return $out;
    }

    /**
     * Recent load for one exercise, newest first.
     *
     * What the progression logic reads, and what the UI shows next to the input
     * so the user can see last week's numbers while entering this week's.
     */
    public static function history(int $userId, int $exerciseId, int $limit = 10): array
    {
        $out = [];
        foreach (DB::all(
            'SELECT le.sets_completed, le.actual_reps, le.actual_weight_kg, le.actual_seconds,
                    le.actual_distance_m, le.rpe, le.notes, ld.log_date
             FROM logged_exercises le
             JOIN logged_sessions ls ON ls.id = le.logged_session_id
             JOIN logged_days ld     ON ld.id = ls.logged_day_id
             WHERE ls.user_id = ? AND le.exercise_id = ? AND le.skipped = 0
             ORDER BY ld.log_date DESC, le.id DESC
             LIMIT ' . max(1, min(50, $limit)),
            [$userId, $exerciseId]
        ) as $r) {
            $out[] = [
                'date'           => (string) $r['log_date'],
                'sets_completed' => self::intOrNull($r['sets_completed']),
                'actual_reps'    => $r['actual_reps'],
                'weight_kg'      => $r['actual_weight_kg'] === null ? null : (float) $r['actual_weight_kg'],
                'seconds'        => self::intOrNull($r['actual_seconds']),
                'distance_m'     => self::intOrNull($r['actual_distance_m']),
                'rpe'            => self::intOrNull($r['rpe']),
                'notes'          => $r['notes'],
            ];
        }
        return $out;
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * Resolve an exercise by id, slug, or alias.
     *
     * Aliases matter: the library is seeded with 53 of them, and a client that
     * only knows the name a user typed should still find the canonical row
     * rather than failing the whole log.
     */
    private static function resolveExercise(array $ex): ?int
    {
        $id = Validate::id($ex['exercise_id'] ?? null);
        if ($id !== null) {
            $row = DB::one('SELECT id FROM exercises WHERE id = ?', [$id]);
            return $row === null ? null : (int) $row['id'];
        }

        $key = Validate::str($ex['slug'] ?? $ex['name'] ?? null, 1, 120);
        if ($key === null) {
            return null;
        }

        $row = DB::one('SELECT id FROM exercises WHERE slug = ?', [$key]);
        if ($row !== null) {
            return (int) $row['id'];
        }
        $row = DB::one('SELECT exercise_id FROM exercise_aliases WHERE alias = ?', [$key]);
        if ($row !== null) {
            return (int) $row['exercise_id'];
        }
        // Name match last: slugs and aliases are exact, a display name is not.
        $row = DB::one('SELECT id FROM exercises WHERE name = ?', [$key]);
        return $row === null ? null : (int) $row['id'];
    }

    private static function ownsPrescribedSession(int $userId, int $prescribedId): bool
    {
        return DB::one(
            'SELECT ps.id FROM prescribed_sessions ps
             JOIN plan_versions pv ON pv.id = ps.plan_version_id
             WHERE ps.id = ? AND pv.user_id = ?',
            [$prescribedId, $userId]
        ) !== null;
    }

    private static function prescribedExercises(int $prescribedSessionId): array
    {
        $out = [];
        // The column is session_id, not prescribed_session_id — the FK name and
        // the logged_exercises equivalent differ, which is easy to get wrong.
        foreach (DB::all(
            'SELECT pe.id, pe.exercise_id, pe.block, pe.sort_order, pe.sets, pe.target_reps,
                    pe.target_weight_kg, pe.is_per_side, pe.target_seconds,
                    pe.target_distance_m, pe.target_rpe, pe.rest_seconds,
                    pe.cardio_detail, pe.rationale, e.slug, e.name, e.pattern
             FROM prescribed_exercises pe
             JOIN exercises e ON e.id = pe.exercise_id
             WHERE pe.session_id = ?
             ORDER BY FIELD(pe.block, \'warmup\', \'main\', \'core\', \'cooldown\'),
                      pe.sort_order, pe.id',
            [$prescribedSessionId]
        ) as $e) {
            $out[] = [
                'prescribed_exercise_id' => (int) $e['id'],
                'exercise_id'      => (int) $e['exercise_id'],
                'slug'             => (string) $e['slug'],
                'name'             => (string) $e['name'],
                'pattern'          => $e['pattern'],
                'block'            => (string) $e['block'],
                'sets'             => self::intOrNull($e['sets']),
                'target_reps'      => $e['target_reps'],
                'target_weight_kg' => $e['target_weight_kg'] === null ? null : (float) $e['target_weight_kg'],
                // Per-side dumbbell work: the UI reads "2 x 20 lb" from this.
                'is_per_side'      => (bool) $e['is_per_side'],
                'target_seconds'   => self::intOrNull($e['target_seconds']),
                'target_distance_m' => self::intOrNull($e['target_distance_m']),
                'target_rpe'       => self::intOrNull($e['target_rpe']),
                'rest_seconds'     => self::intOrNull($e['rest_seconds']),
                'cardio_detail'    => $e['cardio_detail'] === null
                                      ? null : json_decode((string) $e['cardio_detail'], true),
                'rationale'        => $e['rationale'],
            ];
        }
        return $out;
    }

    private static function loggedSessions(int $dayId): array
    {
        $sessions = DB::all(
            'SELECT id, prescribed_session_id, session_type, status, actual_minutes,
                    session_rpe, notes, trained_with_buddy, logged_at
             FROM logged_sessions WHERE logged_day_id = ? ORDER BY id',
            [$dayId]
        );
        if ($sessions === []) {
            return [];
        }

        $ids = array_map(static fn(array $s): int => (int) $s['id'], $sessions);
        $in  = implode(',', array_fill(0, count($ids), '?'));

        $bySession = [];
        foreach (DB::all(
            "SELECT le.*, e.slug, e.name
             FROM logged_exercises le
             JOIN exercises e ON e.id = le.exercise_id
             WHERE le.logged_session_id IN ({$in}) ORDER BY le.id",
            $ids
        ) as $e) {
            $bySession[(int) $e['logged_session_id']][] = [
                'id'               => (int) $e['id'],
                'exercise_id'      => (int) $e['exercise_id'],
                'slug'             => (string) $e['slug'],
                'name'             => (string) $e['name'],
                'prescribed_exercise_id' => self::intOrNull($e['prescribed_exercise_id']),
                'sets_completed'   => self::intOrNull($e['sets_completed']),
                'actual_reps'      => $e['actual_reps'],
                'actual_weight_kg' => $e['actual_weight_kg'] === null ? null : (float) $e['actual_weight_kg'],
                'actual_seconds'   => self::intOrNull($e['actual_seconds']),
                'actual_distance_m' => self::intOrNull($e['actual_distance_m']),
                'rpe'              => self::intOrNull($e['rpe']),
                'skipped'          => (bool) $e['skipped'],
                'notes'            => $e['notes'],
            ];
        }

        $out = [];
        foreach ($sessions as $s) {
            $id = (int) $s['id'];
            $out[] = [
                'logged_session_id' => $id,
                'prescribed_session_id' => self::intOrNull($s['prescribed_session_id']),
                'session_type'   => $s['session_type'],
                'status'         => (string) $s['status'],
                'actual_minutes' => self::intOrNull($s['actual_minutes']),
                'session_rpe'    => self::intOrNull($s['session_rpe']),
                'notes'          => $s['notes'],
                'trained_with_buddy' => (bool) $s['trained_with_buddy'],
                'logged_at'      => (string) $s['logged_at'],
                'exercises'      => $bySession[$id] ?? [],
            ];
        }
        return $out;
    }

    private static function intOrNull($v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
