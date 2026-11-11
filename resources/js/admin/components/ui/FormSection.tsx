import type { ReactNode } from 'react';

type FormSectionProps = {
    title?: string;
    description?: string;
    /** Right side of the header (a link, a toggle). */
    aside?: ReactNode;
    children: ReactNode;
    className?: string;
};

/**
 * Figma FormSection 198:901 — a surface card (radius/md, border/default, e1, 20 padding)
 * with an Admin/section title and caption, fields 14 apart.
 */
export function FormSection({ title, description, aside, children, className = '' }: FormSectionProps) {
    return (
        <section className={`flex flex-col gap-3.5 rounded-md border border-line-default bg-surface p-5 shadow-e1 ${className}`}>
            {(title || aside) && (
                <div className="flex items-start gap-3">
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                        {title && <h2 className="t-admin-section text-fg-primary">{title}</h2>}
                        {description && <p className="t-admin-caption text-fg-tertiary">{description}</p>}
                    </div>
                    {aside}
                </div>
            )}
            {children}
        </section>
    );
}
