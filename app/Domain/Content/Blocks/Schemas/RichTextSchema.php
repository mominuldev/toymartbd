<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

use App\Domain\Catalog\Services\ProductDescription;

/**
 * Rich text: HTML in both languages, sanitised on save through the product-description
 * allowlist (p, h2, h3, strong, em, lists, blockquote, safe links, our own media only) and again
 * on the storefront.
 */
final class RichTextSchema extends BlockSchema
{
    public function rules(): array
    {
        return [
            'html' => ['required', 'string', 'max:50000'],
            'html_bn' => ['nullable', 'string', 'max:50000'],
        ];
    }

    public function defaults(): array
    {
        return ['html' => '<p>Write something for parents here.</p>', 'html_bn' => null];
    }

    public function normalize(array $content): array
    {
        return [
            'html' => ProductDescription::sanitize(self::str($content['html'] ?? null)),
            'html_bn' => ProductDescription::sanitize(self::str($content['html_bn'] ?? null)),
        ];
    }
}
