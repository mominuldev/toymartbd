import { useState } from 'react';
import { Link } from 'react-router';
import { ArrowUUpLeftIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { Field, inputClass } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { returnReasons, useOpenReturn, useRefundPanel, type RefundPanel, type ReturnReason } from './api';
import { formatMoney, problemText, reasonLabel, refundStatusLabel, refundTone, returnStatusLabel, returnTone } from './parts';

const card = 'flex flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1';

/**
 * The order detail's Returns & refunds card. Not drawn in Figma: it reuses the order
 * detail card recipe (206:1475 — radius/md, border/default, e1, p-5) with the Returns
 * screen's rows. Shows what can still come back per line, captured vs still refundable,
 * the order's returns and refunds, and the "Request a return" form. Every figure is the
 * server's (rule 1); the form only sends quantities, the reason and the delivery choice.
 */
export function OrderRefundPanel({ orderId }: { orderId: number }) {
    const { data: me } = useMe();
    return can(me, 'returns.view') ? <Panel orderId={orderId} /> : null;
}

function Panel({ orderId }: { orderId: number }) {
    const { t } = useI18n();
    const panel = useRefundPanel(orderId);
    const [opening, setOpening] = useState(false);

    if (panel.isError) return <QueryError error={panel.error} onRetry={() => void panel.refetch()} />;
    if (panel.isPending) return null;
    const p = panel.data;

    return (
        <section className={card} aria-labelledby="order-returns">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h2 id="order-returns" className="t-admin-section text-fg-primary">
                        {t('returns.panel.title')}
                    </h2>
                    {p.captured !== null && p.refundable !== null && (
                        <p className="t-admin-caption tabular text-fg-tertiary">{t('returns.panel.money', { captured: formatMoney(p.captured), refundable: formatMoney(p.refundable) })}</p>
                    )}
                </div>
                {p.can.create_return && p.accepts_returns && !opening && (
                    <Button variant="outline" size="sm" onClick={() => setOpening(true)}>
                        <ArrowUUpLeftIcon size={16} aria-hidden />
                        {t('returns.panel.open')}
                    </Button>
                )}
            </div>

            {!p.accepts_returns && p.returns.length === 0 && <p className="rounded-sm bg-subtle px-4 py-3 t-admin-body text-fg-secondary">{t('returns.panel.notDelivered')}</p>}
            {opening && <OpenReturnForm panel={p} onDone={() => setOpening(false)} />}

            {p.returns.length === 0 ? (
                p.accepts_returns && !opening && <p className="t-admin-body text-fg-secondary">{t('returns.panel.none')}</p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {p.returns.map((r) => (
                        <li key={r.id} className="flex flex-wrap items-center gap-3 rounded-sm bg-subtle px-3.5 py-3">
                            <div className="min-w-0 flex-1">
                                <p className="t-admin-mono text-fg-primary">{r.number}</p>
                                <p className="t-admin-caption text-fg-tertiary">
                                    {t(reasonLabel(r.reason))}
                                    {r.refund && ` · ${t('returns.panel.refund', { amount: formatMoney(r.refund.amount), status: t(refundStatusLabel(r.refund.status)) })}`}
                                </p>
                            </div>
                            <StatusChip label={t(returnStatusLabel(r.status))} tone={returnTone[r.status]} />
                            {r.refund && <StatusChip label={t(refundStatusLabel(r.refund.status))} tone={refundTone[r.refund.status]} />}
                            <Link to={`/returns?tab=${r.status}&rma=${r.id}`} className="t-admin-body text-fg-link hover:underline">
                                {t('returns.panel.view')}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function OpenReturnForm({ panel, onDone }: { panel: RefundPanel; onDone: () => void }) {
    const { t, locale } = useI18n();
    const toast = useToast();
    const open = useOpenReturn(panel.order_id);
    const [quantities, setQuantities] = useState<Record<number, number>>({});
    const [reason, setReason] = useState<ReturnReason>('defective');
    const [note, setNote] = useState('');
    const [shipping, setShipping] = useState(false);
    const [error, setError] = useState<string>();
    const lines = panel.lines.filter((l) => l.returnable > 0);

    const submit = () => {
        const items = Object.entries(quantities)
            .filter(([, q]) => q > 0)
            .map(([id, quantity]) => ({ order_item_id: Number(id), quantity }));
        if (items.length === 0) {
            setError(t('returns.panel.noLines'));
            return;
        }
        setError(undefined);
        open.mutate(
            { items, reason, reason_note: note.trim() || null, refund_shipping: shipping },
            {
                onSuccess: (created) => {
                    toast.notify(t('returns.toast.opened', { number: created.number }));
                    onDone();
                },
                onError: (e) => setError(problemText(e, t('error.title'))),
            },
        );
    };

    return (
        <div className="flex flex-col gap-3 rounded-sm border border-line-default px-4 py-3.5">
            <ul className="flex flex-col gap-2">
                {lines.map((l) => {
                    const name = locale === 'bn' && l.product_name_bn ? l.product_name_bn : l.product_name;
                    return (
                        <li key={l.order_item_id} className="grid grid-cols-[minmax(0,1fr)_6rem] items-center gap-3">
                            <div className="min-w-0">
                                <p className="truncate t-admin-body-strong text-fg-primary">{name}</p>
                                <p className="t-admin-caption tabular text-fg-tertiary">{t('returns.panel.returnable', { returnable: l.returnable, quantity: l.quantity })}</p>
                            </div>
                            <Input
                                type="number"
                                min={0}
                                max={l.returnable}
                                inputMode="numeric"
                                aria-label={t('returns.panel.quantityFor', { name })}
                                value={quantities[l.order_item_id] ?? 0}
                                onChange={(e) => setQuantities((s) => ({ ...s, [l.order_item_id]: Math.max(0, Math.min(l.returnable, Number(e.target.value) || 0)) }))}
                            />
                        </li>
                    );
                })}
            </ul>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field label={t('returns.panel.reason')} required>
                    {({ id }) => (
                        <Select id={id} value={reason} onChange={(e) => setReason(e.target.value as ReturnReason)}>
                            {returnReasons.map((r) => (
                                <option key={r} value={r}>
                                    {t(reasonLabel(r))}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
                <Field label={t('returns.panel.reasonNote')} hint={t('returns.panel.reasonNoteHint')} optional={reason !== 'other'} required={reason === 'other'}>
                    {({ id, describedBy }) => <textarea id={id} aria-describedby={describedBy} rows={1} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} className={`${inputClass} min-h-10 py-2`} />}
                </Field>
            </div>
            {panel.shipping_claimed ? (
                <p className="t-admin-caption text-fg-secondary">{t('returns.panel.shippingClaimed')}</p>
            ) : (
                <label className="inline-flex items-center gap-2 t-admin-body text-fg-primary">
                    <input type="checkbox" checked={shipping} onChange={(e) => setShipping(e.target.checked)} className="size-4 accent-brand" />
                    {t('returns.panel.refundShipping')}
                </label>
            )}
            {error && (
                <p role="alert" className="t-admin-caption text-fg-primary">
                    {error}
                </p>
            )}
            <div className="flex flex-wrap gap-2">
                <Button disabled={open.isPending} onClick={submit}>
                    {t('returns.panel.submit')}
                </Button>
                <Button variant="ghost" onClick={onDone}>
                    {t('common.cancel')}
                </Button>
            </div>
        </div>
    );
}
