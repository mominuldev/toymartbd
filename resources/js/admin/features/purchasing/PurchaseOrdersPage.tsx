import { useMemo } from 'react';
import { Link, useSearchParams } from 'react-router';
import { HandshakeIcon, PlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { TabButton } from '@/components/ui/Tabs';
import { purchaseStatuses, usePurchase, usePurchaseCounts, usePurchases, type PurchaseStatus } from './api';
import { CellLabel, PurchaseStatusChip, Taka, Th, formatDay, formatUnits, supplierTerms } from './parts';
import { ReceivePanel } from './ReceivePanel';

/**
 * Purchase orders (Figma "16 Purchase orders" 213:7971 — Theme B colours, section 14):
 * ContentHeader with Suppliers and New purchase order, status AdminTabs 185:125 with server
 * counts, the DataTable 192:532 of POs (PO number + lines, supplier + terms, expected,
 * total, status) and, beside it, the receive card for the selected open PO. Status and
 * selection live in the URL (`?status=ordered&po=44`). Figma's tabs are Open · Partly
 * received · Received · Draft; Cancelled is added so a cancelled PO stays findable.
 */
export function PurchaseOrdersPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [params, setParams] = useSearchParams();
    const statusParam = params.get('status') as PurchaseStatus | null;
    const status: PurchaseStatus = statusParam && purchaseStatuses.includes(statusParam) ? statusParam : 'ordered';
    const selectedId = /^\d+$/.test(params.get('po') ?? '') ? Number(params.get('po')) : null;

    const counts = usePurchaseCounts();
    const list = usePurchases({ status, supplier: '', search: '' });
    const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);
    const selected = usePurchase(selectedId);
    const viewCost = can(me, 'catalog.view_cost');
    const canReceive = can(me, 'purchasing.receive');

    const set = (key: string, value: string | null) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                if (value) next.set(key, value);
                else next.delete(key);
                if (key === 'status') next.delete('po');
                return next;
            },
            { replace: true },
        );

    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;
    const showPanel = selected.data && canReceive && selected.data.can.receive;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('catalog.crumb') }, { label: t('purchasing.crumb') }]}
                title={t('purchasing.title')}
                subtitle={t('purchasing.subtitle')}
                actions={
                    <>
                        <Link to="/purchasing/suppliers" className={buttonClass('outline')}>
                            <HandshakeIcon size={16} aria-hidden />
                            {t('purchasing.suppliers')}
                        </Link>
                        {can(me, 'purchasing.create') && (
                            <Link to="/purchasing/new" className={buttonClass('primary')}>
                                <PlusIcon size={16} aria-hidden />
                                {t('purchasing.new')}
                            </Link>
                        )}
                    </>
                }
            />

            <div role="group" aria-label={t('purchasing.tabs')} className="scrollbar-none flex overflow-x-auto border-b border-line-default">
                {purchaseStatuses.map((s) => (
                    <TabButton key={s} active={status === s} label={t(`purchasing.tab.${s}` as MessageKey)} count={counts.data?.[s]} onClick={() => set('status', s === 'ordered' ? null : s)} />
                ))}
            </div>

            <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_27.5rem]">
                {list.isError ? (
                    <QueryError error={list.error} onRetry={() => void list.refetch()} />
                ) : list.isPending ? (
                    <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                        {t('common.loading')}
                    </p>
                ) : rows.length === 0 ? (
                    <EmptyState title={t('purchasing.empty')} body={t('purchasing.emptyBody')} />
                ) : (
                    <div className={dataTable.card}>
                        <table className={dataTable.table}>
                            <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                                <tr className="border-b border-line-strong">
                                    <Th>{t('purchasing.col.po')}</Th>
                                    <Th>{t('purchasing.col.supplier')}</Th>
                                    <Th>{t('purchasing.col.expected')}</Th>
                                    {viewCost && <Th end>{t('purchasing.col.total')}</Th>}
                                    <Th>{t('purchasing.col.status')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((po) => {
                                    const active = po.id === selectedId;
                                    return (
                                        <tr
                                            key={po.id}
                                            aria-selected={active}
                                            className={`flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row} ${active ? 'bg-brand-subtle hover:bg-brand-subtle' : ''}`}
                                        >
                                            <td className={`${cell} basis-full lg:basis-auto`}>
                                                <button type="button" onClick={() => set('po', String(po.id))} className="flex flex-col items-start gap-0.5 text-start">
                                                    <span className="t-admin-mono text-fg-primary">{po.number}</span>
                                                    <span className="t-admin-caption text-fg-tertiary">{t('purchasing.lines', { count: formatUnits(po.lines_count) })}</span>
                                                </button>
                                            </td>
                                            <td className={cell}>
                                                <p className="t-admin-body-strong text-fg-primary">{po.supplier.name}</p>
                                                <p className="t-admin-caption text-fg-tertiary">{supplierTerms(po.supplier.payment_terms_days, t)}</p>
                                            </td>
                                            <td className={`${cell} t-admin-body whitespace-nowrap`}>
                                                <CellLabel text={t('purchasing.col.expected')} />
                                                {po.expected_date ? formatDay(po.expected_date, locale) : '—'}
                                            </td>
                                            {viewCost && (
                                                <td className={`${cell} t-admin-num tabular whitespace-nowrap lg:text-end`}>
                                                    <CellLabel text={t('purchasing.col.total')} />
                                                    {po.total !== undefined && <Taka amount={po.total} />}
                                                </td>
                                            )}
                                            <td className={cell}>
                                                <span className="flex items-center gap-2">
                                                    <PurchaseStatusChip status={po.status} />
                                                    <Link to={`/purchasing/${po.id}`} className={`${buttonClass('ghost', 'sm')} lg:sr-only lg:focus:not-sr-only`}>
                                                        {t('purchasing.open')}
                                                    </Link>
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                        {list.hasNextPage && (
                            <DataTableFooter summary={t('purchasing.count', { count: formatUnits(rows.length) })}>
                                <Button variant="outline" size="sm" onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>
                                    {list.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                                </Button>
                            </DataTableFooter>
                        )}
                    </div>
                )}

                {showPanel ? (
                    <ReceivePanel key={selected.data.id} purchase={selected.data} />
                ) : selected.data ? (
                    <div className="flex flex-col items-start gap-3 rounded-md border border-line-default bg-surface p-5 shadow-e1">
                        <p className="t-admin-card-title text-fg-primary">{selected.data.number}</p>
                        <PurchaseStatusChip status={selected.data.status} />
                        <Link to={`/purchasing/${selected.data.id}`} className={buttonClass('outline', 'sm')}>
                            {t('purchasing.open')}
                        </Link>
                    </div>
                ) : (
                    rows.length > 0 && canReceive && (status === 'ordered' || status === 'partially_received') && <p className="t-admin-body text-fg-tertiary">{t('purchasing.select')}</p>
                )}
            </div>
        </section>
    );
}
