import { useId, useState, type ReactNode } from 'react';
import { useSearchParams } from 'react-router';
import { useForm, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { reportScheduleRequestSchema } from '@toymart/api-types/zod';
import { ArrowClockwiseIcon, CheckCircleIcon, ClockIcon, EnvelopeSimpleIcon, PlusIcon, UsersIcon, WarningIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { EmptyState } from '@/components/ui/EmptyState';
import { Field, inputClass } from '@/components/ui/Field';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { AnalyticsTabs } from '@/features/analytics/AnalyticsTabs';
import { formats, frequencies, reportTypes, sendTimes, toInput, useDeleteSchedule, useRecipients, useResendRun, useRuns, useSaveSchedule, useSchedules, type ReportSchedule, type ReportScheduleRun, type ReportType, type RunCopy, type ScheduleInput } from './api';

/** "Sat 7 Nov, 9:15 am" in Dhaka time, Western digits (rules 11, 36). */
function dhakaWhen(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        timeZone: 'Asia/Dhaka',
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
        numberingSystem: 'latn',
    }).format(new Date(iso));
}

const blank: ScheduleInput = { name: '', report_type: 'sales', frequency: 'weekly', send_time: '09:00', locale: 'en', format: 'xlsx', is_active: true, recipient_ids: [] };

/**
 * Analytics → Scheduled reports. Laid out as Figma B · 36 Automations (241:49903): a column
 * of schedule cards with their on/off switch on the left; on the right the WHEN / THEN editor,
 * then recent runs. It lives under Analytics rather than Settings → Automations because the
 * people who schedule reports (accountant, manager) hold reports.export, and the accountant
 * has no settings access. Recipients are staff only, chosen by name; the server sends each
 * one only what their role may see. The selection lives in the URL (`?schedule=` / `?new=1`).
 */
export function ReportSchedulesPage() {
    const { t } = useI18n();
    const schedules = useSchedules();
    const [params, setParams] = useSearchParams();
    const creating = params.get('new') === '1';
    const list = schedules.data ?? [];
    const selected = creating ? null : (list.find((s) => String(s.id) === params.get('schedule')) ?? list[0] ?? null);

    const select = (entries: Record<string, string>) => setParams(entries, { replace: true });

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('courierReport.crumb') }]}
                title={t('schedules.title')}
                subtitle={t('schedules.subtitle')}
                actions={
                    <Button onClick={() => select({ new: '1' })}>
                        <PlusIcon size={16} aria-hidden />
                        {t('schedules.new')}
                    </Button>
                }
            />
            <AnalyticsTabs />

            {schedules.isError ? (
                <QueryError error={schedules.error} onRetry={() => void schedules.refetch()} />
            ) : schedules.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : list.length === 0 && !creating ? (
                <EmptyState
                    reason="noDataYet"
                    title={t('schedules.empty')}
                    body={t('schedules.emptyBody')}
                    actions={
                        <Button onClick={() => select({ new: '1' })}>
                            <PlusIcon size={16} aria-hidden />
                            {t('schedules.new')}
                        </Button>
                    }
                />
            ) : (
                <div className="flex flex-col items-start gap-6 xl:flex-row">
                    <ul aria-label={t('schedules.list')} className="flex w-full shrink-0 flex-col gap-3 xl:w-130">
                        {list.map((s) => (
                            <li key={s.id}>
                                <ScheduleCard schedule={s} selected={selected?.id === s.id} onSelect={() => select({ schedule: String(s.id) })} />
                            </li>
                        ))}
                    </ul>
                    <div className="flex w-full min-w-0 flex-1 flex-col gap-4">
                        <Editor key={selected?.id ?? 'new'} schedule={selected} onSaved={(id) => select({ schedule: String(id) })} onDeleted={() => select({})} />
                        {selected && <RecentRuns schedule={selected} />}
                    </div>
                </div>
            )}
        </section>
    );
}

function whenText(t: ReturnType<typeof useI18n>['t'], s: Pick<ReportSchedule, 'frequency' | 'send_time'>): string {
    return t(`schedules.when.${s.frequency}` as MessageKey, { time: s.send_time });
}

