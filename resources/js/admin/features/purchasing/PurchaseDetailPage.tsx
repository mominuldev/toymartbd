import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { PaperPlaneTiltIcon, PencilSimpleIcon, PrinterIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { openPdf } from '@/lib/pdf';
import { useI18n } from '@/i18n/I18nProvider';
import { formatDhakaDate } from '@/lib/format';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { dataTable } from '@/components/ui/DataTable';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { usePurchase, usePurchaseMove, type Purchase } from './api';
import { CloseShortDialog } from './CloseShortDialog';
import { CellLabel, LineProduct, PurchaseStatusChip, Taka, Th, TotalRow, formatDay, formatUnits } from './parts';
import { PaymentDialog } from './PaymentDialog';
import { ReceivePanel } from './ReceivePanel';

/**
 * One purchase order (06-admin-feature-checklist §2.7 "PO detail with receive-stock flow,
 * supplier balance"; drawn only as the list's receive card, 213:8325 — the detail reuses its
 * pieces): lines with ordered / received / outstanding and the server's unit and landed
 * costs, the totals panel, receipts, and the lifecycle actions the server says are allowed
 * now (`can`).
 */
export function PurchaseDetailPage() {
    const params = useParams();
    const id = Number(params.purchaseId);
    const purchase = usePurchase(Number.isFinite(id) ? id : null);

    if (purchase.isError) return <QueryError error={purchase.error} onRetry={() => void purchase.refetch()} />;
    if (!purchase.data) return <p aria-busy="true" className="sr-only" />;

    return <PurchaseDetail purchase={purchase.data} />;
}

function PurchaseDetail({ purchase }: { purchase: Purchase }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const move = usePurchaseMove(purchase.id);
    const [confirmCancel, setConfirmCancel] = useState(false);
    const [paying, setPaying] = useState(false);
    const [closing, setClosing] = useState(false);
    const viewCost = can(me, 'catalog.view_cost');
    const canCreate = can(me, 'purchasing.create');

    const run = (action: 'order' | 'cancel') =>
        move.mutate(action, {
            onSuccess: (po) => {
                notify(t(action === 'order' ? 'po.placed' : 'po.cancelled', { number: po.number }));
                setConfirmCancel(false);
            },
            onError: (error) => notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger'),
        });

    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;
    const num = `${cell} t-admin-num tabular whitespace-nowrap lg:text-end`;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('catalog.crumb') }, { label: t('purchasing.crumb'), to: '/purchasing' }, { label: purchase.number }]}
                title={purchase.number}
                subtitle={
                    <span className="flex flex-wrap items-center gap-2">
                        <PurchaseStatusChip status={purchase.status} />
                        <Link to={`/purchasing/suppliers/${purchase.supplier.id}`} className="underline-offset-2 hover:underline">
                            {purchase.status === 'draft'
                                ? t('po.detail.draftSubtitle', { supplier: purchase.supplier.name })
                                : t('po.detail.subtitle', { supplier: purchase.supplier.name, date: formatDay(purchase.order_date, locale, true) })}
                        </Link>
                    </span>
                }
                actions={
                    <>
                        {canCreate && purchase.can.cancel && (
                            <Button variant="ghost" onClick={() => setConfirmCancel(true)}>
                                {t('po.cancel')}
                            </Button>
                        )}
                        {canCreate && purchase.can.close && (
                            <Button variant="outline" onClick={() => setClosing(true)}>
                                {t('po.close')}
                            </Button>
                        )}
                        {/* P8 supplier invoice (PINV-…): issued on the first print, reprinted from its snapshot. */}
                        {purchase.status !== 'draft' && purchase.status !== 'cancelled' && can(me, 'catalog.view_cost') && <PrintInvoiceButton purchaseId={purchase.id} />}
                        {canCreate && purchase.can.edit && (
                            <Link to={`/purchasing/${purchase.id}/edit`} className={buttonClass('outline')}>
                                <PencilSimpleIcon size={16} aria-hidden />
                                {t('common.edit')}
                            </Link>
                        )}
                        {can(me, 'finance.record_supplier_payments') && purchase.status !== 'draft' && purchase.status !== 'cancelled' && (
                            <Button variant="outline" onClick={() => setPaying(true)}>
                                {t('po.recordPayment')}
                            </Button>
                        )}
                        {canCreate && purchase.can.place && (
                            <Button onClick={() => run('order')} disabled={move.isPending}>
                                <PaperPlaneTiltIcon size={16} aria-hidden />
                                {t('po.place')}
                            </Button>
                        )}
                    </>
                }
            />

            {purchase.status === 'closed_short' && purchase.closed_at && (
                <p className="border-l-3 border-feedback-warning bg-subtle px-3 py-2.5 t-admin-body text-fg-primary">
                    {t('po.closedNote', { date: formatDhakaDate(purchase.closed_at, locale), reason: purchase.close_reason ?? '—' })}
                </p>
            )}

            <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_27.5rem]">
                <div className="flex min-w-0 flex-col gap-6">
                    <div className={dataTable.card}>
                        <table className={dataTable.table}>
                            <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                                <tr className="border-b border-line-strong">
                                    <Th>{t('po.col.product')}</Th>
                                    <Th end>{t('po.col.ordered')}</Th>
                                    <Th end>{t('po.col.received')}</Th>
                                    <Th end>{t('po.col.outstanding')}</Th>
                                    {viewCost && <Th end>{t('po.col.unitCost')}</Th>}
                                    {viewCost && <Th end>{t('po.col.landed')}</Th>}
                                    {viewCost && <Th end>{t('po.col.lineTotal')}</Th>}
                                </tr>
                            </thead>
                            <tbody>
                                {purchase.items.map((line) => (
                                    <tr key={line.id} className={`flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row}`}>
                                        <td className={`${cell} basis-full lg:basis-auto`}>
                                            <LineProduct line={line} />
                                        </td>
                                        <td className={num}>
                                            <CellLabel text={t('po.col.ordered')} />
                                            {formatUnits(line.quantity_ordered)}
                                        </td>
                                        <td className={num}>
                                            <CellLabel text={t('po.col.received')} />
                                            {formatUnits(line.quantity_received)}
                                        </td>
                                        <td className={`${num} text-fg-secondary`}>
                                            <CellLabel text={t('po.col.outstanding')} />
                                            {formatUnits(line.quantity_outstanding)}
                                        </td>
                                        {viewCost && (
                                            <td className={num}>
                                                <CellLabel text={t('po.col.unitCost')} />
                                                {line.unit_cost !== undefined && <Taka amount={line.unit_cost} />}
                                            </td>
                                        )}
                                        {viewCost && (
                                            <td className={num}>
                                                <CellLabel text={t('po.col.landed')} />
                                                {line.landed_unit_cost ? <Taka amount={line.landed_unit_cost} /> : '—'}
                                            </td>
                                        )}
                                        {viewCost && (
                                            <td className={num}>
                                                <CellLabel text={t('po.col.lineTotal')} />
                                                {line.line_total !== undefined && <Taka amount={line.line_total} />}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <section aria-labelledby="po-receipts" className="flex flex-col gap-2 rounded-md border border-line-default bg-surface p-5 shadow-e1">
                        <h2 id="po-receipts" className="t-admin-card-title text-fg-primary">
                            {t('po.receipts')}
                        </h2>
                        {purchase.receipts.length === 0 ? (
                            <p className="t-admin-body text-fg-tertiary">{t('po.noReceipts')}</p>
                        ) : (
                            <ul className="flex flex-col">
                                {purchase.receipts.map((receipt) => (
                                    <li key={receipt.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-line-default py-2 first:border-t-0">
                                        <span className="t-admin-mono text-fg-primary">{receipt.number}</span>
                                        <span className="flex-1 t-admin-caption text-fg-secondary">
                                            {t('po.receiptLine', {
                                                units: formatUnits(receipt.items.reduce((sum, i) => sum + i.quantity, 0)),
                                                date: formatDhakaDate(receipt.received_at, locale),
                                            })}
                                        </span>
                                        {viewCost && receipt.payable_amount !== undefined && (
                                            <span className="t-admin-num tabular">
                                                <Taka amount={receipt.payable_amount} />
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                <div className="flex min-w-0 flex-col gap-6">
                    {viewCost && purchase.total !== undefined && (
                        <section aria-labelledby="po-totals" className="flex flex-col rounded-md border border-line-default bg-surface p-5 shadow-e1">
                            <h2 id="po-totals" className="pb-2 t-admin-card-title text-fg-primary">
                                {t('po.totals')}
                            </h2>
                            <TotalRow label={t('po.subtotal')} amount={purchase.subtotal ?? '0.00'} />
                            {purchase.discount && purchase.discount !== '0.00' && <TotalRow label={t('po.discountTotal')} amount={`-${purchase.discount}`} />}
                            {purchase.shipping_cost && purchase.shipping_cost !== '0.00' && <TotalRow label={t('po.freight')} amount={purchase.shipping_cost} />}
                            {purchase.customs_duty && purchase.customs_duty !== '0.00' && <TotalRow label={t('po.customs')} amount={purchase.customs_duty} />}
                            {purchase.other_cost && purchase.other_cost !== '0.00' && <TotalRow label={t('po.other')} amount={purchase.other_cost} />}
                            <TotalRow strong label={t('po.total')} amount={purchase.total} />
                            <TotalRow label={t('po.receivedValue')} amount={purchase.received_value ?? '0.00'} />
                            <TotalRow label={t('po.paid')} amount={purchase.paid_amount ?? '0.00'} />
                            <TotalRow strong label={t('po.due')} amount={purchase.due_amount ?? '0.00'} />
                        </section>
                    )}
                    {purchase.can.receive && can(me, 'purchasing.receive') && <ReceivePanel purchase={purchase} />}
                </div>
            </div>

            <ConfirmDialog
                open={confirmCancel}
                title={t('po.cancelTitle', { number: purchase.number })}
                body={t('po.cancelBody')}
                confirmLabel={t('po.cancel')}
                pending={move.isPending}
                onConfirm={() => run('cancel')}
                onClose={() => setConfirmCancel(false)}
            />
            <CloseShortDialog open={closing} onClose={() => setClosing(false)} purchase={purchase} />
            <PaymentDialog open={paying} onClose={() => setPaying(false)} supplier={purchase.supplier} purchaseId={purchase.id} purchases={[{ id: purchase.id, number: purchase.number }]} />
        </section>
    );
}

/** Opens the PO's supplier invoice PDF (`GET /purchases/{id}/invoice`). */
function PrintInvoiceButton({ purchaseId }: { purchaseId: number }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const [printing, setPrinting] = useState(false);
    return (
        <Button
            variant="outline"
            disabled={printing}
            onClick={() => {
                setPrinting(true);
                openPdf(`/purchases/${purchaseId}/invoice`)
                    .catch((error: unknown) => notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('invoices.printFailed'), 'danger'))
                    .finally(() => setPrinting(false));
            }}
        >
            <PrinterIcon size={16} aria-hidden />
            {printing ? t('invoices.printing') : t('invoices.printPurchase')}
        </Button>
    );
}
