import { useEffect, useMemo, useRef, useState } from 'react';
import { useBlocker, useNavigate } from 'react-router';
import { XIcon } from '@phosphor-icons/react';
import { useForm, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { storeCategoryRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { FormSection } from '@/components/ui/FormSection';
import { Select } from '@/components/ui/Select';
import { Field, inputClass, monoInputClass } from '@/components/ui/Field';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import {
    MAX_DEPTH,
    flatten,
    heightOf,
    useArchiveCategory,
    useCategory,
    useMoveCategory,
    useSaveCategory,
    type Category,
    type CategoryInput,
} from './api';

type FormValues = {
    name: string;
    name_bn: string;
    slug: string;
    parent_id: string;
    description: string;
    description_bn: string;
    icon: string;
    is_featured: boolean;
    show_in_menu: boolean;
    is_active: boolean;
};

const validateInput = zodResolver(storeCategoryRequestSchema);

const fields: (keyof FormValues)[] = ['name', 'name_bn', 'slug', 'parent_id', 'description', 'description_bn', 'icon', 'is_featured', 'show_in_menu', 'is_active'];

function toForm(category?: Category, parentId?: number | null): FormValues {
    return {
        name: category?.name ?? '',
        name_bn: category?.name_bn ?? '',
        slug: category?.slug ?? '',
        parent_id: String(category?.parent_id ?? parentId ?? ''),
        description: category?.description ?? '',
        description_bn: category?.description_bn ?? '',
        icon: category?.icon ?? '',
        is_featured: category?.is_featured ?? false,
        show_in_menu: category?.show_in_menu ?? true,
        is_active: category?.is_active ?? true,
    };
}

function toInput(values: FormValues): CategoryInput {
    const orNull = (value: string) => (value.trim() === '' ? null : value.trim());
    return {
        name: values.name.trim(),
        name_bn: orNull(values.name_bn),
        slug: orNull(values.slug),
        parent_id: values.parent_id === '' ? null : Number(values.parent_id),
        description: orNull(values.description),
        description_bn: orNull(values.description_bn),
        icon: orNull(values.icon),
        is_featured: values.is_featured,
        show_in_menu: values.show_in_menu,
        is_active: values.is_active,
    };
}

/**
 * The edit panel of the Categories split view (Figma Categories 212:7132, Theme B copy in
 * section 14): create or edit a category in both languages. Choosing another parent on an
 * existing category moves it (last under the new parent) through the move endpoint, so the
 * tree's invariants stay on the server. Unsaved edits block leaving the row.
 */
export function CategoryPanel({ id, parentId, tree, onClose }: { id: number | null; parentId: number | null; tree: Category[]; onClose: () => void }) {
    const category = useCategory(id);

    if (id !== null && category.isError) {
        return <QueryError error={category.error} onRetry={() => void category.refetch()} />;
    }
    if (id !== null && !category.data) {
        return <p aria-busy="true" className="sr-only" />;
    }

    return <CategoryForm key={`${category.data?.id ?? 'new'}-${parentId ?? ''}`} id={id} category={category.data} tree={tree} parentId={parentId} onClose={onClose} />;
}

function CategoryForm({ id, category, tree, parentId, onClose }: { id: number | null; category?: Category; tree: Category[]; parentId: number | null; onClose: () => void }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const navigate = useNavigate();
    const save = useSaveCategory(id);
    const move = useMoveCategory();
    const archive = useArchiveCategory(id ?? 0);
    const [confirming, setConfirming] = useState(false);
    // Set just before a navigation this form starts itself (after save or archive).
    const leaving = useRef(false);
    const editable = can(me, id === null ? 'catalog.create' : 'catalog.update');

    // A category can move under any node that is not itself or below it, and that leaves
    // its subtree within three levels. The server re-checks all of this.
    const parents = useMemo(() => {
        const self = id === null ? null : flatten(tree).find((entry) => entry.node.id === id)?.node;
        const excluded = new Set(self ? flatten([self]).map((entry) => entry.node.id) : []);
        const height = self ? heightOf(self) : 0;
        return flatten(tree).filter(({ node, depth }) => !excluded.has(node.id) && depth + 1 + height <= MAX_DEPTH);
    }, [id, tree]);

    const form = useForm<FormValues>({
        defaultValues: toForm(category, parentId),
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values), context, options as never);
            return Object.keys(result.errors).length > 0 ? { values: {}, errors: result.errors as never } : { values, errors: {} };
        },
    });
    const { register, handleSubmit, setError, formState, reset } = form;

    useEffect(() => reset(toForm(category, parentId)), [category, parentId, reset]);

    // Unsaved edits: ask before following another row, a tab or the back button…
    const blocker = useBlocker(({ currentLocation, nextLocation }) => !leaving.current && formState.isDirty && currentLocation.pathname !== nextLocation.pathname);
    // …and before closing or reloading the browser tab.
    useEffect(() => {
        if (!formState.isDirty) return;
        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [formState.isDirty]);

    // Focus the panel heading when a row opens, so the edit is announced and in view.
    const heading = useRef<HTMLHeadingElement>(null);
    useEffect(() => heading.current?.focus(), []);

    const fail = (error: Error) => {
        if (error instanceof ApiError && error.status === 422) {
            for (const field of fields) {
                const message = error.fieldError(field);
                if (message) setError(field as FieldPath<FormValues>, { message });
            }
        } else {
            notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
        }
    };

    const submit = handleSubmit((values) => {
        const input = toInput(values);
        save.mutate(input, {
            onSuccess: (saved) => {
                if (id === null) {
                    notify(t('categories.created'));
                    leaving.current = true;
                    navigate(`/products/categories/${saved.id}`, { replace: true });
                    return;
                }
                reset(values);
                const newParent = input.parent_id ?? null;
                if (newParent === (category?.parent_id ?? null)) {
                    notify(t('categories.saved'));
                    return;
                }
                const siblings = newParent === null ? tree : (flatten(tree).find((entry) => entry.node.id === newParent)?.node.children ?? []);
                move.mutate(
                    { id: saved.id, parent_id: newParent, position: siblings.length },
                    { onSuccess: () => notify(t('categories.saved')), onError: fail },
                );
            },
            onError: fail,
        });
    });

    const errorOf = (field: keyof FormValues) => formState.errors[field]?.message;
    const pending = save.isPending || move.isPending;

    const parentName = category?.parent_id ? (flatten(tree).find((entry) => entry.node.id === category.parent_id)?.node.name ?? '') : t('categories.topLevel');
    const locked = !editable || pending;
    const title = id === null ? t('categories.new') : (category?.name ?? '');

    return (
        <>
        <form onSubmit={submit} noValidate aria-labelledby="category-panel-title" className="flex min-w-0 flex-col gap-6">
                {/* Figma Categories 212:7132 edit panel: a FormSection titled with the category. */}
                <FormSection>
                    <div className="flex items-start gap-3">
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <h2 id="category-panel-title" ref={heading} tabIndex={-1} className="t-admin-section text-fg-primary outline-none focus:shadow-none">
                                {title}
                            </h2>
                            {category && (
                                <p className="t-admin-caption text-fg-tertiary">
                                    {t('categories.parentLine', { name: parentName })} · <span className="t-admin-mono-sm">/categories/{category.slug}</span>
                                </p>
                            )}
                        </div>
                        <IconButton size="sm" aria-label={t('common.close')} onClick={onClose} className="-me-1.5 -mt-1">
                            <XIcon size={16} aria-hidden />
                        </IconButton>
                    </div>
                    <fieldset disabled={locked} className="flex min-w-0 flex-col gap-3.5">
                    <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                        <Field label={t('categories.field.name')} error={errorOf('name')}>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <input id={fieldId} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} {...register('name')} />
                            )}
                        </Field>
                        <Field label={t('categories.field.nameBn')} hint={t('categories.field.nameBnHint')} error={errorOf('name_bn')} optional>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <input id={fieldId} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} {...register('name_bn')} />
                            )}
                        </Field>
                    </div>

                    <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                        <Field label={t('categories.field.slug')} hint={t('categories.field.slugHint')} error={errorOf('slug')} optional>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <input
                                    id={fieldId}
                                    spellCheck={false}
                                    autoCapitalize="none"
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    className={`h-10 ${monoInputClass}`}
                                    {...register('slug')}
                                />
                            )}
                        </Field>
                        <Field label={t('categories.field.parent')} hint={t('categories.field.parentHint')} error={errorOf('parent_id')}>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <Select id={fieldId} aria-describedby={describedBy} aria-invalid={invalid || undefined} {...register('parent_id')}>
                                    <option value="">{t('categories.field.parentNone')}</option>
                                    {parents.map(({ node, depth }) => (
                                        <option key={node.id} value={String(node.id)}>
                                            {`${'— '.repeat(depth)}${node.name}`}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                    </div>

                    <Field label={t('categories.field.icon')} hint={t('categories.field.iconHint')} error={errorOf('icon')} optional>
                        {({ id: fieldId, describedBy, invalid }) => (
                            <input
                                id={fieldId}
                                spellCheck={false}
                                autoCapitalize="none"
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                                className={`h-10 md:max-w-80 ${monoInputClass}`}
                                {...register('icon')}
                            />
                        )}
                    </Field>

                    <div className="space-y-1">
                        {(['show_in_menu', 'is_featured', 'is_active'] as const).map((flag) => (
                            <label key={flag} className="flex min-h-touch-min items-center gap-3 t-admin-body text-fg-primary">
                                <input type="checkbox" className="size-4 accent-brand" {...register(flag)} />
                                {t(flag === 'show_in_menu' ? 'categories.field.showInMenu' : flag === 'is_featured' ? 'categories.field.featured' : 'categories.field.active')}
                            </label>
                        ))}
                    </div>
                    </fieldset>
                </FormSection>

                <FormSection title={t('categories.descriptionTitle')}>
                    <fieldset disabled={locked} className="grid min-w-0 gap-x-4 gap-y-3.5 md:grid-cols-2">
                        <Field label={t('categories.field.description')} error={errorOf('description')} optional>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <textarea id={fieldId} rows={4} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} {...register('description')} />
                            )}
                        </Field>
                        <Field label={t('categories.field.descriptionBn')} error={errorOf('description_bn')} optional>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <textarea id={fieldId} rows={4} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} {...register('description_bn')} />
                            )}
                        </Field>
                    </fieldset>
                </FormSection>

            {/* Figma FormActions 198:1085, scoped to the panel: destructive far left, then Discard and Save. */}
            <div className="sticky bottom-4 z-10 flex min-h-16 flex-wrap items-center justify-between gap-3 rounded-md border border-line-default bg-surface px-5 py-3.5 shadow-e4">
                {id !== null && category && can(me, 'catalog.archive') && category.is_active ? (
                    <Button variant="danger" onClick={() => setConfirming(true)}>
                        {t('categories.archive')}
                    </Button>
                ) : (
                    <span />
                )}
                <div className="flex flex-wrap items-center gap-3">
                    <Button variant="ghost" disabled={!formState.isDirty || pending} onClick={() => reset(toForm(category, parentId))}>
                        {t('common.discard')}
                    </Button>
                    {editable && (
                        <Button type="submit" disabled={pending}>
                            {pending ? t('common.saving') : t('common.save')}
                        </Button>
                    )}
                </div>
            </div>
        </form>

            {category && (
                <ConfirmDialog
                    open={confirming}
                    title={t('categories.archiveTitle', { name: category.name })}
                    body={t('categories.archiveBody')}
                    confirmLabel={t('categories.archive')}
                    confirmText={category.name}
                    typePrompt={t('categories.archivePrompt', { name: category.name })}
                    pending={archive.isPending}
                    onClose={() => setConfirming(false)}
                    onConfirm={() =>
                        archive.mutate(undefined, {
                            onSuccess: () => {
                                setConfirming(false);
                                notify(t('categories.archived'));
                                leaving.current = true;
                                navigate('/products/categories', { replace: true });
                            },
                            onError: (error) => {
                                setConfirming(false);
                                fail(error);
                            },
                        })
                    }
                />
            )}

            <ConfirmDialog
                open={blocker.state === 'blocked'}
                title={t('categories.unsavedTitle')}
                body={t('categories.unsavedBody')}
                confirmLabel={t('categories.discard')}
                cancelLabel={t('categories.keepEditing')}
                onClose={() => blocker.reset?.()}
                onConfirm={() => blocker.proceed?.()}
            />
        </>
    );
}
