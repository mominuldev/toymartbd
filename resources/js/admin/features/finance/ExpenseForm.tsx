import { useEffect, useId, useRef, useState } from 'react';
import { useForm, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { expenseRequestSchema } from '@toymart/api-types/zod';
import { CalendarBlankIcon, CheckIcon, PaperclipIcon, XIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { paymentMethods, useSaveExpense, type Expense, type ExpenseCategory, type ExpenseInput, type PaymentMethod } from './api';
import { Notice, TAKA, Toggle, dhakaDate, methodLabel } from './parts';

type FormValues = {
    amount: string;
    category_id: string;
    payment_method: PaymentMethod | '';
    expense_date: string;
    vendor: string;
    reference: string;
    description: string;
    is_recurring: boolean;
};

const fields: (keyof FormValues)[] = ['amount', 'category_id', 'payment_method', 'expense_date', 'vendor', 'reference', 'description', 'is_recurring'];
const validateInput = zodResolver(expenseRequestSchema);

function toForm(expense?: Expense): FormValues {
    return {
        amount: expense?.amount ?? '',
        category_id: expense ? String(expense.category.id) : '',
        payment_method: expense?.payment_method ?? '',
        expense_date: expense?.expense_date ?? dhakaDate(0),
        vendor: expense?.vendor ?? '',
        reference: expense?.reference ?? '',
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
        reference: orNull(values.reference),
        is_recurring: values.is_recurring,
        recurrence: values.is_recurring ? (expense?.recurrence ?? 'monthly') : null,
        order_id: expense?.order_id ?? null,
    };
}

/** "248 KB" / "1.2 MB" — a file's size for the attachment row (bytes, not money). */
function fileSize(bytes: number): string {
    if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/** "JPEG" from "image/jpeg", "PDF" from "application/pdf". */
function fileType(mime: string): string {
    return (mime.split('/')[1] ?? mime).toUpperCase();
}

/**
 * Add / edit expense (Figma "Drawer · Add expense" 423:72036): a 520-wide right drawer on
 * <dialog>.showModal() — header with title and caption, Amount (৳ prefix), Category + Date,
 * What was it for?, Vendor + Reference, Paid by (with "Not paid yet"), the receipt (the
 * attached file row and a dashed drop zone), "Repeat every month", the "Approved and paid are
 * two different steps" notice, and a footer with Cancel / Save. Client validation is the schema
 * generated from the FormRequest (rule 27); the server's 422 lands on the same fields.
 */
export function ExpenseDrawer({ open, categories, expense, onClose }: { open: boolean; categories: ExpenseCategory[]; expense?: Expense; onClose: () => void }) {
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }
    }, [open]);

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            onCancel={onClose}
            className="m-0 ms-auto h-dvh max-h-dvh w-full max-w-130 overflow-hidden bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay max-md:max-w-none"
        >
            {open && <ExpenseFormBody key={expense?.id ?? 'new'} titleId={titleId} categories={categories} expense={expense} onClose={onClose} />}
        </dialog>
    );
}

