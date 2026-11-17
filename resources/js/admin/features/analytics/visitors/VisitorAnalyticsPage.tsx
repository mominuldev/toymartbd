import { useId, type CSSProperties, type ReactNode } from 'react';
import { ChartBarIcon, InfoIcon, PercentIcon, ShoppingCartIcon, TruckIcon, WarningIcon } from '@phosphor-icons/react';
import { useSearchParams } from 'react-router';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { formatCount } from '@/lib/money';
import { AnalyticsTabs } from '../AnalyticsTabs';
import { PlainWords, change } from '../PlainWords';
import { Delta, Percent, dhakaToday, formatDay } from '../sales/format';
import { rangePresets, useVisitorAnalytics, type FunnelStep, type RangePreset, type VisitorAnalytics, type VisitorFilters } from './api';

const DATE = /^\d{4}-\d{2}-\d{2}$/;

type T = ReturnType<typeof useI18n>['t'];

/** Filters live in the URL so the report is a shareable link; the server validates every value. */
function useFilters(): [VisitorFilters, (entries: Record<string, string>) => void] {
    const [params, setParams] = useSearchParams();
    const date = (key: string) => (DATE.test(params.get(key) ?? '') ? params.get(key)! : '');
    const rangeParam = params.get('range') as RangePreset | null;
    const filters: VisitorFilters = {
        range: rangeParam && rangePresets.includes(rangeParam) ? rangeParam : '7d',
        from: date('from'),
        to: date('to'),
    };
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
    return [filters, set];
}

/** A server percent string without trailing zeros ("41.00" → "41", "0.63" → "0.63"). Display only. */
function trim(value: string): string {
    return value.replace(/^-/, '').replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
}

/**
 * Analytics › Visitors & funnel (Figma B · 24 premium pass 522:79774 desktop, 522:80278 mobile).
 * Every count and percentage — including which step drops the most — comes from the server
 * (rule 1); days are Dhaka days (rule 11). Bar heights and widths are display proportions only.
 * Only analytics-consented sessions are counted, and the page says so; no device or place data
 * exists, and the page says that too.
 */
export function VisitorAnalyticsPage() {
    const { t, locale } = useI18n();
    const [filters, set] = useFilters();
    const report = useVisitorAnalytics(filters);
    const data = report.data;
    const ids = useId();
    const today = dhakaToday();

    const range = data ? (data.range.from === data.range.to ? formatDay(data.range.from, locale) : `${formatDay(data.range.from, locale, false)} – ${formatDay(data.range.to, locale)}`) : '';

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('courierReport.crumb') }]}
                title={t('analyticsVisitors.title')}
                subtitle={t('analyticsVisitors.subtitle')}
                actions={
                    <>
                        <label htmlFor={`${ids}-range`} className="sr-only">
                            {t('analyticsVisitors.period')}
                        </label>
                        <Select
                            id={`${ids}-range`}
                            value={filters.range}
                            onChange={(e) => {
                                const next = e.target.value as RangePreset;
                                set(next === 'custom' ? { range: next, from: filters.from || data?.range.from || today, to: filters.to || data?.range.to || today } : { range: next === '7d' ? '' : next, from: '', to: '' });
                            }}
                            className="w-48"
                        >
                            {rangePresets.map((p) => (
                                <option key={p} value={p}>
                                    {t(`analyticsVisitors.range.${p}` as MessageKey)}
                                </option>
                            ))}
                        </Select>
                        {filters.range === 'custom' && (
                            <>
                                <label htmlFor={`${ids}-from`} className="sr-only">
                                    {t('analyticsVisitors.from')}
                                </label>
                                <div className="w-40 shrink-0">
                                    <Input id={`${ids}-from`} type="date" max={filters.to || today} value={filters.from} onChange={(e) => set({ from: e.target.value })} className="tabular" />
                                </div>
                                <label htmlFor={`${ids}-to`} className="sr-only">
                                    {t('analyticsVisitors.to')}
                                </label>
                                <div className="w-40 shrink-0">
                                    <Input id={`${ids}-to`} type="date" min={filters.from} max={today} value={filters.to} onChange={(e) => set({ to: e.target.value })} className="tabular" />
                                </div>
                            </>
                        )}
                    </>
                }
            />
            <AnalyticsTabs />

            {report.isError ? (
                <QueryError error={report.error} onRetry={() => void report.refetch()} />
            ) : !data ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('analyticsVisitors.loading')}
                </p>
            ) : data.summary.visits === 0 ? (
                <>
                    <EmptyState title={t('analyticsVisitors.empty')} body={t('analyticsVisitors.emptyBody', { range })} />
                    <DevicesNote data={data} />
                </>
            ) : (
                <>
                    <PlainWords label={t('analyticsVisitors.plain.label', { range })} lines={sentences(data, t)} />
                    <Stats data={data} />
                    <FunnelCard data={data} />
                    <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        <DailyCard data={data} />
                        <ProductsCard data={data} />
                    </div>
                    <DevicesNote data={data} />
                </>
            )}
        </section>
    );
}

