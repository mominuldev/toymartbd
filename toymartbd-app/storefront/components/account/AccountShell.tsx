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
// home tiles). AccountNavItem 236:2112: 40 tall, radius xs, icon 16 + label; Active = brand-subtle
// fill. Sign out is a POST form, so it works without JavaScript.

export type AccountSection = 'overview' | 'orders' | 'wishlist' | 'addresses' | 'profile';

const ITEMS: Array<{ key: AccountSection; path: string; icon: IconName }> = [
    { key: 'overview', path: '/account', icon: 'house' },
    { key: 'orders', path: '/account/orders', icon: 'cube' },
    { key: 'wishlist', path: '/account/wishlist', icon: 'heartFill' },
    { key: 'addresses', path: '/account/addresses', icon: 'mapPin' },
    { key: 'profile', path: '/account/profile', icon: 'user' },
];

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
    children: ReactNode;
}

export function AccountShell({ locale, path, account, section, title, back, children }: Props) {
    const m = accountMessages(locale);
    const mark = initials(account.name);
    const backTo = back ?? (section === 'overview' ? null : { path: '/account', label: m.nav.myAccount });

    return (
        <PageShell locale={locale} path={path} top="crumb">
            <div className="flex flex-col gap-4 pb-8 lg:gap-6 lg:pb-20">
                <nav aria-label={m.nav.myAccount} className="hidden lg:block">
                    <ol className="t-body-sm flex items-center gap-2 whitespace-nowrap">
                        <li>
                            <a href={localizedPath(locale, '/')} className="text-fg-link underline-offset-2 hover:underline">
                                {m.nav.home}
                            </a>
                        </li>
                        <li className="flex items-center gap-2">
                            <Icon name="caretRight" className="size-3.5 text-fg-tertiary" />
                            {section === 'overview' ? (
                                <span aria-current="page" className="t-body-sm-strong text-fg-secondary">
                                    {m.nav.myAccount}
                                </span>
                            ) : (
                                <a href={localizedPath(locale, '/account')} className="text-fg-link underline-offset-2 hover:underline">
                                    {m.nav.myAccount}
                                </a>
                            )}
                        </li>
                        {section !== 'overview' ? (
                            <li className="flex items-center gap-2">
                                <Icon name="caretRight" className="size-3.5 text-fg-tertiary" />
                                <span aria-current="page" className="t-body-sm-strong text-fg-secondary">
                                    {title}
                                </span>
                            </li>
                        ) : null}
                    </ol>
                </nav>

                {backTo ? (
                    <a href={localizedPath(locale, backTo.path)} className="t-body-sm-strong flex w-fit items-center gap-3 text-fg-primary lg:hidden">
                        <span className="flex size-11 items-center justify-center rounded-full border border-line-default bg-surface">
                            <Icon name="arrowLeft" className="size-icon-md" />
                        </span>
                        {backTo.label}
                    </a>
                ) : null}

                <div className="flex flex-col gap-6 lg:flex-row lg:items-start lg:gap-8">
                    <aside className="hidden w-70 shrink-0 flex-col gap-1 rounded-sm border border-line-default bg-surface p-3 lg:flex">
                        <div className="flex items-center gap-3 px-2 pt-1 pb-3">
                            <span aria-hidden="true" className="t-body-sm-strong flex size-10 shrink-0 items-center justify-center rounded-full bg-grape-subtle text-fg-brand">
                                {mark ?? <Icon name="user" className="size-icon-sm" />}
                            </span>
                            <span className="flex min-w-0 flex-col">
                                <span className="t-body-sm-strong truncate text-fg-primary">{account.name || displayPhone(account.phone)}</span>
                                <span className="t-caption text-fg-tertiary tabular-nums">{account.name ? displayPhone(account.phone) : m.nav.verified}</span>
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
                                                className={`t-body-sm flex h-10 items-center gap-3 rounded-xs px-3 text-fg-primary ${active ? 'bg-brand-subtle font-semibold' : 'hover:bg-subtle'}`}
                                            >
                                                <Icon name={item.icon} className={`size-4 ${active ? 'text-fg-brand' : 'text-fg-secondary'}`} />
                                                {m.nav[item.key]}
                                            </a>
                                        </li>
                                    );
                                })}
                                <li>
                                    <SignOutButton locale={locale} label={m.nav.signOut} className="t-body-sm flex h-10 w-full items-center gap-3 rounded-xs px-3 text-fg-primary hover:bg-subtle" />
                                </li>
                            </ul>
                        </nav>
                    </aside>
                    <div className="flex min-w-0 grow flex-col gap-4 lg:gap-6">{children}</div>
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
                <Icon name="arrowRight" className="size-4 text-fg-secondary" />
                {label}
            </button>
        </form>
    );
}
