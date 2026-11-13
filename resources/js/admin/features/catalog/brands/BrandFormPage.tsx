import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useForm, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { storeBrandRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Field, inputClass, monoInputClass } from '@/components/ui/Field';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { useArchiveBrand, useBrand, useSaveBrand, type Brand, type BrandInput } from './api';
import { SeoPanel } from '@/features/seo';

type FormValues = {
    name: string;
    name_bn: string;
    slug: string;
    description: string;
    description_bn: string;
    is_active: boolean;
};

const validateInput = zodResolver(storeBrandRequestSchema);

const fields: (keyof FormValues)[] = ['name', 'name_bn', 'slug', 'description', 'description_bn', 'is_active'];

function toForm(brand?: Brand): FormValues {
    return {
        name: brand?.name ?? '',
        name_bn: brand?.name_bn ?? '',
        slug: brand?.slug ?? '',
        description: brand?.description ?? '',
        description_bn: brand?.description_bn ?? '',
        is_active: brand?.is_active ?? true,
    };
}

/** Empty optional text means "no value" to the API, not an empty string. */
function toInput(values: FormValues): BrandInput {
    const orNull = (value: string) => (value.trim() === '' ? null : value.trim());
    return {
        name: values.name.trim(),
        name_bn: orNull(values.name_bn),
        slug: orNull(values.slug),
        description: orNull(values.description),
        description_bn: orNull(values.description_bn),
        is_active: values.is_active,
    };
}

/**
 * Create and edit a brand — the P3-ADM-04 proof of table → form → validation → mutation.
 * Client validation is the schema generated from the API's FormRequest (rule 27); the
 * server stays the authority, and its 422 field errors land on the same fields.
 */
export function BrandFormPage() {
    const params = useParams();
    const id = params.brandId ? Number(params.brandId) : null;
    const brand = useBrand(id);

    if (id !== null && brand.isError) {
        return <QueryError error={brand.error} onRetry={() => void brand.refetch()} />;
    }
    if (id !== null && !brand.data) {
        return <p aria-busy="true" className="sr-only" />;
    }

    return <BrandForm key={brand.data?.id ?? 'new'} id={id} brand={brand.data} />;
}

function BrandForm({ id, brand }: { id: number | null; brand?: Brand }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const navigate = useNavigate();
    const save = useSaveBrand(id);
    const archive = useArchiveBrand(id ?? 0);
    const [confirming, setConfirming] = useState(false);
    const editable = can(me, id === null ? 'catalog.create' : 'catalog.update');

    const form = useForm<FormValues>({
        defaultValues: toForm(brand),
        // Validate the payload the API will receive, against the API's own schema.
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values), context, options as never);
            return Object.keys(result.errors).length > 0 ? { values: {}, errors: result.errors as never } : { values, errors: {} };
        },
    });
    const { register, handleSubmit, setError, formState, reset } = form;

    useEffect(() => reset(toForm(brand)), [brand, reset]);

    const submit = handleSubmit((values) =>
        save.mutate(toInput(values), {
            onSuccess: (saved) => {
                if (id === null) {
                    notify(t('brands.created'));
                    navigate(`/products/brands/${saved.id}`, { replace: true });
                } else {
                    notify(t('brands.saved'));
                }
            },
            onError: (error) => {
                if (error instanceof ApiError && error.status === 422) {
                    for (const field of fields) {
                        const message = error.fieldError(field);
                        if (message) setError(field as FieldPath<FormValues>, { message });
                    }
                } else {
                    notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
                }
            },
        }),
    );

    const errorOf = (field: keyof FormValues) => formState.errors[field]?.message;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('catalog.crumb') }, { label: t('brands.backToList'), to: '/products/brands' }, { label: id === null ? t('brands.new') : t('brands.edit') }]}
                title={id === null ? t('brands.new') : t('brands.edit')}
            />

            <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                <fieldset disabled={!editable || save.isPending} className="flex w-full min-w-0 max-w-3xl flex-col gap-3.5 rounded-md border border-line-default bg-surface p-5 shadow-e1">
                    <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                        <Field label={t('brands.field.name')} error={errorOf('name')}>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <input id={fieldId} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} {...register('name')} />
                            )}
                        </Field>
                        <Field label={t('brands.field.nameBn')} hint={t('brands.field.nameBnHint')} error={errorOf('name_bn')} optional>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <input id={fieldId} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} {...register('name_bn')} />
                            )}
                        </Field>
                    </div>

                    <Field label={t('brands.field.slug')} hint={t('brands.field.slugHint')} error={errorOf('slug')} optional>
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

                    <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                        <Field label={t('brands.field.description')} error={errorOf('description')} optional>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <textarea id={fieldId} rows={4} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} {...register('description')} />
                            )}
                        </Field>
                        <Field label={t('brands.field.descriptionBn')} error={errorOf('description_bn')} optional>
                            {({ id: fieldId, describedBy, invalid }) => (
                                <textarea id={fieldId} rows={4} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} {...register('description_bn')} />
                            )}
                        </Field>
                    </div>

                    <label className="flex min-h-touch-min items-center gap-3 t-admin-body text-fg-primary">
                        <input type="checkbox" className="size-4 accent-brand" {...register('is_active')} />
                        {t('brands.field.active')}
                    </label>
                </fieldset>

                {/* Figma FormActions 198:1085: 64 high, destructive far left, buttons 12 apart. */}
                <div className="sticky bottom-0 z-10 -mx-4 -mb-4 flex min-h-16 flex-wrap items-center justify-between gap-3 border-t border-line-default bg-surface px-4 py-3.5 shadow-e4 md:-mx-5 md:-mb-10 md:px-5 xl:-mx-6 xl:px-6">
                    {id !== null && brand && can(me, 'catalog.archive') && brand.is_active ? (
                        <Button variant="danger" onClick={() => setConfirming(true)}>
                            {t('brands.archive')}
                        </Button>
                    ) : (
                        <span />
                    )}
                    <div className="flex flex-wrap items-center gap-3">
                        <Link to="/products/brands" className={buttonClass('ghost')}>
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

            {/* Search & social (P7 SEO module): its own form, saved separately. */}
            {brand && (
                <div className="mt-4 w-full min-w-0 max-w-3xl md:mt-10">
                    <SeoPanel type="brand" id={brand.id} />
                </div>
            )}

            {brand && (
                <ConfirmDialog
                    open={confirming}
                    title={t('brands.archiveTitle', { name: brand.name })}
                    body={t('brands.archiveBody')}
                    confirmLabel={t('brands.archive')}
                    confirmText={brand.name}
                    typePrompt={t('brands.archivePrompt', { name: brand.name })}
                    pending={archive.isPending}
                    onClose={() => setConfirming(false)}
                    onConfirm={() =>
                        archive.mutate(undefined, {
                            onSuccess: () => {
                                setConfirming(false);
                                notify(t('brands.archived'));
                                navigate('/products/brands', { replace: true });
                            },
                        })
                    }
                />
            )}
        </section>
    );
}
