<?php
declare(strict_types=1);

/**
 * Live tests for Claude.php. These make real API calls and cost real money
 * (a few cents total).
 *
 * The point is the wire format: adaptive-thinking-only, no sampling params,
 * effort nested in output_config, structured output instead of prefill. Every
 * one of those is a 400 if wrong, and none of them can be verified without
 * actually calling the API.
 *
 *   php bin/test-claude.php
 *   php bin/test-claude.php --offline   # skip API calls; shape checks only
 */

require __DIR__ . '/../src/bootstrap_cli.php';
require YK_SRC . '/lib/Response.php';
require YK_SRC . '/lib/RateLimit.php';
require YK_SRC . '/lib/Claude.php';

$offline = in_array('--offline', array_slice($argv, 1), true);

$pass = 0;
$fail = 0;

function t(string $label, callable $fn): void
{
    global $pass, $fail;
    try {
        $r = $fn();
        if ($r === true) {
            printf("  ok    %s\n", $label);
            $pass++;
        } elseif ($r === null) {
            printf("  skip  %s\n", $label);
        } else {
            printf("  FAIL  %s — %s\n", $label, is_string($r) ? $r : 'false');
            $fail++;
        }
    } catch (Throwable $e) {
        printf("  FAIL  %s — %s\n", $label, $e->getMessage());
        $fail++;
    }
}

echo "Claude client tests\n\n";
echo "1. configuration\n";

t('api key is set', function () {
    $k = (string) yk_config('anthropic.api_key', '');
    return $k !== '' ?: 'anthropic.api_key is empty in config.php';
});

t('model is set and carries no date suffix', function () {
    $m = (string) yk_config('anthropic.model', '');
    if ($m === '') {
        return 'anthropic.model is empty';
    }
    // 'claude-sonnet-5-20260101' style ids are 404s on this model family.
    if (preg_match('/-\d{8}$/', $m)) {
        return "model '{$m}' has a date suffix — these ids are fixed strings";
    }
    printf("        model: %s\n", $m);
    return true;
});

t('effort is a valid level', function () {
    $e = (string) yk_config('anthropic.effort', 'high');
    return in_array($e, ['low', 'medium', 'high', 'xhigh', 'max'], true)
        ?: "effort '{$e}' is not a valid level";
});

echo "\n2. JSON extraction (no API calls)\n";

// extractJson is private; exercise it through a reflection handle so the
// fence-stripping fallback is actually covered.
$extract = (new ReflectionMethod(Claude::class, 'extractJson'));
$extract->setAccessible(true);
$x = fn(string $s) => $extract->invoke(null, $s);

t('plain JSON object', fn() => ($x('{"a":1}')['a'] ?? null) === 1 ?: 'failed');
t('plain JSON array', fn() => is_array($x('[1,2,3]')) ?: 'failed');
t('fenced json block', fn() => ($x("```json\n{\"a\":2}\n```")['a'] ?? null) === 2 ?: 'failed');
t('unlabelled fence', fn() => ($x("```\n{\"a\":3}\n```")['a'] ?? null) === 3 ?: 'failed');
t('prose wrapping JSON', fn() => ($x('Here you go: {"a":4} hope that helps')['a'] ?? null) === 4 ?: 'failed');
t('empty string returns null', fn() => $x('') === null ?: 'expected null');
t('non-JSON prose returns null', fn() => $x('no json at all here') === null ?: 'expected null');

echo "\n3. request body shape (no API calls)\n";

$build = (new ReflectionMethod(Claude::class, 'buildBody'));
$build->setAccessible(true);
$b = fn(array $o) => $build->invoke(null, 'claude-sonnet-5', $o);

t('adaptive thinking, never budget_tokens', function () use ($b) {
    $body = $b(['messages' => [['role' => 'user', 'content' => 'hi']]]);
    if (($body['thinking']['type'] ?? null) !== 'adaptive') {
        return 'thinking.type should be adaptive';
    }
    // budget_tokens returns a 400 on this model family.
    return !isset($body['thinking']['budget_tokens']) ?: 'budget_tokens must not be sent';
});

t('no sampling parameters', function () use ($b) {
    $body = $b(['messages' => []]);
    foreach (['temperature', 'top_p', 'top_k'] as $k) {
        if (isset($body[$k])) {
            return "{$k} must not be sent — rejected with 400";
        }
    }
    return true;
});

t('effort nested inside output_config', function () use ($b) {
    $body = $b(['messages' => [], 'effort' => 'medium']);
    if (isset($body['effort'])) {
        return 'effort must not be top-level';
    }
    return ($body['output_config']['effort'] ?? null) === 'medium' ?: 'effort not in output_config';
});

