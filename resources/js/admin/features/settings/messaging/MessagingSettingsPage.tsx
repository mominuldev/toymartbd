import { useEffect, useState } from 'react';
import { useForm, type FieldPath, type UseFormRegisterReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { CheckCircleIcon, XCircleIcon } from '@phosphor-icons/react';
import { sendTestSmsRequestSchema, updateMessagingSettingsRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Field, inputClass, monoInputClass } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import {
    smsDrivers,
    useMessagingSettings,
    useSaveMessagingSettings,
    useSendTestSms,
    type MessagingSettings,
    type MessagingSettingsInput,
    type SmsTestResult,
} from './api';

const PERMISSION = 'settings.manage_integrations';
const FORM_ID = 'messaging-settings';

type FormValues = {
    sms_driver: string;
    reve_base_url: string;
    reve_sender_id: string;
    sms_cost_per_segment: string;
    whatsapp_enabled: boolean;
    reve_api_key: string;
    reve_secret_key: string;
    clear_reve_api_key: boolean;
    clear_reve_secret_key: boolean;
};

const fields: (keyof FormValues)[] = [
    'sms_driver',
    'reve_base_url',
    'reve_sender_id',
    'sms_cost_per_segment',
    'whatsapp_enabled',
    'reve_api_key',
    'reve_secret_key',
    'clear_reve_api_key',
    'clear_reve_secret_key',
];

const validateInput = zodResolver(updateMessagingSettingsRequestSchema);

/** Secrets start blank: the API never sends them, and blank means "keep" on save. */
function toForm(s: MessagingSettings): FormValues {
    return {
        sms_driver: s.sms_driver,
        reve_base_url: s.reve_base_url ?? '',
        reve_sender_id: s.reve_sender_id ?? '',
        sms_cost_per_segment: s.sms_cost_per_segment,
        whatsapp_enabled: s.whatsapp_enabled,
        reve_api_key: '',
        reve_secret_key: '',
        clear_reve_api_key: false,
        clear_reve_secret_key: false,
    };
}

function toInput(v: FormValues): MessagingSettingsInput {
    // `?? ''`: a disabled control (a secret being cleared) reads back as undefined.
    const orNull = (value: string | undefined) => ((value ?? '').trim() === '' ? null : (value ?? '').trim());
    return {
        sms_driver: v.sms_driver as MessagingSettingsInput['sms_driver'],
        reve_base_url: orNull(v.reve_base_url),
        reve_sender_id: orNull(v.reve_sender_id),
        sms_cost_per_segment: v.sms_cost_per_segment.trim(),
        whatsapp_enabled: v.whatsapp_enabled,
        reve_api_key: orNull(v.reve_api_key),
        reve_secret_key: orNull(v.reve_secret_key),
        clear_reve_api_key: v.clear_reve_api_key,
        clear_reve_secret_key: v.clear_reve_secret_key,
    };
}

/**
 * Integrations → Messaging (owner request 2026-11-13): the SMS provider and its credentials,
 * entered here instead of the server's env file, plus the WhatsApp-first switch and a test
 * send. No Figma frame exists for this screen; it follows Store settings (B · 38, 241:50827):
 * ContentHeader with the primary "Save changes" top right, FormSection cards, 2-column fields.
 * Secrets are write-only: the server returns whether one is set and its last 4 characters.
 */
export function MessagingSettingsPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const allowed = can(me, PERMISSION);
    const settings = useMessagingSettings(allowed);

    if (me && !allowed) {
        return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;
    }
    if (settings.isError) {
        return <QueryError error={settings.error} onRetry={() => void settings.refetch()} />;
    }
    if (!settings.data) {
        return <p aria-busy="true" className="sr-only" />;
    }

    return <MessagingForm settings={settings.data} />;
}

