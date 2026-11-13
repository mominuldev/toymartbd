import { useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router';
import { TrashIcon } from '@phosphor-icons/react';
import { storePurchaseRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Field, inputClass } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { usePurchase, useSavePurchase, useStockLocations, useSuppliers, useVariantSearch, type Purchase, type PurchaseInput, type VariantOption } from './api';
import { LineProduct, TAKA, dhakaToday, formatMoney } from './parts';

type Line = Pick<VariantOption, 'image_url' | 'product_name' | 'product_name_bn' | 'variant_name' | 'variant_name_bn' | 'sku'> & {
    variant_id: number;
    quantity: string;
    unit_cost: string;
    discount: string;
    current_cost?: string;
};

type Header = { supplier_id: string; order_date: string; expected_date: string; location_id: string; supplier_invoice_no: string; note: string };

function fromPurchase(purchase?: Purchase): { header: Header; lines: Line[] } {
    return {
        header: {
            supplier_id: purchase ? String(purchase.supplier.id) : '',
            order_date: purchase?.order_date ?? dhakaToday(),
            expected_date: purchase?.expected_date ?? '',
            location_id: purchase ? String(purchase.location_id) : '',
            supplier_invoice_no: purchase?.supplier_invoice_no ?? '',
            note: purchase?.note ?? '',
        },
        lines: (purchase?.items ?? []).map((item) => ({
            variant_id: item.variant_id,
            image_url: item.image_url,
            product_name: item.product_name,
            product_name_bn: item.product_name_bn,
            variant_name: item.variant_name,
            variant_name_bn: item.variant_name_bn,
            sku: item.sku,
            quantity: String(item.quantity_ordered),
            unit_cost: item.unit_cost ?? '',
            discount: item.discount && item.discount !== '0.00' ? item.discount : '',
        })),
    };
}

/** "310.0000" → "310": the cost basis as an editable default, trimmed, never recomputed. */
function trimDecimal(value: string): string {
    return value.includes('.') ? value.replace(/\.?0+$/, '') : value;
}

/**
 * Create or edit a draft purchase order (06-admin-feature-checklist §2.7; no form drawn —
 * built from the Product edit form's FormSection/Field/AdminInput pieces and the DataTable
 * line style of 213:7971). Only quantities, unit costs and discounts are entered; line and
 * PO totals are computed by the server on save (rule 1) and shown on the PO. Client
 * validation is the schema generated from the FormRequest (rule 27). `?sku=` (the inventory
 * page's Create PO) starts the product search with that SKU.
 */
export function PurchaseFormPage() {
    const params = useParams();
    const id = params.purchaseId ? Number(params.purchaseId) : null;
    const purchase = usePurchase(id);

    if (id !== null && purchase.isError) return <QueryError error={purchase.error} onRetry={() => void purchase.refetch()} />;
    if (id !== null && !purchase.data) return <p aria-busy="true" className="sr-only" />;

    return <PurchaseForm key={purchase.data?.id ?? 'new'} id={id} purchase={purchase.data} />;
}

function PurchaseForm({ id, purchase }: { id: number | null; purchase?: Purchase }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const navigate = useNavigate();
    const [search] = useSearchParams();
    const save = useSavePurchase(id);
    const suppliers = useSuppliers({ status: 'active', search: '' });
    const locations = useStockLocations(can(me, 'inventory.view'));
    const initial = useMemo(() => fromPurchase(purchase), [purchase]);
    const [header, setHeader] = useState<Header>(initial.header);
    const [lines, setLines] = useState<Line[]>(initial.lines);
    const [term, setTerm] = useState(search.get('sku') ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const results = useVariantSearch(term.trim());
    const options = (results.data?.data ?? []).filter((o) => !lines.some((l) => l.variant_id === o.variant_id)).slice(0, 8);
    const supplierOptions = suppliers.data?.pages.flatMap((p) => p.data) ?? [];

    const setH = (key: keyof Header, value: string) => setHeader((h) => ({ ...h, [key]: value }));
    const setLine = (index: number, key: 'quantity' | 'unit_cost' | 'discount', value: string) => setLines((ls) => ls.map((l, i) => (i === index ? { ...l, [key]: value } : l)));

    function add(option: VariantOption) {
        setLines((ls) => [
            ...ls,
            {
                variant_id: option.variant_id,
                image_url: option.image_url,
                product_name: option.product_name,
                product_name_bn: option.product_name_bn,
                variant_name: option.variant_name,
                variant_name_bn: option.variant_name_bn,
                sku: option.sku,
                quantity: option.reorder_quantity > 0 ? String(option.reorder_quantity) : '1',
                unit_cost: option.unit_cost ? trimDecimal(option.unit_cost) : '',
                discount: '',
                current_cost: option.unit_cost,
            },
        ]);
        setTerm('');
    }

    function payload(): PurchaseInput {
        const orNull = (v: string) => (v.trim() === '' ? null : v.trim());
        return {
            supplier_id: Number(header.supplier_id),
            order_date: header.order_date,
            expected_date: orNull(header.expected_date),
            location_id: header.location_id === '' ? null : Number(header.location_id),
            supplier_invoice_no: orNull(header.supplier_invoice_no),
            note: orNull(header.note),
            lines: lines.map((l) => ({
                variant_id: l.variant_id,
                quantity: /^\d+$/.test(l.quantity.trim()) ? Number(l.quantity.trim()) : Number.NaN,
                unit_cost: l.unit_cost.trim().replace(/,/g, ''),
                discount: orNull(l.discount.replace(/,/g, '')),
            })),
        };
    }

    function submit() {
        const input = payload();
        const parsed = storePurchaseRequestSchema.safeParse(input);
        if (!parsed.success || lines.length === 0) {
            const next: Record<string, string> = {};
            if (lines.length === 0) next.lines = t('po.noLines');
            if (!parsed.success) for (const issue of parsed.error.issues) next[issue.path.join('.')] ??= issue.path[0] === 'supplier_id' ? t('validation.required') : issue.message;
            setErrors(next);
            return;
        }
        setErrors({});
        save.mutate(input, {
            onSuccess: (saved) => {
                notify(t(id === null ? 'po.created' : 'po.saved', { number: saved.number }));
                navigate(`/purchasing/${saved.id}`, { replace: true });
            },
            onError: (error) => {
                if (error instanceof ApiError && error.status === 422) {
                    setErrors(Object.fromEntries((error.problem.errors ?? []).map((e) => [e.field, e.message])));
                } else {
                    notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
                }
            },
        });
    }

    const title = id === null ? t('po.crumbNew') : t('po.crumbEdit', { number: purchase?.number ?? '' });
    const editable = can(me, 'purchasing.create');

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader breadcrumb={[{ label: t('catalog.crumb') }, { label: t('purchasing.crumb'), to: '/purchasing' }, { label: title }]} title={title} />

            <form
                noValidate
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
                className="flex flex-col gap-6"
            >
                <fieldset disabled={!editable || save.isPending} className="flex w-full min-w-0 max-w-4xl flex-col gap-3.5 rounded-md border border-line-default bg-surface p-5 shadow-e1">
                    <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                        <Field label={t('po.supplier')} error={errors.supplier_id} required>
                            {({ id: fid, describedBy, invalid }) => (
                                <Select id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={header.supplier_id} onChange={(e) => setH('supplier_id', e.target.value)}>
                                    <option value="">{t('po.supplierPick')}</option>
                                    {supplierOptions.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                        <Field label={t('po.invoiceNo')} error={errors.supplier_invoice_no} optional>
                            {({ id: fid, describedBy, invalid }) => (
                                <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="t-admin-mono" value={header.supplier_invoice_no} onChange={(e) => setH('supplier_invoice_no', e.target.value)} />
                            )}
                        </Field>
                        <Field label={t('po.orderDate')} error={errors.order_date} required>
                            {({ id: fid, describedBy, invalid }) => (
                                <Input id={fid} type="date" aria-describedby={describedBy} aria-invalid={invalid || undefined} className="tabular" value={header.order_date} onChange={(e) => setH('order_date', e.target.value)} />
                            )}
                        </Field>
                        <Field label={t('po.expectedDate')} error={errors.expected_date} optional>
                            {({ id: fid, describedBy, invalid }) => (
                                <Input id={fid} type="date" aria-describedby={describedBy} aria-invalid={invalid || undefined} className="tabular" value={header.expected_date} onChange={(e) => setH('expected_date', e.target.value)} />
                            )}
                        </Field>
                        {locations.data && locations.data.length > 1 && (
                            <Field label={t('po.location')} error={errors.location_id} optional>
                                {({ id: fid, describedBy, invalid }) => (
                                    <Select id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={header.location_id} onChange={(e) => setH('location_id', e.target.value)}>
                                        <option value="">—</option>
                                        {locations.data.map((l) => (
                                            <option key={l.id} value={l.id}>
                                                {locale === 'bn' ? (l.name_bn ?? l.name) : l.name}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                        )}
                    </div>
                    <Field label={t('po.note')} error={errors.note} optional>
                        {({ id: fid, describedBy, invalid }) => (
                            <textarea id={fid} rows={2} maxLength={1000} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} value={header.note} onChange={(e) => setH('note', e.target.value)} />
                        )}
                    </Field>

                    <div className="flex flex-col gap-2 border-t border-line-default pt-3.5">
                        <h2 className="t-admin-card-title text-fg-primary">{t('po.lines')}</h2>
                        <p className="t-admin-caption text-fg-tertiary">{t('po.linesHint')}</p>
                        {lines.map((line, index) => (
                            <div key={line.variant_id} className="grid items-start gap-3 border-b border-line-default py-2 md:grid-cols-[minmax(0,1fr)_6rem_8rem_8rem_auto]">
                                <div className="flex min-w-0 flex-col gap-1 pt-1">
                                    <LineProduct line={line} />
                                    {line.current_cost && (
                                        <p className="ms-11.5 t-admin-caption tabular text-fg-tertiary">{t('po.currentCost', { cost: `${TAKA}${formatMoney(line.current_cost)}` })}</p>
                                    )}
                                    {errors[`lines.${index}.variant_id`] && <p className="t-admin-caption text-fg-primary">{errors[`lines.${index}.variant_id`]}</p>}
                                </div>
                                <Field label={t('po.quantity')} error={errors[`lines.${index}.quantity`]}>
                                    {({ id: fid, describedBy, invalid }) => (
                                        <Input id={fid} size="sm" inputMode="numeric" aria-describedby={describedBy} aria-invalid={invalid || undefined} className="tabular" value={line.quantity} onChange={(e) => setLine(index, 'quantity', e.target.value)} />
                                    )}
                                </Field>
                                <Field label={t('po.unitCost')} error={errors[`lines.${index}.unit_cost`]}>
                                    {({ id: fid, describedBy, invalid }) => (
                                        <Input
                                            id={fid}
                                            size="sm"
                                            inputMode="decimal"
                                            prefix={<span className="t-admin-currency-num">{TAKA}</span>}
                                            aria-describedby={describedBy}
                                            aria-invalid={invalid || undefined}
                                            className="tabular"
                                            value={line.unit_cost}
                                            onChange={(e) => setLine(index, 'unit_cost', e.target.value)}
                                        />
                                    )}
                                </Field>
                                <Field label={t('po.discount')} error={errors[`lines.${index}.discount`]} optional>
                                    {({ id: fid, describedBy, invalid }) => (
                                        <Input
                                            id={fid}
                                            size="sm"
                                            inputMode="decimal"
                                            prefix={<span className="t-admin-currency-num">{TAKA}</span>}
                                            aria-describedby={describedBy}
                                            aria-invalid={invalid || undefined}
                                            className="tabular"
                                            value={line.discount}
                                            onChange={(e) => setLine(index, 'discount', e.target.value)}
                                        />
                                    )}
                                </Field>
                                <IconButton aria-label={t('po.removeLine')} className="mt-6" onClick={() => setLines((ls) => ls.filter((_, i) => i !== index))}>
                                    <TrashIcon size={16} aria-hidden />
                                </IconButton>
                            </div>
                        ))}
                        {errors.lines && <p role="alert" className="t-admin-caption text-fg-primary">{errors.lines}</p>}
                        <div className="flex flex-col gap-1.5 pt-1">
                            <SearchField label={t('po.variantSearch')} placeholder={t('po.variantSearch')} value={term} onChange={(e) => setTerm(e.target.value)} />
                            {term.trim().length >= 2 && (
                                <ul aria-label={t('po.variantSearch')} className="flex max-h-60 flex-col overflow-y-auto rounded-sm border border-line-default">
                                    {options.length === 0 && !results.isFetching ? (
                                        <li className="px-3 py-2 t-admin-caption text-fg-tertiary">{t('po.variantNone')}</li>
                                    ) : (
                                        options.map((option) => (
                                            <li key={option.variant_id} className="border-t border-line-default first:border-t-0">
                                                <button type="button" onClick={() => add(option)} className="w-full px-3 py-2 text-start hover:bg-subtle">
                                                    <LineProduct line={option} />
                                                </button>
                                            </li>
                                        ))
                                    )}
                                </ul>
                            )}
                        </div>
                    </div>
                </fieldset>

                <div className="sticky bottom-0 z-10 -mx-4 -mb-4 flex min-h-16 flex-wrap items-center justify-end gap-3 border-t border-line-default bg-surface px-4 py-3.5 shadow-e4 md:-mx-5 md:-mb-10 md:px-5 xl:-mx-6 xl:px-6">
                    <Link to={id === null ? '/purchasing?status=draft' : `/purchasing/${id}`} className={buttonClass('ghost')}>
                        {t('common.cancel')}
                    </Link>
                    {editable && (
                        <Button type="submit" disabled={save.isPending}>
                            {save.isPending ? t('common.saving') : t('po.saveDraft')}
                        </Button>
                    )}
                </div>
            </form>
        </section>
    );
}
