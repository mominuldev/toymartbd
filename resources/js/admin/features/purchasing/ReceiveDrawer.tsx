import { useEffect, useId, useMemo, useState, type ReactNode } from 'react';
import { DownloadSimpleIcon, WarningCircleIcon, XIcon } from '@phosphor-icons/react';
import { receivePurchaseRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { useWorkingBranch } from '@/components/shell/workingBranch';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { thirdPartyPaymentMethods, useReceiptQuote, useReceive, type Purchase, type PurchaseLine, type ReceiptInput, type ThirdPartyPaymentMethod } from './api';
import { Money, formatUnits, lineName, takaText, useDialog } from './parts';

type Costs = { freight: string; customs_duty: string; other_cost: string };
type Share = 'value' | 'quantity';

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
 * Receive stock (Figma "Receive stock drawer" 416:70736): a right drawer in three plain steps —
 * 1 What arrived? (per line: ordered / in / to come and the units that came, "Everything
 * arrived"), 2 Extra costs (freight, customs & duty, other; share by product value or by
 * quantity; whether they go on the supplier's bill), 3 Check and receive (the branch, only when
 * there are several, and the server's quote). Every taka figure is the quote's
 * (`POST …/receipts/quote`, rule 1); the receipt itself runs the same calculator and carries
 * one Idempotency-Key per opening, so a retry never receives twice.
 */
export function ReceiveDrawer({ purchase, open, onClose }: { purchase: Purchase | null; open: boolean; onClose: () => void }) {
    const titleId = useId();
    const ref = useDialog(open && purchase !== null, () => undefined);

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            onCancel={onClose}
            className="m-0 ms-auto h-dvh max-h-dvh w-full max-w-135 overflow-hidden bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay max-md:max-w-none"
        >
            {open && purchase && <ReceiveForm key={purchase.id} purchase={purchase} titleId={titleId} onClose={onClose} />}
        </dialog>
    );
}

function StepHeading({ n, title, id }: { n: number; title: string; id: string }) {
    return (
        <h3 id={id} className="flex items-center gap-3 t-admin-card-title text-fg-primary">
            <span aria-hidden className="grid size-6 shrink-0 place-items-center rounded-full bg-brand-subtle t-admin-caption-strong text-fg-brand">
                {n}
            </span>
            {title}
        </h3>
    );
}

function Thumb({ line }: { line: PurchaseLine }) {
    return line.image_url ? (
        <img src={line.image_url} alt="" className="size-9 shrink-0 rounded-xs-plus object-cover" />
    ) : (
        <span aria-hidden className="size-9 shrink-0 rounded-xs-plus bg-subtle" />
    );
}

function ReceiveForm({ purchase, titleId, onClose }: { purchase: Purchase; titleId: string; onClose: () => void }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const receive = useReceive(purchase.id);
    const branch = useWorkingBranch();
    const outstanding = useMemo(() => purchase.items.filter((line) => line.quantity_outstanding > 0), [purchase.items]);
    const toCome = outstanding.reduce((sum, l) => sum + l.quantity_outstanding, 0);

    const [quantities, setQuantities] = useState<Record<number, string>>(() => Object.fromEntries(outstanding.map((l) => [l.id, String(l.quantity_outstanding)])));
    const [costs, setCosts] = useState<Costs>({ freight: '', customs_duty: '', other_cost: '' });
    const [share, setShare] = useState<Share>('value');
    const [toSupplier, setToSupplier] = useState(true);
    // Who charged the extra costs when the supplier did not (a forwarder or C&F agent).
    const [vendor, setVendor] = useState('');
    const [vendorRef, setVendorRef] = useState('');
    const [paidVia, setPaidVia] = useState<ThirdPartyPaymentMethod | ''>('');
    // Stock arrives where the person receiving it works: the working branch, else the PO's branch.
    const multi = branch.multi && branch.locations.length > 1;
    const [location, setLocation] = useState(() => String(multi && branch.current ? branch.current.id : purchase.location_id));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());

    const input: ReceiptInput | null = useMemo(() => {
        const lines = outstanding.map((l) => {
            const text = (quantities[l.id] ?? '').trim();
            return { purchase_item_id: l.id, quantity: /^\d+$/.test(text) ? Number(text) : Number.NaN };
        });
        const money = (value: string) => (value.trim() === '' ? null : value.trim().replace(/,/g, ''));
        const candidate: ReceiptInput = {
            lines,
            freight: money(costs.freight),
            customs_duty: money(costs.customs_duty),
            other_cost: money(costs.other_cost),
            apportion_method: share,
            costs_paid_to_supplier: toSupplier,
            location_id: Number(location) === purchase.location_id ? null : Number(location),
            ...(toSupplier ? {} : { costs_vendor: money(vendor), costs_reference: money(vendorRef), costs_payment_method: paidVia === '' ? null : paidVia }),
        };
        return receivePurchaseRequestSchema.safeParse(candidate).success && lines.some((l) => l.quantity > 0) ? candidate : null;
    }, [outstanding, quantities, costs, share, toSupplier, location, purchase.location_id, vendor, vendorRef, paidVia]);

    const quoteInput = useDebounced(input, 300);
    const quote = useReceiptQuote(purchase.id, quoteInput);
    const quoted = quote.data && quoteInput !== null && input !== null ? quote.data : null;
    const quoteError = quote.error instanceof ApiError && quote.error.status === 422 ? quote.error : null;
    // Units, not money: what the button receives.
    const units = outstanding.reduce((sum, l) => sum + (/^\d+$/.test((quantities[l.id] ?? '').trim()) ? Number(quantities[l.id]) : 0), 0);

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
                    onClose();
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
        <Field label={t(label)} error={errors[key] ?? quoteError?.fieldError(key)}>
            {({ id, describedBy, invalid }) => (
                <Input
                    id={id}
                    aria-describedby={describedBy}
                    aria-invalid={invalid || undefined}
                    inputMode="decimal"
                    placeholder="0"
                    className="tabular"
                    value={costs[key]}
                    onChange={(e) => setCosts((c) => ({ ...c, [key]: e.target.value }))}
                />
            )}
        </Field>
    );
    const locationName = (id: number) => {
        const here = branch.locations.find((l) => l.id === id);
        return here ? (locale === 'bn' ? (here.name_bn ?? here.name) : here.name) : '';
    };
    const avgChanges = quoted?.lines.filter((l) => l.quantity > 0) ?? [];
    const nameOf = (itemId: number) => {
        const line = outstanding.find((l) => l.id === itemId);
        return line ? lineName(line, locale) : '';
    };

    return (
        <form
            noValidate
            className="flex h-full flex-col"
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <div className="flex shrink-0 items-start gap-2 border-b border-line-default py-4 ps-6 pe-4">
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <h2 id={titleId} className="t-admin-section text-fg-primary">
                        {t('rcv.title')}
                    </h2>
                    <p className="t-admin-caption text-fg-secondary">{t('rcv.subtitle', { number: purchase.number, supplier: purchase.supplier.name, units: formatUnits(toCome) })}</p>
                </div>
                <IconButton aria-label={t('rcv.close')} onClick={onClose}>
                    <XIcon size={18} aria-hidden />
                </IconButton>
            </div>

            <fieldset disabled={receive.isPending} className="flex min-h-0 min-w-0 flex-1 flex-col gap-6 overflow-y-auto px-6 py-5">
                <section aria-labelledby={`${titleId}-1`} className="flex flex-col gap-3">
                    <StepHeading n={1} id={`${titleId}-1`} title={t('rcv.step1')} />
                    <p className="t-admin-caption text-fg-secondary">{t('rcv.step1Hint')}</p>
                    <div className="flex items-center justify-between gap-3">
                        <p className="t-admin-caption text-fg-secondary">{t('rcv.toCome', { products: formatUnits(outstanding.length), units: formatUnits(toCome) })}</p>
                        <Button variant="ghost" size="sm" onClick={() => setQuantities(Object.fromEntries(outstanding.map((l) => [l.id, String(l.quantity_outstanding)])))}>
                            {t('rcv.everything')}
                        </Button>
                    </div>
                    <ul className="flex flex-col">
                        {outstanding.map((line, index) => {
                            const error = lineError(index);
                            const name = lineName(line, locale);
                            return (
                                <li key={line.id} className="flex flex-col gap-1 border-b border-line-default py-3.5">
                                    <div className="flex items-center gap-3">
                                        <Thumb line={line} />
                                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                            <p className="truncate t-admin-body-strong text-fg-primary">{name}</p>
                                            <p className="t-admin-caption tabular text-fg-secondary">
                                                {line.quantity_received > 0
                                                    ? t('rcv.lineMeta', { ordered: formatUnits(line.quantity_ordered), in: formatUnits(line.quantity_received), left: formatUnits(line.quantity_outstanding) })
                                                    : t('rcv.lineMetaNone', { ordered: formatUnits(line.quantity_ordered) })}
                                            </p>
                                        </div>
                                        <div className="flex w-24 shrink-0 flex-col gap-1">
                                            <span aria-hidden className="t-admin-body-strong text-fg-primary">
                                                {t('rcv.arrived')}
                                            </span>
                                            <Input
                                                aria-label={t('receive.qtyLabel', { name })}
                                                aria-invalid={error ? true : undefined}
                                                inputMode="numeric"
                                                className="tabular"
                                                value={quantities[line.id] ?? ''}
                                                onChange={(e) => setQuantities((qs) => ({ ...qs, [line.id]: e.target.value }))}
                                            />
                                        </div>
                                    </div>
                                    {error && <ErrorLine>{error}</ErrorLine>}
                                </li>
                            );
                        })}
                    </ul>
                    {errors.lines && <ErrorLine>{errors.lines}</ErrorLine>}
                </section>

                <section aria-labelledby={`${titleId}-2`} className="flex flex-col gap-3">
                    <StepHeading n={2} id={`${titleId}-2`} title={t('rcv.step2')} />
                    <p className="t-admin-caption text-fg-secondary">{t('rcv.step2Hint')}</p>
                    <div className="grid gap-3 sm:grid-cols-3">
                        {costField('freight', 'rcv.freight')}
                        {costField('customs_duty', 'rcv.customs')}
                        {costField('other_cost', 'rcv.other')}
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <p className="t-admin-body-strong text-fg-primary">{t('rcv.share')}</p>
                        <SegmentedControl
                            label={t('rcv.share')}
                            className="h-9 [&>button]:h-8 [&>button]:flex-1"
                            options={[
                                { value: 'value', label: t('rcv.share.value') },
                                { value: 'quantity', label: t('rcv.share.quantity') },
                            ]}
                            value={share}
                            onChange={setShare}
                        />
                    </div>
                    <label className="flex items-start gap-3 pt-1">
                        <input type="checkbox" className="mt-0.5 size-4 shrink-0 accent-brand" checked={toSupplier} onChange={(e) => setToSupplier(e.target.checked)} />
                        <span className="flex flex-col gap-0.5">
                            <span className="t-admin-body text-fg-primary">{t('rcv.toSupplier')}</span>
                            <span className="t-admin-caption text-fg-secondary">{t('rcv.toSupplierHint')}</span>
                        </span>
                    </label>
                    {!toSupplier && (
                        <div role="group" aria-label={t('receive.thirdParty')} className="grid gap-3 sm:grid-cols-3">
                            <Field label={t('receive.costsVendor')} hint={t('receive.costsVendorHint')} error={errors.costs_vendor} optional>
                                {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={vendor} onChange={(e) => setVendor(e.target.value)} />}
                            </Field>
                            <Field label={t('receive.costsReference')} error={errors.costs_reference} optional>
                                {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={vendorRef} onChange={(e) => setVendorRef(e.target.value)} />}
                            </Field>
                            <Field label={t('receive.costsPaidVia')} error={errors.costs_payment_method} optional>
                                {({ id, describedBy, invalid }) => (
                                    <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={paidVia} onChange={(e) => setPaidVia(e.target.value as ThirdPartyPaymentMethod | '')}>
                                        <option value="">{t('receive.costsUnpaid')}</option>
                                        {thirdPartyPaymentMethods.map((m) => (
                                            <option key={m} value={m}>
                                                {t(`payment.method.${m}` as MessageKey)}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                        </div>
                    )}
                </section>

                <section aria-labelledby={`${titleId}-3`} className="flex flex-col gap-3">
                    <StepHeading n={3} id={`${titleId}-3`} title={t('rcv.step3')} />
                    {multi && (
                        <Field label={t('rcv.into')} error={errors.location_id}>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={location} onChange={(e) => setLocation(e.target.value)}>
                                    {branch.locations.map((l) => (
                                        <option key={l.id} value={l.id}>
                                            {locationName(l.id)}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                    )}
                    <div aria-live="polite" className="flex flex-col gap-2 rounded-sm bg-subtle px-4 py-3.5">
                        {quoted ? (
                            <>
                                <SummaryRow label={t('rcv.goods', { units: formatUnits(quoted.units) })} amount={quoted.goods_value} />
                                <SummaryRow label={t(toSupplier ? 'rcv.extraSupplier' : 'rcv.extraElsewhere')} amount={quoted.landed_total} />
                                <SummaryRow strong label={t('rcv.owe')} amount={quoted.payable_amount} />
                                {avgChanges.length > 0 && (
                                    <p className="t-admin-caption tabular text-fg-secondary">
                                        {t('rcv.avg')}{' '}
                                        {avgChanges
                                            .map((l) => t('rcv.avgLine', { name: nameOf(l.purchase_item_id), before: takaText(l.avg_cost_before), after: takaText(l.avg_cost_after) }))
                                            .join(' · ')}
                                    </p>
                                )}
                            </>
                        ) : (
                            <p className="t-admin-caption text-fg-secondary">{input !== null && quote.isFetching ? t('rcv.checking') : t('rcv.nothing')}</p>
                        )}
                    </div>
                </section>
            </fieldset>

            <div className="flex shrink-0 flex-wrap items-center gap-3 border-t border-line-default px-6 py-4">
                <p className="min-w-0 flex-1 basis-48 t-admin-caption text-fg-secondary">{t('rcv.footer')}</p>
                <Button variant="ghost" onClick={onClose}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" disabled={receive.isPending || input === null}>
                    <DownloadSimpleIcon size={16} aria-hidden />
                    {t('receive.submit', { units: formatUnits(quoted?.units ?? units) })}
                </Button>
            </div>
        </form>
    );
}

function SummaryRow({ label, amount, strong }: { label: string; amount: string; strong?: boolean }) {
    return (
        <div className={`flex items-center gap-2 ${strong ? 't-admin-body-strong text-fg-primary' : 't-admin-body text-fg-secondary'}`}>
            <span className="min-w-0 flex-1">{label}</span>
            <span className="tabular whitespace-nowrap text-fg-primary t-admin-body-strong">
                <Money amount={amount} />
            </span>
        </div>
    );
}

function ErrorLine({ children }: { children: ReactNode }) {
    return (
        <p role="alert" className="flex items-start gap-1 t-admin-caption text-fg-primary">
            <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
            {children}
        </p>
    );
}
