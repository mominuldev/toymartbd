<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Enums;

/** How a product rail chooses its products. Every rule lists only listable products (ProductListing). */
enum ProductRailRule: string
{
    /** Newest published first. */
    case New = 'new';
    /** Units sold over the last `window_days` (not cancelled), featured first as the tiebreak. */
    case BestSellers = 'best_sellers';
    /** A category and its descendants, featured order. */
    case Category = 'category';
    /** One brand, featured order. */
    case Brand = 'brand';
    /** The products the editor picked, in the editor's order. */
    case Manual = 'manual';
}
