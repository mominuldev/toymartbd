import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import { LocaleToggle } from '@/components/LocaleToggle';
import type { MenuItem } from '@/lib/category-tree';
import { format, getMessages, localizedPath, otherLocale, type Locale } from '@/lib/i18n';
import { cartBadge } from '@/lib/navigation';
import { MegaMenu } from './MegaMenu';
import { SearchBar } from './SearchBar';

interface Props {
    locale: Locale;
    /** Locale-independent path of the current page (query included), for the language toggle. */
    path: string;
    menu: MenuItem[];
    searchQuery?: string;
    /** No client-side cart exists yet (P4-STO-08); the count is a server-supplied 0. */
    cartCount?: number;
}

const iconLink =
    'relative size-touch-min shrink-0 items-center justify-center rounded-full text-fg-primary transition-colors duration-(--tm-duration-fast) ease-standard hover:bg-subtle';

function IconLink({ href, label, icon, badge, className = 'inline-flex' }: { href: string; label: string; icon: IconName; badge?: string | null; className?: string }) {
    // `className` carries the display utility (`inline-flex`, or `hidden lg:inline-flex`).
    return (
        <a href={href} aria-label={label} className={`${iconLink} ${className}`}>
            <Icon name={icon} className="size-icon-lg" />
            {badge ? (
                <span
                    aria-hidden="true"
                    className="t-caption absolute top-0 right-0 inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-brand px-1 font-bold text-fg-on-brand"
                >
                    {badge}
                </span>
            ) : null}
        </a>
    );
}

// Header 56 (mobile) / 72 + MegaMenu bar 48 (≥1024), sticky (storefront spec §0.1). Every control
// is a plain link or a GET form, so the header is fully usable without JavaScript.
export function SiteHeader({ locale, path, menu, searchQuery, cartCount = 0 }: Props) {
    const t = getMessages(locale);
    const target = otherLocale(locale);
    const badge = cartBadge(cartCount);

    return (
        <div className="sticky top-0 z-30">
            <header className="border-b border-line-default bg-surface">
                <div className="mx-auto flex h-14 max-w-content items-center gap-2 px-gutter-mobile md:px-gutter-tablet lg:h-18 lg:gap-6 lg:px-gutter-desktop">
                    <a
                        href={localizedPath(locale, '/')}
                        aria-label={t.nav.logoAria}
                        className="t-h3 inline-flex min-h-touch-min shrink-0 items-center rounded-xs text-fg-brand"
                    >
                        {t.site.name}
                    </a>
                    <div className="mx-auto hidden w-full max-w-140 lg:block">
                        <SearchBar locale={locale} id="search-desktop" defaultValue={searchQuery} />
                    </div>
                    <nav aria-label={t.nav.primary} className="ml-auto flex items-center gap-1 lg:ml-0">
                        <a
                            href={localizedPath(locale, '/brands')}
                            className="t-label hidden min-h-touch-min items-center rounded-xs px-3 text-fg-primary underline-offset-4 hover:underline lg:inline-flex"
                        >
                            {t.nav.brands}
                        </a>
                        <IconLink href={localizedPath(locale, '/wishlist')} label={t.nav.wishlist} icon="heart" />
                        <IconLink href={localizedPath(locale, '/account')} label={t.nav.account} icon="user" className="hidden lg:inline-flex" />
                        <IconLink
                            href={localizedPath(locale, '/cart')}
                            label={badge ? format(t.nav.cartCount, { count: badge }) : t.nav.cartEmpty}
                            icon="shoppingCart"
                            badge={badge}
                            className="hidden lg:inline-flex"
                        />
                        <LocaleToggle target={target} href={localizedPath(target, path)} label={t.language[target]} accessibleLabel={t.language.switchLabel} />
                    </nav>
                </div>
                <div className="px-gutter-mobile pb-2 md:px-gutter-tablet lg:hidden">
                    <SearchBar locale={locale} id="search-mobile" defaultValue={searchQuery} />
                </div>
            </header>
            <MegaMenu locale={locale} items={menu} label={t.nav.categoriesMenu} />
        </div>
    );
}
