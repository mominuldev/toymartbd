import { useState, type FormEvent } from 'react';
import { useNavigate, useParams } from 'react-router';
import { CalendarBlankIcon, CheckIcon, EyeIcon, ImageIcon, TrashIcon, WarningIcon, XIcon } from '@phosphor-icons/react';
import { blogPostRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Field, inputClass, monoInputClass } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { FormActions } from '@/components/ui/FormActions';
import { Select } from '@/components/ui/Select';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { DescriptionEditor } from '@/features/catalog/products/DescriptionEditor';
import { useSeoMeasure } from '@/features/catalog/products/api';
import { MediaPickerModal, type InsertFailure } from '@/features/media/picker';
import type { Media } from '@/features/media/api';
import { SeoPanel } from '@/features/seo';
import { apiFieldErrors, issuesToErrors, type FieldErrors } from '../shared/errors';
import { formatDhakaDateTime } from '../shared/dhaka';
import { postStatuses, postTypes, useDeletePost, usePost, usePreviewLink, useSavePost, useTerms, type BlogPost, type PostStatus } from './api';
import { languageState, toPostForm, toPostPayload, type PostForm } from './form';
import { GuideSections } from './GuideSections';
import { PostStatusChip, cardClass } from './parts';
import { ProductLinksField } from './ProductLinksField';

/** "2:20 pm" when saved today (Dhaka), else the Dhaka date and time — Figma "Draft · last saved 2:20 pm". */
function savedTime(iso: string, locale: string): string {
    const day = (d: Date) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(d);
    const at = new Date(iso);
    if (day(at) !== day(new Date())) return formatDhakaDateTime(iso, locale);
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-US', { timeZone: 'Asia/Dhaka', hour: 'numeric', minute: '2-digit', hour12: true, numberingSystem: 'latn' }).format(at).toLowerCase();
}

/** Online store → Blog → New / Edit post (Figma B · 28 Blog editor, 241:46011). */
export function BlogPostEditorPage() {
    const params = useParams();
    const id = params.postId ? Number(params.postId) : null;
    const post = usePost(id);

    if (id !== null && post.isError) return <QueryError error={post.error} onRetry={() => void post.refetch()} />;
    if (id !== null && !post.data) return <p aria-busy="true" className="sr-only" />;

    return <PostEditor key={post.data?.id ?? 'new'} id={id} post={post.data} />;
}

