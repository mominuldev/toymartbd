import { useEffect, useState } from 'react';
import { useForm, type FieldPath, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { CheckCircleIcon, CheckIcon, CopyIcon, WarningCircleIcon, XCircleIcon } from '@phosphor-icons/react';
import { updateInboxChannelSettingsRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { Field, monoInputClass } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { IconButton } from '@/components/ui/IconButton';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { useToast } from '@/components/ui/Toast';
import {
    useInboxChannelSettings,
    useSaveInboxChannelSettings,
    type InboxChannelCredentials,
    type InboxChannelSettings,
    type InboxChannelSettingsInput,
} from './api';
import { SecretField } from './SecretField';

type Channel = 'whatsapp' | 'messenger';
type SecretSlot = 'send_token' | 'app_secret' | 'verify_token';

type FormValues = {
    whatsapp_driver: string;
    whatsapp_phone_number_id: string;
    whatsapp_access_token: string;
    whatsapp_app_secret: string;
    whatsapp_verify_token: string;
    clear_whatsapp_access_token: boolean;
    clear_whatsapp_app_secret: boolean;
    clear_whatsapp_verify_token: boolean;
    messenger_driver: string;
    messenger_page_id: string;
    messenger_page_access_token: string;
    messenger_app_secret: string;
    messenger_verify_token: string;
    clear_messenger_page_access_token: boolean;
    clear_messenger_app_secret: boolean;
    clear_messenger_verify_token: boolean;
};

type SecretInput =
    | 'whatsapp_access_token'
    | 'whatsapp_app_secret'
    | 'whatsapp_verify_token'
    | 'messenger_page_access_token'
    | 'messenger_app_secret'
    | 'messenger_verify_token';

/** Form field names per channel — the API's names (phone_number_id vs page_id, access_token vs page_access_token). */
const FIELDS = {
    whatsapp: {
        driver: 'whatsapp_driver',
        id: 'whatsapp_phone_number_id',
        meta: 'cloud',
        secrets: { send_token: 'whatsapp_access_token', app_secret: 'whatsapp_app_secret', verify_token: 'whatsapp_verify_token' },
    },
    messenger: {
        driver: 'messenger_driver',
        id: 'messenger_page_id',
        meta: 'messenger',
        secrets: { send_token: 'messenger_page_access_token', app_secret: 'messenger_app_secret', verify_token: 'messenger_verify_token' },
    },
} as const satisfies Record<Channel, { driver: keyof FormValues; id: keyof FormValues; meta: string; secrets: Record<SecretSlot, SecretInput> }>;

const SLOTS: SecretSlot[] = ['send_token', 'app_secret', 'verify_token'];
const CHANNELS: Channel[] = ['whatsapp', 'messenger'];
const FORM_ID = 'inbox-channel-settings';

const clearOf = (field: SecretInput) => `clear_${field}` as const;

const validateInput = zodResolver(updateInboxChannelSettingsRequestSchema);

/** Secrets start blank: the API never sends them, and blank means "keep" on save. */
function toForm(s: InboxChannelSettings): FormValues {
    return {
        whatsapp_driver: s.whatsapp.driver,
        whatsapp_phone_number_id: s.whatsapp.account_id_source === 'settings' ? (s.whatsapp.account_id ?? '') : '',
        whatsapp_access_token: '',
        whatsapp_app_secret: '',
        whatsapp_verify_token: '',
        clear_whatsapp_access_token: false,
        clear_whatsapp_app_secret: false,
        clear_whatsapp_verify_token: false,
        messenger_driver: s.messenger.driver,
        messenger_page_id: s.messenger.account_id_source === 'settings' ? (s.messenger.account_id ?? '') : '',
        messenger_page_access_token: '',
        messenger_app_secret: '',
        messenger_verify_token: '',
        clear_messenger_page_access_token: false,
        clear_messenger_app_secret: false,
        clear_messenger_verify_token: false,
    };
}

function toInput(v: FormValues): InboxChannelSettingsInput {
    // `?? ''`: a disabled control (a secret being cleared) reads back as undefined.
    const orNull = (value: string | undefined) => ((value ?? '').trim() === '' ? null : (value ?? '').trim());
    return {
        whatsapp_driver: v.whatsapp_driver as InboxChannelSettingsInput['whatsapp_driver'],
        whatsapp_phone_number_id: orNull(v.whatsapp_phone_number_id),
        whatsapp_access_token: orNull(v.whatsapp_access_token),
        whatsapp_app_secret: orNull(v.whatsapp_app_secret),
        whatsapp_verify_token: orNull(v.whatsapp_verify_token),
        clear_whatsapp_access_token: v.clear_whatsapp_access_token,
        clear_whatsapp_app_secret: v.clear_whatsapp_app_secret,
        clear_whatsapp_verify_token: v.clear_whatsapp_verify_token,
        messenger_driver: v.messenger_driver as InboxChannelSettingsInput['messenger_driver'],
        messenger_page_id: orNull(v.messenger_page_id),
        messenger_page_access_token: orNull(v.messenger_page_access_token),
        messenger_app_secret: orNull(v.messenger_app_secret),
        messenger_verify_token: orNull(v.messenger_verify_token),
        clear_messenger_page_access_token: v.clear_messenger_page_access_token,
        clear_messenger_app_secret: v.clear_messenger_app_secret,
        clear_messenger_verify_token: v.clear_messenger_verify_token,
    };
}

/**
 * Integrations → Messaging, inbox channels: the WhatsApp Cloud API and Messenger credentials
 * the unified inbox uses, so a channel can be connected the day the owner has a Meta account.
 * Its own form and save (`PUT /settings/inbox-channels`), separate from the SMS settings above.
 * No Figma frame: FormSection cards and the secret-field pattern of the rest of this screen.
 * Every channel stays "Not verified" — saving keys never changes that.
 */
export function InboxChannelsSection() {
    const settings = useInboxChannelSettings(true);

    if (settings.isError) {
        return (
            <div className="w-full max-w-4xl">
                <QueryError error={settings.error} onRetry={() => void settings.refetch()} />
            </div>
        );
    }
    if (!settings.data) {
        return <p aria-busy="true" className="sr-only" />;
    }

    return <InboxChannelsForm settings={settings.data} />;
}

function InboxChannelsForm({ settings }: { settings: InboxChannelSettings }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSaveInboxChannelSettings();

    const form = useForm<FormValues>({
        defaultValues: toForm(settings),
        // Validate the payload the API will receive, against the API's own schema (rule 27).
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values), context, options as never);
            const errors = result.errors as Partial<Record<keyof FormValues, { message?: string }>>;
            for (const channel of CHANNELS) {
                const id = FIELDS[channel].id;
                if (errors[id]) errors[id] = { ...errors[id], message: t('messaging.inbox.idInvalid') };
            }
            return Object.keys(errors).length > 0 ? { values: {}, errors: errors as never } : { values, errors: {} };
        },
    });
    const { handleSubmit, setError, reset } = form;

    useEffect(() => reset(toForm(settings)), [settings, reset]);

    const submit = handleSubmit((values) =>
        save.mutate(toInput(values), {
            onSuccess: () => notify(t('messaging.inbox.saved')),
            onError: (error) => {
                if (error instanceof ApiError && error.status === 422) {
                    for (const field of Object.keys(values) as (keyof FormValues)[]) {
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
        <form id={FORM_ID} onSubmit={submit} noValidate aria-labelledby={`${FORM_ID}-title`} className="flex w-full min-w-0 max-w-4xl flex-col gap-6">
            <div className="flex flex-col gap-0.5">
                <h2 id={`${FORM_ID}-title`} className="t-admin-section text-fg-primary">
                    {t('messaging.inbox.title')}
                </h2>
                <p className="t-admin-caption text-fg-tertiary">{t('messaging.inbox.description')}</p>
            </div>
            <fieldset disabled={save.isPending} className="flex min-w-0 flex-col gap-6">
                {CHANNELS.map((channel) => (
                    <ChannelCard key={channel} channel={channel} data={settings[channel]} form={form} />
                ))}
            </fieldset>
            <div className="flex justify-end">
                <Button type="submit" disabled={save.isPending} aria-busy={save.isPending || undefined}>
                    {save.isPending ? t('messaging.saving') : t('messaging.inbox.save')}
                </Button>
            </div>
        </form>
    );
}

function ChannelCard({ channel, data, form }: { channel: Channel; data: InboxChannelCredentials; form: UseFormReturn<FormValues> }) {
    const { t } = useI18n();
    const { register, watch, setValue, formState } = form;
    const f = FIELDS[channel];
    const driver = watch(f.driver);
    const errorOf = (field: keyof FormValues) => formState.errors[field]?.message;
    const key = (suffix: string) => `messaging.inbox.${channel}.${suffix}` as MessageKey;

    return (
        <FormSection title={t(key('title'))} description={t(key('description'))} aside={<Readiness data={data} />}>
            <div className="flex flex-col gap-1.5">
                <span className="t-admin-body-strong text-fg-primary">{t('messaging.inbox.driver')}</span>
                <SegmentedControl
                    label={t('messaging.inbox.driverLabel', { channel: t(key('title')) })}
                    value={driver}
                    onChange={(value) => setValue(f.driver, value, { shouldDirty: true })}
                    className="self-start"
                    options={[
                        { value: 'log', label: t('messaging.inbox.driver.off') },
                        { value: f.meta, label: t(key('driver')) },
                    ]}
                />
                <p className="t-admin-caption text-fg-secondary">{t(driver === 'log' ? 'messaging.inbox.driver.offHint' : 'messaging.inbox.driver.metaHint')}</p>
                {data.driver_source === 'env' && <p className="t-admin-caption text-fg-tertiary">{t('messaging.fromEnv')}</p>}
            </div>

            <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                <Field
                    label={t(key('accountId'))}
                    hint={data.account_id_source === 'env' ? t('messaging.inbox.idFromEnv', { id: data.account_id ?? '' }) : t(key('accountIdHint'))}
                    error={errorOf(f.id)}
                >
                    {({ id, describedBy, invalid }) => (
                        <input
                            id={id}
                            inputMode="numeric"
                            autoComplete="off"
                            spellCheck={false}
                            aria-describedby={describedBy}
                            aria-invalid={invalid || undefined}
                            className={`h-10 ${monoInputClass}`}
                            {...register(f.id)}
                        />
                    )}
                </Field>
                {SLOTS.map((slot) => {
                    const field = f.secrets[slot];
                    const clear = clearOf(field);
                    return (
                        <SecretField
                            key={slot}
                            label={t(key(`secret.${slot}`))}
                            name={field}
                            isSet={data[slot].set}
                            hint={data[slot].hint}
                            source={data[slot].source}
                            clearing={watch(clear)}
                            onClear={(value) => {
                                setValue(field, '');
                                setValue(clear, value, { shouldDirty: true });
                            }}
                            removeLabel={t('messaging.inbox.remove', { name: t(key(`secret.${slot}`)).toLocaleLowerCase() })}
                            error={errorOf(field)}
                            register={register(field)}
                        />
                    );
                })}
            </div>

            <WebhookUrl channel={channel} url={data.webhook_url} />
        </FormSection>
    );
}

function WebhookUrl({ channel, url }: { channel: Channel; url: string }) {
    const { t } = useI18n();
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            /* clipboard refused: the URL stays selectable */
        }
    };

    return (
        <div className="flex flex-col gap-1.5 border-t border-line-default pt-3.5">
            <span className="t-admin-body-strong text-fg-primary">{t('messaging.inbox.webhookUrl')}</span>
            <div className="flex items-center gap-2">
                <code data-webhook-url={channel} className="min-w-0 flex-1 truncate rounded-sm border border-line-default bg-subtle px-3 py-2 t-admin-mono-sm text-fg-primary">
                    {url}
                </code>
                <IconButton aria-label={t('messaging.inbox.copyUrl', { channel: t(`messaging.inbox.${channel}.title` as MessageKey) })} onClick={() => void copy()}>
                    {copied ? <CheckIcon size={18} aria-hidden className="text-feedback-success" /> : <CopyIcon size={18} aria-hidden />}
                </IconButton>
            </div>
            <p className="t-admin-caption text-fg-secondary">{t(`messaging.inbox.${channel}.webhookHint` as MessageKey)}</p>
        </div>
    );
}

/** Webhook ready · Sending ready · Not verified — straight from the server's summary. */
function Readiness({ data }: { data: InboxChannelCredentials }) {
    const { t } = useI18n();
    const item = (ok: boolean, yes: MessageKey, no: MessageKey) => (
        <li className="flex items-center gap-1.5">
            {ok ? <CheckCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-success" /> : <XCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-danger" />}
            {t(ok ? yes : no)}
        </li>
    );

    return (
        <ul aria-label={t('messaging.inbox.readiness')} className="flex shrink-0 flex-col items-end gap-1 t-admin-caption text-fg-secondary">
            {item(data.webhook_ready, 'messaging.inbox.webhookReady', 'messaging.inbox.webhookNotReady')}
            {item(data.send_ready, 'messaging.inbox.sendReady', 'messaging.inbox.sendNotReady')}
            <li className="flex items-center gap-1.5">
                {data.verified ? (
                    <CheckCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-success" />
                ) : (
                    <WarningCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-warning" />
                )}
                {t(data.verified ? 'messaging.inbox.verified' : 'messaging.inbox.notVerified')}
            </li>
        </ul>
    );
}
