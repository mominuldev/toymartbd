import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router';
import { ArrowsClockwiseIcon, HourglassIcon, MegaphoneIcon, PaperclipIcon, PlusCircleIcon, ReceiptIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Field, inputClass } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { KpiCard } from '@/components/ui/KpiCard';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import {
    expenseStatuses,
    paymentMethods,
    receiptUrl,
    useExpenseAction,
    useExpenseCategories,
    useExpenseSummary,
    useExpenses,
    type Expense,
    type ExpenseAction,
    type ExpenseCategory,
    type ExpenseFilters,
    type ExpenseStatus,
    type PaymentMethod,
} from './api';
import { ExpenseDrawer } from './ExpenseForm';
import { FinanceTabs, FormDialog, KpiMoney, RowMenu, RowMenuItem, Taka, TakaFixed, dhakaDate, formatCount, formatMoney, methodLabel, monthName, rangeLabel, shortDate } from './parts';

const ranges = ['7', '30', '90', 'all'] as const;
type Range = (typeof ranges)[number];

type Pending = { expense: Expense; action: Exclude<ExpenseAction, 'approve'> } | null;

/** The status column (Figma B · 32 Expenses 422:72109): label + icon shape + tint, never colour alone. */
function expenseStatus(e: Expense): { label: MessageKey; tone: StatusTone } {
    if (e.status === 'pending') return { label: 'finUi.exp.status.pending', tone: 'warning' };
    if (e.status === 'rejected') return { label: 'finUi.exp.status.rejected', tone: 'danger' };
    if (e.status === 'void') return { label: 'finUi.exp.status.void', tone: 'neutral' };
    return e.paid_on === null ? { label: 'finUi.exp.status.unpaid', tone: 'info' } : { label: 'finUi.exp.status.approved', tone: 'success' };
}

/**
 * Expenses (Figma B · 32 Expenses 422:71449): ContentHeader with the date range, Recurring and
 * Add expense; four KpiCards the server computes (the "to date" card is a shortcut to what waits
 * for approval); search + status + category filters; the expense table — date, category with its
 * type, what it was for with the vendor (and a "Repeats monthly" chip), paid by with the paid
 * date, amount, the receipt file, the status chip and a row menu. Add / edit opens the drawer
 * (423:72036). Approve / reject / void / pay / delete per row, each guarded by the server (the
 * creator never approves their own expense). Filters live in the URL.
 */
