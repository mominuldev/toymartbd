import { useId, useState } from 'react';
import { ArrowLeftIcon, StackIcon, TrashIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { useToast } from '@/components/ui/Toast';
import { closeDialog, useDialog } from './dialog';
import { useDiscardHold, useResume, type PosHeldSale } from './api';
import { dhakaTime, phoneDisplay } from './format';
import { KEY } from './keys';

type HeldSalesDialogProps = {
    open: boolean;
    sessionId: number;
    holds: PosHeldSale[];
    cartHasLines: boolean;
    holdPending: boolean;
    onHold: (label: string | null) => void;
    onClose: () => void;
    onResumed: (held: PosHeldSale) => void;
};

/**
 * Held sales (F8) — Figma B · 33 POS v2 351:64687. Park the current cart under an optional
 * label, or bring a parked one back (Resume, once — it needs an empty cart) or Discard it.
 */
export function HeldSalesDialog({ open, sessionId, holds, cartHasLines, holdPending, onHold, onClose, onResumed }: HeldSalesDialogProps) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const titleId = useId();
    const [label, setLabel] = useState('');
    const resume = useResume(sessionId);
    const discard = useDiscardHold(sessionId);
    const ref = useDialog(open, () => setLabel(''));

    const title = (h: PosHeldSale) => h.label ?? t('pos.holds.unnamed', { time: h.held_at ? dhakaTime(h.held_at, locale) : '' });

    return (
        <dialog ref={ref} aria-labelledby={titleId} onClose={onClose} className="m-auto w-[min(100%-2rem,33.5rem)] rounded-xl bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay">
            <header className="flex items-center gap-3 border-b border-line-default px-6 py-5">
                <h2 id={titleId} className="flex-1 t-admin-card-title">
                    {t('pos.holds.title')}
                </h2>
                <span aria-hidden className="t-admin-mono-sm text-fg-tertiary">
                    {KEY.f8}
                </span>
                <IconButton variant="outline" aria-label={t('pos.close.cancel')} onClick={() => closeDialog(ref.current, onClose)}>
                    <XIcon size={18} aria-hidden />
                </IconButton>
            </header>
            <div className="flex flex-col gap-4 px-6 py-5">
                <form
                    className="flex flex-col gap-1.5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!cartHasLines) return;
                        onHold(label.trim() || null);
                        setLabel('');
                    }}
                >
                    <label htmlFor="pos-hold-label" className="t-admin-body-strong">
                        {t('pos.holds.label')}
                    </label>
                    <div className="flex flex-wrap gap-2">
                        <Input id="pos-hold-label" className="h-11 min-w-48 flex-1" maxLength={80} placeholder={t('pos.holds.labelPlaceholder')} value={label} onChange={(e) => setLabel(e.target.value)} />
                        <Button type="submit" size="lg" disabled={!cartHasLines || holdPending}>
                            <StackIcon size={18} aria-hidden />
                            {t('pos.holds.holdCurrent')}
                        </Button>
                    </div>
                </form>

                <h3 className="t-admin-overline text-fg-secondary">{t('pos.holds.count', { count: holds.length })}</h3>
                {holds.length === 0 ? (
                    <p className="t-admin-body text-fg-secondary">{t('pos.holds.empty')}</p>
                ) : (
                    <ul className="overflow-hidden rounded-md border border-line-default">
                        {holds.map((h) => (
                            <li key={h.id} className="flex items-center gap-3 border-b border-line-default px-4 py-3 last:border-0">
                                <div className="flex min-w-0 flex-1 flex-col">
                                    <span className="truncate t-admin-body-strong">{title(h)}</span>
                                    <span className="t-admin-caption text-fg-secondary">
                                        {t('pos.holds.items', { count: h.item_count })}
                                        {h.customer_phone ? ` · ${phoneDisplay(h.customer_phone)}` : ''}
                                    </span>
                                </div>
                                <Button
                                    variant="outline"
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
                                    <ArrowLeftIcon size={16} aria-hidden />
                                    {t('pos.holds.resume')}
                                </Button>
                                <IconButton
                                    aria-label={t('pos.holds.discard', { name: title(h) })}
                                    className="size-11 text-feedback-danger! hover:bg-danger-subtle"
                                    disabled={discard.isPending}
                                    onClick={() =>
                                        discard.mutate(h.id, {
                                            onSuccess: () => notify(t('pos.holds.discarded'), 'success'),
                                            onError: () => notify(t('pos.holds.discardFailed'), 'danger'),
                                        })
                                    }
                                >
                                    <TrashIcon size={18} aria-hidden />
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
