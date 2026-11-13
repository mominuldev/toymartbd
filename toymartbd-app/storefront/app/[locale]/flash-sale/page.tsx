import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { CountdownTimer } from '@/components/flash-sale/CountdownTimer';
import { PageShell } from '@/components/PageShell';
import { ProductCard } from '@/components/listing/ProductCard';
import { dhakaDateTime } from '@/lib/flash-sale/format';
import { getFlashSales } from '@/lib/flash-sale/fetch';
import { format, getMessages, isLocale, localizedPath } from '@/lib/i18n';
import { staticMetadata } from '@/lib/seo/metadata';

// /flash-sale (P7, storefront spec §B1.3 band language, §A15 countdown). Server Component, ISR under
// `flash-sale` + `products` (lib/flash-sale/fetch.ts). Each running sale: its name, an md countdown
// (sm below 1024) that renders the end time without JavaScript, and its cards — each card carries
// its own flash price and badge from the API. No sale running → an empty state naming the next
// sale's start (Dhaka time). Prices are the API's strings (rule 1).

interface Props {
    params: Promise<{ locale: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    const t = getMessages(locale).flashSale;
    // SEO: agent 4 — staticMetadata gives canonical + hreflang (en, bn, x-default) for /flash-sale.
    return staticMetadata(locale, '/flash-sale', t.metaTitle, t.metaDescription);
}

export default async function FlashSalePage({ params }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const messages = getMessages(locale);
    const t = messages.flashSale;
    const data = await getFlashSales(locale);
    const sales = (data?.sales ?? []).filter((s) => s.products.length > 0);
    const bn = locale === 'bn';

    return (
        <PageShell locale={locale} path="/flash-sale">
            <h1 className="t-h1 text-fg-primary">{t.title}</h1>
            {sales.length === 0 ? (
                <section className="mt-6 flex flex-col items-start gap-3 rounded-sm bg-brand-subtle px-6 py-10">
                    <p className="t-h3 text-fg-primary">{t.empty}</p>
                    <p className="t-body text-fg-secondary">{data?.next ? format(t.nextSale, { time: dhakaDateTime(data.next.startsAt) }) : t.noNext}</p>
                    <a href={localizedPath(locale, '/toys')} className="t-button mt-2 inline-flex h-control-md items-center rounded-full bg-brand px-6 text-fg-on-brand">
                        {t.browse}
                    </a>
                </section>
            ) : (
                sales.map((sale) => {
                    const title = bn && sale.nameBn ? sale.nameBn : sale.name;
                    return (
                        <section key={sale.id} aria-labelledby={`flash-${sale.id}`} className="-mx-gutter-mobile mt-6 flex flex-col gap-3 bg-brand-subtle px-gutter-mobile py-6 md:mx-0 md:rounded-md md:px-6 lg:gap-4">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h2 id={`flash-${sale.id}`} className="t-h2 text-fg-primary" lang={bn && !sale.nameBn ? 'en' : undefined}>
                                    {title}
                                </h2>
                                <CountdownTimer endsAt={sale.endsAt} size="sm" t={t} renderedAt={data?.serverTime} className="lg:hidden" />
                                <CountdownTimer endsAt={sale.endsAt} size="md" t={t} renderedAt={data?.serverTime} className="max-lg:hidden" />
                            </div>
                            <ul className="grid grid-cols-2 gap-x-3 gap-y-4 md:grid-cols-3 md:gap-x-4 lg:gap-x-6 lg:gap-y-8 2xl:grid-cols-4">
                                {sale.products.map((product, i) => (
                                    <li key={product.id}>
                                        <ProductCard product={product} locale={locale} t={messages.listing} priority={i < 2} />
                                    </li>
                                ))}
                            </ul>
                        </section>
                    );
                })
            )}
        </PageShell>
    );
}
