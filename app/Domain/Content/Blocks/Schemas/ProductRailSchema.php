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
 *
 * Smart Junior (Bestsellers 34:5742, New arrivals 34:5779, Gifts & combos 57:12772): the section
 * header (eyebrow chip, heading, line), a `layout` — `slider` (one row, "see all" and prev/next in
 * the header) or `grid` (every product in rows, the "see all" button under them) — an optional
 * pastel `band` behind the section, the button wording (`see_all_label`) and up to six category
 * filter `chips` (`chip_category_ids`, led by `all_label`): the server runs the same rule inside
 * each category, so the storefront never filters or ranks products itself (rule 1).
 */
final class ProductRailSchema extends BlockSchema
{
    public const int MAX_CHIPS = 6;

    public const array LAYOUTS = ['slider', 'grid'];

    public function rules(): array
    {
        return [
            ...self::headerRules(),
            'layout' => ['sometimes', Rule::in(self::LAYOUTS)],
            ...self::sliderRules(),
            'band' => self::toneRule(),
            ...self::text('see_all_label', 60, false),
            ...self::text('all_label', 30, false),
            'chip_category_ids' => ['sometimes', 'array', 'max:'.self::MAX_CHIPS],
            'chip_category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')],
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
            'eyebrow' => 'Most loved', 'eyebrow_bn' => 'সবচেয়ে প্রিয়', 'eyebrow_tone' => 'yellow',
            'title' => 'What parents buy again and again', 'title_bn' => 'অভিভাবকরা বারবার কেনেন যেগুলো',
            'sub' => 'The toys that get played with month after month.', 'sub_bn' => 'যে খেলনাগুলো মাসের পর মাস খেলা হয়।',
            'layout' => 'slider', 'band' => null, 'see_all_label' => 'See all best sellers', 'see_all_label_bn' => 'সব বেস্টসেলার দেখুন',
            'all_label' => 'All', 'all_label_bn' => 'সব', 'chip_category_ids' => [],
            'rule' => ProductRailRule::BestSellers->value,
            'category_id' => null, 'brand_id' => null, 'product_ids' => [], 'window_days' => 30,
            'limit' => 8, 'min_items' => 1, 'hide_sold_out' => true, 'show_see_all' => true,
            'slider' => self::sliderDefaults(),
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyHeader($content, $out, 'yellow');
        $out['layout'] = in_array($content['layout'] ?? null, self::LAYOUTS, true) ? $content['layout'] : 'slider';
        $out['band'] = self::tone($content['band'] ?? null, null);
        self::copyText($content, $out, 'see_all_label', 'all_label');
        $out['chip_category_ids'] = array_slice(self::ids($content['chip_category_ids'] ?? []), 0, self::MAX_CHIPS);
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
        $out['slider'] = self::slider($content['slider'] ?? null);

        return $out;
    }
}
