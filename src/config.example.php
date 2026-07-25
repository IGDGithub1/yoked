<?php
/**
 * Copy to config.php (same directory) and fill in real values.
 * config.php is gitignored and must never be committed.
 */
return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'yoked',
        'user'    => 'CHANGE_ME',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // Absolute path for uploads. MUST be outside the web root — progress
    // photos are served through a gateway that checks ownership, never
    // directly by URL.
    'uploads_dir' => __DIR__ . '/../storage/uploads',

    'session' => [
        'name'     => 'yk_session',
        'lifetime' => 60 * 60 * 24 * 30,
        'secure'   => true,   // true in production (HTTPS)
    ],

    // Claude API. Single shared key, all users billed to the account owner.
    // Stays server-side always — never exposed to the client.
    'anthropic' => [
        'api_key' => '',

        // Model ids are fixed strings with NO date suffix. Do not append one —
        // 'claude-sonnet-5-20260101' and the like are 404s.
        //
        //   claude-sonnet-5   $3.00 / $15.00 per Mtok  (intro $2/$10 to 2026-08-31)
        //   claude-opus-5     $5.00 / $25.00 per Mtok
        //   claude-haiku-4-5  $1.00 /  $5.00 per Mtok
        //
        // Sonnet 5 is the pick here: near-Opus quality on reasoning at 60% of
        // the cost, and the whole app is a handful of calls per user per week.
        // The Keto Tracker reference pinned claude-sonnet-4-20250514, which was
        // already two generations stale when it was extracted — hence the note.
        'model'   => 'claude-sonnet-5',

        // Per-request tuning. Adaptive thinking is the only supported mode on
        // Sonnet 5; budget_tokens is removed and returns a 400.
        //   effort: low | medium | high | xhigh | max   (default high)
        'effort'  => 'high',

        // Rate limit per user per rolling hour. Plan generation is ~1/week and
        // food search is bursty, so this is a runaway-loop backstop, not a
        // usage cap.
        'max_calls_per_hour' => 30,
    ],

    // 'production' hides error details from API responses.
    'env' => 'production',
];
