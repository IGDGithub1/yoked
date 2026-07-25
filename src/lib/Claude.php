<?php
declare(strict_types=1);

/**
 * Anthropic API client.
 *
 * Hand-rolled cURL against POST /v1/messages — there is no Composer in this
 * project, so the official PHP SDK is not an option. That is a constraint, not
 * a preference: it means the wire format is our problem, and the notes below
 * record the parts that are easy to get wrong.
 *
 * Responsibilities:
 *   - build and send requests, with structured-output support
 *   - prompt-cache the stable part of the prompt (§ Caching below)
 *   - rate limit per user
 *   - log every call to ai_calls for cost visibility
 *   - the constraint-violation retry loop from SPEC-safety.md §5
 *
 * SERVER-SIDE ONLY. The API key lives in config.php (gitignored, 0600, outside
 * the web root) and is never sent to a client.
 *
 * ---------------------------------------------------------------------------
 * Wire-format notes for claude-sonnet-5 (getting these wrong is a 400)
 * ---------------------------------------------------------------------------
 *   * Adaptive thinking only. `thinking: {"type": "adaptive"}`. The older
 *     `{"type": "enabled", "budget_tokens": N}` is REMOVED and returns 400.
 *     Depth is controlled by output_config.effort instead.
 *   * No sampling parameters. temperature / top_p / top_k are rejected.
 *     Steer with the prompt. (Suits us — plan generation wants consistency.)
 *   * Model ids carry no date suffix. 'claude-sonnet-5', never
 *     'claude-sonnet-5-20260101'. The Keto Tracker reference pinned
 *     'claude-sonnet-4-20250514', which is exactly the mistake to avoid.
 *   * effort lives inside output_config, not at the top level.
 *   * Assistant-turn prefill returns 400. Use structured outputs to force a
 *     response shape instead — see json() below.
 */
final class Claude
{
    /** Anthropic's dated API version header — unrelated to the model id. */
    private const API_VERSION = '2023-06-01';

    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** Generation is slow by design; a week's plan can take a while. */
    private const TIMEOUT_SECONDS = 300;

    /** Retries for transient failures (429 / 5xx). Distinct from constraint retries. */
    private const MAX_TRANSPORT_RETRIES = 3;

    /** Purposes accepted by ai_calls.purpose — must match the ENUM. */
    public const PURPOSES = [
        'plan_generation', 'provisional_plan', 'baseline_analysis',
        'drift_eval', 'veto_replacement', 'interjection',
        'weekly_review', 'food_search', 'other',
    ];

    /**
     * Send a request and return the parsed response.
     *
     * @param array $opts {
     *   purpose:     string   one of PURPOSES — required, for ai_calls
     *   user_id:     ?int     for rate limiting and attribution
     *   system:      string|array<array>  string, or blocks for cache control
     *   messages:    array    the conversation
     *   max_tokens:  int      default 16000
     *   effort:      string   low|medium|high|xhigh|max — defaults to config
     *   schema:      ?array   JSON Schema; enables structured output
     *   thinking:    bool     default true (adaptive)
     *   cache_system: bool    default true — see § Caching
     * }
     *
     * @return array{
     *   ok: bool, text: ?string, data: ?array, stop_reason: ?string,
     *   usage: array, model: ?string, error: ?string, duration_ms: int
     * }
     */
    public static function send(array $opts): array
    {
        $started = microtime(true);
        $purpose = $opts['purpose'] ?? 'other';
        if (!in_array($purpose, self::PURPOSES, true)) {
            $purpose = 'other';
        }
        $userId = isset($opts['user_id']) ? (int) $opts['user_id'] : null;

        $apiKey = (string) yk_config('anthropic.api_key', '');
        if ($apiKey === '') {
            return self::fail($purpose, $userId, 'Anthropic API key is not configured.', $started);
        }

        $model = (string) yk_config('anthropic.model', '');
        if ($model === '') {
            return self::fail($purpose, $userId, 'Anthropic model is not configured.', $started);
        }

        // Rate limit per user per rolling hour. Uses allow() rather than
        // check() so a capped user does not kill a cron sweep.
        if ($userId !== null) {
            $max = (int) yk_config('anthropic.max_calls_per_hour', 30);
            if ($max > 0 && !RateLimit::allow("ai:{$userId}", $max, 3600)) {
                return self::fail(
                    $purpose, $userId,
                    "Rate limit reached ({$max} AI calls/hour).", $started
                );
            }
        }

        $body = self::buildBody($model, $opts);

        $attempt = 0;
        $lastError = null;

        while ($attempt < self::MAX_TRANSPORT_RETRIES) {
            $attempt++;
            [$status, $raw, $curlError] = self::post($apiKey, $body);

            if ($curlError !== null) {
                $lastError = "transport: {$curlError}";
                // Network-level failure — worth retrying.
                if ($attempt < self::MAX_TRANSPORT_RETRIES) {
                    sleep(self::backoff($attempt));
                    continue;
                }
                break;
            }

            // 429 and 5xx are transient. Everything else is ours to fix, so
            // fail fast rather than hammering the API with a bad request.
            if ($status === 429 || $status >= 500) {
                $lastError = "HTTP {$status}: " . self::errorMessage($raw);
                if ($attempt < self::MAX_TRANSPORT_RETRIES) {
                    sleep(self::backoff($attempt));
                    continue;
                }
                break;
            }

            if ($status !== 200) {
                $lastError = "HTTP {$status}: " . self::errorMessage($raw);
                break;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $lastError = 'Response was not valid JSON.';
                break;
            }

            return self::interpret($decoded, $purpose, $userId, $started, $attempt - 1);
        }

        return self::fail($purpose, $userId, $lastError ?? 'Unknown failure.', $started, $attempt - 1);
    }

