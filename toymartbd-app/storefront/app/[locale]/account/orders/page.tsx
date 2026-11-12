import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { AccountShell } from '@/components/account/AccountShell';
import { OrderCard } from '@/components/account/OrderCard';
import { smallButton } from '@/components/account/styles';
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

    return (
        <AccountShell locale={locale} path={PATH} account={account} section="orders" title={m.orders.title}>
            <header className="flex flex-col gap-1">
                <h1 className="t-h1 text-fg-primary">{m.orders.title}</h1>
                <p className="t-body-sm text-fg-secondary tabular-nums">{format(m.orders.sub, { count: String(total) })}</p>
            </header>

            <nav aria-label={m.orders.tabsLabel} className="-mx-gutter-mobile overflow-x-auto px-gutter-mobile md:mx-0 md:px-0">
                <ul className="flex w-max gap-1 rounded-full bg-subtle p-1">
                    {ORDER_GROUPS.map((key) => {
                        const current = key === group;
                        return (
                            <li key={key}>
                                <a
                                    href={`${localizedPath(locale, PATH)}?group=${key}`}
                                    aria-current={current ? 'page' : undefined}
                                    className={`t-body-sm flex h-10 items-center gap-2 rounded-full px-4 ${current ? 'bg-surface font-semibold text-fg-primary shadow-e1' : 'text-fg-secondary'}`}
                                >
                                    {m.orders.tabs[key]}
                                    <span className={`t-caption-strong inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 tabular-nums ${current ? 'bg-brand-subtle text-fg-brand' : 'bg-surface text-fg-secondary'}`}>
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
