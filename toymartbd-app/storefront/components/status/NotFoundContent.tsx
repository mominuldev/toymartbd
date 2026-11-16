import { CategoryTile } from '@/components/CategoryTile';
import { Icon } from '@/components/icons/Icon';
import { buttonClass, buttonIconClass } from '@/components/layout/button-styles';
import { SearchBar } from '@/components/layout/SearchBar';
import { ProductRail } from '@/components/listing/ProductRail';
import { getCategoryTree } from '@/lib/categories';
import { format, getMessages, localizedPath, type Locale } from '@/lib/i18n';
import { fetchRail } from '@/lib/products';
import { NotFoundIllustration } from './NotFoundIllustration';

// The 404 body — Figma 163:9166 (1440) / 163:17451 (390), storefront spec §B8, content spec §C.7:
// a Bloom-candy hero with the "4 · ball · 4" illustration, a search bar and two ways back, then
// category tiles and the "Loved by kids this week" best-sellers row (the Home rail's source:
// GET /storefront/products sorted `featured`), so a dead link is still a working entry point.
// Used by both the route-level not-found and app/global-not-found.tsx.
export async function NotFoundContent({ locale }: { locale: Locale }) {
    const t = getMessages(locale);
    const [tree, best] = await Promise.all([getCategoryTree(), fetchRail('featured', locale)]);
    const categories = tree.slice(0, 8);

    return (
        <div className="flex flex-col gap-10 lg:gap-20">
            <section className="flex flex-col items-center gap-4 overflow-hidden rounded-lg-plus bg-(image:--tm-gradient-bloom-candy) px-5 pt-7 pb-6 text-center lg:flex-row lg:gap-12 lg:rounded-xl-plus lg:py-16 lg:pr-14 lg:pl-18 lg:text-left">
                <div className="flex w-full min-w-0 flex-col items-center gap-4 lg:flex-1 lg:items-start lg:gap-5">
                    <p className="t-overline rounded-full bg-surface px-3 py-1.25 text-fg-brand lg:px-3.5 lg:py-1.5">{t.notFound.eyebrow}</p>
                    <h1 className="t-display-2 text-fg-primary lg:t-display-1">{t.notFound.title}</h1>
                    <p className="t-body text-fg-secondary lg:t-body-lg">{t.notFound.body}</p>
                    <div className="w-full">
                        <SearchBar locale={locale} id="search-not-found" />
                    </div>
                    <div className="flex w-full flex-col gap-2.5 lg:w-auto lg:flex-row lg:gap-3">
                        <a href={localizedPath(locale, '/')} className={buttonClass('primary')}>
                            <Icon name="house" className={buttonIconClass.lg} />
                            {t.notFound.home}
                        </a>
                        <a href={localizedPath(locale, '/categories')} className={buttonClass('secondary')}>
                            <Icon name="puzzlePiece" className={buttonIconClass.lg} />
                            {t.notFound.browse}
                        </a>
                    </div>
                </div>
                <NotFoundIllustration className="w-full max-w-75 max-lg:-order-1 lg:w-140 lg:max-w-none" />
            </section>

            {categories.length > 0 ? (
                <section aria-labelledby="nf-categories" className="flex flex-col gap-4 lg:gap-6">
                    <div className="flex items-center justify-between gap-4">
                        <h2 id="nf-categories" className="t-h2 text-fg-primary">
                            {t.notFound.categoriesTitle}
                        </h2>
                        <a href={localizedPath(locale, '/categories')} className="t-body-sm-strong shrink-0 rounded-xs text-fg-brand underline-offset-4 hover:underline">
                            {t.notFound.seeAll}
                        </a>
                    </div>
                    <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-6">
                        {categories.map((c, i) => (
                            <li key={c.slug}>
                                <CategoryTile node={c} locale={locale} index={i} />
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            {best && best.products.length > 0 ? (
                <ProductRail
                    id="nf-best"
                    title={t.notFound.bestSellersTitle}
                    products={best.products.slice(0, 8)}
                    locale={locale}
                    t={t.listing}
                    action={{ href: localizedPath(locale, '/toys'), label: t.notFound.seeAll, ariaLabel: format(t.home.seeAllLabel, { section: t.notFound.bestSellersTitle }) }}
                    slider
                />
            ) : null}
        </div>
    );
}
