import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { AccountNotice } from '@/components/account/AccountNotice';
import { AccountShell } from '@/components/account/AccountShell';
import { smallButton } from '@/components/account/styles';
import { Icon } from '@/components/icons/Icon';
import { ProductCard } from '@/components/listing/ProductCard';
import { buttonClass } from '@/components/layout/button-styles';
import { noticeText } from '@/lib/account/format';
import { accountMessages } from '@/lib/account/messages';
import { getWishlist, readAccountFlash, requireAccount } from '@/lib/account/server';
import { format, getMessages, isLocale, localizedPath } from '@/lib/i18n';

// /account/wishlist — My wishlist, Figma 239:13228 (1440) / 238:10450 (390): the saved toys as the
// standard ProductCard (Version B), 3-up on desktop, 2-up on mobile, each with a Remove form. Price,
// was-price and stock are read live from the API on every visit — never what they were when saved.
// A toy that has been archived or unpublished shows as "No longer available" with only Remove.
// Not built yet: back-in-stock alerts and "Share on WhatsApp" / "Copy link" (no backend).

const PATH = '/account/wishlist';

export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ locale: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    return { title: accountMessages(locale).meta.wishlist, robots: { index: false, follow: false } };
}

export default async function WishlistPage({ params }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const m = accountMessages(locale);
    const t = getMessages(locale);
    const { token, account } = await requireAccount(locale, PATH);
    const [items, flash] = await Promise.all([getWishlist(token, locale), readAccountFlash()]);

    return (
        <AccountShell locale={locale} path={PATH} account={account} section="wishlist" title={m.wishlist.title}>
            <header className="flex flex-col gap-1">
                <h1 className="t-h1 text-fg-primary">{m.wishlist.title}</h1>
                <p className="t-body-sm text-fg-secondary tabular-nums">{format(m.wishlist.sub, { count: String(items.length) })}</p>
            </header>

            <AccountNotice kind={flash?.kind ?? 'success'} text={noticeText(flash?.message, m)} />

            {items.length === 0 ? (
                <section className="flex flex-col items-center gap-3 rounded-sm border border-line-default bg-surface px-6 py-10 text-center">
                    <span className="flex size-16 items-center justify-center rounded-full bg-tile-pink text-fg-brand">
                        <Icon name="heartFill" className="size-8" />
                    </span>
                    <h2 className="t-emptystate-title text-fg-primary">{m.wishlist.emptyTitle}</h2>
                    <p className="t-body-sm max-w-90 text-fg-secondary">{m.wishlist.emptyBody}</p>
                    <a href={localizedPath(locale, '/toys')} className={buttonClass('primary', 'md')}>
                        {m.wishlist.emptyCta}
                    </a>
                </section>
            ) : (
                <ul className="grid grid-cols-2 gap-x-3 gap-y-4 lg:grid-cols-3 lg:gap-x-6 lg:gap-y-8">
                    {items.map((item, i) => {
                        const name = item.product ? (locale === 'bn' && item.product.name_bn ? item.product.name_bn : item.product.name) : '';
                        return (
                            <li key={item.product_id} className="flex flex-col gap-2">
                                {item.product ? (
                                    <ProductCard product={item.product} locale={locale} t={t.listing} priority={i < 2} />
                                ) : (
                                    <p className="t-body-sm flex min-h-40 items-center justify-center rounded-sm border border-line-default bg-subtle p-4 text-center text-fg-secondary">{m.wishlist.unavailable}</p>
                                )}
                                <form method="post" action="/api/account/wishlist">
                                    <input type="hidden" name="locale" value={locale} />
                                    <input type="hidden" name="intent" value="remove" />
                                    <input type="hidden" name="product_id" value={item.product_id} />
                                    <button type="submit" aria-label={name ? format(m.wishlist.removeLabel, { name }) : undefined} className={`${smallButton.outline} w-full`}>
                                        <Icon name="trash" className="size-4" />
                                        {m.wishlist.remove}
                                    </button>
                                </form>
                            </li>
                        );
                    })}
                </ul>
            )}
        </AccountShell>
    );
}
