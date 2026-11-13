import { useId, type ReactNode } from 'react';
import { CheckCircleIcon, MinusIcon, TrendDownIcon, WarningIcon } from '@phosphor-icons/react';
import { Bar, BarChart, Cell, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis, ReferenceLine } from 'recharts';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { compares, pnlExportUrl, usePnl, type Compare, type PnlBlock, type PnlStatement } from './api';
import { Card, ExportLink, Money, PeriodPicker, ReportTabs, TAKA, formatAmount, formatCount, formatDay, isNegative, useRangeParams } from './parts';

/** Lines that are only drawn when the period has something on them (the Figma statement shows none of them). */
const quietLines = new Set(['salaries', 'rent', 'utilities', 'other_income']);

/** Rail per block (A19): border/strong for the two inflow blocks, text/tertiary for the outflows; 4 → 4 → 3 → 3 → 2 px. */
const rails: Record<PnlBlock['key'], string> = {
    revenue: 'border-l-4 border-line-strong',
    goods: 'border-l-4 border-line-strong',
    delivery: 'border-l-3 border-fg-tertiary',
    losses: 'border-l-3 border-fg-tertiary',
    running: 'border-l-2 border-fg-tertiary',
};

const waterfallKeys = ['net_revenue', 'gross_profit', 'profit_after_delivery', 'profit_after_losses', 'net_profit'] as const;

/**
 * Profit & loss (Figma "B · 31 Profit & loss" 241:47268, spec 04-admin-spec A19/B7): the
 * five-block statement with running subtotals, the cash card kept apart from profit, the
 * "where the money went" waterfall, the reconciliation card and the "not in this
 * statement" footer. Every figure — every line, subtotal, margin and delta — is the
 * server's (rule 1); the page only lays it out. Period and comparison live in the URL.
 */
export function ProfitLossPage() {
    const { t, locale } = useI18n();
    const { range, preset, params, set, choosePreset } = useRangeParams();
    const compareParam = params.get('compare') as Compare | null;
    const compare: Compare = compareParam && compares.includes(compareParam) ? compareParam : 'previous_period';
    const pnl = usePnl(range, compare);
    const data = pnl.data;
    const compareId = useId();

    const rangeLabel = data ? t('reports.period.range', { from: formatDay(data.from, locale), to: formatDay(data.to, locale) }) : '';

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('reports.crumb.money') }, { label: t('reports.crumb.finance') }, { label: t('reports.pnl.title') }]}
                title={t('reports.pnl.title')}
                subtitle={data && t('reports.pnl.subtitle', { range: rangeLabel })}
                actions={
                    <>
                        <PeriodPicker range={range} preset={preset} onPreset={choosePreset} onRange={(r) => set({ preset: 'custom', ...r })} />
                        <label htmlFor={compareId} className="sr-only">
                            {t('reports.compare.label')}
                        </label>
                        <Select id={compareId} value={compare} onChange={(e) => set({ compare: e.target.value === 'previous_period' ? '' : e.target.value })} className="w-56">
                            {compares.map((c) => (
                                <option key={c} value={c}>
                                    {t(`reports.compare.${c}` as MessageKey)}
                                </option>
                            ))}
                        </Select>
                        <ExportLink href={pnlExportUrl(range)} />
                    </>
                }
            />
            <ReportTabs />

            {pnl.isError ? (
                <QueryError error={pnl.error} onRetry={() => void pnl.refetch()} />
            ) : !data ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('reports.loading')}
                </p>
            ) : (
                <>
                    {data.unmapped.length > 0 && (
                        <p role="alert" className="flex items-center gap-2 rounded-md border border-line-default bg-surface p-4 t-admin-body text-fg-primary">
                            <WarningIcon size={16} aria-hidden className="shrink-0 text-feedback-warning" />
                            {t('reports.pnl.unmapped', { types: data.unmapped.map((u) => u.type).join(', ') })}
                        </p>
                    )}
                    <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
                        <Statement data={data} />
                        <div className="flex w-full shrink-0 flex-col gap-6 lg:w-96">
                            <CashCard data={data} />
                            <Waterfall data={data} />
                            <TieUp data={data} />
                        </div>
                    </div>
                    <NotInStatement data={data} />
                </>
            )}
        </section>
    );
}

