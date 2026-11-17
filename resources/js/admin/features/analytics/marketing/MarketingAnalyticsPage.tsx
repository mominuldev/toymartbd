import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { useForm, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { ChartLineUpIcon, CoinsIcon, PencilSimpleIcon, PlusIcon, ShoppingBagIcon, StackIcon } from '@phosphor-icons/react';
import { useSearchParams } from 'react-router';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ApiError } from '@/lib/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Field } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { AnalyticsTabs } from '../AnalyticsTabs';
import { PlainWords } from '../PlainWords';
import { channelLabel } from '../sales/SalesAnalyticsPage';
import { Delta, Money, TAKA, dhakaToday, formatCount, formatDay, takaText } from '../sales/format';
import {
    adSpendInputSchema,
    rangePresets,
    useAdSpend,
    useDeleteAdSpend,
    useMarketingAnalytics,
    useSaveAdSpend,
    type AdSpend,
    type AdSpendInput,
    type CampaignRow,
    type DealRow,
    type MarketingAnalytics,
    type MarketingFilters,
    type RangePreset,
} from './api';

const DATE = /^\d{4}-\d{2}-\d{2}$/;
type T = (key: MessageKey, vars?: Record<string, string | number>) => string;

/** Filters live in the URL so a report is a shareable link; the server validates every value. */
function useFilters(): [MarketingFilters, (entries: Record<string, string>) => void] {
    const [params, setParams] = useSearchParams();
    const date = (key: string) => (DATE.test(params.get(key) ?? '') ? params.get(key)! : '');
    const rangeParam = params.get('range') as RangePreset | null;
    const filters: MarketingFilters = {
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

/** A server ratio ("4.60") as display text ("4.6"): trailing zeros trimmed, nothing computed. */
export function ratioText(value: string): string {
    return value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
}

/** "Nov 2026" for a YYYY-MM month, in the UI language with Western digits (rule 36). */
function monthText(month: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { month: 'short', year: 'numeric', timeZone: 'UTC', numberingSystem: 'latn' }).format(new Date(`${month}-01T00:00:00Z`));
}

const sourceKeys: Record<string, MessageKey> = {
    facebook: 'analyticsMarketing.source.facebook',
    google: 'analyticsMarketing.source.google',
    tiktok: 'analyticsMarketing.source.tiktok',
    youtube: 'analyticsMarketing.source.youtube',
};
function sourceLabel(source: string | null, t: T): string | null {
    if (!source) return null;
    const key = sourceKeys[source.toLowerCase()];
    return key ? t(key) : source;
}

function dealType(deal: DealRow, t: T): string {
    if (deal.type === 'free_shipping' || deal.type === 'free_delivery') return t('analyticsMarketing.deals.kind.freeDelivery');
    return t(deal.kind === 'coupon' ? 'analyticsMarketing.deals.kind.coupon' : 'analyticsMarketing.deals.kind.promotion');
}

/** The three plain sentences (Figma 522:81016 "In plain words"): every figure is the server's. */
export function marketingSentences(data: MarketingAnalytics, t: T): string[] {
    const lines: string[] = [];
    const s = data.summary;
    const one = takaText('1');
    if (s.return_ratio !== null) {
        lines.push(t('analyticsMarketing.plain.spend', { spend: takaText(s.spend), revenue: takaText(s.revenue), ratio: takaText(s.return_ratio), one }));
    } else {
        lines.push(t('analyticsMarketing.plain.noSpend'));
    }
    const best = data.highlights.best_campaign;
    if (best) {
        const source = sourceLabel(best.source, t);
        const vars = { campaign: best.campaign, revenue: takaText(best.delivered_revenue), spend: takaText(best.spend) };
        lines.push(source ? t('analyticsMarketing.plain.best', { ...vars, source }) : t('analyticsMarketing.plain.bestNoSource', vars));
    }
    const coupons = data.deal_totals.coupon;
    if (coupons.orders > 0) {
        const top = data.highlights.top_new_customer_coupon;
        const vars = { cost: takaText(coupons.cost), orders: formatCount(coupons.orders) };
        lines.push(top ? t('analyticsMarketing.plain.coupons', { ...vars, coupon: top.name }) : t('analyticsMarketing.plain.couponsNoNew', vars));
    }
    return lines;
}

/**
 * Analytics → Marketing ROI (Figma B · 24 premium pass 522:81016 at 1440, 522:81516 at 390): what
 * each campaign, coupon and promotion brought in against what it cost. Spend is typed in by staff
 * (marketing.manage); every figure — prorated spend, delivered revenue, return, cost per order —
 * is the server's (rule 1). Revenue is counted on delivery (rule 10); days are Dhaka days (rule 11).
 */
export function MarketingAnalyticsPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [filters, set] = useFilters();
    const report = useMarketingAnalytics(filters);
    const spend = useAdSpend(filters);
    const data = report.data;
    const ids = useId();
    const today = dhakaToday();
    const manage = can(me, 'marketing.manage');
    const [editing, setEditing] = useState<AdSpend | 'new' | null>(null);
    const spendCard = useRef<HTMLDivElement>(null);

    const range = data ? (data.range.from === data.range.to ? formatDay(data.range.from, locale) : `${formatDay(data.range.from, locale, false)} – ${formatDay(data.range.to, locale)}`) : '';
    const openForm = (target: AdSpend | 'new') => {
        setEditing(target);
        requestAnimationFrame(() => spendCard.current?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' }));
    };

    const spendSection = (
        <div ref={spendCard} className="min-w-0">
            <SpendCard rows={spend.data?.data ?? []} suggestions={spend.data?.meta.campaigns ?? []} manage={manage} editing={editing} onEdit={openForm} onClose={() => setEditing(null)} />
        </div>
    );

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('courierReport.crumb') }]}
                title={t('analyticsMarketing.title')}
                subtitle={t('analyticsMarketing.subtitle')}
                actions={
                    <>
                        <label htmlFor={`${ids}-range`} className="sr-only">
                            {t('reports.period.label')}
                        </label>
                        <Select
                            id={`${ids}-range`}
                            value={filters.range}
                            onChange={(e) => {
                                const next = e.target.value as RangePreset;
                                set(next === 'custom' ? { range: next, from: filters.from || data?.range.from || today, to: filters.to || data?.range.to || today } : { range: next === '7d' ? '' : next, from: '', to: '' });
                            }}
                            className="w-full sm:w-48"
                        >
                            {rangePresets.map((p) => (
                                <option key={p} value={p}>
                                    {t(`sales.range.${p}` as MessageKey)}
                                </option>
                            ))}
                        </Select>
                        {filters.range === 'custom' && (
                            <>
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
                            </>
                        )}
                        {manage && (
                            <Button onClick={() => openForm('new')} className="w-full gap-2 sm:w-auto">
                                <PlusIcon size={16} aria-hidden />
                                {t('analyticsMarketing.addSpend')}
                            </Button>
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
            ) : data.empty && (spend.data?.data.length ?? 0) === 0 ? (
                <>
                    <EmptyState title={t('analyticsMarketing.empty', { range })} body={t('analyticsMarketing.emptyBody')} />
                    {(manage || editing !== null) && spendSection}
                </>
            ) : (
                <>
                    <PlainWords label={t('analyticsMarketing.plain.label', { range })} lines={marketingSentences(data, t)} />
                    <Stats data={data} />
                    <CampaignsCard data={data} />
                    <div className="grid grid-cols-1 items-start gap-4 xl:grid-cols-2">
                        <DealsCard deals={data.deals} />
                        {spendSection}
                    </div>
                    <p className="t-admin-caption text-fg-secondary">{t('analyticsMarketing.note')}</p>
                </>
            )}
        </section>
    );
}

