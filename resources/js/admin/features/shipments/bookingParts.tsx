import type { ComponentType } from 'react';
import { CheckCircleIcon, FlaskIcon, InfoIcon, PlugsIcon, ProhibitIcon, type IconProps } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { timeAgo } from '@/lib/format';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import type { BlockedReason, BookingCourier, BookingSummary } from './bookingApi';
import { dhakaTime, taka, wallClock } from './bookingFormat';

/** Courier location mapping (P6) — the screen that fixes an unmapped area; takes `?provider=`. */
export const BOOKING_MAPPING_PATH = '/shipments/locations';
/** The retry queue for failed bookings (P6). */
export const BOOKING_FAILED_PATH = '/shipments/failed';

export type CourierState = 'live' | 'untested' | 'notConnected' | 'off';

/** Live = credentials + a booking has succeeded; untested = credentials, never verified (rule 33a). */
export function courierState(c: Pick<BookingCourier, 'is_active' | 'trust'>): CourierState {
    if (!c.is_active) return 'off';
    if (c.trust === 'not_connected') return 'notConnected';
    return c.trust === 'verified' ? 'live' : 'untested';
}

const stateLook: Record<CourierState, { icon: ComponentType<IconProps>; tint: string }> = {
    live: { icon: CheckCircleIcon, tint: 'text-feedback-success' },
    untested: { icon: FlaskIcon, tint: 'text-status-low-stock' },
    notConnected: { icon: PlugsIcon, tint: 'text-fg-tertiary' },
    off: { icon: ProhibitIcon, tint: 'text-fg-tertiary' },
};

export const blockedKey = (reason: BlockedReason): MessageKey => `bookingQueue.blocked.${reason}` as MessageKey;

export function courierName(c: { name: string; name_bn: string | null }, locale: string): string {
    return locale === 'bn' && c.name_bn ? c.name_bn : c.name;
}

/** "Ready-to-ship orders · Pathao live · Steadfast untested · today's pickup cut-off 4:00 pm" — from the server's facts. */
export function useSubtitle(summary: BookingSummary | undefined): string | undefined {
    const { t, locale } = useI18n();
    if (!summary) return undefined;
    const parts = [t('bookingQueue.subtitle.lead')];
    for (const c of summary.couriers) {
        const state = courierState(c);
        const name = courierName(c, locale);
        parts.push(t(`bookingQueue.subtitle.${state === 'live' ? 'live' : state === 'untested' ? 'untested' : state}` as MessageKey, { name }));
    }
    parts.push(
        t('bookingQueue.subtitle.cutoff', {
            time: wallClock(summary.cutoff_time, locale),
        })
    );
    return parts.join(' · ');
}

/**
 * B · 07 courier card: state icon, name, "Live · 47 bookings · webhook healthy 2 min ago", and
 * the courier's own balance on the right — only when the system has stored one; never ৳0.
 */
export function CourierCard({ courier }: { courier: BookingCourier }) {
    const { t, locale } = useI18n();
    const state = courierState(courier);
    const { icon: Icon, tint } = stateLook[state];
    const webhook = courier.webhook;
    const facts = [
        t(`bookingQueue.card.${state}` as MessageKey),
        t('bookingQueue.card.bookings', { count: courier.bookings_count }),
        t('bookingQueue.card.today', { count: courier.booked_today }),
        webhook.health === 'healthy' || webhook.health === 'failing'
            ? t(`bookingQueue.card.webhook.${webhook.health}`, {
                  ago: webhook.last_received_at ? timeAgo(webhook.last_received_at, locale) : '',
              })
            : t(`bookingQueue.card.webhook.${webhook.health}`),
    ];
    return (
        <li className="flex min-w-0 items-start gap-3 rounded-md border border-line-default bg-surface px-4 py-3.5 shadow-e1">
            <Icon size={20} aria-hidden className={`mt-0.5 shrink-0 ${tint}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <p className="t-admin-body-strong text-fg-primary">{courierName(courier, locale)}</p>
                <p className="t-admin-caption text-fg-secondary">{facts.join(' · ')}</p>
                {courier.blocked_reason && (
                    <p className="t-admin-caption text-fg-tertiary">
                        {t('bookingQueue.card.blocked', {
                            reason: t(blockedKey(courier.blocked_reason)),
                        })}
                    </p>
                )}
            </div>
            {courier.balance !== null && (
                <div className="flex shrink-0 flex-col items-end text-end">
                    <span
                        className="t-admin-num text-fg-primary"
                        aria-label={t('bookingQueue.card.balance', {
                            amount: taka(courier.balance),
                        })}
                    >
                        {taka(courier.balance)}
                    </span>
                    {courier.balance_checked_at && (
                        <span className="t-admin-caption text-fg-tertiary">
                            {t('bookingQueue.card.balanceChecked', {
                                time: dhakaTime(courier.balance_checked_at, locale).time,
                            })}
                        </span>
                    )}
                </div>
            )}
        </li>
    );
}

/** The honest rule-24d notice under the ready table: 3px low-stock edge, caption body. */
export function BulkNotice() {
    const { t } = useI18n();
    return (
        <p role="note" className="flex items-start gap-3 rounded-sm border-l-3 border-l-status-low-stock bg-subtle px-4 py-3 t-admin-body text-fg-primary">
            <InfoIcon size={18} aria-hidden className="mt-0.5 shrink-0 text-status-low-stock" />
            {t('bookingQueue.notice')}
        </p>
    );
}

const statusTone: Record<string, StatusTone> = {
    pending: 'neutral',
    submitted: 'warning',
    failed: 'danger',
    booked: 'success',
};

/** "Submitted — awaiting ID" is never shown as booked (rule 24d). */
export function QueueStatusChip({ status }: { status: string }) {
    const { t } = useI18n();
    const known = status === 'pending' || status === 'submitted' || status === 'failed';
    const label = t((known ? `bookingQueue.status.${status}` : `couriers.status.${status}`) as MessageKey);
    return <StatusChip label={label} tone={statusTone[status] ?? 'info'} />;
}