function Statement({ data }: { data: PnlStatement }) {
    const { t } = useI18n();
    return (
        <Card className="min-w-0 flex-1 overflow-hidden lg:max-w-184">
            {data.blocks.map((block) => (
                <div key={block.key} className="border-t border-line-default first:border-t-0">
                    <div className={`flex flex-col ${rails[block.key]}`}>
                        <header className="flex flex-col gap-1 px-5 pt-4 pb-2">
                            <p className="t-admin-overline text-fg-tertiary">{t('reports.pnl.blockOf', { number: block.number })}</p>
                            <h2 className="t-admin-section text-fg-primary">{t(`reports.pnl.block.${block.key}` as MessageKey)}</h2>
                            <p className="t-admin-caption text-fg-secondary">{t(`reports.pnl.block.${block.key}.sub` as MessageKey)}</p>
                        </header>
                        {block.rows
                            .filter((row) => row.kind === 'line' && !(quietLines.has(row.key) && Number(row.amount) === 0))
                            .map((row) => (
                                <div key={row.key} className="flex h-9 items-center justify-between gap-3 px-5">
                                    <span className="flex items-center gap-2 t-admin-body text-fg-primary">
                                        {isNegative(row.amount) && <MinusIcon size={12} aria-hidden className="text-fg-tertiary" />}
                                        {t(`reports.pnl.line.${row.key}` as MessageKey)}
                                    </span>
                                    <span className="t-admin-num text-end text-fg-primary">
                                        <Money amount={row.amount} />
                                    </span>
                                </div>
                            ))}
                    </div>
                    {block.rows
                        .filter((row) => row.kind === 'subtotal' && row.key !== 'net_profit')
                        .map((row) => (
                            <div key={row.key} className="flex min-h-12 items-center justify-between gap-3 border-t border-line-strong bg-subtle px-5 py-2">
                                <span className="t-admin-body-strong text-fg-primary">
                                    {row.key === 'gross_profit' && data.gross_margin_percent !== null
                                        ? t('reports.pnl.grossMargin', { label: t('reports.pnl.line.gross_profit'), percent: data.gross_margin_percent })
                                        : t(`reports.pnl.line.${row.key}` as MessageKey)}
                                </span>
                                <span className="t-admin-num-lg text-fg-primary">
                                    <Money amount={row.amount} signClass="t-admin-currency-num-lg" />
                                </span>
                            </div>
                        ))}
                </div>
            ))}
            <NetProfit data={data} />
        </Card>
    );
}

function NetProfit({ data }: { data: PnlStatement }) {
    const { t } = useI18n();
    const net = data.totals.net_profit;
    const loss = isNegative(net);
    const delta = data.comparison?.deltas.net_profit;
    return (
        <div className="flex flex-wrap items-center justify-between gap-4 rounded-b-lg bg-brand-subtle p-5">
            <div className="flex flex-col gap-1">
                <p className="flex items-center gap-2 t-admin-section text-fg-primary">
                    {loss && <TrendDownIcon size={20} aria-hidden className="text-feedback-danger" />}
                    {t('reports.pnl.line.net_profit')}
                </p>
                <p className="t-admin-caption text-fg-secondary">{loss ? t('reports.pnl.loss') : t('reports.pnl.netProfitHint')}</p>
            </div>
            <div className="flex flex-col items-end gap-1">
                <p className="t-admin-num-xl text-fg-primary">
                    <Money amount={net} signClass="t-admin-currency-section" />
                </p>
                <p className="t-admin-caption text-fg-secondary tabular">
                    {data.net_margin_percent !== null && t('reports.pnl.netMargin', { percent: data.net_margin_percent })}
                    {data.comparison && delta !== null && delta !== undefined && (
                        <>
                            {' · '}
                            {t('reports.pnl.delta', { delta: `${delta.startsWith('-') ? '' : '+'}${delta}`, amount: `${TAKA}${formatAmount(data.comparison.totals.net_profit)}` })}
                        </>
                    )}
                </p>
            </div>
        </div>
    );
}

