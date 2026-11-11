<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * `products.status`. `draft` is invisible to the storefront; `active` is live once
 * `published_at` has passed; `archived` is retired but kept (orders reference it).
 */
enum ProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
