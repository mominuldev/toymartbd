import { useId, type ReactNode } from 'react';
import { Link } from 'react-router';
import {
    ArrowClockwiseIcon,
    ArrowRightIcon,
    ArrowUUpLeftIcon,
    ArrowUpRightIcon,
    CheckCircleIcon,
    CheckIcon,
    ClockIcon,
    DownloadSimpleIcon,
    GaugeIcon,
    InfoIcon,
    MapPinIcon,
    PackageIcon,
    PauseCircleIcon,
    ProhibitIcon,
    ReceiptIcon,
    ScalesIcon,
    ShieldWarningIcon,
    StackIcon,
    TrendDownIcon,
    TrendUpIcon,
    TruckIcon,
    WarningCircleIcon,
    type Icon,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { buttonClass } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { dashboardExportUrl, rangePresets, type ActivityEvent, type AttentionItem, type Dashboard, type DashboardRange, type DashboardRangePreset } from './api';
import { TAKA, MINUS, deltaText, formatCount, groupDecimal, isNegative, minutesSince, dhakaTime } from './format';

/** A taka amount as Figma sets it: true minus, ৳ in the currency face, figures in the surrounding style. */
export function Money({ amount, signClass = 't-admin-currency-num' }: { amount: string; signClass?: string }) {
    const negative = isNegative(amount);
    return (
        <span className="tabular whitespace-nowrap">
            {negative ? MINUS : ''}
            <span className={signClass}>{TAKA}</span>
            {groupDecimal(amount.replace(/^-/, ''))}
        </span>
    );
}

/** Card shell: surface, hairline border, radius/md, Elevation/Neutral e1 (Figma StatCard / ChartCard). */
export function Card({ children, className = '', label }: { children: ReactNode; className?: string; label?: string }) {
    return (
        <section aria-label={label} className={`min-w-0 rounded-md border border-line-default bg-surface shadow-e1 ${className}`}>
            {children}
        </section>
    );
}

/** Card header: Admin/section title, Admin/caption subtitle, an action on the right (ChartCard header 189:362). */
export function CardHeader({ title, subtitle, action }: { title: string; subtitle?: ReactNode; action?: ReactNode }) {
    return (
        <div className="flex items-start gap-3">
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <h2 className="t-admin-section text-fg-primary">{title}</h2>
                {subtitle !== undefined && <p className="t-admin-caption text-fg-tertiary">{subtitle}</p>}
            </div>
            {action}
        </div>
    );
}

/**
 * Sparkline (Figma StatCard "Sparkline", 236 × 32): brand line over a brand-subtle area; a
 * hairline zero baseline when the series crosses zero (B1 card 4). Points are the server's.
 */
export function Sparkline({ values, label }: { values: number[]; label: string }) {
    if (values.length < 2) return <div className="h-8" aria-hidden />;
    const width = 236;
    const height = 32;
    const min = Math.min(0, ...values);
    const max = Math.max(0, ...values);
    const span = max - min || 1;
    const x = (i: number) => (i / (values.length - 1)) * width;
    const y = (v: number) => height - 2 - ((v - min) / span) * (height - 4);
    const line = values.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ');
    const crosses = min < 0 && max > 0;
    return (
        <svg role="img" aria-label={label} viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" className="h-8 w-full overflow-visible">
            <polygon points={`0,${y(0)} ${line} ${width},${y(0)}`} fill="var(--color-brand-subtle)" />
            {crosses && <line x1="0" x2={width} y1={y(0)} y2={y(0)} stroke="var(--color-line-default)" strokeWidth="1" vectorEffect="non-scaling-stroke" />}
            <polyline points={line} fill="none" stroke="var(--color-brand)" strokeWidth="1.5" strokeLinejoin="round" vectorEffect="non-scaling-stroke" />
        </svg>
    );
}

/** The delta row: trend icon (success up / danger down), "+37.8%", then the comparison caption. */
export function Delta({ percent, caption, inverse = false }: { percent: string | null | undefined; caption: ReactNode; inverse?: boolean }) {
    const { t } = useI18n();
    if (percent === null || percent === undefined) {
        return (
            <p className="flex min-w-0 items-center gap-1 t-admin-caption text-fg-tertiary">
                <span>{t('dash.kpi.noBase')}</span>
                {caption !== null && <span className="truncate">· {caption}</span>}
            </p>
        );
    }
    const down = isNegative(percent);
    const good = inverse ? down : !down;
    const TrendIcon = down ? TrendDownIcon : TrendUpIcon;
    return (
        <p className="flex min-w-0 items-center gap-1">
            <TrendIcon size={14} aria-hidden className={good ? 'shrink-0 text-feedback-success' : 'shrink-0 text-feedback-danger'} />
            <span className="t-admin-caption-strong text-fg-primary whitespace-nowrap">{deltaText(percent)}</span>
            <span className="min-w-0 truncate t-admin-caption text-fg-tertiary">{caption}</span>
        </p>
    );
}

/**
 * Figma StatCard (241:29862): overline label + drill-through arrow (or info), 26px value,
 * delta row, optional sparkline. The whole label row links to the screen behind the number.
 */
export function StatCard({ label, to, info, value, delta, footnote, sparkline }: { label: string; to?: string; info?: string; value: ReactNode; delta?: ReactNode; footnote?: ReactNode; sparkline?: ReactNode }) {
    const { t } = useI18n();
    return (
        <article className="flex min-w-0 flex-col gap-2 overflow-hidden rounded-md border border-line-default bg-surface p-4 shadow-e1">
            <div className="flex items-center gap-1.5">
                <h3 className="min-w-0 flex-1 truncate t-admin-overline text-fg-secondary">{label}</h3>
                {info !== undefined ? (
                    <span title={info} aria-label={info} role="img" className="text-fg-secondary">
                        <InfoIcon size={14} aria-hidden />
                    </span>
                ) : to !== undefined ? (
                    <Link to={to} aria-label={t('dash.kpi.open', { label })} className="text-fg-secondary hover:text-fg-primary">
                        <ArrowUpRightIcon size={14} aria-hidden />
                    </Link>
                ) : null}
            </div>
            <p className="t-admin-num-lg text-fg-primary">{value}</p>
            {delta}
            {footnote !== undefined && <div className="t-admin-caption text-fg-secondary">{footnote}</div>}
            {sparkline}
        </article>
    );
}

type StatusKey = keyof NonNullable<Dashboard['status']>['counts'];

/** The strip's cells, left to right (04-admin-spec B1 R3, plus On hold — see the report). */
const STATUS_CELLS: { key: StatusKey; icon: Icon; queue?: boolean }[] = [
    { key: 'pending', icon: ClockIcon, queue: true },
    { key: 'on_hold', icon: PauseCircleIcon },
    { key: 'confirmed', icon: CheckIcon },
    { key: 'processing', icon: ArrowClockwiseIcon },
    { key: 'ready_to_ship', icon: PackageIcon, queue: true },
    { key: 'shipped', icon: TruckIcon },
    { key: 'delivered', icon: CheckCircleIcon },
    { key: 'completed', icon: CheckIcon },
    { key: 'partially_returned', icon: ArrowUUpLeftIcon },
    { key: 'returned', icon: ArrowUUpLeftIcon },
    { key: 'cancelled', icon: ProhibitIcon },
];

/**
 * Status strip (241:29950). Desktop: one row of equal cells with hairline dividers; Pending and
 * Ready to ship carry a 2px brand bottom edge (the queues that cost money to leave alone). Zero
 * counts render in text/tertiary and are not links. Under 1024 it becomes a scrolling chip row.
 */
export function StatusStrip({ counts }: { counts: NonNullable<Dashboard['status']>['counts'] }) {
    const { t } = useI18n();
    return (
        <nav aria-label={t('dash.status.label')}>
            <ul className="flex gap-2 overflow-x-auto pb-1 lg:hidden">
                {STATUS_CELLS.filter((cell) => counts[cell.key] > 0).map(({ key }) => {
                    const label = t(`orders.status.${key}` as MessageKey);
                    return (
                        <li key={key} className="shrink-0">
                            <Link
                                to={`/orders?tab=${key}`}
                                aria-label={t('dash.status.link', { status: label, count: formatCount(counts[key]) })}
                                className={`flex h-8 items-center gap-1 rounded-sm border px-2.5 t-admin-caption-strong text-fg-primary ${key === 'pending' ? 'border-line-brand bg-brand-subtle' : 'border-line-default bg-surface'}`}
                            >
                                {label} <span className="tabular">{formatCount(counts[key])}</span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
            <ul className="hidden overflow-hidden rounded-md border border-line-default bg-surface shadow-e1 lg:grid lg:grid-cols-6 xl:grid-cols-11">
                {STATUS_CELLS.map(({ key, icon: CellIcon, queue }) => {
                    const count = counts[key];
                    const label = t(`orders.status.${key}` as MessageKey);
                    const body = (
                        <>
                            <CellIcon size={14} aria-hidden className="text-fg-primary" />
                            <span className={`t-admin-num-lg tabular ${count === 0 ? 'text-fg-tertiary' : 'text-fg-primary'}`}>{formatCount(count)}</span>
                            <span className="t-admin-caption text-fg-secondary text-center">{label}</span>
                        </>
                    );
                    const cell = `flex h-full flex-col items-center gap-1 px-2 py-3 ${queue ? 'border-b-2 border-b-brand' : ''}`;
                    return (
                        <li key={key} className="border-l border-line-default first:border-l-0 [&:nth-child(7)]:border-l-0 xl:[&:nth-child(7)]:border-l lg:[&:nth-child(n+7)]:border-t xl:[&:nth-child(n+7)]:border-t-0">
                            {count > 0 ? (
                                <Link to={`/orders?tab=${key}`} aria-label={t('dash.status.link', { status: label, count: formatCount(count) })} className={`${cell} hover:bg-subtle`}>
                                    {body}
                                </Link>
                            ) : (
                                <div className={cell}>{body}</div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

/** DateRangePicker (Figma 193:741) as native controls: a preset select, and from/to for a custom range. */
export function RangePicker({ range, shown, onChange, today }: { range: DashboardRange; shown: { from: string; to: string }; onChange: (next: DashboardRange) => void; today: string }) {
    const { t } = useI18n();
    const id = useId();
    return (
        <div className="flex w-full flex-wrap items-center gap-2 lg:w-auto">
            <label htmlFor={`${id}-preset`} className="sr-only">
                {t('dash.range.label')}
            </label>
            <Select
                id={`${id}-preset`}
                value={range.preset}
                className="w-full lg:w-60"
                onChange={(e) => {
                    const preset = e.target.value as DashboardRangePreset;
                    onChange(preset === 'custom' ? { preset, from: shown.from, to: shown.to } : { preset, from: '', to: '' });
                }}
            >
                {rangePresets.map((p) => (
                    <option key={p} value={p}>
                        {t(`dash.range.${p}` as MessageKey)}
                    </option>
                ))}
            </Select>
            {range.preset === 'custom' && (
                <>
                    <label htmlFor={`${id}-from`} className="sr-only">
                        {t('dash.range.from')}
                    </label>
                    <div className="w-40 shrink-0">
                        <Input id={`${id}-from`} type="date" max={range.to || today} value={range.from} onChange={(e) => e.target.value && onChange({ ...range, from: e.target.value })} className="tabular" />
                    </div>
                    <label htmlFor={`${id}-to`} className="sr-only">
                        {t('dash.range.to')}
                    </label>
                    <div className="w-40 shrink-0">
                        <Input id={`${id}-to`} type="date" min={range.from} max={today} value={range.to} onChange={(e) => e.target.value && onChange({ ...range, to: e.target.value })} className="tabular" />
                    </div>
                </>
            )}
        </div>
    );
}

/**
 * Figma B · 01 header Export button (AdminButton outline md, download icon, 241:29860) opening
 * the two formats. Plain download links for the range on screen; the server builds the file with
 * only the blocks this role may see, so the menu has nothing to compute.
 */
export function ExportMenu({ range }: { range: DashboardRange }) {
    const { t, locale } = useI18n();
    return (
        <details className="relative">
            <summary className={`${buttonClass('outline')} cursor-pointer list-none [&::-webkit-details-marker]:hidden`} title={t('dash.export.hint')}>
                <DownloadSimpleIcon size={16} aria-hidden />
                {t('dash.export.button')}
            </summary>
            <div className="absolute end-0 z-10 mt-1 flex w-48 flex-col rounded-sm border border-line-default bg-surface p-1 shadow-e2">
                <a href={dashboardExportUrl(range, 'xlsx', locale)} download className="rounded-xs px-3 py-2 t-admin-body text-fg-primary hover:bg-subtle">
                    {t('dash.export.xlsx')}
                </a>
                <a href={dashboardExportUrl(range, 'csv', locale)} download className="rounded-xs px-3 py-2 t-admin-body text-fg-primary hover:bg-subtle">
                    {t('dash.export.csv')}
                </a>
            </div>
        </details>
    );
}

type AttentionKey = AttentionItem['key'];

const ATTENTION: Record<AttentionKey, { icon: Icon; edge: string; to: string; primary?: boolean }> = {
    verification: { icon: ShieldWarningIcon, edge: 'border-l-feedback-danger', to: '/risk/queue', primary: true },
    failed_bookings: { icon: WarningCircleIcon, edge: 'border-l-feedback-danger', to: '/shipments/failed' },
    unmapped_areas: { icon: MapPinIcon, edge: 'border-l-status-low-stock', to: '/shipments/locations' },
    submitted: { icon: GaugeIcon, edge: 'border-l-status-low-stock', to: '/orders?tab=ready_to_ship' },
    cod_variances: { icon: ScalesIcon, edge: 'border-l-status-low-stock', to: '/finance/settlements' },
    expenses: { icon: ReceiptIcon, edge: 'border-l-status-low-stock', to: '/finance/expenses?status=pending' },
    refunds: { icon: ArrowUUpLeftIcon, edge: 'border-l-status-low-stock', to: '/returns' },
    low_stock: { icon: StackIcon, edge: 'border-l-status-low-stock', to: '/inventory?state=low' },
};

function useAge() {
    const { t } = useI18n();
    return (iso: string) => {
        const minutes = minutesSince(iso);
        if (minutes < 60) return t('dash.age.minutes', { m: String(minutes) });
        if (minutes < 1440) return t('dash.age.hours', { h: String(Math.floor(minutes / 60)), m: String(minutes % 60) });
        return t('dash.age.days', { d: String(Math.floor(minutes / 1440)), h: String(Math.floor((minutes % 1440) / 60)) });
    };
}

/**
 * Needs-attention stack (241:30076): one card per non-empty queue — a 3px coloured left edge,
 * icon + Admin/body-strong title, an Admin/caption age line, and a sm button to the queue.
 * `compact` is the 768 layout (229:21176): button on the right.
 */
export function AttentionStack({ items, compact = false }: { items: AttentionItem[]; compact?: boolean }) {
    const { t } = useI18n();
    const age = useAge();
    const open = items.filter((item) => item.count > 0);
    return (
        <section aria-label={t('dash.attention.title', { count: String(open.length) })} className="flex flex-col gap-4">
            <h2 className="t-admin-overline text-fg-secondary">{t('dash.attention.title', { count: String(open.length) })}</h2>
            {open.length === 0 ? (
                <p className="flex items-center gap-1.5 t-admin-caption text-fg-secondary">
                    <CheckCircleIcon size={14} aria-hidden className="text-feedback-success" />
                    {t('dash.attention.empty')}
                </p>
            ) : (
                open.map((item) => {
                    const meta = ATTENTION[item.key];
                    const AttentionIcon = meta.icon;
                    const button = (
                        <Link to={meta.to} className={`${buttonClass(meta.primary ? 'primary' : 'outline', 'sm')} self-start`}>
                            {t(`dash.attention.${item.key}.action` as MessageKey)}
                        </Link>
                    );
                    return (
                        <article key={item.key} className={`flex gap-2 rounded-md border-l-3 bg-surface px-4 py-3.5 ${meta.edge} ${compact ? 'items-center' : 'flex-col'}`}>
                            <div className="flex min-w-0 flex-1 flex-col gap-2">
                                <h3 className="flex items-center gap-2 t-admin-body-strong text-fg-primary">
                                    <AttentionIcon size={16} aria-hidden className="shrink-0" />
                                    {t(`dash.attention.${item.key}` as MessageKey, { count: formatCount(item.count) })}
                                </h3>
                                {item.oldest_at !== null && <p className="t-admin-caption text-fg-secondary">{t('dash.attention.oldest', { age: age(item.oldest_at) })}</p>}
                            </div>
                            {button}
                        </article>
                    );
                })
            )}
        </section>
    );
}

const ACTIVITY_TYPES = new Set(['placed', 'status_changed', 'payment_status_changed', 'fulfillment_status_changed', 'note', 'edited', 'return_requested', 'return_inspected', 'refund_approved', 'refund_rejected', 'refund_paid']);

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]!.toUpperCase())
        .join('');
}

/** Activity feed (ActivityFeed 241:30075): one row per order event, rendered from translated templates. */
export function ActivityFeed({ events }: { events: ActivityEvent[] }) {
    const { t, locale } = useI18n();
    const statusLabel = (field: string | null, value: string | null) => {
        if (value === null) return '';
        return field === 'status' || field === null ? t(`orders.status.${value}` as MessageKey) : value.replaceAll('_', ' ');
    };
    return (
        <Card className="flex flex-col">
            <div className="border-b border-line-default px-5 py-4">
                <h2 className="t-admin-section text-fg-primary">{t('dash.activity.title')}</h2>
            </div>
            {events.length === 0 ? (
                <p className="px-5 py-6 t-admin-caption text-fg-secondary">{t('dash.activity.empty')}</p>
            ) : (
                <ol className="divide-y divide-line-default">
                    {events.map((event) => {
                        const actor = event.actor ?? t('dash.activity.system');
                        const type = ACTIVITY_TYPES.has(event.type) ? event.type : 'other';
                        const knownStatus = event.field === 'status' || event.field === null;
                        const sentence = t(`dash.activity.${type}` as MessageKey, {
                            actor,
                            order: event.order_number,
                            from: knownStatus && event.from !== null ? statusLabel(event.field, event.from) : (event.from ?? ''),
                            to: knownStatus && event.to !== null ? statusLabel(event.field, event.to) : (event.to ?? ''),
                        });
                        return (
                            <li key={event.id} className="flex gap-3 px-5 py-3">
                                <span aria-hidden className="flex size-8 shrink-0 items-center justify-center rounded-full bg-subtle t-admin-caption-strong text-fg-primary">
                                    {initials(actor)}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="t-admin-body text-fg-primary">
                                        <Link to={`/orders/${event.order_id}`} className="hover:underline">
                                            {sentence}
                                        </Link>
                                    </p>
                                    <p className="t-admin-caption text-fg-tertiary tabular">{dhakaTime(event.at, locale)}</p>
                                </div>
                            </li>
                        );
                    })}
                </ol>
            )}
        </Card>
    );
}

/** "All products →" style header link (AdminButton ghost sm). */
export function HeaderLink({ to, children }: { to: string; children: ReactNode }) {
    return (
        <Link to={to} className={buttonClass('ghost', 'sm')}>
            {children}
            <ArrowRightIcon size={14} aria-hidden />
        </Link>
    );
}
