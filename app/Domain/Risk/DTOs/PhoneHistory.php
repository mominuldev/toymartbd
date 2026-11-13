<?php

declare(strict_types=1);

namespace App\Domain\Risk\DTOs;

use Carbon\CarbonImmutable;

/**
 * Our own order history with one phone — the evidence behind the phone-level factors and
 * the profile counts (§3.3). Built by {@see \App\Domain\Risk\Services\PhoneHistoryReader}.
 *
 *  - `totalOrders`          every order owned by or shipped to the phone, any status;
 *  - `deliveredCount`       orders that reached the customer (`delivered_at` set);
 *  - `cancelledCount`       cancelled orders, any reason;
 *  - `customerCancelCount`  cancellations the customer caused (customer_request,
 *                           customer_unreachable, fake_order, other) — what the
 *                           cancellation-rate factor counts; our own faults (out of stock,
 *                           pricing error, unserviceable, payment failed, duplicate) are not
 *                           held against the phone;
 *  - `returnedCount`        returned AFTER delivery (customer return);
 *  - `refusedCount`         came back from the courier without being delivered — refused at
 *                           the door or undeliverable (§4.4 courier return);
 *  - `failedDeliveryCount`  failed delivery attempts the courier recorded on our shipments;
 *  - `averageDeliveredValue` mean grand total of delivered orders, null when none;
 *  - `recentOrders`         orders placed within the rapid-repeat window.
 */
final readonly class PhoneHistory
{
    public function __construct(
        public string $phone,
        public ?int $customerId,
        public int $totalOrders,
        public int $deliveredCount,
        public int $cancelledCount,
        public int $customerCancelCount,
        public int $returnedCount,
        public int $refusedCount,
        public int $failedDeliveryCount,
        public ?string $averageDeliveredValue,
        public int $recentOrders,
        public ?CarbonImmutable $lastOrderAt,
    ) {}
}
