import { useId, type ReactNode } from 'react';
import { useSearchParams } from 'react-router';
import { CaretDownIcon, TruckIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { ExportMenu } from '@/components/ui/ExportMenu';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { TakaAmount } from '@/components/ui/TakaAmount';
import { formatCount, isZeroAmount, takaText } from '@/lib/money';
import { AnalyticsTabs } from '@/features/analytics/AnalyticsTabs';
import { PlainWords } from '@/features/analytics/PlainWords';
import { courierReportExportUrl, useCourierPerformance, type CourierFilters, type CourierPerformance, type Metrics } from './api';

const DATE = /^\d{4}-\d{2}-\d{2}$/;

/** Counts, lakh-grouped with Western digits (lib/money, rule 36). */
const count = formatCount;

/** "13910.00" → "৳13,910" — the admin's one formatter (lib/money, lakh grouping); a dash for null. */
function Money({ amount, signClass = 't-admin-currency-num' }: { amount: string | null; signClass?: string }) {
    if (amount === null) return <span className="text-fg-tertiary">—</span>;
    return (
        <span className="tabular whitespace-nowrap">
            <TakaAmount amount={amount} signClass={signClass} />
        </span>
    );
}

/** A server percentage string ("82.35") as "82.35%", or a dash when there is nothing to divide by. */
function Percent({ value }: { value: string | null }) {
    return value === null ? <span className="text-fg-tertiary">—</span> : <span className="tabular">{`${value}%`}</span>;
}

/** "02 Nov 2026" in the UI language, Western digits (rule 36). */
function formatDay(date: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC', numberingSystem: 'latn' }).format(new Date(`${date}T00:00:00Z`));
}

function dhakaToday(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

/** Figma StatCard 189:360 as on Analytics (B · 24 241:30584): overline label, large figure, caption. */
function Stat({ label, value, hint }: { label: string; value: ReactNode; hint: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-1.5 rounded-md border border-line-default bg-surface p-4 shadow-e1">
            <p className="t-admin-overline text-fg-secondary">{label}</p>
            <p className="t-admin-num-lg text-fg-primary">{value}</p>
            <p className="t-admin-caption text-fg-tertiary tabular">{hint}</p>
        </div>
    );
}

const columns: { key: keyof Metrics; label: MessageKey; kind: 'count' | 'percent' | 'days' | 'money' }[] = [
    { key: 'booked', label: 'courierReport.col.booked', kind: 'count' },
    { key: 'delivered', label: 'courierReport.col.delivered', kind: 'count' },
    { key: 'returned', label: 'courierReport.col.returned', kind: 'count' },
    { key: 'in_flight', label: 'courierReport.col.inFlight', kind: 'count' },
    { key: 'delivery_rate', label: 'courierReport.col.deliveryRate', kind: 'percent' },
    { key: 'average_days_to_deliver', label: 'courierReport.col.avgDays', kind: 'days' },
    { key: 'average_charge', label: 'courierReport.col.avgCharge', kind: 'money' },
    { key: 'cod_collected', label: 'courierReport.col.codCollected', kind: 'money' },
    { key: 'cod_outstanding', label: 'courierReport.col.codOutstanding', kind: 'money' },
];

function Cell({ row, column }: { row: Metrics; column: (typeof columns)[number] }) {
    const value = row[column.key];
    if (column.kind === 'count') return <>{count(value as number)}</>;
    if (column.kind === 'percent') return <Percent value={value as string | null} />;
    if (column.kind === 'days') return value === null ? <span className="text-fg-tertiary">—</span> : <span className="tabular">{value as string}</span>;
    return <Money amount={value as string | null} />;
}

function MetricsTable({ title, firstColumn, rows }: { title: string; firstColumn: string; rows: { key: string; name: string; metrics: Metrics }[] }) {
    const id = useId();
    return (
        <section aria-labelledby={id} className="flex flex-col gap-3">
            <h2 id={id} className="t-admin-section text-fg-primary">
                {title}
            </h2>
            <div className={dataTable.card}>
                <div className="overflow-x-auto">
                    <table className={dataTable.table}>
                        <thead className={dataTable.head}>
                            <tr className="border-b border-line-strong">
                                <th scope="col" className={dataTable.th}>
                                    {firstColumn}
                                </th>
                                {columns.map((c) => (
                                    <ColumnHead key={c.key} label={c.label} />
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.key} className={dataTable.row}>
                                    <th scope="row" className={`${dataTable.td} t-admin-body-strong text-start`}>
                                        {row.name}
                                    </th>
                                    {columns.map((c) => (
                                        <td key={c.key} className={`${dataTable.td} t-admin-num text-end`}>
                                            <Cell row={row.metrics} column={c} />
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    );
}

function ColumnHead({ label }: { label: MessageKey }) {
    const { t } = useI18n();
    return (
        <th scope="col" className={`${dataTable.th} text-end`}>
            {t(label)}
        </th>
    );
}

/**
 * Courier performance (06-admin-feature-checklist §2.3 / §2.x "[—] drawn as an Analytics tab" — no
 * frame of its own, so built in the B · 24 Analytics 241:30584 language: ContentHeader with the
 * period and Export, the tab strip, StatCards, DataTables). Per courier and per district for the
 * shipments handed over in a Dhaka-date period. Every figure is the server's (rule 1); filters live
 * in the URL.
 */
export function CourierPerformancePage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [params, setParams] = useSearchParams();
    const date = (key: string) => (DATE.test(params.get(key) ?? '') ? params.get(key)! : '');
    const provider = /^[a-z0-9_-]{1,24}$/.test(params.get('provider') ?? '') ? params.get('provider')! : '';
    const filters: CourierFilters = { from: date('from'), to: date('to'), provider };
    const report = useCourierPerformance(filters);
    const data = report.data;
    const ids = useId();
    const today = dhakaToday();

    const set = (entries: Record<string, string>) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                for (const [key, value] of Object.entries(entries)) {
                    if (value) next.set(key, value);
                    else next.delete(key);
                }
                return next;
            },
            { replace: true },
        );

    const name = (n: string, bn: string | null | undefined) => (locale === 'bn' && bn ? bn : n);
    const options = data ? data.providers.map((p) => p.provider) : [];

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('courierReport.crumb') }, { label: t('courierReport.title') }]}
                title={t('courierReport.title')}
                subtitle={data ? t('courierReport.subtitle', { from: formatDay(data.from, locale), to: formatDay(data.to, locale) }) : t('courierReport.subtitleLoading')}
                actions={
                    <>
                        <label htmlFor={`${ids}-provider`} className="sr-only">
                            {t('courierReport.provider')}
                        </label>
                        <Select id={`${ids}-provider`} value={provider} onChange={(e) => set({ provider: e.target.value })} className="w-44">
                            <option value="">{t('courierReport.allProviders')}</option>
                            {options.map((p) => (
                                <option key={p.code} value={p.code}>
                                    {name(p.name, p.name_bn)}
                                </option>
                            ))}
                        </Select>
                        <label htmlFor={`${ids}-from`} className="sr-only">
                            {t('reports.period.from')}
                        </label>
                        <div className="w-40 shrink-0">
                            <Input id={`${ids}-from`} type="date" max={filters.to || today} value={filters.from} onChange={(e) => set({ from: e.target.value })} className="tabular" />
                        </div>
                        <label htmlFor={`${ids}-to`} className="sr-only">
                            {t('reports.period.to')}
                        </label>
                        <div className="w-40 shrink-0">
                            <Input id={`${ids}-to`} type="date" min={filters.from} max={today} value={filters.to} onChange={(e) => set({ to: e.target.value })} className="tabular" />
                        </div>
                        {can(me, 'reports.export') && (
                            <ExportMenu href={(format, locale) => courierReportExportUrl(filters, format, locale)} />
                        )}
                    </>
                }
            />
            <AnalyticsTabs />

            {report.isError ? (
                <QueryError error={report.error} onRetry={() => void report.refetch()} />
            ) : !data ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('reports.loading')}
                </p>
            ) : data.totals.booked === 0 ? (
                <EmptyState title={t('courierReport.empty')} body={t('courierReport.emptyBody')} />
            ) : (
                <>
                    <PlainWords label={t('courierReport.plain.label')} lines={courierSentences(data, t, name)} />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <Stat label={t('courierReport.stat.booked')} value={count(data.totals.booked)} hint={t('courierReport.stat.bookedHint', { delivered: count(data.totals.delivered), returned: count(data.totals.returned), inFlight: count(data.totals.in_flight) })} />
                        <Stat label={t('courierReport.stat.deliveryRate')} value={<Percent value={data.totals.delivery_rate} />} hint={t('courierReport.stat.deliveryRateHint')} />
                        <Stat
                            label={t('courierReport.stat.avgDays')}
                            value={data.totals.average_days_to_deliver === null ? <span className="text-fg-tertiary">—</span> : t('courierReport.days', { days: data.totals.average_days_to_deliver })}
                            hint={
                                <>
                                    {t('courierReport.stat.avgCharge')} <Money amount={data.totals.average_charge} />
                                </>
                            }
                        />
                        <Stat
                            label={t('courierReport.stat.codCollected')}
                            value={<Money amount={data.totals.cod_collected} signClass="t-admin-currency-num-lg" />}
                            hint={
                                <>
                                    {t('courierReport.stat.codOutstanding')} <Money amount={data.totals.cod_outstanding} />
                                </>
                            }
                        />
                    </div>

                    <ul className="grid grid-cols-1 gap-4 lg:grid-cols-2" aria-label={t('courierReport.compare')}>
                        {data.providers.map((row) => (
                            <CourierCard key={row.provider.code} name={name(row.provider.name, row.provider.name_bn)} metrics={row} />
                        ))}
                    </ul>
                    <MetricsTable
                        title={t('courierReport.byDistrict')}
                        firstColumn={t('courierReport.col.district')}
                        rows={data.districts.map((row) => ({ key: String(row.district?.id ?? 0), name: row.district ? name(row.district.name, row.district.name_bn) : t('courierReport.noDistrict'), metrics: row }))}
                    />
                    <details className="group rounded-md border border-line-default bg-surface shadow-e1">
                        <summary className="flex cursor-pointer list-none items-center gap-3 px-5 py-4">
                            <span className="flex-1 t-admin-body-strong text-fg-primary">{t('courierReport.more')}</span>
                            <CaretDownIcon size={16} aria-hidden className="shrink-0 text-fg-tertiary transition-transform group-open:rotate-180" />
                        </summary>
                        <div className="border-t border-line-default p-5">
                            <MetricsTable
                                title={t('courierReport.byCourier')}
                                firstColumn={t('courierReport.col.courier')}
                                rows={data.providers.map((row) => ({ key: row.provider.code, name: name(row.provider.name, row.provider.name_bn), metrics: row }))}
                            />
                        </div>
                    </details>
                    <p className="t-admin-caption text-fg-secondary">{t('courierReport.note')}</p>
                </>
            )}
        </section>
    );
}

