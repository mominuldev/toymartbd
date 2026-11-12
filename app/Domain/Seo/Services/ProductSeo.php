<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

use App\Domain\Catalog\Contracts\AvailableStockQuery;
use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Seo\Enums\RobotsDirective;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Models\SlugHistory;

/**
 * A product's search and social presentation, computed on the server (rule 1): the
 * defaults an empty override falls back to, the Product JSON-LD, and the SeoPanel
 * checklist (Figma 198:1123) that feeds the SEO health score.
 *
 * Reads the product's loaded `images` and `variants` relations; call with them loaded.
 *
 * @phpstan-type ChecklistItem array{key: string, status: 'pass'|'warn'|'fail', params: array<string, int|string>}
 */
final readonly class ProductSeo
{
    public const string SITE_NAME = 'Toy Mart BD';

    public const string SITE_URL = 'https://toymartbd.com';

    /** Google's preferred minimum for a product image (Merchant Center and rich results). */
    public const int PRIMARY_IMAGE_MIN = 1200;

    /** Characters kept from the long description when no short description exists. */
    public const int DESCRIPTION_FALLBACK_CHARS = 155;

    public function __construct(private SerpMeasure $measure, private AvailableStockQuery $stock) {}

    public function url(Product $product): string
    {
        return self::SITE_URL.'/products/'.$product->slug;
    }

    /** @return array{title: string, title_bn: string, description: string, description_bn: string, og_image: string|null} */
    public function defaults(Product $product): array
    {
        $primary = self::primaryImage($product);

        return [
            'title' => $product->name.' | '.self::SITE_NAME,
            'title_bn' => ($product->name_bn ?? $product->name).' | '.self::SITE_NAME,
            'description' => self::summary($product->short_description, $product->description),
            'description_bn' => self::summary($product->short_description_bn, $product->description_bn)
                ?: self::summary($product->short_description, $product->description),
            'og_image' => $primary?->path !== null && $primary->processing_status === ImageProcessingStatus::Ready ? $primary->url(1200) : null,
        ];
    }

    /** @return array<string, mixed> Product JSON-LD, with the page's `schema_override` merged on top. */
    public function schema(Product $product, ?SeoMeta $meta): array
    {
        $variant = self::defaultVariant($product);
        $defaults = $this->defaults($product);
        $images = $product->images
            ->filter(static fn (ProductImage $i): bool => $i->path !== null && $i->processing_status === ImageProcessingStatus::Ready)
            ->map(static fn (ProductImage $i): ?string => $i->url(1200))
            ->filter()
            ->values()
            ->all();

        $schema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'description' => self::effective($meta?->description, $defaults['description']) ?: null,
            'sku' => $variant?->sku,
            'gtin' => $variant?->barcode,
            'image' => $images === [] ? null : $images,
            'brand' => $product->brand === null ? null : ['@type' => 'Brand', 'name' => $product->brand->name],
            'offers' => $variant === null ? null : [
                '@type' => 'Offer',
                'url' => $this->url($product),
                'priceCurrency' => 'BDT',
                'price' => $variant->price->toDecimal(),
                'availability' => $this->inStock($product) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'itemCondition' => 'https://schema.org/NewCondition',
            ],
        ], static fn (mixed $v): bool => $v !== null);

        return $meta?->schema_override !== null ? array_replace_recursive($schema, $meta->schema_override) : $schema;
    }

    /**
     * @return array{passed: int, total: int, items: list<ChecklistItem>}
     */
    public function checklist(Product $product, ?SeoMeta $meta): array
    {
        $defaults = $this->defaults($product);
        $title = self::effective($meta?->title, $defaults['title']);
        $description = self::effective($meta?->description, $defaults['description']);
        $titleMeasure = $this->measure->title($title);
        $descriptionMeasure = $this->measure->description($description);

        $items = [];

        $items[] = ['key' => 'title_fits', 'status' => $titleMeasure['fits'] ? 'pass' : 'fail', 'params' => ['px' => $titleMeasure['px'], 'max' => $titleMeasure['max']]];

        $items[] = [
            'key' => 'description_fits',
            'status' => $description === '' ? 'fail' : ($descriptionMeasure['fits'] ? 'pass' : 'warn'),
            'params' => ['px' => $descriptionMeasure['px'], 'max' => $descriptionMeasure['max']],
        ];

        $primary = self::primaryImage($product);
        $items[] = [
            'key' => 'primary_image',
            'status' => $primary === null ? 'fail' : (min((int) $primary->width, (int) $primary->height) >= self::PRIMARY_IMAGE_MIN ? 'pass' : 'warn'),
            'params' => ['width' => (int) $primary?->width, 'height' => (int) $primary?->height, 'min' => self::PRIMARY_IMAGE_MIN],
        ];

        $missingBn = $product->images->filter(static fn (ProductImage $i): bool => ($i->alt_text_bn ?? '') === '')->count();
        $missingEn = $product->images->filter(static fn (ProductImage $i): bool => ($i->alt_text ?? '') === '')->count();
        $items[] = [
            'key' => 'image_alt',
            'status' => $missingBn + $missingEn === 0 ? 'pass' : 'warn',
            'params' => ['missing_en' => $missingEn, 'missing_bn' => $missingBn],
        ];

        $items[] = ['key' => 'slug', 'status' => $this->slugOk($product) ? 'pass' : 'fail', 'params' => ['slug' => $product->slug]];

        $variant = self::defaultVariant($product);
        $schemaOk = $product->name !== '' && $variant !== null && ! $variant->price->isZero() && $primary !== null;
        $items[] = ['key' => 'product_schema', 'status' => $schemaOk ? 'pass' : 'fail', 'params' => []];

        $robots = $meta === null ? RobotsDirective::IndexFollow : $meta->robots;
        $reason = match (true) {
            ! $product->isLive() => 'not_live',
            ! $robots->indexable() => 'noindex',
            $meta?->sitemap_exclude === true => 'excluded',
            default => '',
        };
        $items[] = ['key' => 'sitemap', 'status' => $reason === '' ? 'pass' : 'warn', 'params' => ['reason' => $reason]];

        return [
            'passed' => count(array_filter($items, static fn (array $i): bool => $i['status'] === 'pass')),
            'total' => count($items),
            'items' => $items,
        ];
    }

    /** A stored override, or the default when it is unset or empty. */
    public static function effective(?string $override, string $default): string
    {
        return $override !== null && $override !== '' ? $override : $default;
    }

    /** ASCII, unique among products, and not another product's retired slug. */
    private function slugOk(Product $product): bool
    {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $product->slug) !== 1) {
            return false;
        }

        $taken = Product::withTrashed()->where('slug', $product->slug)->whereKeyNot($product->id)->exists();
        $retired = SlugHistory::query()
            ->where('sluggable_type', SeoMeta::TYPE_PRODUCT)
            ->where('old_slug', $product->slug)
            ->where('sluggable_id', '!=', $product->id)
            ->exists();

        return ! $taken && ! $retired;
    }

    private function inStock(Product $product): bool
    {
        $ids = $product->variants->filter(static fn (ProductVariant $v): bool => $v->isActive())->modelKeys();

        if ($ids === []) {
            return false;
        }

        return $this->stock->availableByVariant()->whereIn('variant_id', $ids)->get()
            ->contains(static fn (object $row): bool => (int) ($row->available ?? 0) > 0);
    }

    private static function primaryImage(Product $product): ?ProductImage
    {
        return $product->images->first(static fn (ProductImage $i): bool => $i->is_primary) ?? $product->images->first();
    }

    private static function defaultVariant(Product $product): ?ProductVariant
    {
        $active = $product->variants->filter(static fn (ProductVariant $v): bool => $v->isActive());

        return $active->first(static fn (ProductVariant $v): bool => $v->is_default) ?? $active->first();
    }

    /** The short description, or the first sentence-ish of the long one, as plain text. */
    private static function summary(?string $short, ?string $long): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) (($short ?? '') !== '' ? $short : $long))));

        if (mb_strlen($text) <= self::DESCRIPTION_FALLBACK_CHARS) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::DESCRIPTION_FALLBACK_CHARS);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > 80 ? mb_substr($cut, 0, $space) : $cut, ' ,.;:').'…';
    }
}