function Row({ label, children, strong = false }: { label: string; children: ReactNode; strong?: boolean }) {
    return (
        <div className={`flex items-center justify-between gap-3 ${strong ? 'border-t border-line-strong pt-3' : ''}`}>
            <span className={strong ? 't-admin-body-strong text-fg-primary' : 't-admin-body text-fg-primary'}>{label}</span>
            <span className={strong ? 't-admin-num-lg text-fg-primary' : 't-admin-num text-fg-primary'}>{children}</span>
        </div>
    );
}

function CashCard({ data }: { data: PnlStatement }) {
    const { t } = useI18n();
    return (
        <Card className="flex flex-col gap-3 p-5">
            <p className="t-admin-overline text-fg-secondary">{t('reports.cash.overline')}</p>
            <p className="t-admin-caption text-fg-secondary">{t('reports.cash.intro')}</p>
            <Row label={t('reports.cash.in')}>
                <Money amount={data.cash.in} />
            </Row>
            <Row label={t('reports.cash.out')}>
                <Money amount={isNegative(data.cash.out) || data.cash.out === '0.00' ? data.cash.out : `-${data.cash.out}`} />
            </Row>
            <Row label={t('reports.cash.net')} strong>
                <Money amount={data.cash.net} signClass="t-admin-currency-num-lg" plus />
            </Row>
            <p className="border-t-2 border-line-default pt-3 t-admin-caption text-fg-tertiary">{t('reports.cash.note')}</p>
        </Card>
    );
}

/**
 * "Where the money went" (A19 rail card 2): five bars from net revenue down to net profit,
 * one series in bg/brand, each labelled with its value. A net loss draws below the baseline.
 * The visually-hidden table carries the same figures for screen readers.
 */
