<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

use App\Support\Money;

/**
 * What Fulfilment needs to know about an order to ship it, read through the
 * {@see \App\Domain\Fulfilment\Contracts\ShippableOrders} seam (Ordering implements it;
 * Fulfilment never reads Ordering's models). `phone` is E.164 — never logged (rule 20).
 */
final readonly class ShippableOrder
{
    public function __construct(
        public int $id,
        public string $number,
        public string $status,
        /** confirmed, processing or ready_to_ship (§4.2 / the order state machine). */
        public bool $canShip,
        public string $recipientName,
        public string $phone,
        public string $addressLine,
        public ?string $landmark,
        public ?int $districtId,
        public ?int $upazilaId,
        /** What the courier collects: the order's due total (0 for a prepaid order). */
        public Money $codAmount,
        public Money $declaredValue,
        public int $weightGrams,
        public int $itemCount,
        public string $itemDescription,
    ) {}
}
