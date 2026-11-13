import { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useForm, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { updateNotificationTemplateRequestSchema } from '@toymart/api-types/zod';
import { ArchiveIcon, ArrowCounterClockwiseIcon, ClockCounterClockwiseIcon, WarningIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Field, inputClass } from '@/components/ui/Field';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import {
    channels,
    groupOf,
    useArchiveTemplate,
    useRestoreVersion,
    useSetWhatsAppStatus,
    useTemplate,
    useTemplatePreview,
    useTemplates,
    useUpdateTemplate,
    useVersions,
    whatsAppStatuses,
    type NotificationTemplate,
    type TemplateInput,
    type TemplatePreview,
    type WhatsAppStatus,
} from './api';
import { Panel, Taka, Toggle, channelIcon, channelLabel, dhakaWhen, templateTitle, variableHint } from './parts';

type FormValues = {
    body_en: string;
    body_bn: string;
    is_active: boolean;
    subject_en: string;
    subject_bn: string;
    preheader_en: string;
    preheader_bn: string;
    name_en: string;
    name_bn: string;
};
type TextField = Exclude<keyof FormValues, 'is_active'>;

const fields: (keyof FormValues)[] = ['body_en', 'body_bn', 'is_active', 'subject_en', 'subject_bn', 'preheader_en', 'preheader_bn', 'name_en', 'name_bn'];
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
        name_en: tpl.name_en ?? '',
        name_bn: tpl.name_bn ?? '',
    };
}

function toInput(values: FormValues, custom: boolean): TemplateInput {
    const orNull = (value: string) => (value.trim() === '' ? null : value);
    return {
        body_en: values.body_en,
        body_bn: values.body_bn,
        is_active: values.is_active,
        subject_en: orNull(values.subject_en),
        subject_bn: orNull(values.subject_bn),
        preheader_en: orNull(values.preheader_en),
        preheader_bn: orNull(values.preheader_bn),
        ...(custom ? { name_en: orNull(values.name_en), name_bn: orNull(values.name_bn) } : {}),
    };
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
 * Message template editor (P7). Not drawn in Figma: the Automations editor column's Panel,
 * FlowStep tints and preview bubble (B · 36 241:49972), with EN and BN side by side. Every
 * count — characters, encoding, segments, cost, warnings, unknown or missing variables —
 * comes from the server's preview endpoint; the browser only displays it (rule 1).
 */
export function TemplateEditorPage() {
    const { t, locale } = useI18n();
    const params = useParams();
    const id = Number(params.templateId);
    const template = useTemplate(id);
    const all = useTemplates();

    if (template.isError) return <QueryError error={template.error} onRetry={() => void template.refetch()} />;
    if (!template.data) {
        return (
            <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                {t('common.loading')}
            </p>
        );
    }

    const tpl = template.data;
    const siblings = (all.data ?? []).filter((row) => row.key === tpl.key).sort((a, b) => channels.indexOf(a.channel) - channels.indexOf(b.channel));
    const title = templateTitle(tpl, t, locale);

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[
                    { label: t('notif.crumb.settings') },
                    { label: t('notif.crumb.automations'), to: '/automations' },
                    { label: t('notif.lib.crumb'), to: '/automations/templates' },
                    { label: title },
                ]}
                title={title}
                subtitle={`${t(`notif.lib.group.${groupOf(tpl)}` as MessageKey)} · ${tpl.key}`}
                actions={<ArchiveAction template={tpl} />}
            />
            <Editor key={`${tpl.id}.${tpl.updated_at ?? ''}`} template={tpl} siblings={siblings.length > 0 ? siblings : [tpl]} />
        </section>
    );
}

