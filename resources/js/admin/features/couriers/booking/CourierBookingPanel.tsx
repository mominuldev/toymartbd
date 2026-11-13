import { useEffect, useId, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Link } from 'react-router';
import { CheckCircleIcon, ShieldWarningIcon, TruckIcon, WarningCircleIcon, XIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button, buttonClass } from '@/components/ui/Button';
import { Field, inputClass } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { Select } from '@/components/ui/Select';
import {
    useBookShipment,
    useBulkBook,
    useShipmentQuote,
    type BulkBookResult,
    type OrderQuote,
    type QuoteOrder,
    type QuoteProvider,
    type Shipment,
} from '../api';
import { GRAM, Taka, dhaka, grams, kilos, takaText } from '../format';
import { FAILED_BOOKINGS_PATH, LOCATION_MAPPING_PATH, ShipmentStateChip, TrustChip, UnverifiedBanner } from '../parts';

type Props = { open: boolean; orderIds: number[]; onClose: () => void; onDone?: () => void };

/**
 * Figma CourierBookingPanel 196:743 (B · 07 instance 241:34192, 04-admin-spec A16): a 480
 * right drawer. `single` books one order; `bulk` SUBMITS several — bulk returns no
 * consignment ids on either provider, so the word "book" is not used and every order shows
 * "Submitted — awaiting confirmation" until a webhook or poll confirms it (rule 24d).
 *
 * Every figure — weight from variant weights, item count, COD still due, declared value,
 * the charge preview per courier, whether a courier can take the parcel at all — comes from
 * `POST /shipments/quote` (rule 1). Changing the weight re-asks the server, debounced 300 ms.
 */
export function CourierBookingPanel({ open, orderIds, onClose, onDone }: Props) {
    const { t } = useI18n();
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }
    }, [open]);

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            onCancel={onClose}
            className="m-0 ms-auto h-dvh max-h-dvh w-full max-w-120 overflow-hidden rounded-s-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay max-md:max-w-none max-md:rounded-none"
        >
            {open && orderIds.length > 0 && (orderIds.length === 1 ? <SinglePanel orderId={orderIds[0]!} titleId={titleId} onClose={onClose} onDone={onDone} /> : <BulkPanel orderIds={orderIds} titleId={titleId} onClose={onClose} onDone={onDone} />)}
            {open && orderIds.length === 0 && <span className="sr-only" id={titleId}>{t('couriers.book.title', { number: '' })}</span>}
        </dialog>
    );
}

function useDebounced<T>(value: T, ms = 300): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(value), ms);
        return () => window.clearTimeout(id);
    }, [value, ms]);
    return debounced;
}

/** First provider that can take a booking: the default if it can, else the first open one. */
function pickProvider(providers: QuoteProvider[], usable: (p: QuoteProvider) => boolean): QuoteProvider | undefined {
    return providers.find((p) => p.is_default && usable(p)) ?? providers.find(usable) ?? providers.find((p) => p.is_default) ?? providers[0];
}

function Header({ titleId, title, sub, onClose }: { titleId: string; title: string; sub?: string; onClose: () => void }) {
    const { t } = useI18n();
    return (
        <div className="flex shrink-0 items-center gap-2 border-b border-line-default py-4 ps-5 pe-4">
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <h2 id={titleId} className="truncate t-admin-section text-fg-primary">
                    {title}
                </h2>
                {sub && <p className="truncate t-admin-caption text-fg-tertiary">{sub}</p>}
            </div>
            <IconButton aria-label={t('couriers.close')} onClick={onClose}>
                <XIcon size={18} aria-hidden />
            </IconButton>
        </div>
    );
}

function Footer({ children }: { children: ReactNode }) {
    return <div className="flex shrink-0 items-center gap-3 border-t border-line-default bg-surface px-5 py-4">{children}</div>;
}

