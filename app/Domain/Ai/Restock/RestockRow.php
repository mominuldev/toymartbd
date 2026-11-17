<?php

declare(strict_types=1);

namespace App\Domain\Ai\Restock;

use App\Support\Money;

/** One line of the restock forecast ({@see RestockForecast} documents every figure). */
final readonly class RestockRow
{
    public function __construct(
        public int $variantId,
        public int $productId,
        public string $productName,
        public ?string $productNameBn,
        public ?string $variantName,
        public ?string $variantNameBn,
        public string $sku,
        public int $inStock,
        public int $unitsSold,
        public string $sellsPerDay,
        public int $runsOutInDays,
        public int $leadTimeDays,
        public bool $leadTimeEstimated,
        public int $openPurchaseUnits,
        public int $suggestedOrder,
        public int $unitsShort,
        /** high | medium | low */
        public string $confidence,
        public Money $price,
        public Money $salesAtRisk,
        public ?int $supplierId,
        public ?string $supplierName,
        /** 4-dp unit cost of the latest placed PO line; null when never bought. */
        public ?string $lastUnitCost,
        public ?Money $suggestedOrderValue,
    ) {}

    /** "Kids Tricycle · Red" — the product and, when it has one, the variant; Bangla names when asked and present. */
    public function label(string $language = 'en'): string
    {
        $bn = $language === 'bn';
        $product = $bn ? ($this->productNameBn ?? $this->productName) : $this->productName;
        $variant = $bn ? ($this->variantNameBn ?? $this->variantName) : $this->variantName;

        return $variant !== null && $variant !== '' ? $product.' · '.$variant : $product;
    }
}
