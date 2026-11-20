<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Enums;

use App\Domain\Content\Blocks\Schemas\AgeTilesSchema;
use App\Domain\Content\Blocks\Schemas\BlockSchema;
use App\Domain\Content\Blocks\Schemas\BlogTeaserSchema;
use App\Domain\Content\Blocks\Schemas\BrandStripSchema;
use App\Domain\Content\Blocks\Schemas\CategoryTilesSchema;
use App\Domain\Content\Blocks\Schemas\ChatBandSchema;
use App\Domain\Content\Blocks\Schemas\ContactFormSchema;
use App\Domain\Content\Blocks\Schemas\ContactHeroSchema;
use App\Domain\Content\Blocks\Schemas\ContactInfoSchema;
use App\Domain\Content\Blocks\Schemas\CtaBandSchema;
use App\Domain\Content\Blocks\Schemas\FaqSchema;
use App\Domain\Content\Blocks\Schemas\FeatureTilesSchema;
use App\Domain\Content\Blocks\Schemas\FlashSaleSchema;
use App\Domain\Content\Blocks\Schemas\HelpHeroSchema;
use App\Domain\Content\Blocks\Schemas\HeroSchema;
use App\Domain\Content\Blocks\Schemas\ImageSchema;
use App\Domain\Content\Blocks\Schemas\NumberedCardsSchema;
use App\Domain\Content\Blocks\Schemas\ProductRailSchema;
use App\Domain\Content\Blocks\Schemas\PromoSplitSchema;
use App\Domain\Content\Blocks\Schemas\RichTextSchema;
use App\Domain\Content\Blocks\Schemas\StoryHeroSchema;
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
    /** Store contact details (hotline, WhatsApp, Messenger, email, address) from Settings › Store, plus hours. */
    case ContactInfo = 'contact_info';
    /** One media-library image: alt text, optional caption and link, text width or wide. */
    case Image = 'image';
    /** Story hero (About us): overline, the page headline, two paragraphs, a collage of up to three library photos, a badge. */
    case StoryHero = 'story_hero';
    /** Two to four pastel tiles, each an icon, a title and a sentence. */
    case FeatureTiles = 'feature_tiles';
    /** A heading and one to six numbered cards (01, 02…), each a title and a sentence. */
    case NumberedCards = 'numbered_cards';
    /** A gradient band with a title, a line of copy and up to two buttons. */
    case CtaBand = 'cta_band';
    /** Contact hero (Contact us): overline, the page headline, a lead, a status pill and up to four chat bubbles. */
    case ContactHero = 'contact_hero';
    /** "Send us a message" (→ the admin Inbox) beside an optional visit card with hours and a quick-links card. */
    case ContactForm = 'contact_form';
    /** Help hero (FAQ): centred overline, the page headline, a lead and a question search box. */
    case HelpHero = 'help_hero';
    /** "Still need help?": a mint band with a button to the store's WhatsApp. */
    case ChatBand = 'chat_band';

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
            self::ContactInfo => new ContactInfoSchema,
            self::Image => new ImageSchema,
            self::StoryHero => new StoryHeroSchema,
            self::FeatureTiles => new FeatureTilesSchema,
            self::NumberedCards => new NumberedCardsSchema,
            self::CtaBand => new CtaBandSchema,
            self::ContactHero => new ContactHeroSchema,
            self::ContactForm => new ContactFormSchema,
            self::HelpHero => new HelpHeroSchema,
            self::ChatBand => new ChatBandSchema,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
