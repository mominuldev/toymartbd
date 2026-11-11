import { SearchBar } from '@/components/layout/SearchBar';
import { categoryName, categoryNameIsFallback, categoryPath } from '@/lib/category-tree';
import { getCategoryTree } from '@/lib/categories';
import { getMessages, localizedPath, type Locale } from '@/lib/i18n';

// The 404 body (storefront spec §B8, content spec §C.7): reassurance, a search bar, a way home and
// the top-level categories, so a dead link is still a working entry point into the catalog.
// Used by both the route-level not-found and app/global-not-found.tsx.
export async function NotFoundContent({ locale }: { locale: Locale }) {
    const t = getMessages(locale);
    const categories = (await getCategoryTree()).slice(0, 8);

    return (
        <div className="flex flex-col gap-8">
            <section className="flex flex-col gap-4 rounded-2xl bg-tile-pink p-6 md:p-10">
                <p aria-hidden="true" lang="en" className="t-display-1 text-fg-brand">
                    404
                </p>
                <h1 className="t-h1 text-fg-primary">{t.notFound.title}</h1>
                <p className="t-body-lg max-w-prose text-fg-secondary">{t.notFound.body}</p>
                <div className="flex flex-wrap gap-3">
                    <a
                        href={localizedPath(locale, '/')}
                        className="t-button inline-flex min-h-touch-min items-center rounded-full bg-brand px-6 text-fg-on-brand shadow-e1"
                    >
                        {t.notFound.home}
                    </a>
                    <a
                        href={localizedPath(locale, '/categories')}
                        className="t-button inline-flex min-h-touch-min items-center rounded-full border border-line-strong bg-surface px-6 text-fg-primary"
                    >
                        {t.notFound.browse}
                    </a>
                </div>
            </section>

            <section aria-labelledby="nf-search" className="flex max-w-140 flex-col gap-3">
                <h2 id="nf-search" className="t-h3 text-fg-primary">
                    {t.notFound.search}
                </h2>
                <SearchBar locale={locale} id="search-not-found" />
            </section>

            {categories.length > 0 ? (
                <section aria-labelledby="nf-categories" className="flex flex-col gap-3">
                    <h2 id="nf-categories" className="t-h3 text-fg-primary">
                        {t.nav.allCategories}
                    </h2>
                    <ul className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        {categories.map((c) => (
                            <li key={c.slug}>
                                <a
                                    href={localizedPath(locale, categoryPath(c.slug))}
                                    lang={categoryNameIsFallback(c, locale) ? 'en' : undefined}
                                    className="t-label flex h-full min-h-touch-min items-center rounded-md border border-line-default bg-surface p-4 text-fg-primary shadow-e1 transition-shadow duration-(--tm-duration-base) ease-standard hover:shadow-e2"
                                >
                                    {categoryName(c, locale)}
                                </a>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
        </div>
    );
}
