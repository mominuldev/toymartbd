<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

/**
 * Why an order was cancelled (P4-ORD-09). A fixed list so cancellations can be counted
 * by cause; `Other` requires a free-text note. Feeds the risk profile through
 * OrderCancelled — `FakeOrder` and `CustomerUnreachable` are the signals that matter there.
 */
enum CancellationReason: string
{
    case CustomerRequest = 'customer_request';
    case CustomerUnreachable = 'customer_unreachable';
    case FakeOrder = 'fake_order';
    case DuplicateOrder = 'duplicate_order';
    case OutOfStock = 'out_of_stock';
    case PricingError = 'pricing_error';
    case AddressUnserviceable = 'address_unserviceable';
    case PaymentFailed = 'payment_failed';
    case Other = 'other';

    public function requiresNote(): bool
    {
        return $this === self::Other;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
