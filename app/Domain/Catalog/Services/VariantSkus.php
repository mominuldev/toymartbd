<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;

/**
 * Generated SKUs (P4-CAT-03). Any row may override its SKU; this is only the default.
 *
 *   simple product      TM-{product id, zero-padded to 5}            TM-00042
 *   variable product    TM-{product id}-{value code}-{value code}…   TM-00042-RED-L
 *
 * A value code is the attribute value's slug, uppercased, with everything but A–Z
 * and 0–9 removed, cut to 8 characters ("3-4-years" → "34YEARS"). Codes follow the
 * attribute order of the matrix. The product id — not the name — anchors the SKU,
 * so renaming a product never changes its SKUs, and existing variants keep theirs
 * on every update. A collision (an overridden SKU elsewhere) gets "-2", "-3" ….
 */
final class VariantSkus
{
    private const string PREFIX = 'TM';

    private const int CODE_MAX = 8;

    /** @var array<string, true> SKUs claimed by explicit rows in the current write. */
    private array $reserved = [];

    /** @param  list<string>  $skus */
    public function reserve(array $skus): void
    {
        $this->reserved = array_fill_keys(array_map(strtoupper(...), $skus), true);
    }

    /**
     * @param  list<ProductAttributeValue>  $values  in matrix attribute order; empty for a simple product
     */
    public function generate(int $productId, array $values = []): string
    {
        $parts = [self::PREFIX, str_pad((string) $productId, 5, '0', STR_PAD_LEFT)];

        foreach ($values as $value) {
            $code = substr((string) preg_replace('/[^A-Z0-9]/', '', strtoupper($value->slug)), 0, self::CODE_MAX);
            $parts[] = $code !== '' ? $code : (string) $value->id;
        }

        $base = substr(implode('-', $parts), 0, ProductVariant::SKU_MAX - 4);
        $candidate = $base;

        for ($n = 2; $this->taken($candidate); $n++) {
            $candidate = $base.'-'.$n;
        }

        $this->reserved[strtoupper($candidate)] = true;

        return $candidate;
    }

    private function taken(string $sku): bool
    {
        return isset($this->reserved[strtoupper($sku)]) || ProductVariant::query()->where('sku', $sku)->exists();
    }
}
