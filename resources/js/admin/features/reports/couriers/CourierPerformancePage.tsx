import { useId, type ReactNode } from 'react';
import { DownloadSimpleIcon } from '@phosphor-icons/react';
import { useSearchParams } from 'react-router';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { AnalyticsTabs } from '@/features/analytics/AnalyticsTabs';
import { courierReportExportUrl, useCourierPerformance, type CourierFilters, type Metrics } from './api';

const TAKA = '৳';
const MINUS = '−';
const DATE = /^\d{4}-\d{2}-\d{2}$/;

/** "13910.00" → "13,910": groups the server's decimal string, never parses it into a float (rule 1). */
export function formatAmount(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    const trimmed = fraction.replace(/0+$/, '');
    return `${negative ? MINUS : ''}${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}${trimmed ? `.${trimmed}` : ''}`;
}

/** Western digits with grouping in both locales (rule 36). */
const count = (n: number) => new Intl.NumberFormat('en-US').format(n);

function Money({ amount, signClass = 't-admin-currency-num' }: { amount: string | null; signClass?: string }) {
    if (amount === null) return <span className="text-fg-tertiary">—</span>;
    return (
        <span className="tabular whitespace-nowrap">
            <span className={signClass}>{TAKA}</span>
            {formatAmount(amount)}
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
                            <a href={courierReportExportUrl(filters)} download className={buttonClass('outline')}>
                                <DownloadSimpleIcon size={16} aria-hidden />
                                {t('reports.export')}
                            </a>
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

                    <MetricsTable
                        title={t('courierReport.byCourier')}
                        firstColumn={t('courierReport.col.courier')}
                        rows={data.providers.map((row) => ({ key: row.provider.code, name: name(row.provider.name, row.provider.name_bn), metrics: row }))}
                    />
                    <MetricsTable
                        title={t('courierReport.byDistrict')}
                        firstColumn={t('courierReport.col.district')}
                        rows={data.districts.map((row) => ({ key: String(row.district?.id ?? 0), name: row.district ? name(row.district.name, row.district.name_bn) : t('courierReport.noDistrict'), metrics: row }))}
                    />
                    <p className="t-admin-caption text-fg-secondary">{t('courierReport.note')}</p>
                </>
            )}
        </section>
    );
}
