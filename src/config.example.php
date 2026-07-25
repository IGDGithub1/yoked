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
        // Look up a current model id rather than pinning a stale one; the
        // Keto Tracker reference pinned claude-sonnet-4-20250514, which was
        // already two generations old when it was extracted.
        'model'   => '',
    ],

    // 'production' hides error details from API responses.
    'env' => 'production',
];
