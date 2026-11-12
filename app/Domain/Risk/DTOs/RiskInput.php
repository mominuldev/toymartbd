<?php

declare(strict_types=1);

namespace App\Domain\Risk\DTOs;

use App\Support\Money;
use App\Support\PhoneNumber;

/**
 * What the checkout risk check sees (DATABASE-DESIGN §5.2). Risk does not read orders
 * itself: the caller (Ordering's PlaceOrder) counts the phone's earlier orders.
 *
 * - `priorOrders`: earlier orders owned by or shipped to this phone, any status.
 *   0 = an unknown phone (rule 33b).
 * - `codAdvanceRequired`: the delivery quote says this COD order needs an advance
 *   (delivery.cod_advance); staff must arrange it by phone before dispatch.
 */
final readonly class RiskInput
{
    public function __construct(
        public PhoneNumber $phone,
        public Money $orderValue,
        public int $priorOrders,
        public bool $codAdvanceRequired = false,
    ) {}
}