    /**
     * Send a request expecting structured JSON back, validated against $schema.
     *
     * Structured output is how we replace assistant-turn prefill (removed on
     * this model family): instead of seeding `{"` and hoping, the API is
     * constrained to emit conforming JSON.
     *
     * @return array{ok: bool, data: ?array, error: ?string, ...}
     */
    public static function json(array $schema, array $opts): array
    {
        $opts['schema'] = $schema;
        $result = self::send($opts);

        if (!$result['ok']) {
            return $result;
        }
        if ($result['data'] === null) {
            $result['ok'] = false;
            $result['error'] = 'Model returned no parseable JSON.';
        }
        return $result;
    }

    /**
     * Generate, then validate, then regenerate on violation.
     *
     * Implements SPEC-safety.md §5: a plan that violates a hard constraint is
     * not surfaced to the user as an apology — it is regenerated with the
     * violation named in the retry prompt. Bounded attempts, then fail loudly.
     * Never silently ship a violating plan.
     *
     * @param callable(array):array $validator  data → list of violation strings
     * @param int $maxAttempts  total generation attempts (default 3 = 1 + 2 retries)
     */
    public static function generateValidated(
        array $schema,
        array $opts,
        callable $validator,
        int $maxAttempts = 3
    ): array {
        $attempts = [];
        $baseMessages = $opts['messages'] ?? [];

        for ($i = 1; $i <= $maxAttempts; $i++) {
            $result = self::json($schema, $opts);

            if (!$result['ok']) {
                $result['attempts'] = $i;
                $result['violations'] = [];
                return $result;
            }

            $violations = $validator($result['data']);
            if ($violations === []) {
                $result['attempts'] = $i;
                $result['violations'] = [];
                return $result;
            }

            $attempts[] = $violations;

            // Record the retry against the call we just logged, so a plan that
            // needed two attempts is findable later.
            self::recordViolations($result['call_id'] ?? null, $i - 1, $violations);

            if ($i === $maxAttempts) {
                return [
                    'ok'         => false,
                    'data'       => null,
                    'error'      => 'Could not generate a plan within constraints after '
                                    . $maxAttempts . ' attempts.',
                    'violations' => $violations,
                    'attempts'   => $i,
                    'history'    => $attempts,
                ];
            }

            // Name the violations explicitly in the retry. Vague feedback
            // ("that was wrong") produces another violating plan.
            //
            // The wording here matters more than it looks. This used to say
            // "Regenerate the whole plan. Keep everything that was fine; fix
            // only what is listed above," which is a contradiction: the model
            // can see a complete plan it just wrote, is told most of it is
            // correct, and is asked to re-emit ~30k tokens to change one meal.
            // It reasonably answered with a short partial instead — 4k tokens in
            // 29s against 30k in 580s — which then failed the same check, so the
            // violation survived every attempt and generation failed outright.
            //
            // So: no ambiguity about scope. The full document is required, the
            // reason is stated (it is parsed as a whole and replaces the last
            // one), and the length is acknowledged rather than glossed over.
            $opts['messages'] = array_merge($baseMessages, [
                ['role' => 'assistant', 'content' => json_encode($result['data'])],
                ['role' => 'user', 'content' =>
                    "That plan violates hard constraints that cannot be overridden:\n"
                    . '- ' . implode("\n- ", $violations) . "\n\n"
                    . 'Return the COMPLETE corrected plan — every day, every '
                    . 'session, every meal, in full. It is parsed as one document '
                    . 'and replaces the previous one, so a partial answer or a '
                    . 'diff cannot be used and will fail. Reproduce everything '
                    . 'that was already correct verbatim and change only what is '
                    . 'listed above. Yes, this means emitting the whole plan '
                    . 'again; that is expected. Do not explain the fix.',
                ],
            ]);
        }

        // Unreachable — the loop returns on every path.
        return ['ok' => false, 'data' => null, 'error' => 'Generation loop exited unexpectedly.'];
    }

