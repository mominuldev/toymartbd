import { Icon } from '@/components/icons/Icon';
import { format, getMessages, localizedPath, type Locale } from '@/lib/i18n';
import { activeTab, cartBadge, tabs } from '@/lib/navigation';

interface Props {
    locale: Locale;
    path: string;
    /** Items in the cart. The storefront has no cart yet (P4-STO-08), so callers pass 0. */
    cartCount?: number;
}

// Storefront spec §A5: fixed bottom tab bar below 1024, five 56-tall items. Plain links, so it
// needs no JavaScript. Hidden on /checkout once that exists (pass a flag from that route).
export function MobileNav({ locale, path, cartCount = 0 }: Props) {
    const t = getMessages(locale);
    const current = activeTab(path);
    const badge = cartBadge(cartCount);

    return (
        <nav
            aria-label={t.tabs.label}
            className="fixed inset-x-0 bottom-0 z-30 border-t border-line-default bg-surface pb-[env(safe-area-inset-bottom)] shadow-e3 lg:hidden"
        >
            <ul className="grid grid-cols-5">
                {tabs.map((tab) => {
                    const active = current === tab.key;
                    const isCart = tab.key === 'cart';
                    return (
                        <li key={tab.key} className="min-w-0">
                            <a
                                href={localizedPath(locale, tab.path)}
                                aria-current={active ? 'page' : undefined}
                                aria-label={isCart ? (badge ? format(t.nav.cartCount, { count: badge }) : t.nav.cartEmpty) : undefined}
                                className={`relative flex h-14 flex-col items-center justify-start gap-1 px-1 pt-2 transition-colors duration-(--tm-duration-fast) ease-standard active:bg-subtle ${active ? 'text-fg-primary' : 'text-fg-secondary'}`}
                            >
                                {active ? <span aria-hidden="true" className="absolute top-0 left-1/2 h-0.75 w-5 -translate-x-1/2 rounded-full bg-brand" /> : null}
                                <Icon name={tab.icon} className="size-icon-lg" />
                                <span className="t-caption max-w-full overflow-hidden text-center whitespace-nowrap">{t.tabs[tab.key]}</span>
                                {isCart && badge ? (
                                    <span
                                        aria-hidden="true"
                                        className="t-caption absolute top-1 left-1/2 ml-2 inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-brand px-1 font-bold text-fg-on-brand"
                                    >
                                        {badge}
                                    </span>
                                ) : null}
                            </a>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
