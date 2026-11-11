import { CheckCircleIcon, ArchiveIcon } from '@phosphor-icons/react';

/** Figma StatusChip 185:79 — text + icon shape + colour, never colour alone. */
export function StatusChip({ active, label }: { active: boolean; label: string }) {
    return (
        <span
            className={`inline-flex h-6 items-center gap-1 rounded-xs px-2 t-admin-caption-strong ${
                active ? 'bg-success-subtle text-feedback-success' : 'bg-subtle text-fg-secondary'
            }`}
        >
            {active ? <CheckCircleIcon size={14} weight="fill" aria-hidden /> : <ArchiveIcon size={14} aria-hidden />}
            {label}
        </span>
    );
}
