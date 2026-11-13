import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { cache } from 'react';

import { ListingView } from '@/components/listing/ListingView';
import { PageShell } from '@/components/PageShell';
import { brandDescription, brandName, brandNameIsFallback, getBrand } from '@/lib/brands';
import { format, getMessages, isLocale, localizedPath, type Locale } from '@/lib/i18n';
import { JsonLd } from '@/components/seo/JsonLd';
import { getSeo } from '@/lib/seo/fetch';
import { breadcrumbJsonLd } from '@/lib/seo/jsonld';
import { listingPageMetadata } from '@/lib/seo/metadata';
import { hrefFor, parseListingState, withCategory, type ListingScope, type RawSearchParams } from '@/lib/listing/state';
import { fetchListing, getCategoryPage } from '@/lib/products';

// Brand page = the brand's product listing (P4-STO-03), same layout as a category listing. The
// brand comes from GET /storefront/brands/{slug} (tags `brands` + `brand:{slug}`); its products
// from GET /storefront/products?filter[brand]={slug} (tags `products` + `brand:{slug}`). The
// sub-category chips narrow to one top-level category (`?category=`). Unknown slug → 404.

interface Props {
    params: Promise<{ locale: string; slug: string }>;
    searchParams: Promise<RawSearchParams>;
}

const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

// One API call per request, shared by generateMetadata and the page.
const load = cache(async (slug: string, locale: Locale) => (SLUG.test(slug) ? getBrand(slug, locale) : null));

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
    const { locale, slug } = await params;
    if (!isLocale(locale)) return {};
    const [brand, seo] = await Promise.all([load(slug, locale), getSeo('brand', slug)]);
    if (!brand) return {};
    const name = brandName(brand, locale);
    const scope: ListingScope = { kind: 'brand', slug };
    const state = parseListingState(await searchParams, scope);
    return listingPageMetadata({
        locale,
        path: `/brands/${slug}`,
        state,
        // A cursor page: the same (deduplicated) listing fetch the page makes, to spot a stale cursor.
        listing: state.cursor ? await fetchListing(state, scope, locale) : null,
        title: name,
        description: brandDescription(brand, locale) ?? format(getMessages(locale).listing.metaBrand, { brand: name }),
        seo,
    });
}

export default async function BrandPage({ params, searchParams }: Props) {
    const { locale, slug } = await params;
    if (!isLocale(locale)) notFound();
    const brand = await load(slug, locale);
    if (!brand) notFound();

    const messages = getMessages(locale);
    const t = messages.listing;
    const scope: ListingScope = { kind: 'brand', slug };
    const state = parseListingState(await searchParams, scope);
    const listing = await fetchListing(state, scope, locale);
    const path = `/brands/${slug}`;
    const href = localizedPath(locale, path);
    const name = brandName(brand, locale);
    const nameLang = brandNameIsFallback(brand, locale) ? 'en' : undefined;
    const categories = listing.facets.category.filter((c) => c.count > 0);
    // With `?category=` the facet lists that category's children; name the selected one for its chip.
    if (state.category && !listing.facets.category.some((c) => c.value === state.category)) {
        const selected = await getCategoryPage(state.category, locale);
        if (selected) {
            listing.facets.category.push({ value: selected.slug, label: selected.name, labelBn: selected.name_bn, count: listing.total, selected: true });
        }
    }

    return (
        <PageShell locale={locale} path={hrefFor(path, state)} top="crumb" mobileSearch>
            <JsonLd data={breadcrumbJsonLd(locale, [{ name: t.home, path: '/' }, { name: messages.brands.title, path: '/brands' }, { name, path }])} />
            <ListingView
                locale={locale}
                t={t}
                path={path}
                scope={scope}
                state={state}
                listing={listing}
                title={name}
                titleLang={nameLang}
                intro={brandDescription(brand, locale)}
                crumbs={[
                    { label: t.home, path: '/' },
                    { label: messages.brands.title, path: '/brands' },
                    { label: name, lang: nameLang, path: null },
                ]}
                subLinks={
                    categories.length > 0
                        ? [
                              { label: t.all, href: hrefFor(href, withCategory(state, null)), selected: state.category === null },
                              ...categories.map((c) => {
                                  const bn = locale === 'bn';
                                  return {
                                      label: bn && c.labelBn ? c.labelBn : (c.label ?? c.value),
                                      lang: bn && !c.labelBn ? ('en' as const) : undefined,
                                      href: hrefFor(href, withCategory(state, c.value)),
                                      selected: c.value === state.category,
                                  };
                              }),
                          ]
                        : []
                }
                emptyHref="/brands"
            />
        </PageShell>
    );
}
