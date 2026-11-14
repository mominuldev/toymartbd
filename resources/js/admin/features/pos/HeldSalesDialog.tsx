import { useId } from 'react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { useToast } from '@/components/ui/Toast';
import { closeDialog, useDialog } from './dialog';
import { useResume, type PosHeldSale } from './api';
import { dhakaTime, phoneDisplay } from './format';

/** Parked carts on this drawer, oldest first; Resume puts one back on the register (once). */
export function HeldSalesDialog({ open, sessionId, holds, onClose, onResumed }: { open: boolean; sessionId: number; holds: PosHeldSale[]; onClose: () => void; onResumed: (held: PosHeldSale) => void }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const titleId = useId();
    const resume = useResume(sessionId);
    const ref = useDialog(open, () => undefined);

    return (
        <dialog ref={ref} aria-labelledby={titleId} onClose={onClose} className="m-auto w-[min(100%-2rem,28rem)] rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay">
            <div className="flex flex-col gap-4 p-6">
                <h2 id={titleId} className="t-admin-section">
                    {t('pos.holds.title')}
                </h2>
                {holds.length === 0 ? (
                    <p className="t-admin-body text-fg-secondary">{t('pos.holds.empty')}</p>
                ) : (
                    <ul className="flex flex-col divide-y divide-line-default">
                        {holds.map((h) => (
                            <li key={h.id} className="flex items-center gap-3 py-3">
                                <div className="flex min-w-0 flex-1 flex-col">
                                    <span className="truncate t-admin-body-strong">{h.label ?? t('pos.holds.unnamed', { time: h.held_at ? dhakaTime(h.held_at, locale) : '' })}</span>
                                    <span className="t-admin-caption text-fg-secondary">
                                        {t('pos.holds.items', { count: h.item_count })}
                                        {h.customer_phone ? ` · ${phoneDisplay(h.customer_phone)}` : ''}
                                    </span>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={resume.isPending}
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
                            </li>
                        ))}
                    </ul>
                )}
                <div className="flex justify-end">
                    <Button variant="outline" onClick={() => closeDialog(ref.current, onClose)}>
                        {t('pos.close.cancel')}
                    </Button>
                </div>
            </div>
        </dialog>
    );
}
