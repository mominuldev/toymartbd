import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router';
import { ArrowsClockwiseIcon, CalendarBlankIcon, PaperclipIcon, PlusIcon } from '@phosphor-icons/react';
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
import { ExpenseForm } from './ExpenseForm';
import { FinanceTabs, FormDialog, RowMenu, RowMenuItem, StatCard, TAKA, Taka, dhakaDate, formatCount, formatMoney, methodLabel, monthName, rangeLabel, shortDate, statusLabel } from './parts';

const ranges = ['7', '30', '90', 'all'] as const;
type Range = (typeof ranges)[number];

const statusTone: Record<ExpenseStatus, StatusTone> = { pending: 'warning', approved: 'success', rejected: 'danger', void: 'neutral' };

type Pending = { expense: Expense; action: Exclude<ExpenseAction, 'approve'> } | null;

/**
 * Expenses (Figma B · 32 Expenses 241:47706; Theme A 222:13676): ContentHeader with the date range,
 * Recurring and Add expense; four StatCards computed by the server; the expense DataTable
 * (date, category tag, description, paid by, amount, receipt; a non-approved state is a chip under
 * the description, secondary actions sit in a row menu) beside the Add expense
 * card. Approve / reject / void / pay / delete per row, each guarded by the server.
 * Filters live in the URL (`?range=30&status=pending&recurring=true`).
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
    const [editing, setEditing] = useState<Expense | undefined>();
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
                    if (editing?.id === expense.id) setEditing(undefined);
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
    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('finNav.crumb') }, { label: t('finNav.finance') }, { label: t('expenses.title') }]}
                title={t('expenses.title')}
                subtitle={t('expenses.subtitle')}
                actions={
                    <>
                        <label className="relative flex w-full sm:w-65">
                            <span className="sr-only">{t('expenses.range')}</span>
                            <CalendarBlankIcon size={16} aria-hidden className="pointer-events-none absolute start-3 top-1/2 z-10 -translate-y-1/2 text-fg-secondary" />
                            <Select className="w-full [&_select]:h-9 [&_select]:ps-9" value={range} onChange={(e) => setParam('range', e.target.value === '7' ? '' : e.target.value)}>
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
                            <Button
                                onClick={() => {
                                    setEditing(undefined);
                                    document.getElementById('expense-form')?.querySelector('input')?.focus();
                                }}
                            >
                                <PlusIcon size={16} aria-hidden />
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
                        <StatCard
                            label={t('expenses.stat.period')}
                            value={
                                <span className="t-admin-currency-num-lg">
                                    {TAKA}
                                    {formatMoney(s.period_total)}
                                </span>
                            }
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
                        <StatCard
                            label={t('expenses.stat.monthToDate', { month: monthName(today, locale) })}
                            value={
                                <span className="t-admin-currency-num-lg">
                                    {TAKA}
                                    {formatMoney(s.month_to_date)}
                                </span>
                            }
                            hint={s.pending_count > 0 ? t('expenses.stat.pending', { count: formatCount(s.pending_count) }) : t('expenses.stat.pendingNone')}
                        />
                        <StatCard
                            label={t('expenses.stat.recurring')}
                            value={
                                <span className="t-admin-currency-num-lg">
                                    {TAKA}
                                    {formatMoney(s.recurring_monthly)}
                                </span>
                            }
                            hint={t('expenses.stat.recurringHint')}
                        />
                        <StatCard
                            label={t('expenses.stat.adRatio')}
                            value={<span className="t-admin-num-lg">{s.ad_spend_ratio === null ? '—' : `${formatMoney(s.ad_spend_ratio)}%`}</span>}
                            hint={s.ad_spend_ratio === null ? t('expenses.stat.adRatioNone') : t('expenses.stat.adRatioHint')}
                        />
                    </div>
                )
            )}

            <div className="flex flex-col gap-6 xl:flex-row xl:items-start">
                <div className="flex min-w-0 flex-1 flex-col gap-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <SearchField label={t('expenses.filter.search')} placeholder={t('expenses.filter.search')} defaultValue={filters.search} onChange={(e) => setParam('q', e.target.value)} className="w-full sm:w-72" />
                        <label className="flex">
                            <span className="sr-only">{t('expenses.filter.status')}</span>
                            <Select size="sm" value={filters.status} onChange={(e) => setParam('status', e.target.value)} className="w-44">
                                <option value="">{t('expenses.filter.statusAll')}</option>
                                {expenseStatuses.map((value) => (
                                    <option key={value} value={value}>
                                        {t(statusLabel(value))}
                                    </option>
                                ))}
                            </Select>
                        </label>
                        <label className="flex">
                            <span className="sr-only">{t('expenses.filter.category')}</span>
                            <Select size="sm" value={filters.category} onChange={(e) => setParam('category', e.target.value)} className="w-48">
                                <option value="">{t('expenses.filter.categoryAll')}</option>
                                {(categories.data ?? []).map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.parent_id ? `— ${categoryName(c)}` : categoryName(c)}
                                    </option>
                                ))}
                            </Select>
                        </label>
                    </div>

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
                            <table className={dataTable.table}>
                                <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                                    <tr className="border-b border-line-strong">
                                        <th scope="col" className={dataTable.th}>{t('expenses.col.date')}</th>
                                        <th scope="col" className={dataTable.th}>{t('expenses.col.category')}</th>
                                        <th scope="col" className={dataTable.th}>{t('expenses.col.description')}</th>
                                        <th scope="col" className={dataTable.th}>{t('expenses.col.paidBy')}</th>
                                        <th scope="col" className={`${dataTable.th.replace('text-start', 'text-end')}`}>{t('expenses.col.amount')}</th>
                                        <th scope="col" className={dataTable.th}>{t('expenses.col.receipt')}</th>
                                        <th scope="col" className={dataTable.th}>
                                            <span className="sr-only">{t('expenses.col.actions')}</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((e) => (
                                        <tr key={e.id} className={`${dataTable.row} max-lg:flex max-lg:flex-wrap max-lg:gap-x-3 max-lg:gap-y-1 max-lg:p-3`}>
                                            <td className={`${cell} t-admin-body-strong tabular whitespace-nowrap`}>{shortDate(e.expense_date, locale)}</td>
                                            <td className={cell}>
                                                <span className="inline-flex h-6 items-center rounded-xs border border-line-default bg-subtle px-2 t-admin-caption-strong whitespace-nowrap text-fg-primary">{categoryName(e.category)}</span>
                                            </td>
                                            <td className={`${cell} t-admin-body max-lg:basis-full lg:max-w-60`}>
                                                <span className="line-clamp-2">{[e.vendor, e.description].filter(Boolean).join(' · ') || '—'}</span>
                                                {e.is_recurring && e.recurrence && (
                                                    <span className="block t-admin-caption text-fg-tertiary">{t('expenses.recurringTag', { recurrence: t(`expenses.recurrence.${e.recurrence}` as MessageKey) })}</span>
                                                )}
                                                {/* Figma draws approved rows only; any other state is flagged inline instead of a status column. */}
                                                {e.status !== 'approved' && (
                                                    <span className="mt-1 block">
                                                        <StatusChip label={t(statusLabel(e.status))} tone={statusTone[e.status]} />
                                                    </span>
                                                )}
                                            </td>
                                            <td className={`${cell} t-admin-body text-fg-secondary`}>{e.payment_method ? t(methodLabel(e.payment_method)) : <span className="text-fg-tertiary">{t('expenses.unpaid')}</span>}</td>
                                            <td className={`${cell} t-admin-num tabular whitespace-nowrap lg:text-end`}>
                                                <Taka amount={e.amount} />
                                            </td>
                                            <td className={cell}>
                                                {e.receipt ? (
                                                    <a href={receiptUrl(e.id)} aria-label={t('expenses.receipt.open', { name: e.receipt.name })} className="inline-grid size-8 place-items-center rounded-xs text-fg-secondary hover:bg-subtle hover:text-fg-primary">
                                                        <PaperclipIcon size={16} aria-hidden />
                                                    </a>
                                                ) : (
                                                    <span aria-label={t('expenses.receipt.none')} className="t-admin-body text-fg-tertiary">
                                                        —
                                                    </span>
                                                )}
                                            </td>
                                            <td className={`${cell} max-lg:ms-auto`}>
                                                <div className="flex items-center justify-end gap-1">
                                                    {e.can.approve && (
                                                        <Button size="sm" variant="secondary" disabled={action.isPending} onClick={() => run(e, 'approve')}>
                                                            {t('expenses.action.approve')}
                                                        </Button>
                                                    )}
                                                    {(e.can.reject || e.can.update || e.can.pay || e.can.void || e.can.delete) && (
                                                        <RowMenu label={t('expenses.col.actions')}>
                                                            {e.can.reject && <RowMenuItem onClick={() => openDialog(e, 'reject')}>{t('expenses.action.reject')}</RowMenuItem>}
                                                            {e.can.update && <RowMenuItem onClick={() => setEditing(e)}>{t('expenses.action.edit')}</RowMenuItem>}
                                                            {e.can.pay && <RowMenuItem onClick={() => openDialog(e, 'pay')}>{t('expenses.action.pay')}</RowMenuItem>}
                                                            {e.can.void && <RowMenuItem onClick={() => openDialog(e, 'void')}>{t('expenses.action.void')}</RowMenuItem>}
                                                            {e.can.delete && <RowMenuItem onClick={() => openDialog(e, 'delete')}>{t('expenses.action.delete')}</RowMenuItem>}
                                                        </RowMenu>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <DataTableFooter summary={t('expenses.showing', { count: formatCount(rows.length) })}>
                                {expenses.hasNextPage && (
                                    <Button size="sm" variant="outline" disabled={expenses.isFetchingNextPage} onClick={() => void expenses.fetchNextPage()}>
                                        {t('common.loadMore')}
                                    </Button>
                                )}
                            </DataTableFooter>
                        </div>
                    )}
                </div>

                {manage ? (
                    <div id="expense-form" className="w-full xl:w-90 xl:shrink-0">
                        <ExpenseForm categories={categories.data ?? []} expense={editing} onDone={() => setEditing(undefined)} />
                    </div>
                ) : (
                    <p className="t-admin-caption text-fg-tertiary xl:w-90">{t('expenses.form.readOnly')}</p>
                )}
            </div>

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
