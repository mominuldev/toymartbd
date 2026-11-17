import { openPdf } from '@/lib/pdf';
import { useState, type ReactNode } from 'react';
import { Link } from 'react-router';
import { ArrowSquareOutIcon, CaretDownIcon, CaretUpIcon, PrinterIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { QueryError } from '@/components/ui/QueryError';
import { labelUrl, useShipmentTracking, type BoardEvent, type ShipmentTracking } from './boardApi';
import { SourceChip, Taka, dhaka, grams, statusIcon, statusKey } from './boardParts';

/**
 * The selected shipment's tracking card (Figma B · 08 "Shipment SHP-…"): a facts panel
 * (provider, consignment, weight, COD collected of total, charges, attempts), the courier
 * timeline newest first with each event's source chip and time (rule 24), the partial
 * delivery summary from what staff recorded on the order's return, and the actions — Track
 * on <courier> (only when the driver returned a tracking page), Print label (existing label
 * route; needs a consignment id and customers.view_pii) and Raw events (stored metadata
 * only: status strings, times, source and payload KEYS — never values, rule 20).
 *
 * Figma's "Synced 2:12 pm · next poll 2:42 pm" is replaced by the last event's received
 * time: the poll schedule is not stored per shipment.
 */
export function BoardDetail({ id }: { id: number }) {
    const { t } = useI18n();
    const tracking = useShipmentTracking(id);

    if (tracking.isError) return <QueryError error={tracking.error} onRetry={() => void tracking.refetch()} />;
    if (!tracking.data) {
        return (
            <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                {t('common.loading')}
            </p>
        );
    }
    return <Card s={tracking.data} />;
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-1">
            <span className="t-admin-overline text-fg-tertiary">{label}</span>
            <span className="t-admin-body text-fg-primary">{children}</span>
        </div>
    );
}

