<?php

declare(strict_types=1);

namespace App\Domain\Risk\DTOs;

use App\Support\Money;
use App\Support\PhoneNumber;

/**
 * What the checkout risk check sees (DATABASE-DESIGN §5.2). Risk reads the phone's history
 * itself ({@see \App\Domain\Risk\Services\PhoneHistoryReader}); the caller passes only what
 * is on the order in hand.
 *
 * - `priorOrders`: an override of the history's order count, for tests; null = read it.
 *   0 = an unknown phone (rule 33b).
 * - `codAdvanceRequired`: the delivery quote says this COD order needs an advance
 *   (delivery.cod_advance); staff must arrange it by phone before dispatch.
 * - `addressLine` / `hasLocality`: for the address-quality factor; null = not scored.
 */
final readonly class RiskInput
{
    public function __construct(
        public PhoneNumber $phone,
        public Money $orderValue,
        public ?int $priorOrders = null,
        public bool $codAdvanceRequired = false,
        public ?string $addressLine = null,
        public bool $hasLocality = true,
    ) {}
}
