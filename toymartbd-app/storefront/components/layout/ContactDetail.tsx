import type { ContactValue } from '@/lib/site-config';

interface Props {
    value: ContactValue;
    /** The localised word for "sample". */
    sampleLabel: string;
    className?: string;
}

// Renders one value from lib/site-config.ts `contact`. A confirmed value is a link (tel:, mailto:,
// wa.me). A placeholder is plain text plus a visible "sample" tag and `data-placeholder`, so no
// visitor can dial or message a made-up number, and a test can find every placeholder.
export function ContactDetail({ value, sampleLabel, className = '' }: Props) {
    if (!value.placeholder) {
        return (
            <a href={value.href} className={`underline-offset-4 hover:underline ${className}`}>
                {value.display}
            </a>
        );
    }
    return (
        <span data-placeholder="owner-to-confirm" className={`inline-flex flex-wrap items-center gap-2 ${className}`}>
            <span>{value.display}</span>
            <span className="t-caption rounded-full bg-warning-subtle px-2 text-fg-primary">{sampleLabel}</span>
        </span>
    );
}
