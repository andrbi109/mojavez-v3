<?php
declare(strict_types=1);

namespace App\Modules\Queue\Domain\Enums;

/**
 * Job status in the queue (Phase 4 will materialize the jobs table).
 *
 * Inherited from gateway's JobStatus. Phase 4 will wire this with
 * JobQueue + Worker.
 */
enum JobStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Retrying = 'retrying';

    public function titleFa(): string
    {
        return match ($this) {
            self::Queued      => 'در صف',
            self::Processing  => 'در حال اجرا',
            self::Completed   => 'تکمیل‌شده',
            self::Failed      => 'ناموفق',
            self::Retrying    => 'در حال تلاش مجدد',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Queued      => 'secondary',
            self::Processing  => 'primary',
            self::Completed   => 'success',
            self::Failed      => 'danger',
            self::Retrying    => 'warning',
        };
    }

    /**
     * Whether the job is in a terminal state (no more retries).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }
}
