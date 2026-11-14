import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { Bar, BarChart, CartesianGrid, Cell, LabelList, ResponsiveContainer, XAxis, YAxis } from 'recharts';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { useBoardIssues, type BoardIssues as Issues } from './boardApi';
import { Taka } from './boardParts';

/** The courier-area mapping screen (rule 32) — never guess a zone, map it. */
const LOCATION_MAPPING_PATH = '/shipments/locations';

/**
 * Delivery issues, last 30 days (Figma B · 08 side panel): parcels that came back, failed
 * attempts, bookings blocked by an unmapped area (linking to the mapping screen), then
 * "Delivery success by courier" — one bar per courier × area that had an outcome, never a
 * placeholder bar. Figma's "refused at door" reads "came back": the couriers report a return,
 * not whether the customer refused it.
 */
export function BoardIssues() {
    const { t } = useI18n();
    const issues = useBoardIssues();

    return (
        <section className="flex flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1" aria-labelledby="delivery-issues">
            <div className="flex flex-col gap-0.5">
                <h2 id="delivery-issues" className="t-admin-section text-fg-primary">
                    {t('shipmentsBoard.issues.title')}
                </h2>
                <p className="t-admin-caption text-fg-tertiary">{t('shipmentsBoard.issues.window')}</p>
            </div>
            {issues.isError ? (
                <QueryError error={issues.error} onRetry={() => void issues.refetch()} />
            ) : !issues.data ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : (
                <>
                    <IssueList data={issues.data} />
                    <SuccessChart data={issues.data} />
                </>
            )}
        </section>
    );
}

function Callout({ tone, title, body }: { tone: 'danger' | 'warning'; title: string; body: ReactNode }) {
    return (
        <li className={`flex flex-col gap-1 rounded-sm border-l-2 bg-subtle px-3.5 py-3 ${tone === 'danger' ? 'border-l-feedback-danger' : 'border-l-feedback-warning'}`}>
            <p className="t-admin-body-strong text-fg-primary">{title}</p>
            <p className="t-admin-caption text-fg-secondary">{body}</p>
        </li>
    );
}

function IssueList({ data }: { data: Issues }) {
    const { t } = useI18n();
    const { returned, failed_attempts: failed, unmapped } = data;
    if (returned.count === 0 && failed.count === 0 && unmapped.count === 0) {
        return <EmptyState reason="allClear" title={t('shipmentsBoard.issues.none')} className="border-none px-2 py-4" />;
    }

    return (
        <ul className="flex flex-col gap-3">
            {returned.count > 0 && (
                <Callout
                    tone="danger"
                    title={t('shipmentsBoard.issues.returned', {
                        count: returned.count,
                    })}
                    body={
                        <span className="tabular">
                            {returned.districts.length > 0 && (
                                <>
                                    {t('shipmentsBoard.issues.returnedIn', {
                                        places: returned.districts.join('/'),
                                    })}{' '}
                                    ·{' '}
                                </>
                            )}
                            <Taka amount={returned.cost} /> {t('shipmentsBoard.issues.returnedCost')}
                        </span>
                    }
                />
            )}
            {failed.count > 0 && (
                <Callout
                    tone="warning"
                    title={t('shipmentsBoard.issues.failed', {
                        count: failed.count,
                    })}
                    body={
                        failed.latest_provider_status
                            ? t('shipmentsBoard.issues.failedLatest', {
                                  status: failed.latest_provider_status,
                              })
                            : t('shipmentsBoard.issues.failedBody')
                    }
                />
            )}
            {unmapped.count > 0 && (
                <Callout
                    tone="warning"
                    title={t('shipmentsBoard.issues.unmapped', {
                        count: unmapped.count,
                    })}
                    body={
                        <>
                            {unmapped.areas.length > 0 && <>{unmapped.areas.join('; ')} · </>}
                            <Link to={LOCATION_MAPPING_PATH} className="text-fg-link underline">
                                {t('shipmentsBoard.issues.mapIt')}
                            </Link>
                        </>
                    }
                />
            )}
        </ul>
    );
}

/** Bar fill per area: Dhaka solid brand, outside a brand outline, unknown neutral — the label carries it too, never colour alone. */
const areaFill: Record<string, { fill: string; stroke: string }> = {
    dhaka: { fill: 'var(--color-brand)', stroke: 'var(--color-brand)' },
    outside: {
        fill: 'var(--color-brand-subtle)',
        stroke: 'var(--color-brand)',
    },
    unknown: {
        fill: 'var(--color-subtle)',
        stroke: 'var(--color-line-strong)',
    },
};

