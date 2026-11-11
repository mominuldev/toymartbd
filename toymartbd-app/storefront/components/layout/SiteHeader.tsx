import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import { LocaleToggle } from '@/components/LocaleToggle';
import type { MenuItem } from '@/lib/category-tree';
import { format, getMessages, localizedPath, otherLocale, type Locale } from '@/lib/i18n';
import { cartBadge } from '@/lib/navigation';
import { contact } from '@/lib/site-config';
import { MegaMenu } from './MegaMenu';
import { SearchBar } from './SearchBar';
import { Wordmark } from './Wordmark';

interface Props {
    locale: Locale;
    /** Locale-independent path of the current page (query included), for the language toggle. */
    path: string;
    menu: MenuItem[];
    searchQuery?: string;
    /** No client-side cart exists yet (P4-STO-08); the count is a server-supplied 0. */
    cartCount?: number;
    /** Mobile search row under the header (Figma Home 126:10 "SearchRow"; the search page). */
    mobileSearch?: boolean;
}

const iconButton =
    'relative size-touch-min shrink-0 items-center justify-center rounded-full transition-colors duration-(--tm-duration-fast) ease-standard hover:bg-fg-primary/8 active:bg-fg-primary/12';

function IconLink({ href, label, icon, badge, className = 'inline-flex text-fg-primary' }: { href: string; label: string; icon: IconName; badge?: string | null; className?: string }) {
    // `className` carries the display utility (`inline-flex`, or `hidden lg:inline-flex`) and the ink.
    return (
        <a href={href} aria-label={label} className={`${iconButton} ${className}`}>
            <Icon name={icon} className="size-icon-md" />
            {badge ? (
                <span
                    aria-hidden="true"
                    className="absolute top-0.5 left-6.5 inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full border-2 border-surface bg-brand px-1 t-caption font-display-latin font-semibold text-fg-on-brand"
                >
                    {badge}
                </span>
            ) : null}
        </a>
    );
}

// Figma Header 123:1832. Mobile 56: menu · centred wordmark · search · wishlist, 1px pink rule.
// Desktop 72 (content 1280): wordmark · 560 search · EN/বাং locale pill · hotline · wishlist ·
// account · cart with count badge; the MegaMenuBar (123:1833) sits directly under it (sticky 120).
// Every control is a plain link or a GET form, so the header is fully usable without JavaScript.
export function SiteHeader({ locale, path, menu, searchQuery, cartCount = 0, mobileSearch = false }: Props) {
    const t = getMessages(locale);
    const target = otherLocale(locale);
    const badge = cartBadge(cartCount);
    const cartLabel = badge ? format(t.nav.cartCount, { count: badge }) : t.nav.cartEmpty;

    return (
        <div className="sticky top-0 z-30">
            {/* On desktop the MegaMenuBar draws the rule under the header; without a menu the header keeps it. */}
            <header className={`border-b border-line-brand-subtle bg-surface lg:px-gutter-desktop ${menu.length > 0 ? 'lg:border-b-0' : ''}`}>
                <div className="mx-auto flex h-14 max-w-content items-center px-1.5 lg:h-18 lg:gap-6 lg:px-0">
                    <IconLink href={localizedPath(locale, '/categories')} label={t.nav.menu} icon="list" className="inline-flex text-fg-primary lg:hidden" />
                    <a
                        href={localizedPath(locale, '/')}
                        aria-label={t.nav.logoAria}
                        className="mx-auto inline-flex min-h-touch-min min-w-0 shrink-0 items-center rounded-xs lg:mx-0"
                    >
                        <Wordmark locale={locale} />
                    </a>
                    <div className="hidden w-140 shrink lg:mx-auto lg:block">
                        <SearchBar locale={locale} id="search-desktop" defaultValue={searchQuery} />
                    </div>
                    <nav aria-label={t.nav.primary} className="flex items-center gap-1">
                        <IconLink href={localizedPath(locale, '/search')} label={t.nav.search} icon="magnifyingGlass" className="inline-flex text-fg-primary lg:hidden" />
                        <details className="locale-menu relative hidden lg:block">
                            <summary
                                aria-label={t.language.switchLabel}
                                className="t-body-sm inline-flex h-control-sm cursor-pointer list-none items-center gap-1 rounded-full bg-brand-subtle pr-2.5 pl-3 font-semibold text-fg-primary transition-colors duration-(--tm-duration-fast) ease-standard hover:bg-brand-subtle-shelf [&::-webkit-details-marker]:hidden"
                            >
                                <span lang={locale}>{t.nav.languageShort[locale]}</span>
                                <Icon name="caretDown" className="size-3.5" />
                            </summary>
                            <div className="absolute top-full right-0 z-10 mt-2 flex min-w-32 flex-col rounded-md bg-surface-raised p-1 shadow-e3">
                                <span lang={locale} aria-current="true" className="t-body-sm flex min-h-touch-min items-center rounded-sm px-3 font-semibold text-fg-brand">
                                    {t.language[locale]}
                                </span>
                                <LocaleToggle
                                    target={target}
                                    href={localizedPath(target, path)}
                                    label={t.language[target]}
                                    accessibleLabel={t.language.switchLabel}
                                    variant="menu"
                                />
                            </div>
                        </details>
                        <p className="t-body-sm hidden items-center gap-1.5 px-2.5 font-semibold whitespace-nowrap text-fg-primary lg:inline-flex">
                            <Icon name="phone" className="size-icon-sm text-fg-brand" />
                            <span className="sr-only">{t.nav.hotline}: </span>
                            {/* A placeholder number is plain text (never tel:) — see lib/site-config.ts. */}
                            {contact.hotline.placeholder ? (
                                <span data-placeholder="owner-to-confirm">{contact.hotline.display}</span>
                            ) : (
                                <a href={contact.hotline.href} className="rounded-xs underline-offset-4 hover:underline">
                                    {contact.hotline.display}
                                </a>
                            )}
                        </p>
                        <IconLink href={localizedPath(locale, '/wishlist')} label={t.nav.wishlist} icon="heart" />
                        <IconLink href={localizedPath(locale, '/account')} label={t.nav.account} icon="user" className="hidden text-fg-primary lg:inline-flex" />
                        <IconLink
                            href={localizedPath(locale, '/cart')}
                            label={cartLabel}
                            icon="shoppingBagOpenFill"
                            badge={badge}
                            className="hidden text-fg-brand lg:inline-flex"
                        />
                    </nav>
                </div>
                {mobileSearch ? (
                    <div className="px-gutter-mobile py-1.5 lg:hidden">
                        <SearchBar locale={locale} id="search-mobile" defaultValue={searchQuery} />
                    </div>
                ) : null}
            </header>
            <MegaMenu locale={locale} items={menu} label={t.nav.categoriesMenu} />
        </div>
    );
}
