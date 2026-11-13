import type { ReactNode } from 'react';
import { CheckCircleIcon, FlaskIcon, PlugsConnectedIcon, WarningCircleIcon, type IconProps } from '@phosphor-icons/react';
import type { ComponentType } from 'react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import type { DispatchStatus } from './api';

export const TRACKING_PATH = '/integrations/tracking';
export const DIAGNOSTICS_PATH = '/integrations/tracking/diagnostics';

/** Figma toggle (B · 25 cards 241:31103): 36×20 track, bg/brand on, border/strong off. */
export function Switch({ checked, label, onChange, disabled }: { checked: boolean; label: string; onChange: (value: boolean) => void; disabled?: boolean }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors duration-150 ease-standard disabled:cursor-not-allowed disabled:opacity-60 ${
                checked ? 'bg-brand' : 'bg-line-strong'
            }`}
        >
            <span aria-hidden className={`size-4 rounded-full bg-surface shadow-e1 transition-transform duration-150 ease-standard ${checked ? 'translate-x-4' : 'translate-x-0'}`} />
        </button>
    );
}

export type CardState = 'connected' | 'attention' | 'testMode' | 'notConnected' | 'off';

const cardChip: Record<CardState, { tone: StatusTone; icon: ComponentType<IconProps>; label: MessageKey }> = {
    connected: { tone: 'success', icon: CheckCircleIcon, label: 'tracking.state.connected' },
    attention: { tone: 'danger', icon: WarningCircleIcon, label: 'tracking.state.attention' },
    testMode: { tone: 'warning', icon: FlaskIcon, label: 'tracking.state.testMode' },
    notConnected: { tone: 'neutral', icon: PlugsConnectedIcon, label: 'tracking.state.notConnected' },
    off: { tone: 'neutral', icon: PlugsConnectedIcon, label: 'tracking.state.off' },
};

type DestinationCardProps = {
    title: string;
    /** The public id (measurement id, pixel id, container id) in mono, or a "not set" caption. */
    id: string | null;
    state: CardState;
    caption: string;
    toggle?: ReactNode;
    action?: ReactNode;
};

/**
 * One destination tile (Figma B · 25 grid 241:31099, IntegrationCard 203:2257 family): title
 * + switch, the mono id, a StatusChip, a caption; "Needs attention" swaps the hairline for a
 * 3px danger edge (241:31120). The footer action ("Settings") is IntegrationCard's.
 */
export function DestinationCard({ title, id, state, caption, toggle, action }: DestinationCardProps) {
    const { t } = useI18n();
    const chip = cardChip[state];
    const frame = state === 'attention' ? 'border-l-3 border-feedback-danger' : 'border border-line-default';
    return (
        <section aria-label={title} className={`flex min-w-0 flex-col gap-2 overflow-hidden rounded-md bg-surface p-4 ${frame}`}>
            <div className="flex items-center gap-2">
                <h2 className="min-w-0 flex-1 truncate t-admin-body-strong text-fg-primary">{title}</h2>
                {toggle}
            </div>
            <p className={`truncate ${id ? 't-admin-mono-sm text-fg-secondary' : 't-admin-caption text-fg-tertiary'}`}>{id ?? t('tracking.card.noId')}</p>
            <div className="flex items-center justify-between gap-2">
                <StatusChip tone={chip.tone} icon={chip.icon} label={t(chip.label)} />
                {action}
            </div>
            <p className="t-admin-caption text-fg-tertiary">{caption}</p>
        </section>
    );
}

const statusTone: Record<DispatchStatus, StatusTone> = {
    pending: 'info',
    sent: 'success',
    failed: 'danger',
    skipped_consent: 'warning',
    skipped_disabled: 'neutral',
    skipped_stale: 'warning',
    not_applicable: 'neutral',
};

/** A dispatch status as StatusChip 185:79: icon + label, never colour alone. */
export function DispatchChip({ status }: { status: DispatchStatus }) {
    const { t } = useI18n();
    return <StatusChip tone={statusTone[status]} label={t(`tracking.status.${status}` as MessageKey)} showIcon={status !== 'not_applicable'} />;
}

/** StatCard 189:360: overline label, value, caption; `edge` = the 3px danger edge for an open problem. */
export function Stat({ label, hint, edge, children }: { label: string; hint: ReactNode; edge?: boolean; children: ReactNode }) {
    return (
        <div className={`flex min-w-0 flex-col gap-1.5 overflow-hidden rounded-md bg-surface p-4 shadow-e1 ${edge ? 'border-l-3 border-feedback-danger' : 'border border-line-default'}`}>
            <p className="t-admin-overline text-fg-secondary">{label}</p>
            <p className="t-admin-num-lg tabular text-fg-primary">{children}</p>
            <p className="t-admin-caption text-fg-tertiary">{hint}</p>
        </div>
    );
}

function tag(locale: string): string {
    return locale === 'bn' ? 'bn-BD' : 'en-GB';
}

/** Dhaka day + time, Western digits in both locales (rules 11, 36). */
export function dhakaWhen(iso: string, locale: string): { day: string; time: string } {
    const date = new Date(iso);
    return {
        day: new Intl.DateTimeFormat(tag(locale), { timeZone: 'Asia/Dhaka', day: '2-digit', month: 'short', numberingSystem: 'latn' }).format(date),
        time: new Intl.DateTimeFormat(tag(locale), { timeZone: 'Asia/Dhaka', hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true, numberingSystem: 'latn' })
            .format(date)
            .toLowerCase(),
    };
}

/** "12 sec ago" — Western digits in both locales (rule 36). */
export function timeAgo(iso: string, locale: string, now: number = Date.now()): string {
    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);
    const format = new Intl.RelativeTimeFormat(`${tag(locale)}-u-nu-latn`, { numeric: 'auto', style: 'short' });
    const abs = Math.abs(seconds);
    if (abs < 60) return format.format(seconds, 'second');
    if (abs < 3600) return format.format(Math.round(seconds / 60), 'minute');
    if (abs < 86400) return format.format(Math.round(seconds / 3600), 'hour');
    return format.format(Math.round(seconds / 86400), 'day');
}

/** Today's Dhaka calendar date, Y-m-d (rule 11). */
export function dhakaToday(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

export const TAKA = '৳';

/** Display only — groups the server's decimal string: "1250.00" → "1,250.00" (rule 1: no arithmetic). */
export function formatMoney(decimal: string, currency: string | null): string {
    const [whole = '', fraction = ''] = decimal.split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const amount = fraction ? `${grouped}.${fraction}` : grouped;
    return currency === null || currency === 'BDT' ? `${TAKA}${amount}` : `${currency} ${amount}`;
}
