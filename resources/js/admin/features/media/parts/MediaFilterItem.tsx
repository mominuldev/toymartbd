import type { ComponentType } from 'react';
import { WarningIcon, type IconProps } from '@phosphor-icons/react';
import { CountBadge } from '@/components/ui/CountBadge';

type MediaFilterItemProps = {
    label: string;
    icon?: ComponentType<IconProps>;
    count?: number | string;
    active?: boolean;
    /** The alt-text gaps: a warning glyph and a Danger count (Figma State=Warning). */
    warning?: boolean;
    onClick: () => void;
};

/**
 * Figma MediaFilterItem 289:1910 — a 36-high rail row, 8×12 padding, 8 gap, radius/sm: a
 * 16px glyph, Admin/body label and a CountBadge. Active = bg/brand-subtle with a brand
 * icon and a brand count (as in the sidebar); Warning = a warning-tinted icon and a Danger
 * count. A button with aria-pressed, so the rail reads as a set of filters.
 */
export function MediaFilterItem({ label, icon: Icon, count, active = false, warning = false, onClick }: MediaFilterItemProps) {
    const Glyph = warning ? WarningIcon : Icon;
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={`flex h-9 w-full items-center gap-2 rounded-sm px-3 text-start transition-colors duration-150 ease-standard ${
                active ? 'bg-brand-subtle text-fg-primary' : 'text-fg-primary hover:bg-subtle'
            } ${active || warning ? 't-admin-body-strong' : 't-admin-body'}`}
        >
            {Glyph && <Glyph size={16} aria-hidden className={`shrink-0 ${warning ? 'text-feedback-warning' : active ? 'text-fg-brand' : 'text-fg-secondary'}`} />}
            <span className="min-w-0 flex-1 truncate">{label}</span>
            {count !== undefined && <CountBadge count={count} tone={warning && count !== 0 && count !== '0' ? 'danger' : active ? 'brand' : 'neutral'} />}
        </button>
    );
}
