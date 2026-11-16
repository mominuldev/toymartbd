import { useMemo, useState } from 'react';
import { ArrowElbowDownRightIcon, PlusCircleIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { categoryLedgerTypes, categoryTypes, useDeactivateCategory, useExpenseCategoryList, useSaveCategory, type CategoryInput, type ExpenseCategory } from './api';
import { FinanceTabs, FormDialog, Notice, RowMenu, RowMenuItem, Toggle, formatCount, ledgerTypeLabel } from './parts';

type Draft = { name: string; name_bn: string; type: ExpenseCategory['type']; ledger_type: string; parent_id: string; is_fixed: boolean };
const blank: Draft = { name: '', name_bn: '', type: 'operating', ledger_type: '', parent_id: '', is_fixed: false };
const shows = ['all', 'active', 'off'] as const;
type Show = (typeof shows)[number];

/**
 * Expense categories (Figma B · 32 Expense categories 424:72294): the "Why categories matter"
 * notice; search + All / Active / Switched off over the short list the server returns whole; the
 * server's counts in the caption; the table — name with its Bangla name (a sub-category indented
 * under ↳), type, what it counts in the P&L as, a Fixed tag, how many expenses use it, the status
 * chip and a row menu (Edit, Switch off). A category in use is switched off, never deleted.
 */
export function ExpenseCategoriesPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const manage = can(me, 'finance.manage_expenses');
    const categories = useExpenseCategoryList();
    const [editing, setEditing] = useState<ExpenseCategory | null | 'new'>(null);
    const [draft, setDraft] = useState<Draft>(blank);
    const [errors, setErrors] = useState<Partial<Record<keyof Draft, string>>>({});
    const [search, setSearch] = useState('');
    const [show, setShow] = useState<Show>('all');
    const save = useSaveCategory(editing && editing !== 'new' ? editing.id : null);
    const deactivate = useDeactivateCategory();
    const list = useMemo(() => categories.data?.data ?? [], [categories.data]);
    const meta = categories.data?.meta;
    const name = (c: ExpenseCategory) => (locale === 'bn' ? (c.name_bn ?? c.name) : c.name);
    const second = (c: ExpenseCategory) => (locale === 'bn' ? (c.name_bn ? c.name : null) : c.name_bn);

    // A view over the whole list the server returned (a handful of rows; no money, no counts).
    const visible = useMemo(() => {
        const needle = search.trim().toLocaleLowerCase();
        return list.filter(
            (c) =>
                (show === 'all' || (show === 'active' ? c.is_active : !c.is_active)) &&
                (needle === '' || c.name.toLocaleLowerCase().includes(needle) || (c.name_bn ?? '').includes(search.trim())),
        );
    }, [list, search, show]);

    const open = (category: ExpenseCategory | 'new') => {
        setErrors({});
        setDraft(
            category === 'new'
                ? blank
                : { name: category.name, name_bn: category.name_bn ?? '', type: category.type, ledger_type: category.ledger_type ?? '', parent_id: category.parent_id ? String(category.parent_id) : '', is_fixed: category.is_fixed },
        );
        setEditing(category);
    };

    const submit = () => {
        const input: CategoryInput = {
            name: draft.name.trim(),
            name_bn: draft.name_bn.trim() || null,
            type: draft.type,
            ledger_type: (draft.ledger_type || null) as CategoryInput['ledger_type'],
            parent_id: draft.parent_id ? Number(draft.parent_id) : null,
            is_fixed: draft.is_fixed,
        };
        save.mutate(input, {
            onSuccess: () => {
                notify(t('finCat.saved'));
                setEditing(null);
            },
            onError: (error) => {
                if (error instanceof ApiError && error.status === 422) {
                    setErrors({ name: error.fieldError('name'), name_bn: error.fieldError('name_bn'), type: error.fieldError('type'), ledger_type: error.fieldError('ledger_type'), parent_id: error.fieldError('parent_id') });
                } else notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
            },
        });
    };

    const cell = `${dataTable.td} h-16 max-lg:h-auto max-lg:p-0`;
    const end = dataTable.th.replace('[:where(&)]:text-start', 'text-end');

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('finNav.crumb') }, { label: t('finNav.finance') }, { label: t('finUi.crumb.expenses'), to: '/finance/expenses' }]}
                title={t('finCat.title')}
                subtitle={t('finUi.cat.subtitle')}
                actions={
                    manage && (
                        <Button onClick={() => open('new')}>
                            <PlusCircleIcon size={16} aria-hidden />
                            {t('finCat.add')}
                        </Button>
                    )
                }
            />
            <FinanceTabs />

            <Notice title={t('finUi.cat.noticeTitle')}>{t('finUi.cat.noticeBody')}</Notice>

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <SearchField label={t('finUi.cat.search')} placeholder={t('finUi.cat.search')} value={search} onChange={(e) => setSearch(e.target.value)} className="w-full sm:w-80" />
                    <SegmentedControl label={t('finUi.cat.show')} options={shows.map((value) => ({ value, label: t(`finUi.cat.show.${value}` as MessageKey) }))} value={show} onChange={setShow} className="h-9" />
                </div>
                {meta && (
                    <p className="t-admin-caption tabular text-fg-secondary">
                        {t('finUi.cat.caption', { categories: formatCount(meta.category_count), expenses: formatCount(meta.expense_count) })}
                    </p>
                )}
            </div>

            {categories.isError ? (
                <QueryError error={categories.error} onRetry={() => void categories.refetch()} />
            ) : categories.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : list.length === 0 ? (
                <EmptyState title={t('finCat.title')} body={t('finUi.cat.subtitle')} />
            ) : visible.length === 0 ? (
                <EmptyState title={t('finUi.cat.noMatch')} body={t('finUi.cat.noMatchBody')} />
            ) : (
                <div className={dataTable.card}>
                    <table className={dataTable.table}>
                        <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                            <tr className="border-b border-line-default">
                                <th scope="col" className={dataTable.th}>{t('finCat.col.name')}</th>
                                <th scope="col" className={`${dataTable.th} w-37`}>{t('finCat.col.type')}</th>
                                <th scope="col" className={`${dataTable.th} w-52`}>{t('finUi.cat.col.countsAs')}</th>
                                <th scope="col" className={`${dataTable.th} w-37`}>{t('finUi.cat.col.fixed')}</th>
                                <th scope="col" className={`${end} w-23`}>{t('finCat.col.expenses')}</th>
                                <th scope="col" className={`${dataTable.th} w-35`}>{t('finCat.col.status')}</th>
                                <th scope="col" className={`${dataTable.th} w-14`}>
                                    <span className="sr-only">{t('common.actions')}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {visible.map((c) => {
                                const muted = c.is_active ? 'text-fg-primary' : 'text-fg-tertiary';
                                return (
                                    <tr key={c.id} className={`${dataTable.row} max-lg:flex max-lg:flex-wrap max-lg:gap-x-3 max-lg:gap-y-1 max-lg:p-3`}>
                                        <td className={`${cell} max-lg:basis-full`}>
                                            <span className={`flex items-start gap-1.5 ${c.parent_id ? 'ps-5' : ''}`}>
                                                {c.parent_id && <ArrowElbowDownRightIcon size={14} aria-hidden className="mt-1 shrink-0 text-fg-tertiary" />}
                                                <span className="flex min-w-0 flex-col gap-0.5">
                                                    <span className={`t-admin-body-strong ${muted}`}>{name(c)}</span>
                                                    {second(c) && (
                                                        <span lang={locale === 'bn' ? 'en' : 'bn'} className="t-admin-caption text-fg-secondary">
                                                            {second(c)}
                                                        </span>
                                                    )}
                                                </span>
                                            </span>
                                        </td>
                                        <td className={`${cell} t-admin-body`}>
                                            <span className={muted}>{t(`finCat.type.${c.type}` as MessageKey)}</span>
                                        </td>
                                        <td className={`${cell} t-admin-body`}>
                                            <span className={muted}>{t(ledgerTypeLabel(c.resolved_ledger_type as never))}</span>
                                        </td>
                                        <td className={cell}>{c.is_fixed ? <StatusChip showIcon={false} label={t('finUi.cat.fixed')} /> : <span className="t-admin-body text-fg-tertiary">—</span>}</td>
                                        <td className={`${cell} t-admin-num tabular lg:text-end`}>
                                            <span className={muted}>{formatCount(c.expenses_count)}</span>
                                        </td>
                                        <td className={cell}>
                                            <StatusChip label={t(c.is_active ? 'finUi.cat.status.active' : 'finUi.cat.status.off')} tone={c.is_active ? 'success' : 'neutral'} />
                                        </td>
                                        <td className={`${cell} max-lg:ms-auto`}>
                                            {manage && (
                                                <div className="flex justify-end">
                                                    <RowMenu label={t('finUi.actionsFor', { name: name(c) })}>
                                                        <RowMenuItem onClick={() => open(c)}>{t('common.edit')}</RowMenuItem>
                                                        {c.is_active && (
                                                            <RowMenuItem disabled={deactivate.isPending} onClick={() => deactivate.mutate(c.id, { onSuccess: () => notify(t('finUi.cat.switchedOff')) })}>
                                                                {t('finUi.cat.switchOff')}
                                                            </RowMenuItem>
                                                        )}
                                                    </RowMenu>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            <FormDialog
                open={editing !== null}
                title={editing === 'new' ? t('finCat.add') : t('common.edit')}
                body={t('finUi.cat.subtitle')}
                submitLabel={t('common.save')}
                pending={save.isPending}
                onClose={() => setEditing(null)}
                onSubmit={submit}
            >
                <Field label={t('finCat.field.name')} error={errors.name} required>
                    {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} />}
                </Field>
                <Field label={t('finCat.field.nameBn')} error={errors.name_bn} optional>
                    {({ id, describedBy, invalid }) => <Input id={id} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} value={draft.name_bn} onChange={(e) => setDraft({ ...draft, name_bn: e.target.value })} />}
                </Field>
                <div className="grid gap-3.5 sm:grid-cols-2">
                    <Field label={t('finCat.field.type')} error={errors.type}>
                        {({ id }) => (
                            <Select id={id} value={draft.type} onChange={(e) => setDraft({ ...draft, type: e.target.value as Draft['type'] })}>
                                {categoryTypes.map((type) => (
                                    <option key={type} value={type}>
                                        {t(`finCat.type.${type}` as MessageKey)}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                    <Field label={t('finUi.cat.col.countsAs')} error={errors.ledger_type}>
                        {({ id }) => (
                            <Select id={id} value={draft.ledger_type} onChange={(e) => setDraft({ ...draft, ledger_type: e.target.value })}>
                                <option value="">{t('finCat.field.ledgerAuto')}</option>
                                {categoryLedgerTypes.map((type) => (
                                    <option key={type} value={type}>
                                        {t(ledgerTypeLabel(type))}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                </div>
                <Field label={t('finCat.field.parent')} error={errors.parent_id}>
                    {({ id }) => (
                        <Select id={id} value={draft.parent_id} onChange={(e) => setDraft({ ...draft, parent_id: e.target.value })}>
                            <option value="">{t('finCat.field.parentNone')}</option>
                            {list
                                .filter((c) => c.parent_id === null && (editing === 'new' || editing === null || c.id !== editing.id))
                                .map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {name(c)}
                                    </option>
                                ))}
                        </Select>
                    )}
                </Field>
                <label className="flex items-center gap-2.5">
                    <Toggle checked={draft.is_fixed} onChange={(next) => setDraft({ ...draft, is_fixed: next })} label={t('finCat.field.fixed')} />
                    <span className="t-admin-body text-fg-primary">{t('finCat.field.fixed')}</span>
                </label>
            </FormDialog>
        </section>
    );
}
