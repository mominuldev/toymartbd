import { Fragment, useState } from 'react';
import { Link, useParams } from 'react-router';
import { CheckIcon, DownloadSimpleIcon, InfoIcon, PaperPlaneTiltIcon, PencilSimpleIcon, WalletIcon, WarningIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { openPdf } from '@/lib/pdf';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { formatDhakaDate } from '@/lib/format';
import { can, useMe } from '@/features/auth/api';
import { useWorkingBranch } from '@/components/shell/workingBranch';
import { ActionMenu, type ActionMenuItem } from '@/components/ui/ActionMenu';
import { Button, buttonClass } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { dataTable } from '@/components/ui/DataTable';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { usePurchase, usePurchaseMove, useSupplier, type Purchase, type PurchaseLine, type PurchaseReceipt } from './api';
import { CloseShortDialog } from './CloseShortDialog';
import { CellLabel, LineProduct, Money, PurchaseStatusChip, ReceivedBar, Th, formatDay, formatUnits, isZeroAmount, lineName, supplierTerms, takaText } from './parts';
import { PaymentDialog } from './PaymentDialog';
import { ReceiveDrawer } from './ReceiveDrawer';

/**
 * One purchase order (Figma "Purchasing · Premium" PO page 415:70452): title + status, ONE
 * primary action for the state the server reports (draft → Place order; waiting / partly
 * received → Receive stock; received with something owed → Record payment), the rest behind
 * ⋯ (Print invoice, Close short, Cancel). A progress stepper Draft → Ordered → Receiving →
 * Received → Paid read off the PO's status and dates; the items with received progress in
 * units; the deliveries received; and a rail with "What you owe" and the supplier. Receiving
 * happens in the Receive stock drawer only. Every taka figure is the server's (rule 1).
 */
export function PurchaseDetailPage() {
    const params = useParams();
    const id = Number(params.purchaseId);
    const purchase = usePurchase(Number.isFinite(id) ? id : null);

    if (purchase.isError) return <QueryError error={purchase.error} onRetry={() => void purchase.refetch()} />;
    if (!purchase.data) return <p aria-busy="true" className="sr-only" />;

    return <PurchaseDetail purchase={purchase.data} />;
}

/** A positive server amount — a display decision on its string, never arithmetic. */
function owes(amount: string | undefined): boolean {
    return amount !== undefined && !amount.startsWith('-') && !isZeroAmount(amount);
}

function PurchaseDetail({ purchase }: { purchase: Purchase }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const branch = useWorkingBranch();
    const move = usePurchaseMove(purchase.id);
    const [confirmCancel, setConfirmCancel] = useState(false);
    const [paying, setPaying] = useState(false);
    const [closing, setClosing] = useState(false);
    const [receiving, setReceiving] = useState(false);
    const viewCost = can(me, 'catalog.view_cost');
    const canCreate = can(me, 'purchasing.create');
    const canReceive = can(me, 'purchasing.receive');
    const canPay = can(me, 'finance.record_supplier_payments') && purchase.status !== 'draft' && purchase.status !== 'cancelled';
    const finished = purchase.status === 'received' || purchase.status === 'closed_short';

    const locationName = (id: number) => {
        const here = branch.locations.find((l) => l.id === id);
        return here ? (locale === 'bn' ? (here.name_bn ?? here.name) : here.name) : '—';
    };

    const run = (action: 'order' | 'cancel') =>
        move.mutate(action, {
            onSuccess: (po) => {
                notify(t(action === 'order' ? 'po.placed' : 'po.cancelled', { number: po.number }));
                setConfirmCancel(false);
            },
            onError: (error) => notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger'),
        });

    const print = () =>
        openPdf(`/purchases/${purchase.id}/invoice`).catch((error: unknown) => notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('invoices.printFailed'), 'danger'));

    // One primary action, chosen by the state the server reports (`can`, status, due).
    const primary =
        canCreate && purchase.can.place ? (
            <Button onClick={() => run('order')} disabled={move.isPending}>
                <PaperPlaneTiltIcon size={16} aria-hidden />
                {t('po.place')}
            </Button>
        ) : canReceive && purchase.can.receive ? (
            <Button onClick={() => setReceiving(true)}>
                <DownloadSimpleIcon size={16} aria-hidden />
                {t('po.receive')}
            </Button>
        ) : canPay && finished && owes(purchase.due_amount) ? (
            <Button onClick={() => setPaying(true)}>
                <WalletIcon size={16} aria-hidden />
                {t('po.recordPayment')}
            </Button>
        ) : null;
    const primaryIsPay = canPay && finished && owes(purchase.due_amount) && !purchase.can.place && !purchase.can.receive;
    // The rail's "What you owe" card holds Record payment; without cost rights it is not shown, so ⋯ carries it.
    const railShowsPay = viewCost && canPay && !primaryIsPay;

    const more: ActionMenuItem[] = [];
    if (viewCost && purchase.status !== 'draft' && purchase.status !== 'cancelled') more.push({ key: 'print', label: t('po.printInvoice'), onSelect: () => void print() });
    if (canPay && !viewCost && !primaryIsPay) more.push({ key: 'pay', label: t('po.recordPayment'), onSelect: () => setPaying(true) });
    if (canCreate && purchase.can.close) more.push({ key: 'close', label: t('po.close'), onSelect: () => setClosing(true) });
    if (canCreate && purchase.can.cancel) more.push({ key: 'cancel', label: t('po.cancel'), onSelect: () => setConfirmCancel(true) });

    const meta = [
        purchase.supplier.name,
        purchase.ordered_at ? t('po.meta.ordered', { date: formatDhakaDate(purchase.ordered_at, locale) }) : t('po.meta.saved', { date: formatDhakaDate(purchase.created_at, locale) }),
        t('po.meta.deliver', { location: locationName(purchase.location_id) }),
        purchase.expected_date ? t('po.meta.expected', { date: formatDay(purchase.expected_date, locale) }) : null,
    ].filter(Boolean);

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('catalog.crumb') }, { label: t('purchasing.crumb'), to: '/purchasing' }, { label: purchase.number }]}
                title={purchase.number}
                titleAddon={
                    <span className="flex flex-wrap items-center gap-2">
                        <PurchaseStatusChip status={purchase.status} />
                    </span>
                }
                subtitle={meta.join(' · ')}
                actions={
                    <>
                        {canCreate && purchase.can.edit && (
                            <Link to={`/purchasing/${purchase.id}/edit`} className={buttonClass('outline')}>
                                <PencilSimpleIcon size={16} aria-hidden />
                                {t('common.edit')}
                            </Link>
                        )}
                        {primary}
                        <ActionMenu variant="outline" size="md" label={t('po.more', { number: purchase.number })} items={more} />
                    </>
                }
            />

            {purchase.status === 'closed_short' && purchase.closed_at && (
                <p className="flex items-start gap-2 rounded-sm bg-warning-subtle px-3 py-2.5 t-admin-body text-fg-primary">
                    <WarningIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-feedback-warning" />
                    {t('po.closedNote', { date: formatDhakaDate(purchase.closed_at, locale), reason: purchase.close_reason ?? '—' })}
                </p>
            )}
            {purchase.status === 'cancelled' && (
                <p className="flex items-start gap-2 rounded-sm bg-danger-subtle px-3 py-2.5 t-admin-body text-fg-primary">
                    <InfoIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-feedback-danger" />
                    {t('po.cancelledNote')}
                </p>
            )}

            {purchase.status !== 'cancelled' && <Progress purchase={purchase} viewCost={viewCost} />}

            <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22.5rem]">
                <div className="flex min-w-0 flex-col gap-6">
                    <Items purchase={purchase} viewCost={viewCost} />
                    <Deliveries purchase={purchase} viewCost={viewCost} locationName={locationName} />
                </div>
                <div className="flex min-w-0 flex-col gap-6">
                    {viewCost && purchase.total !== undefined && <Owe purchase={purchase} onPay={railShowsPay ? () => setPaying(true) : null} />}
                    <SupplierCard purchase={purchase} />
                </div>
            </div>

            <ReceiveDrawer purchase={purchase} open={receiving} onClose={() => setReceiving(false)} />
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

