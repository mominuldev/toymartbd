import { useId, useState, type ReactNode } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { ArrowUUpLeftIcon, SquaresFourIcon } from '@phosphor-icons/react';
import { cmsPageRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Field, inputClass } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { FormActions } from '@/components/ui/FormActions';
import { Select } from '@/components/ui/Select';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { SeoPanel } from '@/features/seo';
import { PageStateChip } from '../shared/PageStateChip';
import { apiFieldErrors, issuesToErrors, type FieldErrors } from '../shared/errors';
import { SITE_HOST } from './PagesPage';
import { useArchivePage, usePage, useSavePage, type CmsPage } from './api';
import { toPageForm, toPagePayload, type PageForm } from './form';

const URL_PREFIX = `${SITE_HOST}/`;

/**
 * Create a static page and edit its settings — title, URL, status, template; its SEO panel sits under the details. No dedicated
 * Figma frame: Product edit (B · 12 241:37883) language. The page body is sections, edited in
 * the page builder (owner decision 2026-11-16); "Edit sections" opens it, and a new page goes
 * straight there once created.
 */
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
    const formId = useId();

    const set = <K extends keyof PageForm>(key: K, value: PageForm[K]) => setForm((f) => ({ ...f, [key]: value }));
    const err = (field: string) => errors[field];

    const slugChanged = page !== undefined && page.state === 'live' && form.slug.trim() !== '' && form.slug.trim() !== page.slug;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const payload = toPagePayload(form, page);
        const parsed = cmsPageRequestSchema.safeParse(payload);
        const local: FieldErrors = parsed.success ? {} : issuesToErrors(parsed.error.issues);
        if (slugChanged && !form.acknowledge_slug_redirect) local.slug = t('products.slug.redirectTitle');
        setErrors(local);
        if (Object.keys(local).length > 0) return;

        save.mutate(payload, {
            onSuccess: (saved) => {
                notify(t(id === null ? 'content.pages.created' : 'content.pages.saved'));
                if (id === null) navigate(`/store/pages/${saved.id}/build`, { replace: true });
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

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('content.crumb.store') }, { label: t('content.pages.backToList'), to: '/store/pages' }, { label: title }]}
                title={page?.title || title}
                titleAddon={page && <PageStateChip page={page} />}
                actions={
                    page && (
                        <Link to={`/store/pages/${page.id}/build`} className={buttonClass('primary')}>
                            <SquaresFourIcon size={16} aria-hidden />
                            {t('content.pages.editSections')}
                        </Link>
                    )
                }
            />

            {/*
              * One grid for the page: details and the SEO panel on the left, publishing on the right.
              * The SEO panel is its own <form> (forms cannot nest), so the page form wraps only the
              * details; the rail's fields are React state like the rest, and Save joins the form
              * through its `form` attribute. The sticky save bar closes the page, below everything.
              */}
            <div className="grid min-w-0 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
                <div className="flex min-w-0 flex-col gap-6">
                    <form id={formId} onSubmit={submit} noValidate>
                        <fieldset disabled={!editable || save.isPending} className="flex min-w-0 flex-col gap-6">
                            <FormSection title={t('content.pages.section.details')}>
                                <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                                    {text('title', 'content.pages.field.title')}
                                    {text('title_bn', 'content.pages.field.titleBn', { lang: 'bn', hint: t('content.pages.field.titleBnHint'), optional: true })}
                                </div>
                                <p className="t-admin-caption text-fg-secondary">{t(id === null ? 'content.pages.sectionsHintNew' : 'content.pages.sectionsHint')}</p>
                            </FormSection>

                            {id === null && (
                                <FormSection title={t('content.pages.section.seo')}>
                                    <p className="t-admin-caption text-fg-secondary">{t('content.pages.seoAfterSave')}</p>
                                </FormSection>
                            )}
                        </fieldset>
                    </form>

                    {/* Full SEO (owner request 2026-11-18): previews, score, Bangla share text, Media Library share image. */}
                    {page && can(me, 'seo.view') && <SeoPanel type="page" id={page.id} />}
                </div>

                <fieldset disabled={!editable || save.isPending} className="min-w-0">
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
                            {/* `faq` belonged to the old FAQ editor: offered only to a page that still has it, so a save sends it back. */}
                            <Field label={t('content.pages.field.template')} error={err('template') ?? err('faq_items')}>
                                {({ id: fid }) => (
                                    <Select id={fid} value={form.template} onChange={(e) => set('template', e.target.value as PageForm['template'])}>
                                        {(page?.template === 'faq' ? (['default', 'sidebar', 'faq'] as const) : (['default', 'sidebar'] as const)).map((tpl) => (
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
                                            {'/'}
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
            </div>

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
                    <Button type="submit" form={formId} disabled={save.isPending}>
                        {save.isPending ? t('common.saving') : t('common.save')}
                    </Button>
                )}
            </FormActions>

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
