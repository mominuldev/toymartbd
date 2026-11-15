import { useId, useRef, useState } from 'react';
import { PauseCircleIcon, TrashIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { useToast } from '@/components/ui/Toast';
import { closeDialog, useDialog } from './dialog';
import { useDiscardHold, useResume, type PosHeldSale } from './api';
import { dhakaDate, dhakaTime } from './format';

type HeldSalesDialogProps = {
    open: boolean;
    sessionId: number;
    holds: PosHeldSale[];
    cartHasLines: boolean;
    holdPending: boolean;
    /** Prefills the label when the dialog opens — the cart's customer name, if any. */
    defaultLabel?: string;
    onHold: (label: string | null) => void;
    onClose: () => void;
    onResumed: (held: PosHeldSale) => void;
};

/**
 * Held sales (F8, and the cart's Hold button) — Figma Sell Pilot 410:43493 (Held sales modal). Park the current cart under an
 * optional label, or bring a parked one back (Resume, once — it needs an empty cart) or Discard it.
 * Each row: the label (or "Sale {time}") over the Dhaka date it was held.
 */
export function HeldSalesDialog({ open, sessionId, holds, cartHasLines, holdPending, defaultLabel = '', onHold, onClose, onResumed }: HeldSalesDialogProps) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const titleId = useId();
    const [label, setLabel] = useState('');
    const resume = useResume(sessionId);
    const discard = useDiscardHold(sessionId);
    const labelRef = useRef<HTMLInputElement>(null);
    // Opened with items in the cart (the cart's Hold button, or F8): the label is ready to type.
    const ref = useDialog(open, () => {
        setLabel(defaultLabel.slice(0, 80));
        if (cartHasLines) requestAnimationFrame(() => labelRef.current?.select());
    });

    const title = (h: PosHeldSale) => h.label ?? t('pos.holds.unnamed', { time: h.held_at ? dhakaTime(h.held_at, locale) : '' });

    return (
        <dialog ref={ref} aria-labelledby={titleId} onClose={onClose} className="m-auto w-[min(100%-2rem,35rem)] rounded-lg-plus bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay">
            <header className="flex items-center gap-4 border-b border-line-default py-4.5 ps-7 pe-5">
                <h2 id={titleId} className="flex-1 t-admin-card-title">
                    {t('pos.holds.title')}
                </h2>
                <IconButton aria-label={t('pos.close.cancel')} className="bg-subtle" onClick={() => closeDialog(ref.current, onClose)}>
                    <XIcon size={18} aria-hidden />
                </IconButton>
            </header>
            <div className="flex flex-col gap-4 px-7 pt-5 pb-6">
                <form
                    className="flex items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!cartHasLines) return;
                        onHold(label.trim() || null);
                        setLabel('');
                    }}
                >
                    <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                        <label htmlFor="pos-hold-label" className="t-admin-caption-strong text-fg-primary">
                            {t('pos.holds.label')}
                        </label>
                        <Input ref={labelRef} id="pos-hold-label" className="h-10" maxLength={80} placeholder={t('pos.holds.labelPlaceholder')} value={label} onChange={(e) => setLabel(e.target.value)} />
                    </div>
                    <Button type="submit" className="h-10 px-4" disabled={!cartHasLines || holdPending}>
                        <PauseCircleIcon size={18} aria-hidden />
                        {t('pos.holds.holdCurrent')}
                    </Button>
                </form>

                {holds.length === 0 ? (
                    <p className="t-admin-body text-fg-secondary">{t('pos.holds.empty')}</p>
                ) : (
                    <ul aria-label={t('pos.holds.count', { count: holds.length })} className="overflow-hidden rounded-sm-plus border border-line-default">
                        {holds.map((h) => (
                            <li key={h.id} className="flex items-center gap-3 border-b border-line-default py-3 ps-4 pe-3 last:border-0">
                                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span className="truncate t-admin-body-strong">{title(h)}</span>
                                    {h.held_at && <span className="t-admin-caption text-fg-secondary">{dhakaDate(h.held_at, locale)}</span>}
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={resume.isPending || cartHasLines}
                                    onClick={() =>
                                        resume.mutate(h.id, {
                                            onSuccess: (held) => {
                                                closeDialog(ref.current, onClose);
                                                onResumed(held);
                                            },
                                            onError: () => notify(t('pos.holds.failed'), 'danger'),
                                        })
                                    }
                                >
                                    {t('pos.holds.resume')}
                                </Button>
                                <IconButton
                                    size="sm"
                                    aria-label={t('pos.holds.discard', { name: title(h) })}
                                    disabled={discard.isPending}
                                    onClick={() =>
                                        discard.mutate(h.id, {
                                            onSuccess: () => notify(t('pos.holds.discarded'), 'success'),
                                            onError: () => notify(t('pos.holds.discardFailed'), 'danger'),
                                        })
                                    }
                                >
                                    <TrashIcon size={16} aria-hidden />
                                </IconButton>
                            </li>
                        ))}
                    </ul>
                )}
                {cartHasLines && holds.length > 0 && <p className="t-admin-caption text-fg-tertiary">{t('pos.holds.replaceHint')}</p>}
            </div>
        </dialog>
    );
}
