<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Catalog\Models\Category;
use App\Domain\Marketing\DTOs\PricingLine;
use App\Domain\Marketing\Enums\ProductScopeType;

/**
 * Which lines a coupon or promotion applies to: an inclusion (all, or listed products,
 * categories or brands) minus exclusions. Category ids — included or excluded — cover
 * the whole subtree, so "Vehicles" includes "Vehicles › Cars".
 */
final readonly class ProductScope
{
    /**
     * @param  list<int>  $ids  included ids (categories already expanded to subtrees)
     * @param  list<int>  $excludeProductIds
     * @param  list<int>  $excludeCategoryIds  expanded to subtrees
     * @param  list<int>  $excludeBrandIds
     */
    private function __construct(
        public ProductScopeType $type,
        public array $ids,
        public array $excludeProductIds,
        public array $excludeCategoryIds,
        public array $excludeBrandIds,
    ) {}

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>|null  $excludes  `{product_ids, category_ids, brand_ids}`
     */
    public static function make(ProductScopeType $type, array $ids, ?array $excludes): self
    {
        $excludes ??= [];

        return new self(
            $type,
            $type === ProductScopeType::Categories ? self::subtrees($ids) : self::ints($ids),
            self::ints($excludes['product_ids'] ?? []),
            self::subtrees(self::ints($excludes['category_ids'] ?? [])),
            self::ints($excludes['brand_ids'] ?? []),
        );
    }

    /** @param  array<string, mixed>  $scope  promotion `rules.scope` */
    public static function fromRules(array $scope): self
    {
        $type = ProductScopeType::tryFrom(is_string($scope['applies_to'] ?? null) ? $scope['applies_to'] : 'all') ?? ProductScopeType::All;
        $excludes = [
            'product_ids' => $scope['exclude_product_ids'] ?? [],
            'category_ids' => $scope['exclude_category_ids'] ?? [],
            'brand_ids' => $scope['exclude_brand_ids'] ?? [],
        ];

        return self::make($type, self::ints($scope['ids'] ?? []), $excludes);
    }

    public function matches(PricingLine $line): bool
    {
        if (in_array($line->productId, $this->excludeProductIds, true)
            || ($line->brandId !== null && in_array($line->brandId, $this->excludeBrandIds, true))
            || array_intersect($line->categoryIds, $this->excludeCategoryIds) !== []) {
            return false;
        }

        return match ($this->type) {
            ProductScopeType::All => true,
            ProductScopeType::Products => in_array($line->productId, $this->ids, true),
            ProductScopeType::Brands => $line->brandId !== null && in_array($line->brandId, $this->ids, true),
            ProductScopeType::Categories => array_intersect($line->categoryIds, $this->ids) !== [],
        };
    }

    /**
     * @param  list<PricingLine>  $lines
     * @return list<PricingLine>
     */
    public function filter(array $lines): array
    {
        return array_values(array_filter($lines, $this->matches(...)));
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function subtrees(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $roots = Category::query()->whereIn('id', $ids)->get(['id', '_lft', '_rgt']);
        if ($roots->isEmpty()) {
            return [];
        }

        $query = Category::query()->select('id');
        foreach ($roots as $root) {
            $query->orWhereBetween('_lft', [$root->_lft, $root->_rgt]);
        }

        return array_values(array_map('intval', $query->pluck('id')->all()));
    }

    /** @return list<int> */
    private static function ints(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('intval', array_filter($value, 'is_numeric'))) : [];
    }
}