function MessagingForm({ settings }: { settings: MessagingSettings }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSaveMessagingSettings();

    const form = useForm<FormValues>({
        defaultValues: toForm(settings),
        // Validate the payload the API will receive, against the API's own schema (rule 27).
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values), context, options as never);
            return Object.keys(result.errors).length > 0 ? { values: {}, errors: result.errors as never } : { values, errors: {} };
        },
    });
    const { register, handleSubmit, setError, formState, reset, watch, setValue } = form;

    useEffect(() => reset(toForm(settings)), [settings, reset]);

    const driver = watch('sms_driver');
    const whatsapp = watch('whatsapp_enabled');
    const errorOf = (field: keyof FormValues) => formState.errors[field]?.message;
    const fromEnv = (key: keyof MessagingSettings['sources']) => (settings.sources[key] === 'env' ? t('messaging.fromEnv') : undefined);
    // An env-only driver (sms_net_bd) still shows as the current value, but cannot be picked.
    const driverOptions: string[] = (smsDrivers as readonly string[]).includes(settings.sms_driver) ? [...smsDrivers] : [...smsDrivers, settings.sms_driver];

    const submit = handleSubmit((values) =>
        save.mutate(toInput(values), {
            onSuccess: () => notify(t('messaging.saved')),
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

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('messaging.crumb.settings') }, { label: t('messaging.crumb.integrations'), to: '/integrations' }, { label: t('messaging.title') }]}
                title={t('messaging.title')}
                subtitle={t('messaging.subtitle')}
                actions={
                    <Button type="submit" form={FORM_ID} disabled={save.isPending}>
                        {save.isPending ? t('messaging.saving') : t('messaging.save')}
                    </Button>
                }
            />

            <form id={FORM_ID} onSubmit={submit} noValidate className="flex w-full min-w-0 max-w-4xl flex-col gap-6">
                <fieldset disabled={save.isPending} className="flex min-w-0 flex-col gap-6">
                    <FormSection title={t('messaging.sms.title')} description={t('messaging.sms.description')}>
                        <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                            <Field label={t('messaging.field.driver')} hint={fromEnv('sms_driver')} error={errorOf('sms_driver')}>
                                {({ id, describedBy, invalid }) => (
                                    <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} {...register('sms_driver')}>
                                        {driverOptions.map((value) => (
                                            <option key={value} value={value} disabled={!(smsDrivers as readonly string[]).includes(value)}>
                                                {t(`messaging.driver.${value}` as MessageKey)}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            <Field label={t('messaging.field.cost')} hint={fromEnv('sms_cost_per_segment') ?? t('messaging.field.costHint')} error={errorOf('sms_cost_per_segment')}>
                                {({ id, describedBy, invalid }) => (
                                    <input id={id} inputMode="decimal" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} {...register('sms_cost_per_segment')} />
                                )}
                            </Field>
                        </div>
                        {driver === 'reve' && (
                            <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                                <Field label={t('messaging.field.baseUrl')} hint={fromEnv('reve_base_url') ?? t('messaging.field.baseUrlHint')} error={errorOf('reve_base_url')}>
                                    {({ id, describedBy, invalid }) => (
                                        <input
                                            id={id}
                                            type="url"
                                            spellCheck={false}
                                            autoCapitalize="none"
                                            aria-describedby={describedBy}
                                            aria-invalid={invalid || undefined}
                                            className={`h-10 ${monoInputClass}`}
                                            {...register('reve_base_url')}
                                        />
                                    )}
                                </Field>
                                <Field label={t('messaging.field.senderId')} hint={fromEnv('reve_sender_id') ?? t('messaging.field.senderIdHint')} error={errorOf('reve_sender_id')}>
                                    {({ id, describedBy, invalid }) => (
                                        <input id={id} spellCheck={false} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} {...register('reve_sender_id')} />
                                    )}
                                </Field>
                            </div>
                        )}
                    </FormSection>

                    {driver === 'reve' && (
                        <FormSection title={t('messaging.credentials.title')} description={t('messaging.credentials.description')}>
                            <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                                <SecretField
                                    label={t('messaging.field.apiKey')}
                                    name="reve_api_key"
                                    isSet={settings.reve_api_key_set}
                                    hint={settings.reve_api_key_hint}
                                    source={settings.reve_api_key_source}
                                    clearing={watch('clear_reve_api_key')}
                                    onClear={(value) => {
                                        setValue('reve_api_key', '');
                                        setValue('clear_reve_api_key', value, { shouldDirty: true });
                                    }}
                                    error={errorOf('reve_api_key')}
                                    register={register('reve_api_key')}
                                />
                                <SecretField
                                    label={t('messaging.field.secretKey')}
                                    name="reve_secret_key"
                                    isSet={settings.reve_secret_key_set}
                                    hint={settings.reve_secret_key_hint}
                                    source={settings.reve_secret_key_source}
                                    clearing={watch('clear_reve_secret_key')}
                                    onClear={(value) => {
                                        setValue('reve_secret_key', '');
                                        setValue('clear_reve_secret_key', value, { shouldDirty: true });
                                    }}
                                    error={errorOf('reve_secret_key')}
                                    register={register('reve_secret_key')}
                                />
                            </div>
                        </FormSection>
                    )}

                    <FormSection title={t('messaging.whatsapp.title')}>
                        <div className="flex items-start gap-4">
                            <div className="flex min-w-0 flex-1 flex-col gap-1">
                                <p className="t-admin-body text-fg-primary">{t('messaging.whatsapp.toggle')}</p>
                                <p className="t-admin-caption text-fg-secondary">{t('messaging.whatsapp.explain')}</p>
                                {fromEnv('whatsapp_enabled') && <p className="t-admin-caption text-fg-tertiary">{fromEnv('whatsapp_enabled')}</p>}
                            </div>
                            <Switch
                                checked={whatsapp}
                                label={t('messaging.whatsapp.toggle')}
                                onChange={(value) => setValue('whatsapp_enabled', value, { shouldDirty: true })}
                            />
                        </div>
                    </FormSection>
                </fieldset>
            </form>

            <TestSmsCard dirty={formState.isDirty} />
        </section>
    );
}

