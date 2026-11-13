import { buttonClass } from '@/components/layout/button-styles';
import { localizedPath, type Locale } from '@/lib/i18n';
import { PER_PAGE } from '@/lib/blog/fetch';
import type { BlogMessages } from '@/lib/blog/messages';
import type { BlogPage } from '@/lib/blog/types';
import { BlogCard } from './BlogCard';
import { BlogPagination } from './BlogPagination';
import { Breadcrumbs, type BlogCrumb } from './Breadcrumbs';

// A blog list page (index, guides, category, tag, author): breadcrumb, title + intro on a Bloom-sky
// header (the static-page header language, storefront spec §B8 — no blog-index frame exists in
// Figma yet), optional category chips, a 1 / 2 / 3-column grid of BlogCards and numbered
// pagination. An empty list says so and routes back into the catalogue.

interface Props {
    locale: Locale;
    t: BlogMessages;
    /** Locale-independent path of the list (`/blog`, `/blog/tag/stem`). */
    path: string;
    title: string;
    titleLang?: 'en';
    intro?: string | null;
    crumbs: BlogCrumb[];
    chips?: Array<{ label: string; href: string; selected: boolean; lang?: 'en' }>;
    list: BlogPage;
}

export function BlogList({ locale, t, path, title, titleLang, intro, crumbs, chips = [], list }: Props) {
    const current = localizedPath(locale, list.page > 1 ? `${path}?page=${list.page}` : path);
    return (
        <div className="flex flex-col gap-6 lg:gap-8">
            <Breadcrumbs locale={locale} crumbs={crumbs} label={t.breadcrumb} current={current} />
            <header className="flex flex-col gap-3 rounded-lg-plus bg-(image:--tm-gradient-bloom-sky) p-6 lg:rounded-xl-plus lg:p-10">
                <h1 className="t-display-2 text-fg-primary lg:t-display-1" lang={titleLang}>
                    {title}
                </h1>
                {intro ? <p className="t-body max-w-prose text-fg-secondary lg:t-body-lg">{intro}</p> : null}
            </header>
            {chips.length > 0 ? (
                <nav aria-label={t.categoriesNav} className="-mx-gutter-mobile overflow-x-auto px-gutter-mobile md:mx-0 md:px-0">
                    <ul className="flex gap-2 pb-1 lg:flex-wrap lg:gap-3">
                        {chips.map((chip) => (
                            <li key={chip.href}>
                                <a
                                    href={chip.href}
                                    aria-current={chip.selected ? 'page' : undefined}
                                    lang={chip.lang}
                                    className={`t-body-sm-strong inline-flex h-9 items-center rounded-full px-4 whitespace-nowrap ${chip.selected ? 'bg-brand text-fg-on-brand' : 'bg-surface text-fg-primary shadow-e1 hover:text-fg-link'}`}
                                >
                                    {chip.label}
                                </a>
                            </li>
                        ))}
                    </ul>
                </nav>
            ) : null}
            {list.posts.length === 0 ? (
                <section className="flex flex-col items-start gap-3 rounded-lg bg-surface p-6 shadow-e1">
                    <h2 className="t-h3 text-fg-primary">{t.empty}</h2>
                    <p className="t-body text-fg-secondary">{t.emptyBody}</p>
                    <a href={localizedPath(locale, '/categories')} className={buttonClass('primary')}>
                        {t.browse}
                    </a>
                </section>
            ) : (
                <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 lg:gap-6">
                    {list.posts.map((post, i) => (
                        <li key={post.id}>
                            <BlogCard post={post} locale={locale} t={t} priority={i < 3 && list.page === 1} />
                        </li>
                    ))}
                </ul>
            )}
            <BlogPagination locale={locale} path={path} page={list.page} lastPage={list.lastPage} total={list.total} perPage={PER_PAGE} t={t} />
        </div>
    );
}
