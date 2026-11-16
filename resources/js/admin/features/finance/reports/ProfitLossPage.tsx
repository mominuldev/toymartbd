import { useId, useState, type ComponentType, type ReactNode } from 'react';
import { Link } from 'react-router';
import {
    ArrowRightIcon,
    BankIcon,
    CaretDownIcon,
    CaretRightIcon,
    CaretUpIcon,
    ChartBarIcon,
    CheckCircleIcon,
    CoinsIcon,
    HourglassIcon,
    InfoIcon,
    LockSimpleIcon,
    ReceiptIcon,
    TrendDownIcon,
    TrendUpIcon,
    WarehouseIcon,
    WarningIcon,
    XCircleIcon,
    type IconProps,
} from '@phosphor-icons/react';
import { Bar, BarChart, Cell, LabelList, ReferenceLine, ResponsiveContainer, XAxis, YAxis } from 'recharts';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { buttonClass } from '@/components/ui/Button';
import { ExportMenu } from '@/components/ui/ExportMenu';
import { Input } from '@/components/ui/Input';
import { KpiCard, type KpiTone } from '@/components/ui/KpiCard';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { useReceivables, useRefundsOwed } from '../api';
import { compares, pnlExportUrl, usePnl, type Compare, type PnlBlock, type PnlStatement } from './api';
import { MINUS, Money, ReportTabs, TAKA, dhakaToday, formatCount, formatDay, isNegative, presetRange, useRangeParams, type Preset } from './parts';
import { takaText } from '@/lib/money';

type Row = PnlBlock['rows'][number];
type BlockKey = PnlBlock['key'];

/** Lines that are only drawn when the period has something on them (the Figma statement shows none of them). */
const quietLines = new Set(['salaries', 'rent', 'utilities', 'other_income']);

/** `gross_revenue` is a server subtotal the statement does not draw; `net_profit` is the closing band. */
const hiddenSubtotals = new Set(['gross_revenue', 'net_profit']);

/** Lines with an "about this line" tooltip (Figma: the ⓘ next to the label). */
const tips = new Set(['cogs', 'cod_fees', 'gateway_fees', 'write_offs', 'marketing']);

/** The quick periods in the toolbar's SegmentedControl (Figma 378:70114). */
const quickPresets = ['last7', 'thisMonth', 'lastMonth'] as const;

/** Step tile per block (Figma "step tile"): tone fill + the number in the tone's text colour. */
const stepTiles: Record<BlockKey, string> = {
    revenue: 'bg-brand-subtle text-fg-brand',
    goods: 'bg-subtle text-fg-secondary',
    delivery: 'bg-teal-subtle text-teal',
    losses: 'bg-danger-subtle text-feedback-danger',
    running: 'bg-warning-subtle text-feedback-warning',
};

/** The series colour of each cost step — the journey bars, the ৳100 strip and the biggest-cost bars share it. */
const costColours: Record<BlockKey, string> = {
    revenue: 'var(--color-brand)',
    goods: 'var(--color-line-strong)',
    delivery: 'var(--color-teal)',
    losses: 'var(--color-feedback-danger)',
    running: 'var(--color-feedback-warning)',
};
const costFills: Record<BlockKey, string> = {
    revenue: 'bg-brand',
    goods: 'bg-line-strong',
    delivery: 'bg-teal',
    losses: 'bg-feedback-danger',
    running: 'bg-feedback-warning',
};

/** A server percentage ("40.98") to one place ("41.0"), true minus — display rounding only; null → "—". */
function share(percent: string | null | undefined): string {
    if (percent === null || percent === undefined) return '—';
    const n = Number(percent);
    if (!Number.isFinite(n)) return percent;
    const fixed = Math.abs(n).toFixed(1);
    const text = fixed === '100.0' ? '100' : fixed;
    return `${n < 0 && text !== '0.0' ? MINUS : ''}${text}`;
}

/** "−৳602" / "৳18,000" as plain text, for sentences (lib/money `auto`, lakh grouping). */
const moneyText = (amount: string): string => takaText(amount, 'auto');

/** A server decimal without its sign (a cost shown as a positive figure) — string work only. */
const magnitude = (amount: string) => amount.replace(/^-/, '');

function dhakaClock(iso: string, locale: string): { time: string; date: string } {
    const lang = locale === 'bn' ? 'bn-BD' : 'en-GB';
    const at = new Date(iso);
    return {
        time: new Intl.DateTimeFormat(lang, { timeZone: 'Asia/Dhaka', hour: 'numeric', minute: '2-digit', hour12: true, numberingSystem: 'latn' }).format(at),
        date: new Intl.DateTimeFormat(lang, { timeZone: 'Asia/Dhaka', day: '2-digit', month: 'short', numberingSystem: 'latn' }).format(at),
    };
}

const findRow = (data: PnlStatement, key: string): Row | undefined => data.blocks.flatMap((b) => b.rows).find((r) => r.key === key);
const findBlock = (data: PnlStatement, key: BlockKey): PnlBlock | undefined => data.blocks.find((b) => b.key === key);
const ledgerLink = (data: PnlStatement, type: string) => `/finance/ledger?type=${encodeURIComponent(type)}&from=${data.from}&to=${data.to}`;

/**
 * Profit & loss — premium pass (Figma "B · 31 Profit & loss · Premium" 378:69159, states
 * 384:68956, 390 mobile 386:68959; spec 04-admin-spec A19/B7). KPI row with tone tiles, the
 * revenue → net profit journey with the "out of every ৳100" strip, the five plain-English
 * blocks with running subtotals and each line's share of revenue, then the rail: cash kept
 * apart from profit, the ledger tie-up, the biggest costs and what is not in the statement.
 *
 * Every figure — every line, block total, subtotal, share, margin, delta and count — is the
 * server's (rule 1); the page only formats and lays it out. Bars are drawn to scale from the
 * server's figures. Period and comparison live in the URL.
 */
