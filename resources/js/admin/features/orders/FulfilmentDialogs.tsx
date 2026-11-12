import { useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { CheckCircleIcon, TruckIcon } from '@phosphor-icons/react';
import { deliverOrderRequestSchema, shipOrderRequestSchema } from '@toymart/api-types/zod';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { Field, inputClass, monoInputClass } from '@/components/ui/Field';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import type { Order } from './api';
import { useFulfilOrder } from './fulfilmentApi';

/** A refused move (409) or any other API failure, as one sentence for a toast. */
function problemText(error: unknown, fallback: string): string {
    if (error instanceof ApiError) return error.problem.detail ?? error.problem.title;
    return fallback;
}

/**
 * The detail header's manual fulfilment buttons (P5, until Phase 6 courier booking), each
 * shown only when `available_actions` offers it: "Mark shipped" opens a dialog with an
 * optional courier name and tracking reference; "Mark delivered" asks once before revenue
 * is recognised. The primary look goes to whichever is the order's next step.
 */
export function FulfilmentActions({ order }: { order: Order }) {
    const { t } = useI18n();
    const [dialog, setDialog] = useState<'ship' | 'deliver' | null>(null);
    const actions = order.available_actions;
    const canShip = actions.includes('ship');
    const canDeliver = actions.includes('deliver');
    // Packed orders ship next; earlier ones still have a workflow move as their primary.
    const shipPrimary = order.status === 'ready_to_ship';

    return (
        <>
            {canShip && (
                <Button variant={shipPrimary ? 'primary' : 'outline'} onClick={() => setDialog('ship')}>
                    <TruckIcon size={16} aria-hidden />
                    {t('orders.ship.action')}
                </Button>
            )}
            {canDeliver && (
                <Button onClick={() => setDialog('deliver')}>
                    <CheckCircleIcon size={16} aria-hidden />
                    {t('orders.deliver.action')}
                </Button>
            )}
            {canShip && <ShipDialog open={dialog === 'ship'} order={order} onClose={() => setDialog(null)} />}
            {canDeliver && <DeliverDialog open={dialog === 'deliver'} order={order} onClose={() => setDialog(null)} />}
        </>
    );
}

/** <dialog>.showModal() wiring (real focus trap, Esc closes). */
function useModal(open: boolean) {
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
    return ref;
}

function Shell({ open, title, body, icon, onClose, onSubmit, children, footer }: { open: boolean; title: string; body: string; icon: ReactNode; onClose: () => void; onSubmit: (e: FormEvent) => void; children?: ReactNode; footer: ReactNode }) {
    const ref = useModal(open);
    const titleId = useId();
    return (
        <dialog ref={ref} aria-labelledby={titleId} onClose={onClose} onCancel={onClose} className="m-auto w-[min(100%-2rem,32.5rem)] rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay">
            <form noValidate onSubmit={onSubmit}>
                <div className="flex flex-col items-start gap-2.5 px-6 pt-6 pb-4">
                    <span aria-hidden className="grid size-11 place-items-center rounded-sm bg-brand-subtle text-fg-brand">
                        {icon}
                    </span>
                    <h2 id={titleId} className="t-admin-section text-fg-primary">
                        {title}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">{body}</p>
                </div>
                {children && <div className="flex flex-col gap-4 px-6 pb-4">{children}</div>}
                <div className="flex justify-end gap-3 border-t border-line-default px-6 py-4">{footer}</div>
            </form>
        </dialog>
    );
}

type ShipErrors = { courier_name?: string; tracking_reference?: string; note?: string };

function ShipDialog({ open, order, onClose }: { open: boolean; order: Order; onClose: () => void }) {
    const { t } = useI18n();
    const toast = useToast();
    const fulfil = useFulfilOrder(order.id);
    const [courier, setCourier] = useState('');
    const [tracking, setTracking] = useState('');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<ShipErrors>({});
    const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());

    useEffect(() => {
        if (open) {
            setCourier('');
            setTracking('');
            setNote('');
            setErrors({});
            setIdempotencyKey(crypto.randomUUID());
        }
    }, [open]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const parsed = shipOrderRequestSchema.safeParse({ courier_name: courier.trim() || null, tracking_reference: tracking.trim() || null, note: note.trim() || null });
        if (!parsed.success) {
            const next: ShipErrors = {};
            for (const issue of parsed.error.issues) next[issue.path[0] as keyof ShipErrors] ??= issue.message;
            setErrors(next);
            return;
        }
        fulfil.mutate(
            { move: 'ship', input: parsed.data, idempotencyKey },
            {
                onSuccess: () => {
                    toast.notify(t('orders.ship.done', { number: order.number }));
                    onClose();
                },
                onError: (e) => toast.notify(problemText(e, t('error.title')), 'danger'),
            },
        );
    };

    return (
        <Shell
            open={open}
            icon={<TruckIcon size={22} />}
            title={t('orders.ship.title', { number: order.number })}
            body={t('orders.ship.body')}
            onClose={onClose}
            onSubmit={submit}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" disabled={fulfil.isPending} aria-busy={fulfil.isPending || undefined}>
                        {t('orders.ship.submit')}
                    </Button>
                </>
            }
        >
            <Field label={t('orders.ship.courier')} hint={t('orders.ship.courierHint')} error={errors.courier_name} optional>
                {({ id, describedBy, invalid }) => (
                    <input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} maxLength={120} autoComplete="off" className={`h-11 ${inputClass}`} value={courier} onChange={(e) => setCourier(e.target.value)} />
                )}
            </Field>
            <Field label={t('orders.ship.tracking')} hint={t('orders.ship.trackingHint')} error={errors.tracking_reference} optional>
                {({ id, describedBy, invalid }) => (
                    <input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} maxLength={120} autoComplete="off" spellCheck={false} className={`h-11 ${monoInputClass}`} value={tracking} onChange={(e) => setTracking(e.target.value)} />
                )}
            </Field>
            <Field label={t('orders.ship.note')} error={errors.note} optional>
                {({ id, describedBy, invalid }) => (
                    <textarea id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} rows={2} maxLength={500} className={`py-2 ${inputClass}`} value={note} onChange={(e) => setNote(e.target.value)} />
                )}
            </Field>
        </Shell>
    );
}

function DeliverDialog({ open, order, onClose }: { open: boolean; order: Order; onClose: () => void }) {
    const { t } = useI18n();
    const toast = useToast();
    const fulfil = useFulfilOrder(order.id);
    const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());

    useEffect(() => {
        if (open) setIdempotencyKey(crypto.randomUUID());
    }, [open]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        fulfil.mutate(
            { move: 'deliver', input: deliverOrderRequestSchema.parse({}), idempotencyKey },
            {
                onSuccess: () => {
                    toast.notify(t('orders.deliver.done', { number: order.number }));
                    onClose();
                },
                onError: (e) => toast.notify(problemText(e, t('error.title')), 'danger'),
            },
        );
    };

    return (
        <Shell
            open={open}
            icon={<CheckCircleIcon size={22} />}
            title={t('orders.deliver.title', { number: order.number })}
            body={t('orders.deliver.body')}
            onClose={onClose}
            onSubmit={submit}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" disabled={fulfil.isPending} aria-busy={fulfil.isPending || undefined}>
                        {t('orders.deliver.submit')}
                    </Button>
                </>
            }
        />
    );
}
