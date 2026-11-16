import { CountdownTimer } from '@/components/flash-sale/CountdownTimer';
import { ProductCard } from '@/components/listing/ProductCard';
import { railRowClass } from '@/components/listing/ProductRail';
import { getFlashSales } from '@/lib/flash-sale/fetch';
import { dhakaDateTime } from '@/lib/flash-sale/format';
import { format, getMessages, type Locale } from '@/lib/i18n';

// Flash-sale band (storefront spec §B1.3, Figma Home "FlashSale" 133:431 / 126:325): a full-bleed
// Gradient/Bloom-sunrise band — title with the end time under it, the countdown on the right (md
// 288 × 64 from 1024, the sm pill below), then the running sale's cards: a swipe rail of 148 cards
// below 1024 and a 5-up row from 1024. The cards are the compact rail cards (discount badge only;
// the band carries the countdown). The sale is Marketing's data, read from GET /storefront/flash-sales
// (ISR tags `flash-sale` + `products`, revalidated at every sale boundary): the running sale that
// ends soonest. No running sale, or a sale with no products: nothing renders (a band with no sale
// is removed, §B1.3). The band draws its own gutters, so the page places it outside a Band.

const DESKTOP_CARDS = 5;

export async function FlashSaleBlock({ id, title, limit, locale }: { id: string; title: string; limit: number; locale: Locale }) {
    const data = await getFlashSales(locale);
    const sale = [...(data?.sales ?? [])].filter((s) => s.products.length > 0).sort((a, b) => Date.parse(a.endsAt) - Date.parse(b.endsAt))[0];
    if (!sale) return null;

    const t = getMessages(locale);
    const headingId = `cms-${id}-title`;
    return (
        <section aria-labelledby={headingId} className="bg-(image:--tm-gradient-bloom-sunrise) px-gutter-mobile pt-6 md:px-gutter-tablet lg:px-gutter-desktop lg:py-12">
            <div className="mx-auto flex max-w-content flex-col gap-3 lg:gap-6">
                <div className="flex items-center justify-between gap-3">
                    <div className="flex min-w-0 flex-col">
                        <h2 id={headingId} className="t-h2 text-fg-primary">
                            {title}
                        </h2>
                        <p className="t-caption text-fg-secondary lg:t-body-sm">{format(t.flashSale.endsAt, { time: dhakaDateTime(sale.endsAt) })}</p>
                    </div>
                    <CountdownTimer endsAt={sale.endsAt} size="sm" t={t.flashSale} renderedAt={data?.serverTime} className="shrink-0 lg:hidden" />
                    <CountdownTimer endsAt={sale.endsAt} size="md" t={t.flashSale} renderedAt={data?.serverTime} className="shrink-0 max-lg:hidden" />
                </div>
                <ul className={`${railRowClass(DESKTOP_CARDS)} lg:pt-2 lg:pb-6`}>
                    {sale.products.slice(0, limit).map((product, i) => (
                        <li key={product.id} className={i >= DESKTOP_CARDS ? 'lg:hidden' : undefined}>
                            <ProductCard product={product} locale={locale} t={t.listing} compact />
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
