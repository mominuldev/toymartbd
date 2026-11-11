import './listing.css';

import { Icon } from '@/components/icons/Icon';
import { format, localizedPath, type Locale, type Messages } from '@/lib/i18n';
import type { Listing } from '@/lib/listing/response';
import { activeCount, cleared, hrefFor, withCursor, type ListingScope, type ListingState } from '@/lib/listing/state';
import { ActiveFilterChips } from './ActiveFilterChips';
import { FilterAutoApply } from './FilterAutoApply';
import { FilterForm } from './FilterForm';
import { ProductCard } from './ProductCard';
import { SortMenu } from './SortMenu';

// Category / brand listing (P4-STO-03) — Figma "Category listing" 129:1572 (390) and 136:2172
// (1440); the brand page uses the same layout. Server-rendered from the URL: the filtered grid is
// in the first HTML, with no flash of unfiltered content (§A7.5).

export interface Crumb {
    label: string;
    /** Locale-independent path; null for the current page. */
    path: string | null;
    lang?: 'en';
}

export interface SubLink {
    label: string;
    href: string;
    selected: boolean;
    lang?: 'en';
}

interface Props {
    locale: Locale;
    t: Messages['listing'];
    /** Locale-independent listing path (`/categories/lego`). */
    path: string;
    scope: ListingScope;
    state: ListingState;
    listing: Listing;
    title: string;
    titleLang?: 'en';
    intro: string | null;
    crumbs: Crumb[];
    subLinks: SubLink[];
    emptyHref: string;
}

const SIDEBAR_FORM = 'listing-filters-desktop';
const SHEET_FORM = 'listing-filters-mobile';

