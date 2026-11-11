<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * `categories.status`. A category is archived rather than deleted, because
 * products reference it through `primary_category_id` and `category_product`.
 */
enum CategoryStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