type StepState = 'done' | 'current' | 'upcoming';
type Step = { key: string; label: MessageKey; state: StepState; sub: string };

/**
 * Draft → Ordered → Receiving → Received → Paid, laid out from the PO's own status, dates,
 * units and the server's `is_paid` / `due_amount` — nothing is inferred about money.
 */
function buildSteps(purchase: Purchase, viewCost: boolean, t: ReturnType<typeof useI18n>['t'], locale: string): Step[] {
    const s = purchase.status;
    const finished = s === 'received' || s === 'closed_short';
    const receiving = s === 'ordered' || s === 'partially_received';
    const units = { received: formatUnits(purchase.units_received), ordered: formatUnits(purchase.units_ordered) };
    const paid = finished && purchase.is_paid === true;
    return [
        { key: 'draft', label: 'po.step.draft', state: s === 'draft' ? 'current' : 'done', sub: t('po.step.saved', { date: formatDhakaDate(purchase.created_at, locale) }) },
        {
            key: 'ordered',
            label: 'po.step.ordered',
            state: s === 'draft' ? 'upcoming' : 'done',
            sub: purchase.ordered_at ? t('po.step.sent', { date: formatDhakaDate(purchase.ordered_at, locale) }) : t('po.step.notSent'),
        },
        { key: 'receiving', label: 'po.step.receiving', state: receiving ? 'current' : finished ? 'done' : 'upcoming', sub: t('po.step.unitsIn', units) },
        {
            key: 'received',
            label: s === 'closed_short' ? 'purchasing.status.closed_short' : 'po.step.received',
            state: finished ? 'done' : 'upcoming',
            sub: s === 'received' ? t('po.step.allIn') : finished ? t('po.step.unitsIn', units) : t('po.step.upcoming'),
        },
        {
            key: 'paid',
            label: 'po.step.paid',
            state: paid ? 'done' : finished ? 'current' : 'upcoming',
            sub: paid ? t('po.step.paidOff') : viewCost && owes(purchase.due_amount) ? t('po.step.toPay', { amount: takaText(purchase.due_amount ?? '0') }) : t('po.step.upcoming'),
        },
    ];
}

