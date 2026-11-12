import type { ReactNode } from 'react';

import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import { format, type Locale, type Messages } from '@/lib/i18n';
import { ageLabel, formatPrice } from '@/lib/listing/format';
import { descriptionContext, sanitizeDescription } from '@/lib/product/description';
import type { DeliveryRates } from '@/lib/product/fetch';
import { AGE_SCALE, ageRange, bandMatches, weightLabel } from '@/lib/product/format';
import type { ProductVariant, StorefrontProduct } from '@/lib/product/variants';
import { TabsEnhancer } from './TabsEnhancer';

interface Props {
    product: StorefrontProduct;
    variant: ProductVariant;
    locale: Locale;
    t: Messages;
    rates: DeliveryRates | null;
}

type Size = 'desktop' | 'mobile';

/** The rich-text description in tokens: Body/lg (its Bangla variant carries the 1.15× line-height), 20 between blocks. */
const descriptionClass = [
    'pdp-description flex flex-col gap-5 t-body-lg text-fg-primary',
    '[&_h2]:t-h4 [&_h3]:t-body-strong',
    '[&_ul]:list-disc [&_ul]:ps-6 [&_ol]:list-decimal [&_ol]:ps-6 [&_li]:mt-1',
    '[&_blockquote]:border-s-4 [&_blockquote]:border-line-default [&_blockquote]:ps-4 [&_blockquote]:text-fg-secondary',
    '[&_a]:text-fg-link [&_a]:underline',
    '[&_img]:h-auto [&_img]:max-w-full [&_img]:rounded-lg',
].join(' ');

const pick = (locale: Locale, en: string | null, bn: string | null): string | null => (locale === 'bn' ? (bn ?? en) : en);

