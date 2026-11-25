<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Recommendations;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;

/** One item of the frequently-bought-together block: the product and the in-stock variant "Add all" puts in the cart. */
final readonly class FrequentlyBoughtTogetherItem
{
    public function __construct(public Product $product, public ProductVariant $variant) {}
}