function Progress({ purchase, viewCost }: { purchase: Purchase; viewCost: boolean }) {
    const { t, locale } = useI18n();
    const steps = buildSteps(purchase, viewCost, t, locale);
    return (
        <section aria-label={t('po.progress')} className="rounded-md border border-line-default bg-surface px-5 py-5 shadow-e1">
            <ol className="flex flex-wrap gap-x-8 gap-y-4 xl:flex-nowrap xl:items-center xl:gap-3">
                {steps.map((step, index) => (
                    <Fragment key={step.key}>
                        {index > 0 && <li aria-hidden className={`hidden h-0.5 min-w-6 flex-1 rounded-full xl:block ${step.state === 'upcoming' ? 'bg-line-default' : 'bg-brand'}`} />}
                        <li className="flex shrink-0 items-center gap-3" aria-current={step.state === 'current' ? 'step' : undefined}>
                            <span
                                aria-hidden
                                className={`grid size-6 shrink-0 place-items-center rounded-full t-admin-caption-strong ${
                                    step.state === 'done'
                                        ? 'bg-brand text-fg-on-brand'
                                        : step.state === 'current'
                                          ? 'border-2 border-line-brand text-fg-brand'
                                          : 'bg-subtle text-fg-secondary'
                                }`}
                            >
                                {step.state === 'done' ? <CheckIcon size={14} weight="bold" /> : index + 1}
                            </span>
                            <span className="flex flex-col">
                                <span className={step.state === 'upcoming' ? 't-admin-body text-fg-secondary' : 't-admin-body-strong text-fg-primary'}>
                                    {t(step.label)}
                                    <span className="sr-only"> — {t(`po.step.${step.state}` as MessageKey)}</span>
                                </span>
                                <span className="t-admin-caption tabular text-fg-secondary">{step.sub}</span>
                            </span>
                        </li>
                    </Fragment>
                ))}
            </ol>
        </section>
    );
}

function lineProgress(line: PurchaseLine, t: ReturnType<typeof useI18n>['t']): string {
    return line.quantity_outstanding > 0
        ? t('po.inToCome', { in: formatUnits(line.quantity_received), left: formatUnits(line.quantity_outstanding) })
        : t('po.inAll', { in: formatUnits(line.quantity_received) });
}

