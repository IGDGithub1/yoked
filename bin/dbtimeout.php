<?php
declare(strict_types=1);

/**
 * Report the MySQL idle timeouts that govern how long a connection survives
 * while PHP is busy doing something else.
 *
 * Written because plan generation died with "2006 MySQL server has gone away":
 * a full-week Claude call takes minutes, and the connection opened before it
 * was closed by the server before the first write after it.
 */

require __DIR__ . '/../src/bootstrap_cli.php';

foreach (['wait_timeout', 'interactive_timeout', 'net_read_timeout',
          'net_write_timeout', 'max_allowed_packet'] as $var) {
    $row = DB::one("SHOW VARIABLES LIKE '{$var}'");
    printf("%-22s %s\n", $var, $row['Value'] ?? '(unknown)');
}

// PDO's own reconnect behaviour, for the record: mysqlnd does not reconnect,
// and ATTR_PERSISTENT would hand back an equally dead socket.
printf("\n%-22s %s\n", 'PDO persistent',
    DB::pdo()->getAttribute(PDO::ATTR_PERSISTENT) ? 'yes' : 'no');
printf("%-22s %s\n", 'server version', DB::pdo()->getAttribute(PDO::ATTR_SERVER_VERSION));

// ---- prove the recovery works -------------------------------------------
//
// The failure this guards against is not hypothetical: plan generation died
// with "2006 MySQL server has gone away" after a successful multi-minute
// generation, losing the API call it had just paid for.

$wait = (int) (DB::one("SHOW VARIABLES LIKE 'wait_timeout'")['Value'] ?? 0);

if (in_array('--kill', array_slice($argv, 1), true)) {
    echo "\nkilling this connection server-side, then querying again\n";

    $id = (int) DB::one('SELECT CONNECTION_ID() AS id')['id'];
    printf("  connection id: %d\n", $id);

    // KILL from a second connection — the same effect as the server timing the
    // connection out, without waiting wait_timeout seconds for it.
    $c = yk_config('db');
    $killer = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['name']),
        $c['user'], $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $killer->exec("KILL {$id}");
    usleep(300000);

    try {
        $row = DB::one('SELECT CONNECTION_ID() AS id');
        printf("  after kill: query succeeded on connection %d\n", (int) $row['id']);
        echo ((int) $row['id']) !== $id
            ? "  RECOVERED — reconnected transparently\n"
            : "  unexpected: same connection id\n";
    } catch (Throwable $e) {
        printf("  FAILED to recover: %s\n", $e->getMessage());
        exit(1);
    }

    // The transaction path matters separately: reconnect-and-retry is disabled
    // inside tx(), so tx() has to verify liveness before it opens.
    $killer->exec('KILL ' . (int) DB::one('SELECT CONNECTION_ID() AS id')['id']);
    usleep(300000);
    try {
        $n = DB::tx(fn() => (int) DB::one('SELECT 1 AS n')['n']);
        printf("  tx() after kill: ok (returned %d)\n", $n);
    } catch (Throwable $e) {
        printf("  tx() FAILED to recover: %s\n", $e->getMessage());
        exit(1);
    }
    echo "\nOK\n";
    exit(0);
}

printf("\nwait_timeout is %ds. A full-week generation takes minutes, so any\n", $wait);
echo "connection opened before the API call is dead by the first query after it.\n";
echo "DB::run() reconnects and retries once; DB::tx() checks liveness before\n";
echo "opening, because reconnecting mid-transaction would turn a rollback into\n";
echo "a partial commit.\n\n";
echo "Run with --kill to prove the recovery works.\n";
