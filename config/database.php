<?php
declare(strict_types=1);

/**
 * Database config (MySQL/MariaDB via PDO).
 *
 * Engine: MySQL 5.7+ / MariaDB 10.3+ with InnoDB.
 * Charset: utf8mb4 / utf8mb4_unicode_ci (full Persian + ZWNJ support).
 * Session timezone: +00:00 (UTC for unified timestamps) — see
 * App\Shared\Persistence\Connection::boot().
 *
 * ATTR_EMULATE_PREPARES = false — fixes the named-param duplication quirk
 * and forces the DB to plan real prepared statements (CODING_CONVENTIONS §5).
 *
 * @return array<string,mixed>
 */
return [
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'] ?? 'localhost',
    'port'      => (int)($_ENV['DB_PORT'] ?? 3306),
    'name'      => $_ENV['DB_NAME'] ?? 'mojavez_v3',
    'user'      => $_ENV['DB_USER'] ?? 'root',
    'pass'      => $_ENV['DB_PASS'] ?? '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'timezone'  => '+00:00',  // PDO session timezone (UTC)

    // PDO options
    'options'   => [
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES   => false,
        // Constant-time fetch emulation off → real prepared statements.
        \PDO::ATTR_PERSISTENT         => false,
    ],
];