/** Figma StatCard: overline + icon tile, figure, caption. Compact (2×2) at 390. */
function Stat({ label, icon, value, delta, hint }: { label: string; icon: ReactNode; value: ReactNode; delta?: ReactNode; hint: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-1.5 rounded-md border border-line-default bg-surface p-3 shadow-e1 sm:p-4">
            <div className="flex items-start justify-between gap-2">
                <p className="t-admin-overline text-fg-secondary">{label}</p>
                <span aria-hidden className="hidden size-8 shrink-0 items-center justify-center rounded-sm bg-brand-subtle text-fg-brand sm:flex">
                    {icon}
                </span>
            </div>
            <p className="t-admin-num-lg text-fg-primary">{value}</p>
            {delta}
            <p className="t-admin-caption text-fg-tertiary tabular">{hint}</p>
        </div>
    );
}

function Stats({ data }: { data: MarketingAnalytics }) {
    const { t } = useI18n();
    const s = data.summary;
    const vs = t('sales.vsPrevious');
    const one = takaText('1');
    return (
        <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <Stat
                label={t('analyticsMarketing.stat.spend')}
                icon={<StackIcon size={16} />}
                value={<Money amount={s.spend} signClass="t-admin-currency-num-lg" />}
                delta={<Delta value={data.deltas.spend} caption={vs} />}
                hint={t('analyticsMarketing.stat.spendHint', { count: formatCount(s.campaigns) })}
            />
            <Stat
                label={t('analyticsMarketing.stat.back')}
                icon={<CoinsIcon size={16} />}
                value={<Money amount={s.revenue} signClass="t-admin-currency-num-lg" />}
                delta={<Delta value={data.deltas.revenue} caption={vs} />}
                hint={t('analyticsMarketing.stat.backHint')}
            />
            <Stat
                label={t('analyticsMarketing.stat.return')}
                icon={<ChartLineUpIcon size={16} />}
                value={s.return_ratio === null ? <span className="text-fg-tertiary">—</span> : <span className="tabular">{t('analyticsMarketing.times', { value: ratioText(s.return_ratio) })}</span>}
                hint={s.return_ratio === null ? t('analyticsMarketing.stat.returnNone') : t('analyticsMarketing.stat.returnHint', { ratio: takaText(s.return_ratio), one })}
            />
            <Stat
                label={t('analyticsMarketing.stat.cpo')}
                icon={<ShoppingBagIcon size={16} />}
                value={<Money amount={s.cost_per_order} signClass="t-admin-currency-num-lg" />}
                hint={t('analyticsMarketing.stat.cpoHint', { orders: formatCount(s.orders) })}
            />
        </div>
    );
}

