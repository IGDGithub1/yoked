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
// Plans.php only for MAX_OUTPUT_TOKENS, so the ceiling reported here cannot
// drift from the one actually used. Its dependencies come along for the ride.
require YK_SRC . '/lib/Response.php';
require YK_SRC . '/lib/RateLimit.php';
require YK_SRC . '/lib/Goals.php';
require YK_SRC . '/lib/Claude.php';
require YK_SRC . '/lib/PlanSchema.php';
require YK_SRC . '/lib/BuddySchedule.php';   // Safety::checkAvailability reads it
require YK_SRC . '/lib/Safety.php';
require YK_SRC . '/lib/Plans.php';

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

printf("%-16s %-16s %-7s %-7s %-7s %-3s %-6s %s\n",
    'when', 'purpose', 'in', 'out', 'cached', 'try', 'sec', 'result');
echo str_repeat('-', 100), "\n";

$maxOut = 0;
$truncated = 0;
$truncatedPlans = 0;
$planOutputs = [];

foreach ($rows as $r) {
    $out = (int) $r['output_tokens'];
    $maxOut = max($maxOut, $out);

    $isPlan = str_contains((string) $r['purpose'], 'plan');
    $err = (string) ($r['error'] ?? '');
    if (stripos($err, 'max_tokens') !== false) {
        $truncated++;
        if ($isPlan) {
            $truncatedPlans++;
        }
    } elseif ($isPlan && (int) $r['ok'] === 1) {
        $planOutputs[] = $out;
    }
    $result = (int) $r['ok'] === 1 ? 'ok' : ($err !== '' ? $err : 'failed');

    // Timestamp first, and short: the usual question is "was that truncation
    // before or after the fix I just made", which needs the date to answer.
    printf("%-16s %-16s %-7d %-7d %-7d %-3d %-6.1f %s\n",
        date('m-d H:i', strtotime((string) $r['created_at'])),
        $r['purpose'], (int) $r['input_tokens'], $out, (int) $r['cached_tokens'],
        (int) $r['retry_count'], ((int) $r['duration_ms']) / 1000,
        substr($result, 0, 40));
}

echo "\nlargest output in this window: {$maxOut} tokens\n";

// Headroom is the number worth watching. A successful plan close to the ceiling
// is a plan that fails next week when the week is slightly busier.
if ($planOutputs !== []) {
    printf("successful plans: %d, output %d-%d tokens (ceiling is %d)\n",
        count($planOutputs), min($planOutputs), max($planOutputs), Plans::MAX_OUTPUT_TOKENS);
    $headroom = Plans::MAX_OUTPUT_TOKENS - max($planOutputs);
    printf("headroom below the ceiling: %d tokens (%.0f%%)\n",
        $headroom, 100 * $headroom / Plans::MAX_OUTPUT_TOKENS);
}

if ($truncated > 0) {
    echo "\nTRUNCATED: {$truncated} call(s) hit max_tokens.\n";
    if ($truncatedPlans > 0) {
        echo "  {$truncatedPlans} of those were PLANS — a truncated plan is not a "
            . "degraded plan, it is no plan:\n  the JSON is incomplete, so nothing "
            . "parses and the user gets nothing. Raise max_tokens\n  in "
            . "src/lib/Plans.php.\n";
    } else {
        echo "  None were plans. Check the timestamps — a truncation from before a "
            . "fix is history,\n  not a live problem.\n";
    }
}