function PostEditor({ id, post }: { id: number | null; post?: BlogPost }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const navigate = useNavigate();
    const save = useSavePost(id);
    const remove = useDeletePost(id ?? 0);
    const preview = usePreviewLink(id);
    const categories = useTerms('categories');
    const authors = useTerms('authors');
    const tags = useTerms('tags');
    const [form, setForm] = useState<PostForm>(() => toPostForm(post));
    const [errors, setErrors] = useState<FieldErrors>({});
    const [picking, setPicking] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [seoOpen, setSeoOpen] = useState(false);
    const [savedAt, setSavedAt] = useState<string | null>(post?.updated_at ?? null);
    const editable = can(me, 'content.manage');
    const disabled = !editable || save.isPending;

    const set = <K extends keyof PostForm>(key: K, value: PostForm[K]) => setForm((f) => ({ ...f, [key]: value }));
    const errorOf = (field: string) => errors[field];
    const measure = useSeoMeasure(form.seo_title.trim() || form.title, form.excerpt);
    const titleMeasure = measure.data?.title;

    const nameOf = (term: { name: string; name_bn: string | null } | undefined) => (term ? (locale === 'bn' && term.name_bn ? term.name_bn : term.name) : undefined);
    const category = categories.data?.find((c) => String(c.id) === form.category_id);
    const author = authors.data?.find((a) => String(a.id) === form.author_id);
    const live = post?.is_live ?? false;
    const slugChanged = live && post !== undefined && form.slug.trim() !== '' && form.slug.trim() !== post.slug;

    const submit = (status: PostStatus) => {
        const payload = toPostPayload(form, status);
        const parsed = blogPostRequestSchema.safeParse(payload);
        const local: FieldErrors = parsed.success ? {} : issuesToErrors(parsed.error.issues);
        if (status === 'scheduled' && form.publish_at.trim() === '') local.publish_at = t('blog.settings.publishAtHint');
        setErrors(local);
        if (Object.keys(local).length > 0) return;

        save.mutate(payload, {
            onSuccess: (saved) => {
                setSavedAt(saved.updated_at);
                const message =
                    saved.status === 'scheduled' && saved.published_at
                        ? t('blog.editor.scheduled', { time: formatDhakaDateTime(saved.published_at, locale) })
                        : saved.status === 'published'
                          ? t('blog.editor.published')
                          : t(id === null ? 'blog.editor.created' : 'blog.editor.saved');
                notify(message);
                if (id === null) navigate(`/store/blog/${saved.id}`, { replace: true });
                else setForm(toPostForm(saved));
            },
            onError: (error) => {
                const fields = apiFieldErrors(error);
                if (fields) setErrors(fields);
                else notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
            },
        });
    };

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        submit(form.status);
    };

    const openPreview = () => {
        if (id === null) {
            notify(t('blog.editor.previewSaveFirst'), 'danger');
            return;
        }
        preview.mutate(undefined, {
            onSuccess: (link) => window.open(locale === 'bn' ? link.url_bn : link.url, '_blank', 'noopener'),
            onError: () => notify(t('blog.editor.previewFailed'), 'danger'),
        });
    };

    const insertCover = async (picked: Media[]): Promise<InsertFailure[]> => {
        const media = picked[0];
        if (!media) return [];
        if (media.processing_status !== 'ready') return [{ media, reason: t('blog.editor.coverNotReady') }];
        set('cover', { media_id: media.id, url: media.urls.large ?? media.urls.medium ?? media.urls.thumb, alt: media.alt_text, alt_bn: media.alt_text_bn });
        setPicking(false);
        return [];
    };

    const title = id === null ? t('blog.editor.new') : t('blog.editor.edit');
    const statusLabel = (status: PostStatus): string => {
        if (status === 'draft') return savedAt && post?.status === 'draft' ? t('blog.status.draftSaved', { time: savedTime(savedAt, locale) }) : t('blog.status.draft');
        if (status === 'scheduled' && post?.status === 'scheduled' && post.published_at) return t('blog.status.scheduledFor', { time: formatDhakaDateTime(post.published_at, locale) });
        if (status === 'published' && post?.status === 'published' && post.published_at) return t('blog.status.publishedOn', { time: formatDhakaDateTime(post.published_at, locale) });
        return t(`blog.status.${status}`);
    };
    const productLinks = form.related.length + form.sections.reduce((sum, s) => sum + s.picks.length, 0);
    const checks: Array<{ ok: boolean; label: string }> = [
        titleMeasure ? { ok: titleMeasure.fits, label: t(titleMeasure.fits ? 'blog.seo.check.titleFits' : 'blog.seo.check.titleTooLong') } : null,
        { ok: true, label: t(productLinks > 0 ? 'blog.seo.check.schema' : 'blog.seo.check.schemaArticle') },
        form.cover
            ? { ok: Boolean(form.cover.alt_bn?.trim()), label: t(form.cover.alt_bn?.trim() ? 'blog.seo.check.coverAlt' : 'blog.seo.check.coverAltBn') }
            : { ok: false, label: t('blog.seo.check.noCover') },
        { ok: productLinks > 0, label: productLinks > 0 ? t('blog.seo.check.links', { count: productLinks }) : t('blog.seo.check.noLinks') },
        { ok: languageState(form) === 'both', label: t(languageState(form) === 'both' ? 'blog.seo.check.bangla' : 'blog.seo.check.noBangla') },
    ].filter((c): c is { ok: boolean; label: string } => c !== null);
    const remainingTags = (tags.data ?? []).filter((tag) => !form.tag_ids.includes(tag.id));

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('content.crumb.store') }, { label: t('content.crumb.online'), to: '/store' }, { label: t('blog.crumb'), to: '/store/blog' }]}
                title={title}
                subtitle={t('blog.editor.subtitle')}
                titleAddon={post && <PostStatusChip status={post.status} />}
                actions={
                    <>
                        <Button variant="ghost" onClick={openPreview} disabled={preview.isPending}>
                            <EyeIcon size={16} aria-hidden />
                            {t('blog.editor.preview')}
                        </Button>
                        {editable && (
                            <>
                                <Button variant="outline" disabled={disabled} onClick={() => (form.publish_at.trim() === '' ? (set('status', 'scheduled'), setErrors({ publish_at: t('blog.settings.publishAtHint') })) : submit('scheduled'))}>
                                    <CalendarBlankIcon size={16} aria-hidden />
                                    {t('blog.editor.schedule')}
                                </Button>
                                <Button variant="primary" disabled={disabled} onClick={() => submit('published')}>
                                    {t(live ? 'blog.editor.update' : 'blog.editor.publish')}
                                </Button>
                            </>
                        )}
                    </>
                }
            />

            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
                <fieldset disabled={disabled} className="grid min-w-0 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_23.5rem]">
                    <div className="flex min-w-0 flex-col gap-6">
                        {/* Editor card (241:46027): cover, title, byline, toolbar + body. */}
                        <div className={`${cardClass} gap-4`}>
                            <div className="relative h-55 w-full overflow-hidden rounded-sm-plus bg-[image:var(--tm-gradient-bloom-sunrise)]">
                                {form.cover?.url && <img src={form.cover.url} alt={form.cover.alt ?? ''} className="size-full object-cover" />}
                                {editable && (
                                    <div className="absolute end-3 bottom-3 flex items-center gap-2">
                                        <Button variant="secondary" size="sm" onClick={() => setPicking(true)}>
                                            <ImageIcon size={16} aria-hidden />
                                            {form.cover ? t('blog.editor.coverReplace') : t('blog.editor.coverChoose')}
                                        </Button>
                                        {form.cover && (
                                            <IconButton variant="outline" size="sm" aria-label={t('blog.editor.coverRemove')} onClick={() => set('cover', null)}>
                                                <TrashIcon size={16} aria-hidden />
                                            </IconButton>
                                        )}
                                    </div>
                                )}
                            </div>
                            <label htmlFor="blog-title" className="sr-only">
                                {t('blog.editor.titleEn')}
                            </label>
                            <textarea
                                id="blog-title"
                                rows={2}
                                aria-invalid={errorOf('title') ? true : undefined}
                                aria-describedby={errorOf('title') ? 'blog-title-error' : undefined}
                                placeholder={t('blog.editor.titlePlaceholder')}
                                className="-my-1 w-full resize-none rounded-sm border border-transparent bg-transparent t-h2 text-fg-primary placeholder:text-fg-tertiary hover:border-line-default focus:border-line-focus focus:ring-1 focus:ring-inset focus:ring-line-focus aria-invalid:border-line-danger"
                                value={form.title}
                                onChange={(e) => set('title', e.target.value)}
                            />
                            {errorOf('title') && (
                                <p id="blog-title-error" className="flex items-start gap-1 t-admin-caption text-fg-primary">
                                    <WarningIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                                    {errorOf('title')}
                                </p>
                            )}
                            <p className="t-admin-caption text-fg-tertiary">
                                {t('blog.editor.byline', {
                                    author: nameOf(author) ?? t('blog.editor.noAuthor'),
                                    minutes: locale === 'bn' && post?.reading_minutes_bn ? post.reading_minutes_bn : (post?.reading_minutes ?? 1),
                                    category: nameOf(category) ?? t('blog.editor.noCategory'),
                                })}
                            </p>

                            <DescriptionEditor
                                value={{ en: form.body, bn: form.body_bn }}
                                onChange={(language, value) => set(language === 'en' ? 'body' : 'body_bn', value)}
                                error={errorOf('body') ?? errorOf('body_bn')}
                                invalid={{ en: Boolean(errorOf('body')), bn: Boolean(errorOf('body_bn')) }}
                                disabled={disabled}
                                initialLanguage={locale === 'bn' ? 'bn' : 'en'}
                                label={t('blog.editor.body')}
                                hideLabel
                            />
                        </div>

                        <FormSection title={t('blog.editor.textsTitle')} description={t('blog.editor.textsHint')}>
                            <Field label={t('blog.editor.titleBn')} error={errorOf('title_bn')} optional>
                                {({ id: fid, describedBy, invalid }) => (
                                    <input id={fid} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} value={form.title_bn} onChange={(e) => set('title_bn', e.target.value)} />
                                )}
                            </Field>
                            <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                                <Field label={t('blog.editor.excerpt')} hint={t('blog.editor.excerptHint')} error={errorOf('excerpt')} optional>
                                    {({ id: fid, describedBy, invalid }) => (
                                        <textarea id={fid} rows={3} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} value={form.excerpt} onChange={(e) => set('excerpt', e.target.value)} />
                                    )}
                                </Field>
                                <Field label={t('blog.editor.excerptBn')} error={errorOf('excerpt_bn')} optional>
                                    {({ id: fid, describedBy, invalid }) => (
                                        <textarea id={fid} rows={3} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} value={form.excerpt_bn} onChange={(e) => set('excerpt_bn', e.target.value)} />
                                    )}
                                </Field>
                            </div>
                        </FormSection>

                        {form.type === 'buying_guide' && <GuideSections form={form} set={set} errorOf={errorOf} disabled={disabled} />}

                        <FormSection title={t('blog.products.title')} description={t('blog.products.hint')}>
                            <ProductLinksField label={t('blog.products.title')} value={form.related} onChange={(related) => set('related', related)} max={8} disabled={disabled} />
                        </FormSection>
                    </div>

                    <aside className="flex min-w-0 flex-col gap-6">
                        {/* Post settings (241:46044). */}
                        <FormSection title={t('blog.settings.title')}>
                            <Field label={t('blog.settings.status')} error={errorOf('status')}>
                                {({ id: fid }) => (
                                    <Select id={fid} value={form.status} onChange={(e) => set('status', e.target.value as PostStatus)}>
                                        {postStatuses.map((status) => (
                                            <option key={status} value={status}>
                                                {statusLabel(status)}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            {form.status === 'scheduled' && (
                                <Field label={t('blog.settings.publishAt')} hint={t('blog.settings.publishAtHint')} error={errorOf('publish_at')} required>
                                    {({ id: fid, describedBy, invalid }) => (
                                        <input
                                            id={fid}
                                            type="datetime-local"
                                            aria-describedby={describedBy}
                                            aria-invalid={invalid || undefined}
                                            className={`h-10 tabular-nums ${inputClass}`}
                                            value={form.publish_at}
                                            onChange={(e) => set('publish_at', e.target.value)}
                                        />
                                    )}
                                </Field>
                            )}
                            <Field label={t('blog.settings.category')} error={errorOf('category_id')}>
                                {({ id: fid }) => (
                                    <Select id={fid} value={form.category_id} onChange={(e) => set('category_id', e.target.value)}>
                                        <option value="">{t('blog.settings.noCategory')}</option>
                                        {(categories.data ?? []).map((c) => (
                                            <option key={c.id} value={String(c.id)}>
                                                {nameOf(c)}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            <Field label={t('blog.settings.language')}>
                                {({ id: fid }) => (
                                    <output id={fid} className={`flex h-10 items-center ${inputClass}`}>
                                        {t(languageState(form) === 'both' ? 'blog.settings.lang.both' : 'blog.settings.lang.en')}
                                    </output>
                                )}
                            </Field>
                            <Field label={t('blog.settings.type')} error={errorOf('type')}>
                                {({ id: fid }) => (
                                    <Select id={fid} value={form.type} onChange={(e) => set('type', e.target.value as PostForm['type'])}>
                                        {postTypes.map((type) => (
                                            <option key={type} value={type}>
                                                {t(`blog.type.${type}`)}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            <Field label={t('blog.settings.author')} error={errorOf('author_id')}>
                                {({ id: fid }) => (
                                    <Select id={fid} value={form.author_id} onChange={(e) => set('author_id', e.target.value)}>
                                        <option value="">{t('blog.settings.noAuthor')}</option>
                                        {(authors.data ?? []).map((a) => (
                                            <option key={a.id} value={String(a.id)}>
                                                {nameOf(a)}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            <div className="flex flex-col gap-1.5">
                                <span className="t-admin-body-strong text-fg-primary">{t('blog.settings.tags')}</span>
                                {form.tag_ids.length > 0 && (
                                    <ul className="flex flex-wrap gap-1.5">
                                        {form.tag_ids.map((tagId) => {
                                            const tag = tags.data?.find((x) => x.id === tagId);
                                            const name = nameOf(tag) ?? String(tagId);
                                            return (
                                                <li key={tagId} className="inline-flex items-center gap-1 rounded-xs border border-line-default bg-subtle ps-2 pe-0.5 py-0.75 t-admin-caption-strong text-fg-primary">
                                                    {name}
                                                    {editable && (
                                                        <button type="button" aria-label={t('blog.settings.removeTag', { name })} className="grid size-4 place-items-center rounded-xs text-fg-secondary hover:text-fg-primary" onClick={() => set('tag_ids', form.tag_ids.filter((x) => x !== tagId))}>
                                                            <XIcon size={12} aria-hidden />
                                                        </button>
                                                    )}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                                {editable && remainingTags.length > 0 && (
                                    <Select aria-label={t('blog.settings.addTag')} size="sm" value="" onChange={(e) => e.target.value !== '' && set('tag_ids', [...form.tag_ids, Number(e.target.value)])}>
                                        <option value="">{t('blog.settings.addTag')}</option>
                                        {remainingTags.map((tag) => (
                                            <option key={tag.id} value={String(tag.id)}>
                                                {nameOf(tag)}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </div>
                            <label className="flex min-h-touch-min items-center gap-3 t-admin-body text-fg-primary">
                                <input type="checkbox" className="size-4 accent-brand" checked={form.is_featured} onChange={(e) => set('is_featured', e.target.checked)} />
                                {t('blog.settings.featured')}
                            </label>
                        </FormSection>

                        {/* Search & social (241:46064). */}
                        <FormSection
                            title={t('blog.seo.title')}
                            description={t('blog.seo.subtitle')}
                            aside={
                                <Button variant="ghost" size="sm" onClick={() => setSeoOpen((o) => !o)} aria-expanded={seoOpen} disabled={id === null}>
                                    {t(seoOpen ? 'blog.seo.closePanel' : 'blog.seo.openPanel')}
                                </Button>
                            }
                        >
                            <Field
                                label={t('blog.seo.metaTitle')}
                                hint={titleMeasure ? t('blog.seo.measure', { px: titleMeasure.px, max: titleMeasure.max }) : undefined}
                                error={errorOf('seo.title')}
                                optional
                            >
                                {({ id: fid, describedBy, invalid }) => (
                                    <input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} placeholder={form.title} className={`h-10 ${inputClass}`} value={form.seo_title} onChange={(e) => set('seo_title', e.target.value)} />
                                )}
                            </Field>
                            <Field label={t('blog.seo.metaTitleBn')} error={errorOf('seo.title_bn')} optional>
                                {({ id: fid, describedBy, invalid }) => (
                                    <input id={fid} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} placeholder={form.title_bn} className={`h-10 ${inputClass}`} value={form.seo_title_bn} onChange={(e) => set('seo_title_bn', e.target.value)} />
                                )}
                            </Field>
                            <Field label={t('blog.seo.slug')} hint={slugChanged ? t('blog.seo.slugRedirect') : t('blog.seo.slugHint')} error={errorOf('slug')} optional={id === null}>
                                {({ id: fid, describedBy, invalid }) => (
                                    <input
                                        id={fid}
                                        spellCheck={false}
                                        autoCapitalize="none"
                                        aria-describedby={describedBy}
                                        aria-invalid={invalid || undefined}
                                        className={`h-10 ${monoInputClass}`}
                                        value={form.slug}
                                        onChange={(e) => set('slug', e.target.value)}
                                    />
                                )}
                            </Field>
                            <ul className="flex flex-col gap-1.5 rounded-sm bg-subtle p-3">
                                {checks.map((check) => (
                                    <li key={check.label} className="flex items-center gap-1.5 t-admin-caption text-fg-primary">
                                        {check.ok ? <CheckIcon size={14} aria-hidden className="text-feedback-success" /> : <WarningIcon size={14} aria-hidden className="text-feedback-warning" />}
                                        {check.label}
                                    </li>
                                ))}
                            </ul>
                            {id === null && <p className="t-admin-caption text-fg-tertiary">{t('blog.seo.panelSaveFirst')}</p>}
                        </FormSection>
                        {seoOpen && id !== null && (
                            <div className={cardClass}>
                                <SeoPanel type="blog_post" id={id} />
                            </div>
                        )}
                    </aside>
                </fieldset>

                <FormActions
                    start={
                        editable &&
                        post?.status === 'draft' && (
                            <Button variant="danger" size="sm" onClick={() => setConfirming(true)}>
                                <TrashIcon size={16} aria-hidden />
                                {t('blog.editor.delete')}
                            </Button>
                        )
                    }
                >
                    {editable && live && (
                        <Button variant="outline" disabled={disabled} onClick={() => submit('draft')}>
                            {t('blog.editor.unpublish')}
                        </Button>
                    )}
                    {editable && (
                        <Button type="submit" variant={live ? 'outline' : 'primary'} disabled={disabled}>
                            {form.status === 'draft' ? t('blog.editor.saveDraft') : t('blog.editor.update')}
                        </Button>
                    )}
                </FormActions>
            </form>

            {picking && (
                <MediaPickerModal
                    context={{ title: t('blog.editor.coverPicker'), hint: t('blog.editor.coverHint'), multiple: false, max: 1, kinds: ['image'], defaultFolder: 'blog', ratio: [16, 9] }}
                    onInsert={insertCover}
                    onClose={() => setPicking(false)}
                />
            )}
            {post && (
                <ConfirmDialog
                    open={confirming}
                    title={t('blog.editor.deleteTitle')}
                    body={t('blog.editor.deleteBody', { title: post.title })}
                    confirmLabel={t('blog.editor.delete')}
                    pending={remove.isPending}
                    onClose={() => setConfirming(false)}
                    onConfirm={() =>
                        remove.mutate(undefined, {
                            onSuccess: () => {
                                notify(t('blog.editor.deleted'));
                                navigate('/store/blog', { replace: true });
                            },
                            onError: (error) => notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
                        })
                    }
                />
            )}
        </section>
    );
}
