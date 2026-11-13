import { useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Link } from 'react-router';
import { ArrowClockwiseIcon, CheckIcon, PackageIcon, PauseIcon, PencilSimpleIcon, PlayIcon, PrinterIcon, ProhibitIcon, WarningIcon } from '@phosphor-icons/react';
import { cancelOrderRequestSchema, holdOrderRequestSchema } from '@toymart/api-types/zod';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button, buttonClass } from '@/components/ui/Button';
import { Field, inputClass, monoInputClass } from '@/components/ui/Field';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import { cancelReasons, useOrderMove, type CancelReason, type Order, type WorkflowMove } from './api';
import { openInvoices } from './composeApi';
import { FulfilmentActions } from './FulfilmentDialogs';

/** A refused move (409) or any other API failure, as one sentence for a toast. */
export function problemText(error: unknown, fallback: string): string {
    if (error instanceof ApiError) return error.problem.detail ?? error.problem.title;
    return fallback;
}

const moveLooks: Record<WorkflowMove, { label: MessageKey; done: MessageKey; icon: typeof CheckIcon }> = {
    confirm: { label: 'orders.action.confirm', done: 'orders.action.confirmed', icon: CheckIcon },
    release: { label: 'orders.action.release', done: 'orders.action.released', icon: PlayIcon },
    processing: { label: 'orders.action.processing', done: 'orders.action.processingDone', icon: ArrowClockwiseIcon },
    ready_to_ship: { label: 'orders.action.readyToShip', done: 'orders.action.readyToShipDone', icon: PackageIcon },
};

/**
 * The detail header's actions: exactly `available_actions` from the server (state machine ×
 * permissions), so the client never re-derives a transition. The forward move is primary.
 */
export function OrderActions({ order }: { order: Order }) {
    const { t } = useI18n();
    const toast = useToast();
    const move = useOrderMove(order.id);
    const [dialog, setDialog] = useState<'hold' | 'cancel' | null>(null);
    const actions = order.available_actions;
    const forward = (['confirm', 'release', 'processing', 'ready_to_ship'] as WorkflowMove[]).filter((a) => actions.includes(a));

    const run = (action: WorkflowMove) =>
        move.mutate(
            { action },
            {
                onSuccess: () => toast.notify(t(moveLooks[action].done, { number: order.number })),
                onError: (error) => toast.notify(problemText(error, t('error.title')), 'danger'),
            },
        );

    const [printing, setPrinting] = useState(false);
    const print = () => {
        setPrinting(true);
        openInvoices([order.id])
            .catch((error: unknown) => toast.notify(problemText(error, t('orders.invoice.failed')), 'danger'))
            .finally(() => setPrinting(false));
    };

    return (
        <>
            {order.can_print_invoice && (
                <Button variant="outline" onClick={print} disabled={printing} aria-busy={printing || undefined}>
                    <PrinterIcon size={16} aria-hidden />
                    {printing ? t('orders.invoice.printing') : t('orders.invoice.print')}
                </Button>
            )}
            {order.editable && (
                <Link to={`/orders/${order.id}/edit`} className={buttonClass('outline')}>
                    <PencilSimpleIcon size={16} aria-hidden />
                    {t('orders.edit.action')}
                </Link>
            )}
            {actions.includes('cancel') && (
                <Button variant="outline" onClick={() => setDialog('cancel')}>
                    <ProhibitIcon size={16} aria-hidden className="text-feedback-danger" />
                    {t('orders.action.cancel')}
                </Button>
            )}
            {actions.includes('hold') && (
                <Button variant="outline" onClick={() => setDialog('hold')}>
                    <PauseIcon size={16} aria-hidden />
                    {t('orders.action.hold')}
                </Button>
            )}
            {forward.map((action, i) => {
                const { label, icon: Icon } = moveLooks[action];
                return (
                    <Button key={action} variant={i === 0 ? 'primary' : 'outline'} disabled={move.isPending} onClick={() => run(action)}>
                        <Icon size={16} aria-hidden />
                        {t(label)}
                    </Button>
                );
            })}
            <FulfilmentActions order={order} />
            <HoldDialog open={dialog === 'hold'} order={order} onClose={() => setDialog(null)} />
            <CancelDialog open={dialog === 'cancel'} order={order} onClose={() => setDialog(null)} />
        </>
    );
}

/** <dialog>.showModal() wiring shared by both dialogs (real focus trap, Esc closes). */
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

function Shell({ open, title, body, tone, onClose, onSubmit, children, footer }: { open: boolean; title: string; body: string; tone: 'warning' | 'danger'; onClose: () => void; onSubmit: (e: FormEvent) => void; children: ReactNode; footer: ReactNode }) {
    const ref = useModal(open);
    const titleId = useId();
    return (
        <dialog ref={ref} aria-labelledby={titleId} onClose={onClose} onCancel={onClose} className="m-auto w-[min(100%-2rem,32.5rem)] rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay">
            <form noValidate onSubmit={onSubmit}>
                <div className="flex flex-col items-start gap-2.5 px-6 pt-6 pb-4">
                    <span aria-hidden className={`grid size-11 place-items-center rounded-sm ${tone === 'danger' ? 'bg-danger-subtle text-feedback-danger' : 'bg-warning-subtle text-status-low-stock'}`}>
                        <WarningIcon size={22} />
                    </span>
                    <h2 id={titleId} className="t-admin-section text-fg-primary">
                        {title}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">{body}</p>
                </div>
                <div className="flex flex-col gap-4 px-6 pb-4">{children}</div>
                <div className="flex justify-end gap-3 border-t border-line-default px-6 py-4">{footer}</div>
            </form>
        </dialog>
    );
}

