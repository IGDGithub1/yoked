<?php
declare(strict_types=1);

/**
 * Verify the database is reachable with the credentials in src/config.php.
 *
 * Separate from migrate.php so a connection problem is diagnosed on its own,
 * rather than surfacing as a confusing failure mid-migration.
 *
 *   php bin/dbcheck.php
 *
 * Never prints the password — only its shape, which is enough to spot the
 * usual culprits (empty, unreplaced placeholder, stray whitespace).
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$c = yk_config('db');
printf("host=%s  db=%s  user=%s\n", $c['host'], $c['name'], $c['user']);

// Diagnose the credential's shape without revealing it.
$pass = (string) ($c['pass'] ?? '');
printf("pass:      %d chars", strlen($pass));
if ($pass === '') {
    echo "  ** EMPTY **";
} elseif ($pass === 'CHANGE_ME') {
    echo "  ** still the placeholder **";
} else {
    if ($pass !== trim($pass)) {
        echo "  ** has leading/trailing whitespace **";
    }
    if (preg_match('/[\r\n]/', $pass)) {
        echo "  ** contains a newline **";
    }
}
echo "\n\n";

try {
    $pdo = DB::pdo();
    printf("server:    %s\n", $pdo->query('SELECT VERSION()')->fetchColumn());
    printf("charset:   %s\n", $pdo->query("SHOW VARIABLES LIKE 'character_set_database'")->fetch()['Value'] ?? '?');
    printf("time_zone: %s\n", $pdo->query('SELECT @@session.time_zone')->fetchColumn());

    $tables = DB::all('SHOW TABLES');
    printf("tables:    %d\n", count($tables));
    foreach ($tables as $row) {
        echo '  - ' . current($row) . "\n";
    }

    // Foreign keys are the thing most likely to have silently not been
    // created (MySQL used to ignore them on some engines), so count them.
    $fks = DB::one(
        'SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = "FOREIGN KEY"'
    );
    printf("\nforeign keys: %d\n", (int) ($fks['n'] ?? 0));

    echo "\nOK\n";
} catch (Throwable $e) {
    echo 'FAILED: ' . $e->getMessage() . "\n\n";
    // 1045 is auth; 1044/1049 mean the user exists but can't see the database.
    if (str_contains($e->getMessage(), '1045')) {
        echo "Access denied — either the password is wrong, or the user is not\n";
        echo "granted on this database. SiteGround creates users and databases\n";
        echo "separately; check Site Tools -> MySQL -> Databases and confirm\n";
        echo "{$c['user']} is listed as a user on {$c['name']}.\n";
    } elseif (str_contains($e->getMessage(), '1049')) {
        echo "Unknown database — check the name in Site Tools -> MySQL.\n";
    }
    exit(1);
}
