import { useId, type ReactNode } from 'react';
import { LockSimpleIcon, WarningCircleIcon, type Icon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { inputClass } from '@/components/ui/Field';

/** IconTile tints used by the Site & branding cards (Figma 391:69310, 392:69348, 393:69410, 395:69416). */
export type TileTone = 'brand' | 'info' | 'grape' | 'teal';

const tileTone: Record<TileTone, string> = {
    brand: 'bg-brand-subtle text-fg-brand',
    info: 'bg-info-subtle text-feedback-info',
    grape: 'bg-grape-subtle text-grape',
    teal: 'bg-teal-subtle text-teal',
};

type BrandingCardProps = {
    icon: Icon;
    tone: TileTone;
    title: string;
    description: string;
    /** Right of the header (the Read-only pill). */
    aside?: ReactNode;
    /** The preview rail's header centres the tile against two short lines. */
    centered?: boolean;
    className?: string;
    children: ReactNode;
};

/**
 * The Premium settings card (Figma 391:69308): FormSection's surface (radius/md, border/default,
 * e1, 20 padding) with a 36 IconTile (radius/xs-plus) beside an Admin/section title and an
 * Admin/caption line, 12 apart; blocks 18 apart.
 */
export function BrandingCard({ icon: IconGlyph, tone, title, description, aside, centered = false, className = '', children }: BrandingCardProps) {
    const headingId = useId();
    return (
        <section aria-labelledby={headingId} className={`flex min-w-0 flex-col gap-4.5 rounded-md border border-line-default bg-surface p-4 shadow-e1 md:p-5 ${className}`}>
            <div className={`flex gap-3 ${centered ? 'items-center' : 'items-start'}`}>
                <span className={`grid size-9 shrink-0 place-items-center rounded-xs-plus ${tileTone[tone]}`}>
                    <IconGlyph size={18} aria-hidden />
                </span>
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <h3 id={headingId} className="t-admin-section text-fg-primary">
                        {title}
                    </h3>
                    <p className="t-admin-caption text-fg-secondary">{description}</p>
                </div>
                {aside}
            </div>
            {children}
        </section>
    );
}

/** Admin/caption on bg/subtle with a 16 icon (Figma Note 392:69531). */
export function CardNote({ icon: IconGlyph, children }: { icon: Icon; children: ReactNode }) {
    return (
        <p className="flex items-start gap-2 rounded-xs bg-subtle px-3 py-2.5 t-admin-caption text-fg-secondary">
            <IconGlyph size={16} aria-hidden className="shrink-0 text-fg-secondary" />
            <span>{children}</span>
        </p>
    );
}

/** Code points, as the server's mb_strlen counts them (a Bangla vowel sign is one). */
export function charCount(value: string): number {
    return [...value].length;
}

type CountedFieldProps = {
    label: string;
    hint: string;
    value: string;
    max: number;
    onChange: (value: string) => void;
    multiline?: boolean;
    bn?: boolean;
    placeholder?: string;
    disabled?: boolean;
    error?: string;
};

/**
 * Figma AdminField / AdminTextarea with the Premium meta row (391:69336): the help text on the
 * left and an Admin/mono-sm "11 / 60" counter on the right, 6 under the control.
 */
export function CountedField({ label, hint, value, max, onChange, multiline = false, bn = false, placeholder, disabled, error }: CountedFieldProps) {
    const id = useId();
    const metaId = `${id}-meta`;
    const errorId = `${id}-error`;
    const count = charCount(value);
    const control = {
        id,
        lang: bn ? 'bn' : undefined,
        value,
        maxLength: max,
        placeholder,
        disabled,
        'aria-describedby': error ? `${errorId} ${metaId}` : metaId,
        'aria-invalid': Boolean(error) || undefined,
    };

    return (
        <div className="flex min-w-0 flex-col gap-1.5">
            <label htmlFor={id} className="t-admin-body-strong text-fg-primary">
                {label}
            </label>
            {multiline ? (
                <textarea {...control} rows={3} onChange={(e) => onChange(e.target.value)} className={`field-sizing-content min-h-22 resize-y rounded-md py-2.5 ${inputClass}`} />
            ) : (
                <input {...control} type="text" onChange={(e) => onChange(e.target.value)} className={`h-10 ${inputClass}`} />
            )}
            {error && (
                <p id={errorId} className="flex items-start gap-1 t-admin-caption text-fg-primary">
                    <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                    {error}
                </p>
            )}
            <p id={metaId} className="flex items-start gap-2 t-admin-caption">
                <span className="min-w-0 flex-1 text-fg-secondary">{hint}</span>
                <span className="shrink-0 t-admin-mono-sm text-fg-tertiary">
                    {count} / {max}
                </span>
            </p>
        </div>
    );
}

/** Storefront palette shown read-only; `data-theme="light"` resolves the storefront's own values. */
const swatches = [
    { label: 'storeBranding.colours.brand', token: 'bg/brand', chip: 'bg-brand' },
    { label: 'storeBranding.colours.accent', token: 'bg/accent', chip: 'bg-accent' },
    { label: 'storeBranding.colours.teal', token: 'bg/teal', chip: 'bg-teal' },
    { label: 'storeBranding.colours.pink', token: 'bg/pink', chip: 'bg-pink' },
    { label: 'storeBranding.colours.canvas', token: 'bg/canvas', chip: 'bg-canvas' },
] as const satisfies readonly { label: MessageKey; token: string; chip: string }[];

/**
 * Brand colours (Figma 393:69408, mobile 396:70019): five swatches — 44-high chip
 * (radius/xs, border/default), name (Admin/caption-strong), token (Admin/mono-sm; hidden
 * below md as in the 390 frame). Not editable, on purpose.
 */
export function BrandColours({ icon, className = '' }: { icon: Icon; className?: string }) {
    const { t } = useI18n();
    return (
        <BrandingCard
            icon={icon}
            tone="grape"
            title={t('storeBranding.colours.title')}
            description={t('storeBranding.colours.description')}
            className={className}
            aside={
                <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-subtle px-2 py-0.75 t-admin-caption-strong text-fg-secondary">
                    <LockSimpleIcon size={12} aria-hidden />
                    {t('storeBranding.colours.readOnly')}
                </span>
            }
        >
            <ul className="grid grid-cols-5 gap-2 md:gap-3">
                {swatches.map((s) => (
                    <li key={s.token} className="flex min-w-0 flex-col gap-1.5">
                        <span data-theme="light" aria-hidden className={`h-11 rounded-xs border border-line-default ${s.chip}`} />
                        <span className="truncate t-admin-caption-strong text-fg-primary">{t(s.label)}</span>
                        <span className="hidden truncate t-admin-mono-sm text-fg-tertiary md:block">{s.token}</span>
                    </li>
                ))}
            </ul>
            <CardNote icon={LockSimpleIcon}>{t('storeBranding.colours.note')}</CardNote>
        </BrandingCard>
    );
}
