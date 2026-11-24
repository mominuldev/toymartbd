<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

/**
 * Flash-sale rail slot (storefront spec §B1.3): the running flash sale's products with its
 * countdown. The sale itself lives in Marketing (promotions); the storefront reads it from
 * `GET /storefront/flash-sales` and the block hides itself when no sale is running. `sub` is the
 * line under the heading (Smart Junior 34:5719, e.g. "prices drop every day at 10"); without it
 * the storefront states the sale's end time.
 */
final class FlashSaleSchema extends BlockSchema
{
    public function rules(): array
    {
        return [...self::text('title', 120), ...self::text('sub', 160, false), 'limit' => ['sometimes', 'integer', 'min:2', 'max:12'], ...self::sliderRules()];
    }

    public function defaults(): array
    {
        return ['title' => "Today's flash deals", 'title_bn' => 'আজকের ফ্ল্যাশ ডিল', 'sub' => null, 'sub_bn' => null, 'limit' => 8, 'slider' => self::sliderDefaults()];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'title', 'sub');
        $out['limit'] = self::int($content['limit'] ?? null, 8);
        $out['slider'] = self::slider($content['slider'] ?? null);

        return $out;
    }
}
