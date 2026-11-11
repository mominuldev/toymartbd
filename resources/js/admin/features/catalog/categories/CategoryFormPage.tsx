import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useForm, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { ArrowLeftIcon } from '@phosphor-icons/react';
import { storeCategoryRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { Field, inputClass } from '@/components/ui/Field';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import {
    MAX_DEPTH,
    flatten,
    heightOf,
    useArchiveCategory,
    useCategory,
    useCategoryTree,
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
 * Create and edit a category, in both languages. Choosing another parent on an
 * existing category moves it (last under the new parent) through the move endpoint,
 * so the tree's invariants stay on the server.
 */
export function CategoryFormPage() {
    const params = useParams();
    const id = params.categoryId ? Number(params.categoryId) : null;
    const category = useCategory(id);
    const tree = useCategoryTree();

    const error = (id !== null && category.isError ? category.error : null) ?? (tree.isError ? tree.error : null);
    if (error) {
        return <QueryError error={error} onRetry={() => void (category.refetch(), tree.refetch())} />;
    }
    if ((id !== null && !category.data) || !tree.data) {
        return <p aria-busy="true" className="sr-only" />;
    }

    return <CategoryForm key={category.data?.id ?? 'new'} id={id} category={category.data} tree={tree.data} />;
}

function CategoryForm({ id, category, tree }: { id: number | null; category?: Category; tree: Category[] }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const navigate = useNavigate();
    const save = useSaveCategory(id);
    const move = useMoveCategory();
    const archive = useArchiveCategory(id ?? 0);
    const [confirming, setConfirming] = useState(false);
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
        defaultValues: toForm(category),
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values), context, options as never);
            return Object.keys(result.errors).length > 0 ? { values: {}, errors: result.errors as never } : { values, errors: {} };
        },
    });
    const { register, handleSubmit, setError, formState, reset } = form;

    useEffect(() => reset(toForm(category)), [category, reset]);

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
                    navigate(`/products/categories/${saved.id}`, { replace: true });
                    return;
                }
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

    return (
        <section className="max-w-3xl space-y-5">
            <Link to="/products/categories" className="inline-flex items-center gap-1.5 t-admin-button-sm text-fg-link hover:underline">
                <ArrowLeftIcon size={16} aria-hidden />
                {t('categories.backToList')}
            </Link>
            <h1 className="t-admin-page-title text-fg-primary">{id === null ? t('categories.new') : t('categories.edit')}</h1>

            <form onSubmit={submit} noValidate className="space-y-6">
                <fieldset disabled={!editable || pending} className="space-y-5 rounded-md border border-line-default bg-surface p-5 shadow-e1 md:p-6">
                    <div className="grid gap-5 md:grid-cols-2">
                        <Field label={t('categories.field.name')} error={errorOf('name')}>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <input id={fieldId} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-control-md ${inputClass}`} {...register('name')} />
                            )}
                        </Field>
                        <Field label={t('categories.field.nameBn')} hint={t('categories.field.nameBnHint')} error={errorOf('name_bn')} optional>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <input id={fieldId} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-control-md ${inputClass}`} {...register('name_bn')} />
                            )}
                        </Field>
                    </div>

                    <div className="grid gap-5 md:grid-cols-2">
                        <Field label={t('categories.field.slug')} hint={t('categories.field.slugHint')} error={errorOf('slug')} optional>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <input
                                    id={fieldId}
                                    spellCheck={false}
                                    autoCapitalize="none"
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    className={`h-control-md t-admin-mono ${inputClass}`}
                                    {...register('slug')}
                                />
                            )}
                        </Field>
                        <Field label={t('categories.field.parent')} hint={t('categories.field.parentHint')} error={errorOf('parent_id')}>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <select id={fieldId} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-control-md ${inputClass}`} {...register('parent_id')}>
                                    <option value="">{t('categories.field.parentNone')}</option>
                                    {parents.map(({ node, depth }) => (
                                        <option key={node.id} value={String(node.id)}>
                                            {`${'— '.repeat(depth)}${node.name}`}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </Field>
                    </div>

                    <div className="grid gap-5 md:grid-cols-2">
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
                    </div>

                    <Field label={t('categories.field.icon')} hint={t('categories.field.iconHint')} error={errorOf('icon')} optional>
                        {({ id: fieldId, describedBy, invalid }) => (
                            <input
                                id={fieldId}
                                spellCheck={false}
                                autoCapitalize="none"
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                                className={`h-control-md t-admin-mono md:max-w-80 ${inputClass}`}
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

                <div className="sticky bottom-0 -mx-4 flex items-center justify-between gap-3 border-t border-line-default bg-surface px-4 py-3 shadow-e4 md:-mx-5 md:px-5 lg:-mx-6 lg:px-6">
                    {id !== null && category && can(me, 'catalog.archive') && category.is_active ? (
                        <Button variant="ghost" onClick={() => setConfirming(true)} className="text-feedback-danger">
                            {t('categories.archive')}
                        </Button>
                    ) : (
                        <span />
                    )}
                    <div className="flex gap-2">
                        <Link to="/products/categories" className="inline-flex h-control-md items-center rounded-sm border border-line-strong bg-surface px-4 t-admin-button text-fg-primary hover:bg-subtle">
                            {t('common.cancel')}
                        </Link>
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
        </section>
    );
}