function ArchiveAction({ template }: { template: NotificationTemplate }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const [open, setOpen] = useState(false);
    const archive = useArchiveTemplate(template.id);
    const archived = template.archived_at !== null;

    if (!template.is_custom || !can(me, 'settings.update')) return null;

    const run = (next: boolean) =>
        archive.mutate(next, {
            onSuccess: () => {
                setOpen(false);
                notify(t(next ? 'notif.tpl.archived' : 'notif.tpl.unarchived'));
            },
            onError: (error) => notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
        });

    return archived ? (
        <Button variant="outline" onClick={() => run(false)} disabled={archive.isPending}>
            <ArrowCounterClockwiseIcon size={16} aria-hidden />
            {t('notif.tpl.unarchive')}
        </Button>
    ) : (
        <>
            <Button variant="outline" onClick={() => setOpen(true)}>
                <ArchiveIcon size={16} aria-hidden />
                {t('notif.tpl.archive')}
            </Button>
            <ConfirmDialog
                open={open}
                title={t('notif.tpl.archiveTitle')}
                body={t('notif.tpl.archiveBody')}
                confirmLabel={t('notif.tpl.archive')}
                pending={archive.isPending}
                onConfirm={() => run(true)}
                onClose={() => setOpen(false)}
            />
        </>
    );
}

