export type CountBadgeTone = 'neutral' | 'brand' | 'danger' | 'onBrand';

const tones: Record<CountBadgeTone, string> = {
    neutral: 'bg-subtle text-fg-primary',
    brand: 'bg-brand text-fg-on-brand',
    danger: 'bg-danger text-fg-on-danger',
    onBrand: 'bg-surface text-fg-primary',
};

/** Figma CountBadge 185:43 — always the number, never a colour-only dot. Western digits (rule 36). */
export function CountBadge({ count, tone = 'neutral', className = '' }: { count: number | string; tone?: CountBadgeTone; className?: string }) {
    return (
        <span className={`inline-flex h-4.5 min-w-4.5 shrink-0 items-center justify-center rounded-xs px-1.25 t-admin-caption-strong tabular ${tones[tone]} ${className}`}>
            {count}
        </span>
    );
}
