<?php
declare(strict_types=1);

/**
 * Daily observation and the escalation rule (SPEC-coaching §4.2).
 *
 * This is the main cost-control mechanism in the whole app, and the shape of it is
 * a quote from scoping:
 *
 *   > If things are close to prescribed, no adaptation until the weekly check-in.
 *   > If days are missing or severely off, Claude asks questions.
 *
 * So classification is PURE SQL over columns logged_days already caches. An
 * on-track day costs nothing and produces nothing — no model call, no notification,
 * no record. That is not just about money: a coach who comments on every good day is
 * noise, and the user was promised quiet.
 *
 *   on_track     logged, roughly on target        → nothing at all
 *   minor        one missed session, macros loose  → noted, aggregated for the week
 *   significant  missed session AND heavy overeat,
 *                or 2+ days unlogged              → Claude asks questions (§7.1)
 *   absent       no data for nudge_after_days     → nudge, escalating
 *
 * Nothing here calls Claude. It decides WHETHER a call is warranted, and cron owns
 * making it. Keeping the decision separate from the action is what lets the whole
 * ladder be tested without spending anything.
 */
final class Drift
{
    public const ON_TRACK    = 'on_track';
    public const MINOR       = 'minor';
    public const SIGNIFICANT = 'significant';
    public const ABSENT      = 'absent';

    /**
     * "Heavy" overeat, as a multiple of the calorie target.
     *
     * 1.25 rather than something tighter because this is the threshold for
     * INTERRUPTING someone. A day at 110% of target is a normal day and the weekly
     * check-in is the right place for it; a day at 130% alongside a missed session
     * is a question worth asking.
     */
    private const HEAVY_OVEREAT = 1.25;

    /** Unlogged days that on their own constitute significant drift (§4.2). */
    private const UNLOGGED_IS_SIGNIFICANT = 2;

    /**
     * History needed before unlogged days can mean "drift" at all.
     *
     * Four days rather than a full week: enough that gaps are a pattern rather than a
     * slow start, without making a user wait a fortnight to be noticed. Below this the
     * absence ladder is the right response — someone who has not begun needs a nudge
     * to start, not a question about a week they did not have.
     */
    private const MIN_WINDOW_FOR_DRIFT = 4;

