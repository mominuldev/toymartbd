import type { ReactNode } from 'react';

import { AgeTilesBlock } from '@/components/content/blocks/AgeTilesBlock';
import { BlogTeaserBlock } from '@/components/content/blocks/BlogTeaserBlock';
import { BrandStripBlock } from '@/components/content/blocks/BrandStripBlock';
import { CategoryTilesBlock } from '@/components/content/blocks/CategoryTilesBlock';
import { ContactFormBlock } from '@/components/content/blocks/ContactFormBlock';
import { ContactHeroBlock } from '@/components/content/blocks/ContactHeroBlock';
import { ContactInfoBlock } from '@/components/content/blocks/ContactInfoBlock';
import { CtaBandBlock } from '@/components/content/blocks/CtaBandBlock';
import { FaqBlock } from '@/components/content/blocks/FaqBlock';
import { FeatureTilesBlock } from '@/components/content/blocks/FeatureTilesBlock';
import { FlashSaleBlock } from '@/components/content/blocks/FlashSaleBlock';
import { HeroBlock } from '@/components/content/blocks/HeroBlock';
import { ImageBlock } from '@/components/content/blocks/ImageBlock';
import { NumberedCardsBlock } from '@/components/content/blocks/NumberedCardsBlock';
import { ProductRailBlock } from '@/components/content/blocks/ProductRailBlock';
import { PromoSplitBlock } from '@/components/content/blocks/PromoSplitBlock';
import { RichTextBlock } from '@/components/content/blocks/RichTextBlock';
import { Band } from '@/components/content/blocks/shared';
import { StoryHeroBlock } from '@/components/content/blocks/StoryHeroBlock';
import { TestimonialsBlock } from '@/components/content/blocks/TestimonialsBlock';
import { UspBandBlock } from '@/components/content/blocks/UspBandBlock';
import { JsonLd } from '@/components/seo/JsonLd';
import { deviceClass, type HomeBlock } from '@/lib/content/home-blocks';
import { getMessages, type Locale } from '@/lib/i18n';
import { faqPageJsonLd } from '@/lib/seo/jsonld';

// The CMS homepage (Phase 7): the published blocks, in the editor's order, one component per
// type (components/content/blocks/**). Server Components only. The per-device switch is a CSS
// class at the 1024 breakpoint (the page is cached, so it cannot know the device). The first hero
// carries the LCP image. Full-bleed bands (USP) skip the gutters; everything else sits in a Band.

function render(block: HomeBlock, locale: Locale, firstHero: boolean, hotline: string, pageHeading: boolean, opensPage: boolean): ReactNode {
    const t = getMessages(locale);
    switch (block.type) {
        case 'hero':
            return <HeroBlock id={block.id} slides={block.content.slides} showAgePills={block.content.show_age_pills} locale={locale} t={t.home} priority={firstHero} pageHeading={pageHeading} />;
        case 'category_tiles':
            return <CategoryTilesBlock id={block.id} title={block.content.title} showSeeAll={block.content.show_see_all} categories={block.categories} locale={locale} t={t.home} />;
        case 'age_tiles':
            return <AgeTilesBlock id={block.id} title={block.content.title} showSeeAll={block.content.show_see_all} counts={block.age_counts} locale={locale} t={t.home} />;
        case 'product_rail':
            return <ProductRailBlock id={block.id} title={block.content.title} products={block.products} seeAllHref={block.see_all_href} locale={locale} t={t} />;
        case 'flash_sale':
            return <FlashSaleBlock id={block.id} title={block.content.title} limit={block.content.limit} locale={locale} />;
        case 'brand_strip':
            return <BrandStripBlock id={block.id} title={block.content.title} brands={block.brands} locale={locale} t={t.brands} />;
        case 'usp_band':
            return <UspBandBlock id={block.id} eyebrow={block.content.eyebrow} title={block.content.title} items={block.content.items} locale={locale} hotline={hotline} />;
        case 'promo_split':
            return <PromoSplitBlock id={block.id} content={block.content} locale={locale} />;
        case 'testimonials':
            return <TestimonialsBlock id={block.id} title={block.content.title} testimonials={block.testimonials} locale={locale} t={t.home} />;
        case 'blog_teaser':
            return <BlogTeaserBlock id={block.id} title={block.content.title} sub={block.content.sub} posts={block.posts} locale={locale} t={t.home} />;
        case 'rich_text':
            return <RichTextBlock html={block.content.html} locale={locale} />;
        case 'faq':
            return <FaqBlock id={block.id} title={block.content.title} items={block.content.items} locale={locale} jsonLd={false} />;
        case 'image':
            return <ImageBlock content={block.content} locale={locale} />;
        case 'contact_info':
            return <ContactInfoBlock id={block.id} content={block.content} locale={locale} />;
        case 'story_hero':
            return <StoryHeroBlock content={block.content} locale={locale} heading={pageHeading || opensPage ? 'h1' : 'h2'} priority={opensPage} />;
        case 'feature_tiles':
            return <FeatureTilesBlock id={block.id} title={block.content.title} items={block.content.items} locale={locale} />;
        case 'numbered_cards':
            return <NumberedCardsBlock id={block.id} title={block.content.title} items={block.content.items} locale={locale} />;
        case 'cta_band':
            return <CtaBandBlock id={block.id} content={block.content} locale={locale} />;
        case 'contact_hero':
            return <ContactHeroBlock content={block.content} locale={locale} heading={pageHeading || opensPage ? 'h1' : 'h2'} />;
        case 'contact_form':
            return <ContactFormBlock id={block.id || 'form'} content={block.content} locale={locale} />;
    }
}