t('system prompt gets a cache breakpoint', function () use ($b) {
    $body = $b(['messages' => [], 'system' => 'stable profile text']);
    return ($body['system'][0]['cache_control']['type'] ?? null) === 'ephemeral'
        ?: 'expected cache_control on the system block';
});

t('cache breakpoint can be declined', function () use ($b) {
    $body = $b(['messages' => [], 'system' => 'x', 'cache_system' => false]);
    return !isset($body['system'][0]['cache_control']) ?: 'cache_control should be absent';
});

t('schema becomes output_config.format', function () use ($b) {
    $body = $b(['messages' => [], 'schema' => ['type' => 'object']]);
    return ($body['output_config']['format']['type'] ?? null) === 'json_schema'
        ?: 'schema did not become a json_schema format';
});

t('no assistant prefill in the built body', function () use ($b) {
    // Prefill returns 400 on this family; we never construct one ourselves.
    $body = $b(['messages' => [['role' => 'user', 'content' => 'hi']]]);
    $last = end($body['messages']);
    return ($last['role'] ?? '') !== 'assistant' ?: 'built body ends with an assistant turn';
});

t('plan generation asks for enough output tokens', function () {
    // Measured runs land at 22k-31k output tokens for a full week, and a 32k
    // ceiling truncated two of six generations outright. A truncation is not a
    // degraded plan — the JSON is incomplete, so nothing parses and the user
    // gets nothing. Asserted here because the value is easy to "tidy" downward
    // and the failure only shows up as an occasional dead generation.
    if (!class_exists('Plans')) {
        require YK_SRC . '/lib/Goals.php';
        require YK_SRC . '/lib/PlanSchema.php';
        require YK_SRC . '/lib/BuddySchedule.php';   // Safety::checkAvailability reads it
        require YK_SRC . '/lib/Safety.php';
        require YK_SRC . '/lib/Drift.php';          // BuddyAbsence reads lastLoggedDate
        require YK_SRC . '/lib/BuddyAbsence.php';   // Plans::gatherContext reads it
        require YK_SRC . '/lib/BuddySkeleton.php';  // gatherContext and persist read it
        require YK_SRC . '/lib/Plans.php';
    }
    $n = Plans::MAX_OUTPUT_TOKENS;
    return $n >= 48000
        ?: "plan generation max_tokens is {$n}; needs >= 48000 for headroom "
           . 'over the 31k observed maximum';
});

echo "\n4. cost estimation (no API calls)\n";

t('prices a known model', function () {
    $c = Claude::estimateCost('claude-sonnet-5', [
        'input_tokens' => 1_000_000, 'output_tokens' => 0,
    ]);
    return abs($c - 3.00) < 0.001 ?: "expected 3.00, got {$c}";
});

t('cache reads are ~10% of input', function () {
    $c = Claude::estimateCost('claude-sonnet-5', [
        'input_tokens' => 0, 'output_tokens' => 0,
        'cache_read_input_tokens' => 1_000_000,
    ]);
    return abs($c - 0.30) < 0.001 ?: "expected 0.30, got {$c}";
});

t('unknown model returns null rather than guessing', function () {
    return Claude::estimateCost('some-future-model', ['input_tokens' => 1000]) === null
        ?: 'should return null';
});

t('usage summary query runs', function () {
    $s = Claude::usageSummary(30);
    return isset($s['by_purpose'], $s['est_total']) ?: 'unexpected shape';
});

