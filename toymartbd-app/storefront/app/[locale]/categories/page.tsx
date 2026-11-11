import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { CategoryLinks } from '@/components/CategoryLinks';
import { PageShell } from '@/components/PageShell';
import { fetchCategoryTree } from '@/lib/categories';
import { getMessages, isLocale } from '@/lib/i18n';
import { alternatesFor } from '@/lib/seo';

// All categories (the mobile "Categories" tab and every "browse all toys" link). ISR under tag
// `categories`. Uses the throwing fetch: if the API fails during a revalidation, Next keeps
// serving the last good page instead of caching an empty one.

interface Props {
    params: Promise<{ locale: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    const t = getMessages(locale);
    return { title: t.categories.title, description: t.categories.metaDescription, alternates: alternatesFor(locale, '/categories') };
}

export default async function CategoriesPage({ params }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const t = getMessages(locale);
    const tree = await fetchCategoryTree();

    return (
        <PageShell locale={locale} path="/categories">
            <h1 className="t-h1 text-fg-primary">{t.categories.title}</h1>
            <div className="mt-8">
                {tree.length === 0 ? <p className="t-body text-fg-secondary">{t.categories.empty}</p> : <CategoryLinks nodes={tree} locale={locale} />}
            </div>
        </PageShell>
    );
}
