import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { ListingView, type SubLink } from '@/components/listing/ListingView';
import { ProductRail } from '@/components/listing/ProductRail';
import { PageShell } from '@/components/PageShell';
import { SearchBar } from '@/components/layout/SearchBar';
import { EmptyState } from '@/components/search/EmptyState';
import { Pagination } from '@/components/search/Pagination';
import { Icon } from '@/components/icons/Icon';
import { format, getMessages, isLocale, localizedPath, type Locale, type Messages } from '@/lib/i18n';
import { activeCount, cleared, hrefFor, parseListingState, withCategory, type ListingScope, type RawSearchParams } from '@/lib/listing/state';
import { fetchSearch } from '@/lib/products';
import { pageHref, parsePage, parseQuery, searchPath } from '@/lib/search/query';
import type { SearchResults } from '@/lib/search/response';
import { searchMetadata } from '@/lib/seo/metadata';

// Search results and zero results (P4-STO-04) — Figma 161:15289 (1440) and 132:3269 (390).
// The header search bar submits GET /search?q= (and /bn/search?q=), so this works without
// JavaScript and every result view — filters, sort, page — has a shareable URL. Results come
// from GET /storefront/search (P4-CAT-14: typo-tolerant, Bangla/Banglish synonyms) and reuse the
// listing layout: filters, chips, sort and ProductCard. A query with no hits keeps the visitor
// moving (storefront spec B6.3): spelling suggestions from the index, popular searches, and the
// API's best sellers as "Popular right now". Result pages are noindex.

interface Props {
    params: Promise<{ locale: string }>;
    searchParams: Promise<RawSearchParams>;
}

// Per-request: the root layout's empty generateStaticParams would otherwise make this an ISR
// route, and reading searchParams there fails the render (DYNAMIC_SERVER_USAGE).
export const dynamic = 'force-dynamic';

const SCOPE: ListingScope = { kind: 'search' };

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    const t = getMessages(locale);
    const q = parseQuery((await searchParams).q);
    return searchMetadata(locale, q ? format(t.search.resultsTitle, { query: q }) : t.search.pageTitle, t.search.metaDescription);
}

function relatedLinks(locale: Locale, base: string, results: SearchResults, state: ReturnType<typeof parseListingState>, t: Messages): SubLink[] {
    if (results.related.length === 0) return [];
    return [
        { label: t.search.allResults, href: hrefFor(base, withCategory(state, null)), selected: state.category === null },
        ...results.related.map((r) => {
            const bn = locale === 'bn' && r.labelBn;
            return {
                label: bn ? r.labelBn! : r.label,
                lang: locale === 'bn' && !r.labelBn ? ('en' as const) : undefined,
                href: hrefFor(base, withCategory(state, r.category)),
                selected: state.category === r.category,
            };
        }),
    ];
}

