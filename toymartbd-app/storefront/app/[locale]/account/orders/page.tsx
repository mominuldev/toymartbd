import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { AccountShell } from '@/components/account/AccountShell';
import { OrderCard } from '@/components/account/OrderCard';
import { smallButton } from '@/components/account/styles';
import { monthYear } from '@/lib/account/format';
import { accountMessages } from '@/lib/account/messages';
import { getOrders, requireAccount } from '@/lib/account/server';
import { ORDER_GROUPS, type OrderGroup } from '@/lib/account/types';
import { format, getMessages, isLocale, localizedPath } from '@/lib/i18n';

// /account/orders — My orders, Figma 239:12108 (1440) / 237:9782 (390): Active · Delivered · Cancelled
// · Returns tabs with counts (plain links, `?group=`), a 2-up grid of AccountOrderCards on desktop, one
// column on mobile, and "Show older orders" (`?cursor=`). Only the customer's own orders ever appear.

const PATH = '/account/orders';

export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ locale: string }>;
    searchParams: Promise<Record<string, string | string[] | undefined>>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    return { title: accountMessages(locale).meta.orders, robots: { index: false, follow: false } };
}

function isGroup(value: unknown): value is OrderGroup {
    return typeof value === 'string' && (ORDER_GROUPS as readonly string[]).includes(value);
}

export default async function OrdersPage({ params, searchParams }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const m = accountMessages(locale);
    const t = getMessages(locale);
    const query = await searchParams;
    const group: OrderGroup = isGroup(query.group) ? query.group : 'active';
    const cursor = typeof query.cursor === 'string' && query.cursor.length <= 512 ? query.cursor : null;
    const { token, account } = await requireAccount(locale, PATH);
    const page = await getOrders(token, locale, group, cursor);
    const total = Object.values(page.summary).reduce((a, b) => a + b, 0);
    const next = page.meta.next_cursor;
    const since = monthYear(account.member_since, t);

    return (
        <AccountShell locale={locale} path={PATH} account={account} section="orders" title={m.orders.title} heading={{ sub: since ? format(m.orders.subSince, { count: String(total), date: since }) : format(m.orders.sub, { count: String(total) }) }}>

            <nav aria-label={m.orders.tabsLabel} className="-mx-gutter-mobile overflow-x-auto px-gutter-mobile md:mx-0 md:px-0">
                {/* Tabs/Item Pill (237:9859): no track; active = surface + e1, label Tabs/Label-active; others body-strong secondary. */}
                <ul className="flex w-max gap-2">
                    {ORDER_GROUPS.map((key) => {
                        const current = key === group;
                        return (
                            <li key={key}>
                                <a
                                    href={`${localizedPath(locale, PATH)}?group=${key}`}
                                    aria-current={current ? 'page' : undefined}
                                    className={`flex h-11 min-w-18 items-center justify-center gap-2 rounded-full px-4 whitespace-nowrap ${current ? 't-tabs-label-active bg-surface text-fg-primary shadow-e1' : 't-body-strong text-fg-secondary hover:bg-subtle'}`}
                                >
                                    {m.orders.tabs[key]}
                                    <span className={`t-badge inline-flex h-5 min-w-5 items-center justify-center rounded-full px-2 tabular-nums ${current ? 'bg-brand-subtle text-fg-primary' : 'bg-subtle text-fg-secondary'}`}>
                                        {page.summary[key]}
                                    </span>
                                </a>
                            </li>
                        );
                    })}
                </ul>
            </nav>

            {page.data.length === 0 ? (
                <p className="t-body-sm rounded-sm border border-line-default bg-surface p-6 text-fg-secondary">
                    {m.orders.empty}{' '}
                    <a href={localizedPath(locale, '/toys')} className="font-semibold text-fg-link underline">
                        {m.orders.emptyCta}
                    </a>
                </p>
            ) : (
                <ul className="grid gap-3 lg:grid-cols-2 lg:gap-4">
                    {page.data.map((order) => (
                        <li key={order.number}>
                            <OrderCard order={order} locale={locale} t={t} m={m} />
                        </li>
                    ))}
                </ul>
            )}

            {next ? (
                <a href={`${localizedPath(locale, PATH)}?group=${group}&cursor=${encodeURIComponent(next)}`} className={`${smallButton.outline} self-center`}>
                    {m.orders.loadMore}
                </a>
            ) : null}
        </AccountShell>
    );
}
