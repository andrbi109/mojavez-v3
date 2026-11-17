<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Services;

use App\Events\EventBus;
use App\Events\Events\ApplicantMatched;
use App\Events\Events\OperatorFlag;
use App\Modules\Core\Domain\Applicant;
use App\Modules\Core\Domain\Enums\ApplicantStatus;
use App\Modules\Core\Domain\Enums\LicenseState;
use App\Modules\Core\Domain\Person;
use App\Modules\NationalGateway\Domain\License;
use App\Modules\NationalGateway\Services\PatternMatcher;
use App\Modules\NationalGateway\Services\MatchResult;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\JobAndPropertyRepositoryInterface;
use App\Shared\Contracts\NationalLicenseRepositoryInterface;
use App\Shared\Contracts\PersonRepositoryInterface;
use App\Shared\Contracts\SyncAuditLogRepositoryInterface;
use App\Shared\Support\StructuredLogger;

/**
 * ApplicantSyncAdapter (ACL) — the bridge between NationalGateway and Core.
 *
 * Per docs/00-MASTER.md §9.5 (step 4 "act") — given a matched national
 * license + applicant, performs the atomic "act" transaction:
 *
 *   For AutoConfirmed (confidence >= 90):
 *     1. UPDATE applicants SET national_license_request_number=...,
 *        match_confidence=90, matched_at=NOW(),
 *        license_state='صادر شده', license_valid_until=<expiry or NULL>.
 *     2. validateStatusTransition(current, 'صدور مجوز').
 *     3. UPDATE applicants SET current_status='صدور مجوز'.
 *     4. INSERT INTO status_log (triggered_by='auto_sync').
 *     5. UPDATE national_licenses SET matched_applicant_id=...,
 *        match_confidence=90, matched_by='auto_sync'.
 *     6. INSERT INTO sync_audit_log (action='auto_confirmed').
 *
 *   For OperatorFlag (confidence 50-89):
 *     1. INSERT INTO sync_audit_log (action='operator_flagged').
 *     2. (No status change — pending manual review.)
 *
 *   For AutoRejected (confidence 0):
 *     1. INSERT INTO sync_audit_log (action='auto_rejected').
 *
 * The act step is atomic PER APPLICANT (wrapped in a DB transaction).
 * Per §9.6 safety rule 5, every decision is logged to sync_audit_log.
 *
 * This class is intentionally NOT the orchestrator — it does one match +
 * act. The NationalSyncOrchestrator drives the cycle (scrape → for-each-
 * license → for-each-applicant → matchQuorum → ApplicantSyncAdapter.act()).
 */
final class ApplicantSyncAdapter
{
    public function __construct(
        private readonly PatternMatcher $matcher,
        private readonly ApplicantRepositoryInterface $applicantRepo,
        private readonly PersonRepositoryInterface $personRepo,
        private readonly JobAndPropertyRepositoryInterface $propertyRepo,
        private readonly NationalLicenseRepositoryInterface $licenseRepo,
        private readonly SyncAuditLogRepositoryInterface $auditRepo,
        private readonly EventBus $eventBus,
        private readonly StructuredLogger $logger,
    ) {}

    /**
     * Try to match ONE national license against all eligible applicants.
     *
     * Returns a summary: how many applicants were tried, how many of each
     * outcome. The orchestrator uses this to drive the cycle.
     *
     * @return array{tried:int,auto_confirmed:int,operator_flagged:int,auto_rejected:int}
     */
    public function matchLicenseAgainstApplicants(License $license): array
    {
        $summary = ['tried' => 0, 'auto_confirmed' => 0, 'operator_flagged' => 0, 'auto_rejected' => 0];

        // §9.5 step 3: fetch all applicants in pre-issue statuses.
        $applicants = $this->applicantRepo->findAutoSyncEligible();
        if ($applicants === []) {
            return $summary;
        }

        foreach ($applicants as $applicant) {
            $summary['tried']++;
            $result = $this->matchAndAct($license, $applicant);
            if (isset($summary[$result])) {
                $summary[$result]++;
            }
        }
        return $summary;
    }

