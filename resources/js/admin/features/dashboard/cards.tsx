import { useState } from 'react';
import { Link } from 'react-router';
import { Area, CartesianGrid, Cell, ComposedChart, Line, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { CheckCircleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { buttonClass } from '@/components/ui/Button';
import type { Dashboard } from './api';
import { TAKA, compact, formatCount, moneyText, onePlace, rangeText, shortDay } from './format';
import { Card, CardHeader, HeaderLink, Money } from './parts';

type Trend = NonNullable<Dashboard['trend']>;
type Mode = 'value' | 'orders' | 'units';

/** Axis text: Admin/caption, text/tertiary (ChartCard plot 189:380). */
function Tick({ x, y, payload, anchor, money = false }: { x?: number; y?: number; payload?: { value: string | number }; anchor: 'middle' | 'end'; money?: boolean }) {
    const value = Number(payload?.value ?? 0);
    const text = money ? `${value < 0 ? '−' : ''}${TAKA}${compact(Math.abs(value))}` : typeof payload?.value === 'number' ? compact(value) : String(payload?.value ?? '');
    return (
        <text x={x} y={(y ?? 0) + (anchor === 'middle' ? 12 : 4)} dx={anchor === 'end' ? -4 : 0} textAnchor={anchor} className="t-admin-caption tabular" fill="var(--color-fg-tertiary)">
            {text}
        </text>
    );
}

/**
 * Sales trend (ChartCard / Line 241:29992): placed value per bucket (brand line with area), the
 * comparison range dashed in text/tertiary, and — for finance viewers — recognised revenue in
 * feedback/success, so "placed" and "recognised" are drawn as the different things they are.
 */
export function TrendCard({ trend, range }: { trend: Trend; range: Dashboard['range'] }) {
    const { t, locale } = useI18n();
    const [mode, setMode] = useState<Mode>('value');
    const showRevenue = mode === 'value' && trend.recognised_revenue !== null;

    const points = trend.buckets.map((b) => ({
        key: b.key,
        label: shortDay(b.key, locale),
        current: mode === 'value' ? Number(b.placed_value) : mode === 'orders' ? b.placed_count : b.units,
        previous: b.previous_key === null ? null : mode === 'value' ? Number(b.previous_placed_value) : mode === 'orders' ? b.previous_placed_count : b.previous_units,
        revenue: b.recognised_revenue === null ? null : Number(b.recognised_revenue),
        bucket: b,
    }));

    return (
        <Card className="flex flex-col gap-4 p-5" label={t(`dash.trend.title.${trend.granularity}` as MessageKey)}>
            <CardHeader
                title={t(`dash.trend.title.${trend.granularity}` as MessageKey)}
                subtitle={`${rangeText(range.from, range.to, locale, true)} · ${t('dash.dhakaDates')}`}
                action={
                    <SegmentedControl
                        label={t('dash.trend.mode')}
                        value={mode}
                        onChange={setMode}
                        options={[
                            { value: 'value', label: t('dash.trend.value') },
                            { value: 'orders', label: t('dash.trend.orders') },
                            { value: 'units', label: t('dash.trend.units') },
                        ]}
                    />
                }
            />
            <ul className="flex flex-wrap items-center gap-4 t-admin-caption text-fg-secondary">
                <li className="flex items-center gap-1.5">
                    <span aria-hidden className="size-2.5 rounded-3xs bg-brand" />
                    {t('dash.trend.thisPeriod', { value: moneyText(trend.placed_value) })}
                </li>
                <li className="flex items-center gap-1.5">
                    <span aria-hidden className="h-0.5 w-3.5 bg-fg-tertiary" />
                    {t('dash.trend.previous', { value: moneyText(trend.previous_placed_value) })}
                </li>
                {showRevenue && (
                    <li className="flex items-center gap-1.5">
                        <span aria-hidden className="h-0.5 w-3.5 bg-feedback-success" />
                        {t('dash.trend.revenue', { value: moneyText(trend.recognised_revenue ?? '0.00') })}
                    </li>
                )}
            </ul>
            <div className="h-48 lg:h-64" aria-hidden>
                <ResponsiveContainer width="100%" height="100%">
                    <ComposedChart accessibilityLayer={false} data={points} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                        <CartesianGrid vertical={false} stroke="var(--color-line-default)" />
                        <XAxis dataKey="label" axisLine={false} tickLine={false} tick={<Tick anchor="middle" />} minTickGap={12} />
                        <YAxis axisLine={false} tickLine={false} width={56} tick={<Tick anchor="end" money={mode === 'value'} />} allowDecimals={mode === 'value'} />
                        <Tooltip
                            cursor={{ stroke: 'var(--color-line-strong)' }}
                            content={({ active, payload }) => {
                                const point = active ? (payload?.[0]?.payload as (typeof points)[number] | undefined) : undefined;
                                if (!point) return null;
                                const b = point.bucket;
                                return (
                                    <div className="rounded-sm border border-line-default bg-surface px-3 py-2 shadow-e2">
                                        <p className="t-admin-caption text-fg-secondary">{rangeText(b.from, b.to, locale)}</p>
                                        <p className="t-admin-num text-fg-primary">
                                            <Money amount={b.placed_value} />
                                        </p>
                                        <p className="t-admin-caption text-fg-secondary">
                                            {t('dash.trend.tipOrders', { count: formatCount(b.placed_count) })} · {t('dash.trend.tipUnits', { count: formatCount(b.units) })}
                                        </p>
                                        {b.recognised_revenue !== null && <p className="t-admin-caption text-fg-secondary">{t('dash.trend.revenue', { value: moneyText(b.recognised_revenue) })}</p>}
                                    </div>
                                );
                            }}
                        />
                        <Area type="linear" dataKey="current" stroke="var(--color-brand)" strokeWidth={2} fill="var(--color-brand-subtle)" fillOpacity={1} dot={{ r: 3, fill: 'var(--color-surface)', stroke: 'var(--color-brand)', strokeWidth: 1.5 }} isAnimationActive={false} />
                        <Line type="linear" dataKey="previous" stroke="var(--color-fg-tertiary)" strokeWidth={1.5} strokeDasharray="4 4" dot={false} isAnimationActive={false} connectNulls />
                        {showRevenue && <Line type="linear" dataKey="revenue" stroke="var(--color-feedback-success)" strokeWidth={1.5} dot={false} isAnimationActive={false} />}
                    </ComposedChart>
                </ResponsiveContainer>
            </div>
        </Card>
    );
}

/** Donut slice colours, in Figma's order (ChartCard / Donut 241:29993 legend swatches). */
const SLICE_COLOURS = ['var(--color-brand)', 'var(--color-feedback-info)', 'var(--color-status-low-stock)', 'var(--color-feedback-success)', 'var(--color-grape)', 'var(--color-fg-secondary)', 'var(--color-fg-tertiary)'];
const SLICE_CLASSES = ['bg-brand', 'bg-feedback-info', 'bg-status-low-stock', 'bg-feedback-success', 'bg-grape', 'bg-fg-secondary', 'bg-fg-tertiary'];

/** "Where the money came from" (ChartCard / Donut): recognised item revenue by top-level category. */
export function CategoryDonut({ categories }: { categories: NonNullable<Dashboard['categories']> }) {
    const { t, locale } = useI18n();
    const name = (s: (typeof categories.slices)[number]) => (s.other ? t('dash.categories.other') : ((locale === 'bn' ? s.name_bn : null) ?? s.name ?? t('dash.categories.other')));
    return (
        <Card className="flex flex-col gap-4 p-5" label={t('dash.categories.title')}>
            <CardHeader title={t('dash.categories.title')} subtitle={t('dash.categories.subtitle')} />
            {categories.slices.length === 0 ? (
                <p className="t-admin-caption text-fg-secondary">{t('dash.categories.empty')}</p>
            ) : (
                <>
                    <div className="flex flex-col items-center gap-4 sm:flex-row">
                        <div className="relative size-38 shrink-0">
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart accessibilityLayer={false}>
                                    <Pie data={categories.slices.map((s) => ({ value: Number(s.amount) }))} dataKey="value" innerRadius="70%" outerRadius="100%" startAngle={90} endAngle={-270} stroke="var(--color-surface)" strokeWidth={2} isAnimationActive={false}>
                                        {categories.slices.map((s, i) => (
                                            <Cell key={s.category_id ?? 'other'} fill={SLICE_COLOURS[i % SLICE_COLOURS.length]} />
                                        ))}
                                    </Pie>
                                </PieChart>
                            </ResponsiveContainer>
                            <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                                <span className="t-admin-section text-fg-primary">
                                    <Money amount={categories.total} signClass="t-admin-currency-section" />
                                </span>
                                <span className="t-admin-overline text-fg-tertiary">{t('dash.categories.centre')}</span>
                            </div>
                        </div>
                        <ul className="flex w-full min-w-0 flex-1 flex-col gap-1.5">
                            {categories.slices.map((s, i) => (
                                <li key={s.category_id ?? 'other'} className="flex items-center gap-1.5">
                                    <span aria-hidden className={`size-2.5 shrink-0 rounded-3xs ${SLICE_CLASSES[i % SLICE_CLASSES.length]}`} />
                                    <span className="min-w-0 flex-1 truncate t-admin-caption text-fg-secondary" title={moneyText(s.amount)}>
                                        {name(s)}
                                    </span>
                                    <span className="t-admin-caption-strong text-fg-primary tabular">{s.share_percent === null ? '—' : `${onePlace(s.share_percent)}%`}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <p className="t-admin-caption text-fg-tertiary">{t('dash.categories.footnote')}</p>
                </>
            )}
        </Card>
    );
}

function Thumb({ src }: { src: string | null }) {
    const [broken, setBroken] = useState(false);
    return src && !broken ? (
        <img src={src} alt="" onError={() => setBroken(true)} className="size-10 shrink-0 rounded-sm bg-subtle object-cover" />
    ) : (
        <span aria-hidden className="block size-10 shrink-0 rounded-sm bg-subtle" />
    );
}

/** Best sellers (Card 241:29995): product, SKU, units and order value placed; footer totals. */
export function BestSellersCard({ best, range }: { best: NonNullable<Dashboard['best_sellers']>; range: Dashboard['range'] }) {
    const { t, locale } = useI18n();
    return (
        <Card className="flex flex-col p-5" label={t('dash.best.title')}>
            <CardHeader title={t('dash.best.title')} subtitle={t('dash.best.subtitle', { range: rangeText(range.from, range.to, locale) })} action={<HeaderLink to="/products">{t('dash.best.all')}</HeaderLink>} />
            {best.rows.length === 0 ? (
                <p className="py-4 t-admin-caption text-fg-secondary">{t('dash.best.empty')}</p>
            ) : (
                <>
                    <ul className="divide-y divide-line-default">
                        {best.rows.map((row) => (
                            <li key={row.product_id ?? row.sku} className="flex items-center gap-3 py-2.5">
                                <span className="hidden lg:block">
                                    <Thumb src={row.image_url} />
                                </span>
                                <div className="min-w-0 flex-1">
                                    {row.product_id !== null ? (
                                        <Link to={`/products/${row.product_id}`} className="block truncate t-admin-body-strong text-fg-primary hover:underline">
                                            {(locale === 'bn' ? row.name_bn : null) ?? row.name}
                                        </Link>
                                    ) : (
                                        <p className="truncate t-admin-body-strong text-fg-primary">{row.name}</p>
                                    )}
                                    <p className="hidden truncate t-admin-mono-sm text-fg-tertiary lg:block">{row.sku}</p>
                                    <p className="t-admin-caption text-fg-secondary lg:hidden">{t('dash.best.units', { count: formatCount(row.units) })}</p>
                                </div>
                                <span className="hidden w-16 text-end t-admin-caption text-fg-secondary tabular lg:block">{t('dash.best.units', { count: formatCount(row.units) })}</span>
                                <span className="w-20 text-end t-admin-num text-fg-primary">
                                    <Money amount={row.value} />
                                </span>
                            </li>
                        ))}
                    </ul>
                    <p className="border-t border-line-default pt-3 t-admin-caption text-fg-secondary">
                        {t('dash.best.footer', { units: formatCount(best.units), products: formatCount(best.products), value: moneyText(best.value) })}
                    </p>
                </>
            )}
        </Card>
    );
}

/** Low stock (Card 241:30038): available chip with a low-stock edge, the on-hand − reserved breakdown, Create PO. */
export function LowStockCard({ low }: { low: NonNullable<Dashboard['low_stock']> }) {
    const { t, locale } = useI18n();
    return (
        <Card className="flex flex-col p-5" label={t('dash.low.title')}>
            <CardHeader title={t('dash.low.title')} subtitle={t('dash.low.subtitle')} action={<HeaderLink to="/inventory?state=low">{t('dash.low.open')}</HeaderLink>} />
            {low.rows.length === 0 ? (
                <p className="py-4 t-admin-caption text-fg-secondary">{t('dash.low.empty')}</p>
            ) : (
                <ul className="divide-y divide-line-default">
                    {low.rows.map((row) => (
                        <li key={row.variant_id} className="flex items-center gap-3 py-2.5">
                            <div className="min-w-0 flex-1">
                                <Link to={`/products/${row.product_id}`} className="block truncate t-admin-body-strong text-fg-primary hover:underline">
                                    {(locale === 'bn' ? row.name_bn : null) ?? row.name}
                                    {row.variant_name ? ` · ${row.variant_name}` : ''}
                                </Link>
                                <p className="truncate t-admin-caption text-fg-tertiary tabular">
                                    {t('dash.low.breakdown', { onHand: formatCount(row.on_hand), reserved: formatCount(row.reserved), point: formatCount(row.reorder_point) })}
                                </p>
                            </div>
                            <span className="flex items-baseline gap-1 rounded-sm border-l-2 border-l-status-low-stock bg-subtle px-2 py-0.5">
                                <span className="t-admin-num text-fg-primary">{formatCount(row.available)}</span>
                                <span className="t-admin-caption text-fg-secondary">{t('dash.low.chip')}</span>
                            </span>
                            <Link to="/purchasing/new" className={`${buttonClass('outline', 'sm')} hidden md:inline-flex`}>
                                {t('dash.low.createPo')}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
            <p className="flex items-center gap-1.5 border-t border-line-default pt-3 t-admin-caption text-fg-secondary">
                {low.out === 0 && <CheckCircleIcon size={14} aria-hidden className="text-feedback-success" />}
                {t('dash.low.out', { count: formatCount(low.out) })}
            </p>
        </Card>
    );
}