/** Figma CourierProviderCard 194:1014 — a radio card, not a dropdown, so charges compare at a glance. */
function ProviderRadio({ provider, checked, disabled, onSelect, name }: { provider: QuoteProvider; checked: boolean; disabled: boolean; onSelect: () => void; name: string }) {
    const { t, locale } = useI18n();
    const r = provider.rates;
    const look = checked ? 'border-2 border-brand bg-brand-subtle' : disabled ? 'border border-line-default bg-subtle' : 'border border-line-default bg-surface';
    return (
        <label className={`flex cursor-pointer items-start gap-3 rounded-sm px-4 py-3.5 ${look} ${disabled ? 'cursor-not-allowed' : ''}`} aria-disabled={disabled || undefined}>
            <input type="radio" name={name} checked={checked} disabled={disabled} onChange={onSelect} className="mt-0.5 size-4.5 shrink-0 accent-brand" />
            <span className="flex min-w-0 flex-1 flex-col gap-1">
                <span className={`t-admin-body-strong ${disabled ? 'text-fg-tertiary' : 'text-fg-primary'}`}>{provider.name}</span>
                <span className="t-admin-caption text-fg-secondary">
                    {t('couriers.rates.summary', {
                        dhaka: takaText(r.base_charge_dhaka),
                        suburb: takaText(r.base_charge_suburb),
                        outside: takaText(r.base_charge_outside),
                        perKg: takaText(r.per_kg_charge),
                        kg: kilos(r.included_weight_grams),
                        cod: r.cod_fee_percent.replace(/\.?0+$/, ''),
                        ret: takaText(r.return_charge),
                    })}
                </span>
                <span className="flex flex-wrap items-center gap-2">
                    <TrustChip trust={provider.trust} bookings={provider.bookings_count} />
                    {provider.blocked_reason && <span className="t-admin-caption text-fg-tertiary">{t(`couriers.blocked.${provider.blocked_reason}` as MessageKey)}</span>}
                </span>
            </span>
            <span className="flex shrink-0 flex-col items-end text-end">
                {provider.balance ? (
                    <>
                        <span className="t-admin-num text-fg-primary">
                            <Taka amount={provider.balance} />
                        </span>
                        {provider.balance_checked_at && <span className="t-admin-caption text-fg-tertiary">{t('couriers.book.checked', { time: dhaka(provider.balance_checked_at, locale).time })}</span>}
                    </>
                ) : (
                    <>
                        <span className="t-admin-num text-fg-primary">—</span>
                        <span className="t-admin-caption text-fg-tertiary">{t('couriers.book.noBalance')}</span>
                    </>
                )}
            </span>
        </label>
    );
}

/** The reason a booking cannot go ahead, always naming the screen that fixes it. */
function blockReason(provider: QuoteProvider | undefined, quote: OrderQuote | undefined, order: QuoteOrder | undefined, locationId: number | null): MessageKey | null {
    if (!provider) return 'couriers.blocked.none';
    if (order && order.can_ship === false) return 'couriers.blocked.order_state';
    if (provider.blocked_reason) return `couriers.blocked.${provider.blocked_reason}` as MessageKey;
    if (quote?.blocked_reason) return `couriers.blocked.${quote.blocked_reason}` as MessageKey;
    if (quote?.location_status === 'ambiguous' && locationId === null) return 'couriers.blocked.ambiguous_location';
    return null;
}

