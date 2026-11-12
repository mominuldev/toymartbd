import { useEffect, useMemo, useState } from 'react';
import { DownloadSimpleIcon, WarningCircleIcon, WarningIcon } from '@phosphor-icons/react';
import { receivePurchaseRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { apportionMethods, thirdPartyPaymentMethods, useReceiptQuote, useReceive, useStockLocations, type ApportionMethod, type Purchase, type ReceiptInput, type ThirdPartyPaymentMethod } from './api';
import { LineProduct, TAKA, TotalRow, formatMoney, formatUnits, lineName } from './parts';
import { PaymentDialog } from './PaymentDialog';

type Costs = { freight: string; customs_duty: string; other_cost: string };

/** Debounce a value so typing a quantity sends one quote, not one per keystroke. */
function useDebounced<T>(value: T, ms: number): T {
    const [current, setCurrent] = useState(value);
    useEffect(() => {
        const timer = setTimeout(() => setCurrent(value), ms);
        return () => clearTimeout(timer);
    }, [value, ms]);
    return current;
}

/**
 * The receive-stock card (Figma "16 Purchase orders" → "Receive PO-2026-0044" 213:8325, Theme B
 * colours): one row per outstanding line — thumbnail, name, "unit → landed", "ordered N" and
 * a 64-wide units input — then Goods / landed costs / Total payable, the short-delivery
 * warning and the Receive / Record supplier payment buttons.
 *
 * Every taka figure is the server's: the panel posts its inputs to `…/receipts/quote` and
 * shows what comes back (rule 1), and the receipt runs the same calculator. Landed-cost
 * inputs (freight, customs/duty, other, split method) are additions to the drawn card — the
 * frame shows freight only; they reuse AdminInput 184:338 and AdminSelect 184:369.
 */
export function ReceivePanel({ purchase }: { purchase: Purchase }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const receive = useReceive(purchase.id);
    const locations = useStockLocations(can(me, 'inventory.view'));
    const outstanding = useMemo(() => purchase.items.filter((line) => line.quantity_outstanding > 0), [purchase.items]);

    const [quantities, setQuantities] = useState<Record<number, string>>(() => Object.fromEntries(outstanding.map((l) => [l.id, String(l.quantity_outstanding)])));
    const [costs, setCosts] = useState<Costs>({ freight: '', customs_duty: '', other_cost: '' });
    const [method, setMethod] = useState<ApportionMethod>('value');
    const [toSupplier, setToSupplier] = useState(true);
    // Third party (forwarder / C&F agent) details, sent only when the supplier did not charge the costs.
    const [vendor, setVendor] = useState('');
    const [vendorRef, setVendorRef] = useState('');
    const [paidVia, setPaidVia] = useState<ThirdPartyPaymentMethod | ''>('');
    const [location, setLocation] = useState(String(purchase.location_id));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());
    const [paying, setPaying] = useState(false);

    useEffect(() => {
        setQuantities(Object.fromEntries(outstanding.map((l) => [l.id, String(l.quantity_outstanding)])));
        setCosts({ freight: '', customs_duty: '', other_cost: '' });
        setErrors({});
        setIdempotencyKey(crypto.randomUUID());
    }, [purchase.id, outstanding]);

    const input: ReceiptInput | null = useMemo(() => {
        const lines = outstanding.map((l) => {
            const text = (quantities[l.id] ?? '').trim();
            return { purchase_item_id: l.id, quantity: /^\d+$/.test(text) ? Number(text) : Number.NaN };
        });
        const money = (value: string) => (value.trim() === '' ? null : value.trim());
        const candidate: ReceiptInput = {
            lines,
            freight: money(costs.freight),
            customs_duty: money(costs.customs_duty),
            other_cost: money(costs.other_cost),
            apportion_method: method,
            costs_paid_to_supplier: toSupplier,
            location_id: Number(location) === purchase.location_id ? null : Number(location),
            ...(toSupplier ? {} : { costs_vendor: money(vendor), costs_reference: money(vendorRef), costs_payment_method: paidVia === '' ? null : paidVia }),
        };
        return receivePurchaseRequestSchema.safeParse(candidate).success && lines.some((l) => l.quantity > 0) ? candidate : null;
    }, [outstanding, quantities, costs, method, toSupplier, location, purchase.location_id, vendor, vendorRef, paidVia]);

    const quoteInput = useDebounced(input, 300);
    const quote = useReceiptQuote(purchase.id, quoteInput);
    const quoted = quote.data && quoteInput !== null ? quote.data : null;
    const byLine = new Map((quoted?.lines ?? []).map((l) => [l.purchase_item_id, l]));
    const quoteError = quote.error instanceof ApiError && quote.error.status === 422 ? quote.error : null;

    // Units, not money: how many stay open on the PO after this delivery.
    const units = outstanding.reduce((sum, l) => sum + (Number(quantities[l.id]) || 0), 0);
    const short = outstanding.reduce((sum, l) => sum + Math.max(0, l.quantity_outstanding - (Number(quantities[l.id]) || 0)), 0);
    const locationName = (() => {
        const here = locations.data?.find((l) => String(l.id) === location);
        return here ? (locale === 'bn' ? (here.name_bn ?? here.name) : here.name) : '';
    })();

    function submit() {
        const parsed = receivePurchaseRequestSchema.safeParse(input);
        if (!input || !parsed.success) {
            const next: Record<string, string> = {};
            outstanding.forEach((l, index) => {
                if (!/^\d+$/.test((quantities[l.id] ?? '').trim())) next[`lines.${index}.quantity`] = t('validation.invalid');
            });
            for (const issue of parsed.success ? [] : parsed.error.issues) next[issue.path.join('.')] ??= issue.message;
            if (Object.keys(next).length === 0) next.lines = t('validation.required');
            setErrors(next);
            return;
        }
        setErrors({});
        receive.mutate(
            { input, idempotencyKey },
            {
                onSuccess: () => {
                    notify(t('receive.done', { units: formatUnits(units), number: purchase.number }));
                    setIdempotencyKey(crypto.randomUUID());
                },
                onError: (error) => {
                    if (error instanceof ApiError && error.status === 422) {
                        setErrors(Object.fromEntries((error.problem.errors ?? []).map((e) => [e.field, e.message])));
                        setIdempotencyKey(crypto.randomUUID());
                    } else {
                        notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
                    }
                },
            },
        );
    }

    const lineError = (index: number) => errors[`lines.${index}.quantity`] ?? quoteError?.fieldError(`lines.${index}.quantity`);
    const costField = (key: keyof Costs, label: MessageKey) => (
        <Field label={t(label)} error={errors[key] ?? quoteError?.fieldError(key)} optional>
            {({ id, describedBy, invalid }) => (
                <Input
                    id={id}
                    aria-describedby={describedBy}
                    aria-invalid={invalid || undefined}
                    size="sm"
                    inputMode="decimal"
                    prefix={<span className="t-admin-currency-num">{TAKA}</span>}
                    className="tabular"
                    value={costs[key]}
                    onChange={(e) => setCosts((c) => ({ ...c, [key]: e.target.value }))}
                />
            )}
        </Field>
    );

    return (
        <section aria-labelledby={`receive-${purchase.id}`} className="flex min-w-0 flex-col gap-3.5 rounded-md border border-line-default bg-surface p-5 shadow-e1">
            <header className="flex flex-col gap-0.5">
                <h2 id={`receive-${purchase.id}`} className="t-admin-card-title text-fg-primary">
                    {t('receive.title', { number: purchase.number })}
                </h2>
                <p className="t-admin-caption text-fg-tertiary">{t('receive.subtitle', { supplier: purchase.supplier.name, location: locationName || '—' })}</p>
            </header>

            <fieldset disabled={receive.isPending} className="flex min-w-0 flex-col">
                <legend className="sr-only">{t('po.lines')}</legend>
                {outstanding.map((line, index) => {
                    const q = byLine.get(line.id);
                    const error = lineError(index);
                    return (
                        <div key={line.id} className="flex flex-col gap-1 border-b border-line-default py-2">
                            <div className="flex items-center gap-2.5">
                                <div className="min-w-0 flex-1">
                                    <LineProduct line={line} mono={false} />
                                    {q && (
                                        <p className="ms-11.5 t-admin-caption tabular text-fg-tertiary">
                                            {t('receive.lineCost', { unit: `${TAKA}${formatMoney(q.unit_cost)}`, landed: `${TAKA}${formatMoney(q.landed_unit_cost)}` })}
                                        </p>
                                    )}
                                </div>
                                <span className="t-admin-caption whitespace-nowrap text-fg-secondary">{t('receive.ordered', { count: formatUnits(line.quantity_ordered) })}</span>
                                <Input
                                    size="sm"
                                    aria-label={t('receive.qtyLabel', { name: lineName(line, locale) })}
                                    aria-invalid={error ? true : undefined}
                                    inputMode="numeric"
                                    className="w-16 tabular"
                                    value={quantities[line.id] ?? ''}
                                    onChange={(e) => setQuantities((qs) => ({ ...qs, [line.id]: e.target.value }))}
                                />
                            </div>
                            {error && (
                                <p role="alert" className="flex items-start gap-1 t-admin-caption text-fg-primary">
                                    <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                                    {error}
                                </p>
                            )}
                            {q && (
                                <p className="t-admin-caption tabular text-fg-tertiary">
                                    {t('receive.avg', { before: `${TAKA}${formatMoney(q.avg_cost_before)}`, after: `${TAKA}${formatMoney(q.avg_cost_after)}` })}
                                </p>
                            )}
                        </div>
                    );
                })}

                <div className="grid gap-3 pt-3 sm:grid-cols-3">
                    {costField('freight', 'receive.freight')}
                    {costField('customs_duty', 'receive.customs')}
                    {costField('other_cost', 'receive.other')}
                </div>
                <div className={`grid gap-3 pt-3 ${locations.data && locations.data.length > 1 ? 'sm:grid-cols-2' : ''}`}>
                    <Field label={t('receive.method')} error={errors.apportion_method ?? quoteError?.fieldError('apportion_method')}>
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} size="sm" aria-describedby={describedBy} aria-invalid={invalid || undefined} value={method} onChange={(e) => setMethod(e.target.value as ApportionMethod)}>
                                {apportionMethods.map((m) => (
                                    <option key={m} value={m}>
                                        {t(`receive.method.${m}` as MessageKey)}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                    {locations.data && locations.data.length > 1 && (
                        <Field label={t('receive.location')} error={errors.location_id}>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} size="sm" aria-describedby={describedBy} aria-invalid={invalid || undefined} value={location} onChange={(e) => setLocation(e.target.value)}>
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
                <label className="flex min-h-touch-min items-center gap-3 pt-1 t-admin-body text-fg-primary">
                    <input type="checkbox" className="size-4 accent-brand" checked={toSupplier} onChange={(e) => setToSupplier(e.target.checked)} />
                    {t('receive.costsToSupplier')}
                </label>
                {!toSupplier && (
                    <div role="group" aria-label={t('receive.thirdParty')} className="grid gap-3 pt-1 sm:grid-cols-3">
                        <Field label={t('receive.costsVendor')} hint={t('receive.costsVendorHint')} error={errors.costs_vendor} optional>
                            {({ id, describedBy, invalid }) => <Input id={id} size="sm" aria-describedby={describedBy} aria-invalid={invalid || undefined} value={vendor} onChange={(e) => setVendor(e.target.value)} />}
                        </Field>
                        <Field label={t('receive.costsReference')} error={errors.costs_reference} optional>
                            {({ id, describedBy, invalid }) => <Input id={id} size="sm" aria-describedby={describedBy} aria-invalid={invalid || undefined} value={vendorRef} onChange={(e) => setVendorRef(e.target.value)} />}
                        </Field>
                        <Field label={t('receive.costsPaidVia')} error={errors.costs_payment_method} optional>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} size="sm" aria-describedby={describedBy} aria-invalid={invalid || undefined} value={paidVia} onChange={(e) => setPaidVia(e.target.value as ThirdPartyPaymentMethod | '')}>
                                    <option value="">{t('receive.costsUnpaid')}</option>
                                    {thirdPartyPaymentMethods.map((m) => (
                                        <option key={m} value={m}>
                                            {t(`payment.method.${m}` as MessageKey)}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                        <p className="t-admin-caption text-fg-tertiary sm:col-span-3">{t('receive.costsExpenseHint')}</p>
                    </div>
                )}
            </fieldset>

            <div aria-live="polite" className="flex flex-col">
                {quoted ? (
                    <>
                        <TotalRow label={t('receive.goods')} amount={quoted.goods_value} />
                        {quoted.freight !== '0.00' && <TotalRow label={t('po.freight')} amount={quoted.freight} />}
                        {quoted.customs_duty !== '0.00' && <TotalRow label={t('po.customs')} amount={quoted.customs_duty} />}
                        {quoted.other_cost !== '0.00' && <TotalRow label={t('po.other')} amount={quoted.other_cost} />}
                        <TotalRow strong label={t('receive.payable')} amount={quoted.payable_amount} />
                    </>
                ) : (
                    input !== null && quote.isFetching && <p className="t-admin-caption text-fg-tertiary">{t('receive.quoting')}</p>
                )}
            </div>

            {short > 0 && units > 0 && (
                <p className="flex items-center gap-2 border-l-3 border-status-low-stock bg-subtle px-3 py-2.5 t-admin-caption-strong text-fg-primary">
                    <WarningIcon size={16} aria-hidden className="shrink-0 text-feedback-warning" />
                    {t('receive.short', { count: formatUnits(short), units: formatUnits(units) })}
                </p>
            )}
            {errors.lines && (
                <p role="alert" className="flex items-start gap-1 t-admin-caption text-fg-primary">
                    <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                    {errors.lines}
                </p>
            )}

            <div className="flex flex-wrap gap-2">
                <Button onClick={submit} disabled={receive.isPending || input === null}>
                    <DownloadSimpleIcon size={16} aria-hidden />
                    {t('receive.submit', { units: formatUnits(units) })}
                </Button>
                {can(me, 'finance.record_supplier_payments') && (
                    <Button variant="outline" onClick={() => setPaying(true)}>
                        {t('po.recordPayment')}
                    </Button>
                )}
            </div>

            <PaymentDialog open={paying} onClose={() => setPaying(false)} supplier={purchase.supplier} purchaseId={purchase.id} purchases={[{ id: purchase.id, number: purchase.number }]} />
        </section>
    );
}
