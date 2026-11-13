import type { ReactNode } from 'react';
import { Link, Navigate, useNavigate } from 'react-router';
import { Controller, useForm, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { storeNotificationTemplateRequestSchema } from '@toymart/api-types/zod';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Field, inputClass } from '@/components/ui/Field';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { useToast } from '@/components/ui/Toast';
import { channels, customVariables, useCreateTemplate, type Channel, type NewTemplateInput } from './api';
import { Panel, channelIcon, channelLabel, variableHint } from './parts';

type Kind = NewTemplateInput['kind'];
type Variable = (typeof customVariables)[number];

type FormValues = {
    name_en: string;
    name_bn: string;
    kind: Kind;
    channels: Channel[];
    variables: Variable[];
    body_en: string;
    body_bn: string;
    subject_en: string;
    subject_bn: string;
};

const fields: (keyof FormValues)[] = ['name_en', 'name_bn', 'kind', 'channels', 'variables', 'body_en', 'body_bn', 'subject_en', 'subject_bn'];
const validateInput = zodResolver(storeNotificationTemplateRequestSchema);

function toInput(values: FormValues): NewTemplateInput {
    const email = values.channels.includes('email');
    return {
        name_en: values.name_en,
        name_bn: values.name_bn,
        kind: values.kind,
        channels: values.channels,
        variables: values.variables,
        body_en: values.body_en,
        body_bn: values.body_bn,
        subject_en: email && values.subject_en.trim() !== '' ? values.subject_en : null,
        subject_bn: email && values.subject_bn.trim() !== '' ? values.subject_bn : null,
    };
}

/**
 * New staff-made template (P7): marketing or manual, the channels it exists on, and the
 * generic variables it may use. Nothing sends it yet — a later batch adds sending, and a
 * marketing send will respect the customer's marketing opt-in. Not drawn in Figma: built
 * from the Automations Panel and the form-field patterns.
 */
