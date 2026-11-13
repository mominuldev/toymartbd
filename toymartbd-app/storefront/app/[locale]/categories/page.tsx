import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { CategoryLinks } from '@/components/CategoryLinks';
import { MobileMenuLinks } from '@/components/content/MobileMenuLinks';
import { PageShell } from '@/components/PageShell';
import { getMenus } from '@/lib/content/api';
import { fetchCategoryTree } from '@/lib/categories';
import { getMessages, isLocale } from '@/lib/i18n';
import { staticMetadata } from '@/lib/seo/metadata';

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
    return staticMetadata(locale, '/categories', t.categories.title, t.categories.metaDescription);
}

export default async function CategoriesPage({ params }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const t = getMessages(locale);
    const tree = await fetchCategoryTree();
    // The header's menu button lands here on mobile: the published mobile menu (if any) comes first.
    const { mobile } = await getMenus();

    return (
        <PageShell locale={locale} path="/categories">
            <h1 className="t-h1 text-fg-primary">{t.categories.title}</h1>
            {mobile ? <MobileMenuLinks locale={locale} items={mobile} /> : null}
            <div className="mt-8">
                {tree.length === 0 ? <p className="t-body text-fg-secondary">{t.categories.empty}</p> : <CategoryLinks nodes={tree} locale={locale} />}
            </div>
        </PageShell>
    );
}
