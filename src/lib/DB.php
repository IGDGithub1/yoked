<?php
declare(strict_types=1);

/**
 * PDO wrapper. Prepared statements only — no string interpolation into SQL
 * anywhere in this codebase.
 *
 * Carried over from Friendspace unchanged; the pattern works and the whole
 * point of building on it was not rewriting this layer.
 */
final class DB
{
    private static ?PDO $pdo = null;

    /** True while inside tx(), where a silent reconnect would lose the rollback. */
    private static bool $inTransaction = false;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::connect();
        }
        return self::$pdo;
    }

    private static function connect(): void
    {
        $c = yk_config('db');
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $c['host'], $c['name'], $c['charset'] ?? 'utf8mb4'
        );
        self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Store and read all timestamps in UTC regardless of the server's
        // system zone. The client converts for display.
        self::$pdo->exec("SET time_zone = '+00:00'");
    }

    /**
     * Reconnect and retry once when the server has dropped an idle connection.
     *
     * SiteGround sets wait_timeout to 60 SECONDS. A Claude call for a full week
     * takes several minutes, so a connection opened before the call is reliably
     * dead by the first query after it — which surfaced as plan generation
     * dying with "2006 MySQL server has gone away" after a successful
     * generation. The cost of that is a wasted API call, so this is worth
     * handling centrally rather than at each call site.
     *
     * Only "gone away" / "lost connection" is retried, and only outside a
     * transaction: reconnecting mid-transaction would silently start a fresh
     * one, turning a rollback into a partial commit. Inside tx() the error
     * propagates so the caller can decide.
     */
    private static function withReconnect(callable $fn)
    {
        try {
            return $fn();
        } catch (PDOException $e) {
            if (self::$inTransaction || !self::isConnectionLost($e)) {
                throw $e;
            }
            error_log('[yoked] DB connection lost; reconnecting and retrying once');
            self::$pdo = null;
            self::connect();
            return $fn();
        }
    }

    /** Is this a dropped connection rather than a real query error? */
    private static function isConnectionLost(PDOException $e): bool
    {
        // 2006 = server has gone away, 2013 = lost connection during query.
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        if (in_array($driverCode, [2006, 2013], true)) {
            return true;
        }
        // Some builds surface these only in the message.
        $msg = $e->getMessage();
        return str_contains($msg, 'server has gone away')
            || str_contains($msg, 'Lost connection to')
            || str_contains($msg, 'MySQL server has gone away');
    }

    /**
     * Cheap liveness check, for use before a burst of writes that follows
     * something slow. Reconnects if the connection has died.
     *
     * Prefer this over relying on the retry above when a whole transaction is
     * about to run: the retry cannot help inside tx(), so the connection needs
     * to be known-good before the transaction opens.
     */
    public static function ensureConnected(): void
    {
        if (self::$pdo === null) {
            self::connect();
            return;
        }
        try {
            self::$pdo->query('SELECT 1');
        } catch (PDOException $e) {
            if (!self::isConnectionLost($e)) {
                throw $e;
            }
            error_log('[yoked] DB connection was stale; reconnected');
            self::$pdo = null;
            self::connect();
        }
    }

    /** Run a query with bound params, return the statement. */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        return self::withReconnect(function () use ($sql, $params): PDOStatement {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        });
    }

    /** Fetch one row or null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Fetch all rows. */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /**
     * Insert and return the new id.
     *
     * run() handles any reconnect before lastInsertId() reads it, so both hit
     * the same live connection. Do not reorder these or hoist pdo() above the
     * insert — a reconnect between them would return 0.
     */
    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Run $fn inside a transaction.
     *
     * Checks the connection is alive BEFORE opening the transaction, because
     * the reconnect-and-retry in run() is deliberately disabled inside one — a
     * silent reconnect mid-transaction would start a fresh transaction and turn
     * a rollback into a partial commit. With wait_timeout at 60 seconds, a
     * transaction that follows a slow AI call would otherwise fail on its first
     * statement.
     */
    public static function tx(callable $fn)
    {
        self::ensureConnected();

        $pdo = self::pdo();
        $pdo->beginTransaction();
        self::$inTransaction = true;
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            // The connection may itself be the casualty, in which case there is
            // nothing to roll back and rollBack() would throw over the top of
            // the real error.
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (PDOException $rollbackError) {
                error_log('[yoked] rollback failed: ' . $rollbackError->getMessage());
            }
            throw $e;
        } finally {
            self::$inTransaction = false;
        }
    }
}
