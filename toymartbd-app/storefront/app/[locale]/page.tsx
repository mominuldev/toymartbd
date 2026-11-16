import type { Metadata } from 'next';
import { cookies, draftMode } from 'next/headers';

import { CategoryTile, TileLink } from '@/components/CategoryTile';
import { BlogTeaserBlock } from '@/components/content/blocks/BlogTeaserBlock';
import { FlashSaleBlock } from '@/components/content/blocks/FlashSaleBlock';
import { Band } from '@/components/content/blocks/shared';
import { HomeBlocks, PreviewBar } from '@/components/content/HomeBlocks';
import { PreviewBridge } from '@/components/content/PreviewBridge';
import { HomeHero } from '@/components/home/HomeHero';
import { UspBand } from '@/components/home/UspBand';
import { ageIcon } from '@/components/listing/ProductCard';
import { ProductRail, SectionHeader } from '@/components/listing/ProductRail';
import { PageShell } from '@/components/PageShell';
import { getBlogTeaser } from '@/lib/blog/fetch';
import { getCategoryTree } from '@/lib/categories';
import { fetchHome, fetchHomePreview } from '@/lib/content/home';
import { getFlashSales } from '@/lib/flash-sale/fetch';
import type { HomeLayout } from '@/lib/content/home-blocks';
import { HOME_PREVIEW_COOKIE } from '@/lib/content/preview';
import { adminOrigin } from '@/lib/content/preview-bridge';
import { format, getMessages, isLocale, localizedPath, type Locale } from '@/lib/i18n';
import { AGE_BANDS } from '@/lib/listing/state';
import { fetchRail } from '@/lib/products';
import { JsonLd } from '@/components/seo/JsonLd';
import { organizationJsonLd, websiteJsonLd } from '@/lib/seo/jsonld';
import { getTitleSeparator } from '@/lib/seo/fetch';
import { homeMetadata } from '@/lib/seo/metadata';
import { getStoreInfo } from '@/lib/store-info';

// Home — the CMS homepage (Phase 7): the published blocks from GET /storefront/content/home,
// ISR-cached under `content:home` (+ the tags of the data they show), revalidated on publish and at
// every schedule boundary. In Next.js draft mode (opened by /api/preview/home with a signed token
// from the admin's homepage builder) the DRAFT renders instead, uncached, under a preview bar.
//
// Built-in home (P4-STO-02, Figma 133:8 / 126:10) — shown when the CMS has nothing published or the
// API cannot answer, so the page never fails: the layout and copy live here, the data is real. Sections, in Figma order: hero
// (age chooser; "See the flash sale" while a sale runs), category tiles (the menu tree's top level,
// counts from the catalogue's category facet), the flash-sale band (GET /storefront/flash-sales —
// removed when no sale runs, §B1.3), shop by age (counts from the catalogue's age facet), best
// sellers and new arrivals (GET /storefront/products sorted `featured` / `newest`), the "Why parents
// shop with us" band, and the blog teaser "From the toy guide" (GET /storefront/blog/teaser).
//
// ISR: the product, category, flash-sale and blog fetches are tagged, so writes in Laravel refresh
// Home through POST /api/revalidate. Every section that loses its data hides; the page itself never
// fails on a rail.

interface Props {
    params: Promise<{ locale: string }>;
}

async function localeOf(params: Props['params']): Promise<Locale> {
    const { locale } = await params;
    return isLocale(locale) ? locale : 'en';
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const locale = await localeOf(params);
    return homeMetadata(locale, await getTitleSeparator());
}

const CATEGORY_TILES = 8;

export default async function HomePage({ params }: Props) {
    const locale = await localeOf(params);

    // Draft preview: only when draft mode is on (the cookie is read only then, so the live page
    // stays statically cached). A refused or expired token falls back to the live page.
    let home: HomeLayout | null = null;
    if ((await draftMode()).isEnabled) {
        const token = (await cookies()).get(HOME_PREVIEW_COOKIE)?.value;
        home = token ? await fetchHomePreview(locale, token) : null;
    }
    home ??= await fetchHome(locale);
    const { contact, branding } = await getStoreInfo();

    return (
        <PageShell locale={locale} path="/" announcement mobileSearch top="tight" bleed>
            {home?.preview ? (
                <>
                    <PreviewBar locale={locale} />
                    <PreviewBridge adminOrigin={adminOrigin({ ADMIN_URL: process.env.ADMIN_URL, API_URL: process.env.API_URL })} />
                </>
            ) : null}
            <JsonLd data={[organizationJsonLd(locale, contact, branding), websiteJsonLd(locale, branding)]} />
            {home && home.blocks.length > 0 ? <HomeBlocks blocks={home.blocks} locale={locale} editable={home.preview === true} hotline={contact.hotline.display} /> : <BuiltInHome locale={locale} />}
        </PageShell>
    );
}

