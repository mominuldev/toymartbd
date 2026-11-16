import { useEffect, useMemo, useRef, useState, type MouseEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { CubeIcon, HandshakeIcon, PlusCircleIcon, StackIcon, WarningIcon, XCircleIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { openPdf } from '@/lib/pdf';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ActionMenu, type ActionMenuItem } from '@/components/ui/ActionMenu';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { KpiCard } from '@/components/ui/KpiCard';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { TabButton } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';
import {
    purchaseTabs,
    usePurchase,
    usePurchaseCounts,
    usePurchaseSummary,
    usePurchases,
    useSuppliers,
    type PurchaseExpected,
    type PurchaseListItem,
    type PurchaseTab,
} from './api';
import { CellLabel, Money, PurchaseStatusChip, ReceivedBar, Th, formatDay, formatUnits, supplierTerms } from './parts';
import { PaymentDialog } from './PaymentDialog';
import { ReceiveDrawer } from './ReceiveDrawer';

/**
 * Purchase orders (Figma "Purchasing · Premium" list 414:70079): the KPI row (open orders and
 * their value, arriving this Saturday–Friday week, late, what we owe suppliers — all from
 * `GET /purchases/summary`), search + supplier filter, the All / Draft / Waiting for stock /
 * Partly received / Received / Closed tabs with server counts, and one row per PO: number,
 * supplier and terms, ordered, expected (+ Late), received progress in units, total, still to
 * pay (or Paid), status and a ⋯ menu. A row opens its PO; nothing is received on the list
 * itself except through the ⋯ menu's drawer. Filters live in the URL and are all server-side
 * allowlisted parameters; every taka figure is the server's (rule 1).
 */
export function PurchaseOrdersPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const navigate = useNavigate();
    const [params, setParams] = useSearchParams();
    const tabParam = params.get('status') as PurchaseTab | null;
    const tab: PurchaseTab = tabParam && purchaseTabs.includes(tabParam) ? tabParam : 'all';
    const expectedParam = params.get('expected');
    const expected: PurchaseExpected | '' = expectedParam === 'late' || expectedParam === 'this_week' ? expectedParam : '';
    const supplier = /^\d+$/.test(params.get('supplier') ?? '') ? (params.get('supplier') ?? '') : '';
    const q = params.get('q') ?? '';
    const [search, setSearch] = useState(q);
    const searchRef = useRef<HTMLInputElement>(null);

    const set = (entries: Record<string, string | null>) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                for (const [key, value] of Object.entries(entries)) {
                    if (value) next.set(key, value);
                    else next.delete(key);
                }
                return next;
            },
            { replace: true },
        );

    // The search is a server filter: commit it after a pause, never filter loaded rows.
    useEffect(() => {
        const timer = setTimeout(() => {
            if (search.trim() !== q) set({ q: search.trim() || null });
        }, 300);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    // "/" focuses the search, like Inventory.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            if (event.key !== '/' || target?.closest('input, textarea, select, [contenteditable="true"]')) return;
            event.preventDefault();
            searchRef.current?.focus();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, []);

    const summary = usePurchaseSummary();
    const counts = usePurchaseCounts();
    const suppliers = useSuppliers({ status: '', search: '' });
    const list = usePurchases({ status: tab === 'all' ? '' : tab, supplier, search: q, expected });
    const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);
    const supplierOptions = suppliers.data?.pages.flatMap((p) => p.data) ?? [];
    const viewCost = can(me, 'catalog.view_cost');
    const canReceive = can(me, 'purchasing.receive');
    const canPay = can(me, 'finance.record_supplier_payments');

    const [receivingId, setReceivingId] = useState<number | null>(null);
    const receiving = usePurchase(receivingId);
    const [paying, setPaying] = useState<PurchaseListItem | null>(null);

    const print = (id: number) =>
        openPdf(`/purchases/${id}/invoice`).catch((error: unknown) => notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('invoices.printFailed'), 'danger'));

    const rowItems = (po: PurchaseListItem): ActionMenuItem[] => {
        const items: ActionMenuItem[] = [{ key: 'open', label: t('purchasing.menu.open'), to: `/purchasing/${po.id}` }];
        if (canReceive && (po.status === 'ordered' || po.status === 'partially_received')) items.push({ key: 'receive', label: t('purchasing.menu.receive'), onSelect: () => setReceivingId(po.id) });
        if (canPay && po.status !== 'draft' && po.status !== 'cancelled') items.push({ key: 'pay', label: t('purchasing.menu.pay'), onSelect: () => setPaying(po) });
        if (viewCost && po.status !== 'draft' && po.status !== 'cancelled') items.push({ key: 'print', label: t('purchasing.menu.print'), onSelect: () => void print(po.id) });
        return items;
    };

    /** A row opens its PO — except a click on a link, a button or the ⋯ menu inside it. */
    const openRow = (event: MouseEvent<HTMLTableRowElement>, id: number) => {
        if ((event.target as HTMLElement).closest('a, button, [role="menu"]')) return;
        navigate(`/purchasing/${id}`);
    };

    const data = summary.data;
    const base = `${dataTable.td} max-xl:h-auto max-xl:p-0`;
    // Below 1280 a row is a card: number, status and ⋯ on top, the supplier, then the figures.
    const cell = `${base} max-xl:order-5`;
    const num = `${cell} t-admin-num tabular whitespace-nowrap xl:text-end`;

    return (
        <section className="flex flex-col gap-6 max-sm:gap-4">
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
                                <PlusCircleIcon size={16} aria-hidden />
                                {t('purchasing.new')}
                            </Link>
                        )}
                    </>
                }
            />

            {summary.isError ? (
                <QueryError error={summary.error} onRetry={() => void summary.refetch()} />
            ) : data ? (
                <div role="region" aria-label={t('purchasing.kpi.region')} className={`grid grid-cols-2 gap-3 sm:gap-4 ${data.owed !== null ? 'lg:grid-cols-4' : 'lg:grid-cols-3'}`}>
                    <KpiCard
                        icon={StackIcon}
                        tone="brand"
                        label={t('purchasing.kpi.open')}
                        value={
                            data.open_value !== null ? (
                                <span className="t-admin-currency-num-lg">
                                    <Money amount={data.open_value} signClass="t-admin-currency-num-lg" />
                                </span>
                            ) : (
                                <span className="t-admin-num-lg">{formatUnits(data.open_count)}</span>
                            )
                        }
                        hint={t('purchasing.kpi.openHint', { count: formatUnits(data.open_count) })}
                    />
                    <KpiCard
                        icon={CubeIcon}
                        tone="info"
                        label={t('purchasing.kpi.arriving')}
                        value={<span className="t-admin-num-lg">{formatUnits(data.arriving_count)}</span>}
                        hint={t('purchasing.kpi.arrivingHint', { date: formatDay(data.week_ends, locale, false, true) })}
                        active={expected === 'this_week'}
                        onClick={() => set({ expected: expected === 'this_week' ? null : 'this_week', status: null })}
                    />
                    <KpiCard
                        icon={XCircleIcon}
                        tone="danger"
                        label={t('purchasing.kpi.late')}
                        value={<span className="t-admin-num-lg">{formatUnits(data.late_count)}</span>}
                        hint={t('purchasing.kpi.lateHint')}
                        active={expected === 'late'}
                        onClick={() => set({ expected: expected === 'late' ? null : 'late', status: null })}
                    />
                    {data.owed !== null && (
                        <KpiCard
                            icon={WarningIcon}
                            tone="warning"
                            label={t('purchasing.kpi.owed')}
                            value={
                                <span className="t-admin-currency-num-lg">
                                    <Money amount={data.owed} signClass="t-admin-currency-num-lg" />
                                </span>
                            }
                            hint={t('purchasing.kpi.owedHint', { count: formatUnits(data.owed_suppliers ?? 0) })}
                        />
                    )}
                </div>
            ) : (
                <div aria-hidden className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                    {[0, 1, 2, 3].map((i) => (
                        <div key={i} className="h-32 rounded-md bg-skeleton motion-safe:animate-pulse" />
                    ))}
                </div>
            )}

            <div className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <SearchField
                        ref={searchRef}
                        label={t('purchasing.search.label')}
                        placeholder={t('purchasing.search.placeholder')}
                        shortcut="/"
                        aria-keyshortcuts="/"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Escape' && search) {
                                event.preventDefault();
                                setSearch('');
                            }
                        }}
                        enterKeyHint="search"
                        autoComplete="off"
                        spellCheck={false}
                        className="w-full sm:w-96"
                    />
                    <Select aria-label={t('purchasing.supplierFilter')} className="w-full sm:w-60" value={supplier} onChange={(e) => set({ supplier: e.target.value || null })}>
                        <option value="">{t('purchasing.allSuppliers')}</option>
                        {supplierOptions.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </Select>
                </div>

                <div role="group" aria-label={t('purchasing.tabs')} className="scrollbar-none flex overflow-x-auto border-b border-line-default max-sm:-mx-4 max-sm:px-4">
                    {purchaseTabs.map((s) => (
                        <TabButton
                            key={s}
                            active={tab === s && expected === ''}
                            label={t(`purchasing.tab.${s}` as MessageKey)}
                            count={counts.data?.[s]}
                            onClick={() => set({ status: s === 'all' ? null : s, expected: null })}
                        />
                    ))}
                </div>

                {expected !== '' && (
                    <p className="flex flex-wrap items-center gap-2 rounded-sm bg-info-subtle px-3 py-2 t-admin-body text-fg-primary" aria-live="polite">
                        <span className="min-w-0 flex-1">{t(`purchasing.showing.${expected}` as MessageKey)}</span>
                        <Button variant="ghost" size="sm" onClick={() => set({ expected: null })}>
                            {t('purchasing.showing.clear')}
                        </Button>
                    </p>
                )}
            </div>

            {list.isError ? (
                <QueryError error={list.error} onRetry={() => void list.refetch()} />
            ) : list.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : rows.length === 0 ? (
                q ? (
                    <EmptyState
                        reason="notFound"
                        title={t('purchasing.emptySearch', { term: q })}
                        body={t('purchasing.emptySearchBody')}
                        actions={
                            <Button variant="outline" onClick={() => setSearch('')}>
                                {t('inventory.clearSearch')}
                            </Button>
                        }
                    />
                ) : tab !== 'all' || expected !== '' || supplier !== '' ? (
                    <EmptyState reason="allClear" title={t('purchasing.emptyFiltered')} body={t('purchasing.emptyFilteredBody')} />
                ) : (
                    <EmptyState title={t('purchasing.empty')} body={t('purchasing.emptyBody')} />
                )
            ) : (
                <div className={`${dataTable.card} transition-opacity duration-150 ease-standard ${list.isPlaceholderData ? 'opacity-60' : ''}`} aria-busy={list.isPlaceholderData}>
                    <table className={`${dataTable.table} max-xl:block`}>
                        <thead className={`hidden xl:table-header-group ${dataTable.head}`}>
                            <tr className="border-b border-line-strong">
                                <Th>{t('purchasing.col.po')}</Th>
                                <Th>{t('purchasing.col.supplier')}</Th>
                                <Th>{t('purchasing.col.ordered')}</Th>
                                <Th>{t('purchasing.col.expected')}</Th>
                                <Th>{t('purchasing.col.received')}</Th>
                                {viewCost && <Th end>{t('purchasing.col.total')}</Th>}
                                {viewCost && <Th end>{t('purchasing.col.toPay')}</Th>}
                                <Th>{t('purchasing.col.status')}</Th>
                                <th scope="col" className={`${dataTable.th} w-14`}>
                                    <span className="sr-only">{t('purchasing.col.actions')}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="max-xl:block">
                            {rows.map((po) => (
                                <tr
                                    key={po.id}
                                    onClick={(event) => openRow(event, po.id)}
                                    className={`flex cursor-pointer flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 max-xl:border-t max-xl:border-line-default max-xl:first:border-t-0 xl:table-row xl:p-0 ${dataTable.row}`}
                                >
                                    <td className={`${base} max-xl:order-1 max-xl:min-w-0 max-xl:flex-1`}>
                                        <Link to={`/purchasing/${po.id}`} className="t-admin-mono text-fg-brand hover:underline">
                                            {po.number}
                                        </Link>
                                    </td>
                                    <td className={`${base} max-xl:order-4 max-xl:basis-full xl:max-w-56`}>
                                        <p className="truncate t-admin-body-strong text-fg-primary">{po.supplier.name}</p>
                                        <p className="t-admin-caption text-fg-secondary">{supplierTerms(po.supplier.payment_terms_days, t)}</p>
                                    </td>
                                    <td className={`${cell} t-admin-body whitespace-nowrap`}>
                                        <CellLabel text={t('purchasing.col.ordered')} />
                                        {formatDay(po.order_date, locale)}
                                    </td>
                                    <td className={`${cell} t-admin-body whitespace-nowrap`}>
                                        <CellLabel text={t('purchasing.col.expected')} />
                                        <span className="inline-flex flex-col items-start gap-1">
                                            <span className={po.expected_date ? 'text-fg-primary' : 'text-fg-secondary'}>{po.expected_date ? formatDay(po.expected_date, locale) : t('purchasing.notSet')}</span>
                                            {po.is_late && <StatusChip label={t('purchasing.late')} tone="danger" />}
                                        </span>
                                    </td>
                                    <td className={`${cell} xl:w-40`}>
                                        <CellLabel text={t('purchasing.col.received')} />
                                        {po.status === 'draft' || po.status === 'cancelled' ? (
                                            <span className="t-admin-body text-fg-secondary">—</span>
                                        ) : (
                                            <span className="inline-flex w-33 flex-col gap-1.5 align-middle">
                                                <span className="t-admin-caption tabular text-fg-primary">
                                                    {t('purchasing.unitsOf', { received: formatUnits(po.units_received), ordered: formatUnits(po.units_ordered) })}
                                                </span>
                                                <ReceivedBar
                                                    received={po.units_received}
                                                    ordered={po.units_ordered}
                                                    label={t('purchasing.progressLabel', { received: formatUnits(po.units_received), ordered: formatUnits(po.units_ordered) })}
                                                />
                                            </span>
                                        )}
                                    </td>
                                    {viewCost && (
                                        <td className={`${num} t-admin-body-strong`}>
                                            <CellLabel text={t('purchasing.col.total')} />
                                            {po.total !== undefined && <Money amount={po.total} />}
                                        </td>
                                    )}
                                    {viewCost && (
                                        <td className={num}>
                                            <CellLabel text={t('purchasing.col.toPay')} />
                                            {po.status === 'draft' || po.status === 'cancelled' || po.due_amount === undefined ? (
                                                <span className="text-fg-secondary">—</span>
                                            ) : po.is_paid ? (
                                                <StatusChip label={t('purchasing.paid')} tone="success" />
                                            ) : (
                                                <Money amount={po.due_amount} />
                                            )}
                                        </td>
                                    )}
                                    <td className={`${base} max-xl:order-2`}>
                                        <PurchaseStatusChip status={po.status} />
                                    </td>
                                    <td className={`${base} max-xl:order-3 xl:w-14 xl:text-end`}>
                                        <ActionMenu variant="outline" label={t('purchasing.rowMenu', { number: po.number })} items={rowItems(po)} />
                                    </td>
                                </tr>
                            ))}
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

            <ReceiveDrawer purchase={receiving.data ?? null} open={receivingId !== null} onClose={() => setReceivingId(null)} />
            {paying && (
                <PaymentDialog open onClose={() => setPaying(null)} supplier={paying.supplier} purchaseId={paying.id} purchases={[{ id: paying.id, number: paying.number }]} />
            )}
        </section>
    );
}
