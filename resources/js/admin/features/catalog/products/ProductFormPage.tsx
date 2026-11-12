import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router';
import { useForm, useWatch, type FieldPath } from 'react-hook-form';
import { ArrowSquareOutIcon, CaretDownIcon, CopyIcon, TranslateIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { storeProductRequestSchema, updateProductRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { Field, controlHeight, inputClass, monoInputClass } from '@/components/ui/Field';
import { FormActions } from '@/components/ui/FormActions';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { useAttributes } from '../attributes/api';
import { flatten, useCategoryTree } from '../categories/api';
import { genders, productStatuses, productTypes, useArchiveProduct, useBrandOptions, useDuplicateProduct, useProduct, useSaveProduct, type Product } from './api';
import {
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
import { ProductStatusChip, TAKA, Toggle } from './parts';
import { ProductSection } from './ProductSection';
import { VariantMatrixEditor, type StockMode } from './VariantMatrixEditor';
import { RelationsEditor } from './RelationsEditor';
import { ImagesPanel } from './ImagesPanel';

/** The section navigation of Figma "B · 12 Product edit" 241:37900, in Figma order. */
type Section = 'general' | 'media' | 'pricing' | 'variants' | 'related';

const sectionLabel: Record<Section, MessageKey> = {
    general: 'products.tab.general',
    media: 'products.tab.images',
    pricing: 'products.tab.pricing',
    variants: 'products.tab.variants',
    related: 'products.tab.related',
};

/** One form per page, so plain ids; `?tab=` addresses the same sections. */
const anchor = (section: Section) => `product-${section}`;

const statusOption: Record<Product['status'], MessageKey> = {
    active: 'products.status.active',
    draft: 'products.status.draft',
    archived: 'products.status.archived',
};
const typeOption: Record<Product['type'], MessageKey> = { simple: 'products.type.simple', variable: 'products.type.variable' };
const genderOption: Record<(typeof genders)[number], MessageKey> = { boys: 'products.gender.boys', girls: 'products.gender.girls', unisex: 'products.gender.unisex' };

/** The storefront product URL prefix (SlugField 198:1086); not translated — it is a URL. */
const URL_PREFIX = 'toymartbd.com/products/';

/** Age steps for the Age range selects, in months (the API stores months). */
const AGE_STEPS = [0, 3, 6, 9, 12, 18, 24, 36, 48, 60, 72, 84, 96, 108, 120, 144, 168, 192, 216];

const noErrors: PlacedErrors = { form: [], rows: {}, relations: {}, matrix: [], other: [] };

/** "06 Nov 2026, 4:30 pm" in Dhaka time, Western digits (rules 11, 36). Display only. */
function formatDhakaDateTime(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        timeZone: 'Asia/Dhaka',
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
        numberingSystem: 'latn',
    }).format(new Date(iso));
}

function smoothScroll(): ScrollBehavior {
    return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
}

/**
 * Product create / edit (B4, Figma "B · 12 Product edit" 241:37883; 768 · 230:22079).
 * One long form, as in Figma: the 744 form column (General, Media, Pricing, URL) beside the
 * 376 rail (Status & visibility, Compliance, Recommendations), then the full-width variant
 * matrix. The tab strip jumps to a section and is URL addressable (`?tab=variants`). The
 * document is saved in one PUT; the generated Zod schema checks its shape first, and both
 * its issues and the server's 422 land on the owning field, grid row or relation.
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
    // Stock: opening stock on create (inventory.adjust), read-only afterwards (inventory.view).
    const simpleStock = toForm(product).simple_stock;
    const stockMode: StockMode = id === null ? (can(me, 'inventory.adjust') ? 'opening' : 'hidden') : can(me, 'inventory.view') ? 'readonly' : 'hidden';
    const canArchive = id !== null && product !== undefined && can(me, 'catalog.archive') && product.status !== 'archived';

    const initialMatrix = useMemo(() => toMatrix(product), [product]);
    const initialRelations = useMemo(() => toRelations(product), [product]);
    const [matrix, setMatrix] = useState<MatrixState>(initialMatrix);
    const [relations, setRelations] = useState<RelationRow[]>(initialRelations);
    const [placed, setPlaced] = useState<PlacedErrors>(noErrors);
    const [confirming, setConfirming] = useState(false);
    const [descLang, setDescLang] = useState<'en' | 'bn'>('en');
    const [moreOpen, setMoreOpen] = useState(false);
    const addAnother = useRef(false);

    const form = useForm<FormValues>({ defaultValues: toForm(product) });
    const { register, formState, setError, clearErrors, setValue, control, reset, getValues } = form;
    const type = useWatch({ control, name: 'type' });
    const categoryIds = useWatch({ control, name: 'category_ids' });
    const primaryId = useWatch({ control, name: 'primary_category_id' });
    const ageMin = useWatch({ control, name: 'age_min_months' });
    const ageMax = useWatch({ control, name: 'age_max_months' });
    const featured = useWatch({ control, name: 'is_featured' });

    const variantAttributes = useMemo(() => (attributes.data ?? []).filter((a) => a.is_variant_defining), [attributes.data]);
    const combos = useMemo(() => combinations(matrix.options, variantAttributes), [matrix.options, variantAttributes]);
    const flatCategories = useMemo(() => flatten(categories.data ?? []), [categories.data]);

    const sections: Section[] = ['general', 'media', type === 'variable' ? 'variants' : 'pricing', 'related'];
    const requestedRaw = search.get('tab');
    const requested = (requestedRaw === 'images' ? 'media' : requestedRaw) as Section | null;
    const [active, setActive] = useState<Section>(requested && sections.includes(requested) ? requested : 'general');
    const spyPaused = useRef(0);

    const goTab = useCallback(
        (next: Section) => {
            setActive(next);
            spyPaused.current = Date.now() + 800;
            setSearch(
                (current) => {
                    const params = new URLSearchParams(current);
                    if (next === 'general') params.delete('tab');
                    else params.set('tab', next);
                    return params;
                },
                { replace: true },
            );
            document.getElementById(anchor(next))?.scrollIntoView?.({ behavior: smoothScroll(), block: 'start' });
        },
        [setSearch],
    );

    // Open on the section the URL names.
    useEffect(() => {
        if (requested && requested !== 'general') document.getElementById(anchor(requested))?.scrollIntoView?.({ block: 'start' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Scroll-spy: the first section (in tab order) inside the top third of the viewport is the active tab.
    const sectionKey = sections.join();
    useEffect(() => {
        if (typeof IntersectionObserver === 'undefined') return;
        const visible = new Map<string, boolean>();
        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) visible.set(entry.target.id, entry.isIntersecting);
                if (Date.now() < spyPaused.current) return;
                const first = sections.find((s) => visible.get(anchor(s)));
                if (first) setActive(first);
            },
            { rootMargin: '0px 0px -65% 0px' },
        );
        for (const s of sections) {
            const el = document.getElementById(anchor(s));
            if (el) observer.observe(el);
        }
        return () => observer.disconnect();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sectionKey]);

    // Unsaved state per section, for the dots and the save bar.
    const dirtyFields = formState.dirtyFields;
    const matrixDirty = JSON.stringify(matrix) !== JSON.stringify(initialMatrix);
    const relationsDirty = JSON.stringify(relations) !== JSON.stringify(initialRelations);
    const generalKeys = Object.keys(dirtyFields).filter((k) => k !== 'simple');
    const simpleKeys = Object.values(dirtyFields.simple ?? {}).filter(Boolean).length;
    const dirtySections: Record<Section, boolean> = { general: generalKeys.length > 0, media: false, pricing: simpleKeys > 0, variants: matrixDirty, related: relationsDirty };
    const dirtyCount = Object.values(dirtySections).filter(Boolean).length;
    const changeCount = generalKeys.length + simpleKeys + (matrixDirty ? 1 : 0) + (relationsDirty ? 1 : 0);

    const errorSections: Record<Section, boolean> = {
        general: Object.keys(formState.errors).some((k) => k !== 'simple') || placed.other.length > 0,
        media: false,
        pricing: Boolean(formState.errors.simple),
        variants: placed.matrix.length > 0 || Object.keys(placed.rows).length > 0,
        related: Object.keys(placed.relations).length > 0,
    };

    const place = useCallback(
        (errors: FieldError[]) => {
            const next = placeErrors(errors, getValues('type'), combos, relations);
            for (const { field, message } of next.form) setError(field as FieldPath<FormValues>, { message });
            setPlaced(next);
            const first = (['general', 'pricing', 'variants', 'related'] as Section[]).find((name) =>
                name === 'general'
                    ? next.form.some((f) => !f.field.startsWith('simple.')) || next.other.length > 0
                    : name === 'pricing'
                      ? next.form.some((f) => f.field.startsWith('simple.'))
                      : name === 'variants'
                        ? next.matrix.length > 0 || Object.keys(next.rows).length > 0
                        : Object.keys(next.relations).length > 0,
            );
            if (first && sections.includes(first)) goTab(first);
            // Errors in the "More details" fields open it.
            if (next.form.some((f) => ['short_description', 'short_description_bn', 'category_ids', 'gender', 'is_educational'].includes(f.field))) setMoreOpen(true);
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [combos, relations, getValues, setError, goTab, sectionKey],
    );

    const discard = () => {
        reset(toForm(product));
        setMatrix(initialMatrix);
        setRelations(initialRelations);
        setPlaced(noErrors);
    };

    const submit = () => {
        clearErrors();
        setPlaced(noErrors);
        const values = getValues();
        const payload = toPayload(values, { canCost, canOpeningStock: stockMode === 'opening', combos, matrix, relations, product });
        const another = addAnother.current;
        addAnother.current = false;

        // Shape check with the schema generated from the API's FormRequest (rule 27).
        const schema = id === null ? storeProductRequestSchema : updateProductRequestSchema;
        const parsed = schema.safeParse(payload);
        if (!parsed.success) {
            place(parsed.error.issues.map((issue) => ({ field: issue.path.join('.'), message: issue.message })));
            return;
        }

        save.mutate(payload, {
            onSuccess: (saved) => {
                notify(t(id === null ? 'products.created' : 'products.saved'));
                if (another) {
                    if (id === null) {
                        // Same route, same form instance: start a fresh document.
                        reset(toForm());
                        setMatrix(toMatrix());
                        setRelations([]);
                        goTab('general');
                    } else {
                        navigate('/products/new');
                    }
                } else if (id === null) {
                    navigate(`/products/${saved.id}${active === 'general' ? '' : `?tab=${active}`}`, { replace: true });
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

    /** The Category select is the primary category; it is always one of the product's categories. */
    const choosePrimary = (value: string) => {
        const previous = primaryId === '' ? null : Number(primaryId);
        const others = categoryIds.filter((c) => c !== previous);
        const next = value === '' ? others : [Number(value), ...others.filter((c) => c !== Number(value))];
        setValue('category_ids', next, { shouldDirty: true });
        setValue('primary_category_id', value, { shouldDirty: true });
    };

    const toggleCategory = (categoryId: number, on: boolean) => {
        const next = on ? [...categoryIds, categoryId] : categoryIds.filter((c) => c !== categoryId);
        setValue('category_ids', next, { shouldDirty: true });
        if (on && primaryId === '') setValue('primary_category_id', String(categoryId), { shouldDirty: true });
    };

    const errorOf = (field: FieldPath<FormValues>): string | undefined => {
        const parts = field.split('.');
        let node: unknown = formState.errors;
        for (const part of parts) node = (node as Record<string, unknown> | undefined)?.[part];
        return (node as { message?: string } | undefined)?.message;
    };

    const primaryCategory = flatCategories.find((c) => String(c.node.id) === primaryId)?.node;
    const defaultSku = product?.variants.find((v) => v.is_default)?.sku;
    const categoryName = (node: { name: string; name_bn: string | null }) => (bn ? (node.name_bn ?? node.name) : node.name);

    const ageLabel = (months: number) => {
        if (months < 24 && months % 12 !== 0) return t('products.age.months', { count: months });
        if (months % 12 === 0) return t('products.age.years', { count: months / 12 });
        return t('products.age.yearsMonths', { years: Math.floor(months / 12), months: months % 12 });
    };
    const ageOptions = (current: string) => {
        const steps = new Set(AGE_STEPS);
        if (current !== '' && Number.isInteger(Number(current))) steps.add(Number(current));
        return [...steps].sort((a, b) => a - b);
    };

    const typeSelect =
        id === null ? (
            <span className="flex items-center gap-2">
                <label htmlFor={`${formId}-type`} className="t-admin-body-strong text-fg-primary">
                    {t('products.field.type')}
                </label>
                <Select id={`${formId}-type`} size="sm" className="w-36" {...register('type')}>
                    {productTypes.map((value) => (
                        <option key={value} value={value}>
                            {t(typeOption[value])}
                        </option>
                    ))}
                </Select>
            </span>
        ) : null;

    const publishedSince = product?.published_at ? formatDhakaDateTime(product.published_at, locale).split(',')[0] : null;
    const descError = errorOf('description') ?? errorOf('description_bn');

    return (
        <section className="flex flex-col gap-6 pb-4">
            {/* ContentHeader 187:530 (241:37888) */}
            <div className="flex flex-wrap items-center gap-4">
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <nav aria-label={t('products.breadcrumb')} className="t-admin-caption text-fg-tertiary max-lg:hidden">
                        <span>{t('catalog.crumb')}</span>
                        <span aria-hidden> / </span>
                        <Link to="/products" className="hover:underline">
                            {t('catalog.tab.products')}
                        </Link>
                        {product && <span aria-hidden> / </span>}
                        {product && <span>{product.name}</span>}
                    </nav>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="t-admin-page-title text-fg-primary">{product ? product.name : t('products.new')}</h1>
                        {product && <ProductStatusChip status={product.status} />}
                    </div>
                    {product && (
                        <p className="t-admin-body text-fg-secondary max-lg:hidden">
                            {[defaultSku, primaryCategory ? categoryName(primaryCategory) : null, t('products.updatedAt', { date: formatDhakaDateTime(product.updated_at, locale) })]
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

            {/* Section tabs (AdminTab 185:125) with the unsaved / error dot after the label (241:37900).
                Below 1024: a scrolling chip strip (230:22079); ≥ 1024: underline tabs on a baseline. */}
            <nav aria-label={t('products.tabs')} className="-mx-1 flex gap-2 overflow-x-auto overflow-y-hidden scrollbar-none px-1 lg:gap-0.5 lg:border-b lg:border-line-default">
                {sections.map((name) => {
                    const current = active === name;
                    const dot = errorSections[name] ? 'bg-danger' : dirtySections[name] ? 'bg-brand' : null;
                    return (
                        <span key={name} className="flex shrink-0 items-center gap-0.5 lg:items-start">
                            <a
                                href={`#${anchor(name)}`}
                                aria-current={current ? 'location' : undefined}
                                onClick={(event) => {
                                    event.preventDefault();
                                    goTab(name);
                                }}
                                className={`flex h-8 items-center rounded-sm border px-3 whitespace-nowrap lg:-mb-px lg:h-10 lg:rounded-none lg:border-0 lg:border-b-2 ${
                                    current
                                        ? 'border-brand bg-brand-subtle t-admin-button text-fg-primary lg:bg-transparent'
                                        : 'border-line-default t-admin-body text-fg-secondary hover:text-fg-primary lg:border-transparent'
                                }`}
                            >
                                {t(sectionLabel[name])}
                                {errorSections[name] ? <span className="sr-only"> {t('products.tabHasErrors')}</span> : dirtySections[name] ? <span className="sr-only"> {t('products.tabUnsaved')}</span> : null}
                            </a>
                            {dot && <span aria-hidden className={`size-1.5 shrink-0 rounded-full ${dot}`} />}
                        </span>
                    );
                })}
            </nav>

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
                    {/* Columns: form 744 + rail 376 at 1440 (288 at 1024); the rail drops below the form under 1024. */}
                    <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_--spacing(72)] xl:grid-cols-[minmax(0,1fr)_--spacing(94)]">
                        <div className="flex min-w-0 flex-col gap-6">
                            {/* General (241:37913) */}
                            <ProductSection id={anchor('general')} title={t('products.section.general')} description={t('products.section.generalHint')} defaultOpen forceOpen={errorSections.general}>
                                <div className="grid gap-4 lg:grid-cols-2">
                                    <Field label={t('products.field.name')} hint={t('products.field.nameHint')} error={errorOf('name')} required>
                                        {(ids) => <input {...inputProps(ids)} className={`${controlHeight.md} ${inputClass}`} {...register('name')} />}
                                    </Field>
                                    <Field label={t('products.field.nameBn')} hint={t('products.field.nameBnHint')} error={errorOf('name_bn')}>
                                        {(ids) => <input {...inputProps(ids)} lang="bn" className={`${controlHeight.md} ${inputClass}`} {...register('name_bn')} />}
                                    </Field>
                                </div>

                                {/* Description: one editor with the EN | বাং switch of RichTextToolbar 201:1570. */}
                                <div className="flex flex-col gap-1.5">
                                    <label htmlFor={`${formId}-desc-${descLang}`} className="t-admin-body-strong text-fg-primary">
                                        {t('products.field.description')}
                                    </label>
                                    <div role="group" aria-label={t('products.field.descriptionLanguage')} className="flex w-fit items-center gap-0.5 rounded-sm border border-line-default bg-subtle p-1">
                                        <TranslateIcon size={16} aria-hidden className="mx-2 text-fg-secondary" />
                                        {(['en', 'bn'] as const).map((lang) => (
                                            <button
                                                key={lang}
                                                type="button"
                                                lang={lang}
                                                aria-pressed={descLang === lang}
                                                onClick={() => setDescLang(lang)}
                                                className="h-8 rounded-sm px-3 t-admin-button-sm text-fg-secondary hover:text-fg-primary aria-pressed:bg-surface aria-pressed:text-fg-primary aria-pressed:shadow-e1"
                                            >
                                                {lang === 'en' ? 'EN' : 'বাং'}
                                            </button>
                                        ))}
                                    </div>
                                    <textarea
                                        id={`${formId}-desc-en`}
                                        hidden={descLang !== 'en'}
                                        rows={4}
                                        aria-invalid={errorOf('description') ? true : undefined}
                                        className={`${inputClass} p-3.5`}
                                        {...register('description')}
                                    />
                                    <textarea
                                        id={`${formId}-desc-bn`}
                                        hidden={descLang !== 'bn'}
                                        rows={4}
                                        lang="bn"
                                        aria-invalid={errorOf('description_bn') ? true : undefined}
                                        className={`${inputClass} p-3.5`}
                                        {...register('description_bn')}
                                    />
                                    {descError && (
                                        <p className="flex items-start gap-1 t-admin-caption text-fg-primary">
                                            <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                                            {descError}
                                        </p>
                                    )}
                                </div>

                                {/* Category · Brand · Age (241:37927) */}
                                <div className="grid gap-4 lg:grid-cols-3">
                                    <Field label={t('products.field.category')} error={errorOf('primary_category_id') ?? errorOf('category_ids')}>
                                        {(ids) => (
                                            <Select key={`${flatCategories.length}`} {...inputProps(ids)} value={primaryId} onChange={(e) => choosePrimary(e.target.value)}>
                                                <option value="">{t('products.field.choose')}</option>
                                                {flatCategories.map(({ node, depth }) => (
                                                    <option key={node.id} value={node.id}>
                                                        {`${'  '.repeat(depth)}${categoryName(node)}`}
                                                    </option>
                                                ))}
                                            </Select>
                                        )}
                                    </Field>
                                    <Field label={t('products.field.brand')} error={errorOf('brand_id')}>
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
                                    <fieldset className="flex min-w-0 flex-col gap-1.5">
                                        <legend className="mb-1.5 t-admin-body-strong text-fg-primary">{t('products.field.age')}</legend>
                                        <div className="flex items-center gap-1.5">
                                            <Select aria-label={t('products.field.ageMin')} aria-invalid={errorOf('age_min_months') ? true : undefined} className="min-w-0 flex-1" {...register('age_min_months')}>
                                                <option value="">{t('products.age.any')}</option>
                                                {ageOptions(ageMin).map((m) => (
                                                    <option key={m} value={m}>
                                                        {ageLabel(m)}
                                                    </option>
                                                ))}
                                            </Select>
                                            <span aria-hidden className="t-admin-body text-fg-secondary">
                                                –
                                            </span>
                                            <Select aria-label={t('products.field.ageMax')} aria-invalid={errorOf('age_max_months') ? true : undefined} className="min-w-0 flex-1" {...register('age_max_months')}>
                                                <option value="">{t('products.age.any')}</option>
                                                {ageOptions(ageMax).map((m) => (
                                                    <option key={m} value={m}>
                                                        {ageLabel(m)}
                                                    </option>
                                                ))}
                                            </Select>
                                        </div>
                                        {(errorOf('age_min_months') ?? errorOf('age_max_months')) && (
                                            <p className="flex items-start gap-1 t-admin-caption text-fg-primary">
                                                <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                                                {errorOf('age_min_months') ?? errorOf('age_max_months')}
                                            </p>
                                        )}
                                    </fieldset>
                                </div>

                                {/* Fields the API has but the Figma frame does not show, kept one click away. */}
                                <details open={moreOpen} onToggle={(e) => setMoreOpen((e.target as HTMLDetailsElement).open)} className="group flex flex-col">
                                    <summary className="flex w-fit cursor-pointer list-none items-center gap-1.5 t-admin-body-strong text-fg-primary [&::-webkit-details-marker]:hidden">
                                        <CaretDownIcon size={16} aria-hidden className="-rotate-90 transition-transform duration-150 ease-standard group-open:rotate-0" />
                                        {t('products.moreDetails')}
                                    </summary>
                                    <div className="mt-3.5 flex flex-col gap-3.5">
                                        <div className="grid gap-4 lg:grid-cols-2">
                                            <Field label={t('products.field.shortDescription')} hint={t('products.field.shortDescriptionHint')} error={errorOf('short_description')}>
                                                {(ids) => <textarea {...inputProps(ids)} rows={2} className={`${inputClass} py-2`} {...register('short_description')} />}
                                            </Field>
                                            <Field label={t('products.field.shortDescriptionBn')} error={errorOf('short_description_bn')}>
                                                {(ids) => <textarea {...inputProps(ids)} rows={2} lang="bn" className={`${inputClass} py-2`} {...register('short_description_bn')} />}
                                            </Field>
                                        </div>
                                        <fieldset className="flex flex-col gap-1.5">
                                            <legend className="t-admin-body-strong text-fg-primary">{t('products.field.categories')}</legend>
                                            <p className="t-admin-caption text-fg-secondary">{t('products.field.categoriesHint')}</p>
                                            <div className="max-h-56 overflow-y-auto rounded-sm border border-line-default p-2">
                                                {flatCategories
                                                    .filter(({ node }) => String(node.id) !== primaryId)
                                                    .map(({ node, depth }) => (
                                                        <label key={node.id} className="flex min-h-8 items-center gap-2 rounded-xs px-2 t-admin-body text-fg-primary hover:bg-subtle" style={{ paddingInlineStart: `${0.5 + depth * 1.25}rem` }}>
                                                            <input type="checkbox" className="size-4 accent-brand" checked={categoryIds.includes(node.id)} onChange={(e) => toggleCategory(node.id, e.target.checked)} />
                                                            <span lang={bn && node.name_bn ? 'bn' : undefined}>{categoryName(node)}</span>
                                                            {!node.is_active && <span className="t-admin-caption text-fg-tertiary">{t('common.archived')}</span>}
                                                        </label>
                                                    ))}
                                            </div>
                                        </fieldset>
                                        <div className="grid items-end gap-4 lg:grid-cols-2">
                                            <Field label={t('products.field.gender')} error={errorOf('gender')}>
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
                                            <label className="flex h-10 items-center gap-3 t-admin-body text-fg-primary">
                                                <input type="checkbox" className="size-4 accent-brand" {...register('is_educational')} />
                                                {t('products.field.educational')}
                                            </label>
                                        </div>
                                    </div>
                                </details>
                            </ProductSection>

                            {/* Media — ImageUploader 201:1035 */}
                            <div id={anchor('media')} className="scroll-mt-6">
                                <ImagesPanel product={product} disabled={!editable} />
                            </div>

                            {/* Pricing (FormSection 241:37938) — a simple product's one variant. */}
                            {type === 'simple' && (
                                <ProductSection id={anchor('pricing')} title={t('products.section.pricing')} description={t('products.section.pricingHint')} aside={typeSelect} forceOpen={errorSections.pricing}>
                                    {!canPrice && <p className="rounded-sm bg-subtle px-3 py-2 t-admin-caption text-fg-secondary">{t('products.pricing.locked')}</p>}
                                    <div className="grid gap-4 lg:grid-cols-2">
                                        <MoneyField label={t('products.field.price')} hint={t('products.field.priceHint')} field="price" readOnly={!canPrice} form={form} error={errorOf('simple.price')} required />
                                        <MoneyField label={t('products.field.compareAt')} hint={t('products.field.compareAtHint')} field="compare_at_price" readOnly={!canPrice} form={form} error={errorOf('simple.compare_at_price')} />
                                        {canCost && <MoneyField label={t('products.field.costPrice')} hint={t('products.field.costHint')} field="cost_price" form={form} error={errorOf('simple.cost_price')} />}
                                        <MoneyField label={t('products.field.wholesale')} field="wholesale_price" readOnly={!canPrice} form={form} error={errorOf('simple.wholesale_price')} />
                                        <Field label={t('products.field.sku')} hint={t('products.field.skuHint')} error={errorOf('simple.sku')}>
                                            {(ids) => <input {...inputProps(ids)} spellCheck={false} className={`${controlHeight.md} ${monoInputClass}`} {...register('simple.sku')} />}
                                        </Field>
                                        <Field label={t('products.field.barcode')} error={errorOf('simple.barcode')}>
                                            {(ids) => <input {...inputProps(ids)} spellCheck={false} className={`${controlHeight.md} ${monoInputClass}`} {...register('simple.barcode')} />}
                                        </Field>
                                        <Field label={t('products.field.weight')} error={errorOf('simple.weight_grams')}>
                                            {(ids) => <input {...inputProps(ids)} inputMode="numeric" className={`${controlHeight.md} ${inputClass} text-end tabular`} {...register('simple.weight_grams')} />}
                                        </Field>
                                        {stockMode === 'opening' && (
                                            <Field label={t('products.field.openingStock')} hint={t('products.field.openingStockHint')} error={errorOf('simple.opening_stock')}>
                                                {(ids) => <input {...inputProps(ids)} inputMode="numeric" className={`${controlHeight.md} ${inputClass} text-end tabular`} {...register('simple.opening_stock')} />}
                                            </Field>
                                        )}
                                        {stockMode === 'readonly' && (
                                            <div className="flex flex-col gap-1.5">
                                                <span className="t-admin-body-strong text-fg-primary">{t('products.field.stock')}</span>
                                                <p className="flex h-10 items-center gap-1 t-admin-num tabular text-fg-primary">
                                                    {simpleStock ? t('products.stock.summary', { available: simpleStock.available, onHand: simpleStock.on_hand, reserved: simpleStock.reserved }) : '—'}
                                                    <ArrowSquareOutIcon size={12} aria-hidden className="text-fg-tertiary" />
                                                </p>
                                                <p className="t-admin-caption text-fg-secondary">{t('products.stock.readOnly')}</p>
                                            </div>
                                        )}
                                    </div>
                                </ProductSection>
                            )}

                            {/* URL — SlugField 198:1086, outside a card as in Figma. */}
                            <Field label={t('products.field.slug')} hint={t('products.field.slugHint')} error={errorOf('slug')}>
                                {(ids) => (
                                    <span className="flex h-10 items-stretch overflow-hidden rounded-sm border border-line-default bg-surface focus-within:border-line-focus focus-within:ring-1 focus-within:ring-inset focus-within:ring-line-focus has-aria-invalid:border-line-danger">
                                        <span aria-hidden className="flex items-center border-e border-line-default bg-subtle px-3 t-admin-mono-sm text-fg-secondary">
                                            {URL_PREFIX}
                                        </span>
                                        <input {...inputProps(ids)} spellCheck={false} autoCapitalize="none" className="min-w-0 flex-1 bg-transparent px-3 t-admin-mono text-fg-primary outline-none" {...register('slug')} />
                                    </span>
                                )}
                            </Field>
                        </div>

                        {/* Rail (241:37941) */}
                        <aside className="flex min-w-0 flex-col gap-6">
                            <ProductSection title={t('products.section.status')} forceOpen={Boolean(errorOf('status'))}>
                                <div className="flex items-center gap-2">
                                    <span id={`${formId}-featured`} className="min-w-0 flex-1 t-admin-body text-fg-primary">
                                        {t('products.field.featured')}
                                    </span>
                                    <Toggle checked={featured} label={t('products.field.featured')} disabled={!editable || save.isPending} onChange={(next) => setValue('is_featured', next, { shouldDirty: true })} />
                                </div>
                                <Field label={t('products.field.publish')} error={errorOf('status')}>
                                    {(ids) => (
                                        <Select {...inputProps(ids)} {...register('status')}>
                                            {productStatuses.map((value) => (
                                                <option key={value} value={value}>
                                                    {value === 'active' && publishedSince && product?.status === 'active' ? t('products.status.activeSince', { date: publishedSince }) : t(statusOption[value])}
                                                </option>
                                            ))}
                                        </Select>
                                    )}
                                </Field>
                            </ProductSection>

                            <ProductSection
                                title={t('products.section.compliance')}
                                description={t('products.section.complianceHint')}
                                forceOpen={Boolean(errorOf('safety_note') ?? errorOf('safety_note_bn') ?? errorOf('warranty_months'))}
                            >
                                <Field label={t('products.field.safetyNote')} error={errorOf('safety_note')}>
                                    {(ids) => <input {...inputProps(ids)} className={`${controlHeight.md} ${inputClass}`} {...register('safety_note')} />}
                                </Field>
                                <Field label={t('products.field.safetyNoteBn')} error={errorOf('safety_note_bn')}>
                                    {(ids) => <input {...inputProps(ids)} lang="bn" className={`${controlHeight.md} ${inputClass}`} {...register('safety_note_bn')} />}
                                </Field>
                                <Field label={t('products.field.warranty')} hint={t('products.field.warrantyHint')} error={errorOf('warranty_months')}>
                                    {(ids) => <input {...inputProps(ids)} inputMode="numeric" className={`${controlHeight.md} ${inputClass} tabular`} {...register('warranty_months')} />}
                                </Field>
                            </ProductSection>

                            <RelationsEditor id={anchor('related')} productId={id} value={relations} onChange={setRelations} errors={placed.relations} disabled={!editable || save.isPending} />
                        </aside>
                    </div>

                    {/* Variants — VariantMatrixEditor 201:1201 (241:38029), full 1144 width below the columns. */}
                    {type === 'variable' &&
                        (attributes.isError ? (
                            <QueryError error={attributes.error} onRetry={() => void attributes.refetch()} />
                        ) : (
                            <VariantMatrixEditor
                                id={anchor('variants')}
                                aside={typeSelect}
                                attributes={variantAttributes}
                                value={matrix}
                                combos={combos}
                                onChange={setMatrix}
                                errors={placed.rows}
                                matrixErrors={placed.matrix}
                                canCost={canCost}
                                canPrice={canPrice}
                                stockMode={stockMode}
                                disabled={!editable || save.isPending}
                            />
                        ))}
                </fieldset>

                {/* Below 1024 the destructive action leaves the save bar and sits after the form (Figma 230:22079). */}
                {canArchive && (
                    <Button variant="danger" onClick={() => setConfirming(true)} className="w-full lg:hidden">
                        {t('products.archive')}
                    </Button>
                )}

                {/* FormActions 198:1085 (241:38030) — sticky save bar. Destructive action far left (≥ 1024), then the save status. */}
                <FormActions
                    start={
                        <>
                            {canArchive && (
                                <span className="hidden items-center gap-3 lg:flex">
                                    <Button variant="danger" onClick={() => setConfirming(true)}>
                                        {t('products.archive')}
                                    </Button>
                                    <span aria-hidden className="h-6 w-px bg-line-default" />
                                </span>
                            )}
                            <p className="flex min-w-0 items-center gap-2" aria-live="polite">
                                {dirtyCount > 0 && <span aria-hidden className="size-2.5 shrink-0 rounded-full bg-brand" />}
                                <span className="t-admin-body text-fg-primary">
                                    {dirtyCount === 0
                                        ? t('products.noChanges')
                                        : changeCount === 1
                                          ? t('products.unsavedOne')
                                          : dirtyCount === 1
                                            ? t('products.unsavedOneTab', { changes: changeCount })
                                            : t('products.unsaved', { changes: changeCount, count: dirtyCount })}
                                </span>
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
                    {editable && (
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={save.isPending}
                            className="max-lg:hidden"
                            onClick={() => {
                                addAnother.current = true;
                            }}
                        >
                            {t('products.saveAndAdd')}
                        </Button>
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

type MoneyFieldProps = {
    label: string;
    hint?: string;
    field: Extract<keyof VariantFields, 'price' | 'compare_at_price' | 'cost_price' | 'wholesale_price'>;
    readOnly?: boolean;
    required?: boolean;
    error?: string;
    form: ReturnType<typeof useForm<FormValues>>;
};

function MoneyField({ label, hint, field, readOnly, required, error, form }: MoneyFieldProps) {
    return (
        <Field label={label} hint={hint} error={error} required={required}>
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
