<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Enums;

/** What a coupon or promotion applies to (`coupons.applies_to`, promotion `rules.scope.applies_to`). */
enum ProductScopeType: string
{
    case All = 'all';
    case Products = 'products';
    case Categories = 'categories';
    case Brands = 'brands';
}
