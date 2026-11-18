import type { ReactNode } from 'react';
import type { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { formatDhakaDate } from '@/lib/format';
import type { StatusTone } from '@/components/ui/StatusChip';
import { TakaAmount } from '@/components/ui/TakaAmount';
import type { OrderReturn, RefundStatus, ReturnReason, ReturnStatus } from './api';

/* Money: the admin's one formatter (lib/money — lakh grouping, owner decision 2026-11-16). */
export { TAKA, formatMoney } from '@/lib/money';

/** A taka amount as Figma sets it: the sign in the currency face, figures in the surrounding style. */
export function Taka({ amount, signClass = 't-admin-currency-num' }: { amount: string; signClass?: string }) {
    return <TakaAmount amount={amount} signClass={signClass} />;
}

export const reasonLabel = (reason: ReturnReason): MessageKey => `returns.reason.${reason}` as MessageKey;
export const returnStatusLabel = (status: ReturnStatus): MessageKey => `returns.status.${status}` as MessageKey;
export const refundStatusLabel = (status: RefundStatus): MessageKey => `refunds.status.${status}` as MessageKey;

export const returnTone: Record<ReturnStatus, StatusTone> = {
    requested: 'info',
    received: 'warning',
    inspected: 'brand',
    closed: 'success',
    rejected: 'neutral',
};

export const refundTone: Record<RefundStatus, StatusTone> = {
    requested: 'warning',
    approved: 'info',
    processing: 'info',
    completed: 'success',
    failed: 'danger',
    rejected: 'neutral',
};

/** "2d" / "5h" since the return was opened. */
export function ageParts(iso: string | null, now: Date = new Date()): { key: MessageKey; vars: Record<string, number> } | null {
    if (!iso) return null;
    const hours = Math.max(0, Math.floor((now.getTime() - new Date(iso).getTime()) / 3_600_000));
    return hours >= 24 ? { key: 'returns.age.days', vars: { d: Math.floor(hours / 24) } } : { key: 'returns.age.hours', vars: { h: hours } };
}


/** The server's problem detail (its words, translated by the API), else a fallback. */
export function problemText(error: unknown, fallback: string): string {
    if (error instanceof ApiError) return error.problem.errors?.[0]?.message ?? error.problem.detail ?? error.problem.title;
    return fallback;
}

/** Figma resolution row 241:35893: secondary label left, strong value right, 6 vertical padding. */
export function ResolutionRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-center gap-2 py-1.5">
            <span className="min-w-0 flex-1 t-admin-body text-fg-secondary">{label}</span>
            <span className="t-admin-num tabular whitespace-nowrap text-fg-primary">{children}</span>
        </div>
    );
}


/** The row's button in the queue: what this staff member does next with the return. */
export function nextAction(r: OrderReturn): MessageKey {
    if (r.status === 'requested' || r.status === 'received') return r.can.manage ? 'returns.next.inspect' : 'returns.next.view';
    if (r.status === 'inspected' && r.refund) {
        if (r.refund.status === 'requested' && r.refund.can.approve) return 'returns.next.approve';
        if ((r.refund.status === 'approved' || r.refund.status === 'failed') && r.refund.can.pay) return 'returns.next.pay';
    }
    return 'returns.next.view';
}

/** Whole days the return has been open, for the queue's "waiting long" flag. */
export function daysOpen(iso: string | null, now: Date = new Date()): number {
    return iso ? Math.floor(Math.max(0, now.getTime() - new Date(iso).getTime()) / 86_400_000) : 0;
}

/** Three days open is long for a parcel or a payout (Figma B · 10 List 582:50341). */
export const LONG_WAIT_DAYS = 3;

export type StepState = 'done' | 'current' | 'upcoming' | 'stopped';
export type ReturnStep = { key: string; label: MessageKey; state: StepState; sub: string };

/**
 * Requested → Parcel back → Inspected → Refunded, laid out from the return's own status and
 * timestamps (Figma B · 10 Return detail 583:50649). A rejected return stops after the
 * steps it reached. Nothing here decides money: the refund step only reads the server's status.
 */
export function returnSteps(r: OrderReturn, t: ReturnType<typeof useI18n>['t'], locale: string): ReturnStep[] {
    const date = (iso: string | null) => (iso ? formatDhakaDate(iso, locale) : '—');
    const requested: ReturnStep = { key: 'requested', label: 'returns.step.requested', state: 'done', sub: date(r.created_at) };

    if (r.status === 'rejected') {
        const steps = [requested];
        if (r.received_at) steps.push({ key: 'received', label: 'returns.step.received', state: 'done', sub: date(r.received_at) });
        steps.push({ key: 'rejected', label: 'returns.step.rejected', state: 'stopped', sub: date(r.closed_at) });
        return steps;
    }

    const inspected = r.status === 'inspected' || r.status === 'closed';
    const received: ReturnStep = r.received_at
        ? { key: 'received', label: 'returns.step.received', state: 'done', sub: date(r.received_at) }
        : inspected
          ? { key: 'received', label: 'returns.step.received', state: 'done', sub: t('returns.step.withInspection') }
          : { key: 'received', label: 'returns.step.received', state: 'current', sub: t('returns.step.waiting') };
    const inspection: ReturnStep = inspected
        ? { key: 'inspected', label: 'returns.step.inspected', state: 'done', sub: date(r.inspected_at) }
        : { key: 'inspected', label: 'returns.step.inspected', state: r.status === 'received' ? 'current' : 'upcoming', sub: r.status === 'received' ? t('returns.step.waiting') : '—' };
    const refunded: ReturnStep =
        r.status === 'closed'
            ? { key: 'refunded', label: 'returns.step.refunded', state: 'done', sub: r.has_refund ? date(r.closed_at) : t('returns.step.noRefund') }
            : r.status === 'inspected'
              ? { key: 'refunded', label: 'returns.step.refunded', state: 'current', sub: r.refund ? t(refundStatusLabel(r.refund.status)) : t('returns.step.inProgress') }
              : { key: 'refunded', label: 'returns.step.refunded', state: 'upcoming', sub: '—' };
    return [requested, received, inspection, refunded];
}
