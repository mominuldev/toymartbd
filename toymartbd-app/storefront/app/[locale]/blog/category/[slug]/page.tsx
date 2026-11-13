import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { cache } from 'react';

import { listMetadata, renderList, termSpec } from '@/components/blog/pages';
import { isLocale, type Locale } from '@/lib/i18n';
import { getTerm } from '@/lib/blog/fetch';
import { getSeo } from '@/lib/seo/fetch';
import { parsePage } from '@/lib/blog/format';

// Blog category archive (Phase 7): the category's live posts, numbered pages. 404 for an unknown category or
// one with no live post (an empty archive is not a page). ISR by tag `content:blog`.

interface Props {
    params: Promise<{ locale: string; slug: string }>;
    searchParams: Promise<{ page?: string | string[] }>;
}

const load = cache(async (slug: string, locale: Locale) => {
    const term = await getTerm('category', slug, locale);
    return term && term.posts_count > 0 ? term : null;
});

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
    const { locale, slug } = await params;
    const page = parsePage((await searchParams).page);
    if (!isLocale(locale) || page === null) return {};
    const term = await load(slug, locale);
    if (!term) return {};
    return listMetadata({ ...termSpec('category', term, locale, page), seo: await getSeo('blog_category', slug) });
}

export default async function BlogCategoryPage({ params, searchParams }: Props) {
    const { locale, slug } = await params;
    const page = parsePage((await searchParams).page);
    if (!isLocale(locale) || page === null) notFound();
    const term = await load(slug, locale);
    if (!term) notFound();
    return renderList(termSpec('category', term, locale, page));
}