    // ---- request construction ----------------------------------------------

    private static function buildBody(string $model, array $opts): array
    {
        $body = [
            'model'      => $model,
            'max_tokens' => (int) ($opts['max_tokens'] ?? 16000),
            'messages'   => $opts['messages'] ?? [],
        ];

        // § Caching
        //
        // Caching is a PREFIX match: render order is tools -> system ->
        // messages, and any byte change invalidates everything after it. So the
        // stable profile (who the user is, their constraints, their goal) goes
        // in `system` with a cache breakpoint, and the volatile part (this
        // week's logs, the actual request) goes in `messages` after it.
        //
        // Keep anything per-request out of `system` — a timestamp there would
        // silently make every call a cache miss. Minimum cacheable prefix on
        // Sonnet 5 is 1024 tokens; below that it silently will not cache, which
        // is fine (no error, just no saving).
        if (isset($opts['system'])) {
            $cache = $opts['cache_system'] ?? true;
            if (is_string($opts['system'])) {
                $block = ['type' => 'text', 'text' => $opts['system']];
                if ($cache) {
                    $block['cache_control'] = ['type' => 'ephemeral'];
                }
                $body['system'] = [$block];
            } else {
                // Caller supplied blocks and owns its own cache_control.
                $body['system'] = $opts['system'];
            }
        }

        // Adaptive thinking. NOT budget_tokens — that is removed on this model
        // and returns 400. `display` stays at its default ("omitted"): nothing
        // in Yoked surfaces reasoning to a user, so paying to summarise it
        // would be waste.
        if ($opts['thinking'] ?? true) {
            $body['thinking'] = ['type' => 'adaptive'];
        } else {
            $body['thinking'] = ['type' => 'disabled'];
        }

        // effort is nested inside output_config, not top-level.
        $outputConfig = [];
        $effort = $opts['effort'] ?? yk_config('anthropic.effort', 'high');
        if (is_string($effort) && $effort !== '') {
            $outputConfig['effort'] = $effort;
        }

        // Structured output. Replaces prefill for forcing a response shape.
        if (isset($opts['schema']) && is_array($opts['schema'])) {
            $outputConfig['format'] = [
                'type'   => 'json_schema',
                'schema' => $opts['schema'],
            ];
        }
        if ($outputConfig !== []) {
            $body['output_config'] = $outputConfig;
        }

        // Deliberately absent: temperature, top_p, top_k. All rejected with a
        // 400 on this model family.

        return $body;
    }

    /** @return array{0:int,1:string,2:?string} [status, raw body, curl error] */
    private static function post(string $apiKey, array $body): array
    {
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        // curl_close() is a deprecated no-op since PHP 8.0 — the handle is an
        // object now and frees itself when it goes out of scope.
        unset($ch);

        if ($raw === false) {
            return [$status, '', $err !== '' ? $err : 'cURL failed with no message'];
        }
        return [$status, (string) $raw, null];
    }

    /** Exponential backoff with a small jitter, in seconds. */
    private static function backoff(int $attempt): int
    {
        return min(30, (2 ** $attempt) + random_int(0, 2));
    }

    // ---- response handling -------------------------------------------------