async function BuiltInHome({ locale }: { locale: Locale }) {
    const t = getMessages(locale);
    const [tree, best, fresh, flash, posts] = await Promise.all([getCategoryTree(), fetchRail('featured', locale), fetchRail('newest', locale), getFlashSales(locale), getBlogTeaser(locale)]);
    const categories = tree.slice(0, CATEGORY_TILES);
    const flashSale = (flash?.sales ?? []).some((s) => s.products.length > 0);
    const ageCounts = new Map((best?.facets.age ?? []).map((o) => [o.value, o.count]));
    // Unfiltered, the category facet is the top level with each subtree's count (ProductListing::categoryFacet).
    const categoryCounts = new Map((best?.facets.category ?? []).map((o) => [o.value, o.count]));
    const ageTiles = t.home.ageTiles as Record<string, string>;
    const seeAll = (href: string, section: string) => ({ href: localizedPath(locale, href), label: t.home.seeAll, ariaLabel: format(t.home.seeAllLabel, { section }) });
    const toys = (count: number | undefined) => (count === undefined ? undefined : count === 1 ? t.home.toyCountOne : format(t.home.toyCount, { count: String(count) }));

    return (
        <div className="flex flex-col gap-12 lg:gap-20">
            <Band>
                <HomeHero locale={locale} t={t.home} flashSale={flashSale} />
            </Band>

            {categories.length > 0 ? (
                <Band>
                    <section aria-labelledby="home-categories" className="flex flex-col gap-4 lg:gap-6">
                        <SectionHeader id="home-categories" title={t.home.categoriesTitle} action={seeAll('/categories', t.home.categoriesTitle)} />
                        <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-6">
                            {categories.map((node, i) => (
                                <li key={node.slug}>
                                    <CategoryTile node={node} locale={locale} index={i} count={toys(categoryCounts.get(node.slug))} />
                                </li>
                            ))}
                        </ul>
                    </section>
                </Band>
            ) : null}

            {flashSale ? <FlashSaleBlock id="home-flash" title={t.flashSale.title} limit={8} locale={locale} /> : null}

            <Band>
                <section id="shop-by-age" aria-labelledby="home-age" className="flex scroll-mt-34 flex-col gap-3 lg:gap-6">
                    <SectionHeader id="home-age" title={t.home.ageTitle} action={seeAll('/toys', t.home.ageTitle)} />
                    <ul className="relative -mx-gutter-mobile flex gap-3 overflow-x-auto px-gutter-mobile pt-2 pb-6 md:-mx-gutter-tablet md:px-gutter-tablet lg:mx-0 lg:grid lg:grid-cols-7 lg:gap-3 lg:overflow-visible lg:p-0">
                        {AGE_BANDS.map((band, i) => (
                            <li key={band} className="w-37 shrink-0 lg:w-auto">
                                <TileLink
                                    href={localizedPath(locale, `/toys?age=${band}`)}
                                    icon={ageIcon(Number.parseInt(band, 10))}
                                    label={ageTiles[band] ?? band}
                                    count={toys(ageCounts.get(band))}
                                    index={i}
                                />
                            </li>
                        ))}
                    </ul>
                </section>
            </Band>

            {best && best.products.length > 0 ? (
                <Band>
                    <ProductRail id="home-best" title={t.home.bestSellers} products={best.products.slice(0, 8)} locale={locale} t={t.listing} action={seeAll('/toys', t.home.bestSellers)} slider />
                </Band>
            ) : null}

            {fresh && fresh.products.length > 0 ? (
                <Band>
                    <ProductRail
                        id="home-new"
                        title={t.home.newArrivals}
                        products={fresh.products.slice(0, 8)}
                        locale={locale}
                        t={t.listing}
                        action={seeAll('/toys?sort=newest', t.home.newArrivals)}
                        slider
                    />
                </Band>
            ) : null}

            <UspBand t={t.home} />

            {posts.length > 0 ? (
                <Band>
                    <BlogTeaserBlock id="home-blog" title={t.home.blogTitle} sub={t.home.blogSub} posts={posts} locale={locale} t={t.home} />
                </Band>
            ) : null}
        </div>
    );
}
