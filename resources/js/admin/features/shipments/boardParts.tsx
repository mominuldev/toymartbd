import type { ComponentType } from 'react';
import {
    ArrowArcLeftIcon,
    BicycleIcon,
    CheckCircleIcon,
    CircleDashedIcon,
    ClockClockwiseIcon,
    ClockIcon,
    PackageIcon,
    PauseCircleIcon,
    ProhibitIcon,
    TruckIcon,
    XCircleIcon,
    type IconProps,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import type { BoardStatus } from './boardApi';

/*
 * Display helpers for the shipments board. They group and trim the server's decimal strings
 * and format the server's instants in Dhaka time; they never compute money (rule 1).
 */

/** The taka sign — a currency symbol, not translatable copy. */
export const TAKA = '৳';

/** "1250.00" → "1,250"; "23.10" → "23.1". */
export function formatMoney(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const trimmed = fraction.replace(/0+$/, '');
    return `${negative ? '−' : ''}${grouped}${trimmed ? `.${trimmed}` : ''}`;
}

export function Taka({ amount, signClass = 't-admin-currency-num' }: { amount: string; signClass?: string }) {
    return (
        <>
            <span className={signClass}>{TAKA}</span>
            {formatMoney(amount)}
        </>
    );
}

/** "1,600 g" — Western digits in both locales (rule 36). */
export function grams(value: number): string {
    return `${value.toLocaleString('en-US')} g`;
}

const tag = (locale: string) => (locale === 'bn' ? 'bn-BD' : 'en-GB');

/** Dhaka day and time of an instant, Western digits (rules 11, 36). */
export function dhaka(iso: string, locale: string): { day: string; time: string } {
    const date = new Date(iso);
    const base = { timeZone: 'Asia/Dhaka', numberingSystem: 'latn' } as const;
    return {
        day: new Intl.DateTimeFormat(tag(locale), {
            ...base,
            day: '2-digit',
            month: 'short',
        }).format(date),
        time: new Intl.DateTimeFormat(tag(locale), {
            ...base,
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
        })
            .format(date)
            .toLowerCase(),
    };
}

/** A Dhaka calendar date (YYYY-MM-DD) as "02 Nov". */
export function shortDay(date: string, locale: string): string {
    return new Intl.DateTimeFormat(tag(locale), {
        day: '2-digit',
        month: 'short',
        timeZone: 'UTC',
        numberingSystem: 'latn',
    }).format(new Date(`${date}T00:00:00Z`));
}

/** Today's Dhaka calendar date, YYYY-MM-DD. */
export function dhakaToday(now: Date = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Dhaka',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(now);
}

/** A calendar date `days` before a YYYY-MM-DD date — calendar arithmetic, not money. */
export function daysBefore(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() - days);
    return d.toISOString().slice(0, 10);
}

/** The date-range presets; the server's default (no from/to) is the last 7 Dhaka days. */
export const rangePresets = ['today', '7d', '30d', '90d'] as const;
export type RangePreset = (typeof rangePresets)[number];
const presetDays: Record<RangePreset, number> = {
    today: 0,
    '7d': 6,
    '30d': 29,
    '90d': 89,
};

export function presetRange(preset: RangePreset, today: string): { from: string; to: string } {
    return { from: daysBefore(today, presetDays[preset]), to: today };
}

const look: Record<BoardStatus, { icon: ComponentType<IconProps>; tone: StatusTone }> = {
    pending: { icon: ClockIcon, tone: 'neutral' },
    submitted: { icon: ClockClockwiseIcon, tone: 'anomaly' },
    booked: { icon: CheckCircleIcon, tone: 'info' },
    picked_up: { icon: PackageIcon, tone: 'info' },
    in_transit: { icon: TruckIcon, tone: 'info' },
    out_for_delivery: { icon: BicycleIcon, tone: 'brand' },
    delivered: { icon: CheckCircleIcon, tone: 'success' },
    partially_delivered: { icon: CircleDashedIcon, tone: 'warning' },
    returned: { icon: ArrowArcLeftIcon, tone: 'danger' },
    cancelled: { icon: ProhibitIcon, tone: 'neutral' },
    on_hold: { icon: PauseCircleIcon, tone: 'warning' },
    failed: { icon: XCircleIcon, tone: 'danger' },
};

export function statusIcon(status: string): ComponentType<IconProps> {
    return (look as Record<string, { icon: ComponentType<IconProps> } | undefined>)[status]?.icon ?? CircleDashedIcon;
}

/** A status's label: the courier screens' wording, except "Submitted — awaiting ID" (rule 24d). */
export function statusKey(status: string): MessageKey {
    if (status === 'submitted') return 'shipmentsBoard.status.submitted';
    return (status in look ? `couriers.status.${status}` : 'shipmentsBoard.status.unknown') as MessageKey;
}

/**
 * Figma StatusChip with the status's own glyph. `submitted` takes the anomaly look — the 2px
 * warning left edge — so a parcel without a consignment id never reads as booked (rule 24d).
 */
export function BoardStatusChip({ status }: { status: BoardStatus }) {
    const { t } = useI18n();
    const { icon, tone } = look[status] ?? look.pending;
    return <StatusChip label={t(statusKey(status))} tone={tone} icon={icon} />;
}

/** How an event was learned. Unknown sources read as "other", never dropped. */
export function sourceKey(source: string): MessageKey {
    return `shipmentsBoard.source.${['webhook', 'poll', 'manual', 'booking'].includes(source) ? source : 'other'}` as MessageKey;
}

export function SourceChip({ source }: { source: string }) {
    const { t } = useI18n();
    return <span className="inline-flex h-5 items-center rounded-xs border border-line-default bg-subtle px-1.5 t-admin-caption whitespace-nowrap text-fg-secondary">{t(sourceKey(source))}</span>;
}

/** Elapsed minutes as "5h 25m" — Western digits. */
export function hoursMinutes(minutes: number): { h: number; m: number } {
    return { h: Math.floor(minutes / 60), m: minutes % 60 };
}
