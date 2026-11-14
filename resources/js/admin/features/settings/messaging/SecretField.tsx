import type { UseFormRegisterReturn } from 'react-hook-form';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { Field, monoInputClass } from '@/components/ui/Field';

type SecretFieldProps = {
    label: string;
    name: string;
    isSet: boolean;
    hint: string | null;
    source: 'settings' | 'env' | null;
    clearing: boolean;
    onClear: (value: boolean) => void;
    error?: string;
    register: UseFormRegisterReturn;
    /** Visible text of the remove button (default "Remove saved key"), when several secrets share a card. */
    removeLabel?: string;
};

/**
 * A write-only credential: a password field that starts blank; blank keeps the saved value.
 * The server only ever says whether it is set, where from, and its last 4 characters.
 */
export function SecretField({ label, name, isSet, hint, source, clearing, onClear, error, register, removeLabel }: SecretFieldProps) {
    const { t } = useI18n();
    const status = !isSet
        ? t('messaging.secret.none')
        : source === 'settings'
          ? t('messaging.secret.saved', { hint: hint ?? '••••' })
          : t('messaging.secret.env', { hint: hint ?? '••••' });

    return (
        <div className="flex flex-col gap-1.5">
            <Field label={label} hint={clearing ? t('messaging.secret.willRemove') : status} error={error}>
                {({ id, describedBy, invalid }) => (
                    <input
                        id={id}
                        type="password"
                        autoComplete="new-password"
                        spellCheck={false}
                        data-secret={name}
                        placeholder={isSet && !clearing ? (hint ?? '••••') : undefined}
                        disabled={clearing}
                        aria-describedby={describedBy}
                        aria-invalid={invalid || undefined}
                        className={`h-10 ${monoInputClass}`}
                        {...register}
                    />
                )}
            </Field>
            {source === 'settings' && (
                <div>
                    <Button variant="ghost" size="sm" onClick={() => onClear(!clearing)}>
                        {clearing ? t('messaging.secret.undo') : (removeLabel ?? t('messaging.secret.remove'))}
                    </Button>
                </div>
            )}
        </div>
    );
}