if ($offline) {
    printf("\n%d passed, %d failed (offline — API calls skipped)\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}

echo "\n5. live API calls\n";

t('plain text round-trip', function () {
    $r = Claude::send([
        'purpose'    => 'other',
        'max_tokens' => 64,
        'thinking'   => false,   // keep it cheap
        'effort'     => 'low',
        'messages'   => [['role' => 'user', 'content' => 'Reply with exactly: PONG']],
    ]);
    if (!$r['ok']) {
        return 'API error: ' . $r['error'];
    }
    printf("        model=%s  in=%d out=%d  %dms\n",
        $r['model'],
        $r['usage']['input_tokens'] ?? 0,
        $r['usage']['output_tokens'] ?? 0,
        $r['duration_ms']);
    return str_contains(strtoupper((string) $r['text']), 'PONG')
        ?: 'unexpected reply: ' . $r['text'];
});

t('structured output conforms to schema', function () {
    $schema = [
        'type' => 'object',
        'properties' => [
            'name'     => ['type' => 'string'],
            'calories' => ['type' => 'integer'],
            'protein_g' => ['type' => 'number'],
        ],
        'required' => ['name', 'calories', 'protein_g'],
        'additionalProperties' => false,
    ];

    $r = Claude::json($schema, [
        'purpose'    => 'food_search',
        'max_tokens' => 512,
        'effort'     => 'low',
        'messages'   => [['role' => 'user', 'content' =>
            'Nutrition for 6oz grilled chicken breast. Return only the JSON object.']],
    ]);

    if (!$r['ok']) {
        return 'API error: ' . $r['error'];
    }
    $d = $r['data'];
    foreach (['name', 'calories', 'protein_g'] as $k) {
        if (!array_key_exists($k, $d)) {
            return "missing key '{$k}' in " . json_encode($d);
        }
    }
    printf("        %s: %d kcal, %.1fg protein\n", $d['name'], $d['calories'], $d['protein_g']);
    // A 6oz chicken breast is ~250-330 kcal; a wildly wrong number means the
    // model ignored the question rather than that our plumbing is broken.
    return ($d['calories'] > 100 && $d['calories'] < 600)
        ?: "implausible calories: {$d['calories']}";
});

t('prompt caching reports a cache write', function () {
    // The cacheable minimum is 1024 tokens on this model, so a short system
    // prompt silently will not cache. Pad past the floor to prove the
    // mechanism works at all.
    $system = str_repeat(
        'You are a nutrition assistant. Answer briefly and precisely. ', 220
    );

    $first = Claude::send([
        'purpose'    => 'other',
        'max_tokens' => 32,
        'thinking'   => false,
        'effort'     => 'low',
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => 'Say OK.']],
    ]);
    if (!$first['ok']) {
        return 'first call failed: ' . $first['error'];
    }

    $written = (int) ($first['usage']['cache_creation_input_tokens'] ?? 0);
    $read    = (int) ($first['usage']['cache_read_input_tokens'] ?? 0);
    printf("        first call: cache_write=%d cache_read=%d\n", $written, $read);

    // Either it wrote the cache, or a prior run's entry was still warm and it
    // read instead. Both prove the breakpoint is being honoured; neither being
    // set means the prefix was too short or cache_control never landed.
    return ($written > 0 || $read > 0)
        ?: 'no cache activity — prefix under the 1024-token floor, or cache_control missing';
});

t('cache is read on an identical prefix', function () {
    $system = str_repeat(
        'You are a nutrition assistant. Answer briefly and precisely. ', 220
    );

    // Same system prompt, different user turn: the prefix is byte-identical up
    // to the breakpoint, so this should read what the previous test wrote.
    $r = Claude::send([
        'purpose'    => 'other',
        'max_tokens' => 32,
        'thinking'   => false,
        'effort'     => 'low',
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => 'Say DONE.']],
    ]);
    if (!$r['ok']) {
        return 'call failed: ' . $r['error'];
    }
    $read = (int) ($r['usage']['cache_read_input_tokens'] ?? 0);
    printf("        second call: cache_read=%d\n", $read);
    return $read > 0
        ?: 'cache_read was 0 — something in the prefix differs between calls';
});

