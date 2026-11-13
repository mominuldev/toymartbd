import { useId, useState, type FormEvent } from 'react';
import { CakeIcon, WarningIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import { useMessageConsents, useSetDateOfBirth, useSetMessageConsent, type CustomerProfile, type MessageConsents } from './api';
import { calendarDay, dhakaDate, dhakaDayYear, dhakaMoment } from './format';
import { Panel } from './parts';

type Birthday = MessageConsents['birthday'];

/**
 * "Birthday messages" on the customer profile rail (P8). No Figma frame exists for it; it follows
 * the rail's Panel pattern (B · 18 241:41374). The date of birth is personal data: shown and
 * editable only with customers.view_pii (rule 20). The consent is opt-in only — staff record it
 * after the customer agreed (a confirmation box gates the button), can withdraw it, and see where
 * each change came from. Sending rules live in the API (SendBirthdayMessages).
 */
export function BirthdayPanel({ customer, canUpdate }: { customer: CustomerProfile; canUpdate: boolean }) {
    const { t } = useI18n();
    const consents = useMessageConsents(customer.id, true);
    const titleId = useId();

    return (
        <Panel title={t('customers.birthday.title')} subtitle={t('customers.birthday.subtitle')} titleId={titleId}>
            <DateOfBirth customer={customer} canEdit={canUpdate && !customer.pii_masked} />
            <div className="flex flex-col gap-2 border-t border-line-default pt-3">
                {consents.isError ? (
                    <QueryError error={consents.error} onRetry={() => void consents.refetch()} />
                ) : !consents.data ? (
                    <p className="t-admin-body text-fg-tertiary">{t('common.loading')}</p>
                ) : (
                    <Consent id={customer.id} birthday={consents.data.birthday} hasDate={customer.has_date_of_birth} canUpdate={canUpdate} />
                )}
            </div>
        </Panel>
    );
}

function DateOfBirth({ customer, canEdit }: { customer: CustomerProfile; canEdit: boolean }) {
    const { t, locale } = useI18n();
    const set = useSetDateOfBirth(customer.id);
    const toast = useToast();
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(customer.date_of_birth ?? '');
    const [error, setError] = useState<string | undefined>();
    const inputId = useId();

    const shown = customer.date_of_birth
        ? calendarDay(customer.date_of_birth, locale)
        : customer.has_date_of_birth
          ? t('customers.birthday.dobHidden')
          : t('customers.birthday.dobNone');

    const save = (next: string | null) =>
        set.mutate(
            { date_of_birth: next },
            {
                onSuccess: () => {
                    setEditing(false);
                    setError(undefined);
                    toast.notify(t(next === null ? 'customers.birthday.dobCleared' : 'customers.birthday.dobSaved'));
                },
                onError: (e) => setError(e instanceof ApiError ? (e.fieldError('date_of_birth') ?? e.problem.title) : t('error.title')),
            },
        );
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (value !== '') save(value);
    };

    if (editing) {
        return (
            <form onSubmit={submit} className="flex flex-col gap-2">
                <label htmlFor={inputId} className="t-admin-body-strong text-fg-primary">
                    {t('customers.birthday.dob')}
                </label>
                <Input
                    id={inputId}
                    type="date"
                    value={value}
                    min="1900-01-01"
                    max={dhakaDate(1)}
                    required
                    onChange={(e) => setValue(e.target.value)}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? `${inputId}-error` : undefined}
                    className="tabular"
                />
                {error && (
                    <p id={`${inputId}-error`} role="alert" className="flex items-center gap-1 t-admin-caption text-fg-primary">
                        <WarningIcon size={14} aria-hidden className="shrink-0 text-feedback-danger" />
                        {error}
                    </p>
                )}
                <p className="t-admin-caption text-fg-secondary">{t('customers.birthday.dobHint')}</p>
                <div className="flex flex-wrap items-center gap-2">
                    <Button type="submit" size="sm" disabled={value === '' || set.isPending}>
                        {t('customers.birthday.dobSave')}
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => setEditing(false)} disabled={set.isPending}>
                        {t('common.cancel')}
                    </Button>
                    {customer.date_of_birth && (
                        <Button variant="ghost" size="sm" className="ms-auto" onClick={() => save(null)} disabled={set.isPending}>
                            {t('customers.birthday.dobClear')}
                        </Button>
                    )}
                </div>
            </form>
        );
    }

    return (
        <div className="flex items-center justify-between gap-3">
            <div className="flex min-w-0 flex-col gap-0.5">
                <span className="t-admin-body text-fg-secondary">{t('customers.birthday.dob')}</span>
                <span className={`t-admin-body-strong tabular ${customer.date_of_birth ? 'text-fg-primary' : 'text-fg-tertiary'}`}>{shown}</span>
            </div>
            {canEdit && (
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => {
                        setValue(customer.date_of_birth ?? '');
                        setError(undefined);
                        setEditing(true);
                    }}
                >
                    {t(customer.date_of_birth ? 'customers.birthday.dobEdit' : 'customers.birthday.dobAdd')}
                </Button>
            )}
        </div>
    );
}

