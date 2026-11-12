import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { ListingView } from '@/components/listing/ListingView';
import { PageShell } from '@/components/PageShell';
import { getMessages, isLocale } from '@/lib/i18n';
import { listingMetadata } from '@/lib/listing/seo';
import { hrefFor, parseListingState, type ListingScope, type RawSearchParams } from '@/lib/listing/state';
import { fetchListing } from '@/lib/products';

// All toys — the whole catalogue as one listing (storefront spec §A7.5 `/toys?age=24-48`). It is
// where Home's "Browse all toys", age pills, age tiles and "See all" links land. Same layout and
// URL contract as a category page (Figma 129:1572 / 136:2172), with Category as a filter.
// Rendered per request from the URL's filters; the fetch is tag-cached under `products`.

interface Props {
    params: Promise<{ locale: string }>;
    searchParams: Promise<RawSearchParams>;
}

// Per-request (like /search): reading searchParams on an otherwise static route needs it.
export const dynamic = 'force-dynamic';

const SCOPE: ListingScope = { kind: 'all' };
const PATH = '/toys';

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    const t = getMessages(locale).listing;
    return {
        title: t.allToys,
        description: t.metaAll,
        ...listingMetadata(locale, PATH, parseListingState(await searchParams, SCOPE)),
    };
}

export default async function AllToysPage({ params, searchParams }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const t = getMessages(locale).listing;
    const state = parseListingState(await searchParams, SCOPE);
    const listing = await fetchListing(state, SCOPE, locale);

    return (
        <PageShell locale={locale} path={hrefFor(PATH, state)} top="crumb" mobileSearch>
            <ListingView
                locale={locale}
                t={t}
                path={PATH}
                scope={SCOPE}
                state={state}
                listing={listing}
                title={t.allToys}
                intro={t.allToysIntro}
                crumbs={[
                    { label: t.home, path: '/' },
                    { label: t.allToys, path: null },
                ]}
                subLinks={[]}
                emptyHref="/categories"
            />
        </PageShell>
    );
}