function Card({ s }: { s: ShipmentTracking }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [raw, setRaw] = useState(false);
    const provider = locale === 'bn' && s.provider.name_bn ? s.provider.name_bn : s.provider.name;
    const latest = s.events[0];
    const c = s.charges;

    return (
        <section className="flex flex-col gap-5 rounded-md border border-line-default bg-surface p-5 shadow-e1" aria-labelledby={`shipment-${s.id}`}>
            <div className="flex flex-wrap items-start justify-between gap-2">
                <h2 id={`shipment-${s.id}`} className="t-admin-section text-fg-primary">
                    {t('shipmentsBoard.detail.title', { number: s.number })}
                </h2>
                {latest && (
                    <p className="t-admin-caption text-fg-secondary tabular">
                        {t('shipmentsBoard.detail.lastHeard', {
                            time: `${dhaka(latest.received_at, locale).day} ${dhaka(latest.received_at, locale).time}`,
                        })}
                    </p>
                )}
            </div>

            <div className="grid grid-cols-2 gap-x-6 gap-y-4 rounded-sm bg-subtle p-4 sm:grid-cols-3">
                <Fact label={t('shipmentsBoard.detail.provider')}>{provider}</Fact>
                <Fact label={t('shipmentsBoard.detail.consignment')}>
                    {s.consignment_id ? <span className="t-admin-mono">{s.consignment_id}</span> : <span className="text-fg-tertiary">{t('shipmentsBoard.notIssued')}</span>}
                </Fact>
                <Fact label={t('shipmentsBoard.detail.weight')}>
                    <span className="tabular">{grams(s.weight_grams)}</span>
                </Fact>
                <Fact label={t('shipmentsBoard.detail.cod')}>
                    <span className="tabular">
                        {s.cod_collected === null ? (
                            <>
                                <Taka amount={s.cod_amount} /> <span className="t-admin-caption text-fg-tertiary">{t('shipmentsBoard.detail.notCollected')}</span>
                            </>
                        ) : (
                            <>
                                <Taka amount={s.cod_collected} /> {t('shipmentsBoard.detail.of')} <Taka amount={s.cod_amount} />
                            </>
                        )}
                    </span>
                </Fact>
                <Fact label={t('shipmentsBoard.detail.charges')}>
                    {c.charge === null && c.cod_fee === null && c.return_charge === null ? (
                        <span className="text-fg-tertiary">
                            {s.cost.amount !== null && s.cost.basis === 'estimate' ? (
                                <>
                                    <Taka amount={s.cost.amount} /> {t('shipmentsBoard.cost.est')}
                                </>
                            ) : (
                                t('shipmentsBoard.detail.chargesUnknown')
                            )}
                        </span>
                    ) : (
                        <span className="tabular">
                            {[
                                c.charge && <Taka key="c" amount={c.charge} />,
                                c.cod_fee && (
                                    <span key="f">
                                        <Taka amount={c.cod_fee} /> {t('shipmentsBoard.detail.fee')}
                                    </span>
                                ),
                                c.return_charge && (
                                    <span key="r">
                                        <Taka amount={c.return_charge} /> {t('shipmentsBoard.detail.return')}
                                    </span>
                                ),
                            ]
                                .filter(Boolean)
                                .flatMap((part, i) => (i === 0 ? [part] : [<span key={`p${i}`}> + </span>, part]))}
                            {s.cost.basis === 'settled' && s.cost.amount !== null && (
                                <span className="block t-admin-caption text-fg-tertiary">
                                    {t('shipmentsBoard.detail.settled')} <Taka amount={s.cost.amount} />
                                </span>
                            )}
                        </span>
                    )}
                </Fact>
                <Fact label={t('shipmentsBoard.detail.attempts')}>
                    <span className="tabular">{s.delivery_attempts}</span>
                </Fact>
            </div>

            {s.events.length === 0 ? (
                <p className="t-admin-body text-fg-secondary">{t('shipmentsBoard.detail.noEvents')}</p>
            ) : (
                <ol className="flex flex-col" aria-label={t('shipmentsBoard.detail.timeline')}>
                    {s.events.map((e, i) => (
                        <TimelineItem key={e.id} e={e} last={i === s.events.length - 1} />
                    ))}
                </ol>
            )}

            {s.outcome && <Outcome s={s} />}

            <div className="flex flex-wrap items-center gap-2">
                {s.tracking_url ? (
                    <a href={s.tracking_url} target="_blank" rel="noopener noreferrer" className={buttonClass('outline', 'sm')}>
                        <ArrowSquareOutIcon size={14} aria-hidden />
                        {t('shipmentsBoard.detail.track', { name: provider })}
                    </a>
                ) : (
                    <span className="t-admin-caption text-fg-tertiary">
                        {t('shipmentsBoard.detail.noTracking', {
                            name: provider,
                        })}
                    </span>
                )}
                {can(me, 'customers.view_pii') &&
                    (s.can_print_label ? (
                        <Button variant="outline" size="sm" onClick={() => void openPdf(labelUrl(s.id, locale))}>
                            <PrinterIcon size={14} aria-hidden />
                            {t('shipmentsBoard.detail.label')}
                        </Button>
                    ) : (
                        <Button variant="outline" size="sm" disabled title={t('shipmentsBoard.detail.labelLater')}>
                            <PrinterIcon size={14} aria-hidden />
                            {t('shipmentsBoard.detail.label')}
                            <span className="sr-only">{t('shipmentsBoard.detail.labelLater')}</span>
                        </Button>
                    ))}
                <Button variant="ghost" size="sm" aria-expanded={raw} aria-controls={`raw-${s.id}`} onClick={() => setRaw((v) => !v)} disabled={s.events.length === 0}>
                    {t('shipmentsBoard.detail.raw')}
                    {raw ? <CaretUpIcon size={14} aria-hidden /> : <CaretDownIcon size={14} aria-hidden />}
                </Button>
                {s.order && (
                    <Link to={`/orders/${s.order.id}`} className={`${buttonClass('ghost', 'sm')} ms-auto`}>
                        {t('shipmentsBoard.detail.openOrder', {
                            number: s.order.number,
                        })}
                    </Link>
                )}
            </div>

            {raw && <RawEvents id={`raw-${s.id}`} events={s.events} />}
        </section>
    );
}

