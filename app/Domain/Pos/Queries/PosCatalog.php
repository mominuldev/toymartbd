<?php

declare(strict_types=1);

namespace App\Domain\Pos\Queries;

use App\Domain\Cart\Services\SellableVariants;
use App\Domain\Catalog\Enums\CategoryStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The register's product grid (Figma B · 33): sellable variants of products sold at the
 * counter (`products.channel_pos`), each with what is available AT THE SESSION'S BRANCH
 * ("7 here"). Read-only. Filters: a top-level category tab (the category and everything
 * under it), a search term (name EN/BN, SKU) or an exact barcode — a keyboard-wedge scanner
 * types the code and Enter, so a barcode is an exact match, never a LIKE.
 */
final readonly class PosCatalog
{
    public const int LIMIT = 60;

    public function __construct(private SellableVariants $sellable) {}

    /** @return Collection<int, Category> the tabs: active top-level categories in menu order */
    public function tabs(): Collection
    {
        return Category::query()
            ->whereNull('parent_id')
            ->where('status', CategoryStatus::Active->value)
            ->orderBy('position')->orderBy('id')
            ->get(['id', 'name', 'name_bn', 'position']);
    }

    /**
     * @return list<array{variant: ProductVariant, available: int}>
     */
    public function variants(int $locationId, ?int $categoryId = null, ?string $term = null, ?string $barcode = null): array
    {
        $query = ProductVariant::query()
            ->with(['product.images'])
            ->whereHas('product', static fn (Builder $p) => $p->where('channel_pos', true));

        if ($barcode !== null && trim($barcode) !== '') {
            $query->where('barcode', trim($barcode));
        } elseif ($term !== null && trim($term) !== '') {
            $like = '%'.addcslashes(trim($term), '\\%_').'%';
            $exact = trim($term);
            $query->where(static function (Builder $q) use ($like, $exact): void {
                $q->where('sku', 'like', $like)
                    ->orWhere('barcode', $exact)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('name_bn', 'like', $like)
                    ->orWhereHas('product', static fn (Builder $p) => $p->where('name', 'like', $like)->orWhere('name_bn', 'like', $like));
            });
        }

        if ($categoryId !== null) {
            $category = Category::query()->find($categoryId);
            $ids = $category === null ? [0] : Category::query()
                ->where('_lft', '>=', $category->_lft)->where('_rgt', '<=', $category->_rgt)
                ->pluck('id')->all();
            $query->whereHas('product', static fn (Builder $p) => $p->where(
                static fn (Builder $w) => $w->whereIn('primary_category_id', $ids)->orWhereHas('categories', static fn (Builder $c) => $c->whereIn('categories.id', $ids)),
            ));
        }

        $candidates = $query->orderBy('product_id')->orderBy('position')->orderBy('id')->limit(self::LIMIT * 2)->get();
        $sellable = $candidates->filter(fn (ProductVariant $v): bool => $this->sellable->isSellable($v))->take(self::LIMIT)->values();

        $levels = StockLevel::query()->where('location_id', $locationId)
            ->whereIn('variant_id', $sellable->pluck('id')->all())->get()->keyBy('variant_id');

        return array_values($sellable->map(static function (ProductVariant $v) use ($levels): array {
            $level = $levels->get($v->id);

            return ['variant' => $v, 'available' => $level instanceof StockLevel ? max(0, $level->available()) : 0];
        })->all());
    }
}