/** The three "In plain words" sentences, built only from the server's figures. */
function sentences(data: VisitorAnalytics, t: T): string[] {
    const s = data.summary;
    const lines: string[] = [];
    const base = { visits: formatCount(s.visits), placed: formatCount(s.placed), rate: s.order_rate ?? '0.00' };
    const moved = change(data.deltas.order_rate_points);
    if (moved && data.previous.order_rate !== null) {
        lines.push(t(moved.up ? 'analyticsVisitors.plain.ordersUp' : 'analyticsVisitors.plain.ordersDown', { ...base, previous: data.previous.order_rate }));
    } else {
        lines.push(t('analyticsVisitors.plain.orders', base));
    }

    const drop = data.biggest_drop;
    if (drop) {
        const before = data.funnel.find((step) => step.key === drop.from)?.sessions ?? 0;
        const after = data.funnel.find((step) => step.key === drop.to)?.sessions ?? 0;
        lines.push(
            t('analyticsVisitors.plain.drop', {
                from: t(`analyticsVisitors.short.${drop.from}` as MessageKey),
                to: t(`analyticsVisitors.short.${drop.to}` as MessageKey),
                before: formatCount(before),
                after: formatCount(after),
                percent: trim(drop.percent),
            }),
        );
    } else {
        lines.push(t('analyticsVisitors.plain.noDrop'));
    }

    lines.push(data.tracking.page_views_recorded ? t('analyticsVisitors.plain.counted') : `${t('analyticsVisitors.plain.counted')} ${t('analyticsVisitors.plain.noPageViews')}`);
    return lines;
}

/** Figma StatCard: overline + icon tile, figure, delta, caption. Compact (2×2) below sm. */
function Stat({ label, icon, value, delta, hint }: { label: string; icon: ReactNode; value: ReactNode; delta?: ReactNode; hint: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-1.5 rounded-md border border-line-default bg-surface p-3 shadow-e1 sm:p-4">
            <div className="flex items-start justify-between gap-2">
                <p className="t-admin-overline text-fg-secondary">{label}</p>
                <span aria-hidden className="hidden size-8 shrink-0 place-items-center rounded-sm bg-brand-subtle text-fg-brand sm:grid">
                    {icon}
                </span>
            </div>
            <p className="t-admin-num-lg text-fg-primary">{value}</p>
            {delta}
            <p className="t-admin-caption text-fg-tertiary tabular">{hint}</p>
        </div>
    );
}

function Stats({ data }: { data: VisitorAnalytics }) {
    const { t } = useI18n();
    const s = data.summary;
    const vs = t('analyticsVisitors.vsPrevious');
    const pts = t('analyticsVisitors.points');
    return (
        <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <Stat
                label={t('analyticsVisitors.stat.visits')}
                icon={<ChartBarIcon size={16} />}
                value={<span className="tabular">{formatCount(s.visits)}</span>}
                delta={<Delta value={data.deltas.visits} caption={vs} />}
                hint={t('analyticsVisitors.stat.visitsHint')}
            />
            <Stat
                label={t('analyticsVisitors.stat.orderRate')}
                icon={<PercentIcon size={16} />}
                value={<Percent value={s.order_rate} />}
                delta={<Delta value={data.deltas.order_rate_points} suffix={pts} caption={vs} />}
                hint={t('analyticsVisitors.stat.orderRateHint', { placed: formatCount(s.placed), visits: formatCount(s.visits) })}
            />
            <Stat
                label={t('analyticsVisitors.stat.cartToOrder')}
                icon={<ShoppingCartIcon size={16} />}
                value={<Percent value={s.cart_to_order_rate} />}
                delta={<Delta value={data.deltas.cart_to_order_points} suffix={pts} caption={vs} />}
                hint={t('analyticsVisitors.stat.cartToOrderHint', { placed: formatCount(s.placed), carts: formatCount(s.added_to_cart) })}
            />
            <Stat
                label={t('analyticsVisitors.stat.delivered')}
                icon={<TruckIcon size={16} />}
                value={<Percent value={s.delivered_rate} />}
                delta={<Delta value={data.deltas.delivered_rate_points} suffix={pts} caption={vs} />}
                hint={t('analyticsVisitors.stat.deliveredHint', { delivered: formatCount(s.delivered), open: formatCount(s.in_progress) })}
            />
        </div>
    );
}