function Editor({ template, siblings }: { template: NotificationTemplate; siblings: NotificationTemplate[] }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const navigate = useNavigate();
    const archived = template.archived_at !== null;
    const editable = can(me, 'settings.update') && !archived;
    const save = useUpdateTemplate(template.id);
    const form = useForm<FormValues>({
        defaultValues: toForm(template),
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values, template.is_custom), context, options as never);
            return Object.keys(result.errors).length > 0 ? { values: {}, errors: result.errors as never } : { values, errors: {} };
        },
    });
    const { register, handleSubmit, setError, formState, watch, setValue, getValues } = form;
    const bodyEn = watch('body_en');
    const bodyBn = watch('body_bn');
    const active = watch('is_active');
    const preview = useTemplatePreview(template.id, useDebounced({ body_en: bodyEn, body_bn: bodyBn }));
    const email = template.channel === 'email';

    // Variable chips insert at the caret of the last text field that had focus.
    const elements = useRef<Partial<Record<TextField, HTMLInputElement | HTMLTextAreaElement | null>>>({});
    const lastFocused = useRef<TextField>('body_en');
    const textField = (name: TextField) => {
        const { ref, ...rest } = register(name);
        return {
            ...rest,
            ref: (el: HTMLInputElement | HTMLTextAreaElement | null) => {
                ref(el);
                elements.current[name] = el;
            },
            onFocus: () => {
                lastFocused.current = name;
            },
        };
    };
    const insert = (variable: string) => {
        const name = lastFocused.current;
        const el = elements.current[name];
        const current = getValues(name);
        const token = `{${variable}}`;
        const start = el?.selectionStart ?? current.length;
        const end = el?.selectionEnd ?? current.length;
        setValue(name, current.slice(0, start) + token + current.slice(end), { shouldDirty: true });
        requestAnimationFrame(() => {
            el?.focus();
            el?.setSelectionRange(start + token.length, start + token.length);
        });
    };

    const submit = handleSubmit((values) =>
        save.mutate(toInput(values, template.is_custom), {
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
    const pair = 'grid gap-x-4 gap-y-3.5 md:grid-cols-2';
    const input = (name: TextField, label: string, opts: { bn?: boolean; required?: boolean; optional?: boolean } = {}) => (
        <Field label={label} error={errorOf(name)} required={opts.required} optional={opts.optional}>
            {({ id, describedBy, invalid }) => (
                <input id={id} lang={opts.bn ? 'bn' : undefined} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass} ${opts.bn ? 'lang-bn' : ''}`} {...textField(name)} />
            )}
        </Field>
    );

    return (
        <div className="flex flex-col items-start gap-6 xl:flex-row">
            <div className="flex w-full min-w-0 flex-1 flex-col gap-4">
                <Panel title={t('notif.editor.title', { channel: t(channelLabel(template.channel)) })} subtitle={t('notif.editor.subtitle')}>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        {siblings.length > 1 ? (
                            <SegmentedControl
                                label={t('notif.channels.label')}
                                options={siblings.map((row) => ({ value: String(row.id), label: t(channelLabel(row.channel)) }))}
                                value={String(template.id)}
                                onChange={(next) => navigate(`/automations/templates/${next}`)}
                            />
                        ) : (
                            <ChannelBadge template={template} />
                        )}
                        {archived ? (
                            <StatusChip tone="neutral" label={t('notif.lib.status.archived')} />
                        ) : (
                            <span className="flex items-center gap-2 t-admin-body text-fg-primary">
                                {t('notif.editor.active')}
                                <Toggle
                                    checked={active}
                                    disabled={!editable || template.category === 'security'}
                                    label={t('notif.editor.active')}
                                    onChange={(next) => setValue('is_active', next, { shouldDirty: true })}
                                />
                            </span>
                        )}
                    </div>
                    {template.channel === 'whatsapp' && <WhatsAppApproval template={template} editable={editable} />}
                    {template.is_custom && <p className="rounded-sm bg-subtle px-3.5 py-3 t-admin-caption text-fg-secondary">{t(template.kind === 'marketing' ? 'notif.tpl.optOutMarketing' : 'notif.tpl.optOutManual')}</p>}
                    {archived && <p className="t-admin-caption text-fg-secondary">{t('notif.tpl.archivedNote')}</p>}
                    {!archived && !can(me, 'settings.update') && <p className="t-admin-caption text-fg-secondary">{t('notif.editor.readOnly')}</p>}

                    <form onSubmit={submit} noValidate className="flex flex-col gap-3.5">
                        <fieldset disabled={!editable || save.isPending} className="flex min-w-0 flex-col gap-3.5">
                            {template.is_custom && (
                                <div className={pair}>
                                    {input('name_en', t('notif.tpl.nameEn'))}
                                    {input('name_bn', t('notif.tpl.nameBn'), { bn: true })}
                                </div>
                            )}
                            {email && (
                                <div className={pair}>
                                    {input('subject_en', t('notif.editor.subjectEn'), { required: true })}
                                    {input('subject_bn', t('notif.editor.subjectBn'), { bn: true, required: true })}
                                    {input('preheader_en', t('notif.editor.preheaderEn'), { optional: true })}
                                    {input('preheader_bn', t('notif.editor.preheaderBn'), { bn: true, optional: true })}
                                </div>
                            )}
                            <div className={pair}>
                                {(['en', 'bn'] as const).map((side) => (
                                    <div key={side} className="flex min-w-0 flex-col gap-1.5">
                                        <Field label={t(side === 'en' ? 'notif.editor.bodyEn' : 'notif.editor.bodyBn')} error={errorOf(`body_${side}`)} required>
                                            {({ id, describedBy, invalid }) => (
                                                <textarea
                                                    id={id}
                                                    rows={6}
                                                    lang={side}
                                                    aria-describedby={describedBy}
                                                    aria-invalid={invalid || undefined}
                                                    className={`py-2 ${inputClass} ${side === 'bn' ? 'lang-bn' : ''}`}
                                                    {...textField(`body_${side}`)}
                                                />
                                            )}
                                        </Field>
                                        <Measure preview={preview.data} side={side} />
                                    </div>
                                ))}
                            </div>
                            <PreviewProblems preview={preview.data} />
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
                <PreviewPanel template={template} preview={preview.data} />
            </div>
            <div className="flex w-full shrink-0 flex-col gap-4 xl:w-90">
                <Panel title={t('notif.tpl.variables')} subtitle={t('notif.tpl.variablesHint')}>
                    <ul className="flex flex-col gap-1.5">
                        {template.variables.map((variable) => {
                            const hint = variableHint(variable, t);
                            const required = template.required_variables.includes(variable);
                            return (
                                <li key={variable} className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        disabled={!editable}
                                        onClick={() => insert(variable)}
                                        aria-label={t('notif.tpl.insert', { variable: `{${variable}}` })}
                                        className="inline-flex h-7 shrink-0 items-center rounded-sm border border-line-default bg-subtle px-2 t-admin-mono-sm text-fg-primary hover:bg-brand-subtle disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {`{${variable}}`}
                                    </button>
                                    <span className="min-w-0 flex-1 truncate t-admin-caption text-fg-secondary">{hint}</span>
                                    {required && <span className="t-admin-caption-strong text-fg-tertiary">{t('notif.tpl.required')}</span>}
                                </li>
                            );
                        })}
                    </ul>
                </Panel>
                <VersionHistory template={template} editable={editable} />
            </div>
        </div>
    );
}

function ChannelBadge({ template }: { template: NotificationTemplate }) {
    const { t } = useI18n();
    const Glyph = channelIcon[template.channel];
    return (
        <span className="inline-flex items-center gap-1.5 t-admin-body-strong text-fg-primary">
            <Glyph size={16} aria-hidden />
            {t(channelLabel(template.channel))}
        </span>
    );
}

/** Characters · encoding · segments of N · ৳cost a send — exactly as the server measured it. */
function Measure({ preview, side }: { preview: TemplatePreview | undefined; side: 'en' | 'bn' }) {
    const { t } = useI18n();
    const m = preview?.[side];
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
}

function PreviewProblems({ preview }: { preview: TemplatePreview | undefined }) {
    const { t } = useI18n();
    if (!preview) return null;
    const list = (names: string[]) => names.map((v) => `{${v}}`).join(' ');
    const lines = [
        ...preview.warnings.map((warning) => t(warning === 'long_sms_bn' ? 'notif.tpl.warnLongBn' : 'notif.tpl.warnLongEn')),
        preview.unknown_variables.length > 0 ? t('notif.editor.unknown', { list: list(preview.unknown_variables) }) : null,
        preview.missing_variables.length > 0 ? t('notif.tpl.missing', { list: list(preview.missing_variables) }) : null,
    ].filter((line): line is string => line !== null);
    if (lines.length === 0) return null;
    return (
        <ul role="alert" className="flex flex-col gap-1 rounded-sm bg-warning-subtle px-3.5 py-3">
            {lines.map((line) => (
                <li key={line} className="flex items-start gap-1.5 t-admin-caption text-fg-primary">
                    <WarningIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-warning" />
                    {line}
                </li>
            ))}
        </ul>
    );
}

/** Meta approval state — recorded by staff; this app never calls Meta. */
function WhatsAppApproval({ template, editable }: { template: NotificationTemplate; editable: boolean }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const set = useSetWhatsAppStatus(template.id);
    const status = template.whatsapp_status ?? 'draft';
    const tone = status === 'approved' ? 'success' : status === 'submitted' ? 'info' : 'neutral';

    return (
        <div className="flex flex-col gap-2.5 rounded-sm bg-subtle px-3.5 py-3">
            <div className="flex flex-wrap items-center gap-3">
                <span className="t-admin-caption-strong text-fg-primary">{t('notif.tpl.waStatus')}</span>
                <StatusChip tone={tone} label={t(`notif.tpl.wa.${status}` as MessageKey)} />
                {editable && (
                    <label className="ms-auto flex">
                        <span className="sr-only">{t('notif.tpl.waStatus')}</span>
                        <Select
                            size="sm"
                            value={status}
                            disabled={set.isPending}
                            className="w-40"
                            onChange={(e) =>
                                set.mutate(e.target.value as WhatsAppStatus, {
                                    onSuccess: () => notify(t('notif.tpl.waSaved')),
                                    onError: (error) => notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
                                })
                            }
                        >
                            {whatsAppStatuses.map((value) => (
                                <option key={value} value={value}>
                                    {t(`notif.tpl.wa.${value}` as MessageKey)}
                                </option>
                            ))}
                        </Select>
                    </label>
                )}
            </div>
            <p className="t-admin-caption text-fg-secondary">{t('notif.tpl.waNote')}</p>
        </div>
    );
}

/** Both languages rendered with sample values, in the Automations preview bubble (241:50009). */
function PreviewPanel({ template, preview }: { template: NotificationTemplate; preview: TemplatePreview | undefined }) {
    const { t } = useI18n();
    const Glyph = channelIcon[template.channel];
    return (
        <Panel title={t('notif.preview.title', { channel: t(channelLabel(template.channel)) })} subtitle={t('notif.preview.subtitle')}>
            {preview && (
                <div className="grid gap-4 md:grid-cols-2">
                    {(['en', 'bn'] as const).map((side) => (
                        <figure key={side} className="flex min-w-0 flex-col gap-1.5">
                            <figcaption className="t-admin-overline text-fg-secondary">{t(side === 'en' ? 'notif.tpl.english' : 'notif.tpl.bangla')}</figcaption>
                            <div className="flex w-full flex-col gap-1.5 rounded-md bg-success-subtle px-3.5 py-3">
                                <p lang={side} className={`t-admin-body-lg whitespace-pre-line break-words text-fg-primary ${side === 'bn' ? 'lang-bn' : ''}`}>
                                    {preview[side].rendered}
                                </p>
                                <p className="flex items-center gap-1 t-admin-caption text-fg-tertiary">
                                    <Glyph size={12} aria-hidden />
                                    {t('notif.preview.from')}
                                </p>
                            </div>
                        </figure>
                    ))}
                </div>
            )}
        </Panel>
    );
}

/** Append-only history, newest first; restoring writes a new version. */
function VersionHistory({ template, editable }: { template: NotificationTemplate; editable: boolean }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const versions = useVersions(template.id);
    const restore = useRestoreVersion(template.id);
    const rows = versions.data ?? [];
    const current = rows[0]?.version;

    return (
        <Panel title={t('notif.tpl.history')} subtitle={t('notif.tpl.historyHint')}>
            {versions.isError ? (
                <QueryError error={versions.error} onRetry={() => void versions.refetch()} />
            ) : rows.length === 0 ? (
                <p className="t-admin-caption text-fg-tertiary">{t('notif.tpl.historyEmpty')}</p>
            ) : (
                <ol className="-mt-1.5 flex flex-col">
                    {rows.map((row) => {
                        const at = row.created_at ? dhakaWhen(row.created_at, locale) : null;
                        return (
                            <li key={row.id} className="flex items-start gap-2.5 border-b border-line-default py-2 last:border-b-0">
                                <ClockCounterClockwiseIcon size={14} aria-hidden className="mt-0.5 shrink-0 text-fg-tertiary" />
                                <div className="min-w-0 flex-1">
                                    <p className="t-admin-caption-strong text-fg-primary">
                                        {t('notif.tpl.version', { n: row.version })}
                                        {row.version === current && <span className="ms-1.5 font-normal text-fg-tertiary">· {t('notif.tpl.current')}</span>}
                                    </p>
                                    <p className="t-admin-caption tabular text-fg-tertiary">
                                        {[row.created_by_name ?? t('notif.lib.bySystem'), at ? `${at.day} ${at.time}` : null].filter(Boolean).join(' · ')}
                                        {row.restored_from !== null && ` · ${t('notif.tpl.restoredFrom', { n: row.restored_from })}`}
                                    </p>
                                    <p className="truncate t-admin-caption text-fg-secondary" title={row.body_en}>
                                        {row.body_en}
                                    </p>
                                </div>
                                {editable && row.version !== current && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={restore.isPending}
                                        aria-label={t('notif.tpl.restoreVersion', { n: row.version })}
                                        onClick={() =>
                                            restore.mutate(row.version, {
                                                onSuccess: () => notify(t('notif.tpl.restored', { n: row.version })),
                                                onError: (error) => notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
                                            })
                                        }
                                    >
                                        {t('notif.tpl.restore')}
                                    </Button>
                                )}
                            </li>
                        );
                    })}
                </ol>
            )}
        </Panel>
    );
}