function TimelineItem({ e, last }: { e: BoardEvent; last: boolean }) {
    const { t, locale } = useI18n();
    const Icon = statusIcon(e.status);
    const at = dhaka(e.occurred_at, locale);

    return (
        <li className={`grid grid-cols-[32px_72px_1fr_auto] gap-x-3 py-3 ${last ? '' : 'border-b border-line-default'}`}>
            <span aria-hidden className="grid size-7 place-items-center rounded-full bg-subtle">
                <Icon size={14} className="text-fg-secondary" />
            </span>
            <span className="flex flex-col t-admin-caption tabular">
                <span className="t-admin-body-strong text-fg-primary">{at.day}</span>
                <span className="text-fg-tertiary">{at.time}</span>
            </span>
            <span className="flex min-w-0 flex-col gap-0.5">
                <span className="t-admin-body-strong text-fg-primary">{t(statusKey(e.status))}</span>
                {(e.message || e.provider_status) && <span className="t-admin-body text-fg-secondary">“{e.message ?? e.provider_status}”</span>}
                {e.location && <span className="t-admin-caption text-fg-tertiary">{e.location}</span>}
            </span>
            <span className="self-start">
                <SourceChip source={e.source} />
            </span>
        </li>
    );
}

function Outcome({ s }: { s: ShipmentTracking }) {
    const { t, locale } = useI18n();
    const o = s.outcome;
    if (!o) return null;
    const name = (n: string, bn: string | null) => (locale === 'bn' && bn ? bn : n);
    const line = (
        items: {
            name: string;
            name_bn: string | null;
            quantity: number;
            amount: string;
        }[]
    ) =>
        items.map((i, idx) => (
            <span key={`${i.name}-${idx}`}>
                {idx > 0 && ', '}
                {name(i.name, i.name_bn)} × {i.quantity} · <Taka amount={i.amount} />
            </span>
        ));

    return (
        <div className="flex flex-col gap-2 rounded-sm bg-subtle px-4 py-3 t-admin-body text-fg-primary">
            {o.recorded ? (
                <>
                    <p className="tabular">
                        {t('shipmentsBoard.outcome.delivered', {
                            count: o.delivered.reduce((n, i) => n + i.quantity, 0),
                        })}{' '}
                        — {line(o.delivered)}
                    </p>
                    <p className="tabular">
                        {t('shipmentsBoard.outcome.cameBack', {
                            count: o.came_back.reduce((n, i) => n + i.quantity, 0),
                        })}{' '}
                        — {line(o.came_back)}
                    </p>
                </>
            ) : (
                <p className="tabular">
                    {o.cod_collected !== null && (
                        <>
                            {t('shipmentsBoard.outcome.collected')} <Taka amount={o.cod_collected} /> {t('shipmentsBoard.detail.of')} <Taka amount={s.cod_amount} />.{' '}
                        </>
                    )}
                    {t('shipmentsBoard.outcome.notRecorded')}
                </p>
            )}
        </div>
    );
}

function RawEvents({ id, events }: { id: string; events: BoardEvent[] }) {
    const { t, locale } = useI18n();
    return (
        <div id={id} className="overflow-x-auto rounded-sm border border-line-default">
            <table className="w-full border-collapse t-admin-caption">
                <caption className="px-3 py-2 text-start t-admin-caption text-fg-secondary">{t('shipmentsBoard.raw.caption')}</caption>
                <thead className="bg-subtle">
                    <tr>
                        {(['status', 'provider', 'source', 'received', 'keys'] as const).map((k) => (
                            <th key={k} scope="col" className="h-8 px-3 text-start t-admin-overline text-fg-secondary">
                                {t(`shipmentsBoard.raw.${k}`)}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {events.map((e) => (
                        <tr key={e.id} className="border-t border-line-default">
                            <td className="px-3 py-2 t-admin-mono">
                                {e.from_status ? `${e.from_status} → ` : ''}
                                {e.status}
                            </td>
                            <td className="px-3 py-2 t-admin-mono">{e.provider_status ?? '—'}</td>
                            <td className="px-3 py-2">{e.source}</td>
                            <td className="px-3 py-2 tabular">
                                {dhaka(e.received_at, locale).day} {dhaka(e.received_at, locale).time}
                            </td>
                            <td className="px-3 py-2 t-admin-mono">{e.payload_keys.length === 0 ? '—' : e.payload_keys.join(', ')}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