function Card({ title, subtitle, children, className = '' }: { title: string; subtitle?: string; children: ReactNode; className?: string }) {
    const id = useId();
    return (
        <section aria-labelledby={id} className={`flex min-w-0 flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1 ${className}`}>
            <header className="flex min-w-0 flex-col gap-0.5">
                <h2 id={id} className="t-admin-card-title text-fg-primary">
                    {title}
                </h2>
                {subtitle && <p className="t-admin-caption text-fg-tertiary">{subtitle}</p>}
            </header>
            {children}
        </section>
    );
}

/** A bar's share of the largest, as a CSS length — a display proportion, never a reported figure. */
function share(value: number, max: number): CSSProperties {
    const percent = max > 0 ? Math.max((value / max) * 100, value > 0 ? 2 : 0) : 0;
    return { '--bar': `${percent}%` } as CSSProperties;
}

/** The six steps: vertical bars from sm up (desktop frame), horizontal bars below (390 frame). */
function FunnelCard({ data }: { data: VisitorAnalytics }) {
    const { t } = useI18n();
    const max = Math.max(...data.funnel.map((step) => step.sessions), 0);
    const drop = data.biggest_drop;
    return (
        <Card title={t('analyticsVisitors.funnel.title')} subtitle={t('analyticsVisitors.funnel.subtitle')}>
            <ol className="grid grid-cols-1 gap-4 sm:grid-cols-6 sm:gap-3">
                {data.funnel.map((step, index) => (
                    <li key={step.key} data-step={step.key} className="flex min-w-0 flex-col gap-1.5 sm:justify-end">
                        <p className="flex flex-wrap items-center gap-2">
                            <span className="t-admin-body-strong tabular text-fg-primary">{formatCount(step.sessions)}</span>
                            {index > 0 && <DropChip value={step.drop_percent} />}
                        </p>
                        <div className="flex h-2 w-full items-end rounded-xs bg-canvas sm:h-40 sm:bg-transparent" style={share(step.sessions, max)}>
                            <div aria-hidden className="h-full w-[var(--bar)] rounded-xs bg-brand sm:h-[var(--bar)] sm:w-full" />
                        </div>
                        <p className="t-admin-body text-fg-primary">{t(`analyticsVisitors.step.${step.key}` as MessageKey)}</p>
                        {step.of_visits_percent !== null && <p className="t-admin-caption tabular text-fg-tertiary">{t('analyticsVisitors.funnel.ofVisits', { percent: trim(step.of_visits_percent) })}</p>}
                    </li>
                ))}
            </ol>
            {drop && (
                <p className="flex items-start gap-2 rounded-sm bg-warning-subtle px-3 py-2 t-admin-body text-fg-primary">
                    <WarningIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-feedback-warning" />
                    {t('analyticsVisitors.funnel.insight', { from: shortStep(drop.from, t), to: shortStep(drop.to, t), percent: trim(drop.percent) })}
                </p>
            )}
            {!data.tracking.order_link_complete && <p className="t-admin-caption text-fg-secondary">{t('analyticsVisitors.funnel.oldRange', { days: formatCount(data.tracking.order_link_retention_days) })}</p>}
        </Card>
    );
}

const shortStep = (step: FunnelStep, t: T) => t(`analyticsVisitors.short.${step}` as MessageKey);

