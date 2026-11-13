import { Icon } from '@/components/icons/Icon';
import { format, type Messages } from '@/lib/i18n';

// Flash-sale badge (P7): "⚡ Flash sale −15%" on the card's photo and the PDP gallery. Accent
// fill like the countdown (never white text on accent, §A15). The percentage is the API's
// `discount_percent` — nothing is computed here (rule 1).

interface Props {
    discountPercent: number;
    t: Messages['flashSale'];
    discountTemplate: string;
    className?: string;
}

export function FlashBadge({ discountPercent, t, discountTemplate, className = '' }: Props) {
    return (
        <span className={`t-badge inline-flex h-6 items-center gap-1 rounded-full bg-accent px-2 text-fg-on-accent ${className}`}>
            <Icon name="lightningFill" className="size-3.5" />
            {t.badge}
            {discountPercent > 0 ? <span className="tabular-nums">{format(discountTemplate, { percent: String(discountPercent) })}</span> : null}
        </span>
    );
}
