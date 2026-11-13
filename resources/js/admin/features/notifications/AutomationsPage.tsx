import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { useForm, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { updateNotificationTemplateRequestSchema } from '@toymart/api-types/zod';
import { ChatTextIcon, CheckCircleIcon, FunnelSimpleIcon, LightningIcon, ListChecksIcon, ReceiptIcon, WarningIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { EmptyState } from '@/components/ui/EmptyState';
import { Field, inputClass } from '@/components/ui/Field';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { useToast } from '@/components/ui/Toast';
import { channels, useLogs, useSaveTemplate, useSaveTemplates, useTemplatePreview, useTemplates, type Channel, type NotificationTemplate, type TemplateInput } from './api';
import { FlowLine, FlowStep, Panel, Taka, Toggle, channelIcon, channelLabel, dhakaWhen, eventLabel, whenLabel } from './parts';

/** Lifecycle order for the list; any key the API adds later follows, alphabetically. */
const keyOrder = ['order.placed', 'order.confirmed', 'order.shipped', 'order.out_for_delivery', 'order.out_for_delivery.no_rider', 'order.delivered', 'order.cancelled', 'otp.login'];

type Group = { key: string; templates: Partial<Record<Channel, NotificationTemplate>> };

function groupTemplates(rows: NotificationTemplate[]): Group[] {
    const map = new Map<string, Group>();
    for (const row of rows) {
        const group = map.get(row.key) ?? { key: row.key, templates: {} };
        group.templates[row.channel] = row;
        map.set(row.key, group);
    }
    const rank = (key: string) => (keyOrder.includes(key) ? keyOrder.indexOf(key) : keyOrder.length);
    return [...map.values()].sort((a, b) => rank(a.key) - rank(b.key) || a.key.localeCompare(b.key));
}

function useDebounced<T>(value: T, delay = 400): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const timer = setTimeout(() => setDebounced(value), delay);
        return () => clearTimeout(timer);
    }, [value, delay]);
    return debounced;
}

/**
 * Settings → Automations (Figma "Automations" 223:15784; Theme B colours via the admin tokens).
 * Left: one card per notification event with its on/off switch. Right: the WHEN / IF / THEN /
 * ELSE flow, the template editor (EN + BN, live server-side segment count and cost), the
 * rendered preview bubble and recent runs. The selection lives in the URL (`?template=&channel=`).
 */
export function AutomationsPage() {
    const { t } = useI18n();
    const templates = useTemplates();
    const [params, setParams] = useSearchParams();
    // Event-bound (system) templates only: staff-made ones live in the Message templates library.
    const groups = useMemo(() => groupTemplates((templates.data ?? []).filter((tpl) => !tpl.is_custom)), [templates.data]);
    const selected = groups.find((g) => g.key === params.get('template')) ?? groups[0];
    const available = channels.filter((c) => selected?.templates[c]);
    const channelParam = params.get('channel') as Channel | null;
    const channel: Channel | undefined = channelParam && available.includes(channelParam) ? channelParam : available[0];

    const select = (key: string, nextChannel?: Channel) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                next.set('template', key);
                if (nextChannel) next.set('channel', nextChannel);
                else next.delete('channel');
                return next;
            },
            { replace: true },
        );

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('notif.crumb.settings') }, { label: t('notif.crumb.automations') }]}
                title={t('notif.title')}
                subtitle={t('notif.subtitle')}
                actions={
                    <>
                        {/* "Message templates" — Figma B · 36 AdminButton 241:49914 (outline, chat-text icon). */}
                        <Link to="/automations/templates" className={`${buttonClass('outline', 'md')} h-9`}>
                            <ChatTextIcon size={16} aria-hidden />
                            {t('notif.lib.button')}
                        </Link>
                        <Link to="/automations/log" className={`${buttonClass('outline', 'md')} h-9`}>
                            <ListChecksIcon size={16} aria-hidden />
                            {t('notif.messageLog')}
                        </Link>
                    </>
                }
            />

            {templates.isError ? (
                <QueryError error={templates.error} onRetry={() => void templates.refetch()} />
            ) : templates.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : !selected || !channel ? (
                <EmptyState reason="noDataYet" title={t('notif.log.empty')} />
            ) : (
                <div className="flex flex-col items-start gap-6 xl:flex-row">
                    <ul aria-label={t('notif.list')} className="flex w-full shrink-0 flex-col gap-3 xl:w-130">
                        {groups.map((group) => (
                            <li key={group.key}>
                                <EventCard group={group} selected={group.key === selected.key} onSelect={() => select(group.key)} />
                            </li>
                        ))}
                    </ul>
                    <div className="flex w-full min-w-0 flex-1 flex-col gap-4">
                        <FlowPanel group={selected} />
                        <TemplateEditor
                            key={`${selected.key}.${channel}`}
                            template={selected.templates[channel]!}
                            available={available}
                            onChannel={(next) => select(selected.key, next)}
                        />
                        <RecentRuns templateKey={selected.key} />
                    </div>
                </div>
            )}
        </section>
    );
}