function ExpenseFormBody({ titleId, categories, expense, onClose }: { titleId: string; categories: ExpenseCategory[]; expense?: Expense; onClose: () => void }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const save = useSaveExpense(expense?.id ?? null);
    const [receipt, setReceipt] = useState<File | null>(null);
    const [receiptError, setReceiptError] = useState<string | undefined>();
    const [dragging, setDragging] = useState(false);
    const fileRef = useRef<HTMLInputElement>(null);
    const helpId = useId();

    const { register, handleSubmit, setError, formState, watch, setValue } = useForm<FormValues>({
        defaultValues: toForm(expense),
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values, expense), context, options as never);
            return Object.keys(result.errors).length > 0 ? { values: {}, errors: result.errors as never } : { values, errors: {} };
        },
    });

    const submit = handleSubmit((values) =>
        save.mutate(
            { input: toInput(values, expense), receipt },
            {
                onSuccess: () => {
                    notify(t(expense ? 'expenses.saved' : 'expenses.created'));
                    onClose();
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
    const parentOf = (c: ExpenseCategory) => (c.parent_id ? categories.find((p) => p.id === c.parent_id) : undefined);
    const optionLabel = (c: ExpenseCategory) => {
        const parent = parentOf(c);
        return parent ? `${name(parent)} › ${name(c)}` : name(c);
    };
    const recurring = watch('is_recurring');
    const saved = expense?.receipt && !receipt ? expense.receipt : null;
    const pick = (file: File | null) => {
        setReceipt(file);
        setReceiptError(undefined);
    };

    return (
        <form onSubmit={submit} noValidate className="flex h-full flex-col">
            <header className="flex items-center gap-3 border-b border-line-default px-6 py-4.5">
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <h2 id={titleId} className="t-admin-card-title text-fg-primary">
                        {t(expense ? 'finUi.drawer.editTitle' : 'finUi.drawer.addTitle')}
                    </h2>
                    <p className="t-admin-caption text-fg-secondary">{t(expense ? 'finUi.drawer.editBody' : 'finUi.drawer.addBody')}</p>
                </div>
                <IconButton aria-label={t('finUi.close')} onClick={onClose}>
                    <XIcon size={18} aria-hidden />
                </IconButton>
            </header>

            <fieldset disabled={save.isPending} className="flex min-h-0 min-w-0 flex-1 flex-col gap-5 overflow-y-auto px-6 py-5">
                <Field label={t('expenses.form.amount')} error={errorOf('amount')} required>
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            aria-describedby={describedBy}
                            aria-invalid={invalid || undefined}
                            inputMode="decimal"
                            className="tabular"
                            prefix={<span className="t-admin-num text-fg-tertiary">{TAKA}</span>}
                            {...register('amount')}
                        />
                    )}
                </Field>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label={t('expenses.form.category')} error={errorOf('category_id')} required>
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} {...register('category_id')}>
                                <option value="">{t('expenses.form.categoryPick')}</option>
                                {active.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {optionLabel(c)}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                    <Field label={t('expenses.form.date')} error={errorOf('expense_date')} required>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                type="date"
                                max={dhakaDate(0)}
                                leading={<CalendarBlankIcon size={16} aria-hidden />}
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                                className="tabular"
                                {...register('expense_date')}
                            />
                        )}
                    </Field>
                </div>
                <Field label={t('finUi.drawer.whatFor')} error={errorOf('description')} optional>
                    {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} {...register('description')} />}
                </Field>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label={t('expenses.form.vendor')} error={errorOf('vendor')} optional>
                        {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} {...register('vendor')} />}
                    </Field>
                    <Field label={t('finUi.drawer.reference')} error={errorOf('reference')} optional>
                        {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="t-admin-mono" {...register('reference')} />}
                    </Field>
                </div>
                <Field label={t('expenses.form.paidBy')} hint={t('finUi.drawer.paidHint')} error={errorOf('payment_method')}>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} {...register('payment_method')}>
                            <option value="">{t('finUi.exp.notPaid')}</option>
                            {paymentMethods.map((m) => (
                                <option key={m} value={m}>
                                    {t(methodLabel(m))}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                {/* Receipt photo: the attached file, then the dashed drop zone (Figma "Receipt" 423:72139). */}
                <div className="flex flex-col gap-1.5">
                    <p className="t-admin-body-strong text-fg-primary">{t('finUi.drawer.receipt')}</p>
                    <input
                        ref={fileRef}
                        type="file"
                        accept="application/pdf,image/jpeg,image/png,image/webp"
                        className="sr-only"
                        aria-label={t('expenses.form.receipt')}
                        aria-describedby={helpId}
                        onChange={(e) => pick(e.target.files?.[0] ?? null)}
                    />
                    {(receipt || saved) && (
                        <div className="flex items-center gap-3 rounded-sm border border-line-default bg-subtle py-2.5 ps-3 pe-2">
                            <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-xs-plus bg-brand-subtle text-fg-brand">
                                <PaperclipIcon size={18} />
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className="truncate t-admin-body-strong text-fg-primary">{receipt ? receipt.name : saved!.name}</span>
                                <span className="t-admin-caption text-fg-secondary">
                                    {receipt
                                        ? t('finUi.drawer.receiptMeta', { type: fileType(receipt.type || receipt.name.split('.').pop() || ''), size: fileSize(receipt.size) })
                                        : t('finUi.drawer.receiptMeta', { type: fileType(saved!.mime), size: fileSize(saved!.size) })}
                                </span>
                            </span>
                            {receipt && (
                                <IconButton
                                    size="sm"
                                    aria-label={t('finUi.drawer.receiptRemove', { name: receipt.name })}
                                    onClick={() => {
                                        pick(null);
                                        if (fileRef.current) fileRef.current.value = '';
                                    }}
                                >
                                    <XIcon size={16} aria-hidden />
                                </IconButton>
                            )}
                        </div>
                    )}
                    <button
                        type="button"
                        onClick={() => fileRef.current?.click()}
                        onDragOver={(e) => {
                            e.preventDefault();
                            setDragging(true);
                        }}
                        onDragLeave={() => setDragging(false)}
                        onDrop={(e) => {
                            e.preventDefault();
                            setDragging(false);
                            pick(e.dataTransfer.files?.[0] ?? null);
                        }}
                        className={`flex flex-wrap items-center justify-center gap-2 rounded-sm border border-dashed border-line-strong p-3 ${dragging ? 'bg-brand-subtle' : 'hover:bg-subtle'}`}
                    >
                        <PaperclipIcon size={16} aria-hidden className="text-fg-link" />
                        <span className="t-admin-body text-fg-link">{t(receipt || saved ? 'finUi.drawer.replace' : 'finUi.drawer.drop')}</span>
                        <span className="t-admin-caption text-fg-tertiary">{t('finUi.drawer.types')}</span>
                    </button>
                    <p id={helpId} className={`t-admin-caption ${receiptError ? 'text-fg-primary' : 'sr-only'}`}>
                        {receiptError ?? t('finUi.drawer.types')}
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    <Toggle checked={recurring} onChange={(next) => setValue('is_recurring', next, { shouldDirty: true })} label={t('finUi.drawer.repeat')} />
                    <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span className="t-admin-body-strong text-fg-primary">{t('finUi.drawer.repeat')}</span>
                        <span className="t-admin-caption text-fg-secondary">{t(recurring ? 'finUi.drawer.repeatOn' : 'finUi.drawer.repeatOff')}</span>
                    </span>
                </div>

                <Notice title={t('finUi.drawer.noticeTitle')}>{t('finUi.drawer.noticeBody')}</Notice>
            </fieldset>

            <footer className="flex flex-wrap items-center gap-3 border-t border-line-default px-6 py-4">
                <p className="min-w-0 flex-1 t-admin-caption text-fg-tertiary">{t('finUi.drawer.footer')}</p>
                <Button type="button" variant="ghost" onClick={onClose}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" disabled={save.isPending}>
                    <CheckIcon size={16} aria-hidden />
                    {save.isPending ? t('common.saving') : t(expense ? 'finUi.drawer.saveChanges' : 'finUi.drawer.save')}
                </Button>
            </footer>
        </form>
    );
}