export default async function SearchPage({ params, searchParams }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const t = getMessages(locale);
    const raw = await searchParams;
    const q = parseQuery(raw.q);

    if (!q) {
        return (
            <PageShell locale={locale} path="/search" mobileSearch>
                <h1 className="t-h1 text-fg-primary">{t.search.pageTitle}</h1>
                <p className="t-body mt-4 max-w-prose text-fg-secondary">{t.search.emptyQuery}</p>
                <div className="mt-6 max-w-140">
                    <SearchBar locale={locale} id="search-page" />
                </div>
            </PageShell>
        );
    }

    const state = parseListingState(raw, SCOPE);
    const page = parsePage(raw.page);
    const results = await fetchSearch(q, state, page, locale);
    // A related-search category is a filter like any other; make sure its chip has a name even
    // when the category facet lists only the selected category's children.
    const known = new Set(results.listing.facets.category.map((o) => o.value));
    const listing = {
        ...results.listing,
        facets: {
            ...results.listing.facets,
            category: [
                ...results.listing.facets.category,
                ...results.related.filter((r) => !known.has(r.category)).map((r) => ({ value: r.category, label: r.label, labelBn: r.labelBn, count: r.count, selected: r.selected })),
            ],
        },
    };
    const total = listing.total;
    const base = localizedPath(locale, searchPath(q));
    const shellPath = pageHref(searchPath(q), state, results.page);
    const title = format(t.search.resultsTitle, { query: q });
    const crumbs = [
        { label: t.listing.home, path: '/' },
        { label: t.search.crumb, path: null },
    ];
    const filters = activeCount(state);
    const popular =
        results.bestSellers.length > 0 ? (
            <div className="mt-section-mobile lg:mt-5">
                <ProductRail
                    id="search-popular"
                    title={t.search.popularNow}
                    products={results.bestSellers}
                    locale={locale}
                    t={t.listing}
                    action={{ href: localizedPath(locale, '/toys'), label: t.search.seeAll, ariaLabel: format(t.home.seeAllLabel, { section: t.search.popularNow }) }}
                />
            </div>
        ) : null;

    // Zero hits for the text itself: the toolbar, filters and grid give way to the empty state.
    if (total === 0 && filters === 0) {
        const extra =
            results.suggestions.length > 0
                ? { heading: t.search.didYouMean, chips: results.suggestions.slice(0, 4).map((s) => ({ label: s.query, href: localizedPath(locale, searchPath(s.query)) })) }
                : { heading: t.search.popularSearches, chips: Object.values(t.search.popular).map((label) => ({ label, href: localizedPath(locale, searchPath(label)) })) };
        return (
            <PageShell locale={locale} path={shellPath} searchQuery={q} top="crumb">
                <nav aria-label={t.listing.breadcrumb} className="max-lg:hidden">
                    <ol className="flex min-h-6 items-center gap-2 whitespace-nowrap">
                        <li>
                            <a href={localizedPath(locale, '/')} className="t-body-sm text-fg-link underline-offset-2 hover:underline">
                                {t.listing.home}
                            </a>
                        </li>
                        <li className="flex items-center gap-2">
                            <Icon name="caretRight" className="size-3.5 text-fg-tertiary" />
                            <a href={base} aria-current="page" className="t-body-sm-strong text-fg-secondary">
                                {t.search.crumb}
                            </a>
                        </li>
                    </ol>
                </nav>
                <h1 className="t-h1 mt-2.5 text-fg-primary max-lg:sr-only lg:mt-5">{title}</h1>
                <p className="sr-only" aria-live="polite">
                    {t.search.noResults}
                </p>
                <section aria-label={t.search.zeroLabel} className="mt-2 lg:mt-5">
                    <EmptyState
                        title={format(t.search.zeroTitle, { query: q })}
                        body={t.search.zeroBody}
                        primary={{ label: t.search.clearSearch, href: localizedPath(locale, '/search') }}
                        secondary={{ label: t.search.browseAll, href: localizedPath(locale, '/toys') }}
                        extra={extra}
                    />
                </section>
                {popular}
            </PageShell>
        );
    }

    const listingT = { ...t.listing, countOf: t.search.countOf };
    const mobileHead = (
        <p className="flex flex-wrap items-baseline gap-x-1.5" aria-live="polite">
            <span className="t-h2 text-fg-primary">{total === 1 ? t.search.countHeadOne : format(t.search.countHead, { count: String(total) })}</span>
            <span className="t-body-sm text-fg-secondary">{format(t.search.forQuery, { query: q })}</span>
        </p>
    );

    return (
        <PageShell locale={locale} path={shellPath} searchQuery={q} top="crumb">
            <ListingView
                locale={locale}
                t={listingT}
                path="/search"
                query={q}
                scope={SCOPE}
                state={state}
                listing={listing}
                title={title}
                intro={total === 1 ? t.search.introOne : format(t.search.intro, { count: String(total) })}
                crumbs={crumbs}
                subLinks={relatedLinks(locale, base, results, state, t)}
                emptyHref="/toys"
                mobileHead={mobileHead}
                pagination={<Pagination base={base} state={state} page={results.page} lastPage={results.lastPage} from={results.from} to={results.to} total={total} t={t.search} />}
                empty={
                    <EmptyState
                        title={t.search.filteredZeroTitle}
                        body={t.search.filteredZeroBody}
                        primary={{ label: t.search.clearFilters, href: hrefFor(base, cleared(state)) }}
                        secondary={{ label: t.search.shopByAge, href: `${localizedPath(locale, '/')}#shop-by-age` }}
                    />
                }
            />
            {total === 0 ? popular : null}
        </PageShell>
    );
}