function SinglePanel({ orderId, titleId, onClose, onDone }: { orderId: number; titleId: string; onClose: () => void; onDone?: () => void }) {
    const { t, locale } = useI18n();
    const radioName = useId();
    const [weight, setWeight] = useState('');
    const [note, setNote] = useState('');
    const [providerId, setProviderId] = useState<number | null>(null);
    const [locationId, setLocationId] = useState<number | null>(null);
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const [error, setError] = useState<string | null>(null);
    const [booked, setBooked] = useState<Shipment | null>(null);
    const book = useBookShipment(orderId);

    const typed = /^\d+$/.test(weight.trim()) ? Number(weight.trim()) : null;
    const [variantWeight, setVariantWeight] = useState<number | null>(null);
    // Only a weight that differs from the variant sum is an override; the server owns the default.
    const override = typed !== null && typed !== variantWeight ? typed : null;
    const debounced = useDebounced(override);
    const request = useMemo(() => [{ id: orderId, weight_grams: debounced }], [orderId, debounced]);
    const quote = useShipmentQuote(request, true);

    const data = quote.data;
    const order = data?.orders[0];
    const usable = (p: QuoteProvider) => !p.blocked_reason && !order?.quotes?.find((q) => q.provider_id === p.id)?.blocked_reason;
    const provider = data ? (data.providers.find((p) => p.id === providerId) ?? pickProvider(data.providers, usable)) : undefined;
    const q = order?.quotes?.find((x) => x.provider_id === provider?.id);
    const estimate = q?.estimate ?? null;

    useEffect(() => {
        if (order?.variant_weight_grams !== undefined && weight === '') {
            setVariantWeight(order.variant_weight_grams);
            setWeight(String(order.variant_weight_grams));
        }
        // Only on first load: afterwards the field is the staff member's.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [order?.order_id]);
    useEffect(() => setLocationId(q?.location_status === 'mapped' ? (q.location_options[0]?.id ?? null) : null), [q?.provider_id, q?.location_status]); // eslint-disable-line react-hooks/exhaustive-deps

    const blocked = blockReason(provider, q, order, locationId);
    const loading = quote.isFetching;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!provider || blocked) return;
        setError(null);
        book.mutate(
            { input: { provider: provider.code, courier_location_id: locationId, weight_grams: override, note: note.trim() || null }, idempotencyKey },
            {
                onSuccess: (shipment) => {
                    setBooked(shipment);
                    onDone?.();
                },
                onError: (e) => setError(e instanceof ApiError ? (e.problem.detail ?? e.problem.title) : t('error.title')),
            },
        );
    };

    const sub = order?.found ? [order.recipient_name, order.district ? (locale === 'bn' ? order.district.name_bn : order.district.name) : null, order.item_count !== undefined ? t('couriers.book.items', { count: order.item_count }) : null].filter(Boolean).join(' · ') : undefined;

    if (booked) {
        return (
            <div className="flex h-full flex-col">
                <Header titleId={titleId} title={t('couriers.book.title', { number: order?.number ?? '' })} sub={sub} onClose={onClose} />
                <div className="flex flex-1 flex-col items-start gap-3 overflow-y-auto p-5" aria-live="polite">
                    <CheckCircleIcon size={24} aria-hidden className="text-feedback-success" />
                    <p className="t-admin-section text-fg-primary">{t('couriers.book.requested', { name: provider?.name ?? '' })}</p>
                    <p className="t-admin-body text-fg-secondary">{t('couriers.book.requestedBody', { number: booked.number })}</p>
                    <ShipmentStateChip status={booked.status} />
                </div>
                <Footer>
                    <span className="flex-1" />
                    <Button onClick={onClose}>{t('couriers.close')}</Button>
                </Footer>
            </div>
        );
    }

    return (
        <form onSubmit={submit} noValidate className="flex h-full flex-col" aria-busy={book.isPending || undefined}>
            <Header titleId={titleId} title={t('couriers.book.title', { number: order?.number ?? '' })} sub={sub} onClose={onClose} />
            <div className="flex-1 overflow-y-auto">
                {quote.isError && <p className="m-5 t-admin-body text-fg-primary">{quote.error instanceof ApiError ? quote.error.problem.title : t('error.title')}</p>}
                {!data && !quote.isError && <p className="m-5 t-admin-body text-fg-tertiary" aria-busy="true">{t('common.loading')}</p>}
                {data && order && !order.found && <p className="m-5 t-admin-body text-fg-primary">{t('couriers.book.notFound')}</p>}
                {data && order?.found && (
                    <>
                        {provider && provider.trust !== 'verified' && <UnverifiedBanner name={provider.name} />}
                        <fieldset className="flex flex-col gap-2 px-5 pt-5 pb-2">
                            <legend className="mb-2 t-admin-body-strong text-fg-primary">{t('couriers.book.courier')}</legend>
                            {data.providers.map((p) => (
                                <ProviderRadio key={p.id} name={radioName} provider={p} checked={p.id === provider?.id} disabled={!p.is_active || p.trust === 'not_connected'} onSelect={() => setProviderId(p.id)} />
                            ))}
                        </fieldset>

                        <div className="flex flex-col gap-4 px-5 pt-4 pb-2">
                            <div className="grid grid-cols-2 gap-3">
                                <Field label={t('couriers.book.weight')} hint={t('couriers.book.weightHint')}>
                                    {({ id, describedBy }) => (
                                        <div className="relative">
                                            <input id={id} inputMode="numeric" aria-describedby={describedBy} value={weight} onChange={(e) => setWeight(e.target.value)} className={`h-10 pe-8 tabular ${inputClass}`} />
                                            <span aria-hidden className="pointer-events-none absolute inset-y-0 end-3 flex items-center t-admin-caption text-fg-tertiary">{GRAM}</span>
                                        </div>
                                    )}
                                </Field>
                                <ReadOnly label={t('couriers.book.items.label')} hint={t('couriers.book.itemsHint')} value={String(order.item_count ?? 0)} />
                                <ReadOnly label={t('couriers.book.declared')} hint={t('couriers.book.declaredHint')} value={<Taka amount={order.declared_value ?? '0'} />} />
                                <ReadOnly label={t('couriers.book.cod')} hint={t('couriers.book.codHint')} value={<Taka amount={order.cod_amount ?? '0'} />} />
                            </div>

                            {q && q.location_status !== 'not_required' && provider && (
                                <AreaBlock provider={provider} quote={q} locationId={locationId} onChange={setLocationId} />
                            )}

                            <Field label={t('couriers.book.note')} hint={t('couriers.book.noteHint')} optional>
                                {({ id, describedBy }) => <input id={id} maxLength={120} aria-describedby={describedBy} value={note} onChange={(e) => setNote(e.target.value)} className={`h-10 ${inputClass}`} />}
                            </Field>
                        </div>

                        <div className="px-5 pt-2 pb-5">
                            <ChargePreview estimate={estimate} provider={provider} codAmount={order.cod_amount ?? '0'} loading={loading} zone={order.zone ?? null} />
                        </div>
                    </>
                )}
            </div>
            <Footer>
                <Button variant="ghost" onClick={onClose}>
                    {t('common.cancel')}
                </Button>
                <span className="flex-1" />
                <div className="flex flex-col items-end gap-1">
                    {(blocked || error) && (
                        <p className="flex max-w-72 items-start gap-1 t-admin-caption text-fg-primary" role="alert">
                            <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                            {error ?? t(blocked!)}
                        </p>
                    )}
                    <Button type="submit" size="lg" disabled={!data || Boolean(blocked) || book.isPending || loading}>
                        <TruckIcon size={20} aria-hidden />
                        {book.isPending
                            ? t('couriers.book.booking')
                            : estimate && provider
                              ? t('couriers.book.submitWithCost', { name: provider.name, amount: takaText(estimate.total_if_delivered) })
                              : t('couriers.book.submit', { name: provider?.name ?? '' })}
                    </Button>
                </div>
            </Footer>
            {book.isPending && (
                <p className="sr-only" aria-live="polite">
                    {t('couriers.book.sending', { name: provider?.name ?? '' })}
                </p>
            )}
        </form>
    );
}

