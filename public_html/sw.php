<?php
declare(strict_types=1);

/**
 * Serves sw.js with headers that actually survive.
 *
 * Not indirection for its own sake: SiteGround's nginx serves .js files itself
 * and stamps a one-year Expires on them without ever consulting Apache, so an
 * .htaccess rule for /sw.js is silently ignored (see ../.htaccess for how that
 * was diagnosed). A service worker pinned for a year cannot be recalled, and it
 * governs every other cache — so this is the one static file worth a PHP hop.
 *
 * The app registers this path directly (see app/src/main.jsx) — a rewrite from
 * /sw.js would not work, because nginx matches the request URI before Apache is
 * consulted. A worker is normally scoped to its own directory, which is fine at
 * the web root, and Service-Worker-Allowed makes the root scope explicit.
 */

$file = __DIR__ . '/sw.js';

if (!is_readable($file)) {
    http_response_code(404);
    exit;
}

$body = (string) file_get_contents($file);
$etag = '"' . md5($body) . '"';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Service-Worker-Allowed: /');
header('ETag: ' . $etag);

// no-cache means "revalidate", not "never store" — so honour the conditional
// request and keep the common case a 304 rather than a full re-download.
$sent = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($sent !== '' && $sent === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . strlen($body));
echo $body;
