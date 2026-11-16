import { useState } from 'react';
import { ArrowDownLeftIcon, ArrowUpRightIcon, CalendarBlankIcon, UserPlusIcon } from '@phosphor-icons/react';
import { recordCapitalEntryRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { dhakaDate } from '../parts';
import { capitalMethods, useCapitalHolders, useRecordCapitalEntry, type CapitalEntryInput, type CapitalHolderSummary, type CapitalKind, type CapitalMethod } from './api';
import { CapitalDialog, Notice, TAKA, methodKey, taka, typedAmount } from './parts';

type FieldName = 'holder_id' | 'amount' | 'occurred_on' | 'method' | 'reference' | 'note';

type Props = {
    kind: CapitalKind;
    open: boolean;
    onClose: () => void;
    /** Per-holder figures from the overview, for the drawing dialog's "so far" line. */
    figures: CapitalHolderSummary[];
    /** Preselect this holder (just added from "Add a new owner or partner"). */
    holderId: number | null;
    /** null when the viewer cannot add holders (finance.manage_capital covers both, so always set in practice). */
    onAddHolder: (() => void) | null;
};

/**
 * New investment (money in, Figma 429:74946) and Owner drawing (money out, 429:75097) — one
 * dialog, two kinds. Client validation is the generated schema (rule 27); the server decides
 * everything else. One Idempotency-Key per opening, so a double submit records once. The
 * "After saving" strip and the submit label only echo the typed amount — nothing is computed.
 */
export function CapitalEntryDialog({ kind, open, onClose, figures, holderId, onAddHolder }: Props) {
    const { t } = useI18n();
    const { notify } = useToast();
    const holders = useCapitalHolders();
    const save = useRecordCapitalEntry();
    const moneyIn = kind === 'contribution';
    const active = (holders.data ?? []).filter((h) => h.is_active);

    const [holder, setHolder] = useState('');
    const [amount, setAmount] = useState('');
    const [date, setDate] = useState(() => dhakaDate());
    const [method, setMethod] = useState<CapitalMethod>('cash');
    const [reference, setReference] = useState('');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Partial<Record<FieldName, string>>>({});
    const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());

    const reset = () => {
        setHolder(holderId === null ? '' : String(holderId));
        setAmount('');
        setDate(dhakaDate());
        setMethod(moneyIn ? 'bank_transfer' : 'cash');
        setReference('');
        setNote('');
        setErrors({});
        setIdempotencyKey(crypto.randomUUID());
        save.reset();
    };

    // A holder added while the dialog is open becomes the selection.
    const [seenHolderId, setSeenHolderId] = useState(holderId);
    if (holderId !== seenHolderId) {
        setSeenHolderId(holderId);
        if (holderId !== null) setHolder(String(holderId));
    }

    const typed = typedAmount(amount);
    const chosen = figures.find((f) => String(f.holder.id) === holder);
    const name = (holders.data ?? []).find((h) => String(h.id) === holder)?.name ?? '';

    function submit() {
        const input: CapitalEntryInput = {
            kind,
            holder_id: holder === '' ? 0 : Number(holder),
            amount: amount.trim().replace(/,/g, ''),
            occurred_on: date,
            method,
            reference: reference.trim() === '' ? null : reference.trim(),
            note: note.trim() === '' ? null : note.trim(),
        };
        const next: Partial<Record<FieldName, string>> = {};
        const parsed = recordCapitalEntryRequestSchema.safeParse(input);
        if (!parsed.success) for (const issue of parsed.error.issues) next[issue.path[0] as FieldName] ??= issue.message;
        if (holder === '') next.holder_id = t('capital.field.required', { label: t(moneyIn ? 'capital.in.holder' : 'capital.out.holder') });
        if (amount.trim() === '') next.amount = t('capital.field.required', { label: t('capital.field.amount') });
        else if (typed === null) next.amount ??= t('capital.field.required', { label: t('capital.field.amount') });
        if (Object.keys(next).length > 0) {
            setErrors(next);
            return;
        }
        setErrors({});
        save.mutate(
            { input, idempotencyKey },
            {
                onSuccess: (entry) => {
                    notify(t(moneyIn ? 'capital.in.saved' : 'capital.out.saved', { amount: taka(entry.amount), name: entry.holder?.name ?? name }));
                    onClose();
                },
                onError: (error) => {
                    if (error instanceof ApiError && error.status === 422) {
                        const fieldErrors: Partial<Record<FieldName, string>> = {};
                        for (const { field, message } of error.problem.errors ?? []) fieldErrors[field as FieldName] ??= message;
                        setErrors(fieldErrors);
                        setIdempotencyKey(crypto.randomUUID());
                    } else {
                        notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
                        setIdempotencyKey(crypto.randomUUID());
                    }
                },
            },
        );
    }

    return (
        <CapitalDialog
            open={open}
            onOpen={reset}
            onClose={onClose}
            width="wide"
            title={t(moneyIn ? 'capital.in.title' : 'capital.out.title')}
            subtitle={t(moneyIn ? 'capital.in.subtitle' : 'capital.out.subtitle')}
            pending={save.isPending}
            footnote={t('capital.footer')}
            submitIcon={moneyIn ? <ArrowDownLeftIcon size={16} aria-hidden /> : <ArrowUpRightIcon size={16} aria-hidden />}
            submitLabel={typed === null ? t(moneyIn ? 'capital.in.submitEmpty' : 'capital.out.submitEmpty') : t(moneyIn ? 'capital.in.submit' : 'capital.out.submit', { amount: taka(typed) })}
            onSubmit={submit}
        >
            <Notice tone={moneyIn ? 'success' : 'warning'} icon={moneyIn ? ArrowDownLeftIcon : ArrowUpRightIcon} title={t(moneyIn ? 'capital.in.noticeTitle' : 'capital.out.noticeTitle')}>
                {t(moneyIn ? 'capital.in.noticeBody' : 'capital.out.noticeBody')}
            </Notice>

            <div className="flex flex-col gap-1.5">
                <Field label={t(moneyIn ? 'capital.in.holder' : 'capital.out.holder')} error={errors.holder_id ?? (active.length === 0 && holders.isSuccess ? t('capital.noActiveHolders') : undefined)} required>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={holder} onChange={(e) => setHolder(e.target.value)}>
                            <option value="">{t('capital.field.holderPick')}</option>
                            {active.map((h) => (
                                <option key={h.id} value={h.id}>
                                    {h.name_bn ? `${h.name} · ${h.name_bn}` : h.name}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
                {!moneyIn && chosen && (
                    <p className="t-admin-caption text-fg-secondary tabular">{t('capital.out.soFar', { putIn: taka(chosen.put_in), takenOut: taka(chosen.taken_out) })}</p>
                )}
                {onAddHolder && (
                    <button type="button" onClick={onAddHolder} className="flex w-fit items-center gap-1.5 rounded-xs t-admin-body-strong text-fg-link hover:underline">
                        <UserPlusIcon size={16} aria-hidden />
                        {t('capital.field.addHolder')}
                    </button>
                )}
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <Field label={t('capital.field.amount')} error={errors.amount} required>
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            aria-describedby={describedBy}
                            aria-invalid={invalid || undefined}
                            inputMode="decimal"
                            autoComplete="off"
                            prefix={<span className="t-admin-currency-num text-fg-tertiary">{TAKA}</span>}
                            className="tabular"
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                        />
                    )}
                </Field>
                <Field label={t(moneyIn ? 'capital.in.date' : 'capital.out.date')} hint={t('capital.field.dateHint')} error={errors.occurred_on} required>
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            type="date"
                            max={dhakaDate()}
                            leading={<CalendarBlankIcon size={16} aria-hidden />}
                            aria-describedby={describedBy}
                            aria-invalid={invalid || undefined}
                            className="tabular"
                            value={date}
                            onChange={(e) => setDate(e.target.value)}
                        />
                    )}
                </Field>
            </div>

            <div className="flex flex-col gap-1.5">
                <p aria-hidden className="t-admin-body-strong text-fg-primary">
                    {t(moneyIn ? 'capital.in.method' : 'capital.out.method')}
                    <span aria-hidden className="text-feedback-danger">
                        {' *'}
                    </span>
                </p>
                <SegmentedControl
                    label={t(moneyIn ? 'capital.in.method' : 'capital.out.method')}
                    className="h-9 w-full flex-wrap p-1 [&>button]:flex-1"
                    options={capitalMethods.map((m) => ({ value: m, label: t(methodKey(m)) }))}
                    value={method}
                    onChange={setMethod}
                />
                {errors.method && <p className="t-admin-caption text-fg-primary">{errors.method}</p>}
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <Field label={t('capital.field.reference')} hint={t('capital.field.referenceHint')} error={errors.reference} optional>
                    {({ id, describedBy, invalid }) => (
                        <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} maxLength={120} autoComplete="off" value={reference} onChange={(e) => setReference(e.target.value)} />
                    )}
                </Field>
                <Field label={t('capital.field.note')} error={errors.note} optional>
                    {({ id, describedBy, invalid }) => (
                        <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} maxLength={500} autoComplete="off" value={note} onChange={(e) => setNote(e.target.value)} />
                    )}
                </Field>
            </div>

            {typed !== null && (
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-sm bg-subtle px-3.5 py-3" aria-live="polite">
                    <p className="t-admin-caption-strong text-fg-secondary">{t('capital.after')}</p>
                    <span className="flex flex-wrap items-center gap-2">
                        <StatusChip tone="neutral" showIcon={false} label={t(moneyIn ? 'capital.after.cashIn' : 'capital.after.cashOut', { amount: taka(typed) })} />
                        <StatusChip tone="neutral" showIcon={false} label={t('capital.after.profit')} />
                    </span>
                </div>
            )}
        </CapitalDialog>
    );
}
