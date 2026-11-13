<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Services;

use App\Domain\Catalog\Services\ProductDescription;

/**
 * Blog rich text uses the same allowlist as the product description (the admin reuses its
 * TipTap editor): p, h2, h3, strong, em, lists, blockquote, br, safe links, and images from
 * our own media only. Everything is sanitised on write AND on read, so a row written around
 * the editor (seeder, import) can never reach a page unfiltered.
 */
final class BlogHtml
{
    public static function sanitize(?string $input): ?string
    {
        return ProductDescription::sanitize($input);
    }

    public static function plainText(?string $stored): string
    {
        return ProductDescription::plainText($stored);
    }
}
