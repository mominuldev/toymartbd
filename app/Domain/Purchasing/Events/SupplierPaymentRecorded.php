<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money was paid to a supplier. Held until the payment commits; Finance writes the
 * `supplier_payment` ledger entry from it (cash out, not revenue — rule 10). Carries
 * everything the listener needs so Finance never reads Purchasing's tables.
 */
final readonly class SupplierPaymentRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $paymentId,
        public int $supplierId,
        public ?int $purchaseId,
        /** Decimal string, 2 dp, positive. */
        public string $amount,
        /** Dhaka calendar date, Y-m-d. */
        public string $paidOn,
        public string $method,
        public string $supplierName,
    ) {}
}