/** List card (223:16049): lightning, title, switch; When / Then / segment line. Selected: brand-subtle, 2px brand edge. */
function EventCard({ group, selected, onSelect }: { group: Group; selected: boolean; onSelect: () => void }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const editable = can(me, 'settings.update');
    const all = Object.values(group.templates);
    const security = all.some((tpl) => tpl.category === 'security');
    const active = all.some((tpl) => tpl.is_active);
    const sms = group.templates.sms;
    const toggle = useToggleGroup(all);
    const then = [
        group.templates.whatsapp ? t('notif.then.messaging') : sms ? t('notif.then.smsOnly') : null,
        group.templates.email ? t('notif.then.email') : null,
    ]
        .filter(Boolean)
        .join(' · ');

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
                <LightningIcon size={16} aria-hidden className="shrink-0 text-fg-brand" />
                <p className="min-w-0 flex-1 t-admin-body-strong text-fg-primary">{t(eventLabel(group.key))}</p>
                <Toggle
                    checked={active}
                    disabled={!editable || security || toggle.isPending}
                    label={t('notif.card.toggle', { name: t(eventLabel(group.key)) })}
                    onChange={(next) => toggle.mutate(next)}
                />
            </div>
            <p className="t-admin-caption text-fg-secondary">{t('notif.card.when', { event: t(whenLabel(group.key)) })}</p>
            <p className="t-admin-caption text-fg-secondary">{t('notif.card.then', { channels: then })}</p>
            <p className="t-admin-caption tabular text-fg-tertiary">
                {security ? t('notif.card.security') : sms ? t('notif.card.segments', { en: sms.segments_en ?? '—', bn: sms.segments_bn ?? '—' }) : null}
            </p>
        </div>
    );
}

/** Switches every channel of one event on or off — one PUT per template, each the full document. */
function useToggleGroup(templates: NotificationTemplate[]) {
    const { notify } = useToast();
    const { t } = useI18n();
    const save = useSaveTemplates();

    return {
        isPending: save.isPending,
        mutate: (next: boolean) =>
            save.mutate(
                templates.map((tpl) => ({ id: tpl.id, input: toInput({ ...tpl, is_active: next }) })),
                {
                    onSuccess: () => notify(t('notif.editor.saved')),
                    onError: (error) => notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
                },
            ),
    };
}

