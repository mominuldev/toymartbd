<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

use Illuminate\Validation\Rule;

/** Brand strip: the picked brands in order, or (none picked) active brands with a logo, by name. */
final class BrandStripSchema extends BlockSchema
{
    public function rules(): array
    {
        return [
            ...self::text('title', 120),
            'brand_ids' => ['nullable', 'array', 'max:24'],
            'brand_ids.*' => ['integer', 'distinct', Rule::exists('brands', 'id')],
            'limit' => ['sometimes', 'integer', 'min:3', 'max:24'],
        ];
    }

    public function defaults(): array
    {
        return ['title' => 'Brands parents trust', 'title_bn' => 'অভিভাবকদের আস্থার ব্র্যান্ড', 'brand_ids' => [], 'limit' => 12];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'title');
        $out['brand_ids'] = self::ids($content['brand_ids'] ?? []);
        $out['limit'] = self::int($content['limit'] ?? null, 12);

        return $out;
    }
}
