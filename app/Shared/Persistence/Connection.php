<?php
declare(strict_types=1);

namespace App\Shared\Persistence;

use App\Shared\Support\StructuredLogger;
use PDO;
use PDOException;
use RuntimeException;

/**
 * MySQL PDO connection — memoized singleton.
 *
 * Replaces v2's `Database::getInstance()` Singleton pattern and gateway's
 * SQLite Connection. Every repository receives this same instance via the
 * Container (fix 004-05 from gateway — Connection was a factory before).
 *
 * Configuration (from config/database.php):
 *  - charset: utf8mb4, collation: utf8mb4_unicode_ci (full Persian + ZWNJ).
 *  - session timezone: +00:00 (UTC for unified TIMESTAMP handling).
 *  - ATTR_EMULATE_PREPARES = false — fixes the named-param duplication quirk
 *    and forces real prepared statements (CODING_CONVENTIONS §5).
 *  - ERRMODE_EXCEPTION — PDO throws on error (no manual error checking).
 *  - DEFAULT_FETCH_MODE = FETCH_ASSOC (rows returned as string-keyed arrays).
 *
 * Robustness:
 *  - boot() retries connection up to 3 times with exponential backoff
 *    (200ms, 400ms, 800ms) before failing. Useful on shared hosting where
 *    the DB server may be briefly unreachable.
 *  - All failures are logged via StructuredLogger with context.
 */
final class Connection
{
    private static ?Connection $instance = null;

    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $name,
        private readonly string $user,
        private readonly string $pass,
        private readonly string $charset,
        private readonly string $timezone,
        private readonly StructuredLogger $logger
    ) {
        // Singleton enforcement — but allow construction so the Container
        // can build the first instance. Re-construction in tests is fine
        // (separate process). Memoization happens via the Container.
    }

    /**
     * Memoized singleton factory — the Container calls this once and binds
     * the result; subsequent make(Connection::class) returns the same object.
     */
    public static function instance(
        string $host,
        int $port,
        string $name,
        string $user,
        string $pass,
        string $charset,
        string $timezone,
        StructuredLogger $logger
    ): self {
        if (self::$instance === null) {
            self::$instance = new self(
                $host, $port, $name, $user, $pass, $charset, $timezone, $logger
            );
        }
        return self::$instance;
    }

    /**
     * Boot (lazily create) the PDO handle.
     */
    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->name,
            $this->charset
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            // Buffer queries client-side so multi-statement scripts (like
            // migration files split on ; into many exec() calls) don't trip
            // the "unbuffered queries active" error when the next query starts
            // before the previous result set is fully consumed.
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ];

        $maxAttempts = 3;
        $delayMs = 200;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->pdo = new PDO($dsn, $this->user, $this->pass, $options);
                $this->bootSession();
                $this->logger->info('DB connection established', [
                    '_module' => 'core',
                    'host'    => $this->host,
                    'name'    => $this->name,
                    'attempt' => $attempt,
                ]);
                return $this->pdo;
            } catch (PDOException $e) {
                $this->logger->error('DB connection attempt failed', [
                    '_module' => 'core',
                    'host'    => $this->host,
                    'name'    => $this->name,
                    'attempt' => $attempt,
                    'error'   => $e->getMessage(),
                ]);
                if ($attempt < $maxAttempts) {
                    usleep($delayMs * 1000);
                    $delayMs *= 2;
                } else {
                    throw new RuntimeException(
                        'Could not connect to database after ' . $maxAttempts
                        . ' attempts: ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        }
        // Unreachable — but PHP needs a return.
        throw new RuntimeException('DB connection logic error');
    }

    /**
     * Apply session timezone and charset to the connection.
     */
    private function bootSession(): void
    {
        // charset + timezone come from Environment (not user input), but we
        // whitelist them defensively so a misconfigured .env can't inject SQL.
        $allowedCharsets = ['utf8mb4' => true, 'utf8' => true, 'latin1' => true];
        $charset = isset($allowedCharsets[$this->charset]) ? $this->charset : 'utf8mb4';
        // timezone must be a fixed offset like '+00:00' or a IANA name.
        $tz = preg_match('/^[A-Za-z_\/]+(\+|-)\d{2}:\d{2}$/', $this->timezone)
            ? $this->timezone
            : '+00:00';
        $this->pdo->exec("SET NAMES {$charset} COLLATE utf8mb4_unicode_ci");
        $this->pdo->exec("SET time_zone = '{$tz}'");
        $this->pdo->exec("SET sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
    }

    /**
     * Run a SELECT and return an array of rows (FETCH_ASSOC).
     *
     * @param array<int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Run a SELECT and return the first row or null.
     *
     * @param array<int,mixed> $params
     * @return array<string,mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Run a SELECT and return the first column of the first row (scalar).
     *
     * @param array<int,mixed> $params
     */
    public function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * Run an INSERT/UPDATE/DELETE — returns the number of affected rows.
     *
     * @param array<int,mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Run an INSERT and return the last-insert-id.
     *
     * @param array<int,mixed> $params
     */
    public function insert(string $sql, array $params = []): string
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return (string)$this->pdo()->lastInsertId();
    }

    /**
     * Run a callable inside a DB transaction.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transactional(callable $fn): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Escape a LIKE pattern (%, _, \) for safe use in a prepared LIKE clause.
     */
    public function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
