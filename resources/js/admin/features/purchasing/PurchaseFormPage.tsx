import { useEffect, useId, useMemo, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router';
import { NotePencilIcon, PaperPlaneTiltIcon, TrashIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { storePurchaseRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { useWorkingBranch } from '@/components/shell/workingBranch';
import { Button } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { dataTable } from '@/components/ui/DataTable';
import { Field, inputClass } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { usePurchase, usePurchaseQuote, useSubmitPurchase, useSuppliers, useVariantSearch, type Purchase, type PurchaseInput, type PurchaseQuoteInput, type VariantOption } from './api';
import { LineProduct, Money, Th, dhakaToday, formatUnits, lineName, supplierTerms, takaText } from './parts';

type Line = Pick<VariantOption, 'image_url' | 'product_name' | 'product_name_bn' | 'variant_name' | 'variant_name_bn' | 'sku'> & {
    variant_id: number;
    quantity: string;
    unit_cost: string;
    /** Kept as the draft had it; the premium form does not edit line discounts. */
    discount: string;
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
            unit_cost: item.unit_cost ? trimDecimal(item.unit_cost) : '',
            discount: item.discount && item.discount !== '0.00' ? item.discount : '',
        })),
    };
}

/** "310.0000" → "310.00": an editable default with the poisha kept, never recomputed. */
function trimDecimal(value: string): string {
    if (!value.includes('.')) return value;
    const [whole, fraction = ''] = value.split('.');
    const kept = fraction.replace(/0+$/, '');
    return `${whole}.${kept.length < 2 ? (kept + '00').slice(0, 2) : kept}`;
}

function useDebounced<T>(value: T, ms: number): T {
    const [current, setCurrent] = useState(value);
    useEffect(() => {
        const timer = setTimeout(() => setCurrent(value), ms);
        return () => clearTimeout(timer);
    }, [value, ms]);
    return current;
}

