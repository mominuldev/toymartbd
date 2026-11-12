<?php

declare(strict_types=1);

namespace App\Domain\Seo\Actions;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Product;
use App\Domain\Seo\DTOs\SeoMetaData;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Models\SlugHistory;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Save a product document together with its `seo_meta` and, when a published product's
 * slug changes, its `slug_history` row — one transaction, so a product is never saved
 * with half its search presentation (DATABASE-DESIGN §3.9).
 *
 * The product write itself is the caller's Catalog (or Inventory) Action, passed as a
 * closure: Seo may depend on Catalog, never the reverse (deptrac), and the create path
 * already composes Inventory's CreateProductWithOpeningStock. The inner Action's own
 * transaction nests as a savepoint.
 *
 * - `$seo` null: leave the product's seo_meta as it is.
 * - A slug change is recorded only when the old URL was live (a draft's URL never was).
 *   A slug that becomes live again stops being a redirect: any history row whose
 *   `old_slug` is the new slug is removed, so the live URL always wins.
 */
final class SaveProductWithSeo
{
    /** @param  Closure(): Product  $write */
    public function handle(?Product $existing, Closure $write, ?SeoMetaData $seo, ?int $actorId = null): Product
    {
        return DB::transaction(function () use ($existing, $write, $seo, $actorId): Product {
            $previousSlug = $existing?->slug;
            $wasLive = $existing?->published_at !== null && $existing->isLive();

            $product = $write();

            if ($previousSlug !== null && $previousSlug !== $product->slug) {
                SlugHistory::query()
                    ->where('sluggable_type', SeoMeta::TYPE_PRODUCT)
                    ->where('old_slug', $product->slug)
                    ->delete();

                if ($wasLive) {
                    SlugHistory::query()->create([
                        'sluggable_type' => SeoMeta::TYPE_PRODUCT,
                        'sluggable_id' => $product->id,
                        'old_slug' => $previousSlug,
                        'new_slug' => $product->slug,
                        'user_id' => $actorId,
                    ]);

                    // Earlier redirects of this product now point one hop further.
                    SlugHistory::query()
                        ->where('sluggable_type', SeoMeta::TYPE_PRODUCT)
                        ->where('sluggable_id', $product->id)
                        ->update(['new_slug' => $product->slug]);
                }
            }

            if ($seo !== null) {
                $meta = SeoMeta::query()->updateOrCreate(
                    ['seoable_type' => SeoMeta::TYPE_PRODUCT, 'seoable_id' => $product->id],
                    [
                        'title' => $seo->title,
                        'title_bn' => $seo->titleBn,
                        'description' => $seo->description,
                        'description_bn' => $seo->descriptionBn,
                        'canonical_url' => $seo->canonicalUrl,
                        'robots' => $seo->robots,
                        'sitemap_exclude' => $seo->sitemapExclude,
                        'og_title' => $seo->ogTitle,
                        'og_description' => $seo->ogDescription,
                        'og_image' => $seo->ogImage,
                        'twitter_card' => $seo->twitterCard,
                        'schema_override' => $seo->schemaOverride,
                        'focus_keyword' => $seo->focusKeyword,
                    ],
                );

                // The storefront's <head> reads it: purge the product's pages (after commit).
                if ($meta->wasRecentlyCreated || $meta->wasChanged()) {
                    ProductChanged::dispatch($product->id, $product->slug);
                }
            }

            return $product;
        });
    }
}
