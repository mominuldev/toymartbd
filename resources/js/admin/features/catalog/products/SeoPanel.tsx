import { useId, useState, type ReactNode } from 'react';
import { useWatch, type UseFormReturn } from 'react-hook-form';
import { CheckIcon, ImageIcon, WarningCircleIcon, WarningIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Field, controlHeight, inputClass, monoInputClass } from '@/components/ui/Field';
import { Select } from '@/components/ui/Select';
import { tabClass } from '@/components/ui/Tabs';
import { robotsDirectives, twitterCards, useSeoMeasure, type Product, type SeoChecklistItem, type SeoMeasure } from './api';
import type { FormValues, SeoFields } from './document';
import { TAKA } from './parts';

/** The storefront host as Google and Facebook print it — a domain, not copy. */
const HOST = 'toymartbd.com';

type SeoTab = 'basics' | 'google' | 'social' | 'advanced' | 'schema';

const tabLabel: Record<SeoTab, MessageKey> = {
    basics: 'products.seo.tab.basics',
    google: 'products.seo.tab.google',
    social: 'products.seo.tab.social',
    advanced: 'products.seo.tab.advanced',
    schema: 'products.seo.tab.schema',
};

const tabFields: Record<SeoTab, (keyof SeoFields)[]> = {
    basics: ['title', 'description'],
    google: ['title_bn', 'description_bn'],
    social: ['og_title', 'og_description', 'og_image', 'twitter_card'],
    advanced: ['canonical_url', 'robots', 'sitemap_exclude', 'focus_keyword'],
    schema: ['schema_override'],
};

const robotsLabel: Record<(typeof robotsDirectives)[number], MessageKey> = {
    'index,follow': 'products.seo.robots.indexFollow',
    'noindex,follow': 'products.seo.robots.noindexFollow',
    'index,nofollow': 'products.seo.robots.indexNofollow',
    'noindex,nofollow': 'products.seo.robots.noindexNofollow',
};

const cardLabel: Record<(typeof twitterCards)[number], MessageKey> = {
    summary_large_image: 'products.seo.twitter.large',
    summary: 'products.seo.twitter.summary',
};

type Props = {
    id?: string;
    form: UseFormReturn<FormValues>;
    product?: Product;
    /** The default variant's price as already formatted for display ("1,850"), or null. */
    price: string | null;
    errorOf: (field: `seo.${keyof SeoFields}`) => string | undefined;
};

/**
 * "Search & social" — SeoPanel 198:1123 (Figma "B · 12 Product edit" 241:37939). Basics ·
 * Google preview · Social · Advanced · Schema. Every number on it comes from the API: the
 * pixel meters from `POST /products/seo-measure` (Google's desktop metric, not characters),
 * the checklist and the effective JSON-LD from the saved `Product.seo`. An empty override
 * falls back to the server's default, shown as the placeholder.
 */