function Waterfall({ data }: { data: PnlStatement }) {
    const { t } = useI18n();
    const bars = waterfallKeys.map((key) => ({ key, label: t(`reports.waterfall.${key}` as MessageKey), amount: data.totals[key], value: Number(data.totals[key]) }));
    return (
        <Card className="flex flex-col gap-3 p-5">
            <h2 className="t-admin-section text-fg-primary">{t('reports.waterfall.title')}</h2>
            <div className="h-48" aria-hidden>
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={bars} margin={{ top: 20, right: 4, bottom: 0, left: 4 }}>
                        <XAxis dataKey="label" axisLine={false} tickLine={false} tick={<AxisTick />} interval={0} />
                        <YAxis hide domain={[(min: number) => Math.min(0, min), 'auto']} />
                        <ReferenceLine y={0} stroke="var(--color-line-default)" />
                        <Tooltip
                            cursor={{ fill: 'var(--color-subtle)' }}
                            content={({ active, payload }) => {
                                const bar = active ? (payload?.[0]?.payload as (typeof bars)[number] | undefined) : undefined;
                                return bar ? (
                                    <div className="rounded-sm border border-line-default bg-surface px-3 py-2 shadow-e2">
                                        <p className="t-admin-caption text-fg-secondary">{t(`reports.pnl.line.${bar.key}` as MessageKey)}</p>
                                        <p className="t-admin-num text-fg-primary">
                                            <Money amount={bar.amount} />
                                        </p>
                                    </div>
                                ) : null;
                            }}
                        />
                        <Bar dataKey="value" maxBarSize={48} radius={[4, 4, 0, 0]} isAnimationActive={false}>
                            {bars.map((bar) => (
                                <Cell key={bar.key} fill="var(--color-brand)" />
                            ))}
                            <LabelList dataKey="amount" content={<BarValue />} />
                        </Bar>
                    </BarChart>
                </ResponsiveContainer>
            </div>
            <table className="sr-only">
                <caption>{t('reports.waterfall.table')}</caption>
                <tbody>
                    {bars.map((bar) => (
                        <tr key={bar.key}>
                            <th scope="row">{t(`reports.pnl.line.${bar.key}` as MessageKey)}</th>
                            <td>
                                <Money amount={bar.amount} />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </Card>
    );
}

/** Axis labels in Admin/caption, text/secondary — text never wears the series colour. */
function AxisTick({ x, y, payload }: { x?: number; y?: number; payload?: { value: string } }) {
    return (
        <text x={x} y={(y ?? 0) + 12} textAnchor="middle" className="t-admin-caption" fill="var(--color-fg-secondary)">
            {payload?.value}
        </text>
    );
}

/** The value above each bar (below it for a loss), as the server sent it. */
function BarValue({ x, y, width, height, value }: { x?: number | string; y?: number | string; width?: number | string; height?: number | string; value?: unknown }) {
    const amount = String(value ?? '0');
    const below = isNegative(amount);
    const cx = Number(x ?? 0) + Number(width ?? 0) / 2;
    const top = Number(y ?? 0);
    const ty = below ? top + Math.abs(Number(height ?? 0)) + 14 : top - 6;
    return (
        <text x={cx} y={ty} textAnchor="middle" className="t-admin-caption tabular" fill="var(--color-fg-secondary)">
            {`${below ? '−' : ''}${TAKA}${formatAmount(amount.replace(/^-/, ''))}`}
        </text>
    );
}

function TieUp({ data }: { data: PnlStatement }) {
    const { t } = useI18n();
    const r = data.reconciliation;
    return (
        <Card className="flex flex-col gap-3 p-5">
            <p className="t-admin-overline text-fg-secondary">{t('reports.tie.overline')}</p>
            {[
                ['reports.tie.orders', r.orders],
                ['reports.tie.other', r.other],
                ['reports.tie.total', r.total],
            ].map(([label, amount]) => (
                <div key={label} className="flex items-center justify-between gap-3">
                    <span className="t-admin-body text-fg-secondary">{t(label as MessageKey)}</span>
                    <span className="t-admin-num text-fg-primary">
                        <Money amount={amount!} />
                    </span>
                </div>
            ))}
            {r.reconciled ? (
                <p className="flex items-center gap-2 t-admin-body-strong text-fg-primary">
                    <CheckCircleIcon size={16} aria-hidden className="text-feedback-success" />
                    {t('reports.tie.ok')}
                </p>
            ) : (
                <p role="alert" className="flex items-center gap-2 t-admin-body-strong text-fg-primary">
                    <WarningIcon size={16} aria-hidden className="text-feedback-danger" />
                    {t('reports.tie.bad')}
                </p>
            )}
        </Card>
    );
}

function NotInStatement({ data }: { data: PnlStatement }) {
    const { t } = useI18n();
    const money = (amount: string) => `${TAKA}${formatAmount(amount)}`;
    return (
        <aside className="flex flex-col gap-2 rounded-lg bg-subtle p-5">
            <p className="t-admin-overline text-fg-secondary">{t('reports.notIn.overline')}</p>
            <ul className="flex list-inside list-disc flex-col gap-1 t-admin-body text-fg-primary tabular">
                <li>{t('reports.notIn.open', { amount: money(data.open_orders.value), count: formatCount(data.open_orders.count) })}</li>
                {data.inventory_at_cost !== null && <li>{t('reports.notIn.stock', { amount: money(data.inventory_at_cost) })}</li>}
            </ul>
        </aside>
    );
}