    /**
     * Turn a 200 response into our result shape, and log it.
     *
     * Note the stop_reason check comes BEFORE reading content: a refusal can
     * arrive as a successful 200 with an empty content array, so indexing
     * content[0] first would break on it.
     */
    private static function interpret(
        array $r,
        string $purpose,
        ?int $userId,
        float $started,
        int $retryCount
    ): array {
        $usage = $r['usage'] ?? [];
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $stopReason = $r['stop_reason'] ?? null;

        // Concatenate text blocks. Thinking blocks are present but empty
        // (display defaults to "omitted") — skip anything that isn't text.
        $text = '';
        foreach ($r['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        $ok    = true;
        $error = null;

        if ($stopReason === 'refusal') {
            // Safety classifiers declined. Not retryable by resending.
            $ok    = false;
            $error = 'Model declined the request'
                   . (isset($r['stop_details']['category'])
                      ? " ({$r['stop_details']['category']})" : '') . '.';
        } elseif ($stopReason === 'max_tokens') {
            // Truncated mid-response. The output is unusable rather than
            // partially useful — a half-generated plan is not a plan.
            $ok    = false;
            $error = 'Response hit max_tokens and was truncated.';
        } elseif (trim($text) === '') {
            $ok    = false;
            $error = 'Model returned no text content.';
        }

        $data = $ok ? self::extractJson($text) : null;

        $callId = self::log([
            'user_id'       => $userId,
            'purpose'       => $purpose,
            'model'         => (string) ($r['model'] ?? yk_config('anthropic.model', '')),
            'input_tokens'  => $usage['input_tokens'] ?? null,
            'output_tokens' => $usage['output_tokens'] ?? null,
            'cached_tokens' => $usage['cache_read_input_tokens'] ?? null,
            'retry_count'   => $retryCount,
            'ok'            => $ok,
            'error'         => $error,
            'duration_ms'   => $durationMs,
        ]);

        return [
            'ok'          => $ok,
            'text'        => $text === '' ? null : $text,
            'data'        => $data,
            'stop_reason' => $stopReason,
            'usage'       => $usage,
            'model'       => $r['model'] ?? null,
            'error'       => $error,
            'duration_ms' => $durationMs,
            'call_id'     => $callId,
        ];
    }

    /**
     * Parse JSON out of a response.
     *
     * Structured output should give us clean JSON, but the fence-stripping
     * fallback stays: the Keto Tracker reference stripped ```json fences
     * client-side, and a model can still wrap output when the schema is absent.
     * Cheap insurance.
     */
    private static function extractJson(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Strip a ```json ... ``` fence if present.
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $m)) {
            $decoded = json_decode(trim($m[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Last resort: the outermost {...} or [...] span.
        $start = strcspn($text, '{[');
        if ($start < strlen($text)) {
            $open  = $text[$start];
            $close = $open === '{' ? '}' : ']';
            $end   = strrpos($text, $close);
            if ($end !== false && $end > $start) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    /** Pull the API's error message out of a non-200 body. */
    private static function errorMessage(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            return (string) $decoded['error']['message'];
        }
        return substr($raw, 0, 300);
    }

    // ---- logging -----------------------------------------------------------

    /**
     * Write one ai_calls row. Returns its id, or null.
     *
     * Logging must never be the reason a request fails, so this swallows its
     * own errors to the PHP log rather than throwing.
     */
    private static function log(array $row): ?int
    {
        try {
            // This is the first query after a call that may have run for
            // minutes, and wait_timeout on shared hosting is 60 seconds — so
            // the connection is probably dead. run() would recover on its own,
            // but doing it explicitly keeps the "connection lost" noise out of
            // the error log on every single AI call.
            DB::ensureConnected();

            return DB::insert(
                'INSERT INTO ai_calls
                 (user_id, purpose, model, input_tokens, output_tokens, cached_tokens,
                  retry_count, ok, error, duration_ms)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $row['user_id'],
                    $row['purpose'],
                    $row['model'],
                    $row['input_tokens'],
                    $row['output_tokens'],
                    $row['cached_tokens'],
                    $row['retry_count'],
                    $row['ok'] ? 1 : 0,
                    $row['error'] !== null ? substr($row['error'], 0, 500) : null,
                    $row['duration_ms'],
                ]
            );
        } catch (Throwable $e) {
            error_log('[yoked] ai_calls log failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Attach constraint violations to a logged call. */
    private static function recordViolations(?int $callId, int $retryCount, array $violations): void
    {
        if ($callId === null) {
            return;
        }
        try {
            DB::run(
                'UPDATE ai_calls SET violations = ?, retry_count = ? WHERE id = ?',
                [json_encode($violations), $retryCount, $callId]
            );
        } catch (Throwable $e) {
            error_log('[yoked] ai_calls violation update failed: ' . $e->getMessage());
        }
    }

    /** Uniform failure shape, logged like any other call. */
    private static function fail(
        string $purpose,
        ?int $userId,
        string $error,
        float $started,
        int $retryCount = 0
    ): array {
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $callId = self::log([
            'user_id'       => $userId,
            'purpose'       => $purpose,
            'model'         => (string) yk_config('anthropic.model', 'unconfigured'),
            'input_tokens'  => null,
            'output_tokens' => null,
            'cached_tokens' => null,
            'retry_count'   => $retryCount,
            'ok'            => false,
            'error'         => $error,
            'duration_ms'   => $durationMs,
        ]);

        return [
            'ok' => false, 'text' => null, 'data' => null, 'stop_reason' => null,
            'usage' => [], 'model' => null, 'error' => $error,
            'duration_ms' => $durationMs, 'call_id' => $callId,
        ];
    }

    // ---- cost reporting ----------------------------------------------------

    /**
     * Per-Mtok pricing, for turning token counts into dollars.
     *
     * Hardcoding prices is a liability — they change, and a stale table lies
     * quietly. Kept because the alternative (no cost visibility at all) is
     * worse at four users, and `estimateCost` returns null for an unknown
     * model rather than guessing.
     *
     * Sonnet 5 carries an introductory rate through 2026-08-31; the higher
     * standard rate is used here so estimates never under-report.
     */
    private const PRICING = [
        'claude-sonnet-5'  => ['in' => 3.00, 'out' => 15.00],
        'claude-opus-5'    => ['in' => 5.00, 'out' => 25.00],
        'claude-haiku-4-5' => ['in' => 1.00, 'out' =>  5.00],
    ];

    /** Dollar cost for one call's usage, or null if the model is unpriced. */
    public static function estimateCost(string $model, array $usage): ?float
    {
        $p = self::PRICING[$model] ?? null;
        if ($p === null) {
            return null;
        }
        $in     = (int) ($usage['input_tokens'] ?? 0);
        $out    = (int) ($usage['output_tokens'] ?? 0);
        $cached = (int) ($usage['cache_read_input_tokens'] ?? 0);

        // Cache reads bill at ~0.1x input. Cache writes bill at ~1.25x, and are
        // reported separately as cache_creation_input_tokens.
        $writes = (int) ($usage['cache_creation_input_tokens'] ?? 0);

        return ($in     * $p['in']  / 1_000_000)
             + ($cached * $p['in']  * 0.10 / 1_000_000)
             + ($writes * $p['in']  * 1.25 / 1_000_000)
             + ($out    * $p['out'] / 1_000_000);
    }

    /** Spend and call counts over the last N days, for a cost dashboard. */
    public static function usageSummary(int $days = 30): array
    {
        $rows = DB::all(
            'SELECT purpose, model, COUNT(*) AS calls,
                    SUM(ok) AS ok_calls,
                    COALESCE(SUM(input_tokens), 0)  AS input_tokens,
                    COALESCE(SUM(output_tokens), 0) AS output_tokens,
                    COALESCE(SUM(cached_tokens), 0) AS cached_tokens
             FROM ai_calls
             WHERE created_at >= (NOW() - INTERVAL ? DAY)
             GROUP BY purpose, model
             ORDER BY calls DESC',
            [$days]
        );

        $total = 0.0;
        foreach ($rows as &$row) {
            $cost = self::estimateCost((string) $row['model'], [
                'input_tokens'           => $row['input_tokens'],
                'output_tokens'          => $row['output_tokens'],
                'cache_read_input_tokens' => $row['cached_tokens'],
            ]);
            $row['est_cost'] = $cost;
            $total += $cost ?? 0.0;
        }
        unset($row);

        return ['days' => $days, 'by_purpose' => $rows, 'est_total' => round($total, 4)];
    }
}
