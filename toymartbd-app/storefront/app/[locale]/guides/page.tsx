import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { listMetadata, renderList } from '@/components/blog/pages';
import { isLocale } from '@/lib/i18n';
import { parsePage } from '@/lib/blog/format';
import { blogMessages } from '@/lib/blog/messages';

// Buying guides (Phase 7): live posts of type `buying_guide`, newest first, numbered pages.

// Per-request: the root layout's empty generateStaticParams would otherwise make this an ISR
// route, and reading searchParams (`?page=`) there fails the render with a 500
// (DYNAMIC_SERVER_USAGE) — found by the Phase 9 accessibility sweep. The API reads stay cached
// under `content:blog` (lib/blog/fetch.ts).
export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ locale: string }>;
    searchParams: Promise<{ page?: string | string[] }>;
}

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
    const { locale } = await params;
    const page = parsePage((await searchParams).page);
    if (!isLocale(locale) || page === null) return {};
    const t = blogMessages(locale);
    return listMetadata({ locale, path: '/guides', page, title: t.guidesTitle, description: t.guidesMeta });
}

export default async function GuidesPage({ params, searchParams }: Props) {
    const { locale } = await params;
    const page = parsePage((await searchParams).page);
    if (!isLocale(locale) || page === null) notFound();
    const t = blogMessages(locale);
    return renderList({
        locale,
        path: '/guides',
        page,
        title: t.guidesTitle,
        intro: t.guidesIntro,
        description: t.guidesMeta,
        crumbs: [{ label: t.home, path: '/' }, { label: t.guidesTitle, path: null }],
        query: { type: 'buying_guide' },
    });
}
