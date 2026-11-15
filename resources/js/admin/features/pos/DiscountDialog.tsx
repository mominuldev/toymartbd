import { useId, useRef, useState } from 'react';
import { PercentIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { DISCOUNT_REASONS, discountReasonKey, isDiscountReason, type DiscountDraft, type DiscountReason } from './cart';
import { closeDialog, useDialog } from './dialog';
import { isMoneyInput, TAKA } from './format';

type DiscountDialogProps = {
    open: boolean;
    /** What the cart already carries — edited in place. */
    current: DiscountDraft | null;
    /** The store's cap, a percent of the goods after promotions and the coupon (from the quote). */
    maxPercent: string;
    onApply: (discount: DiscountDraft | null) => void;
    onClose: () => void;
};

/**
 * The cashier's discount (owner decision 2026-11-15): a percent of the goods or a flat taka
 * amount, with a reason from a fixed list. Only the input lives here — the server prices it, checks it against
 * the cap and shows the row in the totals (rule 1). An amount over the cap comes back as a
 * `discount` blocker under the totals.
 */
export function DiscountDialog({ open, current, maxPercent, onApply, onClose }: DiscountDialogProps) {
    const { t } = useI18n();
    const titleId = useId();
    const valueRef = useRef<HTMLInputElement>(null);
    const [type, setType] = useState<DiscountDraft['type']>('percent');
    const [value, setValue] = useState('');
    const [reason, setReason] = useState<DiscountReason | ''>('');
    const [error, setError] = useState<string | null>(null);
    const ref = useDialog(open, () => {
        setType(current?.type ?? 'percent');
        setValue(current?.value ?? '');
        setReason(current?.reason ?? '');
        setError(null);
        requestAnimationFrame(() => valueRef.current?.select());
    });

    const close = () => closeDialog(ref.current, onClose);

    const apply = () => {
        const v = value.trim();
        // Shape checks only; the amount and the cap are the server's.
        if (!isMoneyInput(v) || Number(v) <= 0) return setError(t('pos.discount.valueRequired'));
        if (type === 'percent' && Number(v) > 100) return setError(t('pos.discount.percentMax'));
        if (reason === '') return setError(t('pos.discount.reasonRequired'));
        onApply({ type, value: v, reason });
        close();
    };

    return (
        <dialog ref={ref} aria-labelledby={titleId} onClose={onClose} className="m-auto w-[min(100%-2rem,28rem)] rounded-lg-plus bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay">
            <header className="flex items-center gap-4 border-b border-line-default py-4.5 ps-7 pe-5">
                <h2 id={titleId} className="flex-1 t-admin-card-title">
                    {t('pos.discount.title')}
                </h2>
                <IconButton aria-label={t('pos.close.cancel')} className="bg-subtle" onClick={close}>
                    <XIcon size={18} aria-hidden />
                </IconButton>
            </header>
            <form
                className="flex flex-col gap-4 px-7 pt-5 pb-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    apply();
                }}
            >
                <SegmentedControl
                    label={t('pos.discount.type')}
                    className="self-start"
                    value={type}
                    onChange={setType}
                    options={[
                        { value: 'percent', label: t('pos.discount.percent') },
                        { value: 'amount', label: t('pos.discount.amount') },
                    ]}
                />
                <div className="flex flex-col gap-1.5">
                    <label htmlFor="pos-discount-value" className="t-admin-caption-strong text-fg-primary">
                        {type === 'percent' ? t('pos.discount.percentLabel') : t('pos.discount.amountLabel')}
                    </label>
                    <Input
                        ref={valueRef}
                        id="pos-discount-value"
                        className="h-10"
                        inputMode="decimal"
                        leading={<span aria-hidden>{type === 'percent' ? '%' : TAKA}</span>}
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                    />
                    <span className="t-admin-caption text-fg-secondary">{t('pos.discount.capHint', { percent: maxPercent })}</span>
                </div>
                <div className="flex flex-col gap-1.5">
                    <label htmlFor="pos-discount-reason" className="t-admin-caption-strong text-fg-primary">
                        {t('pos.discount.reason')}
                    </label>
                    <Select id="pos-discount-reason" value={reason} onChange={(e) => setReason(isDiscountReason(e.target.value) ? e.target.value : '')}>
                        <option value="" disabled>
                            {t('pos.discount.reasonChoose')}
                        </option>
                        {DISCOUNT_REASONS.map((code) => (
                            <option key={code} value={code}>
                                {t(discountReasonKey(code))}
                            </option>
                        ))}
                    </Select>
                </div>
                {error && (
                    <p role="alert" className="t-admin-caption text-feedback-danger">
                        {error}
                    </p>
                )}
                <div className="flex justify-end gap-2">
                    {current && (
                        <Button
                            variant="ghost"
                            onClick={() => {
                                onApply(null);
                                close();
                            }}
                        >
                            {t('pos.discount.remove')}
                        </Button>
                    )}
                    <Button type="submit" className="h-10 px-4">
                        <PercentIcon size={18} aria-hidden />
                        {t('pos.discount.apply')}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}
