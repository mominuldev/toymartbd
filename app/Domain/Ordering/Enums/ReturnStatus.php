<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

/**
 * `order_returns.status` — the goods side of a return (DATABASE-DESIGN §4.7):
 *
 *   requested ─▶ received ─▶ inspected ─▶ closed   (refund paid, rejected, or nothing to refund)
 *        ╰─────────────────────▶ inspected      (inspected on arrival, no separate receipt)
 *        ╰──────────╰──▶ rejected                   (never came back / outside policy)
 *
 * Stock and the order's status move at inspection — the moment we know what came back
 * and in what condition. Money moves through the refund the inspection creates.
 */
enum ReturnStatus: string
{
    case Requested = 'requested';
    case Received = 'received';
    case Inspected = 'inspected';
    case Closed = 'closed';
    case Rejected = 'rejected';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Received, self::Inspected, self::Rejected],
            self::Received => [self::Inspected, self::Rejected],
            self::Inspected => [self::Closed],
            self::Closed, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Still holds its quantities against the order lines (not yet inspected or rejected). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Received], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