type ChartBar = { key: string; name: string; areaLabel: string };

/** Axis text: Admin/caption, text/tertiary — courier on one line, area on the next. */
function CourierTick({ x, y, payload, bars }: { x?: number | string; y?: number | string; payload?: { value: string | number }; bars: ChartBar[] }) {
    const bar = bars.find((b) => b.key === payload?.value);
    return (
        <text x={x} y={Number(y ?? 0) + 10} textAnchor="middle" className="t-admin-caption" fill="var(--color-fg-tertiary)">
            <tspan x={x} dy="0">
                {bar?.name}
            </tspan>
            <tspan x={x} dy="12">
                {bar?.areaLabel}
            </tspan>
        </text>
    );
}

function PercentTick({ x, y, payload }: { x?: number | string; y?: number | string; payload?: { value: string | number } }) {
    return (
        <text x={x} y={Number(y ?? 0) + 4} dx={-4} textAnchor="end" className="t-admin-caption tabular" fill="var(--color-fg-tertiary)">
            {`${payload?.value ?? ''}%`}
        </text>
    );
}

function SuccessChart({ data }: { data: Issues }) {
    const { t, locale } = useI18n();
    const bars = data.by_courier.map((b) => ({
        key: `${b.provider.code}-${b.area}`,
        name: locale === 'bn' && b.provider.name_bn ? b.provider.name_bn : b.provider.name,
        area: b.area,
        areaLabel: t(`shipmentsBoard.area.${b.area}` as MessageKey),
        rate: Number(b.rate),
        rateText: `${Math.round(Number(b.rate))}%`,
        b,
    }));

    return (
        <figure className="flex flex-col gap-2 rounded-sm border border-line-default p-3">
            <figcaption className="flex flex-col">
                <span className="t-admin-caption-strong text-fg-primary">{t('shipmentsBoard.chart.title')}</span>
                <span className="t-admin-caption text-fg-tertiary">{t('shipmentsBoard.chart.subtitle')}</span>
            </figcaption>
            {bars.length === 0 ? (
                <p className="t-admin-caption text-fg-secondary">{t('shipmentsBoard.chart.empty')}</p>
            ) : (
                <>
                    <div className="h-40" aria-hidden>
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart
                                data={bars}
                                margin={{
                                    top: 16,
                                    right: 4,
                                    bottom: 0,
                                    left: 0,
                                }}
                            >
                                <CartesianGrid vertical={false} stroke="var(--color-line-default)" />
                                <XAxis dataKey="key" tickLine={false} axisLine={false} interval={0} height={30} tick={<CourierTick bars={bars} />} />
                                <YAxis width={48} domain={[0, 100]} ticks={[0, 25, 50, 75, 100]} tickLine={false} axisLine={false} tick={<PercentTick />} />
                                <Bar dataKey="rate" maxBarSize={28} radius={[2, 2, 0, 0]} isAnimationActive={false}>
                                    {bars.map((bar) => (
                                        <Cell key={bar.key} fill={areaFill[bar.area]?.fill ?? areaFill.unknown!.fill} stroke={areaFill[bar.area]?.stroke ?? areaFill.unknown!.stroke} strokeWidth={1} />
                                    ))}
                                    <LabelList dataKey="rateText" position="top" className="t-admin-caption" fill="var(--color-fg-secondary)" />
                                </Bar>
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                    {/* The chart's numbers as a table for screen readers and tests (the SVG is decorative). */}
                    <div className="sr-only">
                        <table>
                            <caption>{t('shipmentsBoard.chart.title')}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{t('shipmentsBoard.chart.courier')}</th>
                                    <th scope="col">{t('shipmentsBoard.chart.rate')}</th>
                                    <th scope="col">{t('shipmentsBoard.chart.counts')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {bars.map((bar) => (
                                    <tr key={bar.key}>
                                        <th scope="row">
                                            {bar.name} {bar.areaLabel}
                                        </th>
                                        <td>{`${bar.b.rate}%`}</td>
                                        <td>
                                            {t('shipmentsBoard.chart.countsValue', {
                                                delivered: bar.b.delivered,
                                                returned: bar.b.returned,
                                            })}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </figure>
    );
}
