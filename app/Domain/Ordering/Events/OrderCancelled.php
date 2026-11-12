<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

/**
 * Order transition → cancelled (P4-ORD-09). The stock reservation is already released
 * inside the CancelOrder transaction; this event is the hook for what other domains own:
 *
 *  - **Marketing** reverses the coupon usage (`coupon_usages` row for this order,
 *    `coupons.used_count--`) — listen for this event; `couponCode` says whether there is one.
 *  - **Risk** bumps `customer_risk_profiles.cancelled_count` for the order's phone
 *    (load the order by id; the phone is not carried here, rule 20). `reason` lets it
 *    weigh `fake_order` / `customer_unreachable` differently from `customer_request`.
 *  - **Customer** refreshes the aggregate projection (wired today).
 *
 * `paidTotal` > 0 means money was taken online and a refund (Phase 5) is owed.
 */
final readonly class OrderCancelled extends OrderStatusChanged
{
    public function __construct(
        int $orderId,
        int $customerId,
        string $from,
        public string $reason,
        public ?string $note,
        public ?string $couponCode,
        public string $paidTotal,
        ?int $userId = null,
    ) {
        parent::__construct($orderId, $customerId, $from, 'cancelled', $userId);
    }
}
