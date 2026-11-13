<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Enums;

use App\Domain\Content\Blocks\Schemas\AgeTilesSchema;
use App\Domain\Content\Blocks\Schemas\BlockSchema;
use App\Domain\Content\Blocks\Schemas\BlogTeaserSchema;
use App\Domain\Content\Blocks\Schemas\BrandStripSchema;
use App\Domain\Content\Blocks\Schemas\CategoryTilesSchema;
use App\Domain\Content\Blocks\Schemas\FaqSchema;
use App\Domain\Content\Blocks\Schemas\FlashSaleSchema;
use App\Domain\Content\Blocks\Schemas\HeroSchema;
use App\Domain\Content\Blocks\Schemas\ProductRailSchema;
use App\Domain\Content\Blocks\Schemas\PromoSplitSchema;
use App\Domain\Content\Blocks\Schemas\RichTextSchema;
use App\Domain\Content\Blocks\Schemas\TestimonialsSchema;
use App\Domain\Content\Blocks\Schemas\UspBandSchema;

/**
 * Every CMS block type. Each has one JSON schema (BlockSchema): its validation rules, its
 * defaults for a freshly added block, and its normaliser (sanitising rich text, dropping unknown
 * keys). The storefront has one component per type (components/content/blocks/**).
 */
enum BlockType: string
{
    /** Hero / banner carousel: 1–5 slides; the first slide's image is the page's LCP. */
    case Hero = 'hero';
    case CategoryTiles = 'category_tiles';
    /** "Shop by age" tiles with live counts from the catalogue. */
    case AgeTiles = 'age_tiles';
    /** Product rail by rule: new, best sellers, category, brand or a manual pick. */
    case ProductRail = 'product_rail';
    /** Slot for the running flash sale (Marketing); the storefront fills it and hides it when none runs. */
    case FlashSale = 'flash_sale';
    case BrandStrip = 'brand_strip';
    case UspBand = 'usp_band';
    case PromoSplit = 'promo_split';
    /** Slot filled from the testimonials table (Content testimonials). */
    case Testimonials = 'testimonials';
    /** Slot filled from the latest live blog posts (Content blog). */
    case BlogTeaser = 'blog_teaser';
    case RichText = 'rich_text';
    /** Questions and answers; the storefront emits FAQPage JSON-LD for it. */
    case Faq = 'faq';

    public function schema(): BlockSchema
    {
        return match ($this) {
            self::Hero => new HeroSchema,
            self::CategoryTiles => new CategoryTilesSchema,
            self::AgeTiles => new AgeTilesSchema,
            self::ProductRail => new ProductRailSchema,
            self::FlashSale => new FlashSaleSchema,
            self::BrandStrip => new BrandStripSchema,
            self::UspBand => new UspBandSchema,
            self::PromoSplit => new PromoSplitSchema,
            self::Testimonials => new TestimonialsSchema,
            self::BlogTeaser => new BlogTeaserSchema,
            self::RichText => new RichTextSchema,
            self::Faq => new FaqSchema,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
