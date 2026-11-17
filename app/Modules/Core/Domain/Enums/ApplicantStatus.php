<?php
declare(strict_types=1);

namespace App\Modules\Core\Domain\Enums;

/**
 * The 18 applicant workflow statuses (Single Source of Truth).
 *
 * Inherited from Mojavez-v2, with the new v3 method `isAutoSyncable()` to
 * support the golden-link orchestrator (Phase 4).
 *
 * Storage: VARCHAR(32) with the Persian string value (NOT MySQL ENUM —
 * CODING_CONVENTIONS §5.2 avoids the ALTER ENUM cost).
 *
 * Source of truth for statuses: config/statuses.php (mirror of this enum).
 */
enum ApplicantStatus: string
{
    // 14 shared statuses — single-direction review progression.
    case Draft = 'پیش‌نویس';
    case InfoComplete = 'تکمیل اطلاعات';
    case AddressVerification = 'احراز نشانی';
    case FieldExpertReview = 'بررسی کارشناسی رشته';
    case IssueRequest = 'ثبت درخواست صدور';        // ← start of 14-day auto-cancel window
    case DocUploadToOrg = 'بارگذاری مدارک به سازمان';
    case DocCorrectionRequest = 'درخواست اصلاح مدارک';
    case InitialApproval = 'تأیید اولیه';
    case FinalApproval = 'تأیید نهایی';
    case LicenseIssuance = 'صدور مجوز';              // intermediate (golden-link target)
    case LicenseDelivery = 'تحویل مجوز';             // terminal (handicraft)
    case ViewAndPrint = 'مشاهده و چاپ';
    case Rejected = 'رد درخواست';                    // terminal
    case Cancelled = 'لغو';                          // terminal

    // 4 household-business-only statuses (service_type_id = 2).
    // Source: smkh.mcls.gov.ir (NOT qr.mojavez.ir — see docs/00-MASTER.md §20.8).
    case AddressSabtAhvalMatch = 'تطبیق آدرس با ثبت احوال';
    case LicenseImageReceipt = 'دریافت تصویر مجوز';
    case InitialApprovalSMKH = 'تأیید اولیه در سامانه مشاغل خانگی';
    case FinalApprovalTrackingCode = 'تأیید نهایی دریافت کد رهگیری'; // terminal

    /**
     * Returns the workflow order (matches config/statuses.php 'order').
     * Used to forbid backward transitions (except for Cancelled/Rejected).
     */
    public function order(): int
    {
        return match ($this) {
            self::Draft                       => 1,
            self::InfoComplete                 => 2,
            self::AddressVerification          => 3,
            self::FieldExpertReview            => 4,
            self::IssueRequest                 => 5,
            self::DocUploadToOrg               => 6,
            self::DocCorrectionRequest          => 7,
            self::InitialApproval              => 8,
            self::FinalApproval                => 9,
            self::LicenseIssuance              => 10,
            self::LicenseDelivery              => 11,
            self::ViewAndPrint                 => 12,
            self::Rejected                     => 98,
            self::Cancelled                    => 99,
            self::AddressSabtAhvalMatch        => 15,
            self::LicenseImageReceipt          => 25,
            self::InitialApprovalSMKH          => 35,
            self::FinalApprovalTrackingCode    => 36,
        };
    }

    /**
     * Terminal statuses close the case.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Rejected,
            self::Cancelled,
            self::LicenseDelivery,
            self::FinalApprovalTrackingCode,
        ], true);
    }

    /**
     * Pre-issuance statuses — subject to 14-day auto-cancel
     * from "ثبت درخواست صدور".
     */
    public function isPreIssue(): bool
    {
        return in_array($this, [
            self::Draft,
            self::InfoComplete,
            self::AddressVerification,
            self::FieldExpertReview,
            self::IssueRequest,
            self::DocUploadToOrg,
            self::DocCorrectionRequest,
            self::InitialApproval,
            self::FinalApproval,
            self::AddressSabtAhvalMatch,
        ], true);
    }

    /**
     * Manual statuses — admins may set them explicitly.
     * ViewAndPrint is set automatically by user-side actions, never by admins.
     */
    public function isManual(): bool
    {
        return $this !== self::ViewAndPrint;
    }

    /**
     * The new v3 'auto_sync' role may transition a pre-issue applicant to
     * LicenseIssuance only when MatchConfidence = AutoConfirmed.
     *
     * Source: docs/00-MASTER.md §11.2, §25 ACL.
     */
    public function isAutoSyncTransitionableFrom(): bool
    {
        return in_array($this, [
            self::IssueRequest,
            self::DocUploadToOrg,
            self::InitialApproval,
            self::FinalApproval,
        ], true);
    }

    /**
     * Service type IDs that may use this status. Empty = all services.
     *
     * @return array<int,int>
     */
    public function services(): array
    {
        return match ($this) {
            self::AddressSabtAhvalMatch,
            self::LicenseImageReceipt,
            self::InitialApprovalSMKH,
            self::FinalApprovalTrackingCode => [2],
            default => [],  // shared across all service types
        };
    }

    /**
     * Persian title (the enum value itself).
     */
    public function titleFa(): string
    {
        return $this->value;
    }

    /**
     * Try to construct from a Persian slug.
     */
    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $slug) {
                return $case;
            }
        }
        return null;
    }

    /**
     * The bootstrap CSS colour class for the status badge.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Draft                       => 'secondary',
            self::InfoComplete                => 'info',
            self::AddressVerification,
            self::FieldExpertReview,
            self::AddressSabtAhvalMatch        => 'primary',
            self::IssueRequest                => 'primary',
            self::DocUploadToOrg,
            self::DocCorrectionRequest         => 'warning',
            self::InitialApproval,
            self::FinalApproval,
            self::LicenseIssuance,
            self::LicenseDelivery,
            self::LicenseImageReceipt,
            self::InitialApprovalSMKH,
            self::FinalApprovalTrackingCode    => 'success',
            self::ViewAndPrint                => 'dark',
            self::Rejected,
            self::Cancelled                    => 'danger',
        };
    }
}
