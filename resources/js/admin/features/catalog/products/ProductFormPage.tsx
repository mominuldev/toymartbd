import { useCallback, useEffect, useId, useMemo, useState, type ReactNode } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router';
import { useForm, useWatch, type FieldPath } from 'react-hook-form';
import { CopyIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { storeProductRequestSchema, updateProductRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { Field, controlHeight, inputClass, monoInputClass } from '@/components/ui/Field';
import { FormActions } from '@/components/ui/FormActions';
import { FormSection } from '@/components/ui/FormSection';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { formatDhakaDate } from '@/lib/format';
import { useAttributes } from '../attributes/api';
import { flatten, useCategoryTree } from '../categories/api';
import { genders, productStatuses, productTypes, useArchiveProduct, useBrandOptions, useDuplicateProduct, useProduct, useSaveProduct, type Product } from './api';
import {
    ageParts,
    combinations,
    placeErrors,
    toForm,
    toMatrix,
    toPayload,
    toRelations,
    type FieldError,
    type FormValues,
    type MatrixState,
    type PlacedErrors,
    type RelationRow,
    type VariantFields,
} from './document';
import { ProductStatusChip, TAKA } from './parts';
import { VariantMatrixEditor } from './VariantMatrixEditor';
import { RelationsEditor } from './RelationsEditor';
import { ImagesPanel } from './ImagesPanel';

type Tab = 'general' | 'pricing' | 'variants' | 'images' | 'related';

const tabLabel: Record<Tab, MessageKey> = {
    general: 'products.tab.general',
    pricing: 'products.tab.pricing',
    variants: 'products.tab.variants',
    images: 'products.tab.images',
    related: 'products.tab.related',
};

const statusOption: Record<Product['status'], MessageKey> = {
    active: 'products.status.active',
    draft: 'products.status.draft',
    archived: 'products.status.archived',
};
const typeOption: Record<Product['type'], MessageKey> = { simple: 'products.type.simple', variable: 'products.type.variable' };
const genderOption: Record<(typeof genders)[number], MessageKey> = { boys: 'products.gender.boys', girls: 'products.gender.girls', unisex: 'products.gender.unisex' };

/** The storefront product URL prefix (SlugField 198:1086); not translated — it is a URL. */
const URL_PREFIX = 'toymartbd.com/products/';

const noErrors: PlacedErrors = { form: [], rows: {}, relations: {}, matrix: [], other: [] };

/**
 * Product create / edit (B4, Figma "B · 12 Product edit" 241:37883). Tabs are URL
 * addressable (`?tab=variants`). The document is saved in one PUT; the generated Zod
 * schema checks its shape first, and both its issues and the server's 422 land on the
 * owning field, grid row or relation.
 */
export function ProductFormPage() {
    const params = useParams();
    const id = params.productId ? Number(params.productId) : null;
    const product = useProduct(id);

    if (id !== null && product.isError) {
        return <QueryError error={product.error} onRetry={() => void product.refetch()} />;
    }
    if (id !== null && !product.data) {
        return <p aria-busy="true" className="sr-only" />;
    }

    return <ProductForm key={product.data ? `${product.data.id}:${product.data.updated_at}` : 'new'} id={id} product={product.data} />;
}

function ProductForm({ id, product }: { id: number | null; product?: Product }) {
    const { t, locale } = useI18n();
    const bn = locale === 'bn';
    const { data: me } = useMe();
    const { notify } = useToast();
    const navigate = useNavigate();
    const [search, setSearch] = useSearchParams();
    const formId = useId();

    const save = useSaveProduct(id);
    const archive = useArchiveProduct(id ?? 0);
    const duplicate = useDuplicateProduct(id ?? 0);
    const attributes = useAttributes();
    const categories = useCategoryTree();
    const brands = useBrandOptions();

    const editable = can(me, id === null ? 'catalog.create' : 'catalog.update');
    const canCost = can(me, 'catalog.view_cost');
    // Setting the first prices is part of creating; changing them later needs update_price.
    const canPrice = id === null || can(me, 'catalog.update_price');

    const initialMatrix = useMemo(() => toMatrix(product), [product]);
    const initialRelations = useMemo(() => toRelations(product), [product]);
    const [matrix, setMatrix] = useState<MatrixState>(initialMatrix);
    const [relations, setRelations] = useState<RelationRow[]>(initialRelations);
    const [placed, setPlaced] = useState<PlacedErrors>(noErrors);
    const [confirming, setConfirming] = useState(false);

    const form = useForm<FormValues>({ defaultValues: toForm(product) });
    const { register, formState, setError, clearErrors, setValue, control, reset, getValues } = form;
    const type = useWatch({ control, name: 'type' });
    const categoryIds = useWatch({ control, name: 'category_ids' });
    const primaryId = useWatch({ control, name: 'primary_category_id' });
    const ageMin = useWatch({ control, name: 'age_min_months' });
    const ageMax = useWatch({ control, name: 'age_max_months' });

    const variantAttributes = useMemo(() => (attributes.data ?? []).filter((a) => a.is_variant_defining), [attributes.data]);
    const combos = useMemo(() => combinations(matrix.options, variantAttributes), [matrix.options, variantAttributes]);
    const flatCategories = useMemo(() => flatten(categories.data ?? []), [categories.data]);

    const tabs: Tab[] = ['general', type === 'variable' ? 'variants' : 'pricing', 'images', 'related'];
    const requested = search.get('tab') as Tab | null;
    const tab: Tab = requested && tabs.includes(requested) ? requested : 'general';
    const goTab = (next: Tab) =>
        setSearch(
            (current) => {
                const params = new URLSearchParams(current);
                if (next === 'general') params.delete('tab');
                else params.set('tab', next);
                return params;
            },
            { replace: true },
        );

    // Unsaved state per tab, for the dots and the save bar.
    const dirtyFields = formState.dirtyFields;
    const matrixDirty = JSON.stringify(matrix) !== JSON.stringify(initialMatrix);
    const relationsDirty = JSON.stringify(relations) !== JSON.stringify(initialRelations);
    const generalDirty = Object.keys(dirtyFields).some((k) => k !== 'simple');
    const pricingDirty = Boolean(dirtyFields.simple);
    const dirtyTabs: Record<Tab, boolean> = { general: generalDirty, pricing: pricingDirty, variants: matrixDirty, images: false, related: relationsDirty };
    const dirtyCount = Object.values(dirtyTabs).filter(Boolean).length;

    const errorTabs: Record<Tab, boolean> = {
        general: Object.keys(formState.errors).some((k) => k !== 'simple') || placed.other.length > 0,
        pricing: Boolean(formState.errors.simple),
        variants: placed.matrix.length > 0 || Object.keys(placed.rows).length > 0,
        images: false,
        related: Object.keys(placed.relations).length > 0,
    };

    const place = useCallback(
        (errors: FieldError[]) => {
            const next = placeErrors(errors, getValues('type'), combos, relations);
            for (const { field, message } of next.form) setError(field as FieldPath<FormValues>, { message });
            setPlaced(next);
            const first = (['general', 'pricing', 'variants', 'related'] as Tab[]).find((tabName) =>
                tabName === 'general'
                    ? next.form.some((f) => !f.field.startsWith('simple.')) || next.other.length > 0
                    : tabName === 'pricing'
                      ? next.form.some((f) => f.field.startsWith('simple.'))
                      : tabName === 'variants'
                        ? next.matrix.length > 0 || Object.keys(next.rows).length > 0
                        : Object.keys(next.relations).length > 0,
            );
            if (first && tabs.includes(first)) goTab(first);
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [combos, relations, getValues, setError, tabs.join()],
    );

    const submit = () => {
        clearErrors();
        setPlaced(noErrors);
        const values = getValues();
        const payload = toPayload(values, { canCost, combos, matrix, relations, product });

        // Shape check with the schema generated from the API's FormRequest (rule 27).
        const schema = id === null ? storeProductRequestSchema : updateProductRequestSchema;
        const parsed = schema.safeParse(payload);
        if (!parsed.success) {
            place(parsed.error.issues.map((issue) => ({ field: issue.path.join('.'), message: issue.message })));
            return;
        }

        save.mutate(payload, {
            onSuccess: (saved) => {
                if (id === null) {
                    notify(t('products.created'));
                    navigate(`/products/${saved.id}${tab === 'general' ? '' : `?tab=${tab}`}`, { replace: true });
                } else {
                    notify(t('products.saved'));
                }
            },
            onError: (error) => {
                if (error instanceof ApiError && error.status === 422) {
                    place(error.problem.errors ?? []);
                } else {
                    notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
                }
            },
        });
    };

    // ⌘S / Ctrl+S saves from anywhere in the form (A7 FormActions).
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
                event.preventDefault();
                if (editable && !save.isPending) submit();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    const discard = () => {
        reset(toForm(product));
        setMatrix(initialMatrix);
        setRelations(initialRelations);
        setPlaced(noErrors);
    };

    const toggleCategory = (categoryId: number, on: boolean) => {
        const next = on ? [...categoryIds, categoryId] : categoryIds.filter((c) => c !== categoryId);
        setValue('category_ids', next, { shouldDirty: true });
        if (on && primaryId === '') setValue('primary_category_id', String(categoryId), { shouldDirty: true });
        if (!on && primaryId === String(categoryId)) setValue('primary_category_id', next[0] !== undefined ? String(next[0]) : '', { shouldDirty: true });
    };

    const errorOf = (field: FieldPath<FormValues>): string | undefined => {
        const parts = field.split('.');
        let node: unknown = formState.errors;
        for (const part of parts) node = (node as Record<string, unknown> | undefined)?.[part];
        return (node as { message?: string } | undefined)?.message;
    };

    const primaryCategory = flatCategories.find((c) => String(c.node.id) === primaryId)?.node;
    const defaultSku = product?.variants.find((v) => v.is_default)?.sku;

    const ageHelp = (value: string) => {
        const parts = ageParts(value);
        if (!parts) return undefined;
        return t('products.age.helper', { years: parts.years, months: parts.months });
    };

    return (
        <section className="flex flex-col gap-6 pb-4">
            {/* ContentHeader */}
            <div className="flex flex-wrap items-center gap-4">
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <nav aria-label={t('products.breadcrumb')} className="t-admin-caption text-fg-tertiary">
                        <Link to="/products" className="hover:underline">
                            {t('catalog.tab.products')}
                        </Link>
                        {product && <span aria-hidden> / </span>}
                        {product && <span>{product.name}</span>}
                    </nav>
                    <div className="flex flex-wrap items-center gap-2.5">
                        <h1 className="t-admin-page-title text-fg-primary">{product ? product.name : t('products.new')}</h1>
                        {product && <ProductStatusChip status={product.status} />}
                    </div>
                    {product && (
                        <p className="t-admin-caption text-fg-secondary">
                            {[defaultSku, primaryCategory ? (bn ? (primaryCategory.name_bn ?? primaryCategory.name) : primaryCategory.name) : null, t('products.updatedAt', { date: formatDhakaDate(product.updated_at, locale) })]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                    )}
                </div>
                {product && can(me, 'catalog.create') && (
                    <Button
                        variant="outline"
                        disabled={duplicate.isPending}
                        onClick={() =>
                            duplicate.mutate(undefined, {
                                onSuccess: (copy) => {
                                    notify(t('products.duplicated'));
                                    navigate(`/products/${copy.id}`);
                                },
                                onError: (error) => notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
                            })
                        }
                    >
                        <CopyIcon size={16} aria-hidden />
                        {t('products.duplicate')}
                    </Button>
                )}
            </div>

            {/* Tabs (AdminTab 185:125) with unsaved / error dots */}
            {/* Below 1024: a scrolling chip strip with the dots kept (Figma 230:22079); ≥ md: underline tabs. */}
            <div role="tablist" aria-label={t('products.tabs')} className="-mx-1 flex gap-2 overflow-x-auto overflow-y-hidden scrollbar-none px-1 lg:gap-0.5 lg:border-b lg:border-line-default">
                {tabs.map((name) => {
                    const active = tab === name;
                    return (
                        <button
                            key={name}
                            type="button"
                            role="tab"
                            id={`${formId}-tab-${name}`}
                            aria-selected={active}
                            aria-controls={`${formId}-panel`}
                            onClick={() => goTab(name)}
                            className={`flex h-8 shrink-0 items-center gap-1.5 rounded-sm border px-3 whitespace-nowrap lg:-mb-px lg:h-10 lg:rounded-none lg:border-0 lg:border-b-2 ${
                                active
                                    ? 'border-brand bg-brand-subtle t-admin-button text-fg-primary lg:bg-transparent'
                                    : 'border-line-default t-admin-body text-fg-secondary hover:text-fg-primary lg:border-transparent'
                            }`}
                        >
                            {t(tabLabel[name])}
                            {errorTabs[name] ? (
                                <span className="size-1.5 rounded-full bg-danger">
                                    <span className="sr-only">{t('products.tabHasErrors')}</span>
                                </span>
                            ) : dirtyTabs[name] ? (
                                <span className="size-1.5 rounded-full bg-brand">
                                    <span className="sr-only">{t('products.tabUnsaved')}</span>
                                </span>
                            ) : null}
                        </button>
                    );
                })}
            </div>

            <form
                id={formId}
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
                className="flex flex-col gap-6"
            >
                {placed.other.length > 0 && (
                    <div role="alert" className="flex flex-col gap-1 rounded-md border border-line-danger bg-danger-subtle p-4">
                        {placed.other.map((message) => (
                            <p key={message} className="flex items-center gap-1.5 t-admin-body text-fg-primary">
                                <WarningCircleIcon size={16} weight="fill" aria-hidden className="shrink-0 text-feedback-danger" />
                                {message}
                            </p>
                        ))}
                    </div>
                )}

                <fieldset disabled={!editable || save.isPending} className="contents">
                    {/* Form 744 + rail 376 (B4). The variant matrix spans the full 1144 width, as in Figma, so the rail steps aside on that tab. */}
                    <div className={`grid items-start gap-6 ${tab === 'variants' ? '' : 'lg:grid-cols-[minmax(0,1fr)_minmax(0,23.5rem)]'}`}>
                        <div role="tabpanel" id={`${formId}-panel`} aria-labelledby={`${formId}-tab-${tab}`} className="flex min-w-0 flex-col gap-6">
                            {tab === 'general' && (
                                <FormSection title={t('products.section.general')} description={t('products.section.generalHint')}>
                                    <div className="grid gap-4 lg:grid-cols-2">
                                        <Field label={t('products.field.name')} hint={t('products.field.nameHint')} error={errorOf('name')} required>
                                            {(ids) => <input {...inputProps(ids)} className={`${controlHeight.md} ${inputClass}`} {...register('name')} />}
                                        </Field>
                                        <Field label={t('products.field.nameBn')} hint={t('products.field.nameBnHint')} error={errorOf('name_bn')} optional>
                                            {(ids) => <input {...inputProps(ids)} lang="bn" className={`${controlHeight.md} ${inputClass}`} {...register('name_bn')} />}
                                        </Field>
                                    </div>
                                    <Field label={t('products.field.slug')} hint={t('products.field.slugHint')} error={errorOf('slug')} optional>
                                        {(ids) => (
                                            <span className="flex h-10 items-stretch overflow-hidden rounded-sm border border-line-default bg-surface focus-within:border-line-focus focus-within:ring-1 focus-within:ring-inset focus-within:ring-line-focus has-aria-invalid:border-line-danger">
                                                <span aria-hidden className="flex items-center border-r border-line-default bg-subtle px-3 t-admin-mono-sm text-fg-tertiary">
                                                    {URL_PREFIX}
                                                </span>
                                                <input {...inputProps(ids)} spellCheck={false} autoCapitalize="none" className="min-w-0 flex-1 bg-transparent px-3 t-admin-mono text-fg-primary outline-none" {...register('slug')} />
                                            </span>
                                        )}
                                    </Field>
                                    <div className="grid gap-4 lg:grid-cols-2">
                                        <Field label={t('products.field.shortDescription')} hint={t('products.field.shortDescriptionHint')} error={errorOf('short_description')} optional>
                                            {(ids) => <textarea {...inputProps(ids)} rows={2} className={`${inputClass} py-2`} {...register('short_description')} />}
                                        </Field>
                                        <Field label={t('products.field.shortDescriptionBn')} error={errorOf('short_description_bn')} optional>
                                            {(ids) => <textarea {...inputProps(ids)} rows={2} lang="bn" className={`${inputClass} py-2`} {...register('short_description_bn')} />}
                                        </Field>
                                        <Field label={t('products.field.description')} error={errorOf('description')} optional>
                                            {(ids) => <textarea {...inputProps(ids)} rows={6} className={`${inputClass} py-2`} {...register('description')} />}
                                        </Field>
                                        <Field label={t('products.field.descriptionBn')} error={errorOf('description_bn')} optional>
                                            {(ids) => <textarea {...inputProps(ids)} rows={6} lang="bn" className={`${inputClass} py-2`} {...register('description_bn')} />}
                                        </Field>
                                    </div>

                                    <div className="grid gap-4 lg:grid-cols-3">
                                        {id === null ? (
                                            <Field label={t('products.field.type')} hint={t('products.field.typeHint')} error={errorOf('type')}>
                                                {(ids) => (
                                                    <Select {...inputProps(ids)} {...register('type')}>
                                                        {productTypes.map((value) => (
                                                            <option key={value} value={value}>
                                                                {t(typeOption[value])}
                                                            </option>
                                                        ))}
                                                    </Select>
                                                )}
                                            </Field>
                                        ) : (
                                            <ReadOnly label={t('products.field.type')} hint={t('products.field.typeFixed')}>
                                                {t(typeOption[type])}
                                            </ReadOnly>
                                        )}
                                        <Field label={t('products.field.brand')} error={errorOf('brand_id')} optional>
                                            {(ids) => (
                                                <Select key={brands.data?.length ?? 0} {...inputProps(ids)} {...register('brand_id')}>
                                                    <option value="">{t('products.field.noBrand')}</option>
                                                    {product?.brand && !(brands.data ?? []).some((b) => b.id === product.brand?.id) && <option value={product.brand.id}>{product.brand.name}</option>}
                                                    {(brands.data ?? []).map((brand) => (
                                                        <option key={brand.id} value={brand.id}>
                                                            {bn ? (brand.name_bn ?? brand.name) : brand.name}
                                                        </option>
                                                    ))}
                                                </Select>
                                            )}
                                        </Field>
                                        <Field label={t('products.field.gender')} error={errorOf('gender')} optional>
                                            {(ids) => (
                                                <Select {...inputProps(ids)} {...register('gender')}>
                                                    <option value="">{t('products.gender.none')}</option>
                                                    {genders.map((g) => (
                                                        <option key={g} value={g}>
                                                            {t(genderOption[g])}
                                                        </option>
                                                    ))}
                                                </Select>
                                            )}
                                        </Field>
                                    </div>

                                    <fieldset className="flex flex-col gap-1.5">
                                        <legend className="t-admin-body-strong text-fg-primary">{t('products.field.categories')}</legend>
                                        <p className="t-admin-caption text-fg-tertiary">{t('products.field.categoriesHint')}</p>
                                        <div className={`max-h-56 overflow-y-auto rounded-sm border p-2 ${errorOf('category_ids') ? 'border-line-danger' : 'border-line-default'}`}>
                                            {flatCategories.map(({ node, depth }) => (
                                                <label key={node.id} className="flex min-h-8 items-center gap-2 rounded-xs px-2 t-admin-body text-fg-primary hover:bg-subtle" style={{ paddingInlineStart: `${0.5 + depth * 1.25}rem` }}>
                                                    <input type="checkbox" className="size-4 accent-brand" checked={categoryIds.includes(node.id)} onChange={(e) => toggleCategory(node.id, e.target.checked)} />
                                                    <span lang={bn && node.name_bn ? 'bn' : undefined}>{bn ? (node.name_bn ?? node.name) : node.name}</span>
                                                    {!node.is_active && <span className="t-admin-caption text-fg-tertiary">{t('common.archived')}</span>}
                                                </label>
                                            ))}
                                        </div>
                                        {errorOf('category_ids') && <p className="t-admin-caption-strong text-feedback-danger">{errorOf('category_ids')}</p>}
                                    </fieldset>
                                    <Field label={t('products.field.primaryCategory')} hint={t('products.field.primaryCategoryHint')} error={errorOf('primary_category_id')}>
                                        {(ids) => (
                                            <Select key={`${flatCategories.length}:${categoryIds.join()}`} {...inputProps(ids)} {...register('primary_category_id')}>
                                                <option value="">{t('products.field.choose')}</option>
                                                {flatCategories
                                                    .filter(({ node }) => categoryIds.includes(node.id))
                                                    .map(({ node }) => (
                                                        <option key={node.id} value={node.id}>
                                                            {bn ? (node.name_bn ?? node.name) : node.name}
                                                        </option>
                                                    ))}
                                            </Select>
                                        )}
                                    </Field>

                                    <div className="grid gap-4 lg:grid-cols-2">
                                        <Field label={t('products.field.ageMin')} hint={ageHelp(ageMin) ?? t('products.field.ageHint')} error={errorOf('age_min_months')} optional>
                                            {(ids) => <input {...inputProps(ids)} inputMode="numeric" className={`${controlHeight.md} ${inputClass} tabular`} {...register('age_min_months')} />}
                                        </Field>
                                        <Field label={t('products.field.ageMax')} hint={ageHelp(ageMax) ?? t('products.field.ageHint')} error={errorOf('age_max_months')} optional>
                                            {(ids) => <input {...inputProps(ids)} inputMode="numeric" className={`${controlHeight.md} ${inputClass} tabular`} {...register('age_max_months')} />}
                                        </Field>
                                    </div>
                                    <label className="flex min-h-touch-min items-center gap-3 t-admin-body text-fg-primary">
                                        <input type="checkbox" className="size-4 accent-brand" {...register('is_educational')} />
                                        {t('products.field.educational')}
                                    </label>
                                </FormSection>
                            )}

                            {tab === 'pricing' && (
                                <FormSection title={t('products.section.pricing')} description={t('products.section.pricingHint')}>
                                    {!canPrice && <p className="rounded-sm bg-subtle px-3 py-2 t-admin-caption text-fg-secondary">{t('products.pricing.locked')}</p>}
                                    <div className="grid gap-4 lg:grid-cols-2">
                                        <MoneyField label={t('products.field.price')} hint={t('products.field.priceHint')} field="price" readOnly={!canPrice} form={form} error={errorOf('simple.price')} />
                                        <MoneyField label={t('products.field.compareAt')} hint={t('products.field.compareAtHint')} field="compare_at_price" readOnly={!canPrice} form={form} error={errorOf('simple.compare_at_price')} optional />
                                        {canCost && <MoneyField label={t('products.field.cost')} hint={t('products.field.costHint')} field="cost_price" form={form} error={errorOf('simple.cost_price')} optional />}
                                        <MoneyField label={t('products.field.wholesale')} field="wholesale_price" readOnly={!canPrice} form={form} error={errorOf('simple.wholesale_price')} optional />
                                        <Field label={t('products.field.sku')} hint={t('products.field.skuHint')} error={errorOf('simple.sku')} optional>
                                            {(ids) => <input {...inputProps(ids)} spellCheck={false} className={`${controlHeight.md} ${monoInputClass}`} {...register('simple.sku')} />}
                                        </Field>
                                        <Field label={t('products.field.barcode')} error={errorOf('simple.barcode')} optional>
                                            {(ids) => <input {...inputProps(ids)} spellCheck={false} className={`${controlHeight.md} ${monoInputClass}`} {...register('simple.barcode')} />}
                                        </Field>
                                        <Field label={t('products.field.weight')} error={errorOf('simple.weight_grams')} optional>
                                            {(ids) => <input {...inputProps(ids)} inputMode="numeric" className={`${controlHeight.md} ${inputClass} text-end tabular`} {...register('simple.weight_grams')} />}
                                        </Field>
                                    </div>
                                </FormSection>
                            )}

                            {tab === 'variants' &&
                                (attributes.isError ? (
                                    <QueryError error={attributes.error} onRetry={() => void attributes.refetch()} />
                                ) : (
                                    <VariantMatrixEditor
                                        attributes={variantAttributes}
                                        value={matrix}
                                        combos={combos}
                                        onChange={setMatrix}
                                        errors={placed.rows}
                                        matrixErrors={placed.matrix}
                                        canCost={canCost}
                                        canPrice={canPrice}
                                        disabled={!editable || save.isPending}
                                    />
                                ))}

                            {tab === 'images' && <ImagesPanel product={product} disabled={!editable} />}

                            {tab === 'related' && <RelationsEditor productId={id} value={relations} onChange={setRelations} errors={placed.relations} disabled={!editable || save.isPending} />}
                        </div>

                        {/* Rail */}
                        <aside className={`flex flex-col gap-6 ${tab === 'variants' ? 'hidden' : ''}`}>
                            <FormSection title={t('products.section.status')}>
                                <Field label={t('products.field.status')} error={errorOf('status')}>
                                    {(ids) => (
                                        <Select {...inputProps(ids)} {...register('status')}>
                                            {productStatuses.map((value) => (
                                                <option key={value} value={value}>
                                                    {t(statusOption[value])}
                                                </option>
                                            ))}
                                        </Select>
                                    )}
                                </Field>
                                <label className="flex min-h-touch-min items-center gap-3 t-admin-body text-fg-primary">
                                    <input type="checkbox" className="size-4 accent-brand" {...register('is_featured')} />
                                    {t('products.field.featured')}
                                </label>
                            </FormSection>
                            <FormSection title={t('products.section.compliance')} description={t('products.section.complianceHint')}>
                                <Field label={t('products.field.safetyNote')} error={errorOf('safety_note')} optional>
                                    {(ids) => <input {...inputProps(ids)} className={`${controlHeight.md} ${inputClass}`} {...register('safety_note')} />}
                                </Field>
                                <Field label={t('products.field.safetyNoteBn')} error={errorOf('safety_note_bn')} optional>
                                    {(ids) => <input {...inputProps(ids)} lang="bn" className={`${controlHeight.md} ${inputClass}`} {...register('safety_note_bn')} />}
                                </Field>
                                <Field label={t('products.field.warranty')} hint={t('products.field.warrantyHint')} error={errorOf('warranty_months')} optional>
                                    {(ids) => <input {...inputProps(ids)} inputMode="numeric" className={`${controlHeight.md} ${inputClass} tabular`} {...register('warranty_months')} />}
                                </Field>
                            </FormSection>
                        </aside>
                    </div>
                </fieldset>

                {/* Below 1024 the destructive action leaves the save bar and sits after the form (Figma 230:22079). */}
                {id !== null && product && can(me, 'catalog.archive') && product.status !== 'archived' && (
                    <Button variant="danger" onClick={() => setConfirming(true)} className="w-full lg:hidden">
                        {t('products.archive')}
                    </Button>
                )}

                {/* FormActions 198:1085 — sticky save bar. Destructive action far left (≥ 1024), then the save status. */}
                <FormActions
                    start={
                        <>
                            {id !== null && product && can(me, 'catalog.archive') && product.status !== 'archived' && (
                                <span className="hidden items-center gap-3 lg:flex">
                                    <Button variant="danger" onClick={() => setConfirming(true)}>
                                        {t('products.archive')}
                                    </Button>
                                    <span aria-hidden className="h-6 w-px bg-line-default" />
                                </span>
                            )}
                            <p className="flex min-w-0 items-center gap-2" aria-live="polite">
                                {dirtyCount > 0 && <span aria-hidden className="size-2.5 shrink-0 rounded-full bg-brand" />}
                                <span className="t-admin-body text-fg-primary">{dirtyCount > 0 ? t('products.unsaved', { count: dirtyCount }) : t('products.noChanges')}</span>
                                <span className="hidden t-admin-caption text-fg-tertiary md:inline">{t('products.saveShortcut')}</span>
                            </p>
                        </>
                    }
                >
                    {dirtyCount > 0 && editable && (
                        <Button variant="ghost" onClick={discard}>
                            {t('products.discard')}
                        </Button>
                    )}
                    {id === null && (
                        <Link to="/products" className={buttonClass('outline')}>
                            {t('common.cancel')}
                        </Link>
                    )}
                    {editable && (
                        <Button type="submit" disabled={save.isPending}>
                            {save.isPending ? t('common.saving') : id === null ? t('products.create') : t('products.saveChanges')}
                        </Button>
                    )}
                </FormActions>
            </form>

            {product && (
                <ConfirmDialog
                    open={confirming}
                    title={t('products.archiveTitle', { name: product.name })}
                    body={t('products.archiveBody')}
                    confirmLabel={t('products.archive')}
                    confirmText={product.name}
                    typePrompt={t('products.archivePrompt', { name: product.name })}
                    pending={archive.isPending}
                    onClose={() => setConfirming(false)}
                    onConfirm={() =>
                        archive.mutate(undefined, {
                            onSuccess: () => {
                                setConfirming(false);
                                notify(t('products.archived'));
                                navigate('/products', { replace: true });
                            },
                        })
                    }
                />
            )}
        </section>
    );
}

function inputProps({ id, describedBy, invalid }: { id: string; describedBy: string | undefined; invalid: boolean }) {
    return { id, 'aria-describedby': describedBy, 'aria-invalid': invalid || undefined };
}

function ReadOnly({ label, hint, children }: { label: string; hint: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1.5">
            <p className="t-admin-body-strong text-fg-primary">{label}</p>
            <p className="flex h-control-md items-center rounded-sm border border-line-default bg-subtle px-3 t-admin-body text-fg-secondary">{children}</p>
            <p className="t-admin-caption text-fg-tertiary">{hint}</p>
        </div>
    );
}

type MoneyFieldProps = {
    label: string;
    hint?: string;
    field: Extract<keyof VariantFields, 'price' | 'compare_at_price' | 'cost_price' | 'wholesale_price'>;
    readOnly?: boolean;
    optional?: boolean;
    error?: string;
    form: ReturnType<typeof useForm<FormValues>>;
};

function MoneyField({ label, hint, field, readOnly, optional, error, form }: MoneyFieldProps) {
    return (
        <Field label={label} hint={hint} error={error} optional={optional}>
            {(ids) => (
                <Input
                    {...inputProps(ids)}
                    prefix={TAKA}
                    inputMode="decimal"
                    autoComplete="off"
                    readOnly={readOnly}
                    className={`[&_input]:tabular-nums ${readOnly ? 'bg-subtle!' : ''}`}
                    {...form.register(`simple.${field}`)}
                />
            )}
        </Field>
    );
}