export function ListingView({ locale, t, path, scope, state, listing, title, titleLang, intro, crumbs, subLinks, emptyHref }: Props) {
    const href = localizedPath(locale, path);
    const filters = activeCount(state);
    const { products, total, facets, nextCursor } = listing;
    const formProps = { locale, path, scope, state, facets, t };
    const countLine = format(t.countOf, { shown: String(products.length), total: String(total) });
    const status = nextCursor || state.cursor ? format(t.showing, { shown: String(products.length), total: String(total) }) : format(t.showingAll, { total: String(total) });

    return (
        <div className="flex flex-col">
            {/* PageHead */}
            <nav aria-label={t.breadcrumb} className="overflow-x-auto overflow-y-hidden">
                <ol className="flex min-h-6 items-center gap-2 whitespace-nowrap">
                    {crumbs.map((crumb, i) => (
                        <li key={`${crumb.path ?? 'current'}-${i}`} className="flex items-center gap-2">
                            {i > 0 ? <Icon name="caretRight" className="size-3.5 text-fg-tertiary" /> : null}
                            {crumb.path === null ? (
                                <a href={href} aria-current="page" lang={crumb.lang} className="t-body-sm-strong text-fg-secondary">
                                    {crumb.label}
                                </a>
                            ) : (
                                <a href={localizedPath(locale, crumb.path)} lang={crumb.lang} className="t-body-sm text-fg-link underline-offset-2 hover:underline">
                                    {crumb.label}
                                </a>
                            )}
                        </li>
                    ))}
                </ol>
            </nav>
            <h1 className="t-h1 mt-2.5 text-fg-primary lg:mt-5" lang={titleLang}>
                {title}
            </h1>
            {intro ? <p className="t-body-sm mt-2.5 max-w-210 text-fg-secondary lg:mt-5">{intro}</p> : null}
            {subLinks.length > 0 ? (
                <nav aria-label={t.subcategories} className="-mx-gutter-mobile mt-2.5 overflow-x-auto px-gutter-mobile md:mx-0 md:px-0 lg:mt-5">
                    <ul className="flex gap-2 pb-1 lg:flex-wrap lg:gap-3">
                        {subLinks.map((link) => (
                            <li key={link.href}>
                                <a
                                    href={link.href}
                                    lang={link.lang}
                                    aria-current={link.selected ? 'page' : undefined}
                                    className={`t-chip flex h-control-sm min-w-14 items-center justify-center whitespace-nowrap rounded-full border px-3 text-fg-primary ${link.selected ? 'border-brand bg-brand-subtle' : 'border-line-default bg-surface hover:border-line-strong'}`}
                                >
                                    {link.label}
                                </a>
                            </li>
                        ))}
                    </ul>
                </nav>
            ) : null}

            {/* FilterBar — mobile and tablet (<1024): sticky under the header. */}
            <div className="sticky top-14 z-20 -mx-gutter-mobile has-[.filter-sheet[open]]:z-50 mt-4 flex items-center gap-3 border-b border-line-brand-subtle bg-surface px-gutter-mobile py-2 md:-mx-gutter-tablet md:px-gutter-tablet lg:hidden">
                <details className="filter-sheet min-w-0 flex-1">
                    <summary className="flex h-control-md cursor-pointer list-none items-center justify-center gap-2 rounded-full border-(length:--tm-border-width-control) border-line-brand-subtle bg-surface px-4 text-fg-primary [&::-webkit-details-marker]:hidden">
                        <Icon name="funnel" className="size-4.5" />
                        <span className="t-body-sm-strong">{t.filters}</span>
                        {filters > 0 ? (
                            <span className="t-badge flex h-5 min-w-5 items-center justify-center rounded-full bg-brand px-1.5 text-fg-on-brand">{filters}</span>
                        ) : null}
                    </summary>
                    <a href={hrefFor(href, state)} aria-label={t.close} className="fixed inset-0 z-40 bg-overlay" />
                    <div role="dialog" aria-modal="true" aria-labelledby="filter-sheet-title" className="filter-sheet-panel fixed inset-x-0 bottom-0 z-50 flex max-h-[88dvh] flex-col rounded-t-2xl bg-surface shadow-e4">
                        <div className="mx-auto mt-3 h-1 w-9 rounded-full bg-line-strong" aria-hidden="true" />
                        <div className="flex h-14 items-center justify-between gap-3 px-4">
                            <h2 id="filter-sheet-title" className="t-h3 text-fg-primary">
                                {t.filters}
                            </h2>
                            <div className="flex items-center gap-1">
                                {filters > 0 ? (
                                    <a href={hrefFor(href, cleared(state))} className="t-body-sm-strong flex h-touch-min items-center px-2 text-fg-brand">
                                        {t.clearAll}
                                    </a>
                                ) : null}
                                <a href={hrefFor(href, state)} aria-label={t.close} className="flex size-touch-min items-center justify-center rounded-full text-fg-primary hover:bg-subtle">
                                    <Icon name="x" className="size-icon-lg" />
                                </a>
                            </div>
                        </div>
                        <div className="min-h-0 grow overflow-y-auto px-4">
                            <FilterForm id={SHEET_FORM} size="mobile" {...formProps}>
                                <div className="sticky bottom-0 -mx-4 mt-2 border-t border-line-default bg-surface p-4">
                                    <button type="submit" className="t-chip flex h-control-lg w-full items-center justify-center rounded-full bg-(image:--tm-gradient-button-primary) px-6 text-fg-on-brand">
                                        {t.apply}
                                    </button>
                                </div>
                            </FilterForm>
                        </div>
                    </div>
                </details>
                <SortMenu href={href} state={state} t={t} context="mobile" />
            </div>

            {/* Toolbar — desktop */}
            <div className="mt-5 hidden items-center justify-between lg:flex">
                <p className="t-body-sm text-fg-secondary" aria-live="polite">
                    {countLine}
                </p>
                <SortMenu href={href} state={state} t={t} context="desktop" />
            </div>

            <div className="mt-3 lg:mt-5">
                <ActiveFilterChips href={href} state={state} facets={facets} locale={locale} t={t} />
            </div>
            <p className="t-body-sm mt-3 text-fg-secondary lg:hidden">{countLine}</p>

            <div className="mt-3 lg:mt-5 lg:grid lg:grid-cols-[17rem_minmax(0,1fr)] lg:items-start lg:gap-8">
                <aside aria-labelledby="filter-sidebar-title" className="sticky top-34 hidden max-h-[calc(100dvh-9.5rem)] overflow-y-auto rounded-sm border-(length:--tm-border-width-control) border-line-brand-subtle bg-surface p-5 shadow-e1 lg:block">
                    <div className="flex items-center justify-between">
                        <h2 id="filter-sidebar-title" className="t-h3 text-fg-primary">
                            {t.filters}
                        </h2>
                        {filters > 0 ? (
                            <a href={hrefFor(href, cleared(state))} className="t-body-sm-strong text-fg-brand">
                                {t.clearAll}
                            </a>
                        ) : (
                            <span aria-disabled="true" className="t-body-sm-strong text-fg-disabled">
                                {t.clearAll}
                            </span>
                        )}
                    </div>
                    <p className="t-caption text-fg-secondary" aria-live="polite">
                        {format(t.products, { count: String(total) })}
                    </p>
                    <FilterForm id={SIDEBAR_FORM} size="desktop" {...formProps} />
                    <FilterAutoApply formId={SIDEBAR_FORM} />
                </aside>

                <section aria-label={title}>
                    {products.length > 0 ? (
                        <>
                            <ul className="grid grid-cols-2 gap-x-3 gap-y-4 md:grid-cols-3 md:gap-x-4 lg:gap-x-6 lg:gap-y-8 2xl:grid-cols-4">
                                {products.map((product, i) => (
                                    <li key={product.id}>
                                        <ProductCard product={product} locale={locale} t={t} priority={i < 2} />
                                    </li>
                                ))}
                            </ul>
                            <div className="mx-auto flex w-full max-w-90 flex-col items-center gap-3 pt-8 pb-12 lg:max-w-none">
                                <p className="t-body-sm text-center text-fg-secondary">{status}</p>
                                {nextCursor ? (
                                    <a
                                        href={hrefFor(href, withCursor(state, nextCursor))}
                                        rel="next"
                                        className="t-button flex h-control-lg w-full min-w-32 items-center justify-center rounded-full border-(length:--tm-border-width-strong) border-line-brand-subtle bg-surface px-6 text-fg-brand hover:bg-brand-subtle lg:w-70"
                                    >
                                        {t.loadMore}
                                    </a>
                                ) : null}
                            </div>
                        </>
                    ) : (
                        <div className="flex flex-col items-start gap-4 rounded-sm border-(length:--tm-border-width-control) border-line-brand-subtle bg-surface p-6 shadow-e1">
                            <Icon name="puzzlePiece" className="size-icon-category text-fg-brand" />
                            <h2 className="t-h3 text-fg-primary">{filters > 0 ? t.emptyTitle : t.emptyNoProducts}</h2>
                            {filters > 0 ? <p className="t-body text-fg-secondary">{t.emptyBody}</p> : null}
                            <a
                                href={filters > 0 ? hrefFor(href, cleared(state)) : localizedPath(locale, emptyHref)}
                                className="t-chip flex h-control-md items-center justify-center rounded-full bg-(image:--tm-gradient-button-primary) px-5 text-fg-on-brand"
                            >
                                {filters > 0 ? t.emptyClear : t.emptyBrowse}
                            </a>
                        </div>
                    )}
                </section>
            </div>
        </div>
    );
}