export function NewTemplatePage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const navigate = useNavigate();
    const create = useCreateTemplate();
    const form = useForm<FormValues>({
        defaultValues: { name_en: '', name_bn: '', kind: 'marketing', channels: ['sms'], variables: ['name', 'store_name'], body_en: '', body_bn: '', subject_en: '', subject_bn: '' },
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values), context, options as never);
            return Object.keys(result.errors).length > 0 ? { values: {}, errors: result.errors as never } : { values, errors: {} };
        },
    });
    const { register, handleSubmit, setError, formState, watch, control } = form;
    const chosen = watch('channels');
    const email = chosen.includes('email');

    if (me && !can(me, 'settings.update')) return <Navigate to="/automations/templates" replace />;

    const submit = handleSubmit((values) =>
        create.mutate(toInput(values), {
            onSuccess: (rows) => {
                notify(t('notif.tpl.created'));
                const first = rows[0];
                navigate(first ? `/automations/templates/${first.id}` : '/automations/templates');
            },
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
    const kind = watch('kind');

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[
                    { label: t('notif.crumb.settings') },
                    { label: t('notif.crumb.automations'), to: '/automations' },
                    { label: t('notif.lib.crumb'), to: '/automations/templates' },
                    { label: t('notif.lib.new') },
                ]}
                title={t('notif.tpl.newTitle')}
                subtitle={t('notif.tpl.newSubtitle')}
            />
            <form onSubmit={submit} noValidate className="flex max-w-240 flex-col gap-4">
                <fieldset disabled={create.isPending} className="flex min-w-0 flex-col gap-4">
                    <Panel title={t('notif.tpl.basics')}>
                        <div className={pair}>
                            <Field label={t('notif.tpl.nameEn')} error={errorOf('name_en')} required>
                                {({ id, describedBy, invalid }) => <input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} {...register('name_en')} />}
                            </Field>
                            <Field label={t('notif.tpl.nameBn')} error={errorOf('name_bn')} required>
                                {({ id, describedBy, invalid }) => <input id={id} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass} lang-bn`} {...register('name_bn')} />}
                            </Field>
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <p className="t-admin-body-strong text-fg-primary">{t('notif.tpl.kind')}</p>
                            <Controller
                                control={control}
                                name="kind"
                                render={({ field }) => (
                                    <SegmentedControl
                                        className="self-start"
                                        label={t('notif.tpl.kind')}
                                        options={(['marketing', 'manual'] as const).map((value) => ({ value, label: t(`notif.lib.kind.${value}` as MessageKey) }))}
                                        value={field.value}
                                        onChange={field.onChange}
                                    />
                                )}
                            />
                            <p className="t-admin-caption text-fg-secondary">{t(kind === 'marketing' ? 'notif.tpl.optOutMarketing' : 'notif.tpl.optOutManual')}</p>
                        </div>
                        <CheckGroup
                            legend={t('notif.lib.col.channels')}
                            error={errorOf('channels') ?? formState.errors.channels?.root?.message}
                            control={control}
                            name="channels"
                            options={channels.map((value) => {
                                const Glyph = channelIcon[value];
                                return {
                                    value,
                                    label: (
                                        <span className="inline-flex items-center gap-1.5">
                                            <Glyph size={14} aria-hidden />
                                            {t(channelLabel(value))}
                                        </span>
                                    ),
                                };
                            })}
                        />
                        {chosen.includes('whatsapp') && <p className="rounded-sm bg-subtle px-3.5 py-3 t-admin-caption text-fg-secondary">{t('notif.tpl.waNote')}</p>}
                        <CheckGroup
                            legend={t('notif.tpl.variables')}
                            hint={t('notif.tpl.variablesPick')}
                            error={errorOf('variables')}
                            control={control}
                            name="variables"
                            options={customVariables.map((value) => ({
                                value,
                                label: (
                                    <span className="inline-flex items-center gap-1.5">
                                        <span className="t-admin-mono-sm">{`{${value}}`}</span>
                                        <span className="t-admin-caption text-fg-secondary">{variableHint(value, t)}</span>
                                    </span>
                                ),
                            }))}
                        />
                    </Panel>
                    <Panel title={t('notif.tpl.copy')} subtitle={t('notif.tpl.copyHint')}>
                        {email && (
                            <div className={pair}>
                                <Field label={t('notif.editor.subjectEn')} error={errorOf('subject_en')} required>
                                    {({ id, describedBy, invalid }) => <input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} {...register('subject_en')} />}
                                </Field>
                                <Field label={t('notif.editor.subjectBn')} error={errorOf('subject_bn')} required>
                                    {({ id, describedBy, invalid }) => <input id={id} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass} lang-bn`} {...register('subject_bn')} />}
                                </Field>
                            </div>
                        )}
                        <div className={pair}>
                            <Field label={t('notif.editor.bodyEn')} error={errorOf('body_en')} required>
                                {({ id, describedBy, invalid }) => <textarea id={id} rows={5} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} {...register('body_en')} />}
                            </Field>
                            <Field label={t('notif.editor.bodyBn')} error={errorOf('body_bn')} required>
                                {({ id, describedBy, invalid }) => (
                                    <textarea id={id} rows={5} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass} lang-bn`} {...register('body_bn')} />
                                )}
                            </Field>
                        </div>
                    </Panel>
                </fieldset>
                <div className="flex justify-end gap-3">
                    <Link to="/automations/templates" className={buttonClass('ghost', 'md')}>
                        {t('common.cancel')}
                    </Link>
                    <Button type="submit" disabled={create.isPending}>
                        {t('notif.tpl.create')}
                    </Button>
                </div>
            </form>
        </section>
    );
}

type CheckName = 'channels' | 'variables';

function CheckGroup<N extends CheckName>({
    legend,
    hint,
    error,
    control,
    name,
    options,
}: {
    legend: string;
    hint?: string;
    error?: string;
    control: ReturnType<typeof useForm<FormValues>>['control'];
    name: N;
    options: { value: FormValues[N][number]; label: ReactNode }[];
}) {
    return (
        <Controller
            control={control}
            name={name}
            render={({ field }) => {
                const value = field.value as string[];
                return (
                    <fieldset className="flex min-w-0 flex-col gap-1.5" aria-invalid={error ? true : undefined}>
                        <legend className="mb-1.5 t-admin-body-strong text-fg-primary">{legend}</legend>
                        {hint && <p className="t-admin-caption text-fg-secondary">{hint}</p>}
                        <div className="flex flex-wrap gap-x-5 gap-y-2">
                            {options.map((option) => (
                                <label key={option.value} className="flex cursor-pointer items-center gap-2 t-admin-body text-fg-primary">
                                    <input
                                        type="checkbox"
                                        className="size-4 accent-brand"
                                        checked={value.includes(option.value)}
                                        onChange={(e) => field.onChange(e.target.checked ? [...value, option.value] : value.filter((v) => v !== option.value))}
                                    />
                                    {option.label}
                                </label>
                            ))}
                        </div>
                        {error && (
                            <p role="alert" className="t-admin-caption text-feedback-danger">
                                {error}
                            </p>
                        )}
                    </fieldset>
                );
            }}
        />
    );
}
