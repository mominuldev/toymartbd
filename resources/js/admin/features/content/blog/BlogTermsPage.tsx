import { useState, type FormEvent } from 'react';
import { PencilSimpleIcon, PlusIcon, TrashIcon } from '@phosphor-icons/react';
import { blogTermRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { Field, inputClass, monoInputClass } from '@/components/ui/Field';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { MediaField, type PickedImage } from '../shared/MediaField';
import { apiFieldErrors, issuesToErrors, type FieldErrors } from '../shared/errors';
import { useDeleteTerm, useSaveTerm, useTerms, type BlogTerm, type BlogTermInput, type TermKind } from './api';
import { BlogHeader, cardClass } from './parts';

type TermForm = { name: string; name_bn: string; slug: string; description: string; description_bn: string; position: string; avatar: PickedImage | null };

const titles: Record<TermKind, MessageKey> = { categories: 'blog.terms.categories.title', tags: 'blog.terms.tags.title', authors: 'blog.terms.authors.title' };

function toForm(term?: BlogTerm): TermForm {
    return {
        name: term?.name ?? '',
        name_bn: term?.name_bn ?? '',
        slug: term?.slug ?? '',
        description: term?.description ?? '',
        description_bn: term?.description_bn ?? '',
        position: term?.position != null ? String(term.position) : '',
        avatar: term?.avatar ? { media_id: term.avatar.media_id, url: term.avatar.url, alt: term.avatar.alt } : null,
    };
}

const orNull = (value: string): string | null => (value.trim() === '' ? null : value.trim());

function toPayload(kind: TermKind, form: TermForm): BlogTermInput {
    return {
        name: form.name.trim(),
        name_bn: orNull(form.name_bn),
        slug: orNull(form.slug),
        ...(kind !== 'tags' ? { description: orNull(form.description), description_bn: orNull(form.description_bn) } : {}),
        ...(kind === 'categories' && form.position.trim() !== '' ? { position: Number(form.position) } : {}),
        ...(kind === 'authors' ? { avatar_media_id: form.avatar?.media_id ?? null } : {}),
    };
}

/**
 * Blog categories, tags and authors: one screen per kind under the blog tabs. No Figma frame —
 * the Pages card list (B · 27 241:45525) beside an edit card in the FormSection language.
 */
export function BlogTermsPage({ kind }: { kind: TermKind }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const terms = useTerms(kind);
    const save = useSaveTerm(kind);
    const remove = useDeleteTerm(kind);
    const [editing, setEditing] = useState<BlogTerm | 'new' | null>(null);
    const [form, setForm] = useState<TermForm>(toForm());
    const [errors, setErrors] = useState<FieldErrors>({});
    const [deleting, setDeleting] = useState<BlogTerm | null>(null);
    const manage = can(me, 'content.manage');

    const open = (term: BlogTerm | 'new') => {
        setEditing(term);
        setForm(toForm(term === 'new' ? undefined : term));
        setErrors({});
    };
    const set = <K extends keyof TermForm>(key: K, value: TermForm[K]) => setForm((f) => ({ ...f, [key]: value }));
    const label = (term: BlogTerm) => (locale === 'bn' && term.name_bn ? term.name_bn : term.name);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const payload = toPayload(kind, form);
        const parsed = blogTermRequestSchema.safeParse(payload);
        if (!parsed.success) {
            setErrors(issuesToErrors(parsed.error.issues));
            return;
        }
        save.mutate(
            { id: editing && editing !== 'new' ? editing.id : null, input: payload },
            {
                onSuccess: () => {
                    notify(t('blog.terms.saved'));
                    setEditing(null);
                },
                onError: (error) => {
                    const fields = apiFieldErrors(error);
                    if (fields) setErrors(fields);
                    else notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
                },
            },
        );
    };

    const text = (key: 'name' | 'name_bn' | 'description' | 'description_bn', labelKey: MessageKey, opts: { bn?: boolean; area?: boolean; required?: boolean } = {}) => (
        <Field label={t(labelKey)} error={errors[key]} optional={!opts.required} required={opts.required}>
            {({ id, describedBy, invalid }) =>
                opts.area ? (
                    <textarea id={id} rows={3} lang={opts.bn ? 'bn' : undefined} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} value={form[key]} onChange={(e) => set(key, e.target.value)} />
                ) : (
                    <input id={id} lang={opts.bn ? 'bn' : undefined} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} value={form[key]} onChange={(e) => set(key, e.target.value)} />
                )
            }
        </Field>
    );

    return (
        <section className="flex flex-col gap-6">
            <BlogHeader
                action={
                    manage && (
                        <Button onClick={() => open('new')}>
                            <PlusIcon size={16} aria-hidden />
                            {t('blog.terms.new')}
                        </Button>
                    )
                }
            />

            <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,620fr)_minmax(0,500fr)]">
                <div className={`${cardClass} gap-1`}>
                    <h2 className="pb-2 t-admin-section text-fg-primary">{t(titles[kind])}</h2>
                    {terms.isError ? (
                        <QueryError error={terms.error} onRetry={() => void terms.refetch()} />
                    ) : terms.isPending ? (
                        <p aria-busy="true" className="py-3 t-admin-body text-fg-tertiary">
                            {t('common.loading')}
                        </p>
                    ) : terms.data.length === 0 ? (
                        <p className="py-3 t-admin-body text-fg-secondary">{t('blog.terms.empty')}</p>
                    ) : (
                        <ul aria-label={t(titles[kind])}>
                            {terms.data.map((term) => (
                                <li key={term.id} className="flex items-center gap-3 border-b border-line-default py-3">
                                    {kind === 'authors' && (
                                        <span className="size-9 shrink-0 overflow-hidden rounded-full bg-subtle">{term.avatar && <img src={term.avatar.url} alt="" className="size-full object-cover" />}</span>
                                    )}
                                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <span className="t-admin-body-strong truncate text-fg-primary" lang={locale === 'bn' && term.name_bn ? 'bn' : undefined}>
                                            {label(term)}
                                        </span>
                                        <span className="t-admin-mono-sm truncate text-fg-tertiary">{term.slug}</span>
                                    </div>
                                    <span className="t-admin-caption tabular-nums text-fg-secondary">{t('blog.terms.posts', { count: term.posts_count })}</span>
                                    {manage && (
                                        <>
                                            <IconButton size="sm" aria-label={t('blog.terms.edit', { name: label(term) })} onClick={() => open(term)}>
                                                <PencilSimpleIcon size={16} aria-hidden />
                                            </IconButton>
                                            <IconButton size="sm" aria-label={t('blog.terms.delete', { name: label(term) })} onClick={() => setDeleting(term)}>
                                                <TrashIcon size={16} aria-hidden />
                                            </IconButton>
                                        </>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                {editing !== null && manage && (
                    <form onSubmit={submit} noValidate className={`${cardClass} gap-3.5`}>
                        <h2 className="t-admin-section text-fg-primary">{editing === 'new' ? t('blog.terms.new') : t('blog.terms.edit', { name: label(editing) })}</h2>
                        <fieldset disabled={save.isPending} className="flex flex-col gap-3.5">
                            {text('name', 'blog.terms.name', { required: true })}
                            {text('name_bn', 'blog.terms.nameBn', { bn: true })}
                            <Field label={t('blog.terms.slug')} hint={t('blog.terms.slugHint')} error={errors.slug} optional>
                                {({ id, describedBy, invalid }) => (
                                    <input id={id} spellCheck={false} autoCapitalize="none" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${monoInputClass}`} value={form.slug} onChange={(e) => set('slug', e.target.value)} />
                                )}
                            </Field>
                            {kind === 'categories' && (
                                <>
                                    {text('description', 'blog.terms.description', { area: true })}
                                    {text('description_bn', 'blog.terms.descriptionBn', { area: true, bn: true })}
                                    <Field label={t('blog.terms.position')} error={errors.position} optional>
                                        {({ id, describedBy, invalid }) => (
                                            <input id={id} inputMode="numeric" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 tabular-nums ${inputClass}`} value={form.position} onChange={(e) => set('position', e.target.value)} />
                                        )}
                                    </Field>
                                </>
                            )}
                            {kind === 'authors' && (
                                <>
                                    {text('description', 'blog.terms.bio', { area: true })}
                                    {text('description_bn', 'blog.terms.bioBn', { area: true, bn: true })}
                                    <MediaField label={t('blog.terms.avatar')} pickerTitle="blog.terms.avatarPicker" value={form.avatar} onChange={(next) => set('avatar', next)} ratio={[1, 1]} error={errors.avatar_media_id} />
                                </>
                            )}
                        </fieldset>
                        <div className="flex justify-end gap-3">
                            <Button variant="ghost" onClick={() => setEditing(null)}>
                                {t('blog.terms.cancel')}
                            </Button>
                            <Button type="submit" disabled={save.isPending}>
                                {t('blog.terms.save')}
                            </Button>
                        </div>
                    </form>
                )}
            </div>

            {deleting && (
                <ConfirmDialog
                    open
                    title={t('blog.terms.deleteTitle', { name: label(deleting) })}
                    body={t('blog.terms.deleteBody')}
                    confirmLabel={t('blog.terms.delete', { name: label(deleting) })}
                    pending={remove.isPending}
                    onClose={() => setDeleting(null)}
                    onConfirm={() =>
                        remove.mutate(deleting.id, {
                            onSuccess: () => {
                                notify(t('blog.terms.deleted'));
                                setDeleting(null);
                            },
                            onError: (error) => {
                                notify(error instanceof ApiError ? (error.problem.errors?.[0]?.message ?? error.problem.title) : t('error.title'), 'danger');
                                setDeleting(null);
                            },
                        })
                    }
                />
            )}
        </section>
    );
}
