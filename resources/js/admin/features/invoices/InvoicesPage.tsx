import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { GearIcon, PrinterIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { openPdf } from '@/lib/pdf';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { inputClass } from '@/components/ui/Field';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { StatusChip } from '@/components/ui/StatusChip';
import { TabButton } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';
import { invoiceTypes, useInvoices, type InvoiceFilters, type InvoiceListItem, type InvoiceType } from './api';
import { Taka, formatIssued } from './parts';

const ymd = /^\d{4}-\d{2}-\d{2}$/;

function useDebounced<T>(value: T, delay = 350): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const timer = setTimeout(() => setDebounced(value), delay);
        return () => clearTimeout(timer);
    }, [value, delay]);
    return debounced;
}

/**
 * Invoices (P8, PRD N-13). No Figma frame exists for an invoice list; it follows the
 * DataTable pattern of Purchase orders (B · 16 241:40438): ContentHeader, type tabs, a
 * search + issued-date filter row, the table. Every figure is the snapshot the server took
 * at issue (rule 1). An invoice is issued by its first print from the order or PO screen;
 * here it is only reprinted. Filters live in the URL.
 */
export function InvoicesPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [params, setParams] = useSearchParams();
    const typeParam = params.get('type') as InvoiceType | null;
    const type: InvoiceType | '' = typeParam && invoiceTypes.includes(typeParam) ? typeParam : '';
    const [search, setSearch] = useState(params.get('q') ?? '');
    const debounced = useDebounced(search);
    const from = ymd.test(params.get('from') ?? '') ? (params.get('from') ?? '') : '';
    const to = ymd.test(params.get('to') ?? '') ? (params.get('to') ?? '') : '';
    const filters: InvoiceFilters = { type, search: debounced, from, to };
    const list = useInvoices(filters);
    const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);

    const set = (key: string, value: string) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                if (value) next.set(key, value);
                else next.delete(key);
                return next;
            },
            { replace: true },
        );
    useEffect(() => set('q', debounced.trim()), [debounced]); // eslint-disable-line react-hooks/exhaustive-deps

    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('invoices.crumb.money') }, { label: t('invoices.title') }]}
                title={t('invoices.title')}
                subtitle={t('invoices.subtitle')}
                actions={
                    can(me, 'settings.view') && (
                        <Link to="/settings/invoice" className={buttonClass('outline')}>
                            <GearIcon size={16} aria-hidden />
                            {t('invoices.settings')}
                        </Link>
                    )
                }
            />

            <div role="group" aria-label={t('invoices.tabs')} className="scrollbar-none flex overflow-x-auto border-b border-line-default">
                {(['', ...invoiceTypes] as const).map((value) => (
                    <TabButton key={value || 'all'} active={type === value} label={t(`invoices.tab.${value || 'all'}` as MessageKey)} onClick={() => set('type', value)} />
                ))}
            </div>

            <div className="flex flex-wrap items-end gap-3">
                <SearchField label={t('invoices.search')} placeholder={t('invoices.searchPlaceholder')} value={search} onChange={(e) => setSearch(e.target.value)} className="w-full sm:w-80" />
                <label className="flex flex-col gap-1 t-admin-caption text-fg-secondary">
                    {t('invoices.from')}
                    <input type="date" value={from} max={to || undefined} onChange={(e) => set('from', e.target.value)} className={`h-9 ${inputClass}`} />
                </label>
                <label className="flex flex-col gap-1 t-admin-caption text-fg-secondary">
                    {t('invoices.to')}
                    <input type="date" value={to} min={from || undefined} onChange={(e) => set('to', e.target.value)} className={`h-9 ${inputClass}`} />
                </label>
            </div>

            {list.isError ? (
                <QueryError error={list.error} onRetry={() => void list.refetch()} />
            ) : list.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : rows.length === 0 ? (
                <EmptyState title={t('invoices.empty')} body={t('invoices.emptyBody')} />
            ) : (
                <div className={dataTable.card}>
                    <table className={dataTable.table}>
                        <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                            <tr className="border-b border-line-strong">
                                <th scope="col" className={dataTable.th}>{t('invoices.col.number')}</th>
                                <th scope="col" className={dataTable.th}>{t('invoices.col.reference')}</th>
                                <th scope="col" className={dataTable.th}>{t('invoices.col.issuedTo')}</th>
                                <th scope="col" className={dataTable.th}>{t('invoices.col.issued')}</th>
                                <th scope="col" className={`${dataTable.th} text-end`}>{t('invoices.col.total')}</th>
                                <th scope="col" className={`${dataTable.th} text-end`}>{t('invoices.col.due')}</th>
                                <th scope="col" className={dataTable.th}>
                                    <span className="sr-only">{t('invoices.col.actions')}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.id} className={`flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row}`}>
                                    <td className={`${cell} basis-full lg:basis-auto`}>
                                        <span className="flex flex-col items-start gap-0.5">
                                            <span className="t-admin-mono text-fg-primary">{row.number}</span>
                                            <StatusChip label={t(`invoices.type.${row.type}`)} tone={row.type === 'sales' ? 'info' : 'neutral'} showIcon={false} />
                                        </span>
                                    </td>
                                    <td className={cell}>
                                        <ReferenceLink row={row} />
                                    </td>
                                    <td className={`${cell} t-admin-body text-fg-primary`}>{row.issued_to_name}</td>
                                    <td className={`${cell} t-admin-body whitespace-nowrap text-fg-secondary`}>{formatIssued(row.issued_at, locale)}</td>
                                    <td className={`${cell} t-admin-num tabular whitespace-nowrap lg:text-end`}>
                                        <Taka amount={row.total} />
                                    </td>
                                    <td className={`${cell} t-admin-num tabular whitespace-nowrap text-fg-secondary lg:text-end`}>
                                        <Taka amount={row.due} />
                                    </td>
                                    <td className={`${cell} lg:text-end`}>{row.can_print && <PrintButton row={row} />}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {list.hasNextPage && (
                        <DataTableFooter summary={t('invoices.count', { count: rows.length })}>
                            <Button variant="outline" size="sm" onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>
                                {list.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                            </Button>
                        </DataTableFooter>
                    )}
                </div>
            )}
        </section>
    );
}

function ReferenceLink({ row }: { row: InvoiceListItem }) {
    const to = row.order_id ? `/orders/${row.order_id}` : row.purchase_id ? `/purchasing/${row.purchase_id}` : null;
    return to ? (
        <Link to={to} className="t-admin-mono text-fg-link hover:underline">
            {row.reference}
        </Link>
    ) : (
        <span className="t-admin-mono text-fg-primary">{row.reference}</span>
    );
}

/** Reprint: the server renders the stored snapshot (details current at issue). */
function PrintButton({ row }: { row: InvoiceListItem }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const [printing, setPrinting] = useState(false);
    return (
        <Button
            variant="ghost"
            size="sm"
            disabled={printing}
            aria-label={t('invoices.reprintAria', { number: row.number })}
            onClick={() => {
                setPrinting(true);
                openPdf(`/invoices/${row.id}/pdf`)
                    .catch((error: unknown) => notify(error instanceof ApiError ? error.problem.title : t('invoices.printFailed'), 'danger'))
                    .finally(() => setPrinting(false));
            }}
        >
            <PrinterIcon size={16} aria-hidden />
            {t('invoices.reprint')}
        </Button>
    );
}