/** A card panel: radius/md, hairline, e1, 20 padding; title Admin/card-title + caption. */
function Card({ title, subtitle, actions, children }: { title: string; subtitle?: string; actions?: ReactNode; children: ReactNode }) {
    const id = useId();
    return (
        <section aria-labelledby={id} className="flex min-w-0 flex-col gap-3 rounded-md border border-line-default bg-surface p-4 shadow-e1 sm:p-5">
            <header className="flex flex-wrap items-start gap-2">
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <h2 id={id} className="t-admin-card-title text-fg-primary">
                        {title}
                    </h2>
                    {subtitle && <p className="t-admin-caption text-fg-tertiary">{subtitle}</p>}
                </div>
                {actions}
            </header>
            {children}
        </section>
    );
}

/** Bar ∝ return against the best row — a display proportion only; the figure beside it is the server's. */
function ReturnBar({ ratio, max }: { ratio: string | null; max: number }) {
    const { t } = useI18n();
    if (ratio === null) return <span className="text-fg-tertiary">—</span>;
    const n = Number(ratio);
    const width = max > 0 ? Math.max(2, Math.round((n / max) * 100)) : 0;
    const tone = n >= 3 ? 'bg-feedback-success' : n >= 1 ? 'bg-brand' : 'bg-feedback-warning';
    return (
        <span className="flex items-center justify-end gap-3">
            <span aria-hidden className="hidden h-1.5 w-32 overflow-hidden rounded-full bg-line-default lg:block">
                <span className={`block h-full rounded-full ${tone}`} style={{ width: `${width}%` }} />
            </span>
            <span className="t-admin-body-strong tabular text-fg-primary">{t('analyticsMarketing.times', { value: ratioText(ratio) })}</span>
        </span>
    );
}