    /**
     * Match one license against one applicant, then act on the result.
     *
     * Returns the outcome string ('auto_confirmed' | 'operator_flagged' |
     * 'auto_rejected') so the caller can aggregate stats.
     */
    public function matchAndAct(License $license, Applicant $applicant): string
    {
        // Build the Person from the Applicant. Applicant carries denormalized
        // firstName/lastName/nationalCode/mobile for fast lookups, but father_name
        // lives only on the Person entity — fetch it via the Person repository
        // if the applicant has a person_id.
        $fatherName = null;
        if ($applicant->personId !== null && $applicant->personId > 0) {
            // The Person repository is fetched lazily to avoid a hard dependency
            // in the constructor (ACL stays focused on the match+act logic).
            // We use the Container's PdoPersonRepository via the interface.
            $person = $this->personRepo->findById($applicant->personId);
            if ($person !== null) {
                $fatherName = $person->fatherName;
            }
        }

        $personVo = new Person(
            id: $applicant->personId ?? 0,
            nationalCode: $applicant->nationalCode,
            firstName: $applicant->firstName,
            lastName: $applicant->lastName,
            mobile: $applicant->mobile,
            personType: \App\Modules\Core\Domain\Enums\PersonType::Real,
            fatherName: $fatherName,
        );

        // Property details (province/township/business_address/postal_code)
        // come from the property_details table (1:1 with applicants). This
        // was D-4-2 — previously the matcher got an empty array, so only
        // national_code + name + father_name could match. Now the full
        // 9-field quorum can fire (AutoConfirmed requires national code +
        // ≥2 OTHER fields — province + township + address are 3 more).
        $property = [];
        $propertyDetail = $this->propertyRepo->findPropertyDetailByApplicantId($applicant->id);
        if ($propertyDetail !== null) {
            // Build the full business address from the components (street +
            // alley + plaque) — the upstream business_address is a single
            // string, so we concatenate for the matcher.
            $addressParts = array_filter([
                $propertyDetail->addressStreet,
                $propertyDetail->addressAlley,
                $propertyDetail->addressPlaque ? 'پلاک ' . $propertyDetail->addressPlaque : null,
            ]);
            // Phase 24 (v1.9.6 / D-5-5): prefer the new `township` column
            // (the resolved title from the wizard's `township_id` select)
            // over `county` (the v2 backward-compat value). Old rows
            // written before migration 013 have township=NULL — fall
            // back to `county` for those.
            $townshipValue = $propertyDetail->township ?? $propertyDetail->county;
            $property = [
                'province' => $propertyDetail->province,
                'township' => $townshipValue,
                'business_address' => implode('، ', $addressParts),
                'postal_code' => $applicant->postalCode,  // postal_code lives on applicant, not property
            ];
        }

        $result = $this->matcher->matchQuorum($license, $personVo, $property);

        // Log the match attempt regardless of outcome (§9.6 rule 5).
        $this->auditRepo->log(
            applicantId: $applicant->id,
            nationalRequestNumber: $license->requestNumber,
            action: 'match_attempted',
            outcome: $this->outcomeForConfidence($result->confidence),
            confidence: $result->totalScore,
            context: [
                'fields_matched' => $result->fieldsMatched,
                'national_code_matched' => $result->nationalCodeMatched,
                'license_title' => $license->licenseTitle,
                'reason' => $result->reason,
            ]
        );

        return $this->act($license, $applicant, $result);
    }

    /**
     * Execute the "act" step for a matched license + applicant.
     * Atomic per-applicant (wrapped in a DB transaction).
     *
     * Returns the outcome string.
     */
    private function act(License $license, Applicant $applicant, MatchResult $result): string
    {
        $confidence = $result->confidence;

        if ($confidence === \App\Modules\NationalGateway\Domain\Enums\MatchConfidence::AutoConfirmed) {
            $this->actAutoConfirmed($license, $applicant, $result);
            return 'auto_confirmed';
        }

        if ($confidence === \App\Modules\NationalGateway\Domain\Enums\MatchConfidence::OperatorFlag) {
            $this->actOperatorFlagged($license, $applicant, $result);
            return 'operator_flagged';
        }

        // AutoRejected — just log, no status change.
        $this->auditRepo->log(
            applicantId: $applicant->id,
            nationalRequestNumber: $license->requestNumber,
            action: 'auto_rejected',
            outcome: 'auto_rejected',
            confidence: 0,
            context: ['reason' => $result->reason]
        );
        return 'auto_rejected';
    }