// ProductInfoPanel — Figma 157:2626 (page 03 §05). Desktop (1280, 158:4142): a `Tabs/Item Underline`
// bar over one panel; Mobile (326, 158:8533): four `Accordion/Item Flush` rows with leading icon and
// subtitle, only the first open. Both are rendered; CSS shows one per breakpoint. Both work without
// JavaScript (fragment links + :target; native <details>).
//
// Content is what the API holds. Figma also draws "Why kids love it" tiles, a "What's in the box"
// checklist, a parent tip, a "most loved by" line, skill chips, three safety tiles and care text —
// the catalogue has no fields for those yet, so they are omitted rather than invented (reported).
// The fifth desktop tab, "Reviews", scrolls to a Reviews section that does not exist yet: omitted.
export function ProductInfoPanel({ product, variant, locale, t, rates }: Props) {
    const range = ageRange(product.age_min_months, product.age_max_months, t);
    const under3 = (product.age_min_months ?? 0) < 36;
    // "From ৳70": the API lists zones cheapest first (inside Dhaka); no comparison of money here (rule 1).
    const cheapest = rates?.zones[0]?.charge ?? null;
    const specRows = specifications(product, variant, locale, t);

    const sections: Array<{ id: string; tab: string; title: string; subtitle: string; icon: IconName; body: (size: Size) => ReactNode }> = [
        {
            id: 'description',
            tab: t.pdp.tabDescription,
            title: t.pdp.accDescription,
            subtitle: t.pdp.accDescriptionSub,
            icon: 'puzzlePiece',
            body: (size) => <Description product={product} locale={locale} size={size} />,
        },
        {
            id: 'specifications',
            tab: t.pdp.tabSpecifications,
            title: t.pdp.tabSpecifications,
            subtitle: specRows
                .slice(0, 3)
                .map((r) => r.value)
                .join(' · '),
            icon: 'list',
            body: (size) => <Specifications rows={specRows} size={size} />,
        },
        {
            id: 'age',
            tab: t.pdp.tabAge,
            title: t.pdp.tabAge,
            subtitle: range ? format(under3 ? t.pdp.accAgeSubUnder3 : t.pdp.accAgeSub, { range }) : '',
            icon: 'shieldCheckFill',
            body: (size) => <AgeSafety product={product} locale={locale} t={t} size={size} />,
        },
        {
            id: 'delivery',
            tab: t.pdp.tabDelivery,
            title: t.pdp.tabDelivery,
            subtitle: cheapest ? format(t.pdp.accDeliverySub, { amount: formatPrice(cheapest) }) : t.pdp.accDeliverySubNoRate,
            icon: 'truck',
            body: (size) => <DeliveryReturns rates={rates} t={t} size={size} />,
        },
    ];

    return (
        <section id="pdp-details" aria-label={t.pdp.details} className="scroll-mt-36">
            <div id="pdp-tabs" className="pdp-tabs hidden lg:block">
                <div role="tablist" aria-label={t.pdp.details} className="flex gap-2 border-b border-line-default">
                    {sections.map((s, i) => (
                        <a
                            key={s.id}
                            href={`#pdp-tab-${s.id}`}
                            role="tab"
                            aria-selected={i === 0}
                            aria-controls={`pdp-tab-${s.id}`}
                            className="pdp-tab t-body-strong relative flex h-11 min-w-18 items-center justify-center px-4 whitespace-nowrap text-fg-secondary hover:rounded-t-md hover:bg-subtle"
                        >
                            {s.tab}
                            <span aria-hidden="true" className="pdp-tab-bar absolute inset-x-0 bottom-0 hidden h-0.5 rounded-full bg-brand" />
                        </a>
                    ))}
                </div>
                {sections.map((s, i) => (
                    <div key={s.id} id={`pdp-tab-${s.id}`} role="tabpanel" aria-label={s.tab} data-first={i === 0 ? '' : undefined} className="pdp-panel flex scroll-mt-48 items-start gap-12 pt-8 pb-10">
                        {s.body('desktop')}
                    </div>
                ))}
                <TabsEnhancer rootId="pdp-tabs" />
            </div>
            <div className="lg:hidden">
                {sections.map((s, i) => (
                    <details key={s.id} name="pdp-details" open={i === 0} className="pdp-accordion border-b border-line-default last:border-b-0">
                        <summary className="flex min-h-14 cursor-pointer items-center gap-3 p-4">
                            <Icon name={s.icon} className="size-icon-md text-fg-primary" />
                            <span className="flex min-w-0 grow flex-col gap-1">
                                <span className="pdp-acc-title t-accordion-title text-fg-primary">{s.title}</span>
                                {s.subtitle ? <span className="t-body-sm text-fg-secondary">{s.subtitle}</span> : null}
                            </span>
                            <Icon name="caretDown" className="pdp-acc-chevron size-icon-md text-fg-primary transition-transform duration-(--tm-duration-base)" />
                        </summary>
                        <div className="flex flex-col gap-6 px-4 pb-6">{s.body('mobile')}</div>
                    </details>
                ))}
            </div>
        </section>
    );
}

function Description({ product, locale, size }: { product: StorefrontProduct; locale: Locale; size: Size }) {
    const text = pick(locale, product.description, product.description_bn) ?? pick(locale, product.short_description, product.short_description_bn);
    const photo = product.images[1] ?? product.images[0];
    const lang = locale === 'bn' && !product.description_bn ? 'en' : undefined;
    // The API sends sanitised HTML; it is filtered again here before it is set as markup.
    const html = sanitizeDescription(text, descriptionContext(process.env.API_URL, product.images.map((i) => i.url)));
    const body = html ? (
        <div
            lang={lang}
            className={`${descriptionClass} ${locale === 'bn' && !lang ? 'lang-bn' : ''}`}
            dangerouslySetInnerHTML={{ __html: html }}
        />
    ) : null;
    if (size === 'mobile') return body;
    return (
        <>
            <div className="flex w-190 shrink-0 flex-col gap-5">{body}</div>
            {photo ? (
                <div className="flex w-118 shrink-0 flex-col gap-5">
                    <div className="h-85 overflow-hidden rounded-lg-plus bg-tile-pink">
                        <img src={photo.url} alt="" width={photo.width ?? undefined} height={photo.height ?? undefined} loading="lazy" decoding="async" className="size-full object-cover" />
                    </div>
                </div>
            ) : null}
        </>
    );
}

interface Row {
    key: string;
    value: string;
    lang?: 'en';
}

