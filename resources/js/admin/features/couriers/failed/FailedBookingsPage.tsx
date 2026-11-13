import { Link } from 'react-router';
import { ArrowClockwiseIcon, ProhibitIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button, buttonClass } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { useCancelShipment, useFailedShipments, useRetryShipment, type Shipment } from '../api';
import { Taka, dhaka, grams } from '../format';
import { COURIER_SETTINGS_PATH, LOCATION_MAPPING_PATH, ShipmentStateChip, failureKey } from '../parts';

/** Failures a person fixes elsewhere before retrying: link to the screen that fixes it. */
const FIX: Record<string, { to: string; label: MessageKey }> = {
    unmapped_location: { to: LOCATION_MAPPING_PATH, label: 'couriers.area.mapNow' },
    ambiguous_location: { to: LOCATION_MAPPING_PATH, label: 'couriers.area.mapNow' },
    unverified_provider: { to: COURIER_SETTINGS_PATH, label: 'couriers.unverified.open' },
    inactive_provider: { to: COURIER_SETTINGS_PATH, label: 'couriers.unverified.open' },
};

/**
 * Fulfilment → Failed bookings (04-admin-spec B6 "Failed" tab; checklist §2.3 [C]). Every
 * failed booking lands here with the provider's own words — never silently dropped — plus
 * a shipment whose courier accepted it but whose order could not be marked shipped
 * (`completion_failed`: retry completes it without booking again). Retry re-sends the SAME
 * shipment number, so a courier that dedupes on reference does not get two parcels.
 * No Figma frame: B · 07's DataTable rows and the A16 `failed` state.
 */
export function FailedBookingsPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const canView = can(me, 'shipments.view');
    const canRetry = can(me, 'orders.update') && can(me, 'shipments.book');
    const canCancel = can(me, 'shipments.cancel');
    const failed = useFailedShipments(canView);
    const retry = useRetryShipment();
    const cancel = useCancelShipment();
    const { notify } = useToast();

    if (me && !canView) return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;

    const run = (move: 'retry' | 'cancel', s: Shipment) =>
        (move === 'retry' ? retry : cancel).mutate(s.id, {
            onSuccess: () => notify(t(move === 'retry' ? 'couriers.failed.retried' : 'couriers.failed.cancelled', { number: s.number })),
            onError: (e) => notify(e instanceof ApiError ? (e.problem.detail ?? e.problem.title) : t('error.title'), 'danger'),
        });

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('nav.group.deliver') }, { label: t('nav.shipments') }, { label: t('couriers.failed.title') }]}
                title={t('couriers.failed.title')}
                subtitle={t('couriers.failed.subtitle')}
            />
            {failed.isError && <QueryError error={failed.error} onRetry={() => void failed.refetch()} />}
            {failed.isPending && canView && <p className="t-admin-body text-fg-tertiary" aria-busy="true">{t('common.loading')}</p>}
            {failed.isSuccess && failed.data.length === 0 && <EmptyState reason="allClear" title={t('couriers.failed.empty')} body={t('couriers.failed.emptyBody')} />}
            {failed.isSuccess && failed.data.length > 0 && (
                <ul className="flex flex-col divide-y divide-line-default rounded-md border border-line-default bg-surface shadow-e1" aria-label={t('couriers.failed.title')}>
                    {failed.data.map((s) => {
                        const fix = s.failure_code ? FIX[s.failure_code] : undefined;
                        const busy = (retry.isPending && retry.variables === s.id) || (cancel.isPending && cancel.variables === s.id);
                        return (
                            <li key={s.id} className="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-start">
                                <div className="flex w-full flex-col gap-1 lg:w-56 lg:shrink-0">
                                    <Link to={`/orders/${s.order_id}`} className="t-admin-mono text-fg-link hover:underline">
                                        {s.order?.number ?? `#${s.order_id}`}
                                    </Link>
                                    <span className="t-admin-mono-sm text-fg-secondary">{s.number}</span>
                                    <span className="t-admin-caption text-fg-tertiary">
                                        {s.provider.name} · {dhaka(s.created_at, locale).day}, {dhaka(s.created_at, locale).time}
                                    </span>
                                </div>
                                <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                                    <ShipmentStateChip status={s.status} />
                                    <p className="t-admin-body-strong text-fg-primary">{t(failureKey(s.failure_code))}</p>
                                    {s.error_message && <p className="t-admin-body break-words text-fg-secondary">“{s.error_message}”</p>}
                                    <p className="t-admin-caption text-fg-tertiary tabular">
                                        {grams(s.weight_grams)} · <Taka amount={s.cod_amount} /> · {t('couriers.failed.attempts', { count: s.booking_attempts ?? 0 })}
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-center gap-2 lg:justify-end">
                                    {fix && (
                                        <Link to={fix.to} className={buttonClass('outline', 'sm')}>
                                            {t(fix.label)}
                                        </Link>
                                    )}
                                    {canRetry && (
                                        <Button size="sm" disabled={busy} onClick={() => run('retry', s)}>
                                            <ArrowClockwiseIcon size={16} aria-hidden />
                                            {t('couriers.failed.retry')}
                                        </Button>
                                    )}
                                    {canCancel && s.status === 'failed' && (
                                        <Button variant="ghost" size="sm" disabled={busy} onClick={() => run('cancel', s)}>
                                            <ProhibitIcon size={16} aria-hidden />
                                            {t('couriers.failed.cancel')}
                                        </Button>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