/** WHEN / IF / THEN / ELSE (223:16116), from what the server will actually do for this event. */
function FlowPanel({ group }: { group: Group }) {
    const { t } = useI18n();
    const security = Object.values(group.templates).some((tpl) => tpl.category === 'security');
    const wa = group.templates.whatsapp;
    const sms = group.templates.sms;
    const waPreview = useTemplatePreview(wa?.id ?? null, { body_en: wa?.body_en ?? '', body_bn: wa?.body_bn ?? '' });
    const smsPreview = useTemplatePreview(sms?.id ?? null, { body_en: 'x', body_bn: 'x' });

    return (
        <Panel title={t(eventLabel(group.key))} subtitle={t('notif.flow.subtitle')}>
            <FlowStep icon={ReceiptIcon} label={t('notif.flow.when')} tone="brand">
                {t(whenLabel(group.key))}
            </FlowStep>
            <FlowLine />
            <FlowStep icon={FunnelSimpleIcon} label={t('notif.flow.if')} tone="subtle">
                {security ? t('notif.flow.ifSecurity') : t('notif.flow.ifOptIn')}
            </FlowStep>
            <FlowLine />
            {wa ? (
                <FlowStep icon={channelIcon.whatsapp} label={t('notif.flow.then')} tone="success">
                    {t('notif.flow.thenWhatsApp', { template: wa.provider_template ?? wa.key })}
                    {waPreview.data && <Taka amount={waPreview.data.en.cost} signClass="t-admin-currency-body-lg" />}
                    {t('notif.flow.perMessage')}
                </FlowStep>
            ) : (
                <FlowStep icon={channelIcon.sms} label={t('notif.flow.then')} tone="success">
                    {t('notif.flow.thenSms')}
                    {smsPreview.data && <Taka amount={smsPreview.data.en.cost} signClass="t-admin-currency-body-lg" />}
                    {t('notif.flow.perSegment')}
                </FlowStep>
            )}
            <FlowLine />
            <FlowStep icon={ChatTextIcon} label={t('notif.flow.else')} tone="subtle">
                {wa && sms ? t('notif.flow.elseSms', { en: sms.segments_en ?? '—', bn: sms.segments_bn ?? '—' }) : t('notif.flow.elseNone')}
            </FlowStep>
        </Panel>
    );
}

type FormValues = {
    body_en: string;
    body_bn: string;
    is_active: boolean;
    subject_en: string;
    subject_bn: string;
    preheader_en: string;
    preheader_bn: string;
};

const fields: (keyof FormValues)[] = ['body_en', 'body_bn', 'is_active', 'subject_en', 'subject_bn', 'preheader_en', 'preheader_bn'];
const validateInput = zodResolver(updateNotificationTemplateRequestSchema);

function toForm(tpl: NotificationTemplate): FormValues {
    return {
        body_en: tpl.body_en,
        body_bn: tpl.body_bn,
        is_active: tpl.is_active,
        subject_en: tpl.subject_en ?? '',
        subject_bn: tpl.subject_bn ?? '',
        preheader_en: tpl.preheader_en ?? '',
        preheader_bn: tpl.preheader_bn ?? '',
    };
}

function toInput(values: Pick<FormValues, 'body_en' | 'body_bn' | 'is_active'> & { subject_en?: string | null; subject_bn?: string | null; preheader_en?: string | null; preheader_bn?: string | null }): TemplateInput {
    const orNull = (value: string | null | undefined) => (value == null || value.trim() === '' ? null : value);
    return {
        body_en: values.body_en,
        body_bn: values.body_bn,
        is_active: values.is_active,
        subject_en: orNull(values.subject_en),
        subject_bn: orNull(values.subject_bn),
        preheader_en: orNull(values.preheader_en),
        preheader_bn: orNull(values.preheader_bn),
    };
}

