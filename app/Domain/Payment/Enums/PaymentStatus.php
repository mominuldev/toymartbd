<?php

declare(strict_types=1);

namespace App\Domain\Payment\Enums;

/**
 * `payments.status` (DATABASE-DESIGN §3.5) and its transition table (P4-PAY-01). Every
 * driver — COD included — moves a payment only along these rows:
 *
 *   pending ─▶ initiated ─▶ authorised ─▶ paid
 *      │           │            │
 *      ╰───────────┴────────────┴──▶ failed | cancelled | expired
 *   pending ─▶ paid      (COD collected on delivery; a gateway that settles at once)
 *
 * A COD payment stays `pending` until the courier collects, then goes `paid` — the same
 * row an online payment takes when its verified status comes back as paid. Terminal
 * states never reopen: a retry is a new payment row.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Initiated = 'initiated';
    case Authorised = 'authorised';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Initiated, self::Authorised, self::Paid, self::Failed, self::Cancelled, self::Expired],
            self::Initiated => [self::Authorised, self::Paid, self::Failed, self::Cancelled, self::Expired],
            self::Authorised => [self::Paid, self::Failed, self::Cancelled, self::Expired],
            self::Paid, self::Failed, self::Cancelled, self::Expired => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Initiated, self::Authorised], true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
