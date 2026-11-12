import { useEffect, useRef, useState } from 'react';
import { useForm, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { expenseRequestSchema } from '@toymart/api-types/zod';
import { PaperclipIcon, XIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { Field, inputClass } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { paymentMethods, useSaveExpense, type Expense, type ExpenseCategory, type ExpenseInput, type PaymentMethod } from './api';
import { TAKA, Toggle, dhakaDate, methodLabel } from './parts';

type FormValues = {
    amount: string;
    category_id: string;
    payment_method: PaymentMethod | '';
    expense_date: string;
    vendor: string;
    description: string;
    is_recurring: boolean;
};

const fields: (keyof FormValues)[] = ['amount', 'category_id', 'payment_method', 'expense_date', 'vendor', 'description', 'is_recurring'];
const validateInput = zodResolver(expenseRequestSchema);

function toForm(expense?: Expense): FormValues {
    return {
        amount: expense?.amount ?? '',
        category_id: expense ? String(expense.category.id) : '',
        payment_method: expense?.payment_method ?? '',
        expense_date: expense?.expense_date ?? dhakaDate(0),
        vendor: expense?.vendor ?? '',
        description: expense?.description ?? '',
        is_recurring: expense?.is_recurring ?? false,
    };
}

/** Empty optional text means "no value" to the API; the receipt travels separately. */
function toInput(values: FormValues, expense?: Expense): ExpenseInput {
    const orNull = (value: string) => (value.trim() === '' ? null : value.trim());
    return {
        amount: values.amount.trim().replace(/,/g, ''),
        category_id: Number(values.category_id),
        payment_method: values.payment_method === '' ? null : values.payment_method,
        paid_on: values.payment_method === '' ? null : (expense?.paid_on ?? values.expense_date),
        expense_date: values.expense_date,
        vendor: orNull(values.vendor),
        description: orNull(values.description),
        reference: expense?.reference ?? null,
        is_recurring: values.is_recurring,
        recurrence: values.is_recurring ? (expense?.recurrence ?? 'monthly') : null,
        order_id: expense?.order_id ?? null,
    };
}

/**
 * The "Add expense" side card of Figma Expenses 222:13676: Amount (৳ prefix), Category, Paid
 * by, Date, the dashed receipt drop zone, "Repeat monthly" and Save. Vendor and description
 * are added (the API needs a description of what was bought). Client validation is the
 * schema generated from the FormRequest (rule 27); the server's 422 lands on the same fields.
 */
export function ExpenseForm({ categories, expense, onDone }: { categories: ExpenseCategory[]; expense?: Expense; onDone: () => void }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const save = useSaveExpense(expense?.id ?? null);
    const [receipt, setReceipt] = useState<File | null>(null);
    const [receiptError, setReceiptError] = useState<string | undefined>();
    const fileRef = useRef<HTMLInputElement>(null);

    const form = useForm<FormValues>({
        defaultValues: toForm(expense),
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values, expense), context, options as never);
            return Object.keys(result.errors).length > 0 ? { values: {}, errors: result.errors as never } : { values, errors: {} };
        },
    });
    const { register, handleSubmit, setError, formState, reset, watch, setValue } = form;
    useEffect(() => {
        reset(toForm(expense));
        setReceipt(null);
    }, [expense, reset]);

    const submit = handleSubmit((values) =>
        save.mutate(
            { input: toInput(values, expense), receipt },
            {
                onSuccess: () => {
                    notify(t(expense ? 'expenses.saved' : 'expenses.created'));
                    reset(toForm());
                    setReceipt(null);
                    onDone();
                },
                onError: (error) => {
                    if (error instanceof ApiError && error.status === 422) {
                        for (const field of fields) {
                            const message = error.fieldError(field);
                            if (message) setError(field as FieldPath<FormValues>, { message });
                        }
                        setReceiptError(error.fieldError('receipt'));
                    } else {
                        notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
                    }
                },
            },
        ),
    );

    const errorOf = (field: keyof FormValues) => formState.errors[field]?.message;
    const active = categories.filter((c) => c.is_active || String(c.id) === watch('category_id'));
    const name = (c: ExpenseCategory) => (locale === 'bn' ? (c.name_bn ?? c.name) : c.name);

    return (
        <form onSubmit={submit} noValidate className="flex flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1">
            <h2 className="t-admin-section text-fg-primary">{t(expense ? 'expenses.form.edit' : 'expenses.form.add')}</h2>
            <fieldset disabled={save.isPending} className="flex min-w-0 flex-col gap-3.5">
                <Field label={t('expenses.form.amount')} error={errorOf('amount')} required>
                    {({ id, describedBy, invalid }) => (
                        <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} inputMode="decimal" className="tabular" prefix={<span className="t-admin-currency-num text-fg-primary">{TAKA}</span>} {...register('amount')} />
                    )}
                </Field>
                <Field label={t('expenses.form.category')} error={errorOf('category_id')} required>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} {...register('category_id')}>
                            <option value="">{t('expenses.form.categoryPick')}</option>
                            {active.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.parent_id ? `— ${name(c)}` : name(c)}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
                <Field label={t('expenses.form.paidBy')} error={errorOf('payment_method')}>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} {...register('payment_method')}>
                            <option value="">{t('expenses.form.notPaid')}</option>
                            {paymentMethods.map((m) => (
                                <option key={m} value={m}>
                                    {t(methodLabel(m))}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
                <Field label={t('expenses.form.date')} error={errorOf('expense_date')} required>
                    {({ id, describedBy, invalid }) => (
                        <Input id={id} type="date" max={dhakaDate(0)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="tabular" {...register('expense_date')} />
                    )}
                </Field>
                <Field label={t('expenses.form.vendor')} error={errorOf('vendor')} optional>
                    {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} {...register('vendor')} />}
                </Field>
                <Field label={t('expenses.form.description')} error={errorOf('description')} optional>
                    {({ id, describedBy, invalid }) => (
                        <textarea id={id} rows={2} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} {...register('description')} />
                    )}
                </Field>

                {/* The dashed receipt drop zone (Figma "Attach receipt photo"). */}
                <div className="flex flex-col gap-1.5">
                    <input
                        ref={fileRef}
                        type="file"
                        accept="application/pdf,image/jpeg,image/png,image/webp"
                        className="sr-only"
                        aria-label={t('expenses.form.receipt')}
                        onChange={(e) => {
                            setReceipt(e.target.files?.[0] ?? null);
                            setReceiptError(undefined);
                        }}
                    />
                    {receipt ? (
                        <div className="flex items-center gap-2 rounded-sm border border-line-default bg-subtle px-3 py-2">
                            <PaperclipIcon size={16} aria-hidden className="shrink-0 text-fg-secondary" />
                            <span className="min-w-0 flex-1 truncate t-admin-caption text-fg-primary">{t('expenses.form.receiptChosen', { name: receipt.name })}</span>
                            <button
                                type="button"
                                aria-label={t('expenses.form.receiptRemove')}
                                onClick={() => {
                                    setReceipt(null);
                                    if (fileRef.current) fileRef.current.value = '';
                                }}
                                className="grid size-6 place-items-center rounded-xs text-fg-secondary hover:bg-surface"
                            >
                                <XIcon size={14} aria-hidden />
                            </button>
                        </div>
                    ) : (
                        <button
                            type="button"
                            onClick={() => fileRef.current?.click()}
                            className="flex flex-col items-center gap-1.5 rounded-sm border border-dashed border-line-strong bg-subtle px-3 py-5 text-fg-secondary hover:bg-surface"
                        >
                            <PaperclipIcon size={18} aria-hidden />
                            <span className="t-admin-caption">{t('expenses.form.receipt')}</span>
                        </button>
                    )}
                    <p className="t-admin-caption text-fg-tertiary">{receiptError ?? t('expenses.form.receiptHint')}</p>
                </div>

                <label className="flex items-center gap-2.5">
                    <Toggle checked={watch('is_recurring')} onChange={(next) => setValue('is_recurring', next, { shouldDirty: true })} label={t('expenses.form.repeat')} />
                    <span className="t-admin-body text-fg-primary">{t('expenses.form.repeat')}</span>
                </label>
            </fieldset>
            <div className="flex flex-wrap gap-3">
                <Button type="submit" disabled={save.isPending}>
                    {save.isPending ? t('common.saving') : t('expenses.form.save')}
                </Button>
                {expense && (
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('expenses.form.cancel')}
                    </Button>
                )}
            </div>
        </form>
    );
}