/** Template editor + preview bubble. Not drawn in Figma: built from FormSection / AdminField patterns. */
function TemplateEditor({ template, available, onChannel }: { template: NotificationTemplate; available: Channel[]; onChannel: (channel: Channel) => void }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const editable = can(me, 'settings.update');
    const save = useSaveTemplate(template.id);
    const form = useForm<FormValues>({
        defaultValues: toForm(template),
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values), context, options as never);
            return Object.keys(result.errors).length > 0 ? { values: {}, errors: result.errors as never } : { values, errors: {} };
        },
    });
    const { register, handleSubmit, setError, formState, watch, setValue } = form;
    const bodyEn = watch('body_en');
    const bodyBn = watch('body_bn');
    const active = watch('is_active');
    const debounced = useDebounced({ body_en: bodyEn, body_bn: bodyBn });
    const preview = useTemplatePreview(template.id, debounced);
    const email = template.channel === 'email';
    const ChannelGlyph = channelIcon[template.channel];

    const submit = handleSubmit((values) =>
        save.mutate(toInput(values), {
            onSuccess: () => notify(t('notif.editor.saved')),
            onError: (error) => {
                if (error instanceof ApiError && error.status === 422) {
                    for (const field of fields) {
                        const message = error.fieldError(field);
                        if (message) setError(field as FieldPath<FormValues>, { message });
                    }
                } else {
                    notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
                }
            },
        }),
    );

    const errorOf = (field: keyof FormValues) => formState.errors[field]?.message;
    const measured = (side: 'en' | 'bn') => {
        const m = preview.data?.[side];
        if (!m) return null;
        return (
            <p className="t-admin-caption tabular text-fg-secondary" aria-live="polite">
                {m.segments !== null
                    ? t('notif.measure.sms', {
                          chars: m.chars,
                          encoding: t(m.encoding === 'gsm7' ? 'notif.measure.gsm7' : 'notif.measure.ucs2'),
                          segments: m.segments,
                          limit: m.per_segment_limit ?? '—',
                      })
                    : t('notif.measure.other', { chars: m.chars })}
                <Taka amount={m.cost} signClass="t-admin-currency-caption" />
                {t('notif.measure.cost')}
            </p>
        );
    };
    const sample = preview.data?.[locale === 'bn' ? 'bn' : 'en'].rendered;

    return (
        <>
            <Panel title={t('notif.editor.title', { channel: t(channelLabel(template.channel)) })} subtitle={t('notif.editor.subtitle')}>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <SegmentedControl
                        label={t('notif.channels.label')}
                        options={available.map((value) => ({ value, label: t(channelLabel(value)) }))}
                        value={template.channel}
                        onChange={onChannel}
                    />
                    <span className="flex items-center gap-2 t-admin-body text-fg-primary">
                        {t('notif.editor.active')}
                        <Toggle checked={active} disabled={!editable || template.category === 'security'} label={t('notif.editor.active')} onChange={(next) => setValue('is_active', next, { shouldDirty: true })} />
                    </span>
                </div>
                {template.channel === 'whatsapp' && <p className="rounded-sm bg-subtle px-3.5 py-3 t-admin-caption text-fg-secondary">{t('notif.editor.whatsappNote')}</p>}
                {!editable && <p className="t-admin-caption text-fg-secondary">{t('notif.editor.readOnly')}</p>}

                <form onSubmit={submit} noValidate className="flex flex-col gap-3.5">
                    <fieldset disabled={!editable || save.isPending} className="flex min-w-0 flex-col gap-3.5">
                        {email && (
                            <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                                <Field label={t('notif.editor.subjectEn')} error={errorOf('subject_en')} required>
                                    {({ id, describedBy, invalid }) => <input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} {...register('subject_en')} />}
                                </Field>
                                <Field label={t('notif.editor.subjectBn')} error={errorOf('subject_bn')} required>
                                    {({ id, describedBy, invalid }) => <input id={id} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} {...register('subject_bn')} />}
                                </Field>
                                <Field label={t('notif.editor.preheaderEn')} error={errorOf('preheader_en')} optional>
                                    {({ id, describedBy, invalid }) => <input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} {...register('preheader_en')} />}
                                </Field>
                                <Field label={t('notif.editor.preheaderBn')} error={errorOf('preheader_bn')} optional>
                                    {({ id, describedBy, invalid }) => <input id={id} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} {...register('preheader_bn')} />}
                                </Field>
                            </div>
                        )}
                        <Field label={t('notif.editor.bodyEn')} error={errorOf('body_en')} required>
                            {({ id, describedBy, invalid }) => (
                                <textarea id={id} rows={4} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} {...register('body_en')} />
                            )}
                        </Field>
                        {measured('en')}
                        <Field label={t('notif.editor.bodyBn')} error={errorOf('body_bn')} required>
                            {({ id, describedBy, invalid }) => (
                                <textarea id={id} rows={4} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} {...register('body_bn')} />
                            )}
                        </Field>
                        {measured('bn')}
                        <p className="t-admin-caption text-fg-tertiary">
                            {t('notif.editor.variables', { list: template.variables.map((v) => `{${v}}`).join(' ') })}
                        </p>
                        {preview.data && preview.data.unknown_variables.length > 0 && (
                            <p className="flex items-start gap-1 t-admin-caption text-fg-primary" role="alert">
                                <WarningIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-warning" />
                                {t('notif.editor.unknown', { list: preview.data.unknown_variables.map((v) => `{${v}}`).join(' ') })}
                            </p>
                        )}
                    </fieldset>
                    {editable && (
                        <div className="flex justify-end">
                            <Button type="submit" disabled={save.isPending}>
                                {t('notif.editor.save')}
                            </Button>
                        </div>
                    )}
                </form>
            </Panel>

            <Panel title={t('notif.preview.title', { channel: t(channelLabel(template.channel)) })} subtitle={t('notif.preview.subtitle')}>
                {sample !== undefined && (
                    <div className="flex w-full max-w-105 flex-col gap-1.5 rounded-md bg-success-subtle px-3.5 py-3">
                        <p lang={locale} className="t-admin-body-lg whitespace-pre-line text-fg-primary">
                            {sample}
                        </p>
                        <p className="flex items-center gap-1 t-admin-caption text-fg-tertiary">
                            <ChannelGlyph size={12} aria-hidden />
                            {t('notif.preview.from')}
                        </p>
                    </div>
                )}
            </Panel>
        </>
    );
}