export function ProfitLossPage() {
    const { t, locale } = useI18n();
    const { range, preset, params, set, choosePreset } = useRangeParams();
    const compareParam = params.get('compare') as Compare | null;
    const compare: Compare = compareParam && compares.includes(compareParam) ? compareParam : 'previous_period';
    const pnl = usePnl(range, compare);
    const data = pnl.data;
    const compareId = useId();
    const fromId = useId();
    const toId = useId();
    const today = dhakaToday();
    const shown = data ? { from: data.from, to: data.to } : range.from ? range : presetRange('last7');

    const rangeLabel = data ? t('reports.period.range', { from: formatDay(data.from, locale), to: formatDay(data.to, locale) }) : '';
    const clock = data ? dhakaClock(data.generated_at, locale) : null;
    const tied = data?.reconciliation.reconciled ?? true;

    return (
        <section className="flex flex-col gap-6 max-sm:gap-4">
            <ContentHeader
                breadcrumb={[{ label: t('reports.crumb.money') }, { label: t('reports.crumb.finance') }]}
                title={t('reports.pnl.title')}
                titleAddon={
                    data &&
                    (tied ? (
                        <StatusChip tone="success" label={t('reports.pnl.chip.ok')} />
                    ) : (
                        <StatusChip tone="danger" label={t('reports.pnl.chip.bad', { amount: moneyText(magnitude(data.reconciliation.difference)) })} />
                    ))
                }
                subtitle={data && t('reports.pnl.premiumSubtitle', { range: rangeLabel })}
                actions={<ExportMenu href={(format, lang) => pnlExportUrl(range, { format, locale: lang })} />}
            />
            <ReportTabs />

            <div className="flex flex-wrap items-center gap-3">
                <SegmentedControl<Preset>
                    label={t('reports.pnl.presets')}
                    options={quickPresets.map((p) => ({ value: p, label: t(`reports.period.${p}` as MessageKey) }))}
                    value={preset}
                    onChange={choosePreset}
                />
                <div className="flex items-center gap-2 max-sm:hidden">
                    <label htmlFor={fromId} className="sr-only">
                        {t('reports.period.from')}
                    </label>
                    <div className="w-40 shrink-0">
                        <Input id={fromId} type="date" max={shown.to || today} value={shown.from} onChange={(e) => set({ preset: 'custom', from: e.target.value, to: shown.to })} className="tabular" />
                    </div>
                    <span aria-hidden className="t-admin-body text-fg-tertiary">
                        –
                    </span>
                    <label htmlFor={toId} className="sr-only">
                        {t('reports.period.to')}
                    </label>
                    <div className="w-40 shrink-0">
                        <Input id={toId} type="date" min={shown.from} max={today} value={shown.to} onChange={(e) => set({ preset: 'custom', from: shown.from, to: e.target.value })} className="tabular" />
                    </div>
                </div>
                <label htmlFor={compareId} className="sr-only">
                    {t('reports.compare.label')}
                </label>
                <Select id={compareId} value={compare} onChange={(e) => set({ compare: e.target.value === 'previous_period' ? '' : e.target.value })} className="w-50 max-sm:hidden">
                    {compares.map((c) => (
                        <option key={c} value={c}>
                            {t(`reports.compare.${c}` as MessageKey)}
                        </option>
                    ))}
                </Select>
                {clock && <p className="ms-auto t-admin-caption text-fg-secondary tabular max-lg:hidden">{t('reports.pnl.asOf', clock)}</p>}
            </div>

            {pnl.isError ? (
                <QueryError error={pnl.error} onRetry={() => void pnl.refetch()} />
            ) : !data ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('reports.loading')}
                </p>
            ) : (
                <>
                    {data.unmapped.length > 0 && (
                        <p role="alert" className="flex items-center gap-2 rounded-sm bg-warning-subtle p-4 t-admin-body text-fg-primary">
                            <WarningIcon size={16} aria-hidden className="shrink-0 text-feedback-warning" />
                            {t('reports.pnl.unmapped', { types: data.unmapped.map((u) => u.type).join(', ') })}
                        </p>
                    )}
                    <Kpis data={data} compare={compare} />
                    <Journey data={data} rangeLabel={t('reports.period.range', { from: formatDay(data.from, locale), to: formatDay(data.to, locale) })} />
                    <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
                        <Statement data={data} compare={compare} />
                        <div className="flex w-full shrink-0 flex-col gap-4 lg:w-96">
                            <CashCard data={data} />
                            <TieUp data={data} time={clock?.time ?? ''} />
                            <BiggestCosts data={data} />
                            <NotInStatement data={data} />
                        </div>
                    </div>
                </>
            )}
        </section>
    );
}

/** "up 40.0% on ৳512 the 7 days before" — the server's delta, or nothing when there is no comparison. */
function useTrend(data: PnlStatement, compare: Compare): string {
    const { t } = useI18n();
    const c = data.comparison;
    const delta = c?.deltas.net_profit;
    if (!c || compare === 'none' || delta === null || delta === undefined) return '';
    const when = t(`reports.pnl.when.${c.kind}` as MessageKey, { days: formatCount(data.days) });
    const amount = moneyText(c.totals.net_profit);
    if (Number(delta) === 0) return t('reports.pnl.trend.flat', { when });
    return t(isNegative(delta) ? 'reports.pnl.trend.down' : 'reports.pnl.trend.up', { percent: share(magnitude(delta)), amount, when });
}

function Figure({ amount }: { amount: string }) {
    return (
        <span className="t-admin-num-lg max-sm:t-admin-num-md">
            <Money amount={amount} signClass="t-admin-currency-num-lg max-sm:t-admin-currency-section" />
        </span>
    );
}

/** A figure withheld from this reader (no catalog.view_cost — owner decision 2026-11-16). */
function Restricted() {
    const { t } = useI18n();
    return (
        <span className="inline-flex items-center gap-1.5 t-admin-body-strong text-fg-secondary">
            <LockSimpleIcon size={14} aria-hidden />
            {t('reports.journey.restricted')}
        </span>
    );
}

