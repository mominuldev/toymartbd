import { useState, type ReactNode } from 'react';
import { Link } from 'react-router';
import { ArrowClockwiseIcon, ArrowSquareOutIcon, CheckIcon, ClockClockwiseIcon, CopyIcon, PrinterIcon, TruckIcon, WarningIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { IconButton } from '@/components/ui/IconButton';
import { useToast } from '@/components/ui/Toast';
import { labelUrl, useOrderShipments, useRetryShipment, type Shipment, type ShipmentStatus } from '../api';
import { CourierBookingPanel } from '../booking/CourierBookingPanel';
import { Taka, dhaka, elapsed, grams } from '../format';
import { FAILED_BOOKINGS_PATH, ShipmentStateChip, failureKey, shipmentIcon, sourceKey } from '../parts';

/** Order statuses a courier booking may start from (EloquentShippableOrders::SHIPPABLE). */
const SHIPPABLE = new Set(['confirmed', 'processing', 'ready_to_ship']);
/** 04-admin-spec A16: a submitted parcel past 6 h is overdue — chase the courier. */
const OVERDUE_MINUTES = 6 * 60;

type OrderLike = { id: number; number: string; status: string };

/**
 * The order detail's shipment card (Figma ShipmentTrackingTimeline in B · 03, 241:32775;
 * 04-admin-spec A17): header with the shipment number, a facts grid (provider, consignment,
 * weight, COD, charges, attempts), tracking link and label, then the event rail. Before any
 * booking it offers "Book courier" (orders.update + shipments.book), opening the booking panel.
 * A `submitted` shipment reads "Not issued yet" for its consignment — never a dash (rule 24d).
 */
export function ShipmentCard({ order }: { order: OrderLike }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const canView = can(me, 'shipments.view');
    const canBook = can(me, 'orders.update') && can(me, 'shipments.book');
    const shipments = useOrderShipments(order.id, canView);
    const [booking, setBooking] = useState(false);

    const latest = shipments.data?.[0];
    const open = latest && latest.status !== 'failed' && latest.status !== 'cancelled';
    const bookable = canBook && SHIPPABLE.has(order.status) && !open;

    return (
        <section className="flex flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1" aria-labelledby="order-shipment">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <h2 id="order-shipment" className="t-admin-section text-fg-primary">
                    {latest ? t('couriers.card.title', { number: latest.number }) : t('orders.shipment.title')}
                </h2>
                {latest && <ShipmentStateChip status={latest.status} />}
            </div>

            {!canView && <p className="t-admin-body text-fg-secondary">{t('couriers.card.noPermission')}</p>}
            {canView && shipments.isError && <p className="t-admin-body text-fg-secondary">{shipments.error instanceof ApiError ? shipments.error.problem.title : t('error.title')}</p>}
            {canView && shipments.isPending && <p className="t-admin-body text-fg-tertiary" aria-busy="true">{t('common.loading')}</p>}

            {canView && shipments.isSuccess && !latest && (
                <EmptyState
                    reason="noDataYet"
                    title={t('couriers.card.empty')}
                    body={SHIPPABLE.has(order.status) ? t('couriers.card.emptyReady') : t('couriers.card.emptyNotReady')}
                    className="border-dashed bg-subtle"
                    actions={
                        bookable && (
                            <Button onClick={() => setBooking(true)}>
                                <TruckIcon size={16} aria-hidden />
                                {t('couriers.card.book')}
                            </Button>
                        )
                    }
                />
            )}

            {latest && <ShipmentBody shipment={latest} canPrint={can(me, 'customers.view_pii')} canRetry={canBook} />}

            {latest && !open && bookable && (
                <div>
                    <Button variant="outline" onClick={() => setBooking(true)}>
                        <TruckIcon size={16} aria-hidden />
                        {t('couriers.card.bookAgain')}
                    </Button>
                </div>
            )}

            {bookable && <CourierBookingPanel open={booking} orderIds={[order.id]} onClose={() => setBooking(false)} />}
        </section>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-1">
            <span className="t-admin-overline text-fg-tertiary uppercase">{label}</span>
            <span className="t-admin-body text-fg-primary">{children}</span>
        </div>
    );
}

function ShipmentBody({ shipment: s, canPrint, canRetry }: { shipment: Shipment; canPrint: boolean; canRetry: boolean }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const retry = useRetryShipment();
    const [copied, setCopied] = useState(false);
    const since = s.status === 'submitted' ? elapsed(s.booked_at ?? s.created_at) : null;
    const overdue = since !== null && since.minutes > OVERDUE_MINUTES;

    const copy = async () => {
        if (!s.consignment_id) return;
        try {
            await navigator.clipboard.writeText(s.consignment_id);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            /* the id stays selectable */
        }
    };

    return (
        <>
            {s.status === 'failed' && (
                <div role="alert" className="flex flex-col gap-2 rounded-sm border-l-3 border-feedback-danger bg-subtle px-4 py-3">
                    <p className="flex items-start gap-2 t-admin-body text-fg-primary">
                        <WarningIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-feedback-danger" />
                        <span>
                            {t(failureKey(s.failure_code))}
                            {s.error_message && <span className="block t-admin-caption text-fg-secondary">“{s.error_message}”</span>}
                        </span>
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {canRetry && (
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={retry.isPending}
                                onClick={() =>
                                    retry.mutate(s.id, {
                                        onSuccess: () => notify(t('couriers.failed.retried', { number: s.number })),
                                        onError: (e) => notify(e instanceof ApiError ? (e.problem.detail ?? e.problem.title) : t('error.title'), 'danger'),
                                    })
                                }
                            >
                                <ArrowClockwiseIcon size={16} aria-hidden />
                                {t('couriers.failed.retry')}
                            </Button>
                        )}
                        <Link to={FAILED_BOOKINGS_PATH} className={buttonClass('ghost', 'sm')}>
                            {t('couriers.failed.open')}
                        </Link>
                    </div>
                </div>
            )}

            <div className="grid grid-cols-2 gap-x-6 gap-y-4 rounded-sm bg-subtle p-4 sm:grid-cols-3">
                <Fact label={t('couriers.card.provider')}>{s.provider.name}</Fact>
                <Fact label={t('couriers.card.consignment')}>
                    {s.consignment_id ? (
                        <span className="inline-flex items-center gap-1">
                            <span className="t-admin-mono text-fg-primary">{s.consignment_id}</span>
                            <IconButton size="sm" aria-label={t('couriers.card.copy')} onClick={() => void copy()}>
                                {copied ? <CheckIcon size={14} aria-hidden className="text-feedback-success" /> : <CopyIcon size={14} aria-hidden />}
                            </IconButton>
                        </span>
                    ) : (
                        <span className="t-admin-mono text-fg-tertiary">{t('couriers.card.notIssued')}</span>
                    )}
                </Fact>
                <Fact label={t('couriers.card.weight')}>
                    <span className="tabular">{grams(s.weight_grams)}</span>
                </Fact>
                <Fact label={t('couriers.card.cod')}>
                    <span className="tabular">
                        <Taka amount={s.cod_amount} />
                    </span>
                </Fact>
                <Fact label={t('couriers.card.charges')}>
                    {s.charge ? (
                        <span className="tabular">
                            <Taka amount={s.charge} />
                            {s.cod_fee && (
                                <>
                                    {' + '}
                                    <Taka amount={s.cod_fee} /> {t('couriers.card.fee')}
                                </>
                            )}
                        </span>
                    ) : (
                        <span className="text-fg-tertiary">{t('couriers.card.chargeUnknown')}</span>
                    )}
                </Fact>
                <Fact label={t('couriers.card.attempts')}>
                    <span className="tabular">{s.booking_attempts ?? 0}</span>
                </Fact>
            </div>

            {since && (
                <p className="flex items-center gap-1.5 t-admin-caption text-fg-primary">
                    {overdue ? <WarningIcon size={14} aria-hidden className="text-feedback-warning" /> : <ClockClockwiseIcon size={14} aria-hidden className="text-status-low-stock" />}
                    <span className="t-admin-num">{t('couriers.card.since', { h: since.h, m: since.m, time: dhaka(s.booked_at ?? s.created_at, locale).time })}</span>
                    {overdue && <span>· {t('couriers.card.overdue', { name: s.provider.name })}</span>}
                </p>
            )}

            <div className="flex flex-wrap items-center gap-2">
                {s.tracking_url ? (
                    <a href={s.tracking_url} target="_blank" rel="noopener noreferrer" className={buttonClass('outline', 'sm')}>
                        <ArrowSquareOutIcon size={14} aria-hidden />
                        {t('couriers.card.track', { name: s.provider.name })}
                    </a>
                ) : (
                    <span className="t-admin-caption text-fg-tertiary">{t('couriers.card.noTracking')}</span>
                )}
                {canPrint &&
                    (s.consignment_id ? (
                        <a href={labelUrl(s.id, locale)} target="_blank" rel="noopener" className={buttonClass('outline', 'sm')}>
                            <PrinterIcon size={14} aria-hidden />
                            {t('couriers.card.label')}
                        </a>
                    ) : (
                        <Button variant="outline" size="sm" disabled title={t('couriers.card.labelLater')} aria-describedby={`label-later-${s.id}`}>
                            <PrinterIcon size={14} aria-hidden />
                            {t('couriers.card.label')}
                            <span id={`label-later-${s.id}`} className="sr-only">
                                {t('couriers.card.labelLater')}
                            </span>
                        </Button>
                    ))}
            </div>

            <Timeline shipment={s} />
        </>
    );
}

/** The event rail (A17): every event says how we learned it — webhook, poll or manual. */
function Timeline({ shipment }: { shipment: Shipment }) {
    const { t, locale } = useI18n();
    const events = shipment.events ?? [];
    if (events.length === 0) {
        return (
            <p className="flex items-center gap-2 rounded-sm border border-dashed border-line-strong px-4 py-3 t-admin-caption text-fg-secondary">
                <ClockClockwiseIcon size={16} aria-hidden className="text-status-low-stock" />
                {shipment.status === 'submitted' ? t('couriers.card.awaitingFirst', { name: shipment.provider.name }) : t('couriers.card.noEvents')}
            </p>
        );
    }
    return (
        <ol className="flex flex-col" aria-label={t('couriers.card.events')}>
            {events.map((e) => {
                const status = (e.normalised_status ?? 'pending') as ShipmentStatus;
                const { icon: Icon, tint } = shipmentIcon(status);
                const when = e.occurred_at ? dhaka(e.occurred_at, locale) : null;
                return (
                    <li key={e.id} className="flex gap-4 border-b border-line-default py-3 last:border-b-0">
                        <span aria-hidden className="grid size-6 shrink-0 place-items-center rounded-full bg-subtle">
                            <Icon size={14} className={tint} />
                        </span>
                        <span className="flex w-24 shrink-0 flex-col">
                            <span className="t-admin-num text-fg-primary">{when?.day ?? '—'}</span>
                            <span className="t-admin-caption text-fg-tertiary">{when?.time ?? ''}</span>
                        </span>
                        <span className="flex min-w-0 flex-1 flex-col gap-1">
                            <span className="t-admin-body-strong text-fg-primary">{t(`couriers.status.${status}` as MessageKey)}</span>
                            {e.message && <span className="t-admin-body text-fg-secondary">“{e.message}”</span>}
                            {e.location && <span className="t-admin-caption text-fg-tertiary">{e.location}</span>}
                        </span>
                        <span className="h-4.5 self-start rounded-xs bg-subtle px-1 t-admin-caption text-fg-tertiary">{t(sourceKey(e.source))}</span>
                    </li>
                );
            })}
        </ol>
    );
}