export function SeoPanel({ id, form, product, price, errorOf }: Props) {
    const { t } = useI18n();
    const baseId = useId();
    const [tab, setTab] = useState<SeoTab>('basics');
    const { register, control } = form;
    const seo = useWatch({ control, name: 'seo' });
    const name = useWatch({ control, name: 'name' });
    const slug = useWatch({ control, name: 'slug' });
    const saved = product?.seo;
    const defaults = saved?.defaults;

    const title = seo.title.trim() || defaults?.title || name.trim();
    const description = seo.description.trim() || defaults?.description || '';
    const measure = useSeoMeasure(title, description).data ?? saved?.measure;
    const tabHasError = (name: SeoTab) => tabFields[name].some((field) => errorOf(`seo.${field}`));

    const availability = (saved?.schema as { offers?: { availability?: unknown } } | undefined)?.offers?.availability;
    const stockLine = availability === 'https://schema.org/InStock' ? t('products.seo.inStock') : availability === 'https://schema.org/OutOfStock' ? t('products.seo.outOfStock') : null;
    const crumbSlug = slug.trim() || product?.slug || '';

    const serp = (heading: string, serpTitle: string, serpDescription: string, lang?: string) => (
        <div lang={lang} className="flex flex-col gap-1 overflow-hidden rounded-sm border border-line-default bg-surface p-4">
            <p className="t-admin-overline text-fg-tertiary uppercase">{heading}</p>
            <p className="truncate t-admin-caption text-fg-secondary">{[HOST, 'products', crumbSlug].filter(Boolean).join(' › ')}</p>
            <p className="truncate t-admin-card-title text-feedback-info">{serpTitle}</p>
            {(price !== null || stockLine) && (
                <p className="t-admin-caption text-fg-secondary">
                    {price !== null && (
                        <>
                            <span className="t-admin-currency-caption">{TAKA}</span>
                            {price}
                        </>
                    )}
                    {price !== null && stockLine && ' · '}
                    {stockLine}
                </p>
            )}
            <p className="line-clamp-2 t-admin-body text-fg-secondary">{serpDescription || t('products.seo.noDescription')}</p>
        </div>
    );

    const ogCard = (
        <div className="flex w-full shrink-0 flex-col overflow-hidden rounded-sm border border-line-default bg-surface-raised md:w-85">
            <div className="flex h-44.5 items-center justify-center overflow-hidden bg-tile-butter">
                {seo.og_image.trim() || defaults?.og_image ? (
                    <img src={seo.og_image.trim() || defaults?.og_image || ''} alt="" className="size-full object-cover" />
                ) : (
                    <ImageIcon size={32} aria-hidden className="text-fg-secondary" />
                )}
            </div>
            <div className="flex flex-col gap-0.5 px-3 pt-2.5 pb-3">
                <p className="t-admin-overline text-fg-tertiary uppercase">{HOST}</p>
                <p className="truncate t-admin-body-strong text-fg-primary">{seo.og_title.trim() || name.trim() || product?.name}</p>
                <p className="truncate t-admin-caption text-fg-secondary">{seo.og_description.trim() || description}</p>
            </div>
        </div>
    );

    return (
        <section id={id} aria-labelledby={`${baseId}-title`} className="flex scroll-mt-32 flex-col rounded-md border border-line-default bg-surface shadow-e1">
            <div className="flex flex-col gap-1 px-5 pt-5">
                <h2 id={`${baseId}-title`} className="t-admin-section text-fg-primary">
                    {t('products.seo.title')}
                </h2>
                <p className="t-admin-caption text-fg-secondary">{t('products.seo.hint')}</p>
            </div>

            <div role="tablist" aria-label={t('products.seo.title')} className="scrollbar-none flex gap-1 overflow-x-auto border-b border-line-default px-5 pt-2">
                {(Object.keys(tabLabel) as SeoTab[]).map((name) => (
                    <button
                        key={name}
                        type="button"
                        role="tab"
                        id={`${baseId}-tab-${name}`}
                        aria-selected={tab === name}
                        aria-controls={`${baseId}-panel`}
                        onClick={() => setTab(name)}
                        className={tabClass(tab === name)}
                    >
                        {t(tabLabel[name])}
                        {tabHasError(name) && (
                            <>
                                <span aria-hidden className="size-1.5 rounded-full bg-danger" />
                                <span className="sr-only">{t('products.tabHasErrors')}</span>
                            </>
                        )}
                    </button>
                ))}
            </div>

            <div id={`${baseId}-panel`} role="tabpanel" aria-labelledby={`${baseId}-tab-${tab}`} className="flex flex-col gap-4 p-5">
                {tab === 'basics' && (
                    <>
                        <div className="flex flex-col gap-4">
                            <Field label={t('products.seo.metaTitle')} error={errorOf('seo.title')}>
                                {(ids) => <input {...inputProps(ids)} placeholder={defaults?.title} className={`${controlHeight.md} ${inputClass}`} {...register('seo.title')} />}
                            </Field>
                            {measure && <TitleMeter measure={measure.title} />}
                        </div>
                        <Field label={t('products.seo.metaDescription')} hint={measure ? descriptionHelp(measure.description, t) : undefined} error={errorOf('seo.description')}>
                            {(ids) => <input {...inputProps(ids)} placeholder={defaults?.description} className={`${controlHeight.md} ${inputClass}`} {...register('seo.description')} />}
                        </Field>
                        {serp(t('products.seo.serpDesktop'), title, description)}
                        <div className="flex flex-col gap-4 md:flex-row md:items-start">
                            {ogCard}
                            <Checklist product={product} measure={measure} />
                        </div>
                    </>
                )}

                {tab === 'google' && (
                    <>
                        {serp(t('products.seo.serpDesktop'), title, description)}
                        <Field label={t('products.seo.metaTitleBn')} error={errorOf('seo.title_bn')}>
                            {(ids) => <input {...inputProps(ids)} lang="bn" placeholder={defaults?.title_bn} className={`${controlHeight.md} ${inputClass}`} {...register('seo.title_bn')} />}
                        </Field>
                        <Field label={t('products.seo.metaDescriptionBn')} error={errorOf('seo.description_bn')}>
                            {(ids) => <textarea {...inputProps(ids)} lang="bn" rows={2} placeholder={defaults?.description_bn} className={`${inputClass} py-2`} {...register('seo.description_bn')} />}
                        </Field>
                        {serp(t('products.seo.serpBn'), seo.title_bn.trim() || defaults?.title_bn || title, seo.description_bn.trim() || defaults?.description_bn || description, 'bn')}
                    </>
                )}

                {tab === 'social' && (
                    <div className="flex flex-col gap-4 md:flex-row md:items-start">
                        <div className="flex min-w-0 flex-1 flex-col gap-4">
                            <Field label={t('products.seo.ogTitle')} error={errorOf('seo.og_title')}>
                                {(ids) => <input {...inputProps(ids)} placeholder={name.trim() || product?.name} className={`${controlHeight.md} ${inputClass}`} {...register('seo.og_title')} />}
                            </Field>
                            <Field label={t('products.seo.ogDescription')} error={errorOf('seo.og_description')}>
                                {(ids) => <textarea {...inputProps(ids)} rows={2} placeholder={description} className={`${inputClass} py-2`} {...register('seo.og_description')} />}
                            </Field>
                            <Field label={t('products.seo.ogImage')} hint={t('products.seo.ogImageHint')} error={errorOf('seo.og_image')}>
                                {(ids) => <input {...inputProps(ids)} type="url" spellCheck={false} placeholder={defaults?.og_image ?? 'https://'} className={`${controlHeight.md} ${monoInputClass}`} {...register('seo.og_image')} />}
                            </Field>
                            <Field label={t('products.seo.twitterCard')} error={errorOf('seo.twitter_card')}>
                                {(ids) => (
                                    <Select {...inputProps(ids)} {...register('seo.twitter_card')}>
                                        {twitterCards.map((card) => (
                                            <option key={card} value={card}>
                                                {t(cardLabel[card])}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                        </div>
                        {ogCard}
                    </div>
                )}

                {tab === 'advanced' && (
                    <>
                        <Field label={t('products.seo.canonical')} hint={t('products.seo.canonicalHint')} error={errorOf('seo.canonical_url')}>
                            {(ids) => <input {...inputProps(ids)} type="url" spellCheck={false} placeholder={saved?.url} className={`${controlHeight.md} ${monoInputClass}`} {...register('seo.canonical_url')} />}
                        </Field>
                        <Field label={t('products.seo.robots')} error={errorOf('seo.robots')}>
                            {(ids) => (
                                <Select {...inputProps(ids)} {...register('seo.robots')}>
                                    {robotsDirectives.map((value) => (
                                        <option key={value} value={value}>
                                            {t(robotsLabel[value])}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                        <label className="flex min-h-10 items-center gap-3 t-admin-body text-fg-primary">
                            <input type="checkbox" className="size-4 accent-brand" {...register('seo.sitemap_exclude')} />
                            {t('products.seo.sitemapExclude')}
                        </label>
                        <Field label={t('products.seo.focusKeyword')} hint={t('products.seo.focusKeywordHint')} error={errorOf('seo.focus_keyword')}>
                            {(ids) => <input {...inputProps(ids)} className={`${controlHeight.md} ${inputClass}`} {...register('seo.focus_keyword')} />}
                        </Field>
                    </>
                )}

                {tab === 'schema' && (
                    <>
                        <Field label={t('products.seo.schemaOverride')} hint={t('products.seo.schemaOverrideHint')} error={errorOf('seo.schema_override')}>
                            {(ids) => <textarea {...inputProps(ids)} rows={6} spellCheck={false} /* i18n-ignore: a JSON-LD code sample, not copy */ placeholder={'{ "brand": { "@type": "Brand", "name": "…" } }'} className={`${monoInputClass} py-2`} {...register('seo.schema_override')} />}
                        </Field>
                        {saved && (
                            <div className="flex flex-col gap-1.5">
                                <p className="t-admin-body-strong text-fg-primary">{t('products.seo.schemaEffective')}</p>
                                <pre className="max-h-80 overflow-auto rounded-sm bg-subtle p-3.5 t-admin-mono-sm text-fg-secondary">{JSON.stringify(saved.schema, null, 2)}</pre>
                            </div>
                        )}
                    </>
                )}
            </div>
        </section>
    );
}

/** The pixel meter (198:1162): a 4 px track, success while it fits, danger past the limit. */
function TitleMeter({ measure }: { measure: SeoMeasure['title'] }) {
    const { t } = useI18n();
    const fill = Math.min(1, measure.max > 0 ? measure.px / measure.max : 0);
    return (
        <div className="flex items-center gap-2.5">
            <div
                role="meter"
                aria-label={t('products.seo.metaTitle')}
                aria-valuemin={0}
                aria-valuemax={measure.max}
                aria-valuenow={Math.min(measure.px, measure.max)}
                className="h-1 min-w-0 flex-1 overflow-hidden rounded-full bg-subtle md:max-w-125"
            >
                <div className={`h-1 rounded-full ${measure.fits ? 'bg-feedback-success' : 'bg-feedback-danger'}`} style={{ width: `${fill * 100}%` }} />
            </div>
            <p className="shrink-0 t-admin-caption tabular text-fg-secondary">{t('products.seo.titleMeter', { px: measure.px, max: measure.max, chars: measure.chars })}</p>
        </div>
    );
}

function descriptionHelp(measure: SeoMeasure['description'], t: ReturnType<typeof useI18n>['t']): string {
    const params = { px: measure.px, max: measure.max };
    if (measure.chars === 0) return t('products.seo.descEmpty');
    if (!measure.fits) return t('products.seo.descTooLong', params);
    return measure.fits_mobile ? t('products.seo.descFits', params) : t('products.seo.descFitsDesktop', params);
}

/**
 * The checklist (198:1195) the server computed for the saved product. The two width
 * checks follow the live measurement, which is also the server's.
 */
function Checklist({ product, measure }: { product?: Product; measure?: SeoMeasure }) {
    const { t } = useI18n();
    const saved = product?.seo.checklist.items ?? [];
    const live = (item: SeoChecklistItem): SeoChecklistItem => {
        if (!measure) return item;
        if (item.key === 'title_fits') return { ...item, status: measure.title.fits ? 'pass' : 'fail', params: { px: measure.title.px, max: measure.title.max } };
        if (item.key === 'description_fits') {
            const d = measure.description;
            return { ...item, status: d.chars === 0 ? 'fail' : d.fits ? 'pass' : 'warn', params: { px: d.px, max: d.max } };
        }
        return item;
    };
    const items: SeoChecklistItem[] = saved.length > 0
        ? saved.map(live)
        : measure
          ? [live({ key: 'title_fits', status: 'pass', params: {} }), live({ key: 'description_fits', status: 'pass', params: {} })]
          : [];
    const passed = items.filter((i) => i.status === 'pass').length;

    return (
        <div className="flex min-w-0 flex-1 flex-col gap-2 rounded-sm bg-subtle p-3.5">
            <p className="t-admin-overline text-fg-tertiary uppercase">{t('products.seo.checklist', { passed, total: items.length })}</p>
            <ul className="flex flex-col gap-2">
                {items.map((item) => (
                    <li key={item.key} className="flex items-start gap-1.5 t-admin-caption text-fg-primary">
                        <StatusIcon status={item.status} />
                        <span>{checkLabel(item, t)}</span>
                    </li>
                ))}
            </ul>
            {saved.length === 0 && <p className="t-admin-caption text-fg-secondary">{t('products.seo.checklistAfterSave')}</p>}
        </div>
    );
}

function StatusIcon({ status }: { status: SeoChecklistItem['status'] }): ReactNode {
    if (status === 'pass') return <CheckIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-success" />;
    if (status === 'warn') return <WarningIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-warning" />;
    return <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />;
}

function checkLabel(item: SeoChecklistItem, t: ReturnType<typeof useI18n>['t']): string {
    const p = item.params;
    const n = (key: string) => Number(p[key] ?? 0);
    switch (item.key) {
        case 'title_fits':
            return item.status === 'pass' ? t('products.seo.check.titlePass', { px: n('px') }) : t('products.seo.check.titleFail', { px: n('px'), max: n('max') });
        case 'description_fits':
            return item.status === 'pass' ? t('products.seo.check.descPass') : item.status === 'warn' ? t('products.seo.check.descWarn', { px: n('px'), max: n('max') }) : t('products.seo.check.descFail');
        case 'primary_image':
            return item.status === 'pass'
                ? t('products.seo.check.imagePass', { width: n('width'), height: n('height') })
                : item.status === 'warn'
                  ? t('products.seo.check.imageWarn', { width: n('width'), height: n('height'), min: n('min') })
                  : t('products.seo.check.imageFail');
        case 'image_alt': {
            if (item.status === 'pass') return t('products.seo.check.altPass');
            const en = n('missing_en');
            const bn = n('missing_bn');
            if (en === 0) return t('products.seo.check.altBn', { count: bn });
            if (bn === 0) return t('products.seo.check.altEn', { count: en });
            return t('products.seo.check.altBoth', { en, bn });
        }
        case 'slug':
            return item.status === 'pass' ? t('products.seo.check.slugPass') : t('products.seo.check.slugFail');
        case 'product_schema':
            return item.status === 'pass' ? t('products.seo.check.schemaPass') : t('products.seo.check.schemaFail');
        case 'sitemap': {
            if (item.status === 'pass') return t('products.seo.check.sitemapPass');
            const reason = String(p.reason ?? '');
            return reason === 'noindex' ? t('products.seo.check.sitemapNoindex') : reason === 'excluded' ? t('products.seo.check.sitemapExcluded') : t('products.seo.check.sitemapNotLive');
        }
        default:
            return item.key;
    }
}

function inputProps({ id, describedBy, invalid }: { id: string; describedBy: string | undefined; invalid: boolean }) {
    return { id, 'aria-describedby': describedBy, 'aria-invalid': invalid || undefined };
}
