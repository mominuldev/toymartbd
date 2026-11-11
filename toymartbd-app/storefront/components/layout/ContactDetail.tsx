import type { ContactValue } from '@/lib/site-config';

interface Props {
    value: ContactValue;
    className?: string;
}

// Renders one value from lib/site-config.ts `contact`. A confirmed value is a link (tel:, mailto:,
// wa.me). A placeholder is plain text marked `data-placeholder="owner-to-confirm"`, so no visitor
// can dial or message a made-up number and a test can find every placeholder. (The visible
// "sample" tag was removed for Figma fidelity, 2026-11-11: the designs show these values bare.)
export function ContactDetail({ value, className = '' }: Props) {
    if (!value.placeholder) {
        return (
            <a href={value.href} className={`whitespace-nowrap underline-offset-4 hover:underline ${className}`}>
                {value.display}
            </a>
        );
    }
    return (
        <span data-placeholder="owner-to-confirm" className={`whitespace-nowrap ${className}`}>
            {value.display}
        </span>
    );
}
