import { useState, type ReactNode } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { ArrowUUpLeftIcon, PlusIcon, TrashIcon } from '@phosphor-icons/react';
import { cmsPageRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Field, inputClass } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { FormActions } from '@/components/ui/FormActions';
import { Select } from '@/components/ui/Select';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { DescriptionEditor } from '@/features/catalog/products/DescriptionEditor';
import { PageStateChip } from '../shared/PageStateChip';
import { apiFieldErrors, issuesToErrors, type FieldErrors } from '../shared/errors';
import { SITE_HOST } from './PagesPage';
import { useArchivePage, usePage, useSavePage, type CmsPage } from './api';
import { toPageForm, toPagePayload, type FaqRow, type PageForm } from './form';

const URL_PREFIX = `${SITE_HOST}/pages/`;

/** Create and edit a static page. No dedicated Figma frame: Product edit (B · 12 241:37883) language. */
export function PageFormPage() {
    const params = useParams();
    const id = params.pageId ? Number(params.pageId) : null;
    const page = usePage(id);

    if (id !== null && page.isError) return <QueryError error={page.error} onRetry={() => void page.refetch()} />;
    if (id !== null && !page.data) return <p aria-busy="true" className="sr-only" />;

    return <PageEditor key={page.data?.id ?? 'new'} id={id} page={page.data} />;
}

