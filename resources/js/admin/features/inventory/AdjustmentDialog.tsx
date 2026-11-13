import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { SlidersHorizontalIcon } from '@phosphor-icons/react';
import { storeStockAdjustmentRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { Field, inputClass } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { SearchField } from '@/components/ui/SearchField';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { adjustmentReasons, useAdjustStock, useStock, type AdjustmentInput, type AdjustmentReason, type InventoryLocation, type StockRow } from './api';
import { VariantCell, formatUnits } from './parts';

type Mode = 'change' | 'counted';
type FieldName = 'variant' | 'location' | 'reason' | 'amount' | 'note';
type Errors = Partial<Record<FieldName, string>>;

/**
 * The input side of each reason, so staff type "2 damaged", not "-2". The server is the
 * authority: AdjustStock refuses a reason moving stock the wrong way, whatever is sent.
 */
const fixedSign: Partial<Record<AdjustmentReason, 1 | -1>> = { found: 1, damage: -1, loss: -1, theft: -1, expiry: -1, gift_sample: -1 };

/** API field → form field, for the server's 422 errors. */
function formField(apiField: string): FieldName | null {
    if (apiField === 'reason') return 'reason';
    if (apiField === 'note') return 'note';
    if (apiField === 'location_id') return 'location';
    if (/^lines\.0\.variant_id$/.test(apiField) || apiField === 'lines') return 'variant';
    if (/^lines\.0\.(quantity|counted)$/.test(apiField)) return 'amount';
    return null;
}

type AdjustmentDialogProps = {
    open: boolean;
    onClose: () => void;
    /** Preselected variant (the row's Adjust button); otherwise the dialog searches. */
    row: StockRow | null;
    /** Opening reason: Stock count opens on `recount`. */
    initialReason: AdjustmentReason;
    locations: InventoryLocation[];
    locationId: number;
};

/**
 * Stock adjustment form (06-admin-feature-checklist §2.7 [C]; no screen drawn — built from
 * ConfirmDialog 203:1387's frame, AdminField 185:151, AdminInput 184:338, AdminSelect 184:369,
 * SegmentedControl 185:110 and AdminButton 184:242). Client validation is the schema generated
 * from the API's FormRequest (rule 27); the server stays the authority and its 422 lands on
 * the same fields. One Idempotency-Key per opening, so a retry never adjusts twice.
 */
export function AdjustmentDialog({ open, onClose, row, initialReason, locations, locationId }: AdjustmentDialogProps) {
    const { t } = useI18n();
    const { notify } = useToast();
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const save = useAdjustStock();

    const [picked, setPicked] = useState<StockRow | null>(row);
    const [term, setTerm] = useState('');
    const [location, setLocation] = useState(String(locationId));
    const [reason, setReason] = useState<AdjustmentReason>(initialReason);
    const [mode, setMode] = useState<Mode>(initialReason === 'recount' ? 'counted' : 'change');
    const [amount, setAmount] = useState('');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());

    const search = term.trim();
    const results = useStock({ location, state: '', search }, open && !picked && search.length >= 2);
    const options = useMemo(() => results.data?.pages.flatMap((p) => p.data).slice(0, 8) ?? [], [results.data]);

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            setPicked(row);
            setTerm('');
            setLocation(String(locationId));
            setReason(initialReason);
            setMode(initialReason === 'recount' ? 'counted' : 'change');
            setAmount('');
            setNote('');
            setErrors({});
            setIdempotencyKey(crypto.randomUUID());
            save.reset();
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const sign = mode === 'change' ? fixedSign[reason] : undefined;
    const amountHint: MessageKey =
        mode === 'counted' ? 'adjust.countedHint' : sign === -1 ? 'adjust.quantityOutHint' : sign === 1 ? 'adjust.quantityInHint' : 'adjust.quantityHint';

    function payload(): AdjustmentInput | null {
        if (!picked) return null;
        const n = amount.trim() === '' || !/^-?\d+$/.test(amount.trim()) ? Number.NaN : Number(amount.trim());
        const line =
            mode === 'counted'
                ? { variant_id: picked.variant_id, counted: n }
                : { variant_id: picked.variant_id, quantity: sign === undefined ? n : sign * Math.abs(n) };
        return {
            reason,
            note: note.trim() === '' ? null : note.trim(),
            location_id: locations.length > 1 ? Number(location) : null,
            lines: [line],
        };
    }

    function submit() {
        const input = payload();
        if (!input || amount.trim() === '') {
            // The schema allows either field to be absent (one of the two is required), so
            // "nothing entered" is caught here; everything else is the generated schema's call.
            setErrors({ ...(input ? {} : { variant: t('validation.required') }), ...(amount.trim() === '' ? { amount: t('validation.required') } : {}) });
            return;
        }
        const parsed = storeStockAdjustmentRequestSchema.safeParse(input);
        if (!parsed.success) {
            const next: Errors = {};
            for (const issue of parsed.error.issues) {
                const field = formField(issue.path.join('.'));
                if (field && !next[field]) next[field] = issue.message;
            }
            setErrors(next);
            return;
        }
        setErrors({});
        save.mutate(
            { input, idempotencyKey },
            {
                onSuccess: (adjustment) => {
                    notify(t(adjustment.status === 'pending' ? 'adjust.pendingSaved' : 'adjust.saved', { number: adjustment.number }));
                    onClose();
                },
                onError: (error) => {
                    if (error instanceof ApiError && error.status === 422) {
                        const next: Errors = {};
                        for (const { field, message } of error.problem.errors ?? []) {
                            const name = formField(field);
                            if (name && !next[name]) next[name] = message;
                        }
                        setErrors(next);
                        // Nothing changed on a 422, so the next attempt may use a fresh key.
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
                        <SlidersHorizontalIcon size={22} />
                    </span>
                    <h2 id={titleId} className="t-admin-section text-fg-primary">
                        {t('adjust.title')}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">{t('adjust.body')}</p>
                    <p className="t-admin-caption text-fg-tertiary">{t('adjust.approvalHint')}</p>
                </div>

                <fieldset disabled={save.isPending} className="flex min-w-0 flex-col gap-3.5 px-6 pb-5">
                    <Field label={t('adjust.variant')} error={errors.variant}>
                        {({ id, describedBy, invalid }) =>
                            picked ? (
                                <div id={id} aria-describedby={describedBy} className="flex flex-col gap-1.5 rounded-sm border border-line-default bg-subtle p-2.5">
                                    <VariantCell row={picked} />
                                    <p className="t-admin-caption tabular text-fg-secondary">
                                        {t('adjust.current', { onHand: formatUnits(picked.on_hand), reserved: formatUnits(picked.reserved), available: formatUnits(picked.available) })}
                                    </p>
                                    {!row && (
                                        <Button variant="ghost" size="sm" className="self-start" onClick={() => setPicked(null)}>
                                            {t('adjust.variantPick')}
                                        </Button>
                                    )}
                                </div>
                            ) : (
                                <div className="flex flex-col gap-1.5">
                                    <SearchField
                                        id={id}
                                        aria-describedby={describedBy}
                                        aria-invalid={invalid || undefined}
                                        label={t('adjust.variantSearch')}
                                        placeholder={t('adjust.variantSearch')}
                                        value={term}
                                        onChange={(e) => setTerm(e.target.value)}
                                    />
                                    {search.length >= 2 && (
                                        <ul aria-label={t('adjust.variantPick')} className="flex max-h-60 flex-col overflow-y-auto rounded-sm border border-line-default">
                                            {options.length === 0 && !results.isFetching ? (
                                                <li className="px-3 py-2 t-admin-caption text-fg-tertiary">{t('adjust.variantNone')}</li>
                                            ) : (
                                                options.map((option) => (
                                                    <li key={option.variant_id} className="border-t border-line-default first:border-t-0">
                                                        <button type="button" onClick={() => setPicked(option)} className="w-full px-3 py-2 text-start hover:bg-subtle">
                                                            <VariantCell row={option} />
                                                        </button>
                                                    </li>
                                                ))
                                            )}
                                        </ul>
                                    )}
                                </div>
                            )
                        }
                    </Field>

                    <div className={`grid gap-3.5 ${locations.length > 1 ? 'sm:grid-cols-2' : ''}`}>
                        {locations.length > 1 && (
                            <Field label={t('adjust.location')} error={errors.location}>
                                {({ id, describedBy, invalid }) => (
                                    <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={location} onChange={(e) => setLocation(e.target.value)}>
                                        {locations.map((l) => (
                                            <option key={l.id} value={l.id}>
                                                {l.name}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                        )}
                        <Field label={t('adjust.reason')} error={errors.reason}>
                            {({ id, describedBy, invalid }) => (
                                <Select
                                    id={id}
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    value={reason}
                                    onChange={(e) => {
                                        const next = e.target.value as AdjustmentReason;
                                        setReason(next);
                                        if (next === 'recount') setMode('counted');
                                    }}
                                >
                                    {adjustmentReasons.map((r) => (
                                        <option key={r} value={r}>
                                            {t(`adjust.reason.${r}` as MessageKey)}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <SegmentedControl<Mode>
                            label={t('adjust.mode')}
                            options={[
                                { value: 'change', label: t('adjust.mode.change') },
                                { value: 'counted', label: t('adjust.mode.counted') },
                            ]}
                            value={mode}
                            onChange={setMode}
                            className="self-start"
                        />
                        <Field label={mode === 'counted' ? t('adjust.counted') : t('adjust.quantity')} hint={t(amountHint)} error={errors.amount} required>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    inputMode={sign === undefined && mode === 'change' ? 'text' : 'numeric'}
                                    autoComplete="off"
                                    className="tabular"
                                    value={amount}
                                    onChange={(e) => setAmount(e.target.value)}
                                />
                            )}
                        </Field>
                    </div>

                    <Field label={t('adjust.note')} error={errors.note} optional>
                        {({ id, describedBy, invalid }) => (
                            <textarea
                                id={id}
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                                rows={2}
                                maxLength={500}
                                placeholder={t('adjust.notePlaceholder')}
                                className={`py-2 ${inputClass}`}
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                            />
                        )}
                    </Field>
                </fieldset>

                <div className="flex justify-end gap-3 border-t border-line-default px-6 py-4">
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" disabled={save.isPending}>
                        {t('adjust.submit')}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}
