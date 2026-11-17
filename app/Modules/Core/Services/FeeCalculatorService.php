<?php
declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\JobAndPropertyRepositoryInterface;
use App\Shared\Contracts\PaymentRepositoryInterface;
use App\Shared\Contracts\SettingRepositoryInterface;
use App\Shared\Persistence\Connection;

/**
 * FeeCalculatorService — computes registration fee based on income decile +
 * rural exemption, with fallback when decile is unknown.
 *
 * Inherited from v2's FeeCalculatorService, refactored to use
 * Connection + repository interfaces instead of `Database::getInstance()`.
 *
 * The fee is composed of:
 *   1. The fee_group_items of the applicant's decile group (sum of all
 *      active items in the group).
 *   2. A rural exemption (100% if applicant is rural + auto-eligible,
 *      configurable).
 *   3. A fallback group if the applicant's decile cannot be determined
 *      (loaded from settings.fee_fallback_group_code).
 *
 * Suspicious-rural detection (per docs/00-MASTER.md §13.5):
 *   If applicant claims rural + lives in a big city (Tehran, Mashhad,
 *   Isfahan, Shiraz, Tabriz, ...), the fee is still computed but
 *   `is_suspicious=1` is flagged for manual review.
 */
final class FeeCalculatorService
{
    /**
     * Big Iranian cities for suspicious-rural detection.
     */
    private const BIG_CITIES = [
        'تهران', 'مشهد', 'اصفهان', 'شیراز', 'تبریز', 'کرج', 'اهواز',
        'قم', 'کرمانشاه', 'ارومیه', 'رشت', 'زاهدان', 'همدان', 'کرمان',
        'اراک', 'یزد', 'اردبیل', 'بندرعباس', 'اسلام‌شهر', 'زنجان',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly ApplicantRepositoryInterface $applicants,
        private readonly JobAndPropertyRepositoryInterface $jobAndProperty,
        private readonly PaymentRepositoryInterface $payments,
        private readonly SettingRepositoryInterface $settings,
    ) {}

    /**
     * Calculate the fee for an applicant.
     *
     * @return array{amount:int, group:?array, is_rural:bool, is_suspicious:bool, suspicion_flags:array, fallback_used:bool, reason:string}
     */
    public function calculateForApplicant(int $applicantId): array
    {
        $applicant = $this->applicants->findById($applicantId);
        if ($applicant === null) {
            return [
                'amount'           => 0,
                'group'            => null,
                'is_rural'         => false,
                'is_suspicious'    => false,
                'suspicion_flags'  => [],
                'fallback_used'    => true,
                'reason'           => 'applicant_not_found',
            ];
        }

        $property = $this->jobAndProperty->findPropertyDetailByApplicantId($applicantId);
        $ruralCheck = $this->isRural($property?->fullAddress() ?? '');

        // Phase 13 (v1.4.0): pays D-2-4. income_decile is now read from
        // applicant_field_values (the wizard-populated EAV) first, with
        // a transparent fallback to applicants.income_decile for older
        // records that pre-date the wizard port. The fallback keeps
        // existing tests passing (those insert into applicants
        // directly) and keeps the fee calculation backward-compatible.
        $decile = $this->getIncomeDecile($applicantId);

        $group = null;
        $amount = 0;
        $fallbackUsed = false;

        if ($decile !== null) {
            $group = $this->connection->one(
                'SELECT * FROM fee_groups WHERE ? BETWEEN decile_min AND decile_max AND is_active = 1 LIMIT 1',
                [$decile]
            );
        }

        if ($group === null) {
            // Fallback: use the configured fallback group code.
            $fallbackCode = $this->settings->get('fee_fallback_group_code', 'د');
            $group = $this->connection->one(
                'SELECT * FROM fee_groups WHERE code = ? AND is_active = 1 LIMIT 1',
                [$fallbackCode]
            );
            $fallbackUsed = true;
        }

        if ($group !== null) {
            $itemsSum = (int)$this->connection->scalar(
                'SELECT COALESCE(SUM(amount), 0) FROM fee_group_items WHERE fee_group_id = ?',
                [(int)$group['id']]
            );
            $amount = $itemsSum;
        }

        $suspicionFlags = [];

        // Rural exemption
        $ruralAutoEligible = $this->settings->get('fee_rural_auto_eligible', '1') === '1';
        $isRural = $ruralCheck['rural'];
        if ($isRural && $ruralAutoEligible) {
            $amount = 0;
            $suspicionFlags[] = 'rural_exempt';
        }

        // Suspicious rural (claims rural + lives in a big city)
        $isSuspicious = false;
        if ($isRural && $ruralCheck['suspicious']) {
            $isSuspicious = true;
            $suspicionFlags[] = 'suspicious_rural';
        }

        return [
            'amount'           => $amount,
            'group'            => $group,
            'is_rural'         => $isRural,
            'is_suspicious'    => $isSuspicious,
            'suspicion_flags'  => $suspicionFlags,
            'fallback_used'    => $fallbackUsed,
            'reason'           => $fallbackUsed ? 'fallback_group' : 'decile_match',
        ];
    }