t('constraint retry loop regenerates on violation', function () {
    // Ask for three foods, then reject any answer containing peanuts. The model
    // is told to include peanut butter, so the first attempt should violate and
    // the retry should come back clean.
    $schema = [
        'type' => 'object',
        'properties' => [
            'foods' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['foods'],
        'additionalProperties' => false,
    ];

    $result = Claude::generateValidated(
        $schema,
        [
            'purpose'    => 'other',
            'max_tokens' => 256,
            'effort'     => 'low',
            'messages'   => [['role' => 'user', 'content' =>
                'List exactly 3 high-protein snack foods, one of which is peanut butter. '
                . 'Return only the JSON object.']],
        ],
        function (array $data): array {
            // Stands in for a hard food constraint.
            foreach ($data['foods'] ?? [] as $f) {
                if (stripos((string) $f, 'peanut') !== false) {
                    return ['contains peanuts, which is a hard allergy constraint'];
                }
            }
            return [];
        },
        3
    );

    printf("        attempts=%d ok=%s\n", $result['attempts'] ?? 0, ($result['ok'] ?? false) ? 'yes' : 'no');
    if (!($result['ok'] ?? false)) {
        // Failing loudly after exhausting retries is correct behaviour, not a
        // test failure — but it does mean we did not observe a clean retry.
        printf("        (exhausted retries — loud failure path exercised)\n");
        return ($result['attempts'] ?? 0) === 3
            ?: 'expected 3 attempts before giving up';
    }
    // Succeeded — it should have taken more than one attempt to get there.
    return ($result['attempts'] ?? 0) >= 2
        ?: 'expected at least one regeneration (the prompt asks for peanut butter)';
});

t('the merge hook keeps what a partial retry omits', function () {
    // The live plan suite only exercises the merge when a generation happens to
    // violate a constraint, which it often does not — both runs in one session
    // passed on the first attempt, leaving the merge path unobserved. This forces
    // it: ask for a list of dated entries, reject one of them, and give the model
    // an explicit nudge to return only the correction. Whether it obliges or
    // re-sends everything, the merged result must be complete either way.
    $schema = [
        'type' => 'object',
        'properties' => [
            'entries' => [
                'type'  => 'array',
                'items' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => ['date', 'colour'],
                    'properties' => [
                        'date'   => ['type' => 'string'],
                        'colour' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
        'required' => ['entries'],
        'additionalProperties' => false,
    ];

    // Same identity-keyed merge shape as Plans::mergePlans, kept local so this
    // tests the hook in Claude rather than the plan domain.
    $merge = function (array $prev, array $next): array {
        $byDate = [];
        foreach ([$prev['entries'] ?? [], $next['entries'] ?? []] as $rows) {
            foreach ($rows as $r) {
                if (($r['date'] ?? '') !== '') {
                    $byDate[(string) $r['date']] = $r;
                }
            }
        }
        ksort($byDate);
        return array_merge($prev, $next, ['entries' => array_values($byDate)]);
    };

    $result = Claude::generateValidated(
        $schema,
        [
            'purpose'    => 'other',
            'max_tokens' => 2000,
            'effort'     => 'low',
            'messages'   => [['role' => 'user', 'content' =>
                'Return one entry for each date 2026-03-01 through 2026-03-07, each '
                . 'with a colour. Make 2026-03-04 "beige" and the rest any other '
                . 'colour. Return only the JSON object.']],
        ],
        function (array $data): array {
            foreach ($data['entries'] ?? [] as $e) {
                if (strcasecmp((string) ($e['colour'] ?? ''), 'beige') === 0) {
                    return ["{$e['date']} is beige, which is not allowed. Send back "
                            . 'only that one corrected entry.'];
                }
            }
            return [];
        },
        3,
        $merge
    );

    if (!($result['ok'] ?? false)) {
        return 'retry loop failed: ' . (string) ($result['error'] ?? '?');
    }
    $attempts = (int) ($result['attempts'] ?? 0);
    $dates = array_column($result['data']['entries'] ?? [], 'date');
    printf("        attempts=%d entries=%d\n", $attempts, count($dates));

    if ($attempts < 2) {
        // The model ignored the instruction to include beige, so nothing was
        // rejected and the merge never ran. Not a defect — report and move on
        // rather than failing the suite on the model's cooperativeness.
        printf("        (first attempt was clean; merge path not exercised)\n");
        return count(array_unique($dates)) === 7
            ?: 'expected 7 entries even without a retry';
    }
    // The point: a retry that returned one entry must not have shrunk the result.
    return count(array_unique($dates)) === 7
        ?: 'merge lost entries — got ' . count(array_unique($dates))
           . ' of 7: ' . implode(',', $dates);
});

t('refusal / error path logs to ai_calls', function () {
    $before = (int) (DB::one('SELECT COUNT(*) AS n FROM ai_calls')['n'] ?? 0);
    // 'other' purpose, deliberately tiny max_tokens to force truncation, which
    // our client treats as a failure rather than a partial success.
    $r = Claude::send([
        'purpose'    => 'other',
        'max_tokens' => 1,
        'thinking'   => false,
        'messages'   => [['role' => 'user', 'content' => 'Write a long essay about protein.']],
    ]);
    $after = (int) (DB::one('SELECT COUNT(*) AS n FROM ai_calls')['n'] ?? 0);

    if ($after !== $before + 1) {
        return "expected 1 new ai_calls row, got " . ($after - $before);
    }
    // max_tokens truncation must not be reported as success.
    return $r['ok'] === false ?: 'a truncated response should not be ok';
});

echo "\n6. cost so far\n";

$summary = Claude::usageSummary(1);
printf("  calls today: %d rows by purpose\n", count($summary['by_purpose']));
foreach ($summary['by_purpose'] as $row) {
    printf("    %-18s %-18s %3d calls  in=%-7d out=%-6d  ~$%s\n",
        $row['purpose'], $row['model'], $row['calls'],
        $row['input_tokens'], $row['output_tokens'],
        $row['est_cost'] === null ? '?' : number_format((float) $row['est_cost'], 4));
}
printf("  estimated total: $%s\n", number_format((float) $summary['est_total'], 4));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
