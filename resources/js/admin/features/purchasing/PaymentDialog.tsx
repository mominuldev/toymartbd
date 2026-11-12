import { useId, useState } from 'react';
import { WalletIcon } from '@phosphor-icons/react';
import { storeSupplierPaymentRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { Field, inputClass } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { paymentMethods, useRecordPayment, type PaymentInput, type PaymentMethod } from './api';
import { TAKA, dhakaToday, formatMoney, useDialog } from './parts';

type FieldName = 'amount' | 'method' | 'reference' | 'paid_at' | 'purchase_id' | 'note';

type PaymentDialogProps = {
    open: boolean;
    onClose: () => void;
    supplier: { id: number; name: string };
    /** Preselected PO (the receive card's button); null records a payment on account. */
    purchaseId: number | null;
    /** POs the payment may be set against. */
    purchases: { id: number; number: string }[];
};

/**
 * Record a supplier payment (06-admin-feature-checklist §2.13 "Supplier payments"; no
 * screen drawn — built from ConfirmDialog 203:1387's frame, AdminField 185:151, AdminInput
 * 184:338, AdminSelect 184:369 and AdminButton 184:242, like the stock adjustment form).
 * Client validation is the generated schema (rule 27); one Idempotency-Key per opening, so
 * a retry never records the payment twice.
 */
export function PaymentDialog({ open, onClose, supplier, purchaseId, purchases }: PaymentDialogProps) {
    const { t } = useI18n();
    const { notify } = useToast();
    const titleId = useId();
    const save = useRecordPayment(supplier.id);
    const [amount, setAmount] = useState('');
    const [method, setMethod] = useState<PaymentMethod>('bank_transfer');
    const [reference, setReference] = useState('');
    const [paidAt, setPaidAt] = useState(dhakaToday);
    const [purchase, setPurchase] = useState(purchaseId === null ? '' : String(purchaseId));
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Partial<Record<FieldName, string>>>({});
    const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());

    const ref = useDialog(open, () => {
        setAmount('');
        setMethod('bank_transfer');
        setReference('');
        setPaidAt(dhakaToday());
        setPurchase(purchaseId === null ? '' : String(purchaseId));
        setNote('');
        setErrors({});
        setIdempotencyKey(crypto.randomUUID());
        save.reset();
    });

    function submit() {
        const input: PaymentInput = {
            amount: amount.trim().replace(/,/g, ''),
            method,
            reference: reference.trim() === '' ? null : reference.trim(),
            paid_at: paidAt,
            purchase_id: purchase === '' ? null : Number(purchase),
            note: note.trim() === '' ? null : note.trim(),
        };
        const parsed = storeSupplierPaymentRequestSchema.safeParse(input);
        if (!parsed.success) {
            const next: Partial<Record<FieldName, string>> = {};
            for (const issue of parsed.error.issues) {
                const field = issue.path[0] as FieldName;
                next[field] ??= issue.message;
            }
            setErrors(next);
            return;
        }
        setErrors({});
        save.mutate(
            { input, idempotencyKey },
            {
                onSuccess: (payment) => {
                    notify(t('payment.saved', { amount: `${TAKA}${formatMoney(payment.amount)}` }));
                    onClose();
                },
                onError: (error) => {
                    if (error instanceof ApiError && error.status === 422) {
                        const next: Partial<Record<FieldName, string>> = {};
                        for (const { field, message } of error.problem.errors ?? []) next[field as FieldName] ??= message;
                        setErrors(next);
                        setIdempotencyKey(crypto.randomUUID());
                    } else {
                        notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
                    }
                },
            },
        );
    }

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            onCancel={onClose}
            className="m-auto w-[min(100%-2rem,32.5rem)] rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay"
        >
            <form
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                <div className="flex flex-col items-start gap-2.5 px-6 pt-6 pb-4">
                    <span aria-hidden className="grid size-11 place-items-center rounded-sm bg-brand-subtle text-fg-brand">
                        <WalletIcon size={22} />
                    </span>
                    <h2 id={titleId} className="t-admin-section text-fg-primary">
                        {t('payment.title')}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">{t('payment.body', { supplier: supplier.name })}</p>
                </div>

                <fieldset disabled={save.isPending} className="flex min-w-0 flex-col gap-3.5 px-6 pb-5">
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field label={t('payment.amount')} error={errors.amount} required>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    inputMode="decimal"
                                    prefix={<span className="t-admin-currency-num">{TAKA}</span>}
                                    className="tabular"
                                    value={amount}
                                    onChange={(e) => setAmount(e.target.value)}
                                />
                            )}
                        </Field>
                        <Field label={t('payment.method')} error={errors.method}>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={method} onChange={(e) => setMethod(e.target.value as PaymentMethod)}>
                                    {paymentMethods.map((m) => (
                                        <option key={m} value={m}>
                                            {t(`payment.method.${m}` as MessageKey)}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                        <Field label={t('payment.paidAt')} error={errors.paid_at} required>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} type="date" max={dhakaToday()} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="tabular" value={paidAt} onChange={(e) => setPaidAt(e.target.value)} />
                            )}
                        </Field>
                        <Field label={t('payment.reference')} hint={t('payment.referenceHint')} error={errors.reference} optional>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} autoComplete="off" value={reference} onChange={(e) => setReference(e.target.value)} />
                            )}
                        </Field>
                    </div>
                    {purchases.length > 0 && (
                        <Field label={t('payment.purchase')} error={errors.purchase_id} optional>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={purchase} onChange={(e) => setPurchase(e.target.value)}>
                                    <option value="">{t('payment.purchaseNone')}</option>
                                    {purchases.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.number}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                    )}
                    <Field label={t('payment.note')} error={errors.note} optional>
                        {({ id, describedBy, invalid }) => (
                            <textarea id={id} rows={2} maxLength={500} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} value={note} onChange={(e) => setNote(e.target.value)} />
                        )}
                    </Field>
                </fieldset>

                <div className="flex justify-end gap-3 border-t border-line-default px-6 py-4">
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" disabled={save.isPending}>
                        {t('payment.submit')}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}
