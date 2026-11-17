<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Core\Domain\Person;

/**
 * Repository contract for Person entities (identity anchor).
 *
 * One person → many applications. The golden-link orchestrator uses
 * findByNationalCode() to locate all applicants that share a person.
 */
interface PersonRepositoryInterface
{
    public function findByNationalCode(string $nationalCode): ?Person;

    public function findById(int $id): ?Person;

    /**
     * Find or create a Person by national_code. Returns the person ID.
     *
     * @param array<string,mixed> $data
     */
    public function findOrCreate(array $data): int;

    /**
     * Create a new Person.
     *
     * @param array<string,mixed> $data
     */
    public function create(array $data): int;

    /**
     * All applications belonging to a person (multi-license support).
     *
     * @return array<int,array<string,mixed>>
     */
    public function applications(int $personId): array;

    /**
     * Sync person fields from the applicant's identity info.
     * Called when an applicant updates their name/national_code/mobile.
     */
    public function syncFromApplicant(int $applicantId): void;

    /**
     * Log an identity check attempt (success or failure). Used for rate
     * limiting + account lockout decisions.
     */
    public function logIdentityCheck(string $nationalCode, bool $success): void;

    /**
     * Recent failed identity check count (for lockout threshold).
     */
    public function recentFailedChecks(string $nationalCode, int $minutes = 10): int;
}