/** One courier side by side with the others (Figma 518:79744): delivered and came-back bars, then speed, cost and cash. */
function CourierCard({ name, metrics }: { name: string; metrics: Metrics }) {
    const { t } = useI18n();
    const bars: [MessageKey, string | null, string][] = [
        ['courierReport.card.delivered', metrics.delivery_rate, 'bg-feedback-success'],
        ['courierReport.card.returned', metrics.return_rate, 'bg-feedback-danger'],
    ];
    const tiles: [MessageKey, ReactNode][] = [
        ['courierReport.card.speed', metrics.average_days_to_deliver === null ? '—' : t('courierReport.days', { days: metrics.average_days_to_deliver })],
        ['courierReport.card.cost', <Money key="c" amount={metrics.average_charge} />],
        ['courierReport.card.collected', <Money key="cc" amount={metrics.cod_collected} />],
        ['courierReport.card.owed', <Money key="o" amount={metrics.cod_outstanding} />],
    ];
    return (
        <li className="flex flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1" aria-label={name}>
            <div className="flex items-center gap-3">
                <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-sm bg-subtle">
                    <TruckIcon size={18} className="text-fg-primary" />
                </span>
                <h3 className="flex-1 t-admin-section text-fg-primary">{name}</h3>
                <span className="t-admin-caption tabular text-fg-secondary">{t('courierReport.card.booked', { booked: count(metrics.booked), inFlight: count(metrics.in_flight) })}</span>
            </div>
            {bars.map(([label, rate, tone]) => (
                <div key={label} className="flex flex-col gap-1">
                    <p className="flex items-center justify-between gap-2">
                        <span className="t-admin-body text-fg-primary">{t(label)}</span>
                        <span className="t-admin-body-strong">
                            <Percent value={rate} />
                        </span>
                    </p>
                    <span aria-hidden className="block h-2 w-full overflow-hidden rounded-full bg-subtle">
                        <span className={`block h-full rounded-full ${tone}`} style={{ width: `${rate ?? '0'}%` }} />
                    </span>
                </div>
            ))}
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                {tiles.map(([label, value]) => (
                    <div key={label} className="flex flex-col gap-0.5 rounded-sm bg-subtle px-3 py-2">
                        <dt className="t-admin-caption text-fg-secondary">{t(label)}</dt>
                        <dd className="t-admin-num tabular text-fg-primary">{value}</dd>
                    </div>
                ))}
            </dl>
        </li>
    );
}