/** List card (Automations 223:16049 / B · 36): icon, name, switch; When / Then / last-and-next line. */
function ScheduleCard({ schedule, selected, onSelect }: { schedule: ReportSchedule; selected: boolean; onSelect: () => void }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const save = useSaveSchedule(schedule.id);
    const run = schedule.latest_run;
    return (
        <div
            role="button"
            tabIndex={0}
            aria-pressed={selected}
            onClick={onSelect}
            onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    onSelect();
                }
            }}
            className={`flex w-full cursor-pointer flex-col gap-1.5 rounded-md px-4 py-3.5 text-start focus-visible:outline-2 focus-visible:outline-line-focus ${
                selected ? 'border-2 border-brand bg-brand-subtle' : 'border border-line-default bg-surface hover:bg-subtle'
            }`}
        >
            <div className="flex items-center gap-2">
                <EnvelopeSimpleIcon size={16} aria-hidden className="shrink-0 text-fg-brand" />
                <p className="min-w-0 flex-1 t-admin-body-strong text-fg-primary">{schedule.name}</p>
                <Toggle
                    checked={schedule.is_active}
                    disabled={save.isPending}
                    label={t('schedules.toggle', { name: schedule.name })}
                    onChange={(next) =>
                        save.mutate(toInput(schedule, { is_active: next }), {
                            onSuccess: () => notify(t('schedules.saved')),
                            onError: (error) => notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
                        })
                    }
                />
            </div>
            <p className="t-admin-caption text-fg-secondary">{t('schedules.card.when', { when: whenText(t, schedule) })}</p>
            <p className="t-admin-caption text-fg-secondary">
                {t('schedules.card.then', { report: t(`schedules.type.${schedule.report_type}` as MessageKey), format: schedule.format.toUpperCase(), count: schedule.recipients.length })}
            </p>
            <p className="t-admin-caption tabular text-fg-tertiary">
                {run ? t(`schedules.card.last.${run.status}` as MessageKey, { period: periodText(run) }) : t('schedules.card.never')}
                {schedule.next_send_at && ` · ${t('schedules.card.next', { when: dhakaWhen(schedule.next_send_at, locale) })}`}
            </p>
        </div>
    );
}

