<?php
declare(strict_types=1);

namespace App\Shared\Persistence;

use App\Support\StructuredLogger;
use PDO;
use RuntimeException;

/**
 * Migration runner — scans app/Modules/*/migrations/*.sql, runs any not
 * yet applied, and records each application in the `migrations` table.
 *
 * Design (per docs/00-MASTER.md §24.5 & docs/SCHEMA_OWNERSHIP.md §4):
 *  - Migration files live in the module that owns the table:
 *    app/Modules/Core/migrations/, app/Modules/NationalGateway/migrations/, etc.
 *  - The runner is invoked at boot time (lazy, only when migrations table
 *    exists — see `migrations` migration below which bootstraps the table
 *    itself on first run via the `migrations` table bootstrap script).
 *  - Each migration runs in its own transaction (atomic).
 *  - A SHA-256 checksum of the file content is stored; if the checksum
 *    differs from the recorded one, the migration is RE-RUN (dangerous,
 *    so the runner logs a warning before re-running).
 *
 * Filename convention: YYYYMMDD_NNN_short_description.sql
 * (e.g. 20261117_001_create_users.sql). Files are sorted alphabetically
 * within each module, which preserves chronological order.
 */
final class MigrationRunner
{
    /** @var array<int, array{path:string,module:string}> */
    private array $queue = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly StructuredLogger $logger
    ) {}

    /**
     * Enqueue a migration file from a module.
     */
    public function enqueue(string $absolutePath, string $module): void
    {
        $this->queue[] = ['path' => $absolutePath, 'module' => $module];
    }

    /**
     * Run all enqueued migrations. Returns the number of newly applied.
     */
    public function run(): int
    {
        if ($this->queue === []) {
            return 0;
        }

        // Ensure the `migrations` table exists. If not, bootstrap it.
        $this->ensureMigrationsTable();

        $applied = 0;
        foreach ($this->queue as $entry) {
            if ($this->applyOne($entry['path'], $entry['module'])) {
                $applied++;
            }
        }
        return $applied;
    }

    /**
     * Create the `migrations` meta table if it does not exist.
     *
     * Per docs/00-MASTER.md §24.5, the table has: filename (UNIQUE),
     * module, applied_at, checksum (SHA-256 of the file content).
     */
    private function ensureMigrationsTable(): void
    {
        $sql = <<<SQL
        CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            module VARCHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            checksum CHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
        $this->connection->execute($sql);
    }

    /**
     * Apply a single migration file. Returns true if it was newly applied.
     */
    private function applyOne(string $path, string $module): bool
    {
        if (!is_file($path)) {
            $this->logger->warning('Migration file not found, skipping', [
                '_module' => $module,
                'path'    => $path,
            ]);
            return false;
        }
        $filename = basename($path);
        $content = (string)file_get_contents($path);
        $checksum = hash('sha256', $content);

        // Check if already applied with the same checksum.
        $existing = $this->connection->one(
            'SELECT checksum FROM migrations WHERE filename = ?',
            [$filename]
        );
        if ($existing !== null) {
            if ($existing['checksum'] === $checksum) {
                // Already applied, unchanged — skip.
                return false;
            }
            // Checksum differs — the file was modified after being applied.
            // Re-running could be destructive. Log a critical warning and
            // skip unless explicitly forced.
            $this->logger->critical('Migration file changed after apply — skipping (manual review required)', [
                '_module' => $module,
                'file'    => $filename,
                'old'     => $existing['checksum'],
                'new'     => $checksum,
            ]);
            return false;
        }

        // Run the migration inside a transaction.
        try {
            $this->connection->transactional(function () use ($content, $filename, $module, $checksum): void {
                $this->executeSqlScript($content);
                $this->connection->execute(
                    'INSERT INTO migrations (filename, module, checksum) VALUES (?, ?, ?)',
                    [$filename, $module, $checksum]
                );
            });
            $this->logger->info('Migration applied', [
                '_module' => $module,
                'file'    => $filename,
                'checksum' => $checksum,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->critical('Migration failed', [
                '_module' => $module,
                'file'    => $filename,
                'error'   => $e->getMessage(),
            ]);
            throw new RuntimeException(
                "Migration failed: $filename — " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Execute a SQL script that may contain multiple statements.
     *
     * Strategy: split on `;` followed by newline (simple but works for
     * our hand-written migration files — none of them use `;` inside
     * string literals or stored procedures).
     *
     * Each statement is executed via PDO::exec (no prepare needed; the
     * migration files contain only DDL/DML with no user-supplied params).
     */
    private function executeSqlScript(string $content): void
    {
        // Strip /* ... */ block comments and -- line comments.
        $content = preg_replace('#/\*.*?\*/#s', '', $content) ?? $content;
        $content = preg_replace('#--[^\n]*#', '', $content) ?? $content;

        $statements = array_filter(
            array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $content) ?: []),
            static fn (string $s): bool => $s !== ''
        );

        foreach ($statements as $stmt) {
            if ($stmt === '') {
                continue;
            }
            $this->connection->pdo()->exec($stmt);
        }
    }

    /**
     * @return array<int, array{filename:string,module:string,checksum:string,applied_at:string}>
     */
    public function applied(): array
    {
        $this->ensureMigrationsTable();
        return $this->connection->all(
            'SELECT filename, module, checksum, applied_at FROM migrations ORDER BY applied_at ASC'
        );
    }
}