function CampaignsCard({ data }: { data: MarketingAnalytics }) {
    const { t } = useI18n();
    const max = Math.max(0, ...data.campaigns.map((c) => (c.return_ratio === null ? 0 : Number(c.return_ratio))));
    const untagged = data.untagged;
    const untaggedSources = untagged.sources.map((s) => channelLabel(s, t)).join(', ');
    const showUntagged = untagged.orders > 0 || untagged.delivered_orders > 0;
    const sub = (c: CampaignRow) => sourceLabel(c.source, t) ?? (c.has_spend ? '' : t('analyticsMarketing.noSpend'));

    return (
        <Card title={t('analyticsMarketing.campaigns.title')} subtitle={t('analyticsMarketing.campaigns.subtitle')}>
            <div className="hidden md:block">
                <table className="w-full table-fixed border-collapse">
                    <thead>
                        <tr className="border-b border-line-default text-start">
                            <th scope="col" className="w-2/6 py-2 text-start t-admin-overline text-fg-secondary">
                                {t('analyticsMarketing.col.campaign')}
                            </th>
                            <th scope="col" className="py-2 text-end t-admin-overline text-fg-secondary">
                                {t('analyticsMarketing.col.spend')}
                            </th>
                            <th scope="col" className="py-2 text-end t-admin-overline text-fg-secondary">
                                {t('analyticsMarketing.col.orders')}
                            </th>
                            <th scope="col" className="py-2 text-end t-admin-overline text-fg-secondary">
                                {t('analyticsMarketing.col.delivered')}
                            </th>
                            <th scope="col" className="w-1/4 py-2 text-end t-admin-overline text-fg-secondary">
                                {t('analyticsMarketing.col.return')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.campaigns.map((c) => (
                            <tr key={c.campaign} className="border-b border-line-default">
                                <th scope="row" className="py-3.5 pe-3 text-start font-normal">
                                    <span className="block truncate t-admin-body-strong text-fg-primary">{c.campaign}</span>
                                    {sub(c) && <span className="block truncate t-admin-caption text-fg-secondary">{sub(c)}</span>}
                                </th>
                                <td className="py-3.5 text-end t-admin-num text-fg-primary">{c.has_spend ? <Money amount={c.spend} /> : <span className="text-fg-tertiary">—</span>}</td>
                                <td className="py-3.5 text-end t-admin-num tabular text-fg-primary">{formatCount(c.orders)}</td>
                                <td className="py-3.5 text-end t-admin-num text-fg-primary">
                                    <Money amount={c.delivered_revenue} />
                                </td>
                                <td className="py-3.5 text-end">
                                    <ReturnBar ratio={c.return_ratio} max={max} />
                                </td>
                            </tr>
                        ))}
                        {showUntagged && (
                            <tr>
                                <th scope="row" className="py-3.5 pe-3 text-start font-normal">
                                    <span className="block t-admin-body-strong text-fg-primary">{t('analyticsMarketing.noTag')}</span>
                                    {untaggedSources && <span className="block truncate t-admin-caption text-fg-secondary">{untaggedSources}</span>}
                                </th>
                                <td className="py-3.5 text-end text-fg-tertiary">—</td>
                                <td className="py-3.5 text-end t-admin-num tabular text-fg-primary">{formatCount(untagged.orders)}</td>
                                <td className="py-3.5 text-end t-admin-num text-fg-primary">
                                    <Money amount={untagged.delivered_revenue} />
                                </td>
                                <td className="py-3.5 text-end text-fg-tertiary">—</td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            {/* 390 (522:81516): campaigns as a short list. */}
            <ul className="flex flex-col md:hidden">
                {data.campaigns.map((c) => (
                    <li key={c.campaign} className="flex items-start gap-3 border-b border-line-default py-3 last:border-b-0">
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span className="truncate t-admin-body-strong text-fg-primary">{c.campaign}</span>
                            <span className="t-admin-caption tabular text-fg-secondary">
                                {t('analyticsMarketing.mobileRow', { spend: c.has_spend ? takaText(c.spend) : '—', orders: formatCount(c.orders), delivered: takaText(c.delivered_revenue) })}
                            </span>
                        </div>
                        <span className="shrink-0 t-admin-body-strong tabular text-fg-primary">{c.return_ratio === null ? '—' : t('analyticsMarketing.times', { value: ratioText(c.return_ratio) })}</span>
                    </li>
                ))}
                {showUntagged && (
                    <li className="flex items-start gap-3 py-3">
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span className="t-admin-body-strong text-fg-primary">{t('analyticsMarketing.noTag')}</span>
                            <span className="t-admin-caption tabular text-fg-secondary">
                                {t('analyticsMarketing.mobileRow', { spend: '—', orders: formatCount(untagged.orders), delivered: takaText(untagged.delivered_revenue) })}
                            </span>
                        </div>
                        <span className="shrink-0 text-fg-tertiary">—</span>
                    </li>
                )}
            </ul>
        </Card>
    );
}

function DealsCard({ deals }: { deals: DealRow[] }) {
    const { t, locale } = useI18n();
    return (
        <Card title={t('analyticsMarketing.deals.title')} subtitle={t('analyticsMarketing.deals.subtitle')}>
            {deals.length === 0 ? (
                <p className="t-admin-body text-fg-tertiary">{t('analyticsMarketing.deals.empty')}</p>
            ) : (
                <ul className="flex flex-col">
                    {deals.map((d) => (
                        <li key={`${d.kind}-${d.id}`} className="flex items-start gap-3 border-t border-line-default py-3.5">
                            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className="truncate t-admin-body-strong text-fg-primary">{locale === 'bn' && d.name_bn ? d.name_bn : d.name}</span>
                                <span className="t-admin-caption tabular text-fg-secondary">
                                    {[dealType(d, t), t('analyticsMarketing.deals.orders', { count: formatCount(d.orders) }), ...(d.new_customers > 0 ? [t('analyticsMarketing.deals.newCustomers', { count: formatCount(d.new_customers) })] : [])].join(' · ')}
                                </span>
                            </div>
                            <div className="flex shrink-0 flex-col items-end gap-0.5">
                                <span className="t-admin-caption tabular text-fg-secondary">{t('analyticsMarketing.deals.cost', { amount: takaText(d.cost) })}</span>
                                <span className="t-admin-body-strong tabular text-fg-primary">{t('analyticsMarketing.deals.brought', { amount: takaText(d.delivered_value) })}</span>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

function SpendCard({
    rows,
    suggestions,
    manage,
    editing,
    onEdit,
    onClose,
}: {
    rows: AdSpend[];
    suggestions: string[];
    manage: boolean;
    editing: AdSpend | 'new' | null;
    onEdit: (target: AdSpend | 'new') => void;
    onClose: () => void;
}) {
    const { t, locale } = useI18n();
    return (
        <Card
            title={t('analyticsMarketing.spend.title')}
            subtitle={t('analyticsMarketing.spend.subtitle')}
            actions={
                manage && editing === null ? (
                    <Button variant="ghost" size="sm" className="gap-1.5" onClick={() => onEdit('new')}>
                        <PlusIcon size={16} aria-hidden />
                        {t('analyticsMarketing.spend.add')}
                    </Button>
                ) : undefined
            }
        >
            {rows.length === 0 ? (
                <p className="t-admin-body text-fg-tertiary">{t('analyticsMarketing.spend.empty')}</p>
            ) : (
                <ul className="flex flex-col">
                    {rows.map((row) => (
                        <li key={row.id} className="flex items-center gap-3 border-t border-line-default py-3.5">
                            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className="truncate t-admin-body-strong text-fg-primary">{row.campaign}</span>
                                <span className="t-admin-caption tabular text-fg-secondary">
                                    {[monthText(row.month, locale), row.entered_by?.name, row.entered_on ? formatDay(row.entered_on, locale, false) : null].filter(Boolean).join(' · ')}
                                </span>
                            </div>
                            <span className="shrink-0 t-admin-num text-fg-primary">
                                <Money amount={row.amount} />
                            </span>
                            {row.can.update && (
                                <IconButton aria-label={t('analyticsMarketing.spend.edit', { campaign: row.campaign })} size="sm" onClick={() => onEdit(row)}>
                                    <PencilSimpleIcon size={16} aria-hidden />
                                </IconButton>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {manage && editing !== null && <SpendForm key={editing === 'new' ? 'new' : editing.id} spend={editing === 'new' ? undefined : editing} suggestions={suggestions} onClose={onClose} />}
        </Card>
    );
}

type FormValues = { campaign: string; source: string; month: string; amount: string };
const fields: (keyof FormValues)[] = ['campaign', 'source', 'month', 'amount'];
const validateInput = zodResolver(adSpendInputSchema);

function toInput(values: FormValues, spend?: AdSpend): AdSpendInput {
    return {
        campaign: values.campaign.trim(),
        source: values.source.trim() === '' ? null : values.source.trim(),
        month: values.month,
        amount: values.amount.trim().replace(/,/g, ''),
        note: spend?.note ?? null,
    };
}

/** Add / edit ad spend: campaign (suggested from orders' utm_campaign), where it ran, month, amount. */
function SpendForm({ spend, suggestions, onClose }: { spend?: AdSpend; suggestions: string[]; onClose: () => void }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const save = useSaveAdSpend(spend?.id ?? null);
    const remove = useDeleteAdSpend();
    const [confirming, setConfirming] = useState(false);
    const titleId = useId();
    const listId = useId();
    const sourceListId = useId();
    const thisMonth = dhakaToday().slice(0, 7);
    const firstField = useRef<HTMLInputElement | null>(null);

    const { register, handleSubmit, setError, formState } = useForm<FormValues>({
        defaultValues: { campaign: spend?.campaign ?? '', source: spend?.source ?? '', month: spend?.month ?? thisMonth, amount: spend?.amount ?? '' },
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values, spend), context, options as never);
            return Object.keys(result.errors).length > 0 ? { values: {}, errors: result.errors as never } : { values, errors: {} };
        },
    });
    const campaignField = register('campaign');

    useEffect(() => {
        firstField.current?.focus();
    }, []);

    const submit = handleSubmit((values) =>
        save.mutate(toInput(values, spend), {
            onSuccess: () => {
                notify(t(spend ? 'analyticsMarketing.form.saved' : 'analyticsMarketing.form.added'));
                onClose();
            },
            onError: (error) => {
                if (error instanceof ApiError && error.status === 422) {
                    for (const field of fields) {
                        const message = error.fieldError(field);
                        if (message) setError(field as FieldPath<FormValues>, { message });
                    }
                } else {
                    notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
                }
            },
        }),
    );
    const errorOf = (field: keyof FormValues) => formState.errors[field]?.message;

    return (
        <form onSubmit={submit} noValidate aria-labelledby={titleId} className="flex flex-col gap-4 rounded-md bg-canvas p-4 sm:p-5">
            <h3 id={titleId} className="t-admin-overline text-fg-secondary">
                {t(spend ? 'analyticsMarketing.form.editTitle' : 'analyticsMarketing.form.addTitle')}
            </h3>
            <fieldset disabled={save.isPending || remove.isPending} className="flex min-w-0 flex-col gap-4">
                <Field label={t('analyticsMarketing.form.campaign')} hint={t('analyticsMarketing.form.campaignHint')} error={errorOf('campaign')} required>
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            aria-describedby={describedBy}
                            aria-invalid={invalid || undefined}
                            list={listId}
                            autoComplete="off"
                            {...campaignField}
                            ref={(el) => {
                                campaignField.ref(el);
                                firstField.current = el;
                            }}
                        />
                    )}
                </Field>
                <datalist id={listId}>
                    {suggestions.map((s) => (
                        <option key={s} value={s} />
                    ))}
                </datalist>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field label={t('analyticsMarketing.form.source')} error={errorOf('source')} optional>
                        {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} list={sourceListId} autoComplete="off" {...register('source')} />}
                    </Field>
                    <datalist id={sourceListId}>
                        {Object.keys(sourceKeys).map((s) => (
                            <option key={s} value={s} />
                        ))}
                    </datalist>
                    <Field label={t('analyticsMarketing.form.month')} error={errorOf('month')} required>
                        {({ id, describedBy, invalid }) => <Input id={id} type="month" max={thisMonth} min="2020-01" aria-describedby={describedBy} aria-invalid={invalid || undefined} className="tabular" {...register('month')} />}
                    </Field>
                    <Field label={t('analyticsMarketing.form.amount')} error={errorOf('amount')} required>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} inputMode="decimal" aria-describedby={describedBy} aria-invalid={invalid || undefined} className="tabular" prefix={<span className="t-admin-num text-fg-tertiary">{TAKA}</span>} {...register('amount')} />
                        )}
                    </Field>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Button type="submit" className="max-sm:w-full">
                        {t('analyticsMarketing.form.save')}
                    </Button>
                    <Button variant="ghost" onClick={onClose} className="max-sm:w-full">
                        {t('analyticsMarketing.form.cancel')}
                    </Button>
                    {spend?.can.delete && (
                        <Button variant="danger" onClick={() => setConfirming(true)} className="max-sm:w-full sm:ms-auto">
                            {t('analyticsMarketing.form.delete')}
                        </Button>
                    )}
                </div>
            </fieldset>
            {spend && (
                <ConfirmDialog
                    open={confirming}
                    title={t('analyticsMarketing.delete.title')}
                    body={t('analyticsMarketing.delete.body', { campaign: spend.campaign, month: monthText(spend.month, locale), amount: takaText(spend.amount) })}
                    confirmLabel={t('analyticsMarketing.delete.confirm')}
                    pending={remove.isPending}
                    onClose={() => setConfirming(false)}
                    onConfirm={() =>
                        remove.mutate(spend.id, {
                            onSuccess: () => {
                                setConfirming(false);
                                notify(t('analyticsMarketing.form.deleted'));
                                onClose();
                            },
                            onError: (error) => notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger'),
                        })
                    }
                />
            )}
        </form>
    );
}
