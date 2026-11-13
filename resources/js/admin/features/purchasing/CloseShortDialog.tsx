import { useId, useState } from 'react';
import { ProhibitIcon } from '@phosphor-icons/react';
import { closePurchaseRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { Field, inputClass } from '@/components/ui/Field';
import { useToast } from '@/components/ui/Toast';
import { useClosePurchase, type Purchase } from './api';
import { formatUnits, useDialog } from './parts';

/**
 * Close a partly received PO short (owner decision 2026-11-13). The ConfirmDialog 203:1387
 * frame with a required reason (AdminField 185:151): the units never received come from the
 * server's lines, and the server decides — a PO that is no longer partially received is a 409.
 */
export function CloseShortDialog({ open, onClose, purchase }: { open: boolean; onClose: () => void; purchase: Purchase }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const titleId = useId();
    const close = useClosePurchase(purchase.id);
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const ref = useDialog(open, () => {
        setReason('');
        setError(undefined);
        close.reset();
    });
    const short = purchase.items.reduce((sum, line) => sum + line.quantity_outstanding, 0);

    function submit() {
        const parsed = closePurchaseRequestSchema.safeParse({ reason: reason.trim() });
        if (!parsed.success) {
            setError(parsed.error.issues[0]?.message ?? t('validation.required'));
            return;
        }
        close.mutate(reason.trim(), {
            onSuccess: (po) => {
                notify(t('po.closed', { number: po.number }));
                onClose();
            },
            onError: (e) => {
                if (e instanceof ApiError && e.status === 422) setError(e.fieldError('reason') ?? e.problem.title);
                else notify(e instanceof ApiError ? (e.problem.detail ?? e.problem.title) : t('error.title'), 'danger');
            },
        });
    }

    return (
        <dialog ref={ref} aria-labelledby={titleId} onClose={onClose} onCancel={onClose} className="m-auto w-[min(100%-2rem,32.5rem)] rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay">
            <form
                noValidate
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <div className="flex flex-col items-start gap-2.5 px-6 pt-6 pb-4">
                    <span aria-hidden className="grid size-11 place-items-center rounded-sm bg-subtle text-feedback-warning">
                        <ProhibitIcon size={22} />
                    </span>
                    <h2 id={titleId} className="t-admin-section text-fg-primary">
                        {t('po.closeTitle', { number: purchase.number })}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">{t('po.closeBody', { units: formatUnits(short) })}</p>
                </div>
                <fieldset disabled={close.isPending} className="flex min-w-0 flex-col gap-3.5 px-6 pb-5">
                    <Field label={t('po.closeReason')} hint={t('po.closeReasonHint')} error={error} required>
                        {({ id, describedBy, invalid }) => (
                            <textarea id={id} rows={3} maxLength={500} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} value={reason} onChange={(e) => setReason(e.target.value)} />
                        )}
                    </Field>
                </fieldset>
                <div className="flex justify-end gap-3 border-t border-line-default px-6 py-4">
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" variant="danger" disabled={close.isPending}>
                        {t('po.closeConfirm')}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}