    /**
     * Classify a user's recent days.
     *
     * Looks at a window ending YESTERDAY, never including today: today is still in
     * progress, and judging a day at 2pm for having eaten one meal is exactly the
     * scolding the app is supposed to avoid. That mistake was already made once in
     * the UI (the verdict showing mid-day) and it is not repeated here.
     *
     * @param array $user a row with id, and ideally onboarding_state
     * @return array{
     *     state:string, reason:string, unlogged:int, days:list<array>,
     *     missed_sessions:int, heavy_overeat_days:int, last_logged:?string,
     *     quiet_days:int
     * }
     */
    public static function assess(array $user, ?string $tz = null, int $window = 7): array
    {
        $userId = (int) $user['id'];
        $today  = Schedule::today($tz);
        // Yesterday backwards. A day is only judged once it is over.
        $end    = date('Y-m-d', strtotime($today . ' -1 day'));
        $start  = date('Y-m-d', strtotime($end . ' -' . ($window - 1) . ' days'));

        /*
         * Never count days from before the user could have logged.
         *
         * A user two days into their baseline was reported as "7 of the last 7 days
         * unlogged" and classified as SIGNIFICANT drift, so the coach opened a
         * conversation about a week that had not happened to them yet. Five of those
         * days predated their account.
         *
         * The window therefore starts at the later of "a week ago" and "when they
         * began", and `window` shrinks with it so the counts stay honest. A brand-new
         * user has nothing to drift from; what they need is a nudge to start, which is
         * the absence branch.
         */
        $began = $user['baseline_starts_on']
            ?? (isset($user['created_at']) ? substr((string) $user['created_at'], 0, 10) : null)
            ?? self::createdOn($userId);
        if ((string) $began > $start) {
            $start  = (string) $began;
            $window = max(1, Schedule::daysBetween($start, $end) + 1);
        }

        $rows = DB::all(
            'SELECT log_date, macro_on_target, macro_short_but_ok, failure_count,
                    sessions_prescribed, sessions_completed
             FROM logged_days
             WHERE user_id = ? AND log_date BETWEEN ? AND ?
             ORDER BY log_date DESC',
            [$userId, $start, $end]
        );

        // A logged_days row can exist with nothing in it: Nutrition::dayId() creates
        // one the moment anything touches the day, including a check-in with no
        // food. "Logged" means there is actually something to assess.
        $logged = [];
        foreach ($rows as $r) {
            $logged[(string) $r['log_date']] = $r;
        }

        $withContent = self::datesWithContent($userId, $start, $end);

        $unlogged = 0;
        $missed   = 0;
        for ($i = 0; $i < $window; $i++) {
            $d = date('Y-m-d', strtotime($end . ' -' . $i . ' days'));
            if (!isset($withContent[$d])) {
                $unlogged++;
                continue;
            }
            $row = $logged[$d] ?? null;
            if ($row === null) {
                continue;
            }
            $prescribed = (int) ($row['sessions_prescribed'] ?? 0);
            $completed  = (int) ($row['sessions_completed'] ?? 0);
            if ($prescribed > $completed) {
                $missed += $prescribed - $completed;
            }
        }

        $heavy = self::heavyOvereatDays($userId, $start, $end);

        /*
         * How long they have been quiet.
         *
         * Falls back to $began (when they could first have logged) rather than to
         * today. An earlier version used `$user['baseline_starts_on'] ?? $today`,
         * which is NULL for an ACTIVE user — so a user who had never logged anything
         * read as zero quiet days, i.e. perfectly up to date. They then fell through
         * to the unlogged-days branch and were classified as significant drift, and
         * the coach opened a conversation about a week there was no data for instead
         * of nudging them to start.
         */
        $lastLogged = self::lastLoggedDate($userId);
        $quiet      = Schedule::daysBetween((string) ($lastLogged ?? $began), $today);

        // ---- the ladder, in order of severity ------------------------------

        $nudgeAfter = (int) (DB::one(
            'SELECT nudge_after_days FROM profiles WHERE user_id = ?', [$userId]
        )['nudge_after_days'] ?? 3);

        if ($quiet >= max(1, $nudgeAfter)) {
            return self::result(self::ABSENT,
                "no data for {$quiet} day" . ($quiet === 1 ? '' : 's'),
                compact('unlogged', 'missed', 'heavy', 'quiet') + ['last' => $lastLogged, 'rows' => $rows]);
        }

        /*
         * "2+ days unlogged" (§4.2) means two days out of a real week, not two days
         * out of two.
         *
         * A user whose whole history is two days had both of them counted as
         * significant drift, which read as "2 of the last 2 days unlogged" and would
         * have opened a coaching conversation with someone who has not started yet.
         * Below a week of history the absence branch above is the right response, and
         * it has already had its chance by this point.
         */
        if ($window >= self::MIN_WINDOW_FOR_DRIFT && $unlogged >= self::UNLOGGED_IS_SIGNIFICANT) {
            return self::result(self::SIGNIFICANT,
                "{$unlogged} of the last {$window} days unlogged",
                compact('unlogged', 'missed', 'heavy', 'quiet') + ['last' => $lastLogged, 'rows' => $rows]);
        }

        if ($missed > 0 && $heavy > 0) {
            // The pair is what makes it significant. Either alone is a bad day; both
            // together is a pattern worth a question.
            return self::result(self::SIGNIFICANT,
                "{$missed} missed session" . ($missed === 1 ? '' : 's')
                . " and {$heavy} heavy overeat day" . ($heavy === 1 ? '' : 's'),
                compact('unlogged', 'missed', 'heavy', 'quiet') + ['last' => $lastLogged, 'rows' => $rows]);
        }

        if ($missed > 0 || $heavy > 0 || $unlogged > 0) {
            return self::result(self::MINOR,
                $missed > 0
                    ? "{$missed} missed session" . ($missed === 1 ? '' : 's')
                    : ($heavy > 0
                        ? 'macros ran loose'
                        : "{$unlogged} day" . ($unlogged === 1 ? '' : 's') . ' unlogged'),
                compact('unlogged', 'missed', 'heavy', 'quiet') + ['last' => $lastLogged, 'rows' => $rows]);
        }

        return self::result(self::ON_TRACK, 'logged and roughly on target',
            compact('unlogged', 'missed', 'heavy', 'quiet') + ['last' => $lastLogged, 'rows' => $rows]);
    }

    /** @param array<string,mixed> $m */
    private static function result(string $state, string $reason, array $m): array
    {
        return [
            'state'              => $state,
            'reason'             => $reason,
            'unlogged'           => (int) $m['unlogged'],
            'missed_sessions'    => (int) $m['missed'],
            'heavy_overeat_days' => (int) $m['heavy'],
            'quiet_days'         => (int) $m['quiet'],
            'last_logged'        => $m['last'],
            'days'               => $m['rows'],
        ];
    }

