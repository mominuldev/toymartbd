import { CmsPageHeader, MorePagesNav } from '@/components/content/CmsPageView';
import { Band } from '@/components/content/blocks/shared';
import { HomeBlocks, PreviewBar } from '@/components/content/HomeBlocks';
import { PreviewBridge } from '@/components/content/PreviewBridge';
import type { PageLayout } from '@/lib/content/page-blocks';
import { adminOrigin } from '@/lib/content/preview-bridge';
import type { CmsPageSummary } from '@/lib/content/types';
import type { Locale } from '@/lib/i18n';
import { getStoreInfo } from '@/lib/store-info';

// A static page built from sections (owner decision 2026-11-16): the page's Bloom-sky header with
// its title as the only H1 (a hero section's headline drops to h2), then its sections rendered by
// the homepage's block components (HomeBlocks), so a section looks the same on every page. The sidebar template's links to the other store pages
// follow the sections. Rendered inside a `bleed` PageShell: each band lays out its own gutters.
// A draft (preview) carries the preview bar and the builder's live-edit bridge, like the home draft.

export async function CmsSectionsPage({ locale, layout, otherPages = [] }: { locale: Locale; layout: PageLayout; otherPages?: CmsPageSummary[] }) {
    const { contact } = await getStoreInfo();
    const more = otherPages.filter((p) => p.slug !== layout.page.slug);

    return (
        <>
            {layout.preview ? (
                <>
                    <PreviewBar locale={locale} page={layout.page.slug} />
                    <PreviewBridge adminOrigin={adminOrigin({ ADMIN_URL: process.env.ADMIN_URL, API_URL: process.env.API_URL })} />
                </>
            ) : null}
            <article className="flex flex-col gap-8 lg:gap-12">
                <Band>
                    <CmsPageHeader locale={locale} page={layout.page} />
                </Band>
                {layout.blocks.length > 0 ? <HomeBlocks blocks={layout.blocks} locale={locale} editable={layout.preview} hotline={contact.hotline.display} pageHeading={false} /> : null}
                {more.length > 0 ? (
                    <Band>
                        <MorePagesNav locale={locale} pages={more} className="lg:max-w-75" />
                    </Band>
                ) : null}
            </article>
        </>
    );
}
