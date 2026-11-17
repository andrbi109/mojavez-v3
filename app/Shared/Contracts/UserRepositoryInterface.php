<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Repository contract for User entities (admin accounts only).
 *
 * Two realms: admins use this; applicants use UserAccountRepositoryInterface.
 */
interface UserRepositoryInterface
{
    public function findByUsername(string $username): ?array;

    public function findById(int $id): ?array;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getAll(): array;

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int;

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): void;

    public function delete(int $id): void;

    public function countByRole(string $role): int;

    public function verifyPassword(string $password, string $hash): bool;
}