    /**
     * Days that actually have food or training on them.
     *
     * Not the same as "has a logged_days row": Nutrition::dayId() creates one when
     * anything touches the day, so a user who only tapped an energy rating has a row
     * with no content. Counting that as logged would hide real absence, which is the
     * one thing the nudge ladder exists to catch.
     *
     * @return array<string,true>
     */
    private static function datesWithContent(int $userId, string $start, string $end): array
    {
        $out = [];
        foreach (DB::all(
            'SELECT DISTINCT ld.log_date
             FROM logged_days ld
             WHERE ld.user_id = ? AND ld.log_date BETWEEN ? AND ?
               AND (
                 EXISTS (
                   SELECT 1 FROM logged_meals lm
                   JOIN logged_entries le ON le.logged_meal_id = lm.id
                   WHERE lm.logged_day_id = ld.id
                 )
                 OR EXISTS (
                   SELECT 1 FROM logged_sessions ls WHERE ls.logged_day_id = ld.id
                 )
               )',
            [$userId, $start, $end]
        ) as $r) {
            $out[(string) $r['log_date']] = true;
        }
        return $out;
    }

    /**
     * Days where intake ran well past the target.
     *
     * Computed here rather than cached because it needs the ratio, and logged_days
     * caches the verdict rather than the numbers behind it. One query for a week is
     * cheap; a column that has to be kept in sync is not.
     */
    private static function heavyOvereatDays(int $userId, string $start, string $end): int
    {
        return (int) (DB::one(
            'SELECT COUNT(*) AS n FROM (
                SELECT ld.log_date,
                       SUM(COALESCE(le.calories, 0)) + COALESCE(MAX(lm.delta_calories), 0) AS eaten,
                       MAX(pd.target_calories) AS target
                FROM logged_days ld
                JOIN logged_meals lm   ON lm.logged_day_id = ld.id
                LEFT JOIN logged_entries le ON le.logged_meal_id = lm.id
                JOIN prescribed_days pd ON pd.day_date = ld.log_date
                JOIN plan_versions pv   ON pv.id = pd.plan_version_id
                                        AND pv.user_id = ld.user_id
                                        AND pv.superseded_at IS NULL
                WHERE ld.user_id = ? AND ld.log_date BETWEEN ? AND ?
                GROUP BY ld.log_date
                HAVING target > 0 AND eaten > target * ?
             ) AS heavy',
            [$userId, $start, $end, self::HEAVY_OVEREAT]
        )['n'] ?? 0);
    }

    /**
     * The account's creation date, as the last resort for "since when".
     *
     * Only reached for a user with no logged content and no baseline window, which is
     * a brand-new active user. The row is already loaded in most callers, so this is
     * a fallback for the ones that pass a thin array.
     */
    private static function createdOn(int $userId): string
    {
        $row = DB::one('SELECT DATE(created_at) AS d FROM users WHERE id = ?', [$userId]);
        return (string) ($row['d'] ?? date('Y-m-d'));
    }

    /** The most recent day with real content on it, or null. */
    public static function lastLoggedDate(int $userId): ?string
    {
        $row = DB::one(
            'SELECT MAX(ld.log_date) AS d
             FROM logged_days ld
             WHERE ld.user_id = ?
               AND (
                 EXISTS (
                   SELECT 1 FROM logged_meals lm
                   JOIN logged_entries le ON le.logged_meal_id = lm.id
                   WHERE lm.logged_day_id = ld.id
                 )
                 OR EXISTS (
                   SELECT 1 FROM logged_sessions ls WHERE ls.logged_day_id = ld.id
                 )
               )',
            [$userId]
        );
        return ($row['d'] ?? null) === null ? null : (string) $row['d'];
    }

    /**
     * Does this state warrant interrupting the user?
     *
     * on_track and minor never do. Minor is REAL and worth noting, but §4.2 is
     * explicit that it aggregates for the weekly check-in rather than generating
     * anything now — one missed session is not a conversation.
     */
    public static function warrantsClaude(string $state): bool
    {
        return $state === self::SIGNIFICANT;
    }

    public static function warrantsNudge(string $state): bool
    {
        return $state === self::ABSENT;
    }

    /**
     * Has the coach already opened a conversation about this rough patch?
     *
     * Keyed on time rather than on an episode id, because a "patch" has no clean
     * boundary: drift fades in and out. Seven days is roughly one check-in cycle, so
     * at worst the user is asked once, gets to the weekly check-in, and the question
     * becomes that conversation instead.
     *
     * Without this the sweep re-asks every 15 minutes forever.
     */
    public static function alreadyAsked(int $userId, int $withinDays = 7): bool
    {
        return DB::one(
            'SELECT 1 AS x FROM chat_turns
             WHERE user_id = ? AND role = "assistant" AND drift_state = "significant"
               AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
             LIMIT 1',
            [$userId, max(1, $withinDays)]
        ) !== null;
    }

    /**
     * Ask about it (§7.1). Claude asks FIRST, then acts.
     *
     * That ordering is the point and it is stated twice in the spec: the coach does
     * not silently reshuffle a week around a guess. Illness lightens a week, being
     * busy reshuffles it, a heavy overeat adjusts the remaining days — and which of
     * those it is cannot be inferred from the logs. Only asking reveals it.
     *
     * "ADAPTATION IS NOT PUNISHMENT. A bad day adjusts the plan; it does not add
     * penance." That framing is wrong for anyone and actively harmful for User #2,
     * so it is in the prompt rather than left to the model's instincts.
     *
     * Writes an assistant turn with outcome='question'. The user's reply comes back
     * through the chat path (§6), which is not built yet — so today this is a
     * question the user reads and can answer once chat lands. Asking is still worth
     * more than silently re-planning.
     */
    public static function ask(int $userId, array $assessment): ?int
    {
        $profile = DB::one(
            'SELECT tone, explanation_depth FROM profiles WHERE user_id = ?', [$userId]
        ) ?? [];

        $result = Claude::json(self::questionSchema(), [
            'purpose'    => 'drift_eval',
            'user_id'    => $userId,
            'max_tokens' => 600,
            'system'     => implode("\n", [
                'You are this user\'s coach. Their recent logging shows a rough patch '
                . 'and you are opening a short conversation about it.',
                '',
                'VOICE: ' . Tone::brief((string) ($profile['tone'] ?? 'friendly_encouraging')),
                '',
                'You ASK FIRST and act afterwards. Do not propose a new plan, do not '
                . 'reshuffle anything, do not assume why. Illness lightens a week; '
                . 'being busy reshuffles it; a heavy week adjusts the days that are '
                . 'left. Which one it is cannot be read off the logs.',
                '',
                'Absolute rules:',
                '- ADAPTATION IS NOT PUNISHMENT. Never suggest making up missed work, '
                . 'never propose a corrective deficit, never frame anything as owed.',
                '- Do not shame a logged bad day. They logged it, which is the hard '
                . 'part.',
                '- One question, not an interrogation. Two or three sentences total.',
                '- No greeting, no signature.',
                // The house voice. Generated copy is the one place the browser suite
                // cannot police, since it only ever sees seeded fixtures.
                '- NO EM DASHES and no en dashes. Use a comma or a full stop.',
            ]),
            'messages'   => [[
                'role'    => 'user',
                'content' => self::questionContext($assessment),
            ]],
        ]);

        if (!($result['ok'] ?? false)) {
            return null;
        }
        $body = Tone::clean((string) ($result['data']['question'] ?? ''));
        if ($body === '') {
            return null;
        }

        $turnId = DB::insert(
            'INSERT INTO chat_turns (user_id, role, body, outcome, drift_state)
             VALUES (?, "assistant", ?, "question", "significant")',
            [$userId, $body]
        );

        // Surfaced as a notification so it is visible without opening chat, which
        // does not exist yet.
        Notify::create($userId, 'drift_question', $body, 'chat_turn', $turnId, 20);

        return $turnId;
    }

    /** What the coach can see. Facts only; the interpretation is the model's. */
    private static function questionContext(array $a): string
    {
        $out = ['Here is what their logging shows over the last week.', ''];
        $out[] = "Assessment: {$a['reason']}.";
        if ($a['unlogged'] > 0) {
            $out[] = "Days with nothing logged: {$a['unlogged']}.";
        }
        if ($a['missed_sessions'] > 0) {
            $out[] = "Committed sessions missed: {$a['missed_sessions']}.";
        }
        if ($a['heavy_overeat_days'] > 0) {
            $out[] = "Days well over the calorie target: {$a['heavy_overeat_days']}.";
        }

        if ($a['days'] !== []) {
            $out[] = '';
            $out[] = 'Day by day, newest first:';
            foreach ($a['days'] as $d) {
                $bits = [(string) $d['log_date']];
                if ($d['sessions_prescribed'] !== null) {
                    $bits[] = "training {$d['sessions_completed']}/{$d['sessions_prescribed']}";
                }
                if ($d['macro_on_target'] !== null) {
                    $bits[] = (int) $d['macro_on_target'] === 1
                        ? 'macros on target'
                        : "macros off by {$d['failure_count']}";
                }
                $out[] = '  ' . implode(' | ', $bits);
            }
        }

        $out[] = '';
        $out[] = 'Ask them one question about it.';
        return implode("\n", $out);
    }

    private static function questionSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['question'],
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'Two or three sentences in the user\'s coaching '
                        . 'voice, ending in one question. No greeting or signature.',
                ],
            ],
        ];
    }
}