/** A real switch (Automations card 223:16055). */
function Toggle({ checked, onChange, label, disabled }: { checked: boolean; onChange: (next: boolean) => void; label: string; disabled?: boolean }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={(event) => {
                event.stopPropagation();
                onChange(!checked);
            }}
            className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors duration-150 ease-standard disabled:cursor-not-allowed disabled:opacity-60 ${checked ? 'bg-brand' : 'bg-line-strong'}`}
        >
            <span aria-hidden className={`size-4 rounded-full bg-surface shadow-e1 transition-transform duration-150 ease-standard ${checked ? 'translate-x-4' : 'translate-x-0'}`} />
        </button>
    );
}

/** Panel (Automations 223:16116): radius/md, hairline, e1, 20 padding, 14 gap. */
function Panel({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
    const id = useId();
    return (
        <section aria-labelledby={id} className="flex w-full min-w-0 flex-col gap-3.5 rounded-md border border-line-default bg-surface p-5 shadow-e1">
            <header className="flex min-w-0 flex-col gap-0.5">
                <h2 id={id} className="t-admin-card-title text-fg-primary">
                    {title}
                </h2>
                {subtitle && <p className="t-admin-caption text-fg-tertiary">{subtitle}</p>}
            </header>
            {children}
        </section>
    );
}

/** WHEN / THEN step (223:16121): tinted block, 18px glyph, overline + content. */
function Step({ icon: Glyph, label, tone, children }: { icon: typeof ClockIcon; label: string; tone: 'brand' | 'success' | 'subtle'; children: ReactNode }) {
    const fill = tone === 'brand' ? 'bg-brand-subtle' : tone === 'success' ? 'bg-success-subtle' : 'bg-subtle';
    return (
        <div className={`flex w-full items-start gap-3 rounded-sm px-3.5 py-3 ${fill}`}>
            <Glyph size={18} aria-hidden className="mt-0.5 shrink-0 text-fg-primary" />
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <p className="t-admin-overline text-fg-secondary">{label}</p>
                {children}
            </div>
        </div>
    );
}

const fields: (keyof ScheduleInput)[] = ['name', 'report_type', 'frequency', 'send_time', 'locale', 'format', 'is_active', 'recipient_ids'];

function Editor({ schedule, onSaved, onDeleted }: { schedule: ReportSchedule | null; onSaved: (id: number) => void; onDeleted: () => void }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const ids = useId();
    const save = useSaveSchedule(schedule?.id ?? null);
    const remove = useDeleteSchedule();
    const [confirming, setConfirming] = useState(false);
    const form = useForm<ScheduleInput>({ defaultValues: schedule ? toInput(schedule) : blank, resolver: zodResolver(reportScheduleRequestSchema) });
    const { register, handleSubmit, setError, formState, watch, setValue } = form;
    const type = watch('report_type') as ReportType;
    const chosen = watch('recipient_ids');
    const recipients = useRecipients(type);
    const errorOf = (field: keyof ScheduleInput) => formState.errors[field]?.message;

    const submit = handleSubmit((values) =>
        save.mutate(values, {
            onSuccess: (saved) => {
                notify(t('schedules.saved'));
                onSaved(saved.id);
            },
            onError: (error) => {
                if (error instanceof ApiError && error.status === 422) {
                    for (const field of fields) {
                        const message = error.fieldError(field);
                        if (message) setError(field as FieldPath<ScheduleInput>, { message });
                    }
                } else {
                    notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
                }
            },
        }),
    );

    const toggleRecipient = (id: number, on: boolean) =>
        setValue('recipient_ids', on ? [...chosen, id] : chosen.filter((x) => x !== id), { shouldDirty: true, shouldValidate: formState.isSubmitted });

    return (
        <Panel title={schedule ? schedule.name : t('schedules.new')} subtitle={t('schedules.editor.subtitle')}>
            <form onSubmit={submit} noValidate className="flex flex-col gap-3.5">
                <fieldset disabled={save.isPending} className="flex min-w-0 flex-col gap-3.5">
                    <Field label={t('schedules.field.name')} error={errorOf('name')} required>
                        {({ id, describedBy, invalid }) => <input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} {...register('name')} />}
                    </Field>
                    <Field label={t('schedules.field.report')} error={errorOf('report_type')} required>
                        {({ id, describedBy }) => (
                            <Select id={id} aria-describedby={describedBy} {...register('report_type')}>
                                {reportTypes.map((r) => (
                                    <option key={r} value={r}>
                                        {t(`schedules.type.${r}` as MessageKey)}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Step icon={ClockIcon} label={t('schedules.flow.when')} tone="brand">
                        <div className="flex flex-wrap items-center gap-3">
                            <SegmentedControl
                                label={t('schedules.field.frequency')}
                                options={frequencies.map((f) => ({ value: f, label: t(`schedules.frequency.${f}` as MessageKey) }))}
                                value={watch('frequency')}
                                onChange={(f) => setValue('frequency', f, { shouldDirty: true })}
                            />
                            <label htmlFor={`${ids}-time`} className="t-admin-body text-fg-primary">
                                {t('schedules.field.time')}
                            </label>
                            <Select id={`${ids}-time`} className="w-28 tabular" {...register('send_time')}>
                                {sendTimes.map((time) => (
                                    <option key={time} value={time}>
                                        {time}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <p className="t-admin-caption text-fg-secondary">{t(`schedules.period.${watch('frequency')}` as MessageKey)}</p>
                        {errorOf('send_time') && <p className="t-admin-caption text-feedback-danger">{errorOf('send_time')}</p>}
                    </Step>

                    <Step icon={EnvelopeSimpleIcon} label={t('schedules.flow.then')} tone="success">
                        <div className="flex flex-wrap items-center gap-3">
                            <SegmentedControl label={t('schedules.field.format')} options={formats.map((f) => ({ value: f, label: f.toUpperCase() }))} value={watch('format')} onChange={(f) => setValue('format', f, { shouldDirty: true })} />
                            <SegmentedControl
                                label={t('schedules.field.language')}
                                options={[
                                    { value: 'en', label: t('schedules.lang.en'), lang: 'en' },
                                    { value: 'bn', label: t('schedules.lang.bn'), lang: 'bn' },
                                ]}
                                value={watch('locale')}
                                onChange={(l) => setValue('locale', l, { shouldDirty: true })}
                            />
                        </div>
                    </Step>

                    <Step icon={UsersIcon} label={t('schedules.flow.to')} tone="subtle">
                        <p className="t-admin-caption text-fg-secondary">{t('schedules.recipients.hint')}</p>
                        {recipients.isPending ? (
                            <p className="t-admin-caption text-fg-tertiary">{t('common.loading')}</p>
                        ) : (recipients.data ?? []).length === 0 ? (
                            <p className="t-admin-caption text-fg-tertiary">{t('schedules.recipients.none')}</p>
                        ) : (
                            <ul className="grid gap-1.5 sm:grid-cols-2" aria-label={t('schedules.field.recipients')}>
                                {(recipients.data ?? []).map((r) => (
                                    <li key={r.id}>
                                        <label className="flex items-center gap-2 t-admin-body text-fg-primary">
                                            <input type="checkbox" className="size-4 accent-brand" checked={chosen.includes(r.id)} onChange={(e) => toggleRecipient(r.id, e.target.checked)} />
                                            <span className="min-w-0 truncate">{r.name}</span>
                                            <span className="t-admin-caption text-fg-tertiary">{r.roles.map((role) => t(`role.${role}` as MessageKey)).join(', ')}</span>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {errorOf('recipient_ids') && (
                            <p className="t-admin-caption text-feedback-danger" role="alert">
                                {errorOf('recipient_ids')}
                            </p>
                        )}
                    </Step>
                    <p className="t-admin-caption text-fg-tertiary">{t('schedules.privacy')}</p>
                </fieldset>
                <div className="flex flex-wrap justify-end gap-2">
                    {schedule && (
                        <Button type="button" variant="outline" onClick={() => setConfirming(true)}>
                            {t('schedules.delete')}
                        </Button>
                    )}
                    <Button type="submit" disabled={save.isPending}>
                        {t('schedules.save')}
                    </Button>
                </div>
            </form>
            {schedule && (
                <ConfirmDialog
                    open={confirming}
                    title={t('schedules.deleteTitle', { name: schedule.name })}
                    body={t('schedules.deleteBody')}
                    confirmLabel={t('schedules.delete')}
                    pending={remove.isPending}
                    onClose={() => setConfirming(false)}
                    onConfirm={() =>
                        remove.mutate(schedule.id, {
                            onSuccess: () => {
                                setConfirming(false);
                                notify(t('schedules.deleted'));
                                onDeleted();
                            },
                            onError: (error) => notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
                        })
                    }
                />
            )}
        </Panel>
    );
}

/** "2026-11-03" or "2026-10-31 – 2026-11-06" (Dhaka dates, Western digits). */
function periodText(run: Pick<ReportScheduleRun, 'period_from' | 'period_to'>): string {
    return run.period_from === run.period_to ? run.period_from : `${run.period_from} – ${run.period_to}`;
}

type ResendTarget = { run: ReportScheduleRun; userId?: number; name?: string };

/**
 * Recent runs (Automations 223:16163): the sent-log, newest period first. A failed run lists
 * each recipient's copy (P9) and can be resent — every failed copy, or one person's — after a
 * confirmation; the server re-checks permissions and never mails a copy twice.
 */
function RecentRuns({ schedule }: { schedule: ReportSchedule }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const runs = useRuns(schedule.id);
    const resend = useResendRun(schedule.id);
    const [target, setTarget] = useState<ResendTarget | null>(null);
    const rows = runs.data ?? [];

    const confirm = () => {
        if (!target) return;
        resend.mutate(
            { runId: target.run.id, userId: target.userId },
            {
                onSuccess: () => {
                    setTarget(null);
                    notify(t('schedules.runs.resent'));
                },
                onError: (error) => {
                    setTarget(null);
                    // Server prose is not shown (it is English); the status says what happened.
                    const message = !(error instanceof ApiError)
                        ? t('error.title')
                        : error.status === 409
                          ? t('schedules.runs.conflict')
                          : error.status === 422
                            ? t('schedules.runs.notAllowed')
                            : error.problem.title;
                    notify(message, 'danger');
                },
            },
        );
    };

    return (
        <Panel title={t('schedules.runs.title')}>
            {rows.length === 0 ? (
                <p className="t-admin-caption text-fg-tertiary">{t('schedules.runs.empty')}</p>
            ) : (
                <ul className="-mt-1.5 flex flex-col">
                    {rows.map((run) => {
                        const ok = run.status === 'sent' || run.status === 'queued' || run.status === 'sending';
                        const failed = run.status === 'failed';
                        return (
                            <li key={run.id} className="flex flex-col gap-2 border-b border-line-default py-2">
                                <div className="flex flex-wrap items-center gap-2.5">
                                    {ok ? <CheckCircleIcon size={14} aria-hidden className="shrink-0 text-feedback-success" /> : <WarningIcon size={14} aria-hidden className="shrink-0 text-feedback-warning" />}
                                    <span className="t-admin-caption tabular whitespace-nowrap text-fg-tertiary">{run.sent_at ? dhakaWhen(run.sent_at, locale) : run.due_at ? dhakaWhen(run.due_at, locale) : ''}</span>
                                    <span className="t-admin-mono-sm whitespace-nowrap text-fg-primary">{periodText(run)}</span>
                                    <span className="min-w-0 flex-1 t-admin-caption text-fg-secondary">
                                        {t(`schedules.status.${run.status}` as MessageKey)}
                                        {run.status === 'sent' && ` · ${t('schedules.runs.sent', { count: run.recipients_sent })}`}
                                        {run.recipients_skipped > 0 && ` · ${t('schedules.runs.skipped', { count: run.recipients_skipped })}`}
                                        {run.error && ` · ${run.error}`}
                                    </span>
                                    {failed && (run.deliveries.length > 0 || run.recipients_sent === 0) && (
                                        <Button variant="outline" size="sm" onClick={() => setTarget({ run })}>
                                            <ArrowClockwiseIcon size={16} aria-hidden />
                                            {t('schedules.runs.resend')}
                                        </Button>
                                    )}
                                </div>
                                {run.resend_count > 0 && run.last_resent_by && (
                                    <p className="ps-6 t-admin-caption text-fg-tertiary">{t('schedules.runs.resentBy', { count: run.resend_count, name: run.last_resent_by.name })}</p>
                                )}
                                {failed && <RunCopies run={run} schedule={schedule} onResend={(userId, name) => setTarget({ run, userId, name })} />}
                            </li>
                        );
                    })}
                </ul>
            )}
            {target && (
                <ConfirmDialog
                    open
                    title={target.name ? t('schedules.runs.resendOneTitle', { name: target.name }) : t('schedules.runs.resendTitle', { period: periodText(target.run) })}
                    body={target.name ? t('schedules.runs.resendOneBody', { name: target.name, period: periodText(target.run) }) : t('schedules.runs.resendBody')}
                    confirmLabel={t('schedules.runs.resendConfirm')}
                    pending={resend.isPending}
                    onClose={() => setTarget(null)}
                    onConfirm={confirm}
                />
            )}
        </Panel>
    );
}

type CopyRow = { user_id: number; name: string; status: RunCopy['status'] | 'unknown' };

/**
 * A failed run's recipients and their copies. A run from before per-recipient records lists
 * the schedule's current recipients as "not recorded", each resendable one at a time.
 */
function RunCopies({ run, schedule, onResend }: { run: ReportScheduleRun; schedule: ReportSchedule; onResend: (userId: number, name: string) => void }) {
    const { t } = useI18n();
    const legacy = run.deliveries.length === 0;
    const copies: CopyRow[] = legacy ? schedule.recipients.map((r) => ({ user_id: r.id, name: r.name, status: 'unknown' })) : run.deliveries;
    if (copies.length === 0) return null;
    return (
        <div className="ms-6 flex flex-col gap-1.5 rounded-sm bg-subtle px-3 py-2">
            <p className="t-admin-overline text-fg-secondary">{t('schedules.runs.copies')}</p>
            {legacy && run.recipients_sent > 0 && <p className="t-admin-caption text-fg-secondary">{t('schedules.runs.legacy')}</p>}
            <ul className="flex flex-col gap-1" aria-label={t('schedules.runs.copiesFor', { period: periodText(run) })}>
                {copies.map((copy) => (
                    <li key={copy.user_id} className="flex flex-wrap items-center gap-2">
                        <span className="min-w-0 flex-1 truncate t-admin-body text-fg-primary">{copy.name}</span>
                        <span className={`t-admin-caption ${copy.status === 'failed' ? 'text-feedback-danger' : 'text-fg-secondary'}`}>{t(`schedules.copy.${copy.status}` as MessageKey)}</span>
                        {(copy.status === 'failed' || copy.status === 'unknown') && (
                            <Button variant="ghost" size="sm" onClick={() => onResend(copy.user_id, copy.name)}>
                                {t('schedules.runs.resendOne', { name: copy.name })}
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
