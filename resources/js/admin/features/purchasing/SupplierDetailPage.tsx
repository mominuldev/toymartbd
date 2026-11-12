import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router';
import { PencilSimpleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { dataTable } from '@/components/ui/DataTable';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip } from '@/components/ui/StatusChip';
import { usePurchases, useSupplier, useSupplierPayments, type Supplier } from './api';
import { CellLabel, PurchaseStatusChip, Taka, Th, formatDay, supplierTerms } from './parts';
import { PaymentDialog } from './PaymentDialog';

/**
 * Supplier detail with payables (I-03, N-10; "supplier detail" is listed as not drawn in
 * 06-admin-feature-checklist §6 — built from StatCard 189:360 and DataTable 192:532):
 * contact and terms, the server's payable figures (opening + received − paid = balance,
 * finance.view only), the supplier's purchase orders, and its payments with Record payment.
 */
export function SupplierDetailPage() {
    const params = useParams();
    const id = Number(params.supplierId);
    const supplier = useSupplier(Number.isFinite(id) ? id : null);

    if (supplier.isError) return <QueryError error={supplier.error} onRetry={() => void supplier.refetch()} />;
    if (!supplier.data) return <p aria-busy="true" className="sr-only" />;

    return <SupplierDetail supplier={supplier.data} />;
}

