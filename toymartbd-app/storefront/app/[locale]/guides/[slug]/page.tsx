import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { cache } from 'react';

import { postMetadata, renderPost } from '@/components/blog/pages';
import { isLocale, type Locale } from '@/lib/i18n';
import { getPost } from '@/lib/blog/fetch';

// A buying guide (Phase 7). ISR by tags `content:blog`, `post:{slug}` and `products` (its product cards
// carry live price and stock). A post of the other type redirects (308) to its own section; an
// old slug is redirected by the proxy from slug_history before this runs.

interface Props {
    params: Promise<{ locale: string; slug: string }>;
}

const load = cache(async (slug: string, locale: Locale) => getPost(slug, locale));

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale, slug } = await params;
    if (!isLocale(locale)) return {};
    const post = await load(slug, locale);
    return post ? postMetadata(post, locale) : {};
}

export default async function GuidePage({ params }: Props) {
    const { locale, slug } = await params;
    if (!isLocale(locale)) notFound();
    const post = await load(slug, locale);
    if (!post) notFound();
    return renderPost(post, locale, 'guides');
}
