import { useId, useState, type FormEvent } from 'react';
import { CakeIcon, PencilSimpleIcon, PlusIcon, SmileyIcon, TrashIcon, WarningIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import {
    confirmationMethods,
    useMessageConsents,
    useRemoveChild,
    useSaveChild,
    useSetMessageConsent,
    type ConfirmationMethod,
    type CustomerChild,
    type CustomerProfile,
    type MessageConsents,
} from './api';
import { dayMonth, dhakaDayYear, dhakaMoment, monthName } from './format';
import { Panel } from './parts';

type Birthday = MessageConsents['birthday'];

/** The most a customer may have — the API's cap (CustomerChild::MAX_PER_CUSTOMER). */
const MAX_CHILDREN = 6;

/**
 * "Kids & birthday reminders" on the customer profile rail (P8 batch 3, owner decision 2026-11-13).
 * Figma B · 18 241:41374 shows the children and their birthdays as rows of the Profile card
 * (rendered there by CustomerProfilePage); this panel is where staff edit them and the consent — it
 * has no frame and follows the rail's Panel pattern. Children's data: listed and editable only with
 * customers.view_pii (+ customers.update to edit, rule 20). The consent is opt-in only — staff
 * record it after the customer agreed, saying how (phone call, in person, written, other), can
 * withdraw it, and see where each change came from. Sending rules live in the API.
 */
export function BirthdayPanel({ customer, canUpdate }: { customer: CustomerProfile; canUpdate: boolean }) {
    const { t } = useI18n();
    const consents = useMessageConsents(customer.id, true);
    const titleId = useId();

    return (
        <Panel title={t('customers.birthday.title')} subtitle={t('customers.birthday.subtitle')} titleId={titleId}>
            <Kids customer={customer} canEdit={canUpdate && !customer.pii_masked} />
            <div className="flex flex-col gap-2 border-t border-line-default pt-3">
                {consents.isError ? (
                    <QueryError error={consents.error} onRetry={() => void consents.refetch()} />
                ) : !consents.data ? (
                    <p className="t-admin-body text-fg-tertiary">{t('common.loading')}</p>
                ) : (
                    <Consent id={customer.id} birthday={consents.data.birthday} hasChildren={customer.children_count > 0} canUpdate={canUpdate} />
                )}
            </div>
        </Panel>
    );
}

function Kids({ customer, canEdit }: { customer: CustomerProfile; canEdit: boolean }) {
    const { t, locale } = useI18n();
    const remove = useRemoveChild(customer.id);
    const toast = useToast();
    const [editing, setEditing] = useState<number | 'new' | null>(null);
    const [removing, setRemoving] = useState<CustomerChild | null>(null);
    const children = customer.children;

    if (children === null) {
        return (
            <p className="t-admin-body text-fg-secondary">
                {customer.children_count === 0 ? t('customers.kids.none') : t('customers.kids.hidden', { count: customer.children_count })}
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-2">
            {children.length === 0 && editing !== 'new' && <p className="t-admin-body text-fg-tertiary">{t('customers.kids.none')}</p>}
            <ul className="flex flex-col gap-2">
                {children.map((child) =>
                    editing === child.id ? (
                        <li key={child.id}>
                            <ChildForm customerId={customer.id} child={child} onDone={() => setEditing(null)} />
                        </li>
                    ) : (
                        <li key={child.id} className="flex items-center gap-3 rounded-sm bg-subtle px-3 py-2">
                            <SmileyIcon size={20} aria-hidden className="shrink-0 text-fg-secondary" />
                            <div className="flex min-w-0 flex-1 flex-col">
                                <span className="t-admin-body-strong text-fg-primary">{childLabel(child, t)}</span>
                                <span className="t-admin-caption text-fg-secondary tabular">{t('customers.kids.birthday', { date: dayMonth(child.birth_month, child.birth_day, locale) })}</span>
                            </div>
                            {canEdit && (
                                <div className="flex shrink-0 gap-1">
                                    <Button variant="ghost" size="sm" aria-label={t('customers.kids.editOne', { name: child.name ?? t('customers.kids.unnamed') })} onClick={() => setEditing(child.id)}>
                                        <PencilSimpleIcon size={16} aria-hidden />
                                    </Button>
                                    <Button variant="ghost" size="sm" aria-label={t('customers.kids.removeOne', { name: child.name ?? t('customers.kids.unnamed') })} onClick={() => setRemoving(child)}>
                                        <TrashIcon size={16} aria-hidden />
                                    </Button>
                                </div>
                            )}
                        </li>
                    ),
                )}
            </ul>
            {editing === 'new' && <ChildForm customerId={customer.id} child={null} onDone={() => setEditing(null)} />}
            {canEdit && editing === null && children.length < MAX_CHILDREN && (
                <Button variant="outline" size="sm" className="self-start" onClick={() => setEditing('new')}>
                    <PlusIcon size={16} aria-hidden />
                    {t('customers.kids.add')}
                </Button>
            )}
            <ConfirmDialog
                open={removing !== null}
                title={t('customers.kids.removeTitle')}
                body={t('customers.kids.removeBody')}
                confirmLabel={t('customers.kids.remove')}
                pending={remove.isPending}
                onClose={() => setRemoving(null)}
                onConfirm={() =>
                    removing &&
                    remove.mutate(removing.id, {
                        onSuccess: () => {
                            setRemoving(null);
                            toast.notify(t('customers.kids.removed'));
                        },
                        onError: (e) => toast.notify(e instanceof ApiError ? e.problem.title : t('error.title'), 'danger'),
                    })
                }
            />
        </div>
    );
}

/** "Arian · 6 years" / "Arian" / "Child · 6 years". */
function childLabel(child: CustomerChild, t: ReturnType<typeof useI18n>['t']): string {
    const name = child.name ?? t('customers.kids.unnamed');
    return child.age === null ? name : t('customers.kids.nameAge', { name, age: child.age });
}

function ChildForm({ customerId, child, onDone }: { customerId: number; child: CustomerChild | null; onDone: () => void }) {
    const { t, locale } = useI18n();
    const save = useSaveChild(customerId);
    const toast = useToast();
    const [name, setName] = useState(child?.name ?? '');
    const [month, setMonth] = useState(child ? String(child.birth_month) : '');
    const [day, setDay] = useState(child ? String(child.birth_day) : '');
    const [year, setYear] = useState(child?.birth_year ? String(child.birth_year) : '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const thisYear = new Date().getFullYear();

    const submit = (e: FormEvent) => {
        e.preventDefault();
        save.mutate(
            { id: child?.id ?? null, input: { name: name.trim() === '' ? null : name.trim(), birth_month: Number(month), birth_day: Number(day), birth_year: year === '' ? null : Number(year) } },
            {
                onSuccess: () => {
                    toast.notify(t(child ? 'customers.kids.saved' : 'customers.kids.added'));
                    onDone();
                },
                onError: (err) => {
                    if (err instanceof ApiError) {
                        const next: Record<string, string> = {};
                        for (const field of ['name', 'birth_month', 'birth_day', 'birth_year', 'children']) {
                            const message = err.fieldError(field);
                            if (message) next[field] = message;
                        }
                        setErrors(Object.keys(next).length > 0 ? next : { children: err.problem.title });
                    } else {
                        setErrors({ children: t('error.title') });
                    }
                },
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3 rounded-sm border border-line-default p-3" aria-label={t(child ? 'customers.kids.editTitle' : 'customers.kids.addTitle')}>
            <Field label={t('customers.kids.name')} hint={t('customers.kids.nameHint')} error={errors.name} optional>
                {({ id, describedBy, invalid }) => <Input id={id} value={name} maxLength={40} onChange={(e) => setName(e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} />}
            </Field>
            <div className="grid grid-cols-2 gap-2">
                <Field label={t('customers.kids.day')} error={errors.birth_day} required>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} value={day} required onChange={(e) => setDay(e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="tabular">
                            <option value="">{t('customers.kids.choose')}</option>
                            {Array.from({ length: 31 }, (_, i) => i + 1).map((d) => (
                                <option key={d} value={d}>
                                    {d}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
                <Field label={t('customers.kids.month')} error={errors.birth_month} required>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} value={month} required onChange={(e) => setMonth(e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined}>
                            <option value="">{t('customers.kids.choose')}</option>
                            {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
                                <option key={m} value={m}>
                                    {monthName(m, locale)}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
            </div>
            <Field label={t('customers.kids.year')} hint={t('customers.kids.yearHint')} error={errors.birth_year} optional>
                {({ id, describedBy, invalid }) => (
                    <Select id={id} value={year} onChange={(e) => setYear(e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="tabular">
                        <option value="">{t('customers.kids.yearNone')}</option>
                        {Array.from({ length: 26 }, (_, i) => thisYear - i).map((y) => (
                            <option key={y} value={y}>
                                {y}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>
            {errors.children && (
                <p role="alert" className="flex items-start gap-1 t-admin-caption text-fg-primary">
                    <WarningIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                    {errors.children}
                </p>
            )}
            <div className="flex flex-wrap gap-2">
                <Button type="submit" size="sm" disabled={month === '' || day === '' || save.isPending}>
                    {t(child ? 'customers.kids.save' : 'customers.kids.addSubmit')}
                </Button>
                <Button variant="ghost" size="sm" onClick={onDone} disabled={save.isPending}>
                    {t('common.cancel')}
                </Button>
            </div>
        </form>
    );
}

function Consent({ id, birthday, hasChildren, canUpdate }: { id: number; birthday: Birthday; hasChildren: boolean; canUpdate: boolean }) {
    const { t, locale } = useI18n();
    const set = useSetMessageConsent(id);
    const toast = useToast();
    const [confirmed, setConfirmed] = useState(false);
    const [method, setMethod] = useState<ConfirmationMethod | ''>('');
    const [note, setNote] = useState('');
    const checkId = useId();

    const withdrawn = !birthday.consented && birthday.withdrawn_at !== null;
    const who = (b: Birthday): string =>
        b.source === 'storefront'
            ? t('customers.birthday.byCustomer')
            : b.recorded_by
              ? t('customers.birthday.byStaff', { name: b.recorded_by.name })
              : t('customers.birthday.byStaffUnknown');
    const how = (b: Birthday): string | null =>
        b.confirmation_method ? [t(`customers.birthday.method.${b.confirmation_method}` as MessageKey), b.confirmation_note].filter(Boolean).join(' — ') : null;

    const change = (next: boolean) =>
        set.mutate(next ? { birthday: true, customer_confirmed: true, confirmation_method: method === '' ? null : method, confirmation_note: note.trim() === '' ? null : note.trim() } : { birthday: false }, {
            onSuccess: () => {
                setConfirmed(false);
                setMethod('');
                setNote('');
                toast.notify(t(next ? 'customers.birthday.recorded' : 'customers.birthday.withdrawnToast'));
            },
            onError: (e) =>
                toast.notify(e instanceof ApiError ? (e.fieldError('confirmation_method') ?? e.fieldError('customer_confirmed') ?? e.problem.title) : t('error.title'), 'danger'),
        });

    return (
        <>
            <div className="flex items-center justify-between gap-3">
                <span className="t-admin-body text-fg-secondary">{t('customers.birthday.consent')}</span>
                <StatusChip
                    tone={birthday.consented ? 'success' : withdrawn ? 'warning' : 'neutral'}
                    label={t(birthday.consented ? 'customers.birthday.state.in' : withdrawn ? 'customers.birthday.state.withdrawn' : 'customers.birthday.state.none')}
                />
            </div>

            {birthday.consented && birthday.consented_at && (
                <p className="t-admin-caption text-fg-secondary tabular">{t('customers.birthday.givenAt', { at: dhakaMoment(birthday.consented_at, locale), who: who(birthday) })}</p>
            )}
            {withdrawn && birthday.withdrawn_at && (
                <p className="t-admin-caption text-fg-secondary tabular">
                    {t('customers.birthday.withdrawnAt', { at: dhakaMoment(birthday.withdrawn_at, locale), who: who(birthday) })}
                    {birthday.consented_at ? ` ${t('customers.birthday.lastGiven', { date: dhakaDayYear(birthday.consented_at, locale) })}` : ''}
                </p>
            )}
            {(birthday.consented || withdrawn) && how(birthday) && <p className="t-admin-caption text-fg-secondary">{t('customers.birthday.confirmedHow', { how: how(birthday) ?? '' })}</p>}
            {!birthday.consented && !withdrawn && <p className="t-admin-caption text-fg-secondary">{t('customers.birthday.noneBody')}</p>}

            {birthday.consented && !hasChildren && (
                <p role="status" className="flex items-start gap-1.5 rounded-sm bg-subtle p-2 t-admin-caption text-fg-primary">
                    <WarningIcon size={14} aria-hidden className="mt-0.5 shrink-0 text-feedback-warning" />
                    {t('customers.birthday.noKids')}
                </p>
            )}

            {canUpdate &&
                (birthday.consented ? (
                    <Button variant="outline" size="sm" className="self-start" onClick={() => change(false)} disabled={set.isPending}>
                        {t('customers.birthday.withdraw')}
                    </Button>
                ) : (
                    <div className="flex flex-col gap-3 rounded-sm border border-line-default p-3">
                        <label htmlFor={checkId} className="inline-flex items-start gap-2 t-admin-body text-fg-primary">
                            <input id={checkId} type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} className="mt-0.5 size-4 shrink-0 accent-brand" />
                            {t('customers.birthday.confirm')}
                        </label>
                        <Field label={t('customers.birthday.methodLabel')} required>
                            {({ id: fieldId, describedBy }) => (
                                <Select id={fieldId} value={method} required onChange={(e) => setMethod(e.target.value as ConfirmationMethod | '')} aria-describedby={describedBy}>
                                    <option value="">{t('customers.kids.choose')}</option>
                                    {confirmationMethods.map((m) => (
                                        <option key={m} value={m}>
                                            {t(`customers.birthday.method.${m}` as MessageKey)}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                        <Field label={t('customers.birthday.noteLabel')} hint={t('customers.birthday.noteHint')} optional>
                            {({ id: fieldId, describedBy }) => <Input id={fieldId} value={note} maxLength={200} onChange={(e) => setNote(e.target.value)} aria-describedby={describedBy} />}
                        </Field>
                        <Button size="sm" className="self-start" onClick={() => change(true)} disabled={!confirmed || method === '' || set.isPending}>
                            <CakeIcon size={16} aria-hidden />
                            {t('customers.birthday.record')}
                        </Button>
                    </div>
                ))}
            <p className="t-admin-caption text-fg-tertiary">{t('customers.birthday.how')}</p>
        </>
    );
}