function SupplierDetail({ supplier }: { supplier: Supplier }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [paying, setPaying] = useState(false);
    const financeView = can(me, 'finance.view');
    const purchases = usePurchases({ status: '', supplier: String(supplier.id), search: '' });
    const payments = useSupplierPayments(supplier.id, financeView);
    const poRows = useMemo(() => purchases.data?.pages.flatMap((p) => p.data) ?? [], [purchases.data]);
    const paymentRows = useMemo(() => payments.data?.pages.flatMap((p) => p.data) ?? [], [payments.data]);
    const viewCost = can(me, 'catalog.view_cost');
    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;
    const balance = supplier.balance;
    const advance = balance?.balance.startsWith('-') ?? false;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('purchasing.crumb'), to: '/purchasing' }, { label: t('suppliers.title'), to: '/purchasing/suppliers' }, { label: supplier.name }]}
                title={supplier.name}
                subtitle={
                    <span className="flex flex-wrap items-center gap-2">
                        <StatusChip label={t(supplier.status === 'active' ? 'common.active' : 'common.archived')} active={supplier.status === 'active'} />
                        {supplierTerms(supplier.payment_terms_days, t)}
                    </span>
                }
                actions={
                    <>
                        {can(me, 'purchasing.manage_suppliers') && (
                            <Link to={`/purchasing/suppliers/${supplier.id}/edit`} className={buttonClass('outline')}>
                                <PencilSimpleIcon size={16} aria-hidden />
                                {t('common.edit')}
                            </Link>
                        )}
                        {can(me, 'finance.record_supplier_payments') && <Button onClick={() => setPaying(true)}>{t('po.recordPayment')}</Button>}
                    </>
                }
            />

            {balance && (
                <section aria-label={t('suppliers.payables')} className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {(
                        [
                            ['suppliers.opening', balance.opening],
                            ['suppliers.received', balance.received],
                            ['suppliers.paid', balance.paid],
                            [advance ? 'suppliers.advance' : 'suppliers.balance', advance ? balance.balance.slice(1) : balance.balance],
                        ] as [MessageKey, string][]
                    ).map(([label, amount], index) => (
                        <div key={label} className={`flex min-w-0 flex-col gap-1.5 rounded-md bg-surface p-4 shadow-e1 ${index === 3 ? 'border-l-3 border-brand' : 'border border-line-default'}`}>
                            <p className="t-admin-overline text-fg-secondary">{t(label)}</p>
                            <p className="t-admin-num-lg tabular text-fg-primary">
                                <Taka amount={amount} signClass="t-admin-currency-num-lg" />
                            </p>
                        </div>
                    ))}
                </section>
            )}

            <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <section aria-labelledby="supplier-pos" className="flex min-w-0 flex-col gap-3">
                    <h2 id="supplier-pos" className="t-admin-card-title text-fg-primary">
                        {t('suppliers.purchases')}
                    </h2>
                    {purchases.isError ? (
                        <QueryError error={purchases.error} onRetry={() => void purchases.refetch()} />
                    ) : poRows.length === 0 ? (
                        <p className="t-admin-body text-fg-tertiary">{t('purchasing.empty')}</p>
                    ) : (
                        <div className={dataTable.card}>
                            <table className={dataTable.table}>
                                <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                                    <tr className="border-b border-line-strong">
                                        <Th>{t('purchasing.col.po')}</Th>
                                        <Th>{t('purchasing.col.expected')}</Th>
                                        {viewCost && <Th end>{t('purchasing.col.total')}</Th>}
                                        {viewCost && <Th end>{t('purchasing.col.due')}</Th>}
                                        <Th>{t('purchasing.col.status')}</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {poRows.map((po) => (
                                        <tr key={po.id} className={`flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row}`}>
                                            <td className={`${cell} basis-full lg:basis-auto`}>
                                                <Link to={`/purchasing/${po.id}`} className="t-admin-mono text-fg-primary hover:underline">
                                                    {po.number}
                                                </Link>
                                            </td>
                                            <td className={`${cell} t-admin-body`}>{po.expected_date ? formatDay(po.expected_date, locale) : '—'}</td>
                                            {viewCost && (
                                                <td className={`${cell} t-admin-num tabular lg:text-end`}>
                                                    <CellLabel text={t('purchasing.col.total')} />
                                                    {po.total !== undefined && <Taka amount={po.total} />}
                                                </td>
                                            )}
                                            {viewCost && (
                                                <td className={`${cell} t-admin-num tabular lg:text-end`}>
                                                    <CellLabel text={t('purchasing.col.due')} />
                                                    {po.due_amount !== undefined && <Taka amount={po.due_amount} />}
                                                </td>
                                            )}
                                            <td className={cell}>
                                                <PurchaseStatusChip status={po.status} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <div className="flex min-w-0 flex-col gap-6">
                    <section aria-labelledby="supplier-contact" className="flex flex-col gap-1 rounded-md border border-line-default bg-surface p-5 shadow-e1">
                        <h2 id="supplier-contact" className="pb-1 t-admin-card-title text-fg-primary">
                            {t('suppliers.contact')}
                        </h2>
                        {supplier.contact_person && <p className="t-admin-body-strong text-fg-primary">{supplier.contact_person}</p>}
                        {supplier.phone && <p className="t-admin-mono-sm text-fg-secondary">{supplier.phone}</p>}
                        {supplier.alt_phone && <p className="t-admin-mono-sm text-fg-secondary">{supplier.alt_phone}</p>}
                        {supplier.email && <p className="t-admin-body text-fg-secondary">{supplier.email}</p>}
                        {supplier.address && <p className="t-admin-body text-fg-secondary">{supplier.address}</p>}
                        {supplier.tin && <p className="t-admin-mono-sm text-fg-tertiary">{supplier.tin}</p>}
                    </section>

                    {financeView && (
                        <section aria-labelledby="supplier-payments" className="flex flex-col gap-2 rounded-md border border-line-default bg-surface p-5 shadow-e1">
                            <h2 id="supplier-payments" className="t-admin-card-title text-fg-primary">
                                {t('suppliers.payments')}
                            </h2>
                            {paymentRows.length === 0 ? (
                                <p className="t-admin-body text-fg-tertiary">{t('suppliers.noPayments')}</p>
                            ) : (
                                <ul className="flex flex-col">
                                    {paymentRows.map((p) => (
                                        <li key={p.id} className="flex items-start gap-3 border-t border-line-default py-2 first:border-t-0">
                                            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                                <span className="t-admin-body-strong text-fg-primary">{formatDay(p.paid_at, locale, true)}</span>
                                                <span className="t-admin-caption text-fg-tertiary">
                                                    {[t(`payment.method.${p.method}` as MessageKey), p.reference, p.purchase_number].filter(Boolean).join(' · ')}
                                                </span>
                                            </div>
                                            <span className="t-admin-num tabular whitespace-nowrap">
                                                <Taka amount={p.amount} />
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {payments.hasNextPage && (
                                <Button variant="outline" size="sm" className="self-start" onClick={() => void payments.fetchNextPage()} disabled={payments.isFetchingNextPage}>
                                    {t('common.loadMore')}
                                </Button>
                            )}
                        </section>
                    )}
                </div>
            </div>

            <PaymentDialog
                open={paying}
                onClose={() => setPaying(false)}
                supplier={supplier}
                purchaseId={null}
                purchases={poRows.filter((po) => po.status !== 'draft' && po.status !== 'cancelled').map((po) => ({ id: po.id, number: po.number }))}
            />
        </section>
    );
}
