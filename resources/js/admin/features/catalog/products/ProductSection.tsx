import { useState, type ReactNode } from 'react';
import { CaretDownIcon } from '@phosphor-icons/react';

type ProductSectionProps = {
    id?: string;
    title: string;
    description?: string;
    /** Right side of the header (≥ 1024 only shows it beside the title; below, it sits under the header). */
    aside?: ReactNode;
    /** Below 1024 the card is an accordion (Figma 768 · Product edit 230:22079); General opens by default. */
    defaultOpen?: boolean;
    /** Keeps the accordion open, e.g. while the section holds an error. */
    forceOpen?: boolean;
    children: ReactNode;
    className?: string;
};

/**
 * The FormSection 198:901 card used on the product screen (radius/md, border/default, e1,
 * 20 padding, Admin/section title + caption, 14 between fields). At ≥ 1024 it is always open;
 * below that its header is a disclosure button, as in the 768 Figma frame.
 */
export function ProductSection({ id, title, description, aside, defaultOpen = false, forceOpen = false, children, className = '' }: ProductSectionProps) {
    const [open, setOpen] = useState(defaultOpen);
    const expanded = open || forceOpen;
    const bodyId = id ? `${id}-body` : undefined;

    return (
        <section id={id} aria-label={title} className={`flex scroll-mt-6 flex-col gap-3.5 rounded-md border border-line-default bg-surface p-5 shadow-e1 ${className}`}>
            <div className="flex items-center gap-2">
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <h2 className="t-admin-section text-fg-primary">
                        {/* ≥ 1024: a plain heading. Below: the accordion trigger. */}
                        <span className="max-lg:hidden">{title}</span>
                        <button
                            type="button"
                            aria-expanded={expanded}
                            aria-controls={bodyId}
                            onClick={() => setOpen((current) => !current)}
                            className="flex w-full items-center gap-2 text-start lg:hidden"
                        >
                            <span className="min-w-0 flex-1">{title}</span>
                            <CaretDownIcon size={20} aria-hidden className={`shrink-0 text-fg-primary transition-transform duration-150 ease-standard ${expanded ? 'rotate-180' : ''}`} />
                        </button>
                    </h2>
                    {description && <p className={`t-admin-caption text-fg-tertiary ${expanded ? '' : 'max-lg:hidden'}`}>{description}</p>}
                </div>
                {aside && <div className={`shrink-0 ${expanded ? '' : 'max-lg:hidden'}`}>{aside}</div>}
            </div>
            <div id={bodyId} className={`flex flex-col gap-3.5 ${expanded ? '' : 'max-lg:hidden'}`}>
                {children}
            </div>
        </section>
    );
}
