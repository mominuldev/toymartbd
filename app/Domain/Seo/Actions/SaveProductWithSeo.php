<?php

declare(strict_types=1);

namespace App\Domain\Seo\Actions;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Product;
use App\Domain\Seo\DTOs\SeoMetaData;
use App\Domain\Seo\Models\SeoMeta;
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
    public function __construct(private readonly RecordSlugChange $slugs, private readonly SaveSeoMeta $meta) {}

    /** @param  Closure(): Product  $write */
    public function handle(?Product $existing, Closure $write, ?SeoMetaData $seo, ?int $actorId = null): Product
    {
        return DB::transaction(function () use ($existing, $write, $seo, $actorId): Product {
            $previousSlug = $existing?->slug;
            $wasLive = $existing?->published_at !== null && $existing->isLive();

            $product = $write();

            if ($previousSlug !== null && $previousSlug !== $product->slug) {
                $this->slugs->handle(SeoMeta::TYPE_PRODUCT, $product->id, $previousSlug, $product->slug, $wasLive, $actorId);
            }

            if ($seo !== null) {
                $meta = $this->meta->write(SeoMeta::TYPE_PRODUCT, $product->id, self::editorColumns($seo));

                // The storefront's <head> reads it: purge the product's pages (after commit).
                if ($meta->wasRecentlyCreated || $meta->wasChanged()) {
                    ProductChanged::dispatch($product->id, $product->slug);
                }
            }

            return $product;
        });
    }

    /**
     * The columns an entity editor (product, static page) saves with its document: everything
     * but the Bangla Open Graph pair, which only the SEO panel edits — so they keep their value.
     *
     * @return array<string, mixed>
     */
    public static function editorColumns(SeoMetaData $seo): array
    {
        return array_diff_key(SaveSeoMeta::columns($seo), array_flip(['og_title_bn', 'og_description_bn']));
    }
}