/**
 * New / edit purchase order (Figma "Purchasing · Premium" New PO 417:71096): "1 · Supplier &
 * delivery" (supplier with terms and what you owe them, deliver to — several branches only,
 * defaulting to the working branch — order date, expected by, supplier invoice no.), "2 ·
 * Products" (search to add, quantity and unit cost per line, the server's line totals and "last
 * bought at"), an optional note, and a Summary rail with the server's order total, Place order
 * and Save as draft. Totals come from `POST /purchases/quote` (rule 1); client validation is
 * the schema generated from the FormRequest (rule 27). `?sku=` (Inventory's Create PO) starts
 * the product search with that SKU.
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
    const formId = useId();
    const submitPurchase = useSubmitPurchase(id);
    const suppliers = useSuppliers({ status: 'active', search: '' });
    const branch = useWorkingBranch();
    const multi = branch.multi && branch.locations.length > 1;
    const initial = useMemo(() => fromPurchase(purchase), [purchase]);
    const [header, setHeader] = useState<Header>(initial.header);
    const [lines, setLines] = useState<Line[]>(initial.lines);
    const [term, setTerm] = useState(search.get('sku') ?? '');
    const [noteOpen, setNoteOpen] = useState(initial.header.note !== '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [action, setAction] = useState<'place' | 'draft' | null>(null);

    // A new purchase is delivered to the sidebar's working branch by default (several branches only).
    useEffect(() => {
        if (purchase || !multi || branch.current === null) return;
        setHeader((h) => (h.location_id === '' ? { ...h, location_id: String(branch.current?.id) } : h));
    }, [purchase, multi, branch.current]);

    const results = useVariantSearch(term.trim());
    const options = (results.data?.data ?? []).filter((o) => !lines.some((l) => l.variant_id === o.variant_id)).slice(0, 8);
    const supplierOptions = suppliers.data?.pages.flatMap((p) => p.data) ?? [];
    const listed = supplierOptions.find((s) => String(s.id) === header.supplier_id) ?? null;
    const chosen: { name: string; payment_terms_days: number } | null = listed ?? (purchase && String(purchase.supplier.id) === header.supplier_id ? purchase.supplier : null);
    const chosenBalance = listed?.balance ?? null;

    const setH = (key: keyof Header, value: string) => setHeader((h) => ({ ...h, [key]: value }));
    const setLine = (index: number, key: 'quantity' | 'unit_cost', value: string) => setLines((ls) => ls.map((l, i) => (i === index ? { ...l, [key]: value } : l)));

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
            },
        ]);
        setTerm('');
    }

    const orNull = (v: string) => (v.trim() === '' ? null : v.trim());
    const linePayload = (l: Line) => ({
        variant_id: l.variant_id,
        quantity: /^\d+$/.test(l.quantity.trim()) ? Number(l.quantity.trim()) : Number.NaN,
        unit_cost: l.unit_cost.trim().replace(/,/g, ''),
        discount: orNull(l.discount.replace(/,/g, '')),
    });

    function payload(): PurchaseInput {
        return {
            supplier_id: Number(header.supplier_id),
            order_date: header.order_date,
            expected_date: orNull(header.expected_date),
            location_id: header.location_id === '' ? null : Number(header.location_id),
            supplier_invoice_no: orNull(header.supplier_invoice_no),
            note: orNull(header.note),
            lines: lines.map(linePayload),
        };
    }

    // The preview: only when every line is complete, so the total is never a partial sum.
    const quoteCandidate: PurchaseQuoteInput | null = useMemo(() => {
        if (lines.length === 0) return null;
        const body = lines.map(linePayload);
        const valid = body.every((l) => Number.isInteger(l.quantity) && l.quantity > 0 && /^\d{1,10}(\.\d{1,4})?$/.test(l.unit_cost));
        return valid ? { supplier_id: header.supplier_id === '' ? null : Number(header.supplier_id), lines: body } : null;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [lines, header.supplier_id]);
    const quoteInput = useDebounced(quoteCandidate, 300);
    const quote = usePurchaseQuote(quoteInput);
    const quoted = quote.data && quoteInput !== null && quoteCandidate !== null ? quote.data : null;
    const byVariant = new Map((quoted?.lines ?? []).map((l) => [l.variant_id, l]));
    // Units, not money: the count in the headings.
    const units = lines.reduce((sum, l) => sum + (/^\d+$/.test(l.quantity.trim()) ? Number(l.quantity) : 0), 0);

    function submit(place: boolean) {
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
        setAction(place ? 'place' : 'draft');
        submitPurchase.mutate(
            { input, place },
            {
                onSuccess: ({ purchase: saved, placeError }) => {
                    if (placeError) {
                        const reason = placeError instanceof ApiError ? (placeError.problem.detail ?? placeError.problem.title) : t('error.title');
                        notify(t('poForm.savedNotPlaced', { number: saved.number, reason }), 'danger');
                    } else {
                        notify(t(place ? 'po.placed' : id === null ? 'po.created' : 'po.saved', { number: saved.number }));
                    }
                    navigate(`/purchasing/${saved.id}`, { replace: true });
                },
                onError: (error) => {
                    if (error instanceof ApiError && error.status === 422) {
                        setErrors(Object.fromEntries((error.problem.errors ?? []).map((e) => [e.field, e.message])));
                    } else {
                        notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
                    }
                },
                onSettled: () => setAction(null),
            },
        );
    }

    const title = id === null ? t('po.crumbNew') : t('po.crumbEdit', { number: purchase?.number ?? '' });
    const editable = can(me, 'purchasing.create');
    const pending = submitPurchase.isPending;
    const locationLabel = (lid: string) => {
        const here = branch.locations.find((l) => String(l.id) === lid);
        return here ? (locale === 'bn' ? (here.name_bn ?? here.name) : here.name) : '—';
    };
    const lineError = (index: number) => errors[`lines.${index}.quantity`] ?? errors[`lines.${index}.unit_cost`] ?? errors[`lines.${index}.variant_id`] ?? errors[`lines.${index}.discount`];

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('catalog.crumb') }, { label: t('purchasing.crumb'), to: '/purchasing' }, { label: title }]}
                title={title}
                subtitle={id === null ? t('poForm.subtitle') : t('poForm.editSubtitle')}
            />

            <form
                id={formId}
                noValidate
                // Enter in a field never places an order: the two rail buttons are the only submits.
                onSubmit={(e) => e.preventDefault()}
                className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22.5rem]"
            >
                <fieldset disabled={!editable || pending} className="flex min-w-0 flex-col gap-6">
                    <section aria-labelledby={`${formId}-s1`} className="flex flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1">
                        <h2 id={`${formId}-s1`} className="t-admin-card-title text-fg-primary">
                            {t('poForm.s1')}
                        </h2>
                        <div className={`grid gap-x-4 gap-y-4 ${multi ? 'md:grid-cols-2' : ''}`}>
                            <Field
                                label={t('po.supplier')}
                                error={errors.supplier_id}
                                required
                                hint={
                                    chosen
                                        ? chosenBalance
                                            ? t('poForm.supplierCaption', { terms: supplierTerms(chosen.payment_terms_days, t), amount: takaText(chosenBalance.balance) })
                                            : supplierTerms(chosen.payment_terms_days, t)
                                        : undefined
                                }
                            >
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
                            {multi && (
                                <Field label={t('po.location')} error={errors.location_id} hint={branch.current && header.location_id === String(branch.current.id) ? t('poForm.deliverHint') : undefined}>
                                    {({ id: fid, describedBy, invalid }) => (
                                        <Select id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={header.location_id} onChange={(e) => setH('location_id', e.target.value)}>
                                            {branch.locations.map((l) => (
                                                <option key={l.id} value={l.id}>
                                                    {locale === 'bn' ? (l.name_bn ?? l.name) : l.name}
                                                </option>
                                            ))}
                                        </Select>
                                    )}
                                </Field>
                            )}
                        </div>
                        <div className="grid gap-x-4 gap-y-4 md:grid-cols-3">
                            <Field label={t('po.orderDate')} error={errors.order_date} required>
                                {({ id: fid, describedBy, invalid }) => (
                                    <Input id={fid} type="date" aria-describedby={describedBy} aria-invalid={invalid || undefined} className="tabular" value={header.order_date} onChange={(e) => setH('order_date', e.target.value)} />
                                )}
                            </Field>
                            <Field label={t('poForm.expectedBy')} hint={t('poForm.expectedHint')} error={errors.expected_date}>
                                {({ id: fid, describedBy, invalid }) => (
                                    <Input id={fid} type="date" aria-describedby={describedBy} aria-invalid={invalid || undefined} className="tabular" value={header.expected_date} onChange={(e) => setH('expected_date', e.target.value)} />
                                )}
                            </Field>
                            <Field label={t('po.invoiceNo')} hint={t('poForm.optional')} error={errors.supplier_invoice_no}>
                                {({ id: fid, describedBy, invalid }) => (
                                    <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} autoComplete="off" value={header.supplier_invoice_no} onChange={(e) => setH('supplier_invoice_no', e.target.value)} />
                                )}
                            </Field>
                        </div>
                    </section>

                    <section aria-labelledby={`${formId}-s2`} className={dataTable.card.replace('overflow-hidden', 'overflow-visible')}>
                        <div className="flex flex-col gap-3 px-5 pt-5 pb-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 id={`${formId}-s2`} className="t-admin-card-title text-fg-primary">
                                    {t('poForm.s2')}
                                </h2>
                                <p className="t-admin-caption tabular text-fg-secondary">{t('poForm.count', { products: formatUnits(lines.length), units: formatUnits(units) })}</p>
                            </div>
                            <div className="relative">
                                <SearchField label={t('poForm.search')} placeholder={t('poForm.search')} value={term} onChange={(e) => setTerm(e.target.value)} autoComplete="off" />
                                {term.trim().length >= 2 && (
                                    <ul aria-label={t('poForm.search')} className="absolute inset-x-0 top-full z-20 mt-1 flex max-h-72 flex-col overflow-y-auto rounded-sm border border-line-default bg-surface-raised shadow-e3">
                                        {options.length === 0 && !results.isFetching ? (
                                            <li className="px-3 py-2 t-admin-caption text-fg-secondary">{t('po.variantNone')}</li>
                                        ) : (
                                            options.map((option) => (
                                                <li key={option.variant_id} className="border-t border-line-default first:border-t-0">
                                                    <button type="button" onClick={() => add(option)} className="w-full px-3 py-2 text-start hover:bg-subtle focus-visible:bg-subtle">
                                                        <LineProduct line={option} />
                                                    </button>
                                                </li>
                                            ))
                                        )}
                                    </ul>
                                )}
                            </div>
                        </div>

                        {lines.length === 0 ? (
                            <p className="border-t border-line-default px-5 py-6 t-admin-body text-fg-secondary">{t('poForm.empty')}</p>
                        ) : (
                            <table className={`${dataTable.table} md:table-fixed max-md:block`}>
                                <thead className={`hidden md:table-header-group ${dataTable.head}`}>
                                    <tr className="border-b border-line-strong">
                                        <Th className="ps-5">{t('po.col.product')}</Th>
                                        <Th className="w-28">{t('poForm.col.quantity')}</Th>
                                        <Th className="w-36">{t('poForm.col.unitCost')}</Th>
                                        <Th end className="w-34">
                                            {t('poForm.col.lineTotal')}
                                        </Th>
                                        <th scope="col" className={`${dataTable.th} w-14 pe-5`}>
                                            <span className="sr-only">{t('po.removeLine')}</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="max-md:block">
                                    {lines.map((line, index) => {
                                        const q = byVariant.get(line.variant_id);
                                        const name = lineName(line, locale);
                                        const error = lineError(index);
                                        const hint = q?.last_unit_cost ? t('poForm.lastBought', { amount: takaText(q.last_unit_cost) }) : quoted?.supplier_known && q ? t('poForm.newToSupplier') : null;
                                        return (
                                            <tr key={line.variant_id} className="flex flex-wrap items-center gap-3 border-t border-line-default px-5 py-3 md:table-row md:p-0">
                                                <td className="min-w-0 basis-full overflow-hidden align-middle md:basis-auto md:py-3 md:ps-5 md:pe-3">
                                                    <div className="flex min-w-0 items-center gap-2.5">
                                                        <LineProduct line={line} mono={false} />
                                                    </div>
                                                    <p className="ms-11.5 truncate t-admin-caption text-fg-secondary">
                                                        <span className="t-admin-mono-sm">{line.sku}</span>
                                                        {hint && <span className="tabular"> · {hint}</span>}
                                                    </p>
                                                    {error && (
                                                        <p role="alert" className="ms-11.5 flex items-start gap-1 t-admin-caption text-fg-primary">
                                                            <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                                                            {error}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="w-24 align-middle md:px-3 md:py-3">
                                                    <Input
                                                        aria-label={t('poForm.qtyLabel', { name })}
                                                        aria-invalid={errors[`lines.${index}.quantity`] ? true : undefined}
                                                        inputMode="numeric"
                                                        className="tabular"
                                                        value={line.quantity}
                                                        onChange={(e) => setLine(index, 'quantity', e.target.value)}
                                                    />
                                                </td>
                                                <td className="w-32 align-middle md:px-3 md:py-3">
                                                    <Input
                                                        aria-label={t('poForm.costLabel', { name })}
                                                        aria-invalid={errors[`lines.${index}.unit_cost`] ? true : undefined}
                                                        inputMode="decimal"
                                                        className="tabular"
                                                        value={line.unit_cost}
                                                        onChange={(e) => setLine(index, 'unit_cost', e.target.value)}
                                                    />
                                                </td>
                                                <td className="ms-auto align-middle t-admin-body-strong tabular whitespace-nowrap text-fg-primary md:px-3 md:py-3 md:text-end">
                                                    {q ? <Money amount={q.line_total} /> : <span className="text-fg-tertiary">—</span>}
                                                </td>
                                                <td className="align-middle md:py-3 md:pe-5">
                                                    <IconButton aria-label={`${t('po.removeLine')}: ${name}`} onClick={() => setLines((ls) => ls.filter((_, i) => i !== index))}>
                                                        <TrashIcon size={16} aria-hidden />
                                                    </IconButton>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        )}
                        {errors.lines && (
                            <p role="alert" className="flex items-start gap-1 px-5 pb-2 t-admin-caption text-fg-primary">
                                <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                                {errors.lines}
                            </p>
                        )}

                        <div className="border-t border-line-default px-5 py-4">
                            {noteOpen ? (
                                <Field label={t('poForm.note')} error={errors.note}>
                                    {({ id: fid, describedBy, invalid }) => (
                                        <textarea
                                            id={fid}
                                            rows={2}
                                            maxLength={1000}
                                            aria-describedby={describedBy}
                                            aria-invalid={invalid || undefined}
                                            className={`py-2 ${inputClass}`}
                                            value={header.note}
                                            onChange={(e) => setH('note', e.target.value)}
                                        />
                                    )}
                                </Field>
                            ) : (
                                <Button variant="ghost" size="sm" onClick={() => setNoteOpen(true)}>
                                    <NotePencilIcon size={16} aria-hidden />
                                    {t('poForm.addNote')}
                                </Button>
                            )}
                        </div>
                    </section>
                </fieldset>

                <aside aria-labelledby={`${formId}-sum`} className="flex flex-col gap-3 rounded-md border border-line-default bg-surface p-5 shadow-e1 xl:sticky xl:top-20">
                    <h2 id={`${formId}-sum`} className="t-admin-card-title text-fg-primary">
                        {t('poForm.summary')}
                    </h2>
                    <dl className="flex flex-col gap-2.5">
                        <div className="flex items-center justify-between gap-3">
                            <dt className="t-admin-body text-fg-secondary">{t('po.supplier')}</dt>
                            <dd className="truncate t-admin-body text-fg-primary">{chosen?.name ?? '—'}</dd>
                        </div>
                        {multi && (
                            <div className="flex items-center justify-between gap-3">
                                <dt className="t-admin-body text-fg-secondary">{t('po.location')}</dt>
                                <dd className="t-admin-body text-fg-primary">{locationLabel(header.location_id)}</dd>
                            </div>
                        )}
                        <div className="flex items-center justify-between gap-3">
                            <dt className="t-admin-body text-fg-secondary">{t('poForm.summaryProducts')}</dt>
                            <dd className="t-admin-body tabular text-fg-primary">{t('poForm.summaryUnits', { products: formatUnits(lines.length), units: formatUnits(units) })}</dd>
                        </div>
                    </dl>
                    <div className="flex items-center justify-between gap-3 border-t border-line-default pt-3" aria-live="polite">
                        <span className="t-admin-body-strong text-fg-primary">{t('po.orderTotal')}</span>
                        <span className="t-admin-body-strong tabular text-fg-primary">{quoted ? <Money amount={quoted.total} /> : '—'}</span>
                    </div>
                    <p className="t-admin-caption text-fg-secondary">{t('poForm.totalHint')}</p>
                    {editable && (
                        <>
                            <Button className="w-full" disabled={pending} onClick={() => submit(true)}>
                                <PaperPlaneTiltIcon size={16} aria-hidden />
                                {action === 'place' ? t('poForm.placing') : t('po.place')}
                            </Button>
                            <Button variant="outline" className="w-full" disabled={pending} onClick={() => submit(false)}>
                                {action === 'draft' ? t('common.saving') : t('po.saveDraft')}
                            </Button>
                        </>
                    )}
                    <p className="t-admin-caption text-fg-secondary">{t('poForm.railHint')}</p>
                </aside>
            </form>
        </section>
    );
}
