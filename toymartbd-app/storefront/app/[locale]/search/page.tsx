import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { PageShell } from '@/components/PageShell';
import { SearchBar } from '@/components/layout/SearchBar';
import { format, getMessages, isLocale, localizedPath } from '@/lib/i18n';
import { alternatesFor } from '@/lib/seo';

// STUB until P4-STO-04 (search results + zero results). The header search bar submits here
// (GET /search?q= and /bn/search?q=); this page echoes the query and points back into the catalog.
// It reads searchParams, so it renders per request, and it is noindex (result pages are not a
// landing page until search exists).

interface Props {
    params: Promise<{ locale: string }>;
    searchParams: Promise<Record<string, string | string[] | undefined>>;
}

// Per-request: the root layout's empty generateStaticParams would otherwise make this an ISR
// route, and reading searchParams there fails the render (DYNAMIC_SERVER_USAGE).
export const dynamic = 'force-dynamic';

const MAX_QUERY = 100;

function queryOf(value: string | string[] | undefined): string {
    const raw = Array.isArray(value) ? value[0] : value;
    return (raw ?? '').trim().slice(0, MAX_QUERY);
}

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    const t = getMessages(locale);
    const q = queryOf((await searchParams).q);
    return {
        title: q ? format(t.search.resultsTitle, { query: q }) : t.search.pageTitle,
        description: t.search.metaDescription,
        robots: { index: false, follow: true },
        alternates: alternatesFor(locale, '/search'),
    };
}

export default async function SearchPage({ params, searchParams }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const t = getMessages(locale);
    const q = queryOf((await searchParams).q);
    const path = q ? `/search?${new URLSearchParams({ q }).toString()}` : '/search';

    return (
        <PageShell locale={locale} path={path} searchQuery={q}>
            <h1 className="t-h1 text-fg-primary">{q ? format(t.search.resultsTitle, { query: q }) : t.search.pageTitle}</h1>
            <p className="t-body mt-4 max-w-prose text-fg-secondary">{q ? t.search.comingSoon : t.search.emptyQuery}</p>
            {!q ? (
                <div className="mt-6 max-w-140">
                    <SearchBar locale={locale} id="search-page" />
                </div>
            ) : null}
            <a
                href={localizedPath(locale, '/categories')}
                className="t-button mt-8 inline-flex min-h-touch-min items-center rounded-full bg-brand px-6 text-fg-on-brand shadow-e1"
            >
                {t.notFound.browse}
            </a>
        </PageShell>
    );
}
