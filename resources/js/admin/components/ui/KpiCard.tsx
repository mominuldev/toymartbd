import type { ComponentType, ReactNode } from 'react';
import { CaretRightIcon, type IconProps } from '@phosphor-icons/react';

export type KpiTone = 'brand' | 'warning' | 'danger' | 'info' | 'neutral';

const kpiTiles: Record<KpiTone, string> = {
    brand: 'bg-brand-subtle text-fg-brand',
    warning: 'bg-warning-subtle text-feedback-warning',
    danger: 'bg-danger-subtle text-feedback-danger',
    info: 'bg-info-subtle text-feedback-info',
    neutral: 'bg-subtle text-fg-secondary',
};

type KpiCardProps = {
    label: string;
    value: ReactNode;
    hint: ReactNode;
    icon: ComponentType<IconProps>;
    tone?: KpiTone;
    /** A shortcut card (Low stock → the Low stock tab, Pending → the Pending tab): the whole card is the button. */
    onClick?: () => void;
    /** The shortcut's view is the one showing. */
    active?: boolean;
    /** Below 640 the card is one of a compact row: label and figure only (the tile and caption return from 640). */
    compact?: boolean;
    className?: string;
};

/**
 * Admin KPI card (Figma KpiCard 363:2852, Premium pass 366:65205), shared by Inventory and the
 * Orders board so the two stay identical: overline label with a tinted 32px icon tile at its
 * end, the figure, a caption. No status edge — the tile carries the tone and the label says what
 * it is, so nothing rests on colour alone. A shortcut card lifts on hover, ends its caption with
 * a caret, and is pressed (2px brand border: hairline + inset ring) while its view is showing.
 */
export function KpiCard({ label, value, hint, icon: Icon, tone = 'neutral', onClick, active = false, compact = false, className = '' }: KpiCardProps) {
    const frame = `flex min-w-0 flex-col gap-2 rounded-md border bg-surface p-4 text-start shadow-e1 max-sm:gap-1.5 max-sm:p-3 ${
        active ? 'border-line-brand ring-1 ring-inset ring-line-brand' : 'border-line-default'
    } ${className}`;
    const body = (
        <>
            <span className="flex items-center justify-between gap-2">
                <span className={`min-w-0 truncate t-admin-overline text-fg-secondary ${compact ? 'max-sm:min-h-8 max-sm:whitespace-normal' : ''}`}>{label}</span>
                <span aria-hidden className={`grid size-8 shrink-0 place-items-center rounded-sm ${compact ? 'max-sm:hidden' : ''} ${kpiTiles[tone]}`}>
                    <Icon size={16} weight="fill" />
                </span>
            </span>
            <span className="tabular text-fg-primary">{value}</span>
            <span className="flex min-w-0 items-center gap-2 t-admin-caption text-fg-secondary">
                <span className={`min-w-0 flex-1 ${compact ? 'max-sm:sr-only' : ''}`}>{hint}</span>
                {onClick && <CaretRightIcon size={16} aria-hidden className="ms-auto shrink-0 text-fg-tertiary transition-colors duration-150 ease-standard group-hover:text-fg-primary" />}
            </span>
        </>
    );
    if (!onClick) return <div className={frame}>{body}</div>;
    return (
        <button type="button" aria-pressed={active} onClick={onClick} className={`group ${frame} cursor-pointer transition-shadow duration-150 ease-standard hover:shadow-e2`}>
            {body}
        </button>
    );
}
