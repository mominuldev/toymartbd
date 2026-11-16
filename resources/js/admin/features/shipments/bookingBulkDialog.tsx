import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { TruckIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { useToast } from '@/components/ui/Toast';
import { useQueueBulkBook, type BookingCourier } from './bookingApi';
import { courierName } from './bookingParts';

export type BulkGroup = { courier: BookingCourier; orderIds: number[] };

type Props = {
    open: boolean;
    groups: BulkGroup[];
    onClose: () => void;
    onDone: () => void;
};

/**
 * Bulk "book selected" from the queue. One `POST /shipments/bulk` per courier (the endpoint
 * takes one provider), each with an Idempotency-Key minted once when the dialog opens, so a
 * retried click replays instead of booking twice (rule 17). Bulk returns no consignment ids on
 * either provider (rule 24d): staff must acknowledge that the orders move to "Submitted —
 * awaiting ID" before anything is sent.
 */
export function BulkBookDialog({ open, groups, onClose, onDone }: Props) {
    const ref = useRef<HTMLDialogElement>(null);
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
    const titleId = useId();

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            onCancel={onClose}
            className="m-auto w-full max-w-130 rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay"
        >
            {open && <BulkForm titleId={titleId} groups={groups} onClose={onClose} onDone={onDone} />}
        </dialog>
    );
}

function BulkForm({ titleId, groups, onClose, onDone }: { titleId: string; groups: BulkGroup[]; onClose: () => void; onDone: () => void }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const bulk = useQueueBulkBook();
    const [ack, setAck] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // One key per courier group, fixed for the life of this confirmation.
    const [keys] = useState(() => Object.fromEntries(groups.map((g) => [g.courier.id, crypto.randomUUID()])));
    const total = groups.reduce((sum, g) => sum + g.orderIds.length, 0);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!ack || bulk.isPending) return;
        setError(null);
        try {
            for (const group of groups) {
                const name = courierName(group.courier, locale);
                const response = await bulk.mutateAsync({
                    input: {
                        order_ids: group.orderIds,
                        provider: group.courier.code,
                    },
                    idempotencyKey: keys[group.courier.id]!,
                });
                if (response.meta.requested > 0)
                    notify(
                        t('bookingQueue.bulk.done', {
                            count: response.meta.requested,
                            name,
                        })
                    );
                if (response.meta.failed > 0) {
                    const first = response.data.find((r) => r.result !== 'requested');
                    notify(
                        t('bookingQueue.bulk.partial', {
                            name,
                            failed: response.meta.failed,
                            message: first?.message ?? '',
                        }),
                        'danger'
                    );
                }
            }
            onDone();
        } catch (e) {
            setError(e instanceof ApiError ? e.problem.detail ?? e.problem.title : t('error.title'));
        }
    };

    return (
        <form onSubmit={(e) => void submit(e)} noValidate className="flex flex-col">
            <div className="flex flex-col gap-4 px-6 pt-6 pb-5">
                <h2 id={titleId} className="t-admin-section text-fg-primary">
                    {t('bookingQueue.bulk.title', { count: total })}
                </h2>
                <ul className="flex flex-col gap-1">
                    {groups.map((g) => (
                        <li key={g.courier.id} className="t-admin-body text-fg-primary">
                            {t('bookingQueue.bulk.group', {
                                name: courierName(g.courier, locale),
                                count: g.orderIds.length,
                            })}
                        </li>
                    ))}
                </ul>
                <label className="flex items-start gap-3 rounded-sm bg-warning-subtle px-4 py-3">
                    <input type="checkbox" checked={ack} onChange={(e) => setAck(e.target.checked)} className="mt-1 size-4 shrink-0 accent-brand" />
                    <span className="t-admin-body text-fg-primary">{t('bookingQueue.bulk.ack')}</span>
                </label>
                {error && (
                    <p role="alert" className="flex items-start gap-1 t-admin-caption text-fg-primary">
                        <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                        {error}
                    </p>
                )}
            </div>
            <div className="flex items-center gap-3 border-t border-line-default px-6 py-4">
                <Button variant="ghost" onClick={onClose}>
                    {t('bookingQueue.bulk.cancel')}
                </Button>
                <span className="flex-1" />
                <Button type="submit" disabled={!ack || bulk.isPending}>
                    <TruckIcon size={16} aria-hidden />
                    {bulk.isPending ? t('bookingQueue.bulk.submitting') : t('bookingQueue.bulk.submit', { count: total })}
                </Button>
            </div>
        </form>
    );
}
