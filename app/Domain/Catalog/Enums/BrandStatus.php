<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * `brands.status`. A brand is archived rather than deleted, because products
 * reference it; the API exposes this as `is_active`.
 */
enum BrandStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public static function fromActive(bool $active): self
    {
        return $active ? self::Active : self::Archived;
    }
}