export function ExpensesPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const manage = can(me, 'finance.manage_expenses');
    const [params, setParams] = useSearchParams();
    const rangeParam = params.get('range');
    const range: Range = ranges.includes(rangeParam as Range) ? (rangeParam as Range) : '7';
    const statusParam = params.get('status');
    const today = dhakaDate(0);
    const from = range === 'all' ? '' : dhakaDate(Number(range) - 1);
    const filters: ExpenseFilters = useMemo(
        () => ({
            status: expenseStatuses.includes(statusParam as ExpenseStatus) ? (statusParam as ExpenseStatus) : '',
            category: /^\d+$/.test(params.get('category') ?? '') ? params.get('category')! : '',
            from,
            to: range === 'all' ? '' : today,
            search: params.get('q') ?? '',
            recurring: params.get('recurring') === 'true' ? 'true' : '',
        }),
        [params, from, range, statusParam, today],
    );

    const expenses = useExpenses(filters);
    const summary = useExpenseSummary(from || '2000-01-01', today);
    const categories = useExpenseCategories();
    const action = useExpenseAction();
    const rows = useMemo(() => expenses.data?.pages.flatMap((page) => page.data) ?? [], [expenses.data]);
    const [drawer, setDrawer] = useState<{ open: boolean; expense?: Expense }>({ open: false });
    const [pending, setPending] = useState<Pending>(null);
    const [reason, setReason] = useState('');
    const [payMethod, setPayMethod] = useState<PaymentMethod>('cash');
    const [paidOn, setPaidOn] = useState(today);
    const [dialogError, setDialogError] = useState<string | undefined>();

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

    const rangeText = (value: Range): string => (value === 'all' ? t('expenses.range.all') : t(`expenses.range.${value}` as MessageKey, { range: rangeLabel(dhakaDate(Number(value) - 1), today) }));
    const categoryName = (c: ExpenseCategory) => (locale === 'bn' ? (c.name_bn ?? c.name) : c.name);

    const run = (expense: Expense, kind: ExpenseAction, extra: { reason?: string; pay?: { payment_method: PaymentMethod; paid_on: string } } = {}) =>
        action.mutate(
            { id: expense.id, action: kind, ...extra },
            {
                onSuccess: () => {
                    notify(t(({ approve: 'expenses.approved', reject: 'expenses.rejected', void: 'expenses.voided', pay: 'expenses.paid', delete: 'expenses.deleted' } as const)[kind]));
                    setPending(null);
                },
                onError: (error) => {
                    const message = error instanceof ApiError ? (error.fieldError('reason') ?? error.fieldError('paid_on') ?? error.problem.detail ?? error.problem.title) : t('error.title');
                    if (pending) setDialogError(message);
                    else notify(message, 'danger');
                },
            },
        );

    const openDialog = (expense: Expense, kind: Exclude<ExpenseAction, 'approve'>) => {
        setReason('');
        setPayMethod('cash');
        setPaidOn(today);
        setDialogError(undefined);
        setPending({ expense, action: kind });
    };

    const s = summary.data;
    const cell = `${dataTable.td} h-16 max-lg:h-auto max-lg:p-0`;
    const end = dataTable.th.replace('[:where(&)]:text-start', 'text-end');

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('finNav.crumb') }, { label: t('finNav.finance') }]}
                title={t('expenses.title')}
                subtitle={t('finUi.exp.subtitle')}
                actions={
                    <>
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
                        <Button variant="outline" aria-pressed={filters.recurring === 'true'} onClick={() => setParam('recurring', filters.recurring ? '' : 'true')}>
                            <ArrowsClockwiseIcon size={16} aria-hidden />
                            {t('expenses.recurring')}
                        </Button>
                        {manage && (
                            <Button onClick={() => setDrawer({ open: true })}>
                                <PlusCircleIcon size={16} aria-hidden />
                                {t('expenses.add')}
                            </Button>
                        )}
                    </>
                }
            />
            <FinanceTabs />

            {summary.isError ? (
                <QueryError error={summary.error} onRetry={() => void summary.refetch()} />
            ) : (
                s && (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <KpiCard
                            icon={ReceiptIcon}
                            tone="brand"
                            label={t('expenses.stat.period')}
                            value={<KpiMoney amount={s.period_total} />}
                            hint={
                                s.period_top.length === 0
                                    ? t('expenses.stat.periodNone')
                                    : s.period_top.map((c, i) => (
                                          <span key={c.category_id}>
                                              {i > 0 && ' · '}
                                              {locale === 'bn' ? (c.name_bn ?? c.name) : c.name} <Taka amount={c.amount} signClass="t-admin-currency-caption" />
                                          </span>
                                      ))
                            }
                        />
                        <KpiCard
                            icon={HourglassIcon}
                            tone="warning"
                            label={t('expenses.stat.monthToDate', { month: monthName(today, locale) })}
                            value={<KpiMoney amount={s.month_to_date} />}
                            hint={s.pending_count > 0 ? t('finUi.exp.stat.pending', { count: formatCount(s.pending_count) }) : t('expenses.stat.pendingNone')}
                            onClick={s.pending_count > 0 ? () => setParam('status', filters.status === 'pending' ? '' : 'pending') : undefined}
                            active={filters.status === 'pending'}
                        />
                        <KpiCard icon={ArrowsClockwiseIcon} tone="info" label={t('finUi.exp.stat.recurring')} value={<KpiMoney amount={s.recurring_monthly} />} hint={t('expenses.stat.recurringHint')} />
                        <KpiCard
                            icon={MegaphoneIcon}
                            tone="neutral"
                            label={t('finUi.exp.stat.adRatio')}
                            value={<span className="t-admin-num-lg">{s.ad_spend_ratio === null ? '—' : `${formatMoney(s.ad_spend_ratio)}%`}</span>}
                            hint={s.ad_spend_ratio === null ? t('expenses.stat.adRatioNone') : t('finUi.exp.stat.adRatioHint')}
                        />
                    </div>
                )
            )}

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <SearchField label={t('finUi.exp.search')} placeholder={t('finUi.exp.search')} defaultValue={filters.search} onChange={(e) => setParam('q', e.target.value)} className="w-full sm:w-96" />
                    <label className="flex">
                        <span className="sr-only">{t('expenses.filter.status')}</span>
                        <Select size="sm" value={filters.status} onChange={(e) => setParam('status', e.target.value)} className="w-45">
                            <option value="">{t('expenses.filter.statusAll')}</option>
                            {expenseStatuses.map((value) => (
                                <option key={value} value={value}>
                                    {t(`finUi.exp.status.${value}` as MessageKey)}
                                </option>
                            ))}
                        </Select>
                    </label>
                    <label className="flex">
                        <span className="sr-only">{t('expenses.filter.category')}</span>
                        <Select size="sm" value={filters.category} onChange={(e) => setParam('category', e.target.value)} className="w-50">
                            <option value="">{t('expenses.filter.categoryAll')}</option>
                            {(categories.data ?? []).map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.parent_id ? `— ${categoryName(c)}` : categoryName(c)}
                                </option>
                            ))}
                        </Select>
                    </label>
                </div>
                <p className="t-admin-caption text-fg-tertiary">{t('finUi.exp.unpaidHint')}</p>
            </div>

            {!manage && <p className="t-admin-caption text-fg-tertiary">{t('finUi.exp.readOnly')}</p>}

            {expenses.isError ? (
                <QueryError error={expenses.error} onRetry={() => void expenses.refetch()} />
            ) : expenses.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : rows.length === 0 ? (
                <EmptyState title={t('expenses.empty')} body={t('expenses.emptyBody')} />
            ) : (
                <div className={dataTable.card}>
                    <table className={`${dataTable.table} lg:table-fixed`}>
                        <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                            <tr className="border-b border-line-default">
                                <th scope="col" className={`${dataTable.th} w-23`}>{t('expenses.col.date')}</th>
                                <th scope="col" className={`${dataTable.th} w-44`}>{t('expenses.col.category')}</th>
                                <th scope="col" className={dataTable.th}>{t('finUi.exp.col.whatFor')}</th>
                                <th scope="col" className={`${dataTable.th} w-32`}>{t('expenses.col.paidBy')}</th>
                                <th scope="col" className={`${end} w-31`}>{t('expenses.col.amount')}</th>
                                <th scope="col" className={`${dataTable.th} w-32`}>{t('expenses.col.receipt')}</th>
                                <th scope="col" className={`${dataTable.th} w-42`}>{t('expenses.col.status')}</th>
                                <th scope="col" className={`${dataTable.th} w-14`}>
                                    <span className="sr-only">{t('expenses.col.actions')}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((e) => {
                                const status = expenseStatus(e);
                                const dim = e.status === 'rejected' || e.status === 'void';
                                const hasActions = e.can.approve || e.can.reject || e.can.update || e.can.pay || e.can.void || e.can.delete;
                                return (
                                    <tr key={e.id} className={`${dataTable.row} max-lg:flex max-lg:flex-wrap max-lg:gap-x-3 max-lg:gap-y-1 max-lg:p-3`}>
                                        <td className={`${cell} t-admin-body tabular whitespace-nowrap`}>{shortDate(e.expense_date, locale)}</td>
                                        <td className={cell}>
                                            <span className="flex min-w-0 flex-col gap-0.5">
                                                <span className="truncate t-admin-body-strong text-fg-primary">{categoryName(e.category)}</span>
                                                <span className="t-admin-caption text-fg-secondary">{t(`finCat.type.${e.category.type}` as MessageKey)}</span>
                                            </span>
                                        </td>
                                        <td className={`${cell} max-lg:basis-full`}>
                                            <span className="flex min-w-0 flex-col gap-0.5">
                                                <span className="line-clamp-2 t-admin-body text-fg-primary">{e.description ?? e.vendor ?? '—'}</span>
                                                {((e.description && e.vendor) || (e.is_recurring && e.recurrence)) && (
                                                    <span className="flex flex-wrap items-center gap-1.5">
                                                        {e.description && e.vendor && <span className="truncate t-admin-caption text-fg-secondary">{e.vendor}</span>}
                                                        {e.is_recurring && e.recurrence && <StatusChip showIcon={false} label={t(`finUi.exp.repeats.${e.recurrence}` as MessageKey)} />}
                                                    </span>
                                                )}
                                            </span>
                                        </td>
                                        <td className={cell}>
                                            {e.paid_on === null || e.payment_method === null ? (
                                                <span className="t-admin-body text-fg-tertiary">{t('finUi.exp.notPaid')}</span>
                                            ) : (
                                                <span className="flex flex-col gap-0.5 whitespace-nowrap">
                                                    <span className="t-admin-body text-fg-primary">{t(methodLabel(e.payment_method))}</span>
                                                    <span className="t-admin-caption tabular text-fg-secondary">{t('finUi.exp.paidOn', { date: shortDate(e.paid_on, locale) })}</span>
                                                </span>
                                            )}
                                        </td>
                                        <td className={`${cell} t-admin-num lg:text-end`}>
                                            <span className={dim ? 'text-fg-tertiary' : ''}>
                                                <TakaFixed amount={e.amount} />
                                            </span>
                                        </td>
                                        <td className={cell}>
                                            {e.receipt ? (
                                                <a
                                                    href={receiptUrl(e.id)}
                                                    aria-label={t('expenses.receipt.open', { name: e.receipt.name })}
                                                    className="inline-flex max-w-full items-center gap-1.5 rounded-xs t-admin-caption text-fg-link hover:underline"
                                                >
                                                    <PaperclipIcon size={16} aria-hidden className="shrink-0" />
                                                    <span className="truncate">{e.receipt.name}</span>
                                                </a>
                                            ) : (
                                                <span aria-label={t('expenses.receipt.none')} className="t-admin-body text-fg-tertiary">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className={cell}>
                                            <StatusChip label={t(status.label)} tone={status.tone} />
                                        </td>
                                        <td className={`${cell} max-lg:ms-auto`}>
                                            {hasActions && (
                                                <div className="flex justify-end">
                                                    <RowMenu label={t('expenses.col.actions')}>
                                                        {e.can.approve && (
                                                            <RowMenuItem disabled={action.isPending} onClick={() => run(e, 'approve')}>
                                                                {t('expenses.action.approve')}
                                                            </RowMenuItem>
                                                        )}
                                                        {e.can.reject && <RowMenuItem onClick={() => openDialog(e, 'reject')}>{t('expenses.action.reject')}</RowMenuItem>}
                                                        {e.can.update && <RowMenuItem onClick={() => setDrawer({ open: true, expense: e })}>{t('expenses.action.edit')}</RowMenuItem>}
                                                        {e.can.pay && <RowMenuItem onClick={() => openDialog(e, 'pay')}>{t('expenses.action.pay')}</RowMenuItem>}
                                                        {e.can.void && <RowMenuItem onClick={() => openDialog(e, 'void')}>{t('expenses.action.void')}</RowMenuItem>}
                                                        {e.can.delete && <RowMenuItem onClick={() => openDialog(e, 'delete')}>{t('expenses.action.delete')}</RowMenuItem>}
                                                    </RowMenu>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                    <DataTableFooter summary={t('finUi.exp.showing', { count: formatCount(rows.length) })}>
                        {expenses.hasNextPage && (
                            <Button size="sm" variant="ghost" disabled={expenses.isFetchingNextPage} onClick={() => void expenses.fetchNextPage()}>
                                {t('common.loadMore')}
                            </Button>
                        )}
                    </DataTableFooter>
                </div>
            )}

            {manage && <ExpenseDrawer open={drawer.open} categories={categories.data ?? []} expense={drawer.expense} onClose={() => setDrawer({ open: false })} />}

            <FormDialog
                open={pending !== null}
                title={pending ? t(({ reject: 'expenses.dialog.rejectTitle', void: 'expenses.dialog.voidTitle', pay: 'expenses.dialog.payTitle', delete: 'expenses.dialog.deleteTitle' } as const)[pending.action]) : ''}
                body={pending ? t(({ reject: 'expenses.dialog.rejectBody', void: 'expenses.dialog.voidBody', pay: 'expenses.dialog.payBody', delete: 'expenses.dialog.deleteBody' } as const)[pending.action]) : ''}
                submitLabel={pending ? t(({ reject: 'expenses.action.reject', void: 'expenses.action.void', pay: 'expenses.action.pay', delete: 'expenses.action.delete' } as const)[pending.action]) : ''}
                danger={pending?.action === 'void' || pending?.action === 'delete'}
                pending={action.isPending}
                onClose={() => setPending(null)}
                onSubmit={() => {
                    if (!pending) return;
                    if (pending.action === 'pay') run(pending.expense, 'pay', { pay: { payment_method: payMethod, paid_on: paidOn } });
                    else if (pending.action === 'delete') run(pending.expense, 'delete');
                    else run(pending.expense, pending.action, { reason });
                }}
            >
                {(pending?.action === 'reject' || pending?.action === 'void') && (
                    <Field label={t('expenses.dialog.reason')} error={dialogError} required>
                        {({ id, describedBy, invalid }) => (
                            <textarea id={id} rows={2} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} value={reason} onChange={(e) => setReason(e.target.value)} />
                        )}
                    </Field>
                )}
                {pending?.action === 'pay' && (
                    <>
                        <Field label={t('expenses.form.paidBy')}>
                            {({ id }) => (
                                <Select id={id} value={payMethod} onChange={(e) => setPayMethod(e.target.value as PaymentMethod)}>
                                    {paymentMethods.map((m) => (
                                        <option key={m} value={m}>
                                            {t(methodLabel(m))}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                        <Field label={t('expenses.dialog.paidOn')} error={dialogError}>
                            {({ id, describedBy, invalid }) => <Input id={id} type="date" max={today} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={paidOn} onChange={(e) => setPaidOn(e.target.value)} />}
                        </Field>
                    </>
                )}
                {pending?.action === 'delete' && dialogError && <p className="t-admin-caption text-fg-primary">{dialogError}</p>}
            </FormDialog>
        </section>
    );
}
