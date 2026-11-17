<?php
declare(strict_types=1);

namespace App\Modules\Core\Persistence;

use App\Shared\Contracts\UserRepositoryInterface;

final class PdoUserRepository extends PdoRepository implements UserRepositoryInterface
{
    public function findByUsername(string $username): ?array
    {
        return $this->fetchOne('SELECT * FROM users WHERE username = ? LIMIT 1', [$username]);
    }

    public function findById(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
    }

    public function getAll(): array
    {
        return $this->fetchAll('SELECT * FROM users ORDER BY created_at DESC');
    }

    public function create(array $data): int
    {
        return (int)$this->insert(
            'INSERT INTO users (username, password, display_name, role) VALUES (?, ?, ?, ?)',
            [
                $data['username'],
                $data['password'],
                $data['display_name'] ?? null,
                $data['role'] ?? 'admin',
            ]
        );
    }

    public function update(int $id, array $data): void
    {
        $sets = [];
        $params = [];
        foreach (['username', 'password', 'display_name', 'role'] as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $this->execute('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM users WHERE id = ?', [$id]);
    }

    public function countByRole(string $role): int
    {
        return (int)$this->fetchScalar('SELECT COUNT(*) FROM users WHERE role = ?', [$role]);
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
