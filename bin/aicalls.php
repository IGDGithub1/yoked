<?php
declare(strict_types=1);

/**
 * Recent Claude API calls: size, stop reason, cost.
 *
 * Exists to answer "did that generation get truncated, and how close to the
 * ceiling are we normally running?" — a max_tokens truncation is a failed plan
 * for a real user, so the headroom is worth being able to see.
 *
 * Usage: php bin/aicalls.php [limit]
 */

require dirname(__DIR__) . '/src/bootstrap_cli.php';

$limit = max(1, min(200, (int) ($argv[1] ?? 20)));

// There is no stop_reason column; a truncation lands in `error` as the message
// Claude.php writes when it sees stop_reason = max_tokens.
$rows = DB::all(
    'SELECT purpose, input_tokens, output_tokens, cached_tokens,
            retry_count, ok, error, duration_ms, created_at
     FROM ai_calls ORDER BY id DESC LIMIT ' . $limit
);

if ($rows === []) {
    echo "No AI calls recorded.\n";
    exit(0);
}

printf("%-18s %-8s %-8s %-8s %-3s %-6s %s\n",
    'purpose', 'in', 'out', 'cached', 'try', 'sec', 'result');
echo str_repeat('-', 86), "\n";

$maxOut = 0;
$truncated = 0;

foreach ($rows as $r) {
    $out = (int) $r['output_tokens'];
    $maxOut = max($maxOut, $out);

    $err = (string) ($r['error'] ?? '');
    if (stripos($err, 'max_tokens') !== false) {
        $truncated++;
    }
    $result = (int) $r['ok'] === 1 ? 'ok' : ($err !== '' ? $err : 'failed');

    printf("%-18s %-8d %-8d %-8d %-3d %-6.1f %s\n",
        $r['purpose'], (int) $r['input_tokens'], $out, (int) $r['cached_tokens'],
        (int) $r['retry_count'], ((int) $r['duration_ms']) / 1000,
        substr($result, 0, 44));
}

echo "\nlargest output in this window: {$maxOut} tokens\n";
if ($truncated > 0) {
    echo "TRUNCATED: {$truncated} call(s) hit max_tokens — those responses were "
        . "unusable, which for a plan means the user got nothing.\n";
}