function ReadOnly({ label, hint, value }: { label: string; hint: string; value: ReactNode }) {
    return (
        <div className="flex flex-col gap-1.5">
            <span className="t-admin-body-strong text-fg-primary">{label}</span>
            <span className="flex h-10 items-center rounded-sm border border-line-default bg-subtle px-3 t-admin-body tabular text-fg-primary">{value}</span>
            <span className="t-admin-caption text-fg-secondary">{hint}</span>
        </div>
    );
}

/** Pathao only (requires_location_mapping): the mapped zone, a choice when several, or a block when none (rule 32). */
function AreaBlock({ provider, quote, locationId, onChange }: { provider: QuoteProvider; quote: OrderQuote; locationId: number | null; onChange: (id: number | null) => void }) {
    const { t, locale } = useI18n();
    const label = (o: OrderQuote['location_options'][number]) => [o.city, o.zone, o.area].filter(Boolean).join(' › ');

    if (quote.location_status === 'unmapped') {
        return (
            <div role="alert" className="flex flex-col gap-2 rounded-sm border-l-3 border-feedback-danger bg-subtle px-4 py-3">
                <p className="flex items-start gap-2 t-admin-body text-fg-primary">
                    <WarningCircleIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-feedback-danger" />
                    {t('couriers.area.unmapped', { name: provider.name })}
                </p>
                <Link to={LOCATION_MAPPING_PATH} className={`${buttonClass('outline', 'sm')} self-start`}>
                    {t('couriers.area.mapNow')}
                </Link>
            </div>
        );
    }

    const mapped = quote.location_options.find((o) => o.id === locationId);
    return (
        <div className="flex flex-col gap-2">
            <span className="t-admin-body-strong text-fg-primary">{t('couriers.area.title', { name: provider.name })}</span>
            {quote.location_status === 'ambiguous' ? (
                <Field label={t('couriers.area.choose')} hint={t('couriers.area.chooseHint')}>
                    {({ id, describedBy }) => (
                        <Select id={id} aria-describedby={describedBy} value={locationId ?? ''} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}>
                            <option value="">{t('couriers.area.pick')}</option>
                            {quote.location_options.map((o) => (
                                <option key={o.id} value={o.id}>
                                    {label(o)}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
            ) : (
                mapped && (
                    <>
                        <p className="rounded-sm border border-line-default bg-surface px-3 py-2 t-admin-body text-fg-primary">{label(mapped)}</p>
                        <p className="flex items-center gap-1.5 t-admin-caption text-fg-secondary">
                            <CheckCircleIcon size={14} aria-hidden className="text-feedback-success" />
                            {mapped.mapped_at ? t('couriers.area.mappedOn', { date: dhaka(mapped.mapped_at, locale).day }) : t('couriers.area.mapped')}
                        </p>
                    </>
                )
            )}
        </div>
    );
}

/** Figma ChargePreview (196:898): delivery, COD fee, return charge, cost if delivered, refusal cost, provenance. */
function ChargePreview({ estimate, provider, codAmount, loading, zone }: { estimate: OrderQuote['estimate']; provider: QuoteProvider | undefined; codAmount: string; loading: boolean; zone: string | null }) {
    const { t, locale } = useI18n();
    if (!estimate || !provider) {
        return <p className="rounded-sm bg-subtle p-4 t-admin-caption text-fg-secondary">{zone === null ? t('couriers.preview.noZone') : t('couriers.preview.none')}</p>;
    }
    const line = (label: string, amount: string) => (
        <div className="flex items-start gap-2">
            <span className="min-w-0 flex-1 t-admin-body text-fg-secondary">{label}</span>
            <span className="t-admin-num text-fg-primary">
                <Taka amount={amount} />
            </span>
        </div>
    );
    return (
        <div className="flex flex-col gap-2 rounded-sm bg-subtle p-4" aria-live="polite" aria-busy={loading || undefined}>
            <p className="t-admin-overline text-fg-tertiary uppercase">{t('couriers.preview.title')}</p>
            {line(t('couriers.preview.delivery', { zone: t(`couriers.zone.${estimate.zone}` as MessageKey), weight: grams(estimate.weight_grams) }), estimate.delivery_charge)}
            {line(t('couriers.preview.codFee', { pct: estimate.cod_fee_percent.replace(/\.?0+$/, ''), cod: takaText(codAmount) }), estimate.cod_fee)}
            {line(t('couriers.preview.return'), estimate.return_charge)}
            <div className="flex items-center gap-2 border-t border-line-strong pt-2">
                <span className="min-w-0 flex-1 t-admin-body-strong text-fg-primary">{t('couriers.preview.total')}</span>
                <span className="t-admin-num-lg text-fg-primary">{loading ? <span className="t-admin-caption text-fg-tertiary">{t('common.loading')}</span> : <Taka amount={estimate.total_if_delivered} signClass="t-admin-currency-num-lg" />}</span>
            </div>
            <p className="flex items-start gap-1.5 t-admin-caption text-fg-primary">
                <ShieldWarningIcon size={14} aria-hidden className="mt-px shrink-0 text-status-low-stock" />
                {t('couriers.preview.refused', { total: takaText(estimate.cost_if_refused), out: takaText(estimate.delivery_charge), back: takaText(estimate.return_charge) })}
            </p>
            <p className="t-admin-caption text-fg-tertiary">
                {provider.rate_card_updated_at ? t('couriers.preview.provenance', { date: dhaka(provider.rate_card_updated_at, locale).dayYear }) : t('couriers.preview.provenanceNever')}
            </p>
        </div>
    );
}

function BulkPanel({ orderIds, titleId, onClose, onDone }: { orderIds: number[]; titleId: string; onClose: () => void; onDone?: () => void }) {
    const { t, locale } = useI18n();
    const radioName = useId();
    const [providerId, setProviderId] = useState<number | null>(null);
    const [ack, setAck] = useState(false);
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const [results, setResults] = useState<{ at: string; rows: BulkBookResult[]; requested: number; failed: number } | null>(null);
    const [error, setError] = useState<string | null>(null);
    const request = useMemo(() => orderIds.map((id) => ({ id })), [orderIds]);
    const quote = useShipmentQuote(request, true);
    const bulk = useBulkBook();
    const data = quote.data;
    const provider = data ? (data.providers.find((p) => p.id === providerId) ?? pickProvider(data.providers, (p) => !p.blocked_reason)) : undefined;
    const totals = data?.totals.find((x) => x.provider_id === provider?.id);
    const numberOf = (id: number) => data?.orders.find((o) => o.order_id === id)?.number ?? `#${id}`;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!provider || provider.blocked_reason || !ack) return;
        setError(null);
        bulk.mutate(
            { input: { order_ids: orderIds, provider: provider.code }, idempotencyKey },
            {
                onSuccess: (response) => {
                    setResults({ at: new Date().toISOString(), rows: response.data, requested: response.meta.requested, failed: response.meta.failed });
                    onDone?.();
                },
                onError: (e) => setError(e instanceof ApiError ? (e.problem.detail ?? e.problem.title) : t('error.title')),
            },
        );
    };

    const title = t('couriers.bulk.title', { count: orderIds.length });

    if (results) {
        return (
            <div className="flex h-full flex-col">
                <Header titleId={titleId} title={title} onClose={onClose} />
                <div className="flex flex-1 flex-col gap-4 overflow-y-auto p-5" aria-live="polite">
                    <p className="flex items-center gap-2 t-admin-section text-fg-primary">
                        <CheckCircleIcon size={20} aria-hidden className="text-feedback-success" />
                        {t('couriers.bulk.submittedAt', { count: results.requested, time: dhaka(results.at, locale).time, name: provider?.name ?? '' })}
                    </p>
                    <p className="t-admin-body text-fg-secondary">{t('couriers.bulk.awaiting', { name: provider?.name ?? '' })}</p>
                    <ul className="flex flex-col divide-y divide-line-default rounded-sm border border-line-default">
                        {results.rows.map((row) => (
                            <li key={row.order_id} className="flex items-center gap-3 px-4 py-3">
                                <span className="min-w-0 flex-1 t-admin-mono-sm text-fg-primary">{numberOf(row.order_id)}</span>
                                {row.result === 'requested' ? (
                                    <ShipmentStateChip status="submitted" />
                                ) : (
                                    <span className="flex max-w-60 items-start gap-1 t-admin-caption text-fg-primary">
                                        <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                                        {row.message ?? t(row.result === 'not_found' ? 'couriers.bulk.notFound' : 'couriers.bulk.conflict')}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
                <Footer>
                    <Link to={FAILED_BOOKINGS_PATH} className={buttonClass('ghost', 'md')} onClick={onClose}>
                        {t('couriers.failed.open')}
                    </Link>
                    <span className="flex-1" />
                    <Button onClick={onClose}>{t('couriers.close')}</Button>
                </Footer>
            </div>
        );
    }

    return (
        <form onSubmit={submit} noValidate className="flex h-full flex-col">
            <Header
                titleId={titleId}
                title={totals ? t('couriers.bulk.titleCod', { count: orderIds.length, cod: takaText(totals.cod_amount) }) : title}
                onClose={onClose}
            />
            <div className="flex-1 overflow-y-auto">
                {quote.isError && <p className="m-5 t-admin-body text-fg-primary">{quote.error instanceof ApiError ? quote.error.problem.title : t('error.title')}</p>}
                {!data && !quote.isError && <p className="m-5 t-admin-body text-fg-tertiary" aria-busy="true">{t('common.loading')}</p>}
                {data && (
                    <>
                        {provider && provider.trust !== 'verified' && <UnverifiedBanner name={provider.name} />}
                        <fieldset className="flex flex-col gap-2 px-5 pt-5 pb-2">
                            <legend className="mb-2 t-admin-body-strong text-fg-primary">{t('couriers.book.courier')}</legend>
                            {data.providers.map((p) => (
                                <ProviderRadio key={p.id} name={radioName} provider={p} checked={p.id === provider?.id} disabled={!p.is_active || p.trust === 'not_connected'} onSelect={() => setProviderId(p.id)} />
                            ))}
                        </fieldset>
                        <ul className="mx-5 mt-3 flex flex-col divide-y divide-line-default rounded-sm border border-line-default" aria-label={t('couriers.bulk.orders')}>
                            {data.orders.map((o) => {
                                const oq = o.quotes?.find((x) => x.provider_id === provider?.id);
                                const reason = !o.found ? 'couriers.bulk.notFound' : o.can_ship === false ? 'couriers.blocked.order_state' : oq?.blocked_reason ? (`couriers.blocked.${oq.blocked_reason}` as MessageKey) : null;
                                return (
                                    <li key={o.order_id} className="flex min-h-16 items-center gap-3 px-4 py-2.5">
                                        <span className="flex min-w-0 flex-1 flex-col">
                                            <span className="t-admin-mono-sm text-fg-primary">{o.number ?? `#${o.order_id}`}</span>
                                            {o.found && (
                                                <span className="truncate t-admin-caption text-fg-secondary">
                                                    {[o.recipient_name, o.zone ? t(`couriers.zone.${o.zone}` as MessageKey) : null, o.weight_grams !== undefined ? grams(o.weight_grams) : null].filter(Boolean).join(' · ')}
                                                </span>
                                            )}
                                        </span>
                                        {reason ? (
                                            <span className="flex max-w-44 items-start gap-1 t-admin-caption text-fg-primary">
                                                <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                                                {t(reason)}
                                            </span>
                                        ) : (
                                            oq?.estimate && (
                                                <span className="t-admin-num text-fg-primary">
                                                    <Taka amount={oq.estimate.total_if_delivered} />
                                                </span>
                                            )
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                        {totals && (
                            <p className="mx-5 mt-3 t-admin-body text-fg-primary">
                                {t('couriers.bulk.totals', { parcels: totals.parcels, weight: grams(totals.weight_grams), cod: takaText(totals.cod_amount), cost: takaText(totals.total_if_delivered) })}
                            </p>
                        )}
                        <label className="m-5 flex items-start gap-3 border-l-3 border-status-low-stock bg-subtle px-4 py-3">
                            <input type="checkbox" checked={ack} onChange={(e) => setAck(e.target.checked)} className="mt-1 size-4 shrink-0 accent-brand" />
                            <span className="t-admin-body text-fg-primary">{t('couriers.bulk.ack', { count: orderIds.length, name: provider?.name ?? '' })}</span>
                        </label>
                    </>
                )}
            </div>
            <Footer>
                <Button variant="ghost" onClick={onClose}>
                    {t('common.cancel')}
                </Button>
                <span className="flex-1" />
                <div className="flex flex-col items-end gap-1">
                    {(error || provider?.blocked_reason) && (
                        <p className="flex max-w-72 items-start gap-1 t-admin-caption text-fg-primary" role="alert">
                            <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                            {error ?? t(`couriers.blocked.${provider!.blocked_reason}` as MessageKey)}
                        </p>
                    )}
                    <Button type="submit" size="lg" disabled={!data || !provider || Boolean(provider.blocked_reason) || !ack || bulk.isPending}>
                        <TruckIcon size={20} aria-hidden />
                        {bulk.isPending ? t('couriers.bulk.submitting') : t('couriers.bulk.submit', { count: orderIds.length, name: provider?.name ?? '' })}
                    </Button>
                </div>
            </Footer>
        </form>
    );
}

