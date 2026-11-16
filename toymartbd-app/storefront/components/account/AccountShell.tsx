import type { ReactNode } from 'react';

import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import { PageShell } from '@/components/PageShell';
import { displayPhone } from '@/lib/account/flash';
import { initials } from '@/lib/account/format';
import { accountMessages } from '@/lib/account/messages';
import type { Account } from '@/lib/account/types';
import { localizedPath, type Locale } from '@/lib/i18n';

// The signed-in account frame (Figma desktop page 06: 280px account menu + content, e.g. Home
// 239:11547; mobile page 05: a back button + title above the content, the menu lives on the account
// home tiles). AccountMenu 239:12215: radius md, 12/16 inset, "me" row (48 Bloom-sky avatar, name +
// phone); AccountNavItem 236:2112: 44 tall, radius sm, 14 inset, icon 20 + body-sm label; Active =
// brand-subtle fill + semibold. The breadcrumb is one caption line, "Home / My account / …" (239:12213).
// Sign out is a POST form, so it works without JavaScript. The menu's "Rewards & credit" and "Returns"
// have no backend yet (loyalty rates are owner-pending), so they are not shown.

export type AccountSection = 'overview' | 'orders' | 'wishlist' | 'addresses' | 'profile';

// "My kids" is a section of the profile page (KidsCard, #kids), so it is never the active item.
const ITEMS: Array<{ key: AccountSection | 'kids'; path: string; icon: IconName }> = [
    { key: 'overview', path: '/account', icon: 'house' },
    { key: 'orders', path: '/account/orders', icon: 'cube' },
    { key: 'wishlist', path: '/account/wishlist', icon: 'heartFill' },
    { key: 'addresses', path: '/account/addresses', icon: 'mapPin' },
    { key: 'kids', path: '/account/profile#kids', icon: 'smiley' },
    { key: 'profile', path: '/account/profile', icon: 'user' },
];

const navItem = 't-body-sm flex h-11 w-full items-center gap-3 rounded-sm px-3.5 text-fg-primary';

interface Props {
    locale: Locale;
    /** Locale-independent path of this page, for the language toggle and the tab bar. */
    path: string;
    account: Account;
    section: AccountSection;
    /** The page title: breadcrumb tail and the mobile back-bar title. */
    title: string;
    /** Where the mobile back button goes (defaults to the account home; the home has none). */
    back?: { path: string; label: string };
    /** The page heading. 390: back circle 40 + h2-size title + caption (Figma "Back" row, e.g. 237:9851);
     *  1440: h1 + body-sm line. `subDesktop` replaces `sub` at 1440 when the design shows more there. */
    heading?: { sub?: ReactNode; subDesktop?: ReactNode };
    children: ReactNode;
}

