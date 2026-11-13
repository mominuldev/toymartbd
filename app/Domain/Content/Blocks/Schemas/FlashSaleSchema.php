<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

/**
 * Flash-sale rail slot (storefront spec §B1.3): the running flash sale's products with its
 * countdown. The sale itself lives in Marketing (promotions); the storefront reads it from
 * `GET /storefront/flash-sales` and the block hides itself when no sale is running.
 */
final class FlashSaleSchema extends BlockSchema
{
    public function rules(): array
    {
        return [...self::text('title', 120), 'limit' => ['sometimes', 'integer', 'min:2', 'max:12']];
    }

    public function defaults(): array
    {
        return ['title' => 'Flash sale', 'title_bn' => 'ফ্ল্যাশ সেল', 'limit' => 8];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'title');
        $out['limit'] = self::int($content['limit'] ?? null, 8);

        return $out;
    }
}
