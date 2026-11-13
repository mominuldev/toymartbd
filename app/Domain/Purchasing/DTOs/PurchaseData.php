<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\DTOs;

use Carbon\CarbonImmutable;

/** A PO header and its lines (create, or replace a draft). */
final readonly class PurchaseData
{
    /** @param  list<PurchaseLineData>  $lines */
    public function __construct(
        public int $supplierId,
        public array $lines,
        public CarbonImmutable $orderDate,
        public ?CarbonImmutable $expectedDate = null,
        public ?int $locationId = null,
        public ?string $supplierInvoiceNo = null,
        public ?string $note = null,
    ) {}
}