function Items({ purchase, viewCost }: { purchase: Purchase; viewCost: boolean }) {
    const { t } = useI18n();
    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;
    const num = `${cell} t-admin-num tabular whitespace-nowrap lg:text-end`;
    const draft = purchase.status === 'draft';
    return (
        <section aria-labelledby="po-items" className={dataTable.card}>
            <div className="flex flex-wrap items-center justify-between gap-2 px-5 py-4">
                <h2 id="po-items" className="t-admin-card-title text-fg-primary">
                    {t('po.items')}
                </h2>
                <p className="t-admin-caption tabular text-fg-secondary">{t('po.itemsCount', { products: formatUnits(purchase.items.length), units: formatUnits(purchase.units_ordered) })}</p>
            </div>
            <table className={`${dataTable.table} lg:table-fixed max-lg:block`}>
                <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                    <tr className="border-b border-line-strong">
                        <Th className="ps-5">{t('po.col.product')}</Th>
                        <Th end className="w-24">
                            {t('po.col.ordered')}
                        </Th>
                        {!draft && <Th className="w-40">{t('po.col.received')}</Th>}
                        {viewCost && (
                            <Th end className="w-28">
                                {t('po.col.unitCost')}
                            </Th>
                        )}
                        {viewCost && (
                            <Th end className="w-32 pe-5">
                                {t('po.col.lineTotal')}
                            </Th>
                        )}
                    </tr>
                </thead>
                <tbody className="max-lg:block">
                    {purchase.items.map((line) => (
                        <tr key={line.id} className={`flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 max-lg:border-t max-lg:border-line-default lg:table-row lg:p-0 ${dataTable.row} hover:bg-transparent`}>
                            <td className={`${cell} min-w-0 basis-full overflow-hidden lg:basis-auto lg:ps-5`}>
                                <LineProduct line={line} />
                            </td>
                            <td className={`${num} t-admin-body-strong`}>
                                <CellLabel text={t('po.col.ordered')} />
                                {formatUnits(line.quantity_ordered)}
                            </td>
                            {!draft && (
                                <td className={cell}>
                                    <CellLabel text={t('po.col.received')} />
                                    <span className="inline-flex w-33 flex-col gap-1.5 align-middle">
                                        <span className="t-admin-caption tabular text-fg-primary">{lineProgress(line, t)}</span>
                                        <ReceivedBar
                                            received={line.quantity_received}
                                            ordered={line.quantity_ordered}
                                            label={t('purchasing.progressLabel', { received: formatUnits(line.quantity_received), ordered: formatUnits(line.quantity_ordered) })}
                                        />
                                    </span>
                                </td>
                            )}
                            {viewCost && (
                                <td className={`${num} text-fg-secondary`}>
                                    <CellLabel text={t('po.col.unitCost')} />
                                    {line.unit_cost !== undefined && <Money amount={line.unit_cost} />}
                                </td>
                            )}
                            {viewCost && (
                                <td className={`${num} t-admin-body-strong lg:pe-5`}>
                                    <CellLabel text={t('po.col.lineTotal')} />
                                    {line.line_total !== undefined && <Money amount={line.line_total} />}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
            {viewCost && purchase.total !== undefined && (
                <div className="flex flex-col gap-2 border-t border-line-default px-5 py-4">
                    <div className="flex items-center justify-between gap-3">
                        <span className="t-admin-body-strong text-fg-primary">{t('po.orderTotal')}</span>
                        <span className="t-admin-body-strong tabular text-fg-primary">
                            <Money amount={purchase.total} />
                        </span>
                    </div>
                    <ExtraCostRows purchase={purchase} />
                </div>
            )}
        </section>
    );
}

function Deliveries({ purchase, viewCost, locationName }: { purchase: Purchase; viewCost: boolean; locationName: (id: number) => string }) {
    const { t } = useI18n();
    if (purchase.status === 'draft' || purchase.status === 'cancelled') return null;
    return (
        <section aria-labelledby="po-deliveries" className="flex flex-col gap-3 rounded-md border border-line-default bg-surface p-5 shadow-e1">
            <h2 id="po-deliveries" className="t-admin-card-title text-fg-primary">
                {t('po.deliveries')}
            </h2>
            {purchase.receipts.length === 0 ? (
                <p className="t-admin-body text-fg-secondary">{t('po.noDeliveries')}</p>
            ) : (
                <ul className="flex flex-col gap-4">
                    {purchase.receipts.map((receipt) => (
                        <Delivery key={receipt.id} receipt={receipt} purchase={purchase} viewCost={viewCost} locationName={locationName} />
                    ))}
                </ul>
            )}
        </section>
    );
}

function Delivery({ receipt, purchase, viewCost, locationName }: { receipt: PurchaseReceipt; purchase: Purchase; viewCost: boolean; locationName: (id: number) => string }) {
    const { t, locale } = useI18n();
    const [open, setOpen] = useState(false);
    // Units, not money.
    const units = receipt.items.reduce((sum, i) => sum + i.quantity, 0);
    const parts: string[] = [];
    if (viewCost && receipt.goods_value !== undefined) {
        parts.push(t('po.delivery.goods', { amount: takaText(receipt.goods_value) }));
        if (receipt.freight && !isZeroAmount(receipt.freight)) parts.push(t('po.delivery.freight', { amount: takaText(receipt.freight) }));
        if (receipt.customs_duty && !isZeroAmount(receipt.customs_duty)) parts.push(t('po.delivery.customs', { amount: takaText(receipt.customs_duty) }));
        if (receipt.other_cost && !isZeroAmount(receipt.other_cost)) parts.push(t('po.delivery.other', { amount: takaText(receipt.other_cost) }));
    }
    const hasCosts = parts.length > 1;
    let body = [receipt.number, parts.join(' + ')].filter(Boolean).join(' · ');
    if (hasCosts) body += ` ${t(`po.delivery.${receipt.apportion_method}` as MessageKey)}`;
    if (viewCost && receipt.payable_amount !== undefined) {
        body += ` = ${t('po.delivery.owe', { amount: takaText(receipt.payable_amount) })}`;
        if (hasCosts && !receipt.costs_paid_to_supplier) body += ` · ${t('po.delivery.paidYourself')}`;
    }
    const detailsId = `delivery-${receipt.id}`;
    const byItem = new Map(purchase.items.map((l) => [l.id, l]));

    return (
        <li className="flex items-start gap-3">
            <span aria-hidden className="grid size-7 shrink-0 place-items-center rounded-full bg-success-subtle text-feedback-success">
                <DownloadSimpleIcon size={14} />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="t-admin-body-strong text-fg-primary">
                    {t('po.delivery.title', { date: formatDhakaDate(receipt.received_at, locale), units: formatUnits(units), location: locationName(receipt.location_id) })}
                </p>
                <p className="t-admin-caption tabular text-fg-secondary">{body}</p>
                {open && (
                    <ul id={detailsId} className="flex flex-col gap-0.5 pt-1">
                        {receipt.items.map((item) => {
                            const line = byItem.get(item.purchase_item_id);
                            return (
                                <li key={item.purchase_item_id} className="t-admin-caption tabular text-fg-secondary">
                                    {t('po.delivery.line', {
                                        name: line ? lineName(line, locale) : '—',
                                        units: formatUnits(item.quantity),
                                        cost: item.landed_unit_cost ? takaText(item.landed_unit_cost) : '—',
                                    })}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
            <Button variant="ghost" size="sm" aria-expanded={open} aria-controls={detailsId} onClick={() => setOpen((o) => !o)}>
                {open ? t('po.delivery.hide') : t('po.delivery.details')}
            </Button>
        </li>
    );
}

/**
 * Extra costs entered at receipt, from the server's `extra_costs` (summed from the receipts):
 * one line per kind that is not zero, and a note for the part paid to someone else — never
 * part of what the supplier is owed (owner decision 2026-11-16). Nothing is added up here.
 */
function ExtraCostRows({ purchase }: { purchase: Purchase }) {
    const { t } = useI18n();
    const extras = purchase.extra_costs;
    if (!extras || isZeroAmount(extras.total)) return null;
    const rows: [MessageKey, string][] = [
        ['po.extra.freight', extras.freight],
        ['po.extra.customs', extras.customs_duty],
        ['po.extra.other', extras.other],
    ];
    return (
        <div className="flex flex-col">
            {rows
                .filter(([, amount]) => !isZeroAmount(amount))
                .map(([label, amount]) => (
                    <OweRow key={label} label={t(label)} amount={amount} />
                ))}
            {!isZeroAmount(extras.paid_elsewhere) && (
                <p className="pb-1.5 t-admin-caption text-fg-secondary">{t('po.extra.elsewhere', { amount: takaText(extras.paid_elsewhere) })}</p>
            )}
        </div>
    );
}

function OweRow({ label, amount }: { label: string; amount: string }) {
    return (
        <div className="flex items-center justify-between gap-3 py-1.5">
            <span className="t-admin-body text-fg-secondary">{label}</span>
            <span className="t-admin-body-strong tabular text-fg-primary">
                <Money amount={amount} />
            </span>
        </div>
    );
}

function Owe({ purchase, onPay }: { purchase: Purchase; onPay: (() => void) | null }) {
    const { t, locale } = useI18n();
    const hint =
        purchase.status === 'draft'
            ? t('po.owe.hintDraft')
            : purchase.is_paid
              ? t('po.owe.hintPaid')
              : purchase.payment_due_date
                ? t('po.owe.hintDue', { terms: supplierTerms(purchase.supplier.payment_terms_days, t), date: formatDay(purchase.payment_due_date, locale) })
                : purchase.receipts.length === 0
                  ? t('po.owe.hintNothing')
                  : null;
    return (
        <section aria-labelledby="po-owe" className="flex flex-col rounded-md border border-line-default bg-surface p-5 shadow-e1">
            <h2 id="po-owe" className="pb-2 t-admin-card-title text-fg-primary">
                {t('po.owe.title')}
            </h2>
            <OweRow label={t('po.owe.total')} amount={purchase.total ?? '0.00'} />
            <ExtraCostRows purchase={purchase} />
            <OweRow label={t('po.owe.received')} amount={purchase.received_goods ?? '0.00'} />
            <OweRow label={t('po.owe.paid')} amount={purchase.paid_amount ?? '0.00'} />
            <div className="mt-2 flex items-center justify-between gap-3 border-t border-line-strong pt-3">
                <span className="t-admin-body-strong text-fg-primary">{t('po.owe.still')}</span>
                <span className="t-admin-currency-num-lg tabular text-fg-primary">
                    <Money amount={purchase.due_amount ?? '0.00'} signClass="t-admin-currency-num-lg" />
                </span>
            </div>
            {hint && <p className="pt-3 t-admin-caption text-fg-secondary">{hint}</p>}
            {onPay && purchase.status !== 'draft' && purchase.status !== 'cancelled' && (
                <Button variant="outline" className="mt-4 w-full" onClick={onPay}>
                    <WalletIcon size={16} aria-hidden />
                    {t('po.recordPayment')}
                </Button>
            )}
        </section>
    );
}

function SupplierCard({ purchase }: { purchase: Purchase }) {
    const { t } = useI18n();
    const supplier = useSupplier(purchase.supplier.id);
    const data = supplier.data;
    const contact = [data?.contact_person, data?.phone].filter(Boolean).join(' · ');
    return (
        <section aria-labelledby="po-supplier" className="flex flex-col gap-2 rounded-md border border-line-default bg-surface p-5 shadow-e1">
            <h2 id="po-supplier" className="t-admin-card-title text-fg-primary">
                {t('po.supplier.title')}
            </h2>
            <p className="t-admin-body-strong text-fg-primary">{purchase.supplier.name}</p>
            {contact && <p className="t-admin-caption text-fg-secondary">{contact}</p>}
            <div className="flex items-center justify-between gap-3 pt-1">
                <span className="t-admin-body text-fg-secondary">{t('po.supplier.terms')}</span>
                <span className="t-admin-body-strong text-fg-primary">{supplierTerms(purchase.supplier.payment_terms_days, t)}</span>
            </div>
            {data?.balance && (
                <div className="flex items-center justify-between gap-3">
                    <span className="t-admin-body text-fg-secondary">{t('po.supplier.owed')}</span>
                    <span className="t-admin-body-strong tabular text-fg-primary">
                        <Money amount={data.balance.balance} />
                    </span>
                </div>
            )}
            <Link to={`/purchasing/suppliers/${purchase.supplier.id}`} className={`${buttonClass('ghost', 'sm')} self-start`}>
                {t('po.supplier.open')}
            </Link>
        </section>
    );
}
