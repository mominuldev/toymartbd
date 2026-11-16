import { CmsPageHeader, MorePagesNav, PageContents } from '@/components/content/CmsPageView';
import { ContactInfoBlock } from '@/components/content/blocks/ContactInfoBlock';
import { FaqBlock } from '@/components/content/blocks/FaqBlock';
import { ImageBlock } from '@/components/content/blocks/ImageBlock';
import { PromoSplitBlock } from '@/components/content/blocks/PromoSplitBlock';
import { RichTextBlock } from '@/components/content/blocks/RichTextBlock';
import { Band } from '@/components/content/blocks/shared';
import { HomeBlocks, opensPage, PreviewBar } from '@/components/content/HomeBlocks';
import { PreviewBridge } from '@/components/content/PreviewBridge';
import { JsonLd } from '@/components/seo/JsonLd';
import { deviceClass, type HomeBlock } from '@/lib/content/home-blocks';
import type { PageLayout } from '@/lib/content/page-blocks';
import { pageToc } from '@/lib/content/page-toc';
import { adminOrigin } from '@/lib/content/preview-bridge';
import type { CmsPageSummary } from '@/lib/content/types';
import type { Locale } from '@/lib/i18n';
import { faqPageJsonLd } from '@/lib/seo/jsonld';
import { getStoreInfo } from '@/lib/store-info';

// A static page built from sections (owner decision 2026-11-16): the page's Bloom header with its
// title as the only H1 (a hero section's headline drops to h2), then its sections.
//
// Two layouts, chosen by the sections themselves (lib/content/page-toc.ts):
// - With an "On this page" list — two or more h2s across the rich-text sections, or two or more FAQ
//   sections (Privacy 166:9992, Terms 173:11315, FAQ 171:10747): a 300 sticky list beside a 932
//   article column (48 apart, 40 under the header); a page with numbered rich text (the policy look)
//   carries the "last updated" pill in its header.
//   Rich text renders as numbered sections, FAQ groups fill the column. At 390 the list folds into
//   a "Jump to a section" disclosure above the article.
// - Otherwise (Contact, About): the sections stacked full width with the homepage's block components
//   (HomeBlocks), so a section looks the same on every page. A page that opens with a story hero
//   or a contact hero (About us 170:10502, Contact us 165:9657) has no separate header: the hero is the header and its title the H1.
// The sidebar template's links to the other store pages follow the sections. Rendered inside a
// `bleed` PageShell: each band lays out its own gutters. A draft (preview) carries the preview bar
// and the builder's live-edit bridge, like the home draft.

/** Sections that read well in the 932 article column; any other type keeps its full-width band below it. */
const ARTICLE_TYPES = new Set<HomeBlock['type']>(['rich_text', 'faq', 'image', 'contact_info', 'promo_split']);

export async function CmsSectionsPage({ locale, layout, otherPages = [] }: { locale: Locale; layout: PageLayout; otherPages?: CmsPageSummary[] }) {
    const { contact } = await getStoreInfo();
    const more = otherPages.filter((p) => p.slug !== layout.page.slug);
    const { entries, starts } = pageToc(layout.blocks);
    const withContents = entries.length >= 2;

    const preview = layout.preview ? (
        <>
            <PreviewBar locale={locale} page={layout.page.slug} />
            <PreviewBridge adminOrigin={adminOrigin({ ADMIN_URL: process.env.ADMIN_URL, API_URL: process.env.API_URL })} />
        </>
    ) : null;

    if (!withContents) {
        const storyOpensPage = opensPage(layout.blocks[0]);
        return (
            <>
                {preview}
                <article className="flex flex-col gap-8 lg:gap-12">
                    {storyOpensPage ? null : (
                        <Band>
                            <CmsPageHeader locale={locale} page={layout.page} />
                        </Band>
                    )}
                    {layout.blocks.length > 0 ? (
                        <HomeBlocks
                            blocks={layout.blocks}
                            locale={locale}
                            editable={layout.preview}
                            hotline={contact.hotline.display}
                            pageHeading={false}
                            storyOpensPage={storyOpensPage}
                            gap={storyOpensPage ? 'gap-10 lg:gap-16' : undefined}
                        />
                    ) : null}
                    {/* A page whose hero is its header (About, Contact) ends with its own sections, as in Figma. */}
                    {more.length > 0 && !storyOpensPage ? (
                        <Band>
                            <MorePagesNav locale={locale} pages={more} className="lg:max-w-75" />
                        </Band>
                    ) : null}
                </article>
            </>
        );
    }

    const article = layout.blocks.filter((b) => ARTICLE_TYPES.has(b.type));
    const wide = layout.blocks.filter((b) => !ARTICLE_TYPES.has(b.type));
    // One FAQPage for every FAQ section on the page (HomeBlocks does the same for the stacked layout).
    const faqs = article.flatMap((b) => (b.type === 'faq' ? b.content.items : []));

    return (
        <>
            {preview}
            <article className="flex flex-col gap-8 lg:gap-12">
                <Band>
                    <div className="flex flex-col gap-6 lg:gap-10">
                        <CmsPageHeader locale={locale} page={layout.page} updated={entries.some((e) => e.number !== null)} />
                        <div className="flex flex-col gap-8 lg:flex-row lg:items-start lg:gap-12">
                            <aside className="flex flex-col gap-4 lg:sticky lg:top-36 lg:w-75 lg:shrink-0">
                                <PageContents locale={locale} entries={entries} />
                                <div className="hidden lg:block">
                                    <MorePagesNav locale={locale} pages={more} />
                                </div>
                            </aside>
                            <div className="flex min-w-0 grow flex-col gap-10">
                                {faqs.length > 0 ? <JsonLd data={[faqPageJsonLd(faqs)]} /> : null}
                                {article.map((block) => {
                                    const index = layout.blocks.indexOf(block);
                                    return (
                                        <div key={block.id || index} data-block={block.type} data-block-id={layout.preview && block.id ? block.id : undefined} className={deviceClass(block)}>
                                            {renderArticleBlock(block, index, locale, starts.get(index))}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </div>
                </Band>
                {wide.length > 0 ? <HomeBlocks blocks={wide} locale={locale} editable={layout.preview} hotline={contact.hotline.display} pageHeading={false} /> : null}
                {more.length > 0 ? (
                    <Band className="lg:hidden">
                        <MorePagesNav locale={locale} pages={more} />
                    </Band>
                ) : null}
            </article>
        </>
    );
}

function renderArticleBlock(block: HomeBlock, index: number, locale: Locale, numberFrom: number | undefined) {
    switch (block.type) {
        case 'rich_text':
            return <RichTextBlock html={block.content.html} locale={locale} numberFrom={numberFrom ?? 1} />;
        case 'faq':
            return <FaqBlock id={block.id || String(index)} title={block.content.title} items={block.content.items} locale={locale} jsonLd={false} fill />;
        case 'image':
            return <ImageBlock content={block.content} locale={locale} />;
        case 'contact_info':
            return <ContactInfoBlock id={block.id || String(index)} content={block.content} locale={locale} />;
        case 'promo_split':
            return <PromoSplitBlock id={block.id || String(index)} content={block.content} locale={locale} />;
        default:
            return null;
    }
}
