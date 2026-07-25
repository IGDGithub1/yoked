<?php
declare(strict_types=1);

/**
 * Report the PHP environment Yoked actually runs in.
 *
 * Exists because the CLI and web SAPIs on shared hosting frequently load
 * DIFFERENT php.ini files — an extension enabled in Site Tools may be present
 * for web requests and absent for cron. Both matter here: cron generates
 * plans, and the web tier handles uploads.
 *
 *   php bin/envcheck.php          the CLI SAPI (cron, migrate)
 *   curl .../api/envcheck         the web SAPI, if wired up
 */

require __DIR__ . '/../src/bootstrap_cli.php';

printf("sapi:            %s\n", PHP_SAPI);
printf("version:         %s\n", PHP_VERSION);
printf("ini:             %s\n", php_ini_loaded_file() ?: '(none)');
$scanned = php_ini_scanned_files();
printf("scanned:         %s\n", $scanned ? trim(str_replace(",\n", ' ', $scanned)) : '(none)');
printf("extension_dir:   %s\n", ini_get('extension_dir'));
echo "\n";

// Required now vs. required later, stated separately so a missing optional
// extension doesn't read as a blocker.
$required = [
    'pdo_mysql' => 'database',
    'mbstring'  => 'string handling',
    'json'      => 'everything',
    'curl'      => 'Claude API, Open Food Facts',
];
$imaging = [
    'imagick' => 'preferred image pipeline (re-encode uploads)',
    'gd'      => 'fallback image pipeline',
];

echo "required\n";
$missing = [];
foreach ($required as $ext => $why) {
    $ok = extension_loaded($ext);
    printf("  %-12s %-4s %s\n", $ext, $ok ? 'ok' : 'MISS', $why);
    if (!$ok) {
        $missing[] = $ext;
    }
}

echo "\nimaging\n";
foreach ($imaging as $ext => $why) {
    printf("  %-12s %-4s %s\n", $ext, extension_loaded($ext) ? 'ok' : '--', $why);
}

// Progress photos must be re-encoded rather than stored as uploaded — that is
// what strips EXIF and neutralises a file pretending to be an image. Either
// library can do it; having neither means uploads cannot be made safe.
$canReencode = extension_loaded('imagick') || extension_loaded('gd');
printf("\nre-encode uploads: %s\n", $canReencode ? 'yes' : 'NO — uploads cannot be sanitised');

if (extension_loaded('imagick')) {
    $v = Imagick::getVersion();
    printf("imagick:           %s\n", $v['versionString'] ?? '?');
    printf("formats:           %d\n", count(Imagick::queryFormats()));
} elseif (extension_loaded('gd')) {
    $info = gd_info();
    printf("gd:                %s\n", $info['GD Version'] ?? '?');
    printf("jpeg/png/webp:     %s/%s/%s\n",
        !empty($info['JPEG Support'])       ? 'y' : 'n',
        !empty($info['PNG Support'])        ? 'y' : 'n',
        !empty($info['WebP Support'])       ? 'y' : 'n');
}

// Outbound HTTPS is not guaranteed on shared hosting, and the whole app
// depends on reaching the Claude API. Cheap to prove rather than assume.
echo "\noutbound https\n";
foreach (['https://api.anthropic.com' => 'Claude API',
          'https://world.openfoodfacts.org' => 'Open Food Facts'] as $url => $label) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    // Any HTTP response proves reachability; 401/404 from an unauthenticated
    // HEAD is a success for this purpose.
    printf("  %-18s %s\n", $label,
        $code > 0 ? "reachable (HTTP {$code})" : "FAILED — {$err}");
}

echo "\n";
if ($missing) {
    echo 'BLOCKING: missing ' . implode(', ', $missing) . "\n";
    exit(1);
}
echo "OK\n";
