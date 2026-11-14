import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { listMetadata, renderList } from '@/components/blog/pages';
import { isLocale } from '@/lib/i18n';
import { parsePage } from '@/lib/blog/format';
import { blogMessages } from '@/lib/blog/messages';

// Blog index (Phase 7): every live post and guide, newest first, numbered pages, category chips.
// ISR by tag `content:blog` (lib/blog/fetch.ts). No Figma frame for this page yet.

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
    return listMetadata({ locale, path: '/blog', page, title: t.title, description: t.metaDescription });
}

export default async function BlogIndexPage({ params, searchParams }: Props) {
    const { locale } = await params;
    const page = parsePage((await searchParams).page);
    if (!isLocale(locale) || page === null) notFound();
    const t = blogMessages(locale);
    return renderList({
        locale,
        path: '/blog',
        page,
        title: t.title,
        intro: t.intro,
        description: t.metaDescription,
        crumbs: [{ label: t.home, path: '/' }, { label: t.title, path: null }],
        query: {},
        chips: true,
    });
}