function Kpis({ data, compare }: { data: PnlStatement; compare: Compare }) {
    const { t } = useI18n();
    const trend = useTrend(data, compare);
    const loss = isNegative(data.totals.net_profit);
    const product = findRow(data, 'product_revenue');
    const discounts = findRow(data, 'discounts');
    const losses = findBlock(data, 'losses')?.total ?? '0.00';
    const netHint = [data.net_margin_percent !== null ? t('reports.pnl.kpi.netHint', { percent: share(data.net_margin_percent) }) : t('reports.pnl.noRevenue'), trend].filter(Boolean).join(' · ');

    return (
        <div role="group" aria-label={t('reports.pnl.kpis')} className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-5">
            <KpiCard className="col-span-2 lg:col-span-1" icon={loss ? TrendDownIcon : TrendUpIcon} tone={loss ? 'danger' : 'brand'} label={t('reports.pnl.kpi.net')} value={<Figure amount={data.totals.net_profit} />} hint={netHint} />
            <KpiCard
                compact icon={ReceiptIcon}
                label={t('reports.pnl.kpi.revenue')}
                value={<Figure amount={data.totals.net_revenue} />}
                hint={t('reports.pnl.kpi.revenueHint', { count: formatCount(product?.occurrences ?? 0), amount: moneyText(magnitude(discounts?.amount ?? '0.00')) })}
            />
            <KpiCard
                compact icon={CoinsIcon}
                label={t('reports.pnl.kpi.gross')}
                value={data.totals.gross_profit === null ? <Restricted /> : <Figure amount={data.totals.gross_profit} />}
                hint={
                    !data.item_costs_visible
                        ? t('reports.pnl.kpi.grossLocked')
                        : data.gross_margin_percent !== null
                          ? t('reports.pnl.kpi.grossHint', { percent: share(data.gross_margin_percent) })
                          : t('reports.pnl.noRevenue')
                }
            />
            <KpiCard compact icon={BankIcon} tone="info" label={t('reports.pnl.kpi.cash')} value={<Figure amount={data.cash.in} />} hint={t('reports.pnl.kpi.cashHint')} />
            <KpiCard compact icon={WarningIcon} tone="warning" label={t('reports.pnl.kpi.losses')} value={<Figure amount={isNegative(losses) ? magnitude(losses) : losses} />} hint={t('reports.pnl.kpi.lossesHint')} />
        </div>
    );
}

type JourneyKey = 'net_revenue' | 'goods' | 'gross_profit' | 'delivery' | 'losses' | 'running' | 'net_profit';
type Step = { key: JourneyKey; subtotal: boolean; amount: string | null; share: string | null; lo: number; hi: number; level: number; colour: string; fill: string };

/** The seven journey steps from the server's subtotals and block totals; bar ends are the running subtotals. */
function journeySteps(data: PnlStatement): Step[] {
    const tot = data.totals;
    const n = (v: string | null) => (v === null ? 0 : Number(v));
    const loss = isNegative(tot.net_profit);
    const sub = (key: 'net_revenue' | 'gross_profit' | 'net_profit'): Step => ({
        key,
        subtotal: true,
        amount: tot[key],
        share: findRow(data, key)?.percent_of_revenue ?? null,
        lo: Math.min(0, n(tot[key])),
        hi: Math.max(0, n(tot[key])),
        level: n(tot[key]),
        colour: key === 'net_profit' ? (loss ? 'var(--color-feedback-danger)' : 'var(--color-feedback-success)') : 'var(--color-brand)',
        fill: key === 'net_profit' ? (loss ? 'bg-feedback-danger' : 'bg-feedback-success') : 'bg-brand',
    });
    const cost = (key: 'goods' | 'delivery' | 'losses' | 'running', before: string | null, after: string | null): Step => {
        const block = findBlock(data, key);
        return {
            key,
            subtotal: false,
            amount: block?.total ?? null,
            share: block?.percent_of_revenue ?? null,
            lo: Math.min(n(before), n(after)),
            hi: Math.max(n(before), n(after)),
            level: n(after),
            colour: costColours[key],
            fill: costFills[key],
        };
    };
    return [
        sub('net_revenue'),
        cost('goods', tot.net_revenue, tot.gross_profit),
        sub('gross_profit'),
        cost('delivery', tot.gross_profit, tot.profit_after_delivery),
        cost('losses', tot.profit_after_delivery, tot.profit_after_losses),
        cost('running', tot.profit_after_losses, tot.net_profit),
        sub('net_profit'),
    ];
}

