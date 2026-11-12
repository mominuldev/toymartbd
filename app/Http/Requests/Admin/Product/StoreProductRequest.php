<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Domain\Catalog\Models\Product;

/**
 * `POST /products` — needs catalog.create. Setting the initial prices is part of
 * creating; a `cost_price` anywhere in the document needs catalog.view_cost.
 * When `slug` is omitted it is generated from `name` (or `name_bn`).
 * `variants[i].opening_stock` > 0 also needs inventory.adjust.
 */
final class StoreProductRequest extends ProductDocumentRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    protected function product(): ?Product
    {
        return null;
    }

    /**
     * `variants[i].opening_stock`, aligned with the variants of toData(); null where
     * absent. A quantity above zero needs inventory.adjust (checked by the controller's
     * caller through {@see self::setsOpeningStock()}).
     *
     * @return list<int|null>
     */
    public function openingStock(): array
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();

        return array_values(array_map(
            static fn (array $row): ?int => isset($row['opening_stock']) ? (int) $row['opening_stock'] : null,
            (array) ($v['variants'] ?? []),
        ));
    }

    public function setsOpeningStock(): bool
    {
        return array_filter($this->openingStock(), static fn (?int $q): bool => $q !== null && $q > 0) !== [];
    }
}
