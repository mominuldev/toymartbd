import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { AccountNotice } from '@/components/account/AccountNotice';
import { AccountShell, SignOutButton } from '@/components/account/AccountShell';
import { OrderCard } from '@/components/account/OrderCard';
import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import { displayPhone } from '@/lib/account/flash';
import { firstName, initials, monthYear, noticeText } from '@/lib/account/format';
import { accountMessages } from '@/lib/account/messages';
import { getOrders, readAccountFlash, requireAccount } from '@/lib/account/server';
import { format, getMessages, isLocale, localizedPath } from '@/lib/i18n';
import { privateMetadata } from '@/lib/seo/metadata';

// /account — Account home, Figma 239:11547 (1440) / 237:9503 (390): greeting, pastel quick tiles and
// the order on its way. Rewards, "My kids" and "Picked for your kids" are in the design but have no
// backend yet (loyalty and child profiles are later tasks), so they are not shown — a tile that leads
// nowhere would be a dead end.

const PATH = '/account';

export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ locale: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    return privateMetadata(locale, '/account', accountMessages(locale).meta.home);
}

export default async function AccountHomePage({ params }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const m = accountMessages(locale);
    const t = getMessages(locale);
    const { token, account } = await requireAccount(locale, PATH);
    const [active, flash] = await Promise.all([getOrders(token, locale, 'active', null), readAccountFlash()]);
    const latest = active.data[0] ?? null;
    const onTheWay = active.summary.active;
    const greeting = account.name ? format(m.home.greeting, { name: firstName(account.name) }) : m.home.greetingNoName;
    const phone = displayPhone(account.phone);
    const since = monthYear(account.member_since, t);

    const tiles: Array<{ path: string; icon: IconName; tone: string; title: string; sub: string }> = [
        { path: '/account/orders', icon: 'cube', tone: 'bg-tile-pink', title: m.home.tiles.orders, sub: onTheWay > 0 ? format(m.home.tiles.ordersSub, { count: String(onTheWay) }) : m.home.tiles.ordersNone },
        { path: '/account/wishlist', icon: 'heartFill', tone: 'bg-tile-peach', title: m.home.tiles.wishlist, sub: format(m.home.tiles.wishlistSub, { count: String(account.counts.wishlist) }) },
        { path: '/account/addresses', icon: 'mapPin', tone: 'bg-tile-mint', title: m.home.tiles.addresses, sub: format(m.home.tiles.addressesSub, { count: String(account.counts.addresses) }) },
        { path: '/account/profile', icon: 'user', tone: 'bg-tile-lavender', title: m.home.tiles.profile, sub: m.home.tiles.profileSub },
    ];

    return (
        <AccountShell locale={locale} path={PATH} account={account} section="overview" title={m.nav.myAccount}>
            {/* 390: the "Hello" card (237:9572: Bloom-sky, avatar 56 with initials, h2 + phone · member since).
                1440 (239:11547): a plain h1 + sub above the tiles. One h1 either way. */}
            <header className="flex items-center gap-3 rounded-lg bg-(image:--tm-gradient-bloom-sky) px-4 py-4.5 lg:flex-col lg:items-start lg:gap-1 lg:rounded-none lg:bg-none lg:p-0">
                <span aria-hidden="true" className="t-h3 flex size-14 shrink-0 items-center justify-center rounded-full bg-surface text-fg-brand lg:hidden">
                    {initials(account.name) ?? <Icon name="user" className="size-icon-lg" />}
                </span>
                <span className="flex min-w-0 flex-col gap-0.5 lg:gap-1">
                    <h1 className="t-h2 lg:t-h1 text-fg-primary">{greeting}</h1>
                    <span className="t-caption text-fg-secondary tabular-nums lg:hidden">{since ? format(m.home.memberSince, { phone, date: since }) : phone}</span>
                    <span className="t-body-sm hidden text-fg-secondary lg:block">{m.home.sub}</span>
                </span>
            </header>

            <AccountNotice kind={flash?.kind ?? 'success'} text={noticeText(flash?.message, m)} />

            {!account.name ? (
                <p className="t-body-sm rounded-sm bg-tile-butter px-4 py-3 text-fg-primary">
                    {m.home.addName}{' '}
                    <a href={localizedPath(locale, '/account/profile')} className="font-semibold text-fg-link underline">
                        {m.home.addNameLink}
                    </a>
                </p>
            ) : null}

            <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
                {tiles.map((tile) => (
                    <li key={tile.path}>
                        <a href={localizedPath(locale, tile.path)} className={`flex h-full flex-col gap-1.5 rounded-md px-3 py-3.5 ${tile.tone}`}>
                            <span className="flex size-9 items-center justify-center rounded-full bg-surface text-fg-brand">
                                <Icon name={tile.icon} className="size-icon-md" />
                            </span>
                            <span className="flex flex-col gap-1.5">
                                <span className="t-h4 text-fg-primary">{tile.title}</span>
                                <span className="t-caption text-fg-secondary tabular-nums">{tile.sub}</span>
                            </span>
                        </a>
                    </li>
                ))}
            </ul>

            <section aria-labelledby="on-its-way" className="flex flex-col gap-3 lg:max-w-136">
                <div className="flex items-baseline justify-between gap-3">
                    <h2 id="on-its-way" className="t-h4 text-fg-primary">
                        {m.home.onItsWay}
                    </h2>
                    <a href={localizedPath(locale, '/account/orders')} className="t-body-sm-strong text-fg-primary">
                        {m.home.allOrders}
                    </a>
                </div>
                {latest ? (
                    <OrderCard order={latest} locale={locale} t={t} m={m} />
                ) : (
                    <p className="t-body-sm rounded-sm border border-line-default bg-surface p-4 text-fg-secondary">
                        {m.home.nothingOnTheWay}{' '}
                        <a href={localizedPath(locale, '/toys')} className="font-semibold text-fg-link underline">
                            {m.home.browse}
                        </a>
                    </p>
                )}
            </section>

            <div className="lg:hidden">
                <SignOutButton locale={locale} label={m.nav.signOut} className="t-body-sm-strong flex h-11 items-center gap-3 text-fg-primary" />
            </div>
        </AccountShell>
    );
}