    /**
     * Determine whether the property is rural and whether the claim is suspicious.
     *
     * @return array{rural:bool, suspicious:bool}
     */
    public function isRural(?string $fullAddress): array
    {
        if ($fullAddress === null || $fullAddress === '') {
            return ['rural' => false, 'suspicious' => false];
        }
        // Rural if any village/rosta indicator is present.
        $ruralKeywords = ['روستا', 'دهستان', 'بخش', 'روستای'];
        $rural = false;
        foreach ($ruralKeywords as $kw) {
            if (str_contains($fullAddress, $kw)) {
                $rural = true;
                break;
            }
        }
        // Suspicious if a big-city name appears in the address (claims rural
        // + lives in a metropolitan area).
        $suspicious = false;
        if ($rural) {
            foreach (self::BIG_CITIES as $city) {
                if (str_contains($fullAddress, $city)) {
                    $suspicious = true;
                    break;
                }
            }
        }
        return ['rural' => $rural, 'suspicious' => $suspicious];
    }

    /**
     * Resolve the income decile for an applicant.
     *
     * Phase 13 (v1.4.0): pays D-2-4. Was previously a direct
     * `SELECT income_decile FROM applicants WHERE id = ?` — which read
     * from the legacy column populated by the v2-style wizard. In v3,
     * the wizard stores all per-applicant free-form data in the EAV
     * table `applicant_field_values` (joined to `form_fields` by
     * `field_key='income_decile'`). This helper tries the EAV first,
     * then falls back to the legacy `applicants.income_decile` column
     * for records that pre-date the wizard port (so existing tests and
     * migration-from-v2 data keep working).
     *
     * @return int|null The decile (1-10) or null when no value is set.
     */
    private function getIncomeDecile(int $applicantId): ?int
    {
        // 1. Try applicant_field_values (EAV) — joined to form_fields
        //    by field_key='income_decile'. This is the wizard-populated
        //    path (the canonical v3 source).
        $row = $this->connection->one(
            'SELECT afv.field_value
               FROM applicant_field_values afv
               JOIN form_fields ff ON ff.id = afv.field_id
              WHERE afv.applicant_id = ?
                AND ff.field_key = ?
              LIMIT 1',
            [$applicantId, 'income_decile']
        );
        if ($row !== null && $row['field_value'] !== null) {
            $value = (int)$row['field_value'];
            // Sanity: decile is 1..10. Anything else is treated as
            // "no usable value" (so the fallback path runs).
            if ($value >= 1 && $value <= 10) {
                return $value;
            }
        }

        // 2. Fall back to the legacy column on applicants (used by v2
        //    imports and existing tests). NULL or 0 = not set.
        $legacy = $this->connection->one(
            'SELECT income_decile FROM applicants WHERE id = ?',
            [$applicantId]
        );
        if ($legacy === null) {
            return null;
        }
        $value = $legacy['income_decile'] === null ? null : (int)$legacy['income_decile'];
        if ($value === null || $value < 1 || $value > 10) {
            return null;
        }
        return $value;
    }

    /**
     * Compute the final amount for a payment, applying the minimum-10%
     * floor (cannot discount below 10% of the original amount).
     */
    public function calculateFinalAmount(int $paymentId): int
    {
        $payment = $this->payments->findById($paymentId);
        if ($payment === null) {
            return 0;
        }
        $original = $payment->originalAmount ?? $payment->amount;
        if ($original <= 0) {
            return $payment->amount;
        }
        $minPercent = (int)$this->settings->get('fee_min_amount_percent', '10');
        $minFloor = (int)($original * $minPercent / 100);
        return max($payment->amount, $minFloor);
    }

    /**
     * Whether a discount on a payment requires a written reason.
     */
    public function discountRequiresReason(int $paymentId): bool
    {
        $payment = $this->payments->findById($paymentId);
        if ($payment === null || $payment->originalAmount === null) {
            return false;
        }
        $maxWithoutReason = (int)$this->settings->get('fee_max_discount_without_reason', '10');
        $discountPercent = $payment->originalAmount > 0
            ? (1 - $payment->amount / $payment->originalAmount) * 100
            : 0;
        return $discountPercent > $maxWithoutReason;
    }

    /**
     * Whether a discount exceeds the with-reason threshold (requires admin approval).
     */
    public function isDiscountExcessive(int $paymentId): bool
    {
        $payment = $this->payments->findById($paymentId);
        if ($payment === null || $payment->originalAmount === null) {
            return false;
        }
        $maxWithReason = (int)$this->settings->get('fee_max_discount_with_reason', '50');
        $discountPercent = $payment->originalAmount > 0
            ? (1 - $payment->amount / $payment->originalAmount) * 100
            : 0;
        return $discountPercent > $maxWithReason;
    }
}
