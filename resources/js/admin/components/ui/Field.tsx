import { useId, type ReactNode } from 'react';
import { WarningCircleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';

type FieldProps = {
    label: string;
    hint?: string;
    error?: string;
    optional?: boolean;
    /** Shows " *" after the label. Say "required" in the hint too — never the asterisk alone. */
    required?: boolean;
    /** Receives the ids the control must use so label, hint and error are announced. */
    children: (ids: { id: string; describedBy: string | undefined; invalid: boolean }) => ReactNode;
};

/**
 * Figma AdminField 185:151 — label row, control, help. The error line is a
 * feedback-coloured icon plus text/primary text: never colour alone, and
 * color/feedback/* never carries 12px text.
 */
export function Field({ label, hint, error, optional, required, children }: FieldProps) {
    const { t } = useI18n();
    const id = useId();
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;
    const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined;

    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={id} className="flex items-baseline gap-0.5 t-admin-body-strong text-fg-primary">
                {label}
                {required && (
                    <span aria-hidden className="text-feedback-danger">
                        {' *'}
                    </span>
                )}
                {optional && <span className="ms-1.5 t-admin-caption text-fg-tertiary">{t('common.optional')}</span>}
            </label>
            {children({ id, describedBy, invalid: Boolean(error) })}
            {hint && !error && (
                <p id={hintId} className="t-admin-caption text-fg-secondary">
                    {hint}
                </p>
            )}
            {error && (
                <p id={errorId} className="flex items-start gap-1 t-admin-caption text-fg-primary">
                    <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                    {error}
                </p>
            )}
        </div>
    );
}

/** AdminInput heights: sm 32, md 40 (Figma 184:338). Add one to `inputClass` on single-line controls. */
export const controlHeight = { sm: 'h-8', md: 'h-10' } as const;

/**
 * Figma AdminInput 184:338 box, without a height (textareas grow). Default border/default;
 * focus is a 2px border/focus (1px border + 1px inset ring, so text does not shift);
 * error border/danger; disabled bg/disabled.
 */
export const inputClass =
    'w-full rounded-sm border border-line-default bg-surface px-3 t-admin-body text-fg-primary placeholder:text-fg-tertiary focus:border-line-focus focus:ring-1 focus:ring-inset focus:ring-line-focus aria-invalid:border-line-danger disabled:cursor-not-allowed disabled:bg-disabled disabled:text-fg-disabled';

/** `inputClass` set in JetBrains Mono (slugs, SKUs, hex). Swaps the type class rather than
 *  stacking one: under :lang(bn) the Bangla twin of t-admin-body would beat t-admin-mono. */
export const monoInputClass = inputClass.replace('t-admin-body', 't-admin-mono');
