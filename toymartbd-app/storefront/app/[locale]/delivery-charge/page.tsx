import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { PageShell } from '@/components/PageShell';
import { DeliveryEstimator } from '@/components/delivery/DeliveryEstimator';
import { getDeliveryQuote, getDeliveryReference, parseQuoteRequest } from '@/lib/delivery/fetch';
import type { DeliveryQuote } from '@/lib/delivery/types';
import { format, getMessages, isLocale } from '@/lib/i18n';
import { formatPrice } from '@/lib/listing/format';
import { alternatesFor } from '@/lib/seo';

// /delivery-charge — the standalone delivery-charge page (storefront spec §A12.7): the full
// DeliveryEstimator centred at 480 plus the whole zone table below it. It is also where the
// estimator's GET form lands without JavaScript: `?district_id=&upazila_id=&variant_id=&quantity=`
// renders that quote server-side (the same API resolver checkout uses). Reads searchParams, so it
// renders per request.

const PATH = '/delivery-charge';

interface Props {
    params: Promise<{ locale: string }>;
    searchParams: Promise<Record<string, string | string[] | undefined>>;
}

const one = (v: string | string[] | undefined): string | null => (Array.isArray(v) ? (v[0] ?? null) : (v ?? null));

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    const t = getMessages(locale).delivery;
    const query = await searchParams;
    return {
        title: t.pageTitle,
        description: t.pageIntro,
        alternates: alternatesFor(locale, PATH),
        // A quote for one address is not a landing page; the bare table is.
        ...(Object.keys(query).length > 0 ? { robots: { index: false, follow: true } } : {}),
    };
}

export default async function DeliveryChargePage({ params, searchParams }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const t = getMessages(locale).delivery;
    const query = await searchParams;
    const request = parseQuoteRequest((key) => one(query[key]));

    let quote: DeliveryQuote | null = null;
    if (request) {
        try {
            quote = await getDeliveryQuote(request, locale);
        } catch {
            quote = null; // The estimator shows its Error state with the published rates.
        }
    }
    const { rates } = await getDeliveryReference(locale);

    return (
        <PageShell locale={locale} path={PATH} top="tight">
            <div className="mx-auto flex w-full max-w-120 flex-col gap-6 px-4">
                <header className="flex flex-col gap-2">
                    <h1 className="t-h1 text-fg-primary">{t.pageTitle}</h1>
                    <p className="t-body text-fg-secondary">{t.pageIntro}</p>
                </header>

                <DeliveryEstimator
                    locale={locale}
                    variantId={request?.variantId ?? 0}
                    quantity={request?.quantity ?? 1}
                    initial={request ? { selection: { districtId: request.districtId, upazilaId: request.upazilaId }, quote } : undefined}
                />

                {rates ? (
                    <section className="flex flex-col gap-3">
                        <table className="w-full border-collapse overflow-hidden rounded-sm bg-surface">
                            <thead className="bg-subtle">
                                <tr>
                                    <th scope="col" className="t-body-sm-strong px-3 py-2 text-start text-fg-primary">{t.zoneHeading}</th>
                                    <th scope="col" className="t-body-sm-strong px-3 py-2 text-end text-fg-primary">{t.chargeHeading}</th>
                                    <th scope="col" className="t-body-sm-strong px-3 py-2 text-end text-fg-primary">{t.timeHeading}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rates.zones.map((z) => (
                                    <tr key={z.zone} className="border-t border-line-default">
                                        <th scope="row" className="t-body-sm px-3 py-2 text-start font-normal text-fg-primary">{t.zones[z.zone]}</th>
                                        <td className="t-body-sm-strong px-3 py-2 text-end tabular-nums text-fg-primary">{formatPrice(z.charge)}</td>
                                        <td className="t-body-sm px-3 py-2 text-end text-fg-secondary">{format(t.zoneDays, { min: String(z.min_days), max: String(z.max_days) })}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <p className="t-caption text-fg-secondary">{format(t.weightNote, { grams: String(rates.included_weight_grams), charge: formatPrice(rates.per_kg_charge) })}</p>
                        <p className="t-caption text-fg-secondary">{format(t.cutoffNote, { time: rates.cutoff_time })}</p>
                    </section>
                ) : null}
            </div>
        </PageShell>
    );
}
