import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { cache } from 'react';

import { CategoryBanner } from '@/components/content/CategoryBanner';
import { ListingView } from '@/components/listing/ListingView';
import { getBanners } from '@/lib/content/api';
import { PageShell } from '@/components/PageShell';
import { categoryPath } from '@/lib/category-tree';
import { format, getMessages, isLocale, localizedPath, type Locale } from '@/lib/i18n';
import { JsonLd } from '@/components/seo/JsonLd';
import { getSeo } from '@/lib/seo/fetch';
import { breadcrumbJsonLd } from '@/lib/seo/jsonld';
import { listingPageMetadata } from '@/lib/seo/metadata';
import { hrefFor, parseListingState, type ListingScope, type RawSearchParams } from '@/lib/listing/state';
import { fetchListing, getCategoryPage } from '@/lib/products';

// Category listing (P4-STO-03): the category (GET /storefront/categories/{slug}, tags `categories`
// + `category:{slug}`) and its products with facets (GET /storefront/products, tags `products` +
// `category:{slug}`). Rendered per request from the URL's filters; both fetches are tag-cached, so
// a product or category write purges them (docs/api/revalidation.md). Unknown slug → 404.

interface Props {
    params: Promise<{ locale: string; slug: string }>;
    searchParams: Promise<RawSearchParams>;
}

const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

const loadCategory = cache(async (slug: string, locale: Locale) => (SLUG.test(slug) ? getCategoryPage(slug, locale) : null));

function localised(locale: Locale, name: string, nameBn: string | null): { text: string; lang?: 'en' } {
    return locale === 'bn' && nameBn ? { text: nameBn } : locale === 'bn' ? { text: name, lang: 'en' } : { text: name };
}

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
    const { locale, slug } = await params;
    if (!isLocale(locale)) return {};
    const [category, seo] = await Promise.all([loadCategory(slug, locale), getSeo('category', slug)]);
    if (!category) return {};
    const name = localised(locale, category.name, category.name_bn).text;
    const scope: ListingScope = { kind: 'category', slug };
    const state = parseListingState(await searchParams, scope);
    return listingPageMetadata({
        locale,
        path: categoryPath(slug),
        state,
        // A cursor page: the same (deduplicated) listing fetch the page makes, to spot a stale cursor.
        listing: state.cursor ? await fetchListing(state, scope, locale) : null,
        title: name,
        description: (locale === 'bn' ? category.description_bn : category.description) ?? format(getMessages(locale).listing.metaCategory, { category: name }),
        seo,
    });
}

export default async function CategoryPage({ params, searchParams }: Props) {
    const { locale, slug } = await params;
    if (!isLocale(locale)) notFound();
    const category = await loadCategory(slug, locale);
    if (!category) notFound();

    const t = getMessages(locale).listing;
    const scope: ListingScope = { kind: 'category', slug };
    const state = parseListingState(await searchParams, scope);
    const listing = await fetchListing(state, scope, locale);
    // The top live category-page banner for this category (or for every category); tag content:banners.
    const [banner] = await getBanners('category_page', 'general', slug);
    const title = localised(locale, category.name, category.name_bn);
    const path = categoryPath(slug);
    // Sub-category chips keep the active filters (minus paging) when moving down the tree.
    const keep = { ...state, cursor: null };

    return (
        <PageShell locale={locale} path={hrefFor(path, state)} top="crumb" mobileSearch>
            <CategoryBanner locale={locale} banner={banner} />
            <JsonLd
                data={breadcrumbJsonLd(locale, [
                    { name: t.home, path: '/' },
                    ...category.breadcrumbs.map((crumb) => ({ name: localised(locale, crumb.name, crumb.name_bn).text, path: categoryPath(crumb.slug) })),
                ])}
            />
            <ListingView
                locale={locale}
                t={t}
                path={path}
                scope={scope}
                state={state}
                listing={listing}
                title={title.text}
                titleLang={title.lang}
                intro={(locale === 'bn' ? (category.description_bn ?? category.description) : category.description) ?? null}
                crumbs={[
                    { label: t.home, path: '/' },
                    ...category.breadcrumbs.map((crumb, i, all) => {
                        const label = localised(locale, crumb.name, crumb.name_bn);
                        return { label: label.text, lang: label.lang, path: i === all.length - 1 ? null : categoryPath(crumb.slug) };
                    }),
                ]}
                subLinks={
                    category.children.length > 0
                        ? [
                              { label: t.all, href: hrefFor(localizedPath(locale, path), keep), selected: true },
                              ...category.children.map((child) => {
                                  const label = localised(locale, child.name, child.name_bn);
                                  return { label: label.text, lang: label.lang, href: hrefFor(localizedPath(locale, categoryPath(child.slug)), keep), selected: false };
                              }),
                          ]
                        : []
                }
                emptyHref="/categories"
            />
        </PageShell>
    );
}
