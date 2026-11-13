<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

use App\Domain\Content\Blocks\Enums\ProductRailRule;
use Illuminate\Validation\Rule;

/**
 * Product rail by rule (Figma B · 26 settings panel: "Automatic · best sellers, 30 days",
 * "8 products · 4 per row", "Hide sold-out products"). Products are always listable (active,
 * published, not archived, an active variant). With `hide_sold_out` sold-out products are left
 * out; a rail left with fewer than `min_items` products hides itself rather than degrading
 * (rule 25c). Never placed on checkout (rule 25b): the homepage is the only placement.
 */
final class ProductRailSchema extends BlockSchema
{
    public function rules(): array
    {
        return [
            ...self::text('title', 120),
            'rule' => ['required', Rule::enum(ProductRailRule::class)],
            'category_id' => ['nullable', 'integer', 'required_if:rule,category', Rule::exists('categories', 'id')],
            'brand_id' => ['nullable', 'integer', 'required_if:rule,brand', Rule::exists('brands', 'id')],
            'product_ids' => ['nullable', 'array', 'max:24', 'required_if:rule,manual'],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')],
            'window_days' => ['sometimes', 'integer', Rule::in([7, 30, 90])],
            'limit' => ['sometimes', 'integer', 'min:2', 'max:12'],
            'min_items' => ['sometimes', 'integer', 'min:1', 'max:12', 'lte:limit'],
            'hide_sold_out' => ['sometimes', 'boolean'],
            'show_see_all' => ['sometimes', 'boolean'],
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'Best sellers', 'title_bn' => 'সবচেয়ে বেশি বিক্রি', 'rule' => ProductRailRule::BestSellers->value,
            'category_id' => null, 'brand_id' => null, 'product_ids' => [], 'window_days' => 30,
            'limit' => 8, 'min_items' => 1, 'hide_sold_out' => true, 'show_see_all' => true,
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'title');
        $rule = ProductRailRule::tryFrom(is_string($content['rule'] ?? null) ? $content['rule'] : '') ?? ProductRailRule::BestSellers;
        $out['rule'] = $rule->value;
        $out['category_id'] = $rule === ProductRailRule::Category ? self::int($content['category_id'] ?? null, 0) : null;
        $out['brand_id'] = $rule === ProductRailRule::Brand ? self::int($content['brand_id'] ?? null, 0) : null;
        $out['product_ids'] = $rule === ProductRailRule::Manual ? self::ids($content['product_ids'] ?? []) : [];
        $out['window_days'] = self::int($content['window_days'] ?? null, 30);
        $out['limit'] = self::int($content['limit'] ?? null, 8);
        $out['min_items'] = min(self::int($content['min_items'] ?? null, 1), $out['limit']);
        $out['hide_sold_out'] = self::bool($content['hide_sold_out'] ?? null, true);
        $out['show_see_all'] = self::bool($content['show_see_all'] ?? null, true);

        return $out;
    }
}