/**
 * The Couriers page's sentences (Figma 518:79744): how many parcels reached the buyer and came
 * back, each courier in one clause, and the cash still with the couriers. Server figures, in words.
 */
function courierSentences(data: CourierPerformance, t: (k: MessageKey, p?: Record<string, string | number>) => string, name: (n: string, bn: string | null | undefined) => string): string[] {
    const lines: string[] = [];
    const tot = data.totals;
    if (tot.delivery_rate !== null) lines.push(t('courierReport.plain.rate', { rate: tot.delivery_rate, returned: count(tot.returned), returnRate: tot.return_rate ?? '0' }));
    const each = data.providers
        .filter((p) => p.booked > 0 && p.delivery_rate !== null)
        .map((p) =>
            p.average_days_to_deliver === null
                ? t('courierReport.plain.courier', { name: name(p.provider.name, p.provider.name_bn), rate: p.delivery_rate ?? '' })
                : t('courierReport.plain.courierDays', { name: name(p.provider.name, p.provider.name_bn), rate: p.delivery_rate ?? '', days: p.average_days_to_deliver }),
        );
    if (each.length > 0) lines.push(`${each.join('; ')}.`);
    lines.push(isZeroAmount(tot.cod_outstanding) ? t('courierReport.plain.cashClear') : t('courierReport.plain.cashOwed', { amount: takaText(tot.cod_outstanding) }));
    return lines;
}
