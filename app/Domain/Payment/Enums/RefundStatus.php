<?php

declare(strict_types=1);

namespace App\Domain\Payment\Enums;

/**
 * `refunds.status` (DATABASE-DESIGN §4.7):
 *
 *   requested ─▶ approved ─▶ processing ─▶ completed
 *       │            │            ╰──────▶ failed ─▶ processing (retry)
 *       ╰────────────┴──▶ rejected        approved ─▶ completed (manual payout entered at once)
 *
 * Above the configured threshold, `approved` needs refunds.approve (RefundPolicy).
 * Only `completed` counts toward the order's refunded_total.
 */
enum RefundStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Rejected = 'rejected';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Rejected],
            self::Approved => [self::Processing, self::Completed, self::Failed, self::Rejected],
            self::Processing => [self::Completed, self::Failed],
            self::Failed => [self::Processing, self::Completed],
            self::Completed, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Counts against the money still refundable (it is, or may become, paid out). */
    public function holdsRefundable(): bool
    {
        return $this !== self::Rejected;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