/** Recent runs (223:16163): the last five log rows for this event, any channel. */
function RecentRuns({ templateKey }: { templateKey: string }) {
    const { t, locale } = useI18n();
    const logs = useLogs({ status: '', channel: '', template: templateKey });
    const rows = logs.data?.pages[0]?.data.slice(0, 5) ?? [];

    return (
        <Panel title={t('notif.runs.title')}>
            {rows.length === 0 ? (
                <p className="t-admin-caption text-fg-tertiary">{t('notif.runs.empty')}</p>
            ) : (
                <ul className="-mt-1.5 flex flex-col">
                    {rows.map((row) => {
                        const at = dhakaWhen(row.created_at ?? row.queued_at ?? '', locale);
                        const ok = row.status === 'sent' || row.status === 'queued' || row.status === 'skipped';
                        return (
                            <li key={row.id} className="flex items-center gap-2.5 border-b border-line-default py-2">
                                {ok ? (
                                    <CheckCircleIcon size={14} aria-hidden className="shrink-0 text-feedback-success" />
                                ) : (
                                    <WarningIcon size={14} aria-hidden className="shrink-0 text-feedback-warning" />
                                )}
                                <span className="t-admin-caption tabular whitespace-nowrap text-fg-tertiary">{at.time}</span>
                                {row.order_number && <span className="t-admin-mono-sm whitespace-nowrap text-fg-primary">{row.order_number}</span>}
                                <span className="min-w-0 flex-1 t-admin-caption text-fg-secondary">
                                    {t(channelLabel(row.channel))} · {t(`notif.status.${row.status}` as MessageKey)}
                                    {row.error === 'opted_out' ? ` · ${t('notif.error.opted_out')}` : row.error ? ` · ${row.error}` : ''}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}
            <Link to={`/automations/log?template=${encodeURIComponent(templateKey)}`} className="t-admin-caption-strong text-fg-link hover:underline">
                {t('notif.runs.viewAll')}
            </Link>
        </Panel>
    );
}