type SecretFieldProps = {
    label: string;
    name: string;
    isSet: boolean;
    hint: string | null;
    source: MessagingSettings['reve_api_key_source'];
    clearing: boolean;
    onClear: (value: boolean) => void;
    error?: string;
    register: UseFormRegisterReturn;
};

/** A write-only credential: a password field that starts blank; blank keeps the saved value. */
function SecretField({ label, name, isSet, hint, source, clearing, onClear, error, register }: SecretFieldProps) {
    const { t } = useI18n();
    const status = !isSet
        ? t('messaging.secret.none')
        : source === 'settings'
          ? t('messaging.secret.saved', { hint: hint ?? '••••' })
          : t('messaging.secret.env', { hint: hint ?? '••••' });

    return (
        <div className="flex flex-col gap-1.5">
            <Field label={label} hint={clearing ? t('messaging.secret.willRemove') : status} error={error}>
                {({ id, describedBy, invalid }) => (
                    <input
                        id={id}
                        type="password"
                        autoComplete="new-password"
                        spellCheck={false}
                        data-secret={name}
                        placeholder={isSet && !clearing ? (hint ?? '••••') : undefined}
                        disabled={clearing}
                        aria-describedby={describedBy}
                        aria-invalid={invalid || undefined}
                        className={`h-10 ${monoInputClass}`}
                        {...register}
                    />
                )}
            </Field>
            {source === 'settings' && (
                <div>
                    <Button variant="ghost" size="sm" onClick={() => onClear(!clearing)}>
                        {clearing ? t('messaging.secret.undo') : t('messaging.secret.remove')}
                    </Button>
                </div>
            )}
        </div>
    );
}

/** Figma toggle (Store settings B · 38 rows): 36×20 track, bg/brand on, border/strong off. */
function Switch({ checked, label, onChange }: { checked: boolean; label: string; onChange: (value: boolean) => void }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            onClick={() => onChange(!checked)}
            className={`relative mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors duration-150 ease-standard disabled:cursor-not-allowed disabled:opacity-60 ${
                checked ? 'bg-brand' : 'bg-line-strong'
            }`}
        >
            <span aria-hidden className={`size-4 rounded-full bg-surface shadow-e1 transition-transform duration-150 ease-standard ${checked ? 'translate-x-4' : 'translate-x-0'}`} />
        </button>
    );
}

