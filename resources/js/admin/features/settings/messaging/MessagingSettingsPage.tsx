import { useEffect, useState, type ReactNode } from 'react';
import { useForm, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Navigate, useParams } from 'react-router';
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
import { TabLink, TabList } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';
import { InboxChannelsSection } from './InboxChannelsSection';
import { SecretField } from './SecretField';
import { Switch } from './Switch';
import {
    isWhatsAppDriver,
    smsDrivers,
    whatsAppDrivers,
    useMessagingSettings,
    useSaveMessagingSettings,
    useSendTestSms,
    type MessagingSettings,
    type MessagingSettingsInput,
    type SmsTestResult,
} from './api';

export type MessagingTab = 'sms' | 'whatsapp' | 'messenger';

const PERMISSION = 'settings.manage_integrations';
const FORM_ID = 'messaging-settings';

type FormValues = {
    sms_driver: string;
    reve_base_url: string;
    reve_sender_id: string;
    sms_cost_per_segment: string;
    whatsapp_enabled: boolean;
    whatsapp_driver: string;
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
    'whatsapp_driver',
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
        whatsapp_driver: s.whatsapp_driver,
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
        // An env-only driver (`null`) is not selectable here: leave it out and the server keeps it.
        ...(isWhatsAppDriver(v.whatsapp_driver) ? { whatsapp_driver: v.whatsapp_driver } : {}),
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
 *
 * One route tab per channel (owner request 2026-11-28), each showing only its own sections:
 * SMS (provider, credentials, test send) · WhatsApp (order updates + the Inbox's Cloud API keys)
 * · Facebook Messenger (the Inbox's Messenger keys). The messaging form still saves as one body;
 * a tab that hides a field keeps its loaded value in the form, so nothing is lost on save.
 */
export function MessagingSettingsPage() {
    const { tab: param } = useParams();
    const tab: MessagingTab | null = param === undefined ? 'sms' : param === 'whatsapp' || param === 'messenger' ? param : null;
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

    if (tab === null) {
        return <Navigate to="/integrations/messaging" replace />;
    }
    return <MessagingForm settings={settings.data} tab={tab} />;
}

function MessagingHeader({ actions }: { actions?: ReactNode }) {
    const { t } = useI18n();
    return (
        <div className="flex flex-col gap-4">
            <ContentHeader
                breadcrumb={[{ label: t('messaging.crumb.settings') }, { label: t('messaging.crumb.integrations'), to: '/integrations' }, { label: t('messaging.title') }]}
                title={t('messaging.title')}
                subtitle={t('messaging.subtitle')}
                actions={actions}
            />
            <TabList label={t('messaging.tabs')}>
                <TabLink to="/integrations/messaging" end label={t('messaging.tab.sms')} />
                <TabLink to="/integrations/messaging/whatsapp" label={t('messaging.tab.whatsapp')} />
                <TabLink to="/integrations/messaging/messenger" label={t('messaging.tab.messenger')} />
            </TabList>
        </div>
    );
}

function MessagingForm({ settings, tab }: { settings: MessagingSettings; tab: MessagingTab }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSaveMessagingSettings();

    const form = useForm<FormValues>({
        defaultValues: toForm(settings),
        // Validate the payload the API will receive, against the API's own schema (rule 27).
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values), context, options as never);
            const errors = result.errors as Partial<Record<keyof FormValues, { message?: string }>>;
            // The schema's regex message is generic; say what a non-masking sender looks like.
            if (errors.reve_sender_id) errors.reve_sender_id = { ...errors.reve_sender_id, message: t('messaging.field.senderIdInvalid') };
            return Object.keys(errors).length > 0 ? { values: {}, errors: errors as never } : { values, errors: {} };
        },
    });
    const { register, handleSubmit, setError, formState, reset, watch, setValue } = form;

    useEffect(() => reset(toForm(settings)), [settings, reset]);

    const driver = watch('sms_driver');
    const whatsapp = watch('whatsapp_enabled');
    const whatsappDriver = watch('whatsapp_driver');
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
            {/* The header's save is the SMS/order-updates form's; the Messenger tab has only the keys form and its own save. */}
            <MessagingHeader
                actions={
                    tab !== 'messenger' && (
                        <Button type="submit" form={FORM_ID} disabled={save.isPending}>
                            {save.isPending ? t('messaging.saving') : t('messaging.save')}
                        </Button>
                    )
                }
            />

            {/* Stays mounted on the Messenger tab too (hidden), so unsaved SMS / WhatsApp edits survive a tab switch. */}
            <form id={FORM_ID} hidden={tab === 'messenger'} onSubmit={submit} noValidate className="flex w-full min-w-0 flex-col gap-6">
                {/* Fills the content width (spec rule 4b): the sections pair up at xl instead of one column stretched to 1600px. */}
                <fieldset disabled={save.isPending} className="grid min-w-0 items-start gap-6 xl:grid-cols-2">
                    {tab === 'sms' && (
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
                                            <input
                                                id={id}
                                                inputMode="numeric"
                                                autoComplete="off"
                                                spellCheck={false}
                                                aria-describedby={describedBy}
                                                aria-invalid={invalid || undefined}
                                                className={`h-10 ${monoInputClass}`}
                                                {...register('reve_sender_id')}
                                            />
                                        )}
                                    </Field>
                                </div>
                            )}
                        </FormSection>
                    )}
                    {tab === 'sms' && driver === 'reve' && (
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

                    {tab === 'whatsapp' && (
                        <FormSection title={t('messaging.whatsapp.title')} className="xl:col-span-2">
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
                            <div className="flex flex-col gap-1.5">
                                <span className="t-admin-body-strong text-fg-primary">{t('messaging.whatsapp.driver')}</span>
                                <SegmentedControl
                                    label={t('messaging.whatsapp.driverLabel')}
                                    value={whatsappDriver}
                                    onChange={(value) => setValue('whatsapp_driver', value, { shouldDirty: true })}
                                    className="self-start"
                                    options={whatsAppDrivers.map((value) => ({ value, label: t(`messaging.whatsapp.driver.${value}` as MessageKey) }))}
                                />
                                <p className="t-admin-caption text-fg-secondary">
                                    {isWhatsAppDriver(whatsappDriver)
                                        ? t(whatsappDriver === 'cloud' ? 'messaging.whatsapp.driver.cloudHint' : 'messaging.whatsapp.driver.logHint')
                                        : t('messaging.whatsapp.driver.envOnly', { driver: whatsappDriver })}
                                </p>
                                {fromEnv('whatsapp_driver') && <p className="t-admin-caption text-fg-tertiary">{fromEnv('whatsapp_driver')}</p>}
                                {errorOf('whatsapp_driver') && (
                                    <p role="alert" className="t-admin-caption text-feedback-danger">
                                        {errorOf('whatsapp_driver')}
                                    </p>
                                )}
                            </div>
                        </FormSection>
                    )}
                </fieldset>
            </form>

            {tab === 'sms' && <TestSmsCard dirty={formState.isDirty} />}
            {/* One keys form for both Meta channels, kept mounted between their tabs. */}
            {tab !== 'sms' && <InboxChannelsSection channel={tab} />}
        </section>
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
        <FormSection title={t('messaging.test.title')} description={t('messaging.test.description')} className="w-full">
            <div className="grid items-start gap-x-4 gap-y-3.5 md:grid-cols-2 xl:grid-cols-4">
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
