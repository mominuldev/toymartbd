<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

use Illuminate\Validation\Rule;

/**
 * Category tiles (Figma Home "Shop by category"). `source` top_level = the first `limit` active
 * top-level categories in menu order; manual = the picked `category_ids`, in that order (an
 * archived pick is skipped, never shown).
 */
final class CategoryTilesSchema extends BlockSchema
{
    public function rules(): array
    {
        return [
            ...self::text('title', 120),
            'source' => ['required', Rule::in(['top_level', 'manual'])],
            'category_ids' => ['nullable', 'array', 'max:12', 'required_if:source,manual'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')],
            'limit' => ['sometimes', 'integer', 'min:2', 'max:12'],
            'show_see_all' => ['sometimes', 'boolean'],
        ];
    }

    public function defaults(): array
    {
        return ['title' => 'Shop by category', 'title_bn' => 'ক্যাটাগরি', 'source' => 'top_level', 'category_ids' => [], 'limit' => 8, 'show_see_all' => true];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'title');
        $out['source'] = ($content['source'] ?? null) === 'manual' ? 'manual' : 'top_level';
        $out['category_ids'] = $out['source'] === 'manual' ? self::ids($content['category_ids'] ?? []) : [];
        $out['limit'] = self::int($content['limit'] ?? null, 8);
        $out['show_see_all'] = self::bool($content['show_see_all'] ?? null, true);

        return $out;
    }
}