function TestSmsCard({ dirty }: { dirty: boolean }) {
    const { t } = useI18n();
    const send = useSendTestSms();
    const [phone, setPhone] = useState('');
    const [locale, setLocale] = useState<'en' | 'bn'>('en');
    const [phoneError, setPhoneError] = useState<string | undefined>();
    const [result, setResult] = useState<SmsTestResult | null>(null);
    const [failure, setFailure] = useState<string | null>(null);

    const submit = () => {
        const input = { phone: phone.trim(), locale };
        if (!sendTestSmsRequestSchema.safeParse(input).success) {
            setPhoneError(t('messaging.test.phoneInvalid'));
            return;
        }
        setPhoneError(undefined);
        setFailure(null);
        setResult(null);
        send.mutate(input, {
            onSuccess: setResult,
            onError: (error) => {
                if (error instanceof ApiError && error.status === 422) {
                    setPhoneError(error.fieldError('phone') ?? t('messaging.test.phoneInvalid'));
                } else if (error instanceof ApiError && error.status === 429) {
                    setFailure(t('messaging.test.tooMany'));
                } else {
                    setFailure(error instanceof ApiError ? error.problem.title : t('error.title'));
                }
            },
        });
    };

    return (
        <FormSection title={t('messaging.test.title')} description={t('messaging.test.description')} className="w-full max-w-4xl">
            <div className="grid items-start gap-x-4 gap-y-3.5 md:grid-cols-2">
                <Field label={t('messaging.test.phone')} hint={t('messaging.test.phoneHint')} error={phoneError}>
                    {({ id, describedBy, invalid }) => (
                        <input
                            id={id}
                            type="tel"
                            inputMode="tel"
                            autoComplete="off"
                            value={phone}
                            onChange={(event) => setPhone(event.target.value)}
                            aria-describedby={describedBy}
                            aria-invalid={invalid || undefined}
                            className={`h-10 ${monoInputClass}`}
                        />
                    )}
                </Field>
                <div className="flex flex-col gap-1.5">
                    <span className="t-admin-body-strong text-fg-primary">{t('messaging.test.language')}</span>
                    <SegmentedControl
                        label={t('messaging.test.language')}
                        value={locale}
                        onChange={setLocale}
                        className="self-start"
                        options={[
                            { value: 'en', label: t('messaging.test.english') },
                            { value: 'bn', label: t('messaging.test.bangla'), lang: 'bn' },
                        ]}
                    />
                </div>
            </div>
            {dirty && <p className="t-admin-caption text-fg-secondary">{t('messaging.test.unsaved')}</p>}
            <div>
                <Button variant="outline" onClick={submit} disabled={send.isPending}>
                    {send.isPending ? t('messaging.test.sending') : t('messaging.test.send')}
                </Button>
            </div>
            <div aria-live="polite" className="flex flex-col gap-1">
                {result && (
                    <>
                        <p className="flex items-center gap-1.5 t-admin-body-strong text-fg-primary">
                            {result.accepted ? (
                                <CheckCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-success" />
                            ) : (
                                <XCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-danger" />
                            )}
                            {t(result.accepted ? 'messaging.test.accepted' : 'messaging.test.rejected', { driver: result.driver })}
                        </p>
                        <p className="t-admin-caption text-fg-secondary">
                            {result.accepted
                                ? t('messaging.test.details', { id: result.message_id ?? '—', segments: String(result.segments), cost: result.cost })
                                : result.error}
                        </p>
                        {result.accepted && <p className="t-admin-caption text-fg-tertiary">{t('messaging.test.acceptedNote')}</p>}
                    </>
                )}
                {failure && (
                    <p className="flex items-center gap-1.5 t-admin-body text-fg-primary">
                        <XCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-danger" />
                        {failure}
                    </p>
                )}
            </div>
        </FormSection>
    );
}
