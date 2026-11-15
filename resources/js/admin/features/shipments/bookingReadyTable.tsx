import { Link } from 'react-router';
import { TruckIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';
import { dataTable } from '@/components/ui/DataTable';
import type { BookingCourier, ReadyOrder } from './bookingApi';
import { dhakaTime, grams, taka } from './bookingFormat';
import { BOOKING_MAPPING_PATH, blockedKey, courierName } from './bookingParts';

/** The courier chosen for a row: staff's pick, else the server's suggestion (default courier when it can). */
export function chosenCourier(row: ReadyOrder, picks: Record<number, number>): number | null {
    return picks[row.order_id] ?? row.suggested_provider_id ?? null;
}

/** Why the chosen courier cannot take this row (null = it can) — straight from the server's preflight. */
export function rowBlock(row: ReadyOrder, providerId: number | null): ReadyOrder['couriers'][number]['blocked_reason'] | 'none' {
    if (providerId === null) return 'none';
    return row.couriers.find((c) => c.provider_id === providerId)?.blocked_reason ?? null;
}

type Props = {
    rows: ReadyOrder[];
    couriers: BookingCourier[];
    picks: Record<number, number>;
    selected: Set<number>;
    canBook: boolean;
    onPick: (orderId: number, providerId: number) => void;
    onToggle: (orderId: number) => void;
    onToggleAll: (orderIds: number[], on: boolean) => void;
    onBookOne: (orderId: number) => void;
};

/**
 * B · 07 ready-to-ship table: select · order + ready since · customer + zone · weight (variant
 * weights) · COD · courier select · Book. A row whose chosen courier is blocked shows the
 * server's reason and cannot be selected for that courier; an unmapped area links to the
 * mapping screen (rule 32 — never guessed).
 */
export function ReadyTable({ rows, couriers, picks, selected, canBook, onPick, onToggle, onToggleAll, onBookOne }: Props) {
    const { t, locale } = useI18n();
    const bookable = rows.filter((r) => rowBlock(r, chosenCourier(r, picks)) === null).map((r) => r.order_id);
    const allOn = bookable.length > 0 && bookable.every((id) => selected.has(id));

    return (
        <div className="relative overflow-x-auto">
            <table className={`${dataTable.table} min-w-240`}>
                <thead className={dataTable.head}>
                    <tr>
                        <th className={`${dataTable.th} w-12`}>
                            {canBook && (
                                <input
                                    type="checkbox"
                                    checked={allOn}
                                    disabled={bookable.length === 0}
                                    onChange={() => onToggleAll(bookable, !allOn)}
                                    aria-label={t('bookingQueue.selectAll')}
                                    className="size-4 accent-brand"
                                />
                            )}
                        </th>
                        <th className={dataTable.th}>{t('bookingQueue.col.order')}</th>
                        <th className={dataTable.th}>{t('bookingQueue.col.customer')}</th>
                        <th className={`${dataTable.th} text-end`}>{t('bookingQueue.col.weight')}</th>
                        <th className={`${dataTable.th} text-end`}>{t('bookingQueue.col.cod')}</th>
                        <th className={dataTable.th}>{t('bookingQueue.col.courier')}</th>
                        <th className={dataTable.th}>
                            <span className="sr-only">{t('bookingQueue.col.actions')}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => {
                        const providerId = chosenCourier(row, picks);
                        const block = rowBlock(row, providerId);
                        const chosen = couriers.find((c) => c.id === providerId);
                        const place = row.district ? (locale === 'bn' ? row.district.name_bn : row.district.name) : null;
                        return (
                            <tr key={row.order_id} className={dataTable.row} data-blocked={block !== null || undefined}>
                                <td className={`${dataTable.td} w-12`}>
                                    {canBook && (
                                        <input
                                            type="checkbox"
                                            checked={selected.has(row.order_id)}
                                            disabled={block !== null}
                                            onChange={() => onToggle(row.order_id)}
                                            aria-label={t('bookingQueue.selectRow', { number: row.number })}
                                            className="size-4 accent-brand"
                                        />
                                    )}
                                </td>
                                <td className={dataTable.td}>
                                    <Link to={`/orders/${row.order_id}`} className="block t-admin-mono text-fg-primary hover:underline">
                                        {row.number}
                                    </Link>
                                    <span className="t-admin-caption text-fg-tertiary">
                                        {row.status !== 'ready_to_ship'
                                            ? row.ready_since
                                                ? t(`bookingQueue.notPacked.${row.status}`, { day: dhakaTime(row.ready_since, locale).day })
                                                : t('bookingQueue.notPackedUnknown')
                                            : row.ready_since
                                              ? t('bookingQueue.readySince', {
                                                    day: dhakaTime(row.ready_since, locale).day,
                                                })
                                              : t('bookingQueue.readyUnknown')}
                                    </span>
                                </td>
                                <td className={dataTable.td}>
                                    <span className="block t-admin-body-strong text-fg-primary">{row.recipient_name}</span>
                                    <span className="t-admin-caption text-fg-tertiary">
                                        {[place, row.zone ? t(`couriers.zone.${row.zone}` as MessageKey) : null].filter(Boolean).join(' · ')}
                                    </span>
                                </td>
                                <td className={`${dataTable.td} text-end t-admin-num tabular`}>
                                    {row.weight_missing ? <span className="text-fg-tertiary">—</span> : grams(row.weight_grams)}
                                </td>
                                <td className={`${dataTable.td} text-end t-admin-num tabular`}>{taka(row.cod_amount)}</td>
                                <td className={`${dataTable.td} w-64`}>
                                    <Select
                                        size="sm"
                                        aria-label={t('bookingQueue.courierFor', { number: row.number })}
                                        value={providerId ?? ''}
                                        disabled={!canBook}
                                        onChange={(e) => onPick(row.order_id, Number(e.target.value))}
                                    >
                                        {providerId === null && <option value="">{t('bookingQueue.noCourier')}</option>}
                                        {couriers.map((c) => {
                                            const reason = row.couriers.find((x) => x.provider_id === c.id)?.blocked_reason ?? null;
                                            const name = courierName(c, locale);
                                            return (
                                                <option key={c.id} value={c.id} disabled={reason !== null}>
                                                    {reason
                                                        ? t('bookingQueue.optionBlocked', {
                                                              name,
                                                              reason: t(blockedKey(reason)),
                                                          })
                                                        : name}
                                                </option>
                                            );
                                        })}
                                    </Select>
                                    {block !== null && (
                                        <p className="mt-1 flex items-start gap-1 t-admin-caption text-fg-primary">
                                            <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                                            <span>
                                                {block === 'none' ? t('bookingQueue.noCourier') : t(blockedKey(block))}
                                                {(block === 'unmapped_location' || block === 'ambiguous_location') && chosen && (
                                                    <>
                                                        {' · '}
                                                        <Link to={`${BOOKING_MAPPING_PATH}?provider=${chosen.code}`} className="text-fg-link hover:underline">
                                                            {t('bookingQueue.mapArea')}
                                                        </Link>
                                                    </>
                                                )}
                                            </span>
                                        </p>
                                    )}
                                </td>
                                <td className={`${dataTable.td} text-end`}>
                                    {canBook && (
                                        <Button variant="outline" size="sm" onClick={() => onBookOne(row.order_id)} aria-label={t('bookingQueue.bookOne', { number: row.number })}>
                                            <TruckIcon size={16} aria-hidden />
                                            {t('bookingQueue.book')}
                                        </Button>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