/** Hold always says why (`reason`, required by HoldOrderRequest). */
function HoldDialog({ open, order, onClose }: { open: boolean; order: Order; onClose: () => void }) {
    const { t } = useI18n();
    const toast = useToast();
    const move = useOrderMove(order.id);
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string>();

    useEffect(() => {
        if (open) {
            setReason('');
            setError(undefined);
        }
    }, [open]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const parsed = holdOrderRequestSchema.safeParse({ reason: reason.trim() });
        if (!parsed.success) {
            setError(parsed.error.issues[0]?.message);
            return;
        }
        move.mutate(
            { action: 'hold', input: parsed.data },
            {
                onSuccess: () => {
                    toast.notify(t('orders.action.held', { number: order.number }));
                    onClose();
                },
                onError: (e) => (e instanceof ApiError && e.fieldError('reason') ? setError(e.fieldError('reason')) : toast.notify(problemText(e, t('error.title')), 'danger')),
            },
        );
    };

    return (
        <Shell
            open={open}
            tone="warning"
            title={t('orders.hold.title', { number: order.number })}
            body={t('orders.hold.body')}
            onClose={onClose}
            onSubmit={submit}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" disabled={move.isPending}>
                        {t('orders.hold.submit')}
                    </Button>
                </>
            }
        >
            <Field label={t('orders.hold.reason')} error={error} required>
                {({ id, describedBy, invalid }) => (
                    <textarea id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} rows={3} maxLength={500} className={`py-2 ${inputClass}`} value={reason} onChange={(e) => setReason(e.target.value)} />
                )}
            </Field>
        </Shell>
    );
}

/**
 * Figma ConfirmDialog / Destructive typed 203:1387 for a cancellation: the reason (fixed list),
 * an optional note, and the order number typed exactly before the danger button enables.
 */
function CancelDialog({ open, order, onClose }: { open: boolean; order: Order; onClose: () => void }) {
    const { t } = useI18n();
    const toast = useToast();
    const move = useOrderMove(order.id);
    const [reason, setReason] = useState<CancelReason | ''>('');
    const [note, setNote] = useState('');
    const [typed, setTyped] = useState('');
    const [errors, setErrors] = useState<{ reason?: string; note?: string }>({});

    useEffect(() => {
        if (open) {
            setReason('');
            setNote('');
            setTyped('');
            setErrors({});
        }
    }, [open]);

    const matches = typed.trim() === order.number;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!matches) return;
        const parsed = cancelOrderRequestSchema.safeParse({ reason: reason || undefined, note: note.trim() || null });
        if (!parsed.success) {
            const next: { reason?: string; note?: string } = {};
            for (const issue of parsed.error.issues) next[issue.path[0] as 'reason' | 'note'] ??= issue.message;
            // An unpicked enum is "invalid" to Zod; to the operator it is simply missing.
            if (!reason) next.reason = t('validation.required');
            setErrors(next);
            return;
        }
        move.mutate(
            { action: 'cancel', input: parsed.data },
            {
                onSuccess: () => {
                    toast.notify(t('orders.action.cancelled', { number: order.number }));
                    onClose();
                },
                onError: (e) => toast.notify(problemText(e, t('error.title')), 'danger'),
            },
        );
    };

    return (
        <Shell
            open={open}
            tone="danger"
            title={t('orders.cancel.title', { number: order.number })}
            body={t('orders.cancel.body')}
            onClose={onClose}
            onSubmit={submit}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('orders.cancel.keep')}
                    </Button>
                    <Button type="submit" variant="danger" disabled={!matches || move.isPending}>
                        {t('orders.cancel.submit')}
                    </Button>
                </>
            }
        >
            <Field label={t('orders.cancel.reason')} error={errors.reason} required>
                {({ id, describedBy, invalid }) => (
                    <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={reason} onChange={(e) => setReason(e.target.value as CancelReason | '')}>
                        <option value="">{t('orders.cancel.reasonPick')}</option>
                        {cancelReasons.map((value) => (
                            <option key={value} value={value}>
                                {t(`orders.cancelReason.${value}` as MessageKey)}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>
            <Field label={t('orders.cancel.note')} error={errors.note} optional>
                {({ id, describedBy, invalid }) => (
                    <textarea id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} rows={2} maxLength={500} className={`py-2 ${inputClass}`} value={note} onChange={(e) => setNote(e.target.value)} />
                )}
            </Field>
            <Field label={t('orders.cancel.type', { number: order.number })} hint={matches ? undefined : t('orders.cancel.noMatch')}>
                {({ id, describedBy }) => (
                    <input
                        id={id}
                        aria-describedby={describedBy}
                        autoComplete="off"
                        spellCheck={false}
                        className={`h-11 ${monoInputClass} ${matches ? 'border-feedback-success' : ''}`}
                        value={typed}
                        onChange={(e) => setTyped(e.target.value)}
                    />
                )}
            </Field>
        </Shell>
    );
}