/** "From revenue to net profit" (Figma 379:68902): a floating waterfall, the ৳100 strip, and a horizontal variant below 640. */
function Journey({ data, rangeLabel }: { data: PnlStatement; rangeLabel: string }) {
    const { t } = useI18n();
    const steps = journeySteps(data);
    const loss = isNegative(data.totals.net_profit);
    const kept = data.net_margin_percent;
    // Without catalog.view_cost the running subtotals are withheld, so no floating bar can be
    // placed honestly: the step list (the 390 layout) is shown at every width instead.
    const restricted = !data.item_costs_visible;

    return (
        <section aria-labelledby="pnl-journey" className="flex flex-col gap-5 rounded-md border border-line-default bg-surface p-6 shadow-e1 max-sm:gap-4 max-sm:p-4">
            <div className="flex flex-wrap items-center gap-4">
                <div className="flex min-w-0 flex-1 basis-80 flex-col gap-1">
                    <h2 id="pnl-journey" className="t-admin-card-title text-fg-primary">
                        {t('reports.journey.title')}
                    </h2>
                    <p className="t-admin-body text-fg-secondary max-sm:hidden">{t('reports.journey.intro', { amount: moneyText(data.totals.net_revenue), range: rangeLabel })}</p>
                    <p className="t-admin-caption text-fg-secondary sm:hidden">{t('reports.journey.mobileIntro')}</p>
                </div>
                {kept !== null && (
                    <p className={`flex items-center gap-2 rounded-full px-3 py-2 t-admin-body-strong max-sm:hidden ${loss ? 'bg-danger-subtle text-feedback-danger' : 'bg-success-subtle text-feedback-success'}`}>
                        {loss ? <TrendDownIcon size={16} aria-hidden /> : <TrendUpIcon size={16} aria-hidden />}
                        {t(loss ? 'reports.journey.lost' : 'reports.journey.kept', { amount: `${TAKA}${Math.round(Math.abs(Number(kept)))}` })}
                    </p>
                )}
            </div>

            {!restricted && (
            <div className="h-74 max-sm:hidden" aria-hidden>
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart accessibilityLayer={false} data={steps.map((s) => ({ ...s, range: [s.lo, s.hi] }))} margin={{ top: 28, right: 8, bottom: 0, left: 8 }} barCategoryGap="22%">
                        <XAxis dataKey="key" axisLine={false} tickLine={false} interval={0} height={56} tick={<StepTick steps={steps} />} />
                        <YAxis hide domain={[(min: number) => Math.min(0, min), 'auto']} />
                        <ReferenceLine y={0} stroke="var(--color-line-strong)" />
                        {steps.slice(0, -1).map((s, i) => (
                            <ReferenceLine
                                key={s.key}
                                segment={[
                                    { x: s.key, y: s.level },
                                    { x: steps[i + 1]!.key, y: s.level },
                                ]}
                                stroke="var(--color-line-strong)"
                            />
                        ))}
                        <Bar dataKey="range" radius={6} isAnimationActive={false}>
                            {steps.map((s) => (
                                <Cell key={s.key} fill={s.colour} />
                            ))}
                            <LabelList dataKey="key" content={<StepValue steps={steps} restricted={t('reports.journey.restricted')} />} />
                        </Bar>
                    </BarChart>
                </ResponsiveContainer>
            </div>
            )}

            <ol className={`flex flex-col gap-3.5 ${restricted ? '' : 'sm:hidden'}`}>
                {steps.map((s) => (
                    <li key={s.key} className="flex flex-col gap-1.5">
                        <span className="flex items-center justify-between gap-2">
                            <span className={s.subtotal ? 't-admin-body-strong text-fg-primary' : 't-admin-body text-fg-secondary'}>{t(`reports.journey.step.${s.key}` as MessageKey)}</span>
                            <span className={`t-admin-currency-num ${s.key === 'net_profit' ? (loss ? 'text-feedback-danger' : 'text-feedback-success') : 'text-fg-primary'}`}>
                                {s.amount === null ? <span className="t-admin-caption text-fg-tertiary">{t('reports.journey.restricted')}</span> : <Money amount={s.amount} signClass="" />}
                            </span>
                        </span>
                        <span className="relative block h-2.5 w-full">
                            {s.amount !== null && (
                            <span
                                className={`absolute inset-y-0 rounded-full ${s.fill}`}
                                style={{ left: `${clampPercent(s.subtotal ? '0' : (offsetOf(data, s.key) ?? '0'))}%`, width: `${Math.max(clampPercent(magnitude(s.share ?? '0')), 2)}%` }}
                            />
                            )}
                        </span>
                    </li>
                ))}
            </ol>

            <table className="sr-only">
                <caption>{t('reports.journey.table')}</caption>
                <tbody>
                    {steps.map((s) => (
                        <tr key={s.key}>
                            <th scope="row">{t(`reports.journey.step.${s.key}` as MessageKey)}</th>
                            <td>{s.amount === null ? t('reports.journey.restricted') : moneyText(s.amount)}</td>
                            <td>{t('reports.journey.share', { percent: share(s.share) })}</td>
                        </tr>
                    ))}
                </tbody>
            </table>

            <Per100 data={data} steps={steps} />
        </section>
    );
}

/** Where a cost step's bar starts on the mobile track: the server's share of the subtotal after it. */
function offsetOf(data: PnlStatement, key: JourneyKey): string | null {
    const after: Partial<Record<JourneyKey, string>> = { goods: 'gross_profit', delivery: 'profit_after_delivery', losses: 'profit_after_losses', running: 'net_profit' };
    const k = after[key];
    return k ? (findRow(data, k)?.percent_of_revenue ?? null) : null;
}

/** A server percentage as a bar position, held to the 0–100 track (layout only). */
function clampPercent(percent: string): number {
    const n = Number(percent);
    return Number.isFinite(n) ? Math.min(100, Math.max(0, n)) : 0;
}