function specifications(product: StorefrontProduct, variant: ProductVariant, locale: Locale, t: Messages): Row[] {
    const rows: Row[] = [];
    if (product.brand) {
        const name = pick(locale, product.brand.name, product.brand.name_bn) ?? product.brand.name;
        rows.push({ key: t.pdp.specBrand, value: name, lang: locale === 'bn' && !product.brand.name_bn ? 'en' : undefined });
    }
    rows.push({ key: t.pdp.specItemCode, value: variant.sku, lang: 'en' });
    const age = ageLabel(product.age_min_months, product.age_max_months, t.listing);
    if (age) rows.push({ key: t.pdp.specAge, value: age });
    const weight = weightLabel(variant.weight_grams, t);
    if (weight) rows.push({ key: t.pdp.specWeight, value: weight });
    if (product.warranty_months) rows.push({ key: t.pdp.specWarranty, value: format(t.pdp.warrantyMonths, { count: String(product.warranty_months) }) });
    // Free-form specification pairs are stored in one language (the admin form has no Bangla twin yet).
    for (const spec of product.specifications) rows.push({ key: spec.key, value: spec.value, lang: locale === 'bn' ? 'en' : undefined });
    return rows;
}

function Specifications({ rows, size }: { rows: Row[]; size: Size }) {
    const half = Math.ceil(rows.length / 2);
    const tables = size === 'desktop' ? [rows.slice(0, half), rows.slice(half)].filter((r) => r.length > 0) : [rows];
    return (
        <>
            {tables.map((table, ti) => (
                <dl key={ti} className="flex min-w-0 flex-1 flex-col overflow-hidden rounded-lg border border-line-default">
                    {table.map((row, i) => (
                        <div key={row.key} className={`flex items-center gap-4 px-4 py-3.5 lg:px-5 ${i % 2 === 0 ? 'bg-tile-butter' : 'bg-surface'}`}>
                            <dt className="t-body-sm w-28 shrink-0 text-fg-secondary lg:w-45">{row.key}</dt>
                            <dd lang={row.lang} className="t-body-strong min-w-0 grow text-fg-primary">
                                {row.value}
                            </dd>
                        </div>
                    ))}
                </dl>
            ))}
        </>
    );
}

function AgeSafety({ product, locale, t, size }: { product: StorefrontProduct; locale: Locale; t: Messages; size: Size }) {
    const range = ageRange(product.age_min_months, product.age_max_months, t);
    const note = pick(locale, product.safety_note, product.safety_note_bn);
    const card = range ? (
        <div className="flex flex-col gap-3.5 rounded-lg-plus bg-tile-lavender p-5">
            <div className="flex items-center gap-3">
                <span aria-hidden="true" className="flex size-11 shrink-0 items-center justify-center rounded-md-minus bg-surface text-fg-primary">
                    <Icon name="smiley" className="size-icon-lg" />
                </span>
                <p className="t-h4 text-fg-primary">{format(t.pdp.bestFor, { range })}</p>
            </div>
            <ol aria-label={t.pdp.ageScale} className="flex gap-1">
                {AGE_SCALE.map((band) => {
                    const on = bandMatches(band, product.age_min_months, product.age_max_months);
                    return (
                        <li key={band.key} className="flex min-w-0 flex-1 flex-col items-center gap-1.5">
                            <span aria-hidden="true" className={`h-2.5 w-full rounded-full ${on ? 'bg-grape' : 'bg-surface'}`} />
                            <span className={`t-caption whitespace-nowrap ${on ? 'text-fg-primary' : 'text-fg-secondary'}`}>{t.pdp.ageBands[band.key]}</span>
                        </li>
                    );
                })}
            </ol>
        </div>
    ) : null;
    const alert = note ? (
        <div role="note" className="flex items-start gap-3 rounded-md border border-feedback-warning bg-surface p-4">
            <Icon name="warning" className="mt-0.5 size-icon-md text-feedback-warning" />
            <div className="flex min-w-0 flex-col gap-1">
                <p className="t-alert-title text-fg-primary">{t.pdp.safetyTitle}</p>
                <p className="t-body-sm text-fg-secondary" lang={locale === 'bn' && !product.safety_note_bn ? 'en' : undefined}>
                    {note}
                </p>
            </div>
        </div>
    ) : null;
    if (size === 'mobile') {
        return (
            <>
                {card}
                {alert}
            </>
        );
    }
    return (
        <>
            <div className="flex w-155 shrink-0 flex-col gap-5">{card}</div>
            <div className="flex min-w-0 flex-1 flex-col gap-5">{alert}</div>
        </>
    );
}

