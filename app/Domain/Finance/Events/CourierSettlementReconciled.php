<?php

declare(strict_types=1);

namespace App\Domain\Finance\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A person confirmed a courier settlement (§4.5); its bookable lines are marked. After commit
 * the Settlement ledger listener writes `cod_received` (gross) and any `courier_charge` /
 * `courier_cod_fee` not already booked. Carries the id only.
 */
final readonly class CourierSettlementReconciled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $settlementId) {}
}