function Consent({ id, birthday, hasDate, canUpdate }: { id: number; birthday: Birthday; hasDate: boolean; canUpdate: boolean }) {
    const { t, locale } = useI18n();
    const set = useSetMessageConsent(id);
    const toast = useToast();
    const [confirmed, setConfirmed] = useState(false);
    const checkId = useId();

    const withdrawn = !birthday.consented && birthday.withdrawn_at !== null;
    const who = (b: Birthday): string =>
        b.source === 'storefront'
            ? t('customers.birthday.byCustomer')
            : b.recorded_by
              ? t('customers.birthday.byStaff', { name: b.recorded_by.name })
              : t('customers.birthday.byStaffUnknown');

    const change = (next: boolean) =>
        set.mutate(next ? { birthday: true, customer_confirmed: true } : { birthday: false }, {
            onSuccess: () => {
                setConfirmed(false);
                toast.notify(t(next ? 'customers.birthday.recorded' : 'customers.birthday.withdrawnToast'));
            },
            onError: (e) => toast.notify(e instanceof ApiError ? (e.fieldError('customer_confirmed') ?? e.problem.title) : t('error.title'), 'danger'),
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
            {!birthday.consented && !withdrawn && <p className="t-admin-caption text-fg-secondary">{t('customers.birthday.noneBody')}</p>}

            {birthday.consented && !hasDate && (
                <p role="status" className="flex items-start gap-1.5 rounded-sm bg-subtle p-2 t-admin-caption text-fg-primary">
                    <WarningIcon size={14} aria-hidden className="mt-0.5 shrink-0 text-feedback-warning" />
                    {t('customers.birthday.noDate')}
                </p>
            )}

            {canUpdate &&
                (birthday.consented ? (
                    <Button variant="outline" size="sm" className="self-start" onClick={() => change(false)} disabled={set.isPending}>
                        {t('customers.birthday.withdraw')}
                    </Button>
                ) : (
                    <div className="flex flex-col gap-2 rounded-sm border border-line-default p-3">
                        <label htmlFor={checkId} className="inline-flex items-start gap-2 t-admin-body text-fg-primary">
                            <input id={checkId} type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} className="mt-0.5 size-4 shrink-0 accent-brand" />
                            {t('customers.birthday.confirm')}
                        </label>
                        <Button size="sm" className="self-start" onClick={() => change(true)} disabled={!confirmed || set.isPending}>
                            <CakeIcon size={16} aria-hidden />
                            {t('customers.birthday.record')}
                        </Button>
                    </div>
                ))}
            <p className="t-admin-caption text-fg-tertiary">{t('customers.birthday.how')}</p>
        </>
    );
}
