import { Icon } from '@/components/icons/Icon';
import { getMessages, localizedPath, type Locale } from '@/lib/i18n';
import { activeTab, tabs } from '@/lib/navigation';
import { CartLink } from './CartLink';

interface Props {
    locale: Locale;
    path: string;
    /** Server-known cart count (cart and checkout pages); cached pages pass 0 and CartLink reads the count cookie. */
    cartCount?: number;
}

// Figma MobileNav 111:784 (storefront spec §A5): fixed bottom tab bar below 1024, 64 tall +
// safe-area inset, five equal tabs (78 wide at 390). Active tab: a brand-subtle bubble behind a
// coral icon and a bold navy label; inactive labels are secondary. 1px pink top rule and the
// upward rose Shadow/Up-nav effect. Plain
// links, so it needs no JavaScript. Hidden on /checkout once that exists (pass a flag from that route).
export function MobileNav({ locale, path, cartCount = 0 }: Props) {
    const t = getMessages(locale);
    const current = activeTab(path);

    return (
        <nav
            aria-label={t.tabs.label}
            className="fixed inset-x-0 bottom-0 z-30 border-t border-line-brand-subtle bg-surface shadow-up-nav pb-[env(safe-area-inset-bottom)] lg:hidden"
        >
            <ul className="grid grid-cols-5">
                {tabs.map((tab) => {
                    const active = current === tab.key;
                    const isCart = tab.key === 'cart';
                    const bubble = `relative inline-flex h-7.5 w-12 items-center justify-center rounded-full transition-colors duration-(--tm-duration-fast) ease-standard group-active:bg-subtle ${active ? 'bg-brand-subtle text-fg-brand' : 'text-fg-primary'}`;
                    const label = (
                        <span className={`t-caption max-w-full overflow-hidden text-center whitespace-nowrap ${active ? 'font-semibold text-fg-primary' : 'text-fg-secondary'}`}>
                            {t.tabs[tab.key]}
                        </span>
                    );
                    return (
                        <li key={tab.key} className="min-w-0">
                            {isCart ? (
                                <CartLink
                                    href={localizedPath(locale, tab.path)}
                                    initial={cartCount}
                                    labels={{ empty: t.nav.cartEmpty, count: t.nav.cartCount }}
                                    current={active}
                                    className="group relative flex h-16 flex-col items-center justify-center gap-0.5 px-1"
                                    badgeClassName="absolute top-0.5 left-[calc(50%+--spacing(1))] inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full border-2 border-surface bg-brand px-1 t-caption font-display-latin font-semibold text-fg-on-brand"
                                >
                                    <span className={bubble}>
                                        <Icon name={tab.icon} className="size-5.5" />
                                    </span>
                                    {label}
                                </CartLink>
                            ) : (
                                <a href={localizedPath(locale, tab.path)} aria-current={active ? 'page' : undefined} className="group flex h-16 flex-col items-center justify-center gap-0.5 px-1">
                                    <span className={bubble}>
                                        <Icon name={tab.icon} className="size-5.5" />
                                    </span>
                                    {label}
                                </a>
                            )}
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
