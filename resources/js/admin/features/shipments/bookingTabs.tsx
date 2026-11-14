import { Link } from 'react-router';
import { MapPinIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { buttonClass } from '@/components/ui/Button';
import { dataTable } from '@/components/ui/DataTable';
import type { QueueShipment, ShipmentTab, UnmappedArea } from './bookingApi';
import { dhakaTime, grams, taka } from './bookingFormat';
import { BOOKING_FAILED_PATH, BOOKING_MAPPING_PATH, QueueStatusChip, courierName } from './bookingParts';

const FAILURES = [
    'unverified_provider',
    'inactive_provider',
    'unmapped_location',
    'ambiguous_location',
    'provider_refused',
    'transport',
    'order_not_shippable',
    'bulk_unsupported',
    'completion_failed',
];

function failureKey(code: string | null): MessageKey {
    return `bookingQueue.failure.${code && FAILURES.includes(code) ? code : 'unknown'}` as MessageKey;
}

/**
 * Submitted — awaiting ID / Booked today / Failed: the shipments behind those tabs. A
 * submitted shipment shows "awaiting ID", never a made-up consignment (rule 24d). Failed rows
 * name the reason in our words and the courier's, and send staff to the retry queue.
 */
export function ShipmentsTable({ tab, rows }: { tab: ShipmentTab; rows: QueueShipment[] }) {
    const { t, locale } = useI18n();
    const lastCol = tab === 'failed' ? 'bookingQueue.col.reason' : 'bookingQueue.col.consignment';
    return (
        <div className="relative overflow-x-auto">
            <table className={`${dataTable.table} min-w-200`}>
                <thead className={dataTable.head}>
                    <tr>
                        <th className={dataTable.th}>{t('bookingQueue.col.order')}</th>
                        <th className={dataTable.th}>{t('bookingQueue.col.customer')}</th>
                        <th className={`${dataTable.th} text-end`}>{t('bookingQueue.col.weight')}</th>
                        <th className={`${dataTable.th} text-end`}>{t('bookingQueue.col.cod')}</th>
                        <th className={dataTable.th}>{t('bookingQueue.col.courier')}</th>
                        <th className={dataTable.th}>{t('bookingQueue.col.status')}</th>
                        <th className={dataTable.th}>{t(lastCol)}</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map(({ shipment: s, submitted_at, recipient_name, district_name, upazila_name }) => {
                        const at = tab === 'booked_today' ? s.booked_at : submitted_at ?? s.created_at;
                        const when = at ? dhakaTime(at, locale) : null;
                        const whenText = when
                            ? tab === 'booked_today'
                                ? t('bookingQueue.bookedAt', {
                                      time: when.time,
                                  })
                                : t(s.status === 'submitted' ? 'bookingQueue.submittedAt' : 'bookingQueue.requestedAt', { day: when.day, time: when.time })
                            : null;
                        return (
                            <tr key={s.id} className={dataTable.row}>
                                <td className={dataTable.td}>
                                    <Link to={`/orders/${s.order_id}`} className="block t-admin-mono text-fg-primary hover:underline">
                                        {s.order?.number ?? `#${s.order_id}`}
                                    </Link>
                                    <span className="t-admin-mono-sm text-fg-tertiary">{s.number}</span>
                                </td>
                                <td className={dataTable.td}>
                                    <span className="block t-admin-body-strong text-fg-primary">{recipient_name ?? '—'}</span>
                                    <span className="t-admin-caption text-fg-tertiary">{[upazila_name, district_name].filter(Boolean).join(', ')}</span>
                                </td>
                                <td className={`${dataTable.td} text-end t-admin-num tabular`}>{grams(s.weight_grams)}</td>
                                <td className={`${dataTable.td} text-end t-admin-num tabular`}>{taka(s.cod_amount)}</td>
                                <td className={`${dataTable.td} t-admin-body`}>{courierName(s.provider, locale)}</td>
                                <td className={dataTable.td}>
                                    <QueueStatusChip status={s.status} />
                                    {whenText && <span className="mt-1 block t-admin-caption text-fg-tertiary">{whenText}</span>}
                                </td>
                                <td className={dataTable.td}>
                                    {tab === 'failed' ? (
                                        <>
                                            <span className="block t-admin-body-strong text-fg-primary">{t(failureKey(s.failure_code))}</span>
                                            <span className="t-admin-caption break-words text-fg-secondary">
                                                {s.error_message ? `“${s.error_message}”` : t('bookingQueue.failed.noMessage')}
                                            </span>
                                        </>
                                    ) : s.consignment_id ? (
                                        <>
                                            <span className="block t-admin-mono-sm text-fg-primary">{s.consignment_id}</span>
                                            {s.tracking_code && <span className="t-admin-mono-sm text-fg-tertiary">{s.tracking_code}</span>}
                                        </>
                                    ) : (
                                        <span className="t-admin-caption text-fg-tertiary">{t('bookingQueue.awaitingId')}</span>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
            {tab === 'failed' && (
                <div className={dataTable.footer}>
                    <span />
                    <Link to={BOOKING_FAILED_PATH} className={buttonClass('outline', 'sm')}>
                        {t('bookingQueue.failed.open')}
                    </Link>
                </div>
            )}
        </div>
    );
}

/** Unmapped areas: courier · area · ready orders there · "Map area" (the mapping screen, never a guess — rule 32). */
export function UnmappedTable({ rows }: { rows: UnmappedArea[] }) {
    const { t, locale } = useI18n();
    const name = (p: { name: string; name_bn: string } | null, fallback: string | null) => (p ? (locale === 'bn' ? p.name_bn : p.name) : fallback);
    return (
        <div className="relative overflow-x-auto">
            <table className={`${dataTable.table} min-w-180`}>
                <thead className={dataTable.head}>
                    <tr>
                        <th className={dataTable.th}>{t('bookingQueue.col.courier')}</th>
                        <th className={dataTable.th}>{t('bookingQueue.col.area')}</th>
                        <th className={dataTable.th}>{t('bookingQueue.col.orders')}</th>
                        <th className={dataTable.th}>
                            <span className="sr-only">{t('bookingQueue.col.actions')}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) => {
                        const more = r.orders_count - r.order_numbers.length;
                        return (
                            <tr key={`${r.provider.id}-${r.district?.id ?? 'x'}-${r.upazila?.id ?? 'x'}`} className={dataTable.row}>
                                <td className={`${dataTable.td} t-admin-body-strong`}>{courierName(r.provider, locale)}</td>
                                <td className={`${dataTable.td} t-admin-body`}>
                                    {[name(r.upazila, r.upazila_name), name(r.district, r.district_name)].filter(Boolean).join(', ')}
                                </td>
                                <td className={dataTable.td}>
                                    <span className="block t-admin-body text-fg-primary">
                                        {t('bookingQueue.unmapped.orders', {
                                            count: r.orders_count,
                                        })}
                                    </span>
                                    <span className="t-admin-mono-sm text-fg-tertiary">
                                        {r.order_numbers.join(', ')}
                                        {more > 0 && ` ${t('bookingQueue.unmapped.more', { count: more })}`}
                                    </span>
                                </td>
                                <td className={`${dataTable.td} text-end`}>
                                    <Link to={`${BOOKING_MAPPING_PATH}?provider=${r.provider.code}`} className={buttonClass('outline', 'sm')}>
                                        <MapPinIcon size={16} aria-hidden />
                                        {t('bookingQueue.mapArea')}
                                    </Link>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
