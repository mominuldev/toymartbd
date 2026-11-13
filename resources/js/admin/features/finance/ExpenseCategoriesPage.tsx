import { useState } from 'react';
import { PlusIcon } from '@phosphor-icons/react';
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
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { categoryLedgerTypes, categoryTypes, useDeactivateCategory, useExpenseCategories, useSaveCategory, type CategoryInput, type ExpenseCategory } from './api';
import { FinanceTabs, FormDialog, Toggle, formatCount, ledgerTypeLabel } from './parts';

type Draft = { name: string; name_bn: string; type: ExpenseCategory['type']; ledger_type: string; parent_id: string; is_fixed: boolean };
const blank: Draft = { name: '', name_bn: '', type: 'operating', ledger_type: '', parent_id: '', is_fixed: false };

/**
 * Expense categories (checklist §2.13: ads, rent, salaries, packaging, utilities). Not drawn in
 * Figma as its own screen: it reuses the DataTable and dialog of the Expenses screen. A
 * category decides where an approved expense lands in the P&L (`resolved_ledger_type`).
 */
export function ExpenseCategoriesPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const manage = can(me, 'finance.manage_expenses');
    const categories = useExpenseCategories();
    const [editing, setEditing] = useState<ExpenseCategory | null | 'new'>(null);
    const [draft, setDraft] = useState<Draft>(blank);
    const [errors, setErrors] = useState<Partial<Record<keyof Draft, string>>>({});
    const save = useSaveCategory(editing && editing !== 'new' ? editing.id : null);
    const deactivate = useDeactivateCategory();
    const list = categories.data ?? [];
    const name = (c: ExpenseCategory) => (locale === 'bn' ? (c.name_bn ?? c.name) : c.name);

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

    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('finNav.crumb') }, { label: t('finNav.finance') }, { label: t('finCat.title') }]}
                title={t('finCat.title')}
                subtitle={t('finCat.subtitle')}
                actions={
                    manage && (
                        <Button onClick={() => open('new')}>
                            <PlusIcon size={16} aria-hidden />
                            {t('finCat.add')}
                        </Button>
                    )
                }
            />
            <FinanceTabs />

            {categories.isError ? (
                <QueryError error={categories.error} onRetry={() => void categories.refetch()} />
            ) : categories.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : list.length === 0 ? (
                <EmptyState title={t('finCat.title')} body={t('finCat.subtitle')} />
            ) : (
                <div className={dataTable.card}>
                    <table className={dataTable.table}>
                        <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                            <tr className="border-b border-line-strong">
                                <th scope="col" className={dataTable.th}>{t('finCat.col.name')}</th>
                                <th scope="col" className={dataTable.th}>{t('finCat.col.type')}</th>
                                <th scope="col" className={dataTable.th}>{t('finCat.col.ledger')}</th>
                                <th scope="col" className={dataTable.th}>{t('finCat.col.fixed')}</th>
                                <th scope="col" className={`${dataTable.th.replace('text-start', 'text-end')}`}>{t('finCat.col.expenses')}</th>
                                <th scope="col" className={dataTable.th}>{t('finCat.col.status')}</th>
                                <th scope="col" className={dataTable.th}>
                                    <span className="sr-only">{t('common.actions')}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {list.map((c) => (
                                <tr key={c.id} className={`${dataTable.row} max-lg:flex max-lg:flex-wrap max-lg:gap-x-3 max-lg:gap-y-1 max-lg:p-3`}>
                                    <td className={`${cell} t-admin-body-strong max-lg:basis-full ${c.parent_id ? 'lg:ps-8' : ''}`}>{name(c)}</td>
                                    <td className={`${cell} t-admin-body`}>{t(`finCat.type.${c.type}` as MessageKey)}</td>
                                    <td className={`${cell} t-admin-body`}>{t(ledgerTypeLabel(c.resolved_ledger_type as never))}</td>
                                    <td className={`${cell} t-admin-body`}>{t(c.is_fixed ? 'finCat.yes' : 'finCat.no')}</td>
                                    <td className={`${cell} t-admin-num tabular lg:text-end`}>{formatCount(c.expenses_count)}</td>
                                    <td className={cell}>
                                        <StatusChip label={t(c.is_active ? 'common.active' : 'common.archived')} active={c.is_active} />
                                    </td>
                                    <td className={cell}>
                                        {manage && (
                                            <div className="flex justify-end gap-1">
                                                <Button size="sm" variant="ghost" onClick={() => open(c)}>
                                                    {t('common.edit')}
                                                </Button>
                                                {c.is_active && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={deactivate.isPending}
                                                        onClick={() => deactivate.mutate(c.id, { onSuccess: () => notify(t('finCat.deactivated')) })}
                                                    >
                                                        {t('finCat.deactivate')}
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <FormDialog
                open={editing !== null}
                title={editing === 'new' ? t('finCat.add') : t('common.edit')}
                body={t('finCat.subtitle')}
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
                    <Field label={t('finCat.field.ledger')} error={errors.ledger_type}>
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
