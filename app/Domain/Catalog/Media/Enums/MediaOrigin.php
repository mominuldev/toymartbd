<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Enums;

/**
 * Where a media row came from (docs/api/media-library.md, decision 10). A `product`
 * file (uploaded through the product form, or backfilled from an existing product
 * image) is deleted with its last product usage, as before the library existed. A
 * `library` file is never deleted implicitly.
 */
enum MediaOrigin: string
{
    case Library = 'library';
    case Product = 'product';
}
