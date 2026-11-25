<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Recommendations;

use App\Domain\Catalog\Models\Product;
use App\Support\Money;

/**
 * One "Upgrade to" card (PRD X-01): the dearer alternative and how much more it costs —
 * the target's lowest active variant price minus the source's, always > 0 (rule 1: the
 * storefront shows it, never computes it).
 */
final readonly class UpsellRecommendation
{
    public function __construct(public Product $product, public Money $priceDelta) {}
}
