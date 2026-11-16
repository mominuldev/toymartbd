import { useState } from 'react';
import { ArrowCounterClockwiseIcon, ArrowDownIcon } from '@phosphor-icons/react';
import { reverseCapitalEntryRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { dhakaDate } from '../parts';
import { useReverseCapitalEntry, type CapitalEntry } from './api';
import { CapitalDialog, KindChips, Notice, capitalDate, methodKey, taka } from './parts';

/**
 * "Reverse this entry?" (Figma 429:75494): the original, what will be written, a required
 * reason, then a danger button. The new entry's sign is the original's kind read the other way
 * round (a drawing reversed is money back in); its amount is the server's — never computed.
 * One Idempotency-Key per opening; a 409 (already reversed) shows the server's own words.
 */
export function ReverseEntryDialog({ entry, onClose }: { entry: CapitalEntry | null; onClose: () => void }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const reverse = useReverseCapitalEntry();
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());
    // Keep the last entry on screen while the dialog closes.
    const [shown, setShown] = useState<CapitalEntry | null>(entry);
    if (entry !== null && entry !== shown) setShown(entry);

    const reset = () => {
        setReason('');
        setError(undefined);
        setIdempotencyKey(crypto.randomUUID());
        reverse.reset();
    };

    const e = shown;
    const wasIn = e?.kind === 'contribution';
    const name = e?.holder?.name ?? t('capital.someone');

    function submit() {
        if (!e) return;
        const parsed = reverseCapitalEntryRequestSchema.safeParse({ reason: reason.trim() });
        if (!parsed.success) {
            setError(reason.trim() === '' ? t('capital.field.required', { label: t('capital.reverse.reason') }) : parsed.error.issues[0]?.message);
            return;
        }
        setError(undefined);
        reverse.mutate(
            { id: e.id, input: parsed.data, idempotencyKey },
            {
                onSuccess: () => {
                    notify(t('capital.reverse.done', { id: e.id }));
                    onClose();
                },
                onError: (failure) => {
                    setIdempotencyKey(crypto.randomUUID());
                    if (failure instanceof ApiError && failure.status === 422) setError(failure.fieldError('reason'));
                    else notify(failure instanceof ApiError ? (failure.problem.detail ?? failure.problem.title) : t('error.title'), 'danger');
                },
            },
        );
    }

    return (
        <CapitalDialog
            open={entry !== null}
            onOpen={reset}
            onClose={onClose}
            width="narrow"
            title={t('capital.reverse.title')}
            subtitle={t('capital.reverse.subtitle')}
            pending={reverse.isPending}
            danger
            submitIcon={<ArrowCounterClockwiseIcon size={16} aria-hidden />}
            submitLabel={t('capital.reverse.submit')}
            onSubmit={submit}
        >
            {e && (
                <>
                    <section aria-label={t('capital.reverse.original', { id: e.id })} className="flex flex-col gap-2 rounded-sm border border-line-default bg-subtle px-4 py-3.5">
                        <p className="t-admin-overline text-fg-secondary">{t('capital.reverse.original', { id: e.id })}</p>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <KindChips entry={e} />
                            <p className="t-admin-num-md text-fg-primary tabular">{taka(e.net_effect, true)}</p>
                        </div>
                        <p className="t-admin-caption text-fg-secondary">
                            {t('capital.reverse.originalMeta', { name, method: t(methodKey(e.method)), date: capitalDate(e.occurred_on, locale), by: e.recorded_by?.name ?? '—' })}
                            {e.note ? ` · “${e.note}”` : ''}
                        </p>
                    </section>

                    <p className="flex items-center justify-center gap-2 t-admin-caption text-fg-tertiary">
                        <ArrowDownIcon size={16} aria-hidden />
                        {t('capital.reverse.arrow')}
                    </p>

                    <section aria-label={t('capital.reverse.new')} className="flex flex-col gap-2 rounded-sm border border-line-default bg-surface px-4 py-3.5">
                        <p className="t-admin-overline text-fg-secondary">{t('capital.reverse.new')}</p>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <StatusChip tone="neutral" label={t(wasIn ? 'capital.reverse.chipOut' : 'capital.reverse.chipIn')} />
                            <p className={`t-admin-num-md tabular ${wasIn ? 'text-fg-primary' : 'text-feedback-success'}`}>{wasIn ? `−${taka(e.amount)}` : `+${taka(e.amount)}`}</p>
                        </div>
                        <p className="t-admin-caption text-fg-secondary">
                            {t('capital.reverse.newMeta', { name, method: t(methodKey(e.method)), date: capitalDate(dhakaDate(), locale), id: e.id })}
                        </p>
                    </section>

                    <Field label={t('capital.reverse.reason')} hint={t('capital.reverse.reasonHint')} error={error} required>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} maxLength={500} autoComplete="off" value={reason} onChange={(ev) => setReason(ev.target.value)} />
                        )}
                    </Field>

                    <Notice>{t(wasIn ? 'capital.reverse.noticeIn' : 'capital.reverse.noticeOut', { name, amount: taka(e.amount) })}</Notice>
                </>
            )}
        </CapitalDialog>
    );
}
