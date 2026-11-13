import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router';
import { CalendarBlankIcon, HandCoinsIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { ledgerTypes, useLedger, useReceivables, useRecordCodReceipt, type LedgerEntry, type LedgerFilters, type LedgerType } from './api';
import { FinanceTabs, FormDialog, StatCard, TAKA, Taka, dhakaDate, formatCount, formatMoney, ledgerTypeLabel, rangeLabel, shortDate } from './parts';

const ranges = ['7', '30', '90', 'all'] as const;
type Range = (typeof ranges)[number];
const kinds = ['', 'recognised', 'cash', 'noncash'] as const;
type Kind = (typeof kinds)[number];
const agingBuckets = ['0_7', '8_15', '16_30', '31_plus'] as const;
const knownSources = ['order', 'order_reversal', 'expense', 'expense_payment', 'expense_void', 'manual_cod_receipt', 'stock_adjustment', 'supplier_payment', 'purchase', 'order_return', 'refund', 'refund_payout'];

function sourceLabel(entry: LedgerEntry, t: (key: MessageKey, vars?: Record<string, string | number>) => string): string {
    return knownSources.includes(entry.source_type)
        ? t(`finLedger.source.${entry.source_type}` as MessageKey, { id: entry.source_id })
        : t('finLedger.source.other', { type: entry.source_type, id: entry.source_id });
}

/**
 * The ledger browser and the COD receivables card (P5; checklist §2.13 "COD receivables").
 * No Figma frame of its own: built from the Stock ledger screen's pieces (213:7516 — range
 * select, DataTable 192:532) and StatCards, Theme B tokens only. Read-only: the ledger is
 * append-only (rule 8). Each row says whether it counts in the P&L and/or as cash (rule 10).
 */
export function LedgerPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const reconcile = can(me, 'finance.reconcile_cod');
    const [params, setParams] = useSearchParams();
    const rangeParam = params.get('range');
    const range: Range = ranges.includes(rangeParam as Range) ? (rangeParam as Range) : '30';
    const kindParam = params.get('kind') ?? '';
    const kind: Kind = kinds.includes(kindParam as Kind) ? (kindParam as Kind) : '';
    const typeParam = params.get('type');
    const today = dhakaDate(0);

    const filters: LedgerFilters = useMemo(
        () => ({
            type: ledgerTypes.includes(typeParam as LedgerType) ? (typeParam as LedgerType) : '',
            recognised: kind === 'recognised' ? 'true' : '',
            cash: kind === 'cash' ? 'true' : kind === 'noncash' ? 'false' : '',
            from: range === 'all' ? '' : dhakaDate(Number(range) - 1),
            to: range === 'all' ? '' : today,
            source: /^[a-z_]+$/.test(params.get('source') ?? '') ? params.get('source')! : '',
        }),
        [params, kind, range, today, typeParam],
    );
    const ledger = useLedger(filters);
    const receivables = useReceivables();
    const record = useRecordCodReceipt();
    const rows = useMemo(() => ledger.data?.pages.flatMap((page) => page.data) ?? [], [ledger.data]);

    const [codOpen, setCodOpen] = useState(false);
    const [cod, setCod] = useState({ order: '', amount: '', received_on: today, reference: '' });
    const [codErrors, setCodErrors] = useState<Record<string, string | undefined>>({});
    const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());

    const setParam = (key: string, value: string) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                if (value) next.set(key, value);
                else next.delete(key);
                return next;
            },
            { replace: true },
        );

    const submitCod = () => {
        if (!/^\d+$/.test(cod.order)) {
            setCodErrors({ order: t('finCod.dialog.orderHint') });
            return;
        }
        record.mutate(
            { orderId: Number(cod.order), input: { amount: cod.amount.trim().replace(/,/g, ''), received_on: cod.received_on, reference: cod.reference.trim() || null }, idempotencyKey },
            {
                onSuccess: (receipt) => {
                    notify(t('finCod.recorded', { outstanding: `${TAKA}${formatMoney(receipt.order_outstanding)}` }));
                    setCodOpen(false);
                },
                onError: (error) => {
                    if (error instanceof ApiError && error.status === 422) {
                        setCodErrors({ amount: error.fieldError('amount'), received_on: error.fieldError('received_on'), reference: error.fieldError('reference') });
                    } else {
                        setCodErrors({ order: error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title') });
                    }
                },
            },
        );
    };

    const r = receivables.data;
    const cell = `${dataTable.td.replace('h-15 px-3 py-2', 'h-11 px-3 py-1.5')} max-lg:h-auto max-lg:p-0`;
    const rangeText = (value: Range): string => (value === 'all' ? t('expenses.range.all') : t(`expenses.range.${value}` as MessageKey, { range: rangeLabel(dhakaDate(Number(value) - 1), today) }));

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('finNav.crumb') }, { label: t('finNav.finance') }, { label: t('finLedger.title') }]}
                title={t('finLedger.title')}
                subtitle={t('finLedger.subtitle')}
                actions={
                    <>
                        <label className="relative flex w-full sm:w-65">
                            <span className="sr-only">{t('expenses.range')}</span>
                            <CalendarBlankIcon size={16} aria-hidden className="pointer-events-none absolute start-3 top-1/2 z-10 -translate-y-1/2 text-fg-secondary" />
                            <Select className="w-full [&_select]:h-9 [&_select]:ps-9" value={range} onChange={(e) => setParam('range', e.target.value === '30' ? '' : e.target.value)}>
                                {ranges.map((value) => (
                                    <option key={value} value={value}>
                                        {rangeText(value)}
                                    </option>
                                ))}
                            </Select>
                        </label>
                        {reconcile && (
                            <Button
                                onClick={() => {
                                    setCod({ order: '', amount: '', received_on: today, reference: '' });
                                    setCodErrors({});
                                    setIdempotencyKey(crypto.randomUUID());
                                    record.reset();
                                    setCodOpen(true);
                                }}
                            >
                                <HandCoinsIcon size={16} aria-hidden />
                                {t('finCod.record')}
                            </Button>
                        )}
                    </>
                }
            />
            <FinanceTabs />

            {receivables.isError ? (
                <QueryError error={receivables.error} onRetry={() => void receivables.refetch()} />
            ) : (
                r && (
                    <section aria-labelledby="cod-receivables" className="flex flex-col gap-3">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 id="cod-receivables" className="t-admin-section text-fg-primary">
                                {t('finCod.title')}
                            </h2>
                            <p className="t-admin-caption text-fg-tertiary">{t('finCod.hint')}</p>
                        </div>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <StatCard
                                label={t('finCod.outstanding')}
                                value={
                                    <span className="t-admin-currency-num-lg">
                                        {TAKA}
                                        {formatMoney(r.outstanding)}
                                    </span>
                                }
                                hint={t('finCod.openOrders', { count: formatCount(r.open_orders) })}
                            />
                            <StatCard label={t('finCod.recognised')} value={<span className="t-admin-currency-num-lg">{TAKA}{formatMoney(r.recognised)}</span>} hint={t('finLedger.flag.pl')} />
                            <StatCard label={t('finCod.received')} value={<span className="t-admin-currency-num-lg">{TAKA}{formatMoney(r.received)}</span>} hint={t('finLedger.flag.cash')} />
                            <div className="flex min-w-0 flex-col gap-1.5 rounded-md border border-line-default bg-surface p-4 shadow-e1">
                                <p className="t-admin-overline text-fg-secondary">{t('finCod.aging')}</p>
                                <dl className="grid grid-cols-2 gap-x-3 gap-y-1">
                                    {agingBuckets.map((bucket) => (
                                        <div key={bucket} className="flex items-baseline justify-between gap-2">
                                            <dt className="t-admin-caption text-fg-tertiary">{t(`finCod.age.${bucket}` as MessageKey)}</dt>
                                            <dd className="t-admin-num tabular text-fg-primary">
                                                <Taka amount={r.aging[bucket]} signClass="t-admin-currency-caption" />
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                        </div>
                    </section>
                )
            )}

            <div className="flex flex-wrap items-center gap-2">
                <label className="flex">
                    <span className="sr-only">{t('finLedger.filter.type')}</span>
                    <Select size="sm" value={filters.type} onChange={(e) => setParam('type', e.target.value)} className="w-52">
                        <option value="">{t('finLedger.filter.typeAll')}</option>
                        {ledgerTypes.map((value) => (
                            <option key={value} value={value}>
                                {t(ledgerTypeLabel(value))}
                            </option>
                        ))}
                    </Select>
                </label>
                <label className="flex">
                    <span className="sr-only">{t('finLedger.filter.kind')}</span>
                    <Select size="sm" value={kind} onChange={(e) => setParam('kind', e.target.value)} className="w-52">
                        <option value="">{t('finLedger.filter.kindAll')}</option>
                        <option value="recognised">{t('finLedger.filter.recognised')}</option>
                        <option value="cash">{t('finLedger.filter.cash')}</option>
                        <option value="noncash">{t('finLedger.filter.nonCash')}</option>
                    </Select>
                </label>
            </div>

            {ledger.isError ? (
                <QueryError error={ledger.error} onRetry={() => void ledger.refetch()} />
            ) : ledger.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : rows.length === 0 ? (
                <EmptyState title={t('finLedger.empty')} body={t('finLedger.emptyBody')} />
            ) : (
                <div className={dataTable.card}>
                    <table className={dataTable.table}>
                        <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                            <tr className="border-b border-line-strong">
                                <th scope="col" className={dataTable.th}>{t('finLedger.col.date')}</th>
                                <th scope="col" className={dataTable.th}>{t('finLedger.col.type')}</th>
                                <th scope="col" className={dataTable.th}>{t('finLedger.col.source')}</th>
                                <th scope="col" className={dataTable.th}>{t('finLedger.col.description')}</th>
                                <th scope="col" className={dataTable.th}>{t('finLedger.col.flags')}</th>
                                <th scope="col" className={`${dataTable.th.replace('text-start', 'text-end')}`}>{t('finLedger.col.in')}</th>
                                <th scope="col" className={`${dataTable.th.replace('text-start', 'text-end')}`}>{t('finLedger.col.out')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((entry) => (
                                <tr key={entry.id} className={`${dataTable.row} max-lg:flex max-lg:flex-wrap max-lg:gap-x-3 max-lg:gap-y-1 max-lg:p-3`}>
                                    <td className={`${cell} t-admin-body-strong tabular whitespace-nowrap`}>{shortDate(entry.occurred_on, locale)}</td>
                                    <td className={`${cell} t-admin-body`}>{t(ledgerTypeLabel(entry.type))}</td>
                                    <td className={`${cell} t-admin-mono-sm whitespace-nowrap`}>{sourceLabel(entry, t)}</td>
                                    <td className={`${cell} t-admin-caption text-fg-secondary max-lg:basis-full`}>
                                        {/* The server's expense descriptions already lead with the category name; don't print it twice. */}
                                        {entry.category_id !== null && !(entry.description ?? '').startsWith(entry.category_name ?? '\u0000') && <span className="t-admin-caption-strong text-fg-primary">{(locale === 'bn' ? entry.category_name_bn : null) ?? entry.category_name} · </span>}
                                        {entry.description ?? '—'}
                                    </td>
                                    <td className={`${cell} t-admin-caption whitespace-nowrap text-fg-secondary`}>
                                        {[entry.is_recognised_revenue && t('finLedger.flag.pl'), entry.is_cash_movement && t('finLedger.flag.cash')].filter(Boolean).join(' · ') || '—'}
                                    </td>
                                    <td className={`${cell} t-admin-num tabular whitespace-nowrap lg:text-end`}>{entry.direction === 'in' ? <Taka amount={entry.amount} /> : ''}</td>
                                    <td className={`${cell} t-admin-num tabular whitespace-nowrap lg:text-end`}>{entry.direction === 'out' ? <Taka amount={entry.amount} /> : ''}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <DataTableFooter summary={t('finLedger.showing', { count: formatCount(rows.length) })}>
                        {ledger.hasNextPage && (
                            <Button size="sm" variant="outline" disabled={ledger.isFetchingNextPage} onClick={() => void ledger.fetchNextPage()}>
                                {t('common.loadMore')}
                            </Button>
                        )}
                    </DataTableFooter>
                </div>
            )}

            <FormDialog open={codOpen} title={t('finCod.dialog.title')} body={t('finCod.dialog.body')} submitLabel={t('finCod.dialog.save')} pending={record.isPending} onClose={() => setCodOpen(false)} onSubmit={submitCod}>
                <Field label={t('finCod.dialog.order')} hint={t('finCod.dialog.orderHint')} error={codErrors.order} required>
                    {({ id, describedBy, invalid }) => <Input id={id} inputMode="numeric" className="tabular" aria-describedby={describedBy} aria-invalid={invalid || undefined} value={cod.order} onChange={(e) => setCod({ ...cod, order: e.target.value.trim() })} />}
                </Field>
                <Field label={t('finCod.dialog.amount')} error={codErrors.amount} required>
                    {({ id, describedBy, invalid }) => (
                        <Input id={id} inputMode="decimal" className="tabular" prefix={<span className="t-admin-currency-num text-fg-primary">{TAKA}</span>} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={cod.amount} onChange={(e) => setCod({ ...cod, amount: e.target.value })} />
                    )}
                </Field>
                <div className="grid gap-3.5 sm:grid-cols-2">
                    <Field label={t('finCod.dialog.receivedOn')} error={codErrors.received_on} required>
                        {({ id, describedBy, invalid }) => <Input id={id} type="date" max={today} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={cod.received_on} onChange={(e) => setCod({ ...cod, received_on: e.target.value })} />}
                    </Field>
                    <Field label={t('finCod.dialog.reference')} error={codErrors.reference} optional>
                        {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={cod.reference} onChange={(e) => setCod({ ...cod, reference: e.target.value })} />}
                    </Field>
                </div>
            </FormDialog>
        </section>
    );
}
