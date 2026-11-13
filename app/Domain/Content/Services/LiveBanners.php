<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Catalog\Models\Category;
use App\Domain\Content\Enums\BannerContext;
use App\Domain\Content\Enums\BannerPlacement;
use App\Domain\Content\Models\Banner;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The banners live in one placement right now, highest priority first.
 *
 * Placement rules (rule 25b): on cart and checkout only the info strip renders — every other
 * placement answers empty there, whatever is scheduled. A category-page banner shows on its
 * target category, or on every category when it has none.
 */
final class LiveBanners
{
    public const int LIMIT = 5;

    /** @return Collection<int, Banner> */
    public function for(BannerPlacement $placement, BannerContext $context, ?string $categorySlug, CarbonInterface $at): Collection
    {
        if ($context->isPurchaseFlow() && ! $placement->allowedOnCheckout()) {
            return new Collection;
        }

        $query = Banner::query()
            ->with('media')
            ->where('placement', $placement->value)
            ->liveAt($at);

        if ($placement === BannerPlacement::CategoryPage) {
            $categoryId = $categorySlug === null ? null : Category::query()->where('slug', $categorySlug)->value('id');
            $query->where(static function (Builder $q) use ($categoryId): void {
                $q->whereNull('category_id');
                if ($categoryId !== null) {
                    $q->orWhere('category_id', $categoryId);
                }
            });
            // A banner aimed at this category outranks a general one of the same priority.
            $query->orderByRaw('CASE WHEN category_id IS NULL THEN 1 ELSE 0 END');
        }

        return $query->orderByDesc('priority')->orderByDesc('id')->limit(self::LIMIT)->get();
    }
}
