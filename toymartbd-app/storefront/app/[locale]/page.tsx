import type { Metadata } from 'next';
import type { ReactNode } from 'react';

import { CategoryTile, TileLink } from '@/components/CategoryTile';
import { HomeHero } from '@/components/home/HomeHero';
import { UspBand } from '@/components/home/UspBand';
import { ageIcon } from '@/components/listing/ProductCard';
import { ProductRail, SectionHeader } from '@/components/listing/ProductRail';
import { PageShell } from '@/components/PageShell';
import { getCategoryTree } from '@/lib/categories';
import { format, getMessages, isLocale, localizedPath, type Locale } from '@/lib/i18n';
import { AGE_BANDS } from '@/lib/listing/state';
import { fetchRail } from '@/lib/products';
import { alternatesFor } from '@/lib/seo';

// Home (P4-STO-02) — Figma 133:8 (1440) and 126:10 (390). A static fallback until the CMS blocks
// of Phase 7: the layout and copy live here, the data is real. Sections, in Figma order: hero
// (age chooser), category tiles (the menu tree's top level), shop by age (counts from the
// catalogue's age facet), best sellers and new arrivals (GET /storefront/products sorted
// `featured` / `newest`), and the "Why parents shop with us" band.
//
// Not built, by design: the flash-sale band (no sale data — a band with no sale is removed, §B1.3)
// and the blog teaser (no blog until Phase 7). ISR: the product and category fetches are tagged
// `products` / `categories`, so writes in Laravel refresh Home through POST /api/revalidate.
// Every section that loses its data hides; the page itself never fails on a rail.

interface Props {
    params: Promise<{ locale: string }>;
}

async function localeOf(params: Props['params']): Promise<Locale> {
    const { locale } = await params;
    return isLocale(locale) ? locale : 'en';
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const locale = await localeOf(params);
    const t = getMessages(locale);
    return { description: t.home.metaDescription, alternates: alternatesFor(locale, '/') };
}

/** One content section inside the gutters and the 1280 cap. */
function Band({ children, className = '' }: { children: ReactNode; className?: string }) {
    return (
        <div className={`px-gutter-mobile md:px-gutter-tablet lg:px-gutter-desktop ${className}`}>
            <div className="mx-auto max-w-content">{children}</div>
        </div>
    );
}

const CATEGORY_TILES = 8;

export default async function HomePage({ params }: Props) {
    const locale = await localeOf(params);
    const t = getMessages(locale);
    const [tree, best, fresh] = await Promise.all([getCategoryTree(), fetchRail('featured', locale), fetchRail('newest', locale)]);
    const categories = tree.slice(0, CATEGORY_TILES);
    const ageCounts = new Map((best?.facets.age ?? []).map((o) => [o.value, o.count]));
    const ageTiles = t.home.ageTiles as Record<string, string>;
    const seeAll = (href: string, section: string) => ({ href: localizedPath(locale, href), label: t.home.seeAll, ariaLabel: format(t.home.seeAllLabel, { section }) });
    const toys = (count: number | undefined) => (count === undefined ? undefined : count === 1 ? t.home.toyCountOne : format(t.home.toyCount, { count: String(count) }));

    return (
        <PageShell locale={locale} path="/" announcement mobileSearch top="tight" bleed>
            <div className="flex flex-col gap-12 lg:gap-20">
                <Band>
                    <HomeHero locale={locale} t={t.home} />
                </Band>

                {categories.length > 0 ? (
                    <Band>
                        <section aria-labelledby="home-categories" className="flex flex-col gap-4 lg:gap-6">
                            <SectionHeader id="home-categories" title={t.home.categoriesTitle} action={seeAll('/categories', t.home.categoriesTitle)} />
                            <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-6">
                                {categories.map((node, i) => (
                                    <li key={node.slug}>
                                        <CategoryTile node={node} locale={locale} index={i} />
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </Band>
                ) : null}

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
                        <ProductRail id="home-best" title={t.home.bestSellers} products={best.products.slice(0, 8)} locale={locale} t={t.listing} action={seeAll('/toys', t.home.bestSellers)} />
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
                        />
                    </Band>
                ) : null}

                <UspBand t={t.home} />
            </div>
        </PageShell>
    );
}
