import type { ReactNode } from 'react';

import { AgeTilesBlock } from '@/components/content/blocks/AgeTilesBlock';
import { BlogTeaserBlock } from '@/components/content/blocks/BlogTeaserBlock';
import { BrandStripBlock } from '@/components/content/blocks/BrandStripBlock';
import { CategoryTilesBlock } from '@/components/content/blocks/CategoryTilesBlock';
import { FaqBlock } from '@/components/content/blocks/FaqBlock';
import { HeroBlock } from '@/components/content/blocks/HeroBlock';
import { ProductRailBlock } from '@/components/content/blocks/ProductRailBlock';
import { PromoSplitBlock } from '@/components/content/blocks/PromoSplitBlock';
import { RichTextBlock } from '@/components/content/blocks/RichTextBlock';
import { Band } from '@/components/content/blocks/shared';
import { TestimonialsBlock } from '@/components/content/blocks/TestimonialsBlock';
import { UspBandBlock } from '@/components/content/blocks/UspBandBlock';
import { deviceClass, type HomeBlock } from '@/lib/content/home-blocks';
import { getMessages, type Locale } from '@/lib/i18n';

// The CMS homepage (Phase 7): the published blocks, in the editor's order, one component per
// type (components/content/blocks/**). Server Components only. The per-device switch is a CSS
// class at the 1024 breakpoint (the page is cached, so it cannot know the device). The first hero
// carries the LCP image. Full-bleed bands (USP) skip the gutters; everything else sits in a Band.

function render(block: HomeBlock, locale: Locale, firstHero: boolean): ReactNode {
    const t = getMessages(locale);
    switch (block.type) {
        case 'hero':
            return <HeroBlock id={block.id} slides={block.content.slides} showAgePills={block.content.show_age_pills} locale={locale} t={t.home} priority={firstHero} />;
        case 'category_tiles':
            return <CategoryTilesBlock id={block.id} title={block.content.title} showSeeAll={block.content.show_see_all} categories={block.categories} locale={locale} t={t.home} />;
        case 'age_tiles':
            return <AgeTilesBlock id={block.id} title={block.content.title} showSeeAll={block.content.show_see_all} counts={block.age_counts} locale={locale} t={t.home} />;
        case 'product_rail':
            return <ProductRailBlock id={block.id} title={block.content.title} products={block.products} seeAllHref={block.see_all_href} locale={locale} t={t} />;
        case 'brand_strip':
            return <BrandStripBlock id={block.id} title={block.content.title} brands={block.brands} locale={locale} t={t.brands} />;
        case 'usp_band':
            return <UspBandBlock id={block.id} eyebrow={block.content.eyebrow} title={block.content.title} items={block.content.items} locale={locale} />;
        case 'promo_split':
            return <PromoSplitBlock id={block.id} content={block.content} locale={locale} />;
        case 'testimonials':
            return <TestimonialsBlock id={block.id} title={block.content.title} testimonials={block.testimonials} locale={locale} t={t.home} />;
        case 'blog_teaser':
            return <BlogTeaserBlock id={block.id} title={block.content.title} sub={block.content.sub} posts={block.posts} locale={locale} t={t.home} />;
        case 'rich_text':
            return <RichTextBlock html={block.content.html} locale={locale} />;
        case 'faq':
            return <FaqBlock id={block.id} title={block.content.title} items={block.content.items} locale={locale} />;
    }
}

export function HomeBlocks({ blocks, locale }: { blocks: HomeBlock[]; locale: Locale }) {
    const firstHero = blocks.findIndex((b) => b.type === 'hero');
    return (
        <div className="flex flex-col gap-12 lg:gap-20">
            {blocks.map((block, i) => {
                const node = render(block, locale, i === firstHero);
                const device = deviceClass(block);
                return block.type === 'usp_band' ? (
                    <div key={block.id || i} data-block={block.type} className={device}>
                        {node}
                    </div>
                ) : (
                    <Band key={block.id || i} className={device}>
                        <div data-block={block.type}>{node}</div>
                    </Band>
                );
            })}
        </div>
    );
}

/** The thin bar shown over a draft preview, with a way out. */
export function PreviewBar({ locale }: { locale: Locale }) {
    const t = getMessages(locale).home;
    return (
        <div role="status" className="flex items-center justify-center gap-3 bg-warning-subtle px-gutter-mobile py-2 text-fg-primary">
            <span className="t-body-sm-strong">{t.previewBanner}</span>
            <a href="/api/preview/home/exit" className="t-body-sm-strong text-fg-link underline">
                {t.previewExit}
            </a>
        </div>
    );
}