function PageEditor({ id, page }: { id: number | null; page?: CmsPage }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const navigate = useNavigate();
    const save = useSavePage(id);
    const archive = useArchivePage(id ?? 0);
    const [form, setForm] = useState<PageForm>(() => toPageForm(page));
    const [errors, setErrors] = useState<FieldErrors>({});
    const [confirming, setConfirming] = useState(false);
    const editable = can(me, 'content.manage');

    const set = <K extends keyof PageForm>(key: K, value: PageForm[K]) => setForm((f) => ({ ...f, [key]: value }));
    const setSeo = <K extends keyof PageForm['seo']>(key: K, value: PageForm['seo'][K]) => setForm((f) => ({ ...f, seo: { ...f.seo, [key]: value } }));
    const setFaq = (index: number, patch: Partial<FaqRow>) => setForm((f) => ({ ...f, faq: f.faq.map((row, i) => (i === index ? { ...row, ...patch } : row)) }));
    const err = (field: string) => errors[field];

    const slugChanged = page !== undefined && page.state === 'live' && form.slug.trim() !== '' && form.slug.trim() !== page.slug;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const payload = toPagePayload(form, page);
        const parsed = cmsPageRequestSchema.safeParse(payload);
        const local: FieldErrors = parsed.success ? {} : issuesToErrors(parsed.error.issues);
        if (form.template === 'faq' && form.status === 'published' && form.faq.length === 0) local.faq_items = t('content.pages.faq.empty');
        if (slugChanged && !form.acknowledge_slug_redirect) local.slug = t('products.slug.redirectTitle');
        setErrors(local);
        if (Object.keys(local).length > 0) return;

        save.mutate(payload, {
            onSuccess: (saved) => {
                notify(t(id === null ? 'content.pages.created' : 'content.pages.saved'));
                if (id === null) navigate(`/store/pages/${saved.id}`, { replace: true });
                else setForm(toPageForm(saved));
            },
            onError: (error) => {
                const fields = apiFieldErrors(error);
                if (fields) setErrors(fields);
                else notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
            },
        });
    };

    const title = id === null ? t('content.pages.new') : t('content.pages.edit');
    const text = (key: keyof PageForm, label: MessageKey, opts: { lang?: string; hint?: string; optional?: boolean } = {}): ReactNode => (
        <Field label={t(label)} hint={opts.hint} error={err(key)} optional={opts.optional}>
            {({ id: fid, describedBy, invalid }) => (
                <input
                    id={fid}
                    lang={opts.lang}
                    aria-describedby={describedBy}
                    aria-invalid={invalid || undefined}
                    className={`h-10 ${inputClass}`}
                    value={form[key] as string}
                    onChange={(e) => set(key, e.target.value as never)}
                />
            )}
        </Field>
    );
    const seoText = (key: keyof PageForm['seo'], label: MessageKey, opts: { lang?: string; hint?: string; area?: boolean } = {}): ReactNode => (
        <Field label={t(label)} hint={opts.hint} error={err(`seo.${key}`)} optional>
            {({ id: fid, describedBy, invalid }) =>
                opts.area ? (
                    <textarea
                        id={fid}
                        rows={2}
                        lang={opts.lang}
                        aria-describedby={describedBy}
                        aria-invalid={invalid || undefined}
                        className={`py-2 ${inputClass}`}
                        value={form.seo[key] as string}
                        onChange={(e) => setSeo(key, e.target.value as never)}
                    />
                ) : (
                    <input
                        id={fid}
                        lang={opts.lang}
                        aria-describedby={describedBy}
                        aria-invalid={invalid || undefined}
                        className={`h-10 ${inputClass}`}
                        value={form.seo[key] as string}
                        onChange={(e) => setSeo(key, e.target.value as never)}
                    />
                )
            }
        </Field>
    );

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('content.crumb.store') }, { label: t('content.pages.backToList'), to: '/store/pages' }, { label: title }]}
                title={page?.title || title}
                titleAddon={page && <PageStateChip page={page} />}
            />

            <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                <fieldset disabled={!editable || save.isPending} className="grid min-w-0 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
                    <div className="flex min-w-0 flex-col gap-6">
                        <FormSection title={t('content.pages.section.content')}>
                            <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                                {text('title', 'content.pages.field.title')}
                                {text('title_bn', 'content.pages.field.titleBn', { lang: 'bn', hint: t('content.pages.field.titleBnHint'), optional: true })}
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <span className="t-admin-body-strong text-fg-primary">{t('content.pages.field.body')}</span>
                                <DescriptionEditor
                                    value={{ en: form.content, bn: form.content_bn }}
                                    onChange={(language, value) => set(language === 'en' ? 'content' : 'content_bn', value)}
                                    error={err('content') ?? err('content_bn')}
                                    invalid={{ en: Boolean(err('content')), bn: Boolean(err('content_bn')) }}
                                    disabled={!editable}
                                />
                            </div>
                        </FormSection>

                        {form.template === 'faq' && (
                            <FormSection
                                title={t('content.pages.section.faq')}
                                aside={
                                    <Button variant="outline" size="sm" onClick={() => set('faq', [...form.faq, { question: '', question_bn: '', answer: '', answer_bn: '' }])}>
                                        <PlusIcon size={16} aria-hidden />
                                        {t('content.pages.faq.add')}
                                    </Button>
                                }
                            >
                                {err('faq_items') && <p className="t-admin-caption text-fg-primary">{err('faq_items')}</p>}
                                <ol className="flex flex-col gap-4">
                                    {form.faq.map((row, index) => (
                                        <li key={index} className="flex flex-col gap-3 rounded-sm border border-line-default p-4">
                                            <div className="flex justify-end">
                                                <IconButton aria-label={t('content.pages.faq.remove', { n: index + 1 })} size="sm" onClick={() => set('faq', form.faq.filter((_, i) => i !== index))}>
                                                    <TrashIcon size={16} aria-hidden />
                                                </IconButton>
                                            </div>
                                            <div className="grid gap-x-4 gap-y-3 md:grid-cols-2">
                                                {(['question', 'question_bn', 'answer', 'answer_bn'] as const).map((key) => (
                                                    <Field
                                                        key={key}
                                                        label={t(`content.pages.faq.${key === 'question' ? 'question' : key === 'question_bn' ? 'questionBn' : key === 'answer' ? 'answer' : 'answerBn'}`, { n: index + 1 })}
                                                        error={err(`faq_items.${index}.${key}`)}
                                                        optional={key.endsWith('_bn')}
                                                    >
                                                        {({ id: fid, describedBy, invalid }) => (
                                                            <textarea
                                                                id={fid}
                                                                rows={key.startsWith('answer') ? 3 : 1}
                                                                lang={key.endsWith('_bn') ? 'bn' : undefined}
                                                                aria-describedby={describedBy}
                                                                aria-invalid={invalid || undefined}
                                                                className={`py-2 ${inputClass}`}
                                                                value={row[key]}
                                                                onChange={(e) => setFaq(index, { [key]: e.target.value })}
                                                            />
                                                        )}
                                                    </Field>
                                                ))}
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            </FormSection>
                        )}

                        <FormSection title={t('content.pages.section.seo')}>
                            <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                                {seoText('title', 'content.pages.seo.title', { hint: page?.seo ? t('content.pages.seo.defaultHint', { value: page.seo.defaults.title }) : undefined })}
                                {seoText('title_bn', 'content.pages.seo.titleBn', { lang: 'bn', hint: page?.seo ? t('content.pages.seo.defaultHint', { value: page.seo.defaults.title_bn }) : undefined })}
                                {seoText('description', 'content.pages.seo.description', { area: true, hint: page?.seo?.defaults.description ? t('content.pages.seo.defaultHint', { value: page.seo.defaults.description }) : undefined })}
                                {seoText('description_bn', 'content.pages.seo.descriptionBn', { area: true, lang: 'bn' })}
                                {seoText('og_title', 'content.pages.seo.ogTitle')}
                                {seoText('og_description', 'content.pages.seo.ogDescription', { area: true })}
                                {seoText('og_image', 'content.pages.seo.ogImage')}
                                {seoText('canonical_url', 'content.pages.seo.canonical')}
                                {seoText('focus_keyword', 'content.pages.seo.focusKeyword')}
                                <Field label={t('content.pages.seo.robots')} error={err('seo.robots')}>
                                    {({ id: fid }) => (
                                        <Select id={fid} value={form.seo.robots} onChange={(e) => setSeo('robots', e.target.value as PageForm['seo']['robots'])}>
                                            {(['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'] as const).map((r) => (
                                                <option key={r} value={r}>
                                                    {r}
                                                </option>
                                            ))}
                                        </Select>
                                    )}
                                </Field>
                                <Field label={t('content.pages.seo.twitterCard')}>
                                    {({ id: fid }) => (
                                        <Select id={fid} value={form.seo.twitter_card} onChange={(e) => setSeo('twitter_card', e.target.value as PageForm['seo']['twitter_card'])}>
                                            <option value="summary_large_image">{t('content.pages.seo.twitter.summary_large_image')}</option>
                                            <option value="summary">{t('content.pages.seo.twitter.summary')}</option>
                                        </Select>
                                    )}
                                </Field>
                            </div>
                            <label className="flex min-h-touch-min items-center gap-3 t-admin-body text-fg-primary">
                                <input type="checkbox" className="size-4 accent-brand" checked={form.seo.sitemap_exclude} onChange={(e) => setSeo('sitemap_exclude', e.target.checked)} />
                                {t('content.pages.seo.sitemapExclude')}
                            </label>
                        </FormSection>
                    </div>

                    <aside className="flex min-w-0 flex-col gap-6">
                        <FormSection title={t('content.pages.section.publish')}>
                            <Field label={t('content.pages.field.status')} error={err('status')}>
                                {({ id: fid }) => (
                                    <Select id={fid} value={form.status} onChange={(e) => set('status', e.target.value as PageForm['status'])}>
                                        {(['draft', 'published', 'archived'] as const).map((s) => (
                                            <option key={s} value={s}>
                                                {t(`content.pages.status.option.${s}`)}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            <Field label={t('content.pages.field.publishAt')} hint={t('content.pages.field.publishAtHint')} error={err('published_at')} optional>
                                {({ id: fid, describedBy, invalid }) => (
                                    <input
                                        id={fid}
                                        type="datetime-local"
                                        aria-describedby={describedBy}
                                        aria-invalid={invalid || undefined}
                                        className={`h-10 tabular ${inputClass}`}
                                        value={form.published_at}
                                        onChange={(e) => set('published_at', e.target.value)}
                                    />
                                )}
                            </Field>
                            <Field label={t('content.pages.field.template')} error={err('template')}>
                                {({ id: fid }) => (
                                    <Select id={fid} value={form.template} onChange={(e) => set('template', e.target.value as PageForm['template'])}>
                                        {(['default', 'sidebar', 'faq'] as const).map((tpl) => (
                                            <option key={tpl} value={tpl}>
                                                {t(`content.pages.template.${tpl}`)}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                        </FormSection>

                        {/* SlugField 198:1086, as on the product form. */}
                        <div className="flex flex-col gap-2">
                            <Field label={t('content.pages.field.slug')} hint={slugChanged ? undefined : t('content.pages.field.slugHint')} error={err('slug')} optional={id === null}>
                                {({ id: fid, describedBy, invalid }) => (
                                    <span className="flex h-10 items-stretch overflow-hidden rounded-sm border border-line-default bg-surface focus-within:border-line-focus focus-within:ring-1 focus-within:ring-inset focus-within:ring-line-focus has-aria-invalid:border-line-danger">
                                        <span aria-hidden className="flex items-center border-e border-line-default bg-subtle px-3 t-admin-mono-sm text-fg-tertiary">
                                            {'/pages/'}
                                        </span>
                                        <input
                                            id={fid}
                                            aria-describedby={describedBy}
                                            aria-invalid={invalid || undefined}
                                            spellCheck={false}
                                            autoCapitalize="none"
                                            className="min-w-0 flex-1 bg-transparent px-3 t-admin-mono text-fg-primary outline-none"
                                            value={form.slug}
                                            onChange={(e) => set('slug', e.target.value)}
                                        />
                                    </span>
                                )}
                            </Field>
                            {slugChanged && page && (
                                <div className="flex items-start gap-3 rounded-sm border border-feedback-warning bg-subtle p-4">
                                    <ArrowUUpLeftIcon size={20} aria-hidden className="shrink-0 text-feedback-warning" />
                                    <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                                        <p className="t-admin-body-strong text-fg-primary">{t('products.slug.redirectTitle')}</p>
                                        <p className="t-admin-mono-sm break-all whitespace-pre-wrap text-fg-secondary">{`${URL_PREFIX}${page.slug}  →  ${URL_PREFIX}${form.slug.trim()}`}</p>
                                        <p className="t-admin-caption text-fg-secondary">{t('products.slug.redirectBody')}</p>
                                        <label className="flex min-h-11 items-center gap-3 t-admin-body text-fg-primary">
                                            <input type="checkbox" className="size-5 accent-brand" checked={form.acknowledge_slug_redirect} onChange={(e) => set('acknowledge_slug_redirect', e.target.checked)} />
                                            {t('products.slug.redirectAck')}
                                        </label>
                                    </div>
                                </div>
                            )}
                        </div>
                    </aside>
                </fieldset>

                <FormActions
                    start={
                        page && editable && page.state !== 'archived' ? (
                            <Button variant="danger" onClick={() => setConfirming(true)}>
                                {t('content.pages.archive')}
                            </Button>
                        ) : undefined
                    }
                >
                    <Link to="/store/pages" className={buttonClass('ghost')}>
                        {t('common.cancel')}
                    </Link>
                    {editable && (
                        <Button type="submit" disabled={save.isPending}>
                            {save.isPending ? t('common.saving') : t('common.save')}
                        </Button>
                    )}
                </FormActions>
            </form>

            {page && (
                <ConfirmDialog
                    open={confirming}
                    title={t('content.pages.archiveTitle', { title: page.title })}
                    body={t('content.pages.archiveBody')}
                    confirmLabel={t('content.pages.archive')}
                    pending={archive.isPending}
                    onClose={() => setConfirming(false)}
                    onConfirm={() =>
                        archive.mutate(undefined, {
                            onSuccess: () => {
                                setConfirming(false);
                                notify(t('content.pages.archived'));
                                navigate('/store/pages', { replace: true });
                            },
                        })
                    }
                />
            )}
        </section>
    );
}
