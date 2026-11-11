import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { CategoryLinks } from '@/components/CategoryLinks';
import { PageShell } from '@/components/PageShell';
import { categoryName, categoryNameIsFallback, categoryPath, findCategory } from '@/lib/category-tree';
import { fetchCategoryTree } from '@/lib/categories';
import { format, getMessages, isLocale, localizedPath } from '@/lib/i18n';
import { alternatesFor } from '@/lib/seo';

// STUB until P4-STO-03 (category listing with filters). It exists so that every mega-menu link
// lands on a real page: the category's name, its parents and its sub-categories, read from the
// same cached tree as the menu (tag `categories`). No products yet. An unknown slug is a 404.

interface Props {
    params: Promise<{ locale: string; slug: string }>;
}

export function generateStaticParams(): Array<{ slug: string }> {
    return [];
}

async function load(slug: string) {
    return findCategory(await fetchCategoryTree(), slug);
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale, slug } = await params;
    if (!isLocale(locale)) return {};
    const found = await load(slug);
    if (!found) return {};
    const name = categoryName(found.node, locale);
    return {
        title: name,
        description: format(getMessages(locale).categories.metaCategory, { category: name }),
        alternates: alternatesFor(locale, categoryPath(slug)),
    };
}

export default async function CategoryPage({ params }: Props) {
    const { locale, slug } = await params;
    if (!isLocale(locale)) notFound();
    const found = await load(slug);
    if (!found) notFound();
    const t = getMessages(locale);
    const { node, trail } = found;

    return (
        <PageShell locale={locale} path={categoryPath(slug)}>
            <nav aria-label={t.categories.title}>
                <ol className="t-caption flex flex-wrap items-center gap-2 text-fg-secondary">
                    <li>
                        <a href={localizedPath(locale, '/categories')} className="underline underline-offset-4">
                            {t.nav.allCategories}
                        </a>
                    </li>
                    {trail.map((parent) => (
                        <li key={parent.slug} className="flex items-center gap-2">
                            <span aria-hidden="true">/</span>
                            <a
                                href={localizedPath(locale, categoryPath(parent.slug))}
                                lang={categoryNameIsFallback(parent, locale) ? 'en' : undefined}
                                className="underline underline-offset-4"
                            >
                                {categoryName(parent, locale)}
                            </a>
                        </li>
                    ))}
                </ol>
            </nav>
            <h1 className="t-h1 mt-4 text-fg-primary" lang={categoryNameIsFallback(node, locale) ? 'en' : undefined}>
                {categoryName(node, locale)}
            </h1>
            <p className="t-body mt-4 max-w-prose text-fg-secondary">{t.categories.comingSoon}</p>
            {node.children.length > 0 ? (
                <section aria-labelledby="in-this-category" className="mt-8">
                    <h2 id="in-this-category" className="t-h2 text-fg-primary">
                        {t.categories.inThisCategory}
                    </h2>
                    <div className="mt-4">
                        <CategoryLinks nodes={node.children} locale={locale} headingLevel={3} />
                    </div>
                </section>
            ) : null}
        </PageShell>
    );
}