/** "Out of every ৳100 customers paid" (Figma 379:68969): the cost steps and what was kept, sized by the server's shares. */
function Per100({ data, steps }: { data: PnlStatement; steps: Step[] }) {
    const { t } = useI18n();
    if (data.net_margin_percent === null) return null;
    const label: Partial<Record<JourneyKey, MessageKey>> = { goods: 'reports.journey.toys', delivery: 'reports.journey.step.delivery', losses: 'reports.journey.step.losses', running: 'reports.journey.step.running' };
    const parts = steps
        .filter((s) => !s.subtotal && s.share !== null && Number(s.share) !== 0)
        .map((s) => ({ key: s.key, label: t(label[s.key]!), share: s.share!, fill: s.fill }));
    if (!isNegative(data.totals.net_profit) && Number(data.net_margin_percent) !== 0) parts.push({ key: 'net_profit', label: t('reports.journey.youKept'), share: data.net_margin_percent, fill: 'bg-feedback-success' });
    const per100 = (p: string) => `${TAKA}${Math.round(Math.abs(Number(p)))}`;

    return (
        <div className="flex flex-col gap-3 border-t border-line-default pt-5 max-sm:hidden">
            <p className="t-admin-overline text-fg-tertiary">{t('reports.journey.per100')}</p>
            <div aria-hidden className="flex h-3.5 w-full gap-0.5 overflow-hidden rounded-xs">
                {parts.map((p) => (
                    <span key={p.key} className={`h-full ${p.fill}`} style={{ flexGrow: Math.abs(Number(p.share)), flexBasis: 0 }} />
                ))}
            </div>
            <ul className="flex flex-wrap gap-x-6 gap-y-2">
                {parts.map((p) => (
                    <li key={p.key} className="flex items-center gap-2">
                        <span aria-hidden className={`size-2.5 rounded-full ${p.fill}`} />
                        <span className="t-admin-body text-fg-secondary">{p.label}</span>
                        <span className={`t-admin-currency-num tabular ${p.key === 'net_profit' ? 'text-feedback-success' : 'text-fg-primary'}`}>{per100(p.share)}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** Axis label: the step name (strong for subtotals) and its share of revenue below it. */
function StepTick({ x, y, payload, steps }: { x?: number | string; y?: number | string; payload?: { value: string }; steps: Step[] }) {
    const { t } = useI18n();
    const step = steps.find((s) => s.key === payload?.value);
    if (!step) return null;
    return (
        <g transform={`translate(${Number(x ?? 0)},${Number(y ?? 0)})`}>
            <text y={14} textAnchor="middle" className={step.subtotal ? 't-admin-caption-strong' : 't-admin-caption'} fill={step.subtotal ? 'var(--color-fg-primary)' : 'var(--color-fg-secondary)'}>
                {t(`reports.journey.step.${step.key}` as MessageKey)}
            </text>
            <text y={34} textAnchor="middle" className="t-admin-caption" fill="var(--color-fg-tertiary)">
                {t('reports.journey.share', { percent: share(step.share) })}
            </text>
        </g>
    );
}

/** The value above each bar: a subtotal in currency/num, a cost smaller with its minus. */
function StepValue({ x, y, width, value, steps, restricted }: { x?: number | string; y?: number | string; width?: number | string; value?: unknown; steps: Step[]; restricted: string }) {
    const step = steps.find((s) => s.key === value);
    if (!step) return null;
    const cx = Number(x ?? 0) + Number(width ?? 0) / 2;
    return (
        <text x={cx} y={Number(y ?? 0) - 8} textAnchor="middle" className={step.subtotal ? 't-admin-currency-num' : 't-admin-currency-caption'} fill={step.subtotal ? 'var(--color-fg-primary)' : 'var(--color-fg-secondary)'}>
            {step.amount === null ? restricted : moneyText(step.amount)}
        </text>
    );
}

function Statement({ data, compare }: { data: PnlStatement; compare: Compare }) {
    const { t } = useI18n();
    const narrow = typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 1023px)').matches;
    // Below 1024 blocks 3–5 start folded to their subtotal (Figma 390 frame); everything is open on desktop.
    const [closed, setClosed] = useState<Set<BlockKey>>(() => new Set(narrow ? (['delivery', 'losses', 'running'] as BlockKey[]) : []));
    const toggle = (key: BlockKey) =>
        setClosed((current) => {
            const next = new Set(current);
            if (next.has(key)) next.delete(key);
            else next.add(key);
            return next;
        });

    return (
        <section aria-labelledby="pnl-statement" className="flex min-w-0 flex-1 flex-col gap-4">
            <div className="flex items-end gap-3 px-6 max-sm:px-0">
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <h2 id="pnl-statement" className="t-admin-section text-fg-primary">
                        {t('reports.pnl.statement')}
                    </h2>
                    <p className="t-admin-caption text-fg-secondary max-sm:hidden">{t('reports.pnl.statementSub')}</p>
                </div>
                <span aria-hidden className="w-22 shrink-0 text-end t-admin-overline whitespace-nowrap text-fg-secondary max-md:hidden">
                    {t('reports.pnl.col.share')}
                </span>
                <span aria-hidden className="w-28 shrink-0 text-end t-admin-overline text-fg-secondary max-md:hidden">
                    {t('reports.pnl.col.amount')}
                </span>
                <span aria-hidden className="w-6 shrink-0 max-md:hidden" />
            </div>
            {data.blocks.map((block) => (
                <Block key={block.key} data={data} block={block} open={!closed.has(block.key)} onToggle={() => toggle(block.key)} compare={compare} />
            ))}
        </section>
    );
}

function Block({ data, block, open, onToggle, compare }: { data: PnlStatement; block: PnlBlock; open: boolean; onToggle: () => void; compare: Compare }) {
    const { t } = useI18n();
    const bodyId = useId();
    const lines = block.rows.filter((row) => row.kind === 'line' && !(quietLines.has(row.key) && row.amount !== null && Number(row.amount) === 0));
    const subtotals = block.rows.filter((row) => row.kind === 'subtotal' && !hiddenSubtotals.has(row.key));
    const locked = lines.some((row) => row.amount === null);

    return (
        <article aria-labelledby={`${bodyId}-title`} className="overflow-hidden rounded-md border border-line-default bg-surface shadow-e1">
            <header className="flex items-start gap-4 px-6 pt-5 pb-4 max-sm:gap-3 max-sm:px-4">
                <span aria-hidden className={`grid size-9 shrink-0 place-items-center rounded-sm t-admin-num-md ${stepTiles[block.key]}`}>
                    {block.number}
                </span>
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <p className="t-admin-overline text-fg-tertiary">{t('reports.pnl.step', { number: block.number, name: t(`reports.pnl.stepName.${block.key}` as MessageKey) })}</p>
                    <h3 id={`${bodyId}-title`} className="t-admin-card-title text-fg-primary">
                        {t(`reports.pnl.block.${block.key}` as MessageKey)}
                    </h3>
                    {open && <p className="t-admin-body text-fg-secondary">{t(`reports.pnl.block.${block.key}.sub` as MessageKey)}</p>}
                </div>
                <button
                    type="button"
                    aria-expanded={open}
                    aria-controls={bodyId}
                    aria-label={t('reports.pnl.toggle', { number: block.number })}
                    onClick={onToggle}
                    className="-m-1 grid size-7 shrink-0 place-items-center rounded-xs text-fg-secondary hover:bg-subtle hover:text-fg-primary"
                >
                    {open ? <CaretUpIcon size={20} aria-hidden /> : <CaretDownIcon size={20} aria-hidden />}
                </button>
            </header>
            <div id={bodyId} hidden={!open}>
                {locked ? <LockedLine /> : lines.map((row) => <Line key={row.key} data={data} row={row} />)}
            </div>
            {subtotals.map((row) => (
                <Subtotal key={row.key} row={row} hint={open ? t('reports.pnl.leftAfter', { number: block.number }) : t('reports.pnl.linesInside', { count: formatCount(lines.length) })} />
            ))}
            {block.key === 'running' && <NetProfit data={data} compare={compare} />}
        </article>
    );
}

function Line({ data, row }: { data: PnlStatement; row: Row }) {
    const { t } = useI18n();
    const label = t(`reports.pnl.line.${row.key}` as MessageKey);
    const type = row.types?.[0];
    return (
        <div className="flex items-center gap-3 border-t border-line-default px-6 py-3 max-sm:px-4">
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="flex items-center gap-1 t-admin-body-strong text-fg-primary">
                    {label}
                    {tips.has(row.key) && <InfoTip label={label} text={t(`reports.pnl.tip.${row.key}` as MessageKey)} />}
                </span>
                {row.occurrences !== null && row.occurrences !== undefined && row.occurrences > 0 && <span className="t-admin-caption text-fg-tertiary">{t(`reports.pnl.caption.${row.key}` as MessageKey, { count: formatCount(row.occurrences) })}</span>}
            </div>
            <span className="w-22 shrink-0 text-end t-admin-caption text-fg-secondary tabular max-md:hidden">{row.percent_of_revenue === null ? '—' : `${share(row.percent_of_revenue)}%`}</span>
            <span className="w-28 shrink-0 text-end t-admin-currency-num text-fg-primary max-sm:w-auto">{row.amount !== null && <Money amount={row.amount} signClass="" />}</span>
            {type ? (
                <Link to={ledgerLink(data, type)} aria-label={t('reports.pnl.drill', { line: label })} className="-m-1 grid size-6 shrink-0 place-items-center rounded-xs text-fg-tertiary hover:bg-subtle hover:text-fg-primary max-md:hidden">
                    <CaretRightIcon size={16} aria-hidden />
                </Link>
            ) : (
                <span aria-hidden className="w-6 shrink-0 max-md:hidden" />
            )}
        </div>
    );
}

/** State A (Figma 384:68985): the reader lacks catalog.view_cost — the line is withheld, the subtotal stays. */
function LockedLine() {
    const { t } = useI18n();
    return (
        <div className="flex items-center gap-3 border-t border-line-default bg-subtle px-6 py-4 max-sm:px-4">
            <span aria-hidden className="grid size-8 shrink-0 place-items-center rounded-sm border border-line-default bg-surface text-fg-secondary">
                <LockSimpleIcon size={14} />
            </span>
            <div className="flex min-w-0 flex-col gap-0.5">
                <p className="t-admin-body text-fg-primary">{t('reports.pnl.locked.title')}</p>
                <p className="t-admin-caption text-fg-secondary">{t('reports.pnl.locked.body')}</p>
            </div>
        </div>
    );
}

function Subtotal({ row, hint }: { row: Row; hint: string }) {
    const { t } = useI18n();
    return (
        <div className="flex items-center gap-3 border-t border-line-default bg-subtle px-6 py-3.5 max-sm:px-4">
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="t-admin-body-strong text-fg-primary">{t('reports.pnl.equals', { label: t(`reports.pnl.line.${row.key}` as MessageKey) })}</span>
                <span className="t-admin-caption text-fg-tertiary">{hint}</span>
            </div>
            <span className="w-22 shrink-0 text-end t-admin-caption-strong text-fg-secondary tabular max-md:hidden">{row.percent_of_revenue === null ? '—' : `${share(row.percent_of_revenue)}%`}</span>
            <span className="w-28 shrink-0 text-end t-admin-currency-section text-fg-primary max-sm:w-auto">{row.amount === null ? <Restricted /> : <Money amount={row.amount} signClass="" />}</span>
            <span aria-hidden className="w-6 shrink-0 max-md:hidden" />
        </div>
    );
}

function NetProfit({ data, compare }: { data: PnlStatement; compare: Compare }) {
    const { t } = useI18n();
    const net = data.totals.net_profit;
    const loss = isNegative(net);
    const trend = useTrend(data, compare);
    const tone = loss ? 'text-feedback-danger' : 'text-feedback-success';
    return (
        <div className={`flex items-center gap-3 border-t border-line-default px-6 py-5 max-sm:px-4 ${loss ? 'bg-danger-subtle' : 'bg-success-subtle'}`}>
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <p className={`flex items-center gap-2 t-admin-section ${tone}`}>
                    {loss && <TrendDownIcon size={18} aria-hidden />}
                    {t('reports.pnl.equals', { label: t('reports.pnl.line.net_profit') })}
                </p>
                <p className="t-admin-caption text-fg-secondary">{[loss ? t('reports.pnl.loss') : t('reports.pnl.netHintPremium'), trend].filter(Boolean).join(' · ')}</p>
                {data.net_margin_percent !== null && <p className={`t-admin-caption-strong md:hidden ${tone}`}>{t('reports.pnl.margin', { percent: share(data.net_margin_percent) })}</p>}
            </div>
            <span className={`w-22 shrink-0 text-end t-admin-caption-strong tabular max-md:hidden ${tone}`}>{data.net_margin_percent !== null && t('reports.pnl.margin', { percent: share(data.net_margin_percent) })}</span>
            <span className={`shrink-0 text-end t-admin-currency-num-lg ${tone}`}>
                <Money amount={net} signClass="" />
            </span>
            <span aria-hidden className="w-6 shrink-0 max-md:hidden" />
        </div>
    );
}

/** A rail card (Figma "Cash, not profit" 382:68939 and siblings): tinted icon tile + title, then content. */
function RailCard({ icon: Icon, tone, title, danger = false, children }: { icon: ComponentType<IconProps>; tone: KpiTone | 'success'; title: string; danger?: boolean; children: ReactNode }) {
    const id = useId();
    const tiles: Record<KpiTone | 'success', string> = {
        brand: 'bg-brand-subtle text-fg-brand',
        warning: 'bg-warning-subtle text-feedback-warning',
        danger: 'bg-danger-subtle text-feedback-danger',
        info: 'bg-info-subtle text-feedback-info',
        neutral: 'bg-subtle text-fg-secondary',
        success: 'bg-success-subtle text-feedback-success',
    };
    return (
        <section aria-labelledby={id} className={`flex flex-col gap-4 rounded-md border bg-surface p-5 shadow-e1 ${danger ? 'border-line-danger' : 'border-line-default'}`}>
            <div className="flex items-center gap-3">
                <span aria-hidden className={`grid size-8 shrink-0 place-items-center rounded-sm ${tiles[tone]}`}>
                    <Icon size={16} />
                </span>
                <h2 id={id} className="t-admin-section text-fg-primary">
                    {title}
                </h2>
            </div>
            {children}
        </section>
    );
}

function RailRow({ label, hint, children, strong = false }: { label: string; hint?: ReactNode; children: ReactNode; strong?: boolean }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <div className="flex min-w-0 flex-col gap-0.5">
                <span className={strong ? 't-admin-body-strong text-fg-primary' : 't-admin-body text-fg-primary'}>{label}</span>
                {hint && <span className="t-admin-caption text-fg-tertiary">{hint}</span>}
            </div>
            <span className={`shrink-0 text-end ${strong ? 't-admin-currency-section' : 't-admin-currency-num'} text-fg-primary`}>{children}</span>
        </div>
    );
}

/** Ledger types with a label in the finance catalogue; anything else is shown by its code. */
const labelledTypes = new Set(['sale_revenue', 'cogs', 'discount_given', 'shipping_revenue', 'courier_charge', 'courier_cod_fee', 'courier_return_charge', 'gateway_fee', 'packaging', 'marketing', 'salary', 'rent', 'utility', 'refund', 'inventory_write_off', 'supplier_payment', 'cod_received', 'other_income', 'other_expense', 'pos_receipt', 'owner_capital_in', 'owner_drawing']);

function CashCard({ data }: { data: PnlStatement }) {
    const { t } = useI18n();
    const me = useMe().data;
    // "Still with the courier": COD recognised on delivery but not yet remitted, as at the
    // period's last Dhaka day — the server's receivables figure (rule 10), never derived here.
    const receivables = useReceivables(data.to, can(me, 'finance.view'));
    // "Owed back to a customer": refunds approved but not yet paid out, as at the same day (server figure).
    const owed = useRefundsOwed(data.to, can(me, 'finance.view'));
    const out = data.cash.out === '0.00' || isNegative(data.cash.out) ? data.cash.out : `-${data.cash.out}`;
    return (
        <RailCard icon={BankIcon} tone="info" title={t('reports.cash.title')}>
            <p className="t-admin-caption text-fg-secondary">{t('reports.cash.introPremium')}</p>
            <div className="flex flex-col gap-3">
                <RailRow label={t('reports.cash.in')}>
                    <Money amount={data.cash.in} signClass="" />
                </RailRow>
                <RailRow label={t('reports.cash.out')}>
                    <Money amount={out} signClass="" />
                </RailRow>
                <div className="flex items-center justify-between gap-3 border-y border-line-default py-3">
                    <span className="t-admin-body-strong text-fg-primary">{t('reports.cash.net')}</span>
                    <span className="t-admin-currency-section text-fg-brand">
                        <Money amount={data.cash.net} signClass="" plus />
                    </span>
                </div>
                {receivables.data && (
                    <RailRow label={t('reports.cash.courier')} hint={t('reports.cash.courierHint')}>
                        <Money amount={receivables.data.outstanding} signClass="" />
                    </RailRow>
                )}
                {owed.data && (
                    <RailRow label={t('reports.cash.owed')} hint={owed.data.count > 0 ? t('reports.cash.owedHint', { count: formatCount(owed.data.count) }) : undefined}>
                        <Money amount={owed.data.total === '0.00' ? owed.data.total : `-${owed.data.total}`} signClass="" />
                    </RailRow>
                )}
            </div>
            <div className="flex flex-col gap-2 rounded-sm bg-subtle p-3 t-admin-caption text-fg-secondary">
                {data.cash.by_type.length > 0 && (
                    <>
                        <p className="t-admin-caption-strong text-fg-primary">{t('reports.cash.byType')}</p>
                        <ul className="flex flex-col gap-1 tabular">
                            {data.cash.by_type.map((flow) => (
                                <li key={flow.type} className="flex justify-between gap-3">
                                    <span>{labelledTypes.has(flow.type) ? t(`finLedger.type.${flow.type}` as MessageKey) : flow.type}</span>
                                    <span className="text-end">{t('reports.cash.flow', { in: moneyText(flow.in), out: moneyText(flow.out) })}</span>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
                <p>{t('reports.cash.note')}</p>
            </div>
        </RailCard>
    );
}

/** "Does it tie up?" (Figma 382:68976) and its mismatch state (384:69003). */
function TieUp({ data, time }: { data: PnlStatement; time: string }) {
    const { t } = useI18n();
    const r = data.reconciliation;
    const ok = r.reconciled;
    return (
        <RailCard icon={ok ? CheckCircleIcon : XCircleIcon} tone={ok ? 'success' : 'danger'} title={t(ok ? 'reports.tie.title' : 'reports.tie.titleBad')} danger={!ok}>
            <p className="t-admin-caption text-fg-secondary">{ok ? t('reports.tie.intro') : t('reports.tie.introBad', { ledger: moneyText(r.total), statement: moneyText(data.totals.net_profit) })}</p>
            <div className="flex flex-col gap-3">
                <RailRow label={t('reports.tie.ordersPremium')} hint={t('reports.tie.ordersHint', { count: formatCount(r.order_count) })}>
                    <Money amount={r.orders} signClass="" />
                </RailRow>
                <RailRow label={t('reports.tie.otherPremium')}>
                    <Money amount={r.other} signClass="" />
                </RailRow>
                <div className="flex items-center justify-between gap-3 border-t border-line-default pt-3">
                    <span className="t-admin-body-strong text-fg-primary">{t(ok ? 'reports.tie.totalOk' : 'reports.tie.totalBad')}</span>
                    <span className={`t-admin-currency-section ${ok ? 'text-fg-primary' : 'text-feedback-danger'}`}>
                        <Money amount={r.total} signClass="" />
                    </span>
                </div>
            </div>
            {ok ? (
                <div>
                    <StatusChip tone="success" label={t('reports.tie.chipOk', { time })} />
                </div>
            ) : (
                <div role="alert" className="flex flex-col items-start gap-3">
                    <StatusChip tone="danger" label={t('reports.tie.chipBad', { amount: moneyText(magnitude(r.difference)) })} />
                    <Link to={`/finance/ledger?from=${data.from}&to=${data.to}&kind=recognised`} className={buttonClass('outline')}>
                        {t('reports.tie.open')}
                        <ArrowRightIcon size={16} aria-hidden />
                    </Link>
                </div>
            )}
        </RailCard>
    );
}

/** "Biggest costs after the toys" (Figma 382:69006): the server's top four, bars relative to the largest. */
function BiggestCosts({ data }: { data: PnlStatement }) {
    const { t } = useI18n();
    const types = (key: string) => findRow(data, key)?.types?.[0];
    return (
        <div className="max-lg:hidden">
            <RailCard icon={ChartBarIcon} tone="warning" title={t('reports.biggest.title')}>
                <p className="t-admin-caption text-fg-secondary">{data.biggest_costs.length > 0 ? t('reports.biggest.intro') : t('reports.biggest.empty')}</p>
                {data.biggest_costs.length > 0 && (
                    <ul className="flex flex-col gap-3.5">
                        {data.biggest_costs.map((c) => {
                            const type = types(c.key);
                            const body = (
                                <>
                                    <span className="flex items-center gap-2 t-admin-body text-fg-primary">
                                        <span className="min-w-0 flex-1">{t(`reports.pnl.line.${c.key}` as MessageKey)}</span>
                                        <span className="t-admin-currency-num">
                                            <Money amount={c.cost} signClass="" />
                                        </span>
                                    </span>
                                    <span aria-hidden className="block h-2 w-full overflow-hidden rounded-full bg-subtle">
                                        <span className={`block h-full rounded-full ${costFills[c.block]}`} style={{ width: `${clampPercent(c.relative_percent)}%` }} />
                                    </span>
                                </>
                            );
                            return (
                                <li key={c.key}>
                                    {type ? (
                                        <Link to={ledgerLink(data, type)} className="flex flex-col gap-1.5 rounded-xs hover:[&>span:first-child]:underline">
                                            {body}
                                        </Link>
                                    ) : (
                                        <div className="flex flex-col gap-1.5">{body}</div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </RailCard>
        </div>
    );
}

function NotInStatement({ data }: { data: PnlStatement }) {
    const { t } = useI18n();
    const pipeline = data.open_orders.by_status.map((s) => t('reports.notIn.status', { count: formatCount(s.count), status: t(`orders.status.${s.status}` as MessageKey).toLocaleLowerCase(), amount: moneyText(s.value) })).join(' · ');
    return (
        <RailCard icon={InfoIcon} tone="neutral" title={t('reports.notIn.overline')}>
            <p className="t-admin-caption text-fg-secondary">{t('reports.notIn.intro')}</p>
            <ul className="flex flex-col gap-3.5">
                <NotInItem icon={HourglassIcon} label={t('reports.notIn.pipeline')} hint={data.open_orders.count > 0 ? pipeline : t('reports.notIn.none')} amount={data.open_orders.value} />
                {data.inventory_at_cost !== null && (
                    <NotInItem icon={WarehouseIcon} label={t('reports.notIn.stockTitle')} hint={t('reports.notIn.stockHint')} amount={data.inventory_at_cost} to="/inventory/valuation" />
                )}
            </ul>
        </RailCard>
    );
}

function NotInItem({ icon: Icon, label, hint, amount, to }: { icon: ComponentType<IconProps>; label: string; hint: string; amount: string; to?: string }) {
    return (
        <li className="flex items-start gap-3">
            <Icon size={16} aria-hidden className="mt-0.5 shrink-0 text-fg-secondary" />
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                {to ? (
                    <Link to={to} className="t-admin-body text-fg-primary hover:underline">
                        {label}
                    </Link>
                ) : (
                    <span className="t-admin-body text-fg-primary">{label}</span>
                )}
                <span className="t-admin-caption text-fg-tertiary tabular">{hint}</span>
            </div>
            <span className="shrink-0 t-admin-currency-num text-fg-primary">
                <Money amount={amount} signClass="" />
            </span>
        </li>
    );
}

/** The ⓘ next to a line label: an explanation on hover and keyboard focus (Figma "Tooltip · Cost of goods sold" 383:68956). */
function InfoTip({ label, text }: { label: string; text: string }) {
    const { t } = useI18n();
    const id = useId();
    return (
        <span className="group/tip relative inline-flex">
            <button type="button" aria-label={t('reports.pnl.info', { line: label })} aria-describedby={id} className="grid size-5 place-items-center rounded-full text-fg-tertiary hover:text-fg-primary">
                <InfoIcon size={14} aria-hidden />
            </button>
            <span
                id={id}
                role="tooltip"
                className="pointer-events-none invisible absolute top-full left-0 z-20 mt-1 w-72 rounded-sm bg-fg-primary px-3 py-2.5 t-admin-caption text-fg-inverse opacity-0 shadow-e2 transition-opacity duration-150 ease-standard group-focus-within/tip:visible group-focus-within/tip:opacity-100 group-hover/tip:visible group-hover/tip:opacity-100"
            >
                <span className="block t-admin-caption-strong">{label}</span>
                {text}
            </span>
        </span>
    );
}