    /**
     * AutoConfirmed action — atomic per-applicant transaction.
     * Per §9.5 step 4 AutoConfirmed.
     */
    private function actAutoConfirmed(License $license, Applicant $applicant, MatchResult $result): void
    {
        // 1. Mark the applicant matched (sets national_license_request_number,
        //    match_confidence, matched_at — idempotent via UNIQUE constraint).
        $this->applicantRepo->markMatchedToNational(
            $applicant->id,
            $license->requestNumber,
            $result->confidence->value,
            'auto_sync'
        );

        // 2. Transition applicant status to 'صدور مجوز' (license issued).
        //    validateStatusTransition is enforced inside changeStatus().
        try {
            $this->applicantRepo->changeStatus(
                $applicant->id,
                ApplicantStatus::from('صدور مجوز'),
                'auto_sync',
                'اتصال طلایی: مجوز در درگاه ملی صادر شد (RN=' . $license->requestNumber . ')'
            );
        } catch (\Throwable $e) {
            // Status transition may be invalid (e.g. applicant already past
            // 'صدور مجوز'). Log but don't crash the cycle — the match is still
            // recorded, the applicant just doesn't get re-transitioned.
            $this->logger->warning('AutoConfirmed: status transition failed', [
                'applicant_id' => $applicant->id,
                'rn' => $license->requestNumber,
                'error' => $e->getMessage(),
            ]);
        }

        // 3. Update license state on the applicant.
        $this->applicantRepo->updateLicenseState(
            $applicant->id,
            LicenseState::Issued,
            $license->expiresAt
        );

        // 4. Set the match result on the national license row.
        $this->licenseRepo->setMatch(
            $license->requestNumber,
            $applicant->id,
            $result->confidence->value,
            'auto_sync'
        );

        // 5. Audit log.
        $this->auditRepo->log(
            applicantId: $applicant->id,
            nationalRequestNumber: $license->requestNumber,
            action: 'auto_confirmed',
            outcome: 'auto_confirmed',
            confidence: $result->confidence->value,
            context: [
                'fields_matched' => $result->fieldsMatched,
                'total_score' => $result->totalScore,
                'license_title' => $license->licenseTitle,
                'expires_at' => $license->expiresAt,
            ]
        );

        $this->logger->info('Golden link AutoConfirmed', [
            'applicant_id' => $applicant->id,
            'rn' => $license->requestNumber,
            'score' => $result->totalScore,
        ]);

        // §9.5 step 5: dispatch ApplicantMatched so NotificationSubscriber
        // (Phase 4g) sends SMS + in-app notification to the applicant.
        $this->eventBus->dispatch(new ApplicantMatched([
            'applicant_id' => $applicant->id,
            'national_request_number' => $license->requestNumber,
            'license_title' => $license->licenseTitle,
            'license_valid_until' => $license->expiresAt,
            'confidence' => $result->confidence->value,
            'matched_fields' => $result->fieldsMatched,
        ]));
    }

    /**
     * OperatorFlag action — log for manual review, no status change.
     *
     * Phase 14 (v1.5.0) — pays D-4-7: also dispatches an OperatorFlag
     * event so NotificationSubscriber can fire an in-app notification
     * for admins. The audit-log row stays the source of truth for the
     * review decision; the in-app notification just signals to admins
     * that a new flag needs review (so they don't have to poll the
     * sync_audit_log page).
     */
    private function actOperatorFlagged(License $license, Applicant $applicant, MatchResult $result): void
    {
        $this->auditRepo->log(
            applicantId: $applicant->id,
            nationalRequestNumber: $license->requestNumber,
            action: 'operator_flagged',
            outcome: 'operator_flag',
            confidence: $result->confidence->value,
            context: [
                'fields_matched' => $result->fieldsMatched,
                'total_score' => $result->totalScore,
                'license_title' => $license->licenseTitle,
                'national_code_matched' => $result->nationalCodeMatched,
                'reason' => $result->reason,
            ]
        );

        $this->logger->info('Golden link OperatorFlag (pending review)', [
            'applicant_id' => $applicant->id,
            'rn' => $license->requestNumber,
            'score' => $result->totalScore,
        ]);

        // §9.5 step 5 (operator-flag branch): dispatch OperatorFlag so
        // NotificationSubscriber can fire an in-app notification for
        // admins (no SMS — admins review via the admin panel, not via
        // personal channels).
        $this->eventBus->dispatch(new OperatorFlag([
            'applicant_id' => $applicant->id,
            'national_request_number' => $license->requestNumber,
            'license_title' => $license->licenseTitle,
            'confidence' => $result->confidence->value,
            'fields_matched' => $result->fieldsMatched,
            'reason' => $result->reason,
        ]));
    }

    private function outcomeForConfidence(?\App\Modules\NationalGateway\Domain\Enums\MatchConfidence $c): string
    {
        return match ($c) {
            \App\Modules\NationalGateway\Domain\Enums\MatchConfidence::AutoConfirmed => 'auto_confirmed',
            \App\Modules\NationalGateway\Domain\Enums\MatchConfidence::OperatorFlag => 'operator_flag',
            \App\Modules\NationalGateway\Domain\Enums\MatchConfidence::AutoRejected => 'auto_rejected',
            null => 'auto_rejected',
        };
    }
}
