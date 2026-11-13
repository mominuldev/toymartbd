import { useEffect, useId, useState } from 'react';
import { useForm, useWatch, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { saveSeoMetaRequestSchema } from '@toymart/api-types/zod';
import { CheckIcon, WarningIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { Field, controlHeight, inputClass, monoInputClass } from '@/components/ui/Field';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { tabClass } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';
import { robotsDirectives, twitterCards, useSaveSeoDocument, useSeoDocument, useSerpPreview, type SeoDocument, type SeoInput, type SeoLocale, type SerpPreview } from './api';

type FormValues = {
    title: string;
    title_bn: string;
    description: string;
    description_bn: string;
    og_title: string;
    og_title_bn: string;
    og_description: string;
    og_description_bn: string;
    og_image: string;
    canonical_url: string;
    robots: SeoInput['robots'];
    sitemap_exclude: boolean;
    twitter_card: SeoInput['twitter_card'];
    focus_keyword: string;
};

type Tab = SeoLocale | 'advanced';

const textFields = ['title', 'title_bn', 'description', 'description_bn', 'og_title', 'og_title_bn', 'og_description', 'og_description_bn', 'og_image', 'canonical_url', 'focus_keyword'] as const;
const allFields: (keyof FormValues)[] = [...textFields, 'robots', 'sitemap_exclude', 'twitter_card'];

const tabFields: Record<Tab, (keyof FormValues)[]> = {
    en: ['title', 'description', 'og_title', 'og_description'],
    bn: ['title_bn', 'description_bn', 'og_title_bn', 'og_description_bn'],
    advanced: ['og_image', 'twitter_card', 'canonical_url', 'robots', 'sitemap_exclude', 'focus_keyword'],
};

const robotsLabel: Record<SeoInput['robots'], MessageKey> = {
    'index,follow': 'seo.panel.robots.indexFollow',
    'noindex,follow': 'seo.panel.robots.noindexFollow',
    'index,nofollow': 'seo.panel.robots.indexNofollow',
    'noindex,nofollow': 'seo.panel.robots.noindexNofollow',
};

const cardLabel: Record<SeoInput['twitter_card'], MessageKey> = {
    summary_large_image: 'seo.panel.twitter.large',
    summary: 'seo.panel.twitter.summary',
};

const validateInput = zodResolver(saveSeoMetaRequestSchema);

function toForm(doc: SeoDocument): FormValues {
    const text = (value: string | null) => value ?? '';
    return {
        title: text(doc.title),
        title_bn: text(doc.title_bn),
        description: text(doc.description),
        description_bn: text(doc.description_bn),
        og_title: text(doc.og_title),
        og_title_bn: text(doc.og_title_bn),
        og_description: text(doc.og_description),
        og_description_bn: text(doc.og_description_bn),
        og_image: text(doc.og_image),
        canonical_url: text(doc.canonical_url),
        robots: doc.robots,
        sitemap_exclude: doc.sitemap_exclude,
        twitter_card: doc.twitter_card,
        focus_keyword: text(doc.focus_keyword),
    };
}

/** Empty text means "use the server default", sent as null. */
function toInput(values: FormValues): SeoInput {
    const orNull = (value: string) => (value.trim() === '' ? null : value.trim());
    return {
        title: orNull(values.title),
        title_bn: orNull(values.title_bn),
        description: orNull(values.description),
        description_bn: orNull(values.description_bn),
        og_title: orNull(values.og_title),
        og_title_bn: orNull(values.og_title_bn),
        og_description: orNull(values.og_description),
        og_description_bn: orNull(values.og_description_bn),
        og_image: orNull(values.og_image),
        canonical_url: orNull(values.canonical_url),
        robots: values.robots,
        sitemap_exclude: values.sitemap_exclude,
        twitter_card: values.twitter_card,
        focus_keyword: orNull(values.focus_keyword),
    };
}

/**
 * The per-entity "Search & social" panel (SeoPanel 198:1123, docs/api/seo.md §2) for any
 * page type with a registered SEO subject — categories, brands, CMS pages, posts. Self-
 * loading and self-saving: it is its own form, so it sits beside an entity's form, never
 * inside it. English and Bangla carry separate title, description and share text (P-06c);
 * the SERP preview's truncation is the server's (rule 1), never the browser's.
 */
export function SeoPanel({ type, id }: { type: string; id: number }) {
    const doc = useSeoDocument(type, id);

    if (doc.isError) return <QueryError error={doc.error} onRetry={() => void doc.refetch()} />;
    if (!doc.data) return <p aria-busy="true" className="sr-only" />;

    return <SeoForm key={`${type}-${id}`} type={type} id={id} doc={doc.data} />;
}

function SeoForm({ type, id, doc }: { type: string; id: number; doc: SeoDocument }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const baseId = useId();
    const [tab, setTab] = useState<Tab>('en');
    const save = useSaveSeoDocument(type, id);
    const editable = can(me, 'seo.manage');

    const form = useForm<FormValues>({
        defaultValues: toForm(doc),
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values), context, options as never);
            return Object.keys(result.errors).length > 0 ? { values: {}, errors: result.errors as never } : { values, errors: {} };
        },
    });
    const { register, handleSubmit, setError, formState, reset, control } = form;
    useEffect(() => reset(toForm(doc)), [doc, reset]);

    const errorOf = (field: keyof FormValues) => formState.errors[field]?.message;
    const tabHasError = (name: Tab) => tabFields[name].some((field) => errorOf(field));

    const submit = handleSubmit((values) =>
        save.mutate(toInput(values), {
            onSuccess: () => notify(t('seo.panel.saved')),
            onError: (error) => {
                if (error instanceof ApiError && error.status === 422) {
                    for (const field of allFields) {
                        const message = error.fieldError(field);
                        if (message) setError(field as FieldPath<FormValues>, { message });
                    }
                } else {
                    notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
                }
            },
        }),
    );

    const tabs: { key: Tab; label: string; lang?: string }[] = [
        { key: 'en', label: t('seo.panel.tab.en') },
        { key: 'bn', label: t('seo.panel.tab.bn'), lang: 'bn' },
        { key: 'advanced', label: t('seo.panel.tab.advanced') },
    ];

    return (
        <form onSubmit={submit} noValidate aria-labelledby={`${baseId}-title`} className="flex min-w-0 flex-col rounded-md border border-line-default bg-surface shadow-e1">
            <div className="flex flex-col gap-1 px-5 pt-5">
                <h2 id={`${baseId}-title`} className="t-admin-section text-fg-primary">
                    {t('seo.panel.title')}
                </h2>
                <p className="t-admin-caption text-fg-secondary">{t('seo.panel.hint')}</p>
            </div>

            <div role="tablist" aria-label={t('seo.panel.title')} className="scrollbar-none flex gap-1 overflow-x-auto border-b border-line-default px-5 pt-2">
                {tabs.map((item) => (
                    <button
                        key={item.key}
                        type="button"
                        role="tab"
                        lang={item.lang}
                        id={`${baseId}-tab-${item.key}`}
                        aria-selected={tab === item.key}
                        aria-controls={`${baseId}-panel`}
                        onClick={() => setTab(item.key)}
                        className={tabClass(tab === item.key)}
                    >
                        {item.label}
                        {tabHasError(item.key) && (
                            <>
                                <span aria-hidden className="size-1.5 rounded-full bg-danger" />
                                <span className="sr-only">{t('seo.panel.tabHasErrors')}</span>
                            </>
                        )}
                    </button>
                ))}
            </div>

            <fieldset disabled={!editable || save.isPending} id={`${baseId}-panel`} role="tabpanel" aria-labelledby={`${baseId}-tab-${tab}`} className="flex min-w-0 flex-col gap-4 p-5">
                {tab !== 'advanced' && <LocaleFields key={tab} locale={tab} doc={doc} form={form} control={control} errorOf={errorOf} />}

                {tab === 'advanced' && (
                    <>
                        <Field label={t('seo.panel.ogImage')} hint={t('seo.panel.ogImageHint')} error={errorOf('og_image')} optional>
                            {(ids) => <input {...inputProps(ids)} type="url" spellCheck={false} placeholder={doc.defaults.og_image ?? 'https://'} className={`${controlHeight.md} ${monoInputClass}`} {...register('og_image')} />}
                        </Field>
                        <Field label={t('seo.panel.twitterCard')} error={errorOf('twitter_card')}>
                            {(ids) => (
                                <Select {...inputProps(ids)} {...register('twitter_card')}>
                                    {twitterCards.map((card) => (
                                        <option key={card} value={card}>
                                            {t(cardLabel[card])}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                        <Field label={t('seo.panel.canonical')} hint={t('seo.panel.canonicalHint')} error={errorOf('canonical_url')} optional>
                            {(ids) => <input {...inputProps(ids)} type="url" spellCheck={false} placeholder={doc.urls.en} className={`${controlHeight.md} ${monoInputClass}`} {...register('canonical_url')} />}
                        </Field>
                        <Field label={t('seo.panel.robots')} error={errorOf('robots')}>
                            {(ids) => (
                                <Select {...inputProps(ids)} {...register('robots')}>
                                    {robotsDirectives.map((value) => (
                                        <option key={value} value={value}>
                                            {t(robotsLabel[value])}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                        <label className="flex min-h-10 items-center gap-3 t-admin-body text-fg-primary">
                            <input type="checkbox" className="size-4 accent-brand" {...register('sitemap_exclude')} />
                            {t('seo.panel.sitemapExclude')}
                        </label>
                        <Field label={t('seo.panel.focusKeyword')} hint={t('seo.panel.focusKeywordHint')} error={errorOf('focus_keyword')} optional>
                            {(ids) => <input {...inputProps(ids)} className={`${controlHeight.md} ${inputClass}`} {...register('focus_keyword')} />}
                        </Field>
                        <p className="flex items-center gap-1.5 t-admin-caption text-fg-secondary">
                            {doc.in_sitemap ? <CheckIcon size={14} aria-hidden className="text-feedback-success" /> : <WarningIcon size={14} aria-hidden className="text-feedback-warning" />}
                            {doc.in_sitemap ? t('seo.panel.inSitemap') : doc.is_live ? t('seo.panel.notInSitemap') : t('seo.panel.notLive')}
                        </p>
                    </>
                )}
            </fieldset>

            {editable && (
                <div className="flex flex-wrap items-center justify-end gap-3 border-t border-line-default px-5 py-3.5">
                    <Button variant="ghost" disabled={!formState.isDirty || save.isPending} onClick={() => reset(toForm(doc))}>
                        {t('common.discard')}
                    </Button>
                    <Button type="submit" disabled={save.isPending}>
                        {save.isPending ? t('common.saving') : t('seo.panel.save')}
                    </Button>
                </div>
            )}
        </form>
    );
}

type LocaleFieldsProps = {
    locale: SeoLocale;
    doc: SeoDocument;
    form: ReturnType<typeof useForm<FormValues>>;
    control: ReturnType<typeof useForm<FormValues>>['control'];
    errorOf: (field: keyof FormValues) => string | undefined;
};

function LocaleFields({ locale, doc, form, control, errorOf }: LocaleFieldsProps) {
    const { t } = useI18n();
    const { register } = form;
    const bn = locale === 'bn';
    const f = (base: 'title' | 'description' | 'og_title' | 'og_description') => (bn ? (`${base}_bn` as const) : base);
    const lang = bn ? 'bn' : undefined;
    const values = useWatch({ control });

    const title = (values[f('title')] ?? '').trim() || (bn ? doc.defaults.title_bn : doc.defaults.title);
    const description = (values[f('description')] ?? '').trim() || (bn ? doc.defaults.description_bn : doc.defaults.description);
    const saved = doc.serp[locale];
    const changed = title !== saved.title || description !== saved.description;
    const live = useSerpPreview(title, description, doc.urls[locale], changed);
    const preview: SerpPreview = changed && live.data ? live.data : saved;

    return (
        <>
            <Field label={t(bn ? 'seo.panel.metaTitleBn' : 'seo.panel.metaTitle')} error={errorOf(f('title'))} optional>
                {(ids) => <input {...inputProps(ids)} lang={lang} placeholder={bn ? doc.defaults.title_bn : doc.defaults.title} className={`${controlHeight.md} ${inputClass}`} {...register(f('title'))} />}
            </Field>
            <Field label={t(bn ? 'seo.panel.metaDescriptionBn' : 'seo.panel.metaDescription')} error={errorOf(f('description'))} optional>
                {(ids) => <textarea {...inputProps(ids)} lang={lang} rows={2} placeholder={bn ? doc.defaults.description_bn : doc.defaults.description} className={`${inputClass} py-2`} {...register(f('description'))} />}
            </Field>

            <SerpCard preview={preview} lang={lang} heading={t(bn ? 'seo.panel.serpBn' : 'seo.panel.serpEn')} />

            <div className="grid gap-4 md:grid-cols-2">
                <Field label={t(bn ? 'seo.panel.ogTitleBn' : 'seo.panel.ogTitle')} error={errorOf(f('og_title'))} optional>
                    {(ids) => <input {...inputProps(ids)} lang={lang} placeholder={title} className={`${controlHeight.md} ${inputClass}`} {...register(f('og_title'))} />}
                </Field>
                <Field label={t(bn ? 'seo.panel.ogDescriptionBn' : 'seo.panel.ogDescription')} error={errorOf(f('og_description'))} optional>
                    {(ids) => <textarea {...inputProps(ids)} lang={lang} rows={2} placeholder={description} className={`${inputClass} py-2`} {...register(f('og_description'))} />}
                </Field>
            </div>
        </>
    );
}

/** A Google desktop result as the server truncates it (SeoPanel "Google preview"). */
function SerpCard({ preview, heading, lang }: { preview: SerpPreview; heading: string; lang?: string }) {
    const { t } = useI18n();
    return (
        <div className="flex flex-col gap-2">
            <div lang={lang} className="flex flex-col gap-1 overflow-hidden rounded-sm border border-line-default bg-surface p-4" data-testid="serp-preview">
                <p className="t-admin-overline text-fg-tertiary uppercase">{heading}</p>
                <p className="truncate t-admin-caption text-fg-secondary">{preview.url_display}</p>
                <p className="t-admin-card-title text-feedback-info">{preview.title_display}</p>
                <p className="t-admin-body text-fg-secondary">{preview.description_display || t('seo.panel.noDescription')}</p>
            </div>
            <ul className="flex flex-wrap gap-x-4 gap-y-1 t-admin-caption tabular text-fg-secondary">
                <li className={preview.title_truncated ? 'text-feedback-danger' : undefined}>
                    {t(preview.title_truncated ? 'seo.panel.titleCut' : 'seo.panel.titleFits', { px: preview.title_px, max: preview.title_max })}
                </li>
                <li className={preview.description_truncated ? 'text-feedback-warning' : undefined}>
                    {t(preview.description_truncated ? 'seo.panel.descCut' : 'seo.panel.descFits', { px: preview.description_px, max: preview.description_max })}
                </li>
            </ul>
        </div>
    );
}

function inputProps({ id, describedBy, invalid }: { id: string; describedBy: string | undefined; invalid: boolean }) {
    return { id, 'aria-describedby': describedBy, 'aria-invalid': invalid || undefined };
}
