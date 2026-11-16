import { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { ClockCounterClockwiseIcon, HandCoinsIcon, MopedIcon, TrendUpIcon, WalletIcon } from '@phosphor-icons/react';
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
import { KpiCard } from '@/components/ui/KpiCard';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { ledgerTypes, useLedger, useReceivables, useRecordCodReceipt, type LedgerEntry, type LedgerFilters, type LedgerType } from './api';
import { FinanceTabs, FormDialog, KpiMoney, TAKA, TakaFixed, dhakaDate, formatCount, formatMoney, ledgerTypeLabel, rangeLabel, shortDate } from './parts';

const ranges = ['7', '30', '90', 'all'] as const;
type Range = (typeof ranges)[number];
const kinds = ['', 'recognised', 'cash', 'noncash'] as const;
type Kind = (typeof kinds)[number];
const agingBuckets = ['0_7', '8_15', '16_30', '31_plus'] as const;
const knownSources = ['order', 'order_reversal', 'expense', 'expense_payment', 'expense_void', 'manual_cod_receipt', 'stock_adjustment', 'supplier_payment', 'purchase', 'order_return', 'refund', 'refund_payout'];
const orderSources = ['order', 'order_reversal'];
const moreSources = ['shipment', 'courier_settlement_item', 'capital_entry', 'capital_reversal', 'payment'];

function sourceLabel(entry: LedgerEntry, t: (key: MessageKey, vars?: Record<string, string | number>) => string): string {
    if (entry.order_number && orderSources.includes(entry.source_type)) {
        return t(entry.source_type === 'order' ? 'finUi.led.fromOrder' : 'finUi.led.fromOrderReversal', { number: entry.order_number });
    }
    if (moreSources.includes(entry.source_type)) return t(`finUi.led.source.${entry.source_type}` as MessageKey, { id: entry.source_id });
    return knownSources.includes(entry.source_type)
        ? t(`finLedger.source.${entry.source_type}` as MessageKey, { id: entry.source_id })
        : t('finLedger.source.other', { type: entry.source_type, id: entry.source_id });
}

/**
 * The ledger (Figma B · 32 Ledger 424:73059). It leads with what couriers still owe — "Money still
 * with couriers": owed, delivered sales (revenue, on delivery), cash received (on remittance) and
 * how long it has been owed, every figure the server's receivables (rule 10) — then the append-only
 * ledger (rule 8): what happened, where it came from (an order by its number), whether it counts
 * in profit and/or cash, money in and money out. Read-only; "Record COD received" stays for
 * one-offs, with an Idempotency-Key.
 */
export function LedgerPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const reconcile = can(me, 'finance.reconcile_cod');
    const [params, setParams] = useSearchParams();
    const rangeParam = params.get('range');
    const range: Range = ranges.includes(rangeParam as Range) ? (rangeParam as Range) : '7';
    const kindParam = params.get('kind') ?? '';
    const kind: Kind = kinds.includes(kindParam as Kind) ? (kindParam as Kind) : '';
    const typeParam = params.get('type');
    const today = dhakaDate(0);

    const filters: LedgerFilters = useMemo(() => {
        // An exact Dhaka-date window (the P&L's drill-down links), overriding the range select.
        const isDay = (value: string | null) => /^\d{4}-\d{2}-\d{2}$/.test(value ?? '');
        const span = isDay(params.get('from')) && isDay(params.get('to')) ? { from: params.get('from')!, to: params.get('to')! } : null;
        return {
            type: ledgerTypes.includes(typeParam as LedgerType) ? (typeParam as LedgerType) : '',
            recognised: kind === 'recognised' ? 'true' : '',
            cash: kind === 'cash' ? 'true' : kind === 'noncash' ? 'false' : '',
            from: span ? span.from : range === 'all' ? '' : dhakaDate(Number(range) - 1),
            to: span ? span.to : range === 'all' ? '' : today,
            source: /^[a-z_]+$/.test(params.get('source') ?? '') ? params.get('source')! : '',
        };
    }, [params, kind, range, today, typeParam]);
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
                if (key === 'range') {
                    next.delete('from');
                    next.delete('to');
                }
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
    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;
    const end = dataTable.th.replace('[:where(&)]:text-start', 'text-end');
    const rangeText = (value: Range): string => (value === 'all' ? t('expenses.range.all') : t(`expenses.range.${value}` as MessageKey, { range: rangeLabel(dhakaDate(Number(value) - 1), today) }));

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('finNav.crumb') }, { label: t('finNav.finance') }]}
                title={t('finLedger.title')}
                subtitle={t('finUi.led.subtitle')}
                actions={
                    reconcile && (
                        <Button
                            variant="outline"
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
                    )
                }
            />
            <FinanceTabs />

            {receivables.isError ? (
                <QueryError error={receivables.error} onRetry={() => void receivables.refetch()} />
            ) : (
                r && (
                    <section aria-labelledby="cod-receivables" className="flex flex-col gap-4">
                        <div className="flex flex-col gap-2">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <h2 id="cod-receivables" className="t-admin-section text-fg-primary">
                                    {t('finUi.led.owed')}
                                </h2>
                                <p className="t-admin-caption tabular text-fg-secondary">{t('finUi.led.asOf', { date: shortDate(r.as_of, locale) })}</p>
                            </div>
                            <p className="t-admin-body text-fg-secondary">{t('finUi.led.owedIntro')}</p>
                        </div>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <KpiCard icon={MopedIcon} tone="warning" label={t('finUi.led.stat.owed')} value={<KpiMoney amount={r.outstanding} />} hint={t('finUi.led.stat.owedHint', { count: formatCount(r.open_orders) })} />
                            <KpiCard icon={TrendUpIcon} tone="brand" label={t('finUi.led.stat.delivered')} value={<KpiMoney amount={r.recognised} />} hint={t('finUi.led.stat.deliveredHint')} />
                            <KpiCard icon={WalletIcon} tone="info" label={t('finUi.led.stat.cash')} value={<KpiMoney amount={r.received} />} hint={t('finUi.led.stat.cashHint')} />
                            <div className="flex min-w-0 flex-col gap-3 rounded-md border border-line-default bg-surface p-4 shadow-e1">
                                <span className="flex items-center justify-between gap-2">
                                    <span className="min-w-0 truncate t-admin-overline text-fg-secondary">{t('finUi.led.stat.aging')}</span>
                                    <span aria-hidden className="grid size-8 shrink-0 place-items-center rounded-sm bg-danger-subtle text-feedback-danger">
                                        <ClockCounterClockwiseIcon size={16} weight="fill" />
                                    </span>
                                </span>
                                <dl className="grid grid-cols-2 gap-x-3 gap-y-2.5">
                                    {agingBuckets.map((bucket) => (
                                        <div key={bucket} className="flex flex-col">
                                            <dt className="t-admin-caption text-fg-secondary">{t(`finUi.led.age.${bucket}` as MessageKey)}</dt>
                                            <dd className={`t-admin-num ${bucket === '31_plus' && r.aging[bucket] !== '0.00' ? 'text-feedback-danger' : 'text-fg-primary'}`}>
                                                <TakaFixed amount={r.aging[bucket]} />
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                        </div>
                    </section>
                )
            )}

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <label className="flex">
                        <span className="sr-only">{t('finLedger.filter.type')}</span>
                        <Select value={filters.type} onChange={(e) => setParam('type', e.target.value)} className="w-50">
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
                        <Select value={kind} onChange={(e) => setParam('kind', e.target.value)} className="w-50">
                            {kinds.map((value) => (
                                <option key={value || 'all'} value={value}>
                                    {t(`finUi.led.kind.${value || 'all'}` as MessageKey)}
                                </option>
                            ))}
                        </Select>
                    </label>
                    <label className="relative flex w-full sm:w-60">
                        <span className="sr-only">{t('expenses.range')}</span>
                        <Select className="w-full" value={range} onChange={(e) => setParam('range', e.target.value === '7' ? '' : e.target.value)}>
                            {ranges.map((value) => (
                                <option key={value} value={value}>
                                    {rangeText(value)}
                                </option>
                            ))}
                        </Select>
                    </label>
                </div>
                <p className="t-admin-caption text-fg-secondary">{t('finUi.led.caption')}</p>
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
                            <tr className="border-b border-line-default">
                                <th scope="col" className={`${dataTable.th} w-23`}>{t('finLedger.col.date')}</th>
                                <th scope="col" className={dataTable.th}>{t('finUi.led.col.what')}</th>
                                <th scope="col" className={`${dataTable.th} w-52`}>{t('finUi.led.col.from')}</th>
                                <th scope="col" className={`${dataTable.th} w-37`}>{t('finUi.led.col.counts')}</th>
                                <th scope="col" className={`${end} w-37`}>{t('finUi.led.col.in')}</th>
                                <th scope="col" className={`${end} w-37`}>{t('finUi.led.col.out')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((entry) => {
                                const fromOrder = orderSources.includes(entry.source_type);
                                // The server's expense descriptions already lead with the category name; don't print it twice.
                                const category = entry.category_id !== null && !(entry.description ?? '').startsWith(entry.category_name ?? '\u0000') ? ((locale === 'bn' ? entry.category_name_bn : null) ?? entry.category_name) : null;
                                return (
                                    <tr key={entry.id} className={`${dataTable.row} max-lg:flex max-lg:flex-wrap max-lg:gap-x-3 max-lg:gap-y-1 max-lg:p-3`}>
                                        <td className={`${cell} t-admin-body tabular whitespace-nowrap`}>{shortDate(entry.occurred_on, locale)}</td>
                                        <td className={`${cell} max-lg:basis-full`}>
                                            <span className="flex min-w-0 flex-col gap-0.5">
                                                <span className="t-admin-body-strong text-fg-primary">{t(ledgerTypeLabel(entry.type))}</span>
                                                {(category || entry.description) && (
                                                    <span className="line-clamp-2 t-admin-caption text-fg-secondary">{[category, entry.description].filter(Boolean).join(' · ')}</span>
                                                )}
                                            </span>
                                        </td>
                                        <td className={`${cell} t-admin-mono-sm whitespace-nowrap`}>
                                            {fromOrder ? (
                                                <Link to={`/orders/${entry.source_id}`} className="text-fg-link hover:underline">
                                                    {sourceLabel(entry, t)}
                                                </Link>
                                            ) : (
                                                <span className="text-fg-link">{sourceLabel(entry, t)}</span>
                                            )}
                                        </td>
                                        <td className={cell}>
                                            <span className="flex flex-wrap gap-1.5">
                                                {entry.is_recognised_revenue && <StatusChip showIcon={false} label={t('finUi.led.chip.profit')} />}
                                                {entry.is_cash_movement && <StatusChip showIcon={false} label={t('finUi.led.chip.cash')} />}
                                                {!entry.is_recognised_revenue && !entry.is_cash_movement && <span className="t-admin-body text-fg-tertiary">—</span>}
                                            </span>
                                        </td>
                                        <td className={`${cell} t-admin-num lg:text-end`}>
                                            {entry.direction === 'in' ? (
                                                <span className="text-feedback-success">
                                                    <TakaFixed amount={entry.amount} />
                                                </span>
                                            ) : (
                                                <span aria-hidden className="max-lg:hidden t-admin-body text-fg-tertiary">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className={`${cell} t-admin-num lg:text-end`}>
                                            {entry.direction === 'out' ? (
                                                <TakaFixed amount={entry.amount} />
                                            ) : (
                                                <span aria-hidden className="max-lg:hidden t-admin-body text-fg-tertiary">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                    <DataTableFooter summary={t('finUi.led.showing', { count: formatCount(rows.length) })}>
                        {ledger.hasNextPage && (
                            <Button size="sm" variant="ghost" disabled={ledger.isFetchingNextPage} onClick={() => void ledger.fetchNextPage()}>
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
