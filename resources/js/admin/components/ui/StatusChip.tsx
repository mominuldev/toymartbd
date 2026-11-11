import type { ComponentType } from 'react';
import { CheckCircleIcon, CircleDashedIcon, InfoIcon, SparkleIcon, WarningIcon, XCircleIcon, type IconProps } from '@phosphor-icons/react';

export type StatusTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'brand' | 'anomaly';

const icons: Record<StatusTone, ComponentType<IconProps>> = {
    neutral: CircleDashedIcon,
    info: InfoIcon,
    success: CheckCircleIcon,
    warning: WarningIcon,
    danger: XCircleIcon,
    brand: SparkleIcon,
    anomaly: WarningIcon,
};

/* Feedback colours tint the 14px icon only — they fail contrast as 12px text. */
const iconTone: Record<StatusTone, string> = {
    neutral: 'text-fg-secondary',
    info: 'text-feedback-info',
    success: 'text-feedback-success',
    warning: 'text-feedback-warning',
    danger: 'text-feedback-danger',
    brand: 'text-fg-brand',
    anomaly: 'text-feedback-warning',
};

type StatusChipProps = {
    label: string;
    tone?: StatusTone;
    /** Shorthand kept for existing callers: true → success, false → neutral. */
    active?: boolean;
    showIcon?: boolean;
};

/**
 * Figma StatusChip 185:79 — label + icon shape + tint, never colour alone. Fill is always
 * bg/subtle with a border/default hairline and a text/primary label; anomaly swaps the
 * hairline for a 2px warning left edge.
 */
export function StatusChip({ label, tone, active, showIcon = true }: StatusChipProps) {
    const resolved: StatusTone = tone ?? (active === undefined ? 'neutral' : active ? 'success' : 'neutral');
    const Icon = icons[resolved];
    const edge = resolved === 'anomaly' ? 'border-l-2 border-feedback-warning' : 'border border-line-default';

    return (
        <span className={`inline-flex h-5.5 items-center gap-1 rounded-xs bg-subtle px-2 t-admin-caption-strong whitespace-nowrap text-fg-primary ${edge}`}>
            {showIcon && <Icon size={14} aria-hidden className={`shrink-0 ${iconTone[resolved]}`} />}
            {label}
        </span>
    );
}