/** "−41%" vs the step before (the server's drop). A step that grew shows "+". */
function DropChip({ value }: { value: string | null }) {
    const { t } = useI18n();
    if (value === null || Number(value) === 0) return null;
    const grew = value.startsWith('-');
    const text = `${grew ? '+' : '−'}${trim(value)}%`;
    return (
        <span className={`rounded-xs px-1.5 t-admin-caption-strong tabular ${grew ? 'bg-brand-subtle text-fg-brand' : 'bg-danger-subtle text-feedback-danger'}`} title={grew ? undefined : t('analyticsVisitors.funnel.dropLabel', { percent: trim(value) })}>
            {text}
        </span>
    );
}

/** Visits per Dhaka day, with that day's orders above each bar. */
function DailyCard({ data }: { data: VisitorAnalytics }) {
    const { t, locale } = useI18n();
    const max = Math.max(...data.daily.map((d) => d.visits), 0);
    const dense = data.daily.length > 14;
    const every = Math.ceil(data.daily.length / 7);
    return (
        <Card title={t('analyticsVisitors.daily.title')} subtitle={t('analyticsVisitors.daily.subtitle')}>
            <ol className="flex h-48 items-end gap-1 sm:gap-2">
                {data.daily.map((day, index) => {
                    const label = formatDay(day.date, locale, false);
                    return (
                        <li key={day.date} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1" aria-label={t('analyticsVisitors.daily.row', { day: label, visits: formatCount(day.visits), orders: formatCount(day.orders) })}>
                            {!dense && (
                                <span aria-hidden className="t-admin-caption tabular whitespace-nowrap text-fg-secondary">
                                    {t('analyticsVisitors.daily.orders', { orders: formatCount(day.orders) })}
                                </span>
                            )}
                            <div aria-hidden className="flex w-full flex-1 items-end" style={share(day.visits, max)}>
                                <div className="h-[var(--bar)] w-full rounded-xs bg-brand-subtle" />
                            </div>
                            <span aria-hidden className={`t-admin-caption tabular whitespace-nowrap text-fg-tertiary ${dense && index % every !== 0 ? 'invisible' : ''}`}>
                                {label}
                            </span>
                        </li>
                    );
                })}
            </ol>
        </Card>
    );
}

function ProductsCard({ data }: { data: VisitorAnalytics }) {
    const { t, locale } = useI18n();
    return (
        <Card title={t('analyticsVisitors.products.title')} subtitle={t('analyticsVisitors.products.subtitle')}>
            {data.top_products.length === 0 ? (
                <p className="t-admin-body text-fg-tertiary">{t('analyticsVisitors.products.empty')}</p>
            ) : (
                <ul className="flex flex-col divide-y divide-line-default border-t border-line-default">
                    {data.top_products.map((p) => (
                        <li key={p.product_id} className="flex items-center gap-3 py-3">
                            <span className={`min-w-0 flex-1 truncate t-admin-body text-fg-primary ${locale === 'bn' && p.name_bn ? 'lang-bn' : ''}`}>{locale === 'bn' && p.name_bn ? p.name_bn : p.name}</span>
                            <span className="t-admin-caption tabular whitespace-nowrap text-fg-tertiary">{t('analyticsVisitors.products.views', { views: formatCount(p.views) })}</span>
                            <span className="w-20 text-end t-admin-caption-strong tabular whitespace-nowrap text-fg-primary">{t('analyticsVisitors.products.carts', { carts: formatCount(p.add_to_carts) })}</span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/** The dashed note: no device or place data exists, and events without a visit id are not counted. */
function DevicesNote({ data }: { data: VisitorAnalytics }) {
    const { t } = useI18n();
    return (
        <aside className="flex items-start gap-3 rounded-md border border-dashed border-line-strong p-5">
            <InfoIcon size={18} aria-hidden className="mt-0.5 shrink-0 text-fg-tertiary" />
            <div className="flex min-w-0 flex-col gap-1">
                <p className="t-admin-body-strong text-fg-primary">{t('analyticsVisitors.note.title')}</p>
                <p className="t-admin-body text-fg-secondary">{t('analyticsVisitors.note.body')}</p>
                {data.tracking.events_without_session > 0 && <p className="t-admin-caption text-fg-tertiary">{t('analyticsVisitors.note.unlinked', { count: formatCount(data.tracking.events_without_session) })}</p>}
            </div>
        </aside>
    );
}
