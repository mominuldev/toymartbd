<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

/**
 * `orders.payment_status` — money owed/received (DATABASE-DESIGN §3.4), independent of
 * the workflow status: a delivered COD order is `paid` here while the cash may still sit
 * with the courier (that is Finance's receivable, not this column).
 *
 * COD starts `unpaid` and becomes `paid` when the courier collects; an online order goes
 * `unpaid → pending` when the gateway session opens. Both then follow the same rows.
 */
enum OrderPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Pending = 'pending';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Failed = 'failed';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Unpaid => [self::Pending, self::PartiallyPaid, self::Paid, self::Failed],
            self::Pending => [self::Unpaid, self::PartiallyPaid, self::Paid, self::Failed],
            self::Failed => [self::Unpaid, self::Pending, self::PartiallyPaid, self::Paid],
            self::PartiallyPaid => [self::Paid, self::PartiallyRefunded, self::Refunded],
            self::Paid => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::Refunded],
            self::Refunded => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
