import type { ComponentType, ReactNode } from 'react';
import { CheckCircleIcon, LockSimpleIcon, PackageIcon, PlugsConnectedIcon, type IconProps } from '@phosphor-icons/react';

export type EmptyReason = 'allClear' | 'noDataYet' | 'noPermission' | 'notConnected';

const looks: Record<EmptyReason, { icon: ComponentType<IconProps>; tile: string; tint: string }> = {
    allClear: { icon: CheckCircleIcon, tile: 'bg-success-subtle', tint: 'text-feedback-success' },
    noDataYet: { icon: PackageIcon, tile: 'bg-brand-subtle', tint: 'text-fg-brand' },
    noPermission: { icon: LockSimpleIcon, tile: 'bg-subtle', tint: 'text-fg-secondary' },
    notConnected: { icon: PlugsConnectedIcon, tile: 'bg-subtle', tint: 'text-fg-secondary' },
};

type EmptyStateProps = {
    reason?: EmptyReason;
    title: string;
    body?: string;
    /** Next action(s): AdminButtons (md). */
    actions?: ReactNode;
    className?: string;
};

/** Figma AdminEmptyState 203:1531 — name the reason, then the next action. */
export function EmptyState({ reason = 'noDataYet', title, body, actions, className = '' }: EmptyStateProps) {
    const { icon: Icon, tile, tint } = looks[reason];

    return (
        <div className={`flex flex-col items-center gap-2.5 rounded-md border border-line-default bg-surface px-6 py-8 text-center ${className}`}>
            <span aria-hidden className={`grid size-14 place-items-center rounded-full ${tile}`}>
                <Icon size={28} className={tint} />
            </span>
            <p className="t-admin-section text-fg-primary">{title}</p>
            {body && <p className="max-w-sm t-admin-body text-fg-secondary">{body}</p>}
            {actions && <div className="flex flex-wrap justify-center gap-2">{actions}</div>}
        </div>
    );
}
