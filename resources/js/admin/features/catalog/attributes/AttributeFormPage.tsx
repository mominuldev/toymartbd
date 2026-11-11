import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useForm, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { ArrowLeftIcon } from '@phosphor-icons/react';
import { storeAttributeRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { Field, inputClass } from '@/components/ui/Field';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { attributeTypes, useAttribute, useDeleteAttribute, useSaveAttribute, type Attribute, type AttributeInput, type AttributeType } from './api';
import { ValuesEditor } from './ValuesEditor';

type FormValues = {
    name: string;
    name_bn: string;
    slug: string;
    type: AttributeType;
    is_variant_defining: boolean;
    is_filterable: boolean;
};

const validateInput = zodResolver(storeAttributeRequestSchema);
const fields: (keyof FormValues)[] = ['name', 'name_bn', 'slug', 'type', 'is_variant_defining', 'is_filterable'];

function toForm(attribute?: Attribute): FormValues {
    return {
        name: attribute?.name ?? '',
        name_bn: attribute?.name_bn ?? '',
        slug: attribute?.slug ?? '',
        type: attribute?.type ?? 'select',
        is_variant_defining: attribute?.is_variant_defining ?? true,
        is_filterable: attribute?.is_filterable ?? true,
    };
}

function toInput(values: FormValues): AttributeInput {
    const orNull = (value: string) => (value.trim() === '' ? null : value.trim());
    return {
        name: values.name.trim(),
        name_bn: orNull(values.name_bn),
        slug: orNull(values.slug),
        type: values.type,
        is_variant_defining: values.is_variant_defining,
        is_filterable: values.is_filterable,
    };
}

/** Create and edit an attribute; its values are edited inline below once it exists. */
export function AttributeFormPage() {
    const params = useParams();
    const id = params.attributeId ? Number(params.attributeId) : null;
    const attribute = useAttribute(id);

    if (id !== null && attribute.isError) {
        return <QueryError error={attribute.error} onRetry={() => void attribute.refetch()} />;
    }
    if (id !== null && !attribute.data) {
        return <p aria-busy="true" className="sr-only" />;
    }

    return <AttributeForm key={attribute.data?.id ?? 'new'} id={id} attribute={attribute.data} />;
}

function AttributeForm({ id, attribute }: { id: number | null; attribute?: Attribute }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const navigate = useNavigate();
    const save = useSaveAttribute(id);
    const remove = useDeleteAttribute(id ?? 0);
    const [confirming, setConfirming] = useState(false);
    const editable = can(me, id === null ? 'catalog.create' : 'catalog.update');

    const form = useForm<FormValues>({
        defaultValues: toForm(attribute),
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values), context, options as never);
            return Object.keys(result.errors).length > 0 ? { values: {}, errors: result.errors as never } : { values, errors: {} };
        },
    });
    const { register, handleSubmit, setError, formState, reset } = form;

    useEffect(() => reset(toForm(attribute)), [attribute, reset]);

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

    const submit = handleSubmit((values) =>
        save.mutate(toInput(values), {
            onSuccess: (saved) => {
                if (id === null) {
                    notify(t('attributes.created'));
                    navigate(`/products/attributes/${saved.id}`, { replace: true });
                } else {
                    notify(t('attributes.saved'));
                }
            },
            onError: fail,
        }),
    );

    const errorOf = (field: keyof FormValues) => formState.errors[field]?.message;

    return (
        <section className="max-w-4xl space-y-5">
            <Link to="/products/attributes" className="inline-flex items-center gap-1.5 t-admin-button-sm text-fg-link hover:underline">
                <ArrowLeftIcon size={16} aria-hidden />
                {t('attributes.backToList')}
            </Link>
            <h1 className="t-admin-page-title text-fg-primary">{id === null ? t('attributes.new') : t('attributes.edit')}</h1>

            <form onSubmit={submit} noValidate className="space-y-6">
                <fieldset disabled={!editable || save.isPending} className="space-y-5 rounded-md border border-line-default bg-surface p-5 shadow-e1 md:p-6">
                    <div className="grid gap-5 md:grid-cols-2">
                        <Field label={t('attributes.field.name')} error={errorOf('name')}>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <input id={fieldId} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-control-md ${inputClass}`} {...register('name')} />
                            )}
                        </Field>
                        <Field label={t('attributes.field.nameBn')} error={errorOf('name_bn')} optional>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <input id={fieldId} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-control-md ${inputClass}`} {...register('name_bn')} />
                            )}
                        </Field>
                    </div>
                    <div className="grid gap-5 md:grid-cols-2">
                        <Field label={t('attributes.field.slug')} hint={t('attributes.field.slugHint')} error={errorOf('slug')} optional>
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
                        <Field label={t('attributes.field.type')} hint={t('attributes.field.typeHint')} error={errorOf('type')}>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <select id={fieldId} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-control-md ${inputClass}`} {...register('type')}>
                                    {attributeTypes.map((type) => (
                                        <option key={type} value={type}>
                                            {t(`attributes.type.${type}`)}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </Field>
                    </div>
                    <div className="space-y-1">
                        <label className="flex min-h-touch-min items-center gap-3 t-admin-body text-fg-primary">
                            <input type="checkbox" className="size-4 accent-brand" {...register('is_variant_defining')} />
                            {t('attributes.field.variantDefining')}
                        </label>
                        <label className="flex min-h-touch-min items-center gap-3 t-admin-body text-fg-primary">
                            <input type="checkbox" className="size-4 accent-brand" {...register('is_filterable')} />
                            {t('attributes.field.filterable')}
                        </label>
                    </div>
                </fieldset>

                <div className="flex items-center justify-between gap-3">
                    {id !== null && attribute && can(me, 'catalog.archive') ? (
                        <Button variant="ghost" onClick={() => setConfirming(true)} className="text-feedback-danger">
                            {t('attributes.delete')}
                        </Button>
                    ) : (
                        <span />
                    )}
                    <div className="flex gap-2">
                        <Link to="/products/attributes" className="inline-flex h-control-md items-center rounded-sm border border-line-strong bg-surface px-4 t-admin-button text-fg-primary hover:bg-subtle">
                            {t('common.cancel')}
                        </Link>
                        {editable && (
                            <Button type="submit" disabled={save.isPending}>
                                {save.isPending ? t('common.saving') : t('common.save')}
                            </Button>
                        )}
                    </div>
                </div>
            </form>

            {attribute ? (
                <ValuesEditor attribute={attribute} />
            ) : (
                <p className="t-admin-body text-fg-tertiary">{t('attributes.values.saveFirst')}</p>
            )}

            {attribute && (
                <ConfirmDialog
                    open={confirming}
                    title={t('attributes.deleteTitle', { name: attribute.name })}
                    body={t('attributes.deleteBody')}
                    confirmLabel={t('attributes.delete')}
                    confirmText={attribute.name}
                    typePrompt={t('attributes.deletePrompt', { name: attribute.name })}
                    pending={remove.isPending}
                    onClose={() => setConfirming(false)}
                    onConfirm={() =>
                        remove.mutate(undefined, {
                            onSuccess: () => {
                                setConfirming(false);
                                notify(t('attributes.deleted'));
                                navigate('/products/attributes', { replace: true });
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
