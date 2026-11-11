import { useId, type ReactNode } from 'react';
import { useI18n } from '@/i18n/I18nProvider';

type FieldProps = {
    label: string;
    hint?: string;
    error?: string;
    optional?: boolean;
    /** Receives the ids the control must use so label, hint and error are announced. */
    children: (ids: { id: string; describedBy: string | undefined; invalid: boolean }) => ReactNode;
};

/** Figma AdminField 185:151 — label, control, hint, error. Errors are text, never colour alone. */
export function Field({ label, hint, error, optional, children }: FieldProps) {
    const { t } = useI18n();
    const id = useId();
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;
    const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined;

    return (
        <div className="space-y-1.5">
            <label htmlFor={id} className="flex items-baseline gap-2 t-admin-body-strong text-fg-primary">
                {label}
                {optional && <span className="t-admin-caption text-fg-tertiary">{t('common.optional')}</span>}
            </label>
            {children({ id, describedBy, invalid: Boolean(error) })}
            {hint && !error && (
                <p id={hintId} className="t-admin-caption text-fg-tertiary">
                    {hint}
                </p>
            )}
            {error && (
                <p id={errorId} className="t-admin-caption-strong text-feedback-danger">
                    {error}
                </p>
            )}
        </div>
    );
}

export const inputClass =
    'w-full rounded-sm border border-line-strong bg-surface px-3 t-admin-body text-fg-primary placeholder:text-fg-tertiary aria-invalid:border-line-danger focus-visible:border-line-focus';