function DeliveryReturns({ rates, t, size }: { rates: DeliveryRates | null; t: Messages; size: Size }) {
    const steps = [
        { title: t.pdp.returnStep1, body: t.pdp.returnStep1Body, tile: 'bg-tile-pink', ink: 'text-fg-ink-pink' },
        { title: t.pdp.returnStep2, body: t.pdp.returnStep2Body, tile: 'bg-tile-butter', ink: 'text-fg-ink-butter' },
        { title: t.pdp.returnStep3, body: t.pdp.returnStep3Body, tile: 'bg-tile-mint', ink: 'text-fg-ink-mint' },
    ];
    const delivery = rates ? (
        <div className={`flex flex-col gap-5 ${size === 'desktop' ? 'w-140 shrink-0' : ''}`}>
            <h3 className="t-h4 text-fg-primary">{t.pdp.deliveryTitle}</h3>
            <table className="w-full overflow-hidden rounded-lg border border-separate border-spacing-0 border-line-default">
                <thead>
                    <tr className="t-caption-strong bg-tile-sky text-left text-fg-ink-sky">
                        <th scope="col" className="px-4 py-3.5 font-semibold lg:px-5">
                            {t.pdp.zoneArea}
                        </th>
                        <th scope="col" className="w-20 py-3.5 font-semibold">
                            {t.pdp.zoneCharge}
                        </th>
                        <th scope="col" className="w-22.5 py-3.5 pr-4 font-semibold lg:pr-5">
                            {t.pdp.zoneArrives}
                        </th>
                    </tr>
                </thead>
                <tbody className="bg-surface">
                    {rates.zones.map((zone) => (
                        <tr key={zone.zone}>
                            <th scope="row" className="t-body border-t border-line-default px-4 py-3.5 text-left font-normal text-fg-primary lg:px-5">
                                {t.pdp.zones[zone.zone]}
                            </th>
                            <td className="t-body-strong border-t border-line-default py-3.5 text-fg-primary tabular-nums">{formatPrice(zone.charge)}</td>
                            <td className="t-body border-t border-line-default py-3.5 pr-4 text-fg-secondary tabular-nums lg:pr-5">
                                {format(t.pdp.zoneDays, { min: String(zone.min_days), max: String(zone.max_days) })}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {rates.free_shipping_threshold ? (
                <p className="t-body-sm-strong inline-flex items-center gap-2 self-start rounded-full bg-success-subtle px-3.5 py-2 text-fg-ink-mint">
                    <Icon name="truck" className="size-4.5" />
                    {format(t.pdp.freeOver, { amount: formatPrice(rates.free_shipping_threshold) })}
                </p>
            ) : null}
        </div>
    ) : null;
    return (
        <>
            {delivery}
            <div className="flex min-w-0 flex-1 flex-col gap-5">
                <h3 className="t-h4 text-fg-primary">{t.pdp.returnsTitle}</h3>
                <ol className="flex flex-col gap-3 lg:flex-row">
                    {steps.map((step, i) => (
                        <li key={step.title} className={`flex flex-1 flex-col gap-2.5 rounded-lg p-4 ${step.tile}`}>
                            <span aria-hidden="true" className={`t-body-strong flex size-9 items-center justify-center rounded-md-plus bg-surface ${step.ink}`}>
                                {i + 1}
                            </span>
                            <span className="flex flex-col gap-0.5">
                                <span className="t-body-strong text-fg-primary">{step.title}</span>
                                <span className="t-body-sm text-fg-secondary">{step.body}</span>
                            </span>
                        </li>
                    ))}
                </ol>
                <p className="t-body-sm text-fg-secondary">{t.pdp.returnsNote}</p>
            </div>
        </>
    );
}
