<?php
declare(strict_types=1);

namespace App\Modules\Core\Persistence;

use App\Shared\Persistence\Connection;

/**
 * Base class for PDO repository implementations.
 *
 * Gives concrete repositories a memoized Connection + helpers for the
 * most common SELECT/INSERT/UPDATE patterns. Inheriting repositories are
 * memoized singletons via the Container (fix 004-05 from gateway — every
 * repository shares the same Connection instance).
 */
abstract class PdoRepository
{
    public function __construct(
        protected readonly Connection $connection
    ) {}

    /**
     * Run a SELECT and return all matching rows (FETCH_ASSOC).
     *
     * @param array<int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    protected function all(string $sql, array $params = []): array
    {
        return $this->connection->all($sql, $params);
    }

    /**
     * Run a SELECT and return the first row or null.
     *
     * @param array<int,mixed> $params
     * @return array<string,mixed>|null
     */
    protected function one(string $sql, array $params = []): ?array
    {
        return $this->connection->one($sql, $params);
    }

    /**
     * Run a SELECT and return the first column of the first row.
     *
     * @param array<int,mixed> $params
     */
    protected function scalar(string $sql, array $params = []): mixed
    {
        return $this->connection->scalar($sql, $params);
    }

    /**
     * Run an INSERT/UPDATE/DELETE — returns the number of affected rows.
     *
     * @param array<int,mixed> $params
     */
    protected function execute(string $sql, array $params = []): int
    {
        return $this->connection->execute($sql, $params);
    }

    /**
     * Run an INSERT and return the last-insert-id.
     *
     * @param array<int,mixed> $params
     */
    protected function insert(string $sql, array $params = []): string
    {
        return $this->connection->insert($sql, $params);
    }

    /**
     * Run a callable inside a DB transaction (commit on success, rollback
     * on exception).
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    protected function transactional(callable $fn): mixed
    {
        return $this->connection->transactional($fn);
    }

    /**
     * Escape a LIKE pattern (%, _, \).
     */
    protected function escapeLike(string $value): string
    {
        return $this->connection->escapeLike($value);
    }
}
