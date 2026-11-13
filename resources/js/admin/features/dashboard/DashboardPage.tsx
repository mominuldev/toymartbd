import type { ReactNode } from 'react';
import { useMe } from '@/features/auth/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { QueryError } from '@/components/ui/QueryError';
import { useDashboard, useDashboardRange, type Dashboard } from './api';
import { BestSellersCard, CategoryDonut, LowStockCard, TrendCard } from './cards';
import { formatCount, moneyText, onePlace, rangeText, dhakaHour } from './format';
import { ActivityFeed, AttentionStack, Delta, Money, RangePicker, Sparkline, StatCard, StatusStrip } from './parts';

/** Today's Dhaka date (rule 11) — the date inputs' upper bound only. */
function dhakaToday(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

const numbers = (points: { value: string | null }[]) => points.map((p) => (p.value === null ? 0 : Number(p.value)));

/**
 * Admin dashboard — Figma B · 01 Dashboard (241:29848), 1024 (226:18450), 768 (229:21176);
 * 04-admin-spec B1. Every figure is the server's (GET /dashboard); this page only lays them out.
 * Blocks the viewer's role may not see come back null and are simply not drawn.
 */
export function DashboardPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { range, setRange } = useDashboardRange();
    const query = useDashboard(range);
    const data = query.data;

    const hour = dhakaHour();
    const part = hour < 12 ? 'morning' : hour < 17 ? 'afternoon' : 'evening';
    const shown = data ? { from: data.range.from, to: data.range.to } : { from: range.from, to: range.to };
    const summary = data ? t('dash.range.summary', { preset: t(`dash.range.${data.range.preset}` as MessageKey), dates: rangeText(data.range.from, data.range.to, locale) }) : '';

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                title={t('dash.title')}
                subtitle={data ? t(`dash.greeting.${part}` as MessageKey, { name: me?.name ?? '', range: summary }) : undefined}
                actions={<RangePicker range={range} shown={shown} onChange={setRange} today={dhakaToday()} />}
            />
            {query.isError && !data ? (
                <QueryError error={query.error} onRetry={() => void query.refetch()} />
            ) : !data ? (
                <p className="t-admin-body text-fg-secondary" role="status">
                    {t('dash.loading')}
                </p>
            ) : (
                <DashboardBody data={data} />
            )}
        </section>
    );
}

function DashboardBody({ data }: { data: Dashboard }) {
    const { t } = useI18n();
    const nothing = !data.visibility.orders && !data.visibility.finance && !data.visibility.customers && !data.visibility.inventory && data.attention.length === 0;

    if (nothing) return <p className="t-admin-body text-fg-secondary">{t('dash.nothingVisible')}</p>;

    return (
        <>
            {/* 768: the operator checks what needs doing before reading totals (229:21176). */}
            {data.attention.length > 0 && (
                <div className="lg:hidden">
                    <AttentionStack items={data.attention} compact />
                </div>
            )}
            <KpiGrid data={data} />
            {data.status !== null && <StatusStrip counts={data.status.counts} />}
            {(data.trend !== null || data.categories !== null) && (
                <div className="grid gap-6 xl:grid-cols-3">
                    {data.trend !== null && (
                        <div className={data.categories !== null ? 'min-w-0 xl:col-span-2' : 'min-w-0 xl:col-span-3'}>
                            <TrendCard trend={data.trend} range={data.range} />
                        </div>
                    )}
                    {data.categories !== null && (
                        <div className="min-w-0 self-start">
                            <CategoryDonut categories={data.categories} />
                        </div>
                    )}
                </div>
            )}
            {(data.best_sellers !== null || data.low_stock !== null) && (
                <div className="grid items-start gap-6 xl:grid-cols-2">
                    {data.best_sellers !== null && <BestSellersCard best={data.best_sellers} range={data.range} />}
                    {data.low_stock !== null && <LowStockCard low={data.low_stock} />}
                </div>
            )}
            {(data.activity !== null || data.attention.length > 0) && (
                <div className="grid items-start gap-6 xl:grid-cols-3">
                    {data.activity !== null && (
                        <div className="min-w-0 xl:col-span-2">
                            <ActivityFeed events={data.activity} />
                        </div>
                    )}
                    {data.attention.length > 0 && (
                        <div className="hidden min-w-0 lg:block">
                            <AttentionStack items={data.attention} />
                        </div>
                    )}
                </div>
            )}
        </>
    );
}

