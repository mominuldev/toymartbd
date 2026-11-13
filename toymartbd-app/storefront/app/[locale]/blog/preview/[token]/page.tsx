import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { cache } from 'react';

import { renderPost } from '@/components/blog/pages';
import { isLocale, type Locale } from '@/lib/i18n';
import { getPreview } from '@/lib/blog/fetch';
import { blogMessages } from '@/lib/blog/messages';
import { noindexMetadata } from '@/lib/seo/metadata';

// Private preview of any post (draft, scheduled) by a signed, expiring token minted in the admin
// (App\Support\PreviewToken). Never cached, never indexed, no JSON-LD.

export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ locale: string; token: string }>;
}

const load = cache(async (token: string, locale: Locale) => getPreview(token, locale));

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return noindexMetadata('Preview');
    return noindexMetadata(blogMessages(locale).previewTitle);
}

export default async function BlogPreviewPage({ params }: Props) {
    const { locale, token } = await params;
    if (!isLocale(locale)) notFound();
    const post = await load(decodeURIComponent(token), locale);
    if (!post) notFound();
    return renderPost(post, locale, post.type === 'buying_guide' ? 'guides' : 'blog', { preview: true });
}