export function AccountShell({ locale, path, account, section, title, back, heading, children }: Props) {
    const m = accountMessages(locale);
    const mark = initials(account.name);
    const backTo = back ?? (section === 'overview' ? null : { path: '/account', label: m.nav.myAccount });

    return (
        <PageShell locale={locale} path={path} top="crumb">
            <div className="flex flex-col gap-4 pt-3 pb-8 lg:gap-6 lg:pt-2 lg:pb-20">{/* 390: body starts 20 below the header (8 + 12); 1440: crumb 32 below the menu bar */}
                <nav aria-label={m.nav.myAccount} className="hidden lg:block">
                    <ol className="t-caption flex items-center gap-1 whitespace-nowrap text-fg-tertiary">
                        <li>
                            <a href={localizedPath(locale, '/')} className="underline-offset-2 hover:underline">
                                {m.nav.home}
                            </a>
                        </li>
                        <li className="flex items-center gap-1">
                            <span aria-hidden="true">/</span>
                            {section === 'overview' ? (
                                <span aria-current="page">{m.nav.myAccount}</span>
                            ) : (
                                <a href={localizedPath(locale, '/account')} className="underline-offset-2 hover:underline">
                                    {m.nav.myAccount}
                                </a>
                            )}
                        </li>
                        {section !== 'overview' ? (
                            <li className="flex items-center gap-1">
                                <span aria-hidden="true">/</span>
                                <span aria-current="page" className="tabular-nums">
                                    {title}
                                </span>
                            </li>
                        ) : null}
                    </ol>
                </nav>

                <div className="flex flex-col gap-6 lg:flex-row lg:items-start lg:gap-8">
                    <aside className="hidden w-70 shrink-0 flex-col gap-1 rounded-md border border-line-default bg-surface px-3 py-4 lg:flex">
                        <div className="flex items-center gap-3 px-2 pt-2 pb-4">
                            <span aria-hidden="true" className="t-h4 flex size-12 shrink-0 items-center justify-center rounded-full bg-(image:--tm-gradient-bloom-sky) text-fg-brand">
                                {mark ?? <Icon name="user" className="size-icon-md" />}
                            </span>
                            <span className="flex min-w-0 flex-col">
                                <span className="t-body-strong truncate text-fg-primary">{account.name || displayPhone(account.phone)}</span>
                                <span className="t-caption text-fg-secondary tabular-nums">{account.name ? displayPhone(account.phone) : m.nav.verified}</span>
                            </span>
                        </div>
                        <nav aria-label={m.nav.label}>
                            <ul className="flex flex-col gap-1">
                                {ITEMS.map((item) => {
                                    const active = item.key === section;
                                    return (
                                        <li key={item.key}>
                                            <a
                                                href={localizedPath(locale, item.path)}
                                                aria-current={active ? 'page' : undefined}
                                                className={`${navItem} ${active ? 'bg-brand-subtle font-semibold' : 'hover:bg-subtle'}`}
                                            >
                                                <Icon name={item.icon} className={`size-5 shrink-0 ${active ? 'text-fg-brand' : 'text-fg-primary'}`} />
                                                {m.nav[item.key]}
                                            </a>
                                        </li>
                                    );
                                })}
                                <li>
                                    <SignOutButton locale={locale} label={m.nav.signOut} className={`${navItem} hover:bg-subtle [&>svg]:size-5 [&>svg]:text-fg-primary`} />
                                </li>
                            </ul>
                        </nav>
                    </aside>
                    <div className="flex min-w-0 grow flex-col gap-4 lg:gap-6">
                        {heading ? (
                            <header className="flex items-center gap-2 lg:flex-col lg:items-start lg:gap-1">
                                {backTo ? (
                                    <a href={localizedPath(locale, backTo.path)} aria-label={backTo.label} className="flex size-10 shrink-0 items-center justify-center rounded-full border border-line-default bg-surface text-fg-primary lg:hidden">
                                        <Icon name="arrowLeft" className="size-4.5" />
                                    </a>
                                ) : null}
                                <div className="flex min-w-0 flex-col lg:gap-1">
                                    <h1 className="t-h2 lg:t-h1 text-fg-primary tabular-nums">{title}</h1>
                                    {heading.sub ? <p className={`t-caption lg:t-body-sm text-fg-tertiary tabular-nums lg:text-fg-secondary ${heading.subDesktop ? 'lg:hidden' : ''}`}>{heading.sub}</p> : null}
                                    {heading.subDesktop ? <p className="t-body-sm hidden text-fg-secondary tabular-nums lg:block">{heading.subDesktop}</p> : null}
                                </div>
                            </header>
                        ) : backTo ? (
                            <a href={localizedPath(locale, backTo.path)} className="t-body-sm-strong flex w-fit items-center gap-2 text-fg-primary lg:hidden">
                                <span className="flex size-10 items-center justify-center rounded-full border border-line-default bg-surface">
                                    <Icon name="arrowLeft" className="size-4.5" />
                                </span>
                                {backTo.label}
                            </a>
                        ) : null}
                        {children}
                    </div>
                </div>
            </div>
        </PageShell>
    );
}

export function SignOutButton({ locale, label, className }: { locale: Locale; label: string; className: string }) {
    return (
        <form method="post" action="/api/account/logout">
            <input type="hidden" name="locale" value={locale} />
            <button type="submit" className={className}>
                <Icon name="signOut" className="size-4 text-fg-secondary" />
                {label}
            </button>
        </form>
    );
}