/** KPI rows (241:29861, 241:29915): 4-up at 1440, 3-up at 1024, 2-up under. */
function KpiGrid({ data }: { data: Dashboard }) {
    const { t, locale } = useI18n();
    const { kpis, previous } = data;
    const vsRange = t('dash.kpi.vsRange', { range: rangeText(previous.from, previous.to, locale) });
    const cards: ReactNode[] = [];

    if (kpis.orders_placed !== null) {
        const placed = kpis.orders_placed;
        cards.push(
            <StatCard
                key="value"
                label={t('dash.kpi.placedValue')}
                to="/orders"
                value={<Money amount={placed.value} signClass="t-admin-currency-num-lg" />}
                delta={<Delta percent={placed.value_delta_percent} caption={vsRange} />}
                sparkline={<Sparkline values={numbers(placed.sparkline)} label={t('dash.kpi.placedValue')} />}
            />,
            <StatCard
                key="orders"
                label={t('dash.kpi.ordersPlaced')}
                to="/orders"
                value={<span className="tabular">{formatCount(placed.count)}</span>}
                delta={<Delta percent={placed.count_delta_percent} caption={t('dash.kpi.vs', { value: formatCount(placed.previous_count) })} />}
                sparkline={<Sparkline values={placed.sparkline.map((p) => p.count)} label={t('dash.kpi.ordersPlaced')} />}
            />,
        );
    }
    if (kpis.recognised_revenue !== null) {
        const revenue = kpis.recognised_revenue;
        const open = data.status?.open;
        cards.push(
            <StatCard
                key="revenue"
                label={t('dash.kpi.revenue')}
                info={open ? t('dash.kpi.revenueInfo', { amount: moneyText(open.value) }) : undefined}
                to="/finance/pnl"
                value={<Money amount={revenue.value} signClass="t-admin-currency-num-lg" />}
                delta={<Delta percent={revenue.delta_percent} caption={t('dash.kpi.vs', { value: moneyText(revenue.previous) })} />}
                sparkline={<Sparkline values={numbers(revenue.sparkline)} label={t('dash.kpi.revenue')} />}
            />,
        );
    }
    if (kpis.net_profit !== null) {
        const profit = kpis.net_profit;
        const gross = kpis.gross_profit;
        const margin = profit.margin_percent === null ? '' : ` · ${t('dash.kpi.margin', { value: onePlace(profit.margin_percent) })}`;
        cards.push(
            <StatCard
                key="profit"
                label={t('dash.kpi.netProfit')}
                to="/finance/pnl"
                value={<Money amount={profit.value} signClass="t-admin-currency-num-lg" />}
                delta={<Delta percent={profit.delta_percent} caption={`${t('dash.kpi.vs', { value: moneyText(profit.previous) })}${margin}`} />}
                footnote={
                    gross !== null ? (
                        <span>
                            {t('dash.kpi.grossProfit', { amount: moneyText(gross.value) })}
                            {gross.margin_percent !== null && ` · ${t('dash.kpi.margin', { value: onePlace(gross.margin_percent) })}`}
                        </span>
                    ) : undefined
                }
                sparkline={<Sparkline values={numbers(profit.sparkline)} label={t('dash.kpi.netProfit')} />}
            />,
        );
    }
    if (kpis.customers !== null) {
        const c = kpis.customers;
        cards.push(
            <StatCard
                key="customers"
                label={t('dash.kpi.customers')}
                to="/customers"
                value={<span className="tabular">{formatCount(c.customers)}</span>}
                delta={<Delta percent={c.new_delta_percent} caption={t('dash.kpi.newReturning', { new: formatCount(c.new), returning: formatCount(c.returning) })} />}
            />,
        );
    }
    if (kpis.average_order_value !== null) {
        const aov = kpis.average_order_value;
        cards.push(
            <StatCard
                key="aov"
                label={t('dash.kpi.aov')}
                to="/orders"
                value={aov.value === null ? <span className="text-fg-tertiary">—</span> : <Money amount={aov.value} signClass="t-admin-currency-num-lg" />}
                delta={<Delta percent={aov.delta_percent} caption={aov.previous === null ? null : t('dash.kpi.vs', { value: moneyText(aov.previous) })} />}
            />,
        );
    }
    if (kpis.cash_collected !== null) {
        const cash = kpis.cash_collected;
        cards.push(
            <StatCard
                key="cash"
                label={t('dash.kpi.cash')}
                to="/finance/settlements"
                value={<Money amount={cash.value} signClass="t-admin-currency-num-lg" />}
                delta={<Delta percent={cash.delta_percent} caption={t('dash.kpi.vs', { value: moneyText(cash.previous) })} />}
                footnote={t('dash.kpi.cashNote')}
            />,
        );
    }
    if (kpis.low_stock !== null) {
        cards.push(
            <StatCard key="low" label={t('dash.kpi.lowStock')} to="/inventory?state=low" value={<span className="tabular">{formatCount(kpis.low_stock.low)}</span>} footnote={t('dash.kpi.lowNote', { out: formatCount(kpis.low_stock.out) })} />,
        );
    }

    if (cards.length === 0) return null;
    return <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 lg:gap-6 xl:grid-cols-4">{cards}</div>;
}
