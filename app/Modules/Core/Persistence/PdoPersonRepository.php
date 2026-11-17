<?php
declare(strict_types=1);

namespace App\Modules\Core\Persistence;

use App\Modules\Core\Domain\Person;
use App\Shared\Contracts\PersonRepositoryInterface;

/**
 * PDO implementation of PersonRepositoryInterface.
 */
final class PdoPersonRepository extends PdoRepository implements PersonRepositoryInterface
{
    public function findByNationalCode(string $nationalCode): ?Person
    {
        $row = $this->one('SELECT * FROM persons WHERE national_code = ? LIMIT 1', [$nationalCode]);
        return $row === null ? null : Person::fromArray($row);
    }

    public function findById(int $id): ?Person
    {
        $row = $this->one('SELECT * FROM persons WHERE id = ? LIMIT 1', [$id]);
        return $row === null ? null : Person::fromArray($row);
    }

    public function findOrCreate(array $data): int
    {
        $existing = $this->findByNationalCode((string)$data['national_code']);
        if ($existing !== null) {
            return $existing->id;
        }
        return $this->create($data);
    }

    public function create(array $data): int
    {
        $fields = ['national_code', 'first_name', 'last_name', 'father_name', 'birth_date',
                   'mobile', 'mobile_2', 'mobile_3', 'postal_code', 'person_type',
                   'company_name', 'company_national_id', 'reg_number', 'reg_date',
                   'company_type', 'economic_code'];
        $cols = [];
        $placeholders = [];
        $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $cols[] = $f;
                $placeholders[] = '?';
                $params[] = $data[$f];
            }
        }
        if ($cols === []) {
            throw new \InvalidArgumentException('No fields provided for person create');
        }
        $sql = 'INSERT INTO persons (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        return (int)$this->insert($sql, $params);
    }

    public function applications(int $personId): array
    {
        return $this->all(
            'SELECT * FROM applicants WHERE person_id = ? AND deleted_at IS NULL ORDER BY created_at DESC',
            [$personId]
        );
    }

    public function syncFromApplicant(int $applicantId): void
    {
        // Pull first_name/last_name/national_code/mobile from applicants into
        // persons (only if the applicant's person exists). Skips silently
        // if applicant has no person_id.
        $this->execute(
            'UPDATE persons p
             INNER JOIN applicants a ON a.person_id = p.id
             SET p.first_name = a.first_name,
                 p.last_name = a.last_name,
                 p.national_code = a.national_code,
                 p.mobile = a.mobile,
                 p.father_name = COALESCE(a.father_name, p.father_name),
                 p.birth_date = COALESCE(a.birth_date, p.birth_date)
             WHERE a.id = ?',
            [$applicantId]
        );
    }

    public function logIdentityCheck(string $nationalCode, bool $success): void
    {
        $this->insert(
            'INSERT INTO identity_checks (national_code, ip, user_agent, success) VALUES (?, ?, ?, ?)',
            [
                $nationalCode,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
                $success ? 1 : 0,
            ]
        );
    }

    public function recentFailedChecks(string $nationalCode, int $minutes = 10): int
    {
        return (int)$this->scalar(
            'SELECT COUNT(*) FROM identity_checks
             WHERE national_code = ? AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$nationalCode, $minutes]
        );
    }
}