/**
 * `editable` (the draft preview only) stamps each section with its block id, which the builder's
 * live editor (PreviewBridge) uses to outline and select it. The live page carries no ids.
 * `hotline` is the store hotline for `{phone}` in USP items (lib/store-info.ts, display form).
 * `pageHeading={false}` on a static page built from sections (CmsSectionsPage): the page title is
 * the H1 there, so a hero headline renders as an h2 — unless the page opens with a story or
 * contact hero (`storyOpensPage`, see opensPage()): then the page has no header, that hero's title
 * is the H1 (and a story hero's photos are the LCP). `gap` overrides the space between sections (static pages use the 64 of their Figma frames).
 */
export function HomeBlocks({
    blocks,
    locale,
    editable = false,
    hotline,
    pageHeading = true,
    storyOpensPage = false,
    gap = 'gap-12 lg:gap-20',
}: {
    blocks: HomeBlock[];
    locale: Locale;
    editable?: boolean;
    hotline: string;
    pageHeading?: boolean;
    storyOpensPage?: boolean;
    gap?: string;
}) {
    const firstHero = blocks.findIndex((b) => b.type === 'hero');
    // One FAQPage for every FAQ block on the page (a page carries at most one FAQPage).
    const faqs = blocks.flatMap((b) => (b.type === 'faq' ? b.content.items : []));
    return (
        <div className={`flex flex-col ${gap}`}>
            {faqs.length > 0 ? <JsonLd data={[faqPageJsonLd(faqs)]} /> : null}
            {blocks.map((block, i) => {
                const node = render(block, locale, i === firstHero, hotline, pageHeading, storyOpensPage && i === 0 && opensPage(block));
                const device = deviceClass(block);
                const blockId = editable && block.id ? block.id : undefined;
                return block.type === 'usp_band' || block.type === 'flash_sale' ? (
                    <div key={block.id || i} data-block={block.type} data-block-id={blockId} className={device}>
                        {node}
                    </div>
                ) : (
                    <Band key={block.id || i} className={device}>
                        <div data-block={block.type} data-block-id={blockId}>
                            {node}
                        </div>
                    </Band>
                );
            })}
        </div>
    );
}

/** Hero sections that, placed first on a page built from sections, are that page's header. */
export function opensPage(block: HomeBlock | undefined): boolean {
    return block?.type === 'story_hero' || block?.type === 'contact_hero';
}

/** The thin bar shown over a draft preview, with a way out (`page`: a static page's draft). */
export function PreviewBar({ locale, page }: { locale: Locale; page?: string }) {
    const t = getMessages(locale);
    const exit = page ? `/api/preview/page/exit?${new URLSearchParams({ slug: page, locale }).toString()}` : '/api/preview/home/exit';
    return (
        <div role="status" className="flex items-center justify-center gap-3 bg-warning-subtle px-gutter-mobile py-2 text-fg-primary">
            <span className="t-body-sm-strong">{page ? t.content.previewBanner : t.home.previewBanner}</span>
            <a href={exit} className="t-body-sm-strong text-fg-link underline">
                {t.home.previewExit}
            </a>
        </div>
    );
}
