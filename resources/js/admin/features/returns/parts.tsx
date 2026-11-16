import type { ReactNode } from 'react';
import { ArrowUUpLeftIcon } from '@phosphor-icons/react';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import type { StatusTone } from '@/components/ui/StatusChip';
import { TakaAmount } from '@/components/ui/TakaAmount';
import type { RefundStatus, ReturnReason, ReturnStatus } from './api';

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

/** Figma "Wants" chip 241:35863: bg/subtle, hairline, 14px icon, Admin/caption-strong. */
export function WantsChip({ label, icon = <ArrowUUpLeftIcon size={14} aria-hidden /> }: { label: string; icon?: ReactNode }) {
    return (
        <span className="inline-flex items-center gap-1 rounded-xs border border-line-default bg-subtle px-2 py-0.75 t-admin-caption-strong whitespace-nowrap text-fg-primary">
            {icon}
            {label}
        </span>
    );
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

export function Overline({ children }: { children: ReactNode }) {
    return <p className="t-admin-overline text-fg-tertiary uppercase">{children}</p>;
}
