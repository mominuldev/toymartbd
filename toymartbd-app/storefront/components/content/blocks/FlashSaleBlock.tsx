import { CountdownTimer } from '@/components/flash-sale/CountdownTimer';
import { ProductRail } from '@/components/listing/ProductRail';
import { getFlashSales } from '@/lib/flash-sale/fetch';
import { format, getMessages, localizedPath, type Locale } from '@/lib/i18n';

// Flash-sale rail (storefront spec §B1.3, Figma Home "Flash sale" band): the running sale that ends
// soonest — its products in the listing rail and its countdown in the header, on the brand-subtle
// band of the /flash-sale page. The sale is Marketing's data, read from GET /storefront/flash-sales
// (ISR tags `flash-sale` + `products`, revalidated at every sale boundary). No running sale, or a
// sale with no products: the block renders nothing (a band with no sale is removed, §B1.3).

export async function FlashSaleBlock({ id, title, limit, locale }: { id: string; title: string; limit: number; locale: Locale }) {
    const data = await getFlashSales(locale);
    const sale = [...(data?.sales ?? [])].filter((s) => s.products.length > 0).sort((a, b) => Date.parse(a.endsAt) - Date.parse(b.endsAt))[0];
    if (!sale) return null;

    const t = getMessages(locale);
    const headingId = `cms-${id}-title`;
    return (
        <section aria-labelledby={headingId} className="-mx-gutter-mobile flex flex-col gap-3 bg-brand-subtle px-gutter-mobile py-6 md:mx-0 md:rounded-md md:px-6 lg:gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 id={headingId} className="t-h2 text-fg-primary">
                    {title}
                </h2>
                <CountdownTimer endsAt={sale.endsAt} size="sm" t={t.flashSale} renderedAt={data?.serverTime} className="lg:hidden" />
                <CountdownTimer endsAt={sale.endsAt} size="md" t={t.flashSale} renderedAt={data?.serverTime} className="max-lg:hidden" />
            </div>
            <ProductRail
                id={`${headingId}-rail`}
                title={locale === 'bn' && sale.nameBn ? sale.nameBn : sale.name}
                products={sale.products.slice(0, limit)}
                locale={locale}
                t={t.listing}
                action={{ href: localizedPath(locale, '/flash-sale'), label: t.home.seeAll, ariaLabel: format(t.home.seeAllLabel, { section: title }) }}
            />
        </section>
    );
}
