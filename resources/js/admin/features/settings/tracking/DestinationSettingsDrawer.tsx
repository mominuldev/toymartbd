import { useEffect, useId, useRef, useState } from 'react';
import type { UseFormReturn } from 'react-hook-form';
import { CheckCircleIcon, XCircleIcon, XIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { Field, monoInputClass } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { useSendTestEvent, type Destination, type TrackingSettings, type TrackingTestResult } from './api';
import type { TrackingFormValues } from './TrackingSettingsPage';

export type DrawerTarget = Destination | 'gtm';

type Props = {
    target: DrawerTarget | null;
    form: UseFormReturn<TrackingFormValues>;
    settings: TrackingSettings;
    saving: boolean;
    onClose: () => void;
    onCancel: (target: DrawerTarget) => void;
};

/**
 * A destination's connection settings, opened from its card's "Settings" (IntegrationCard
 * 203:2257). The 480 right drawer of CourierBookingPanel 196:743 — no frame of its own. Its
 * fields belong to the page form, so "Save" here saves the whole page; "Cancel" puts this
 * destination's fields back. "Send test event" validates with the SAVED settings.
 */
export function DestinationSettingsDrawer({ target, form, settings, saving, onClose, onCancel }: Props) {
    const { t } = useI18n();
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const open = target !== null;

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }
    }, [open]);

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            onCancel={onClose}
            className="m-0 ms-auto h-dvh max-h-dvh w-full max-w-120 overflow-hidden rounded-s-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay max-md:max-w-none max-md:rounded-none"
        >
            {target && (
                <div className="flex h-full flex-col">
                    <div className="flex shrink-0 items-center gap-2 border-b border-line-default py-4 ps-5 pe-4">
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <h2 id={titleId} className="truncate t-admin-section text-fg-primary">
                                {t(`tracking.${target}.title` as MessageKey)}
                            </h2>
                            <p className="truncate t-admin-caption text-fg-tertiary">{t(`tracking.${target}.drawerSub` as MessageKey)}</p>
                        </div>
                        <IconButton aria-label={t('tracking.drawer.close')} onClick={onClose}>
                            <XIcon size={18} aria-hidden />
                        </IconButton>
                    </div>
                    <div className="flex min-h-0 flex-1 flex-col gap-3.5 overflow-y-auto p-5">
                        {target === 'ga4' && <Ga4Fields form={form} settings={settings} />}
                        {target === 'meta' && <MetaFields form={form} settings={settings} />}
                        {target === 'gtm' && <GtmFields form={form} />}
                        {target !== 'gtm' && <TestEvent destination={target} dirty={form.formState.isDirty} blocked={target === 'meta' && !settings.meta_test_event_code} />}
                    </div>
                    <div className="flex shrink-0 items-center justify-end gap-3 border-t border-line-default bg-surface px-5 py-4">
                        <Button variant="outline" onClick={() => onCancel(target)} disabled={saving}>
                            {t('tracking.drawer.cancel')}
                        </Button>
                        <Button type="submit" disabled={saving}>
                            {saving ? t('tracking.saving') : t('tracking.save')}
                        </Button>
                    </div>
                </div>
            )}
        </dialog>
    );
}

type FieldsProps = { form: UseFormReturn<TrackingFormValues>; settings: TrackingSettings };

function Ga4Fields({ form, settings }: FieldsProps) {
    const { t } = useI18n();
    const { register, formState } = form;
    return (
        <>
            <Field label={t('tracking.ga4.measurementId')} hint={t('tracking.ga4.measurementIdHint')} error={formState.errors.ga4_measurement_id?.message}>
                {({ id, describedBy, invalid }) => (
                    <input id={id} spellCheck={false} autoCapitalize="characters" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${monoInputClass}`} {...register('ga4_measurement_id')} />
                )}
            </Field>
            <SecretField
                form={form}
                name="ga4_api_secret"
                clearName="clear_ga4_api_secret"
                label={t('tracking.ga4.apiSecret')}
                help={t('tracking.ga4.apiSecretHint')}
                isSet={settings.ga4_api_secret_set}
                hint={settings.ga4_api_secret_hint}
                source={settings.ga4_api_secret_source}
            />
        </>
    );
}

function MetaFields({ form, settings }: FieldsProps) {
    const { t } = useI18n();
    const { register, formState } = form;
    return (
        <>
            <Field label={t('tracking.meta.pixel')} hint={t('tracking.meta.pixelHint')} error={formState.errors.meta_pixel_id?.message}>
                {({ id, describedBy, invalid }) => (
                    <input id={id} inputMode="numeric" spellCheck={false} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${monoInputClass}`} {...register('meta_pixel_id')} />
                )}
            </Field>
            <SecretField
                form={form}
                name="meta_access_token"
                clearName="clear_meta_access_token"
                label={t('tracking.meta.token')}
                help={t('tracking.meta.tokenHint')}
                isSet={settings.meta_access_token_set}
                hint={settings.meta_access_token_hint}
                source={settings.meta_access_token_source}
            />
            <Field label={t('tracking.meta.testCode')} hint={t('tracking.meta.testCodeHint')} optional error={formState.errors.meta_test_event_code?.message}>
                {({ id, describedBy, invalid }) => (
                    <input id={id} spellCheck={false} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${monoInputClass}`} {...register('meta_test_event_code')} />
                )}
            </Field>
        </>
    );
}

function GtmFields({ form }: { form: UseFormReturn<TrackingFormValues> }) {
    const { t } = useI18n();
    const { register, formState } = form;
    return (
        <Field label={t('tracking.gtm.containerId')} hint={t('tracking.gtm.containerIdHint')} optional error={formState.errors.gtm_container_id?.message}>
            {({ id, describedBy, invalid }) => (
                <input id={id} spellCheck={false} autoCapitalize="characters" aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${monoInputClass}`} {...register('gtm_container_id')} />
            )}
        </Field>
    );
}

type SecretFieldProps = {
    form: UseFormReturn<TrackingFormValues>;
    name: 'ga4_api_secret' | 'meta_access_token';
    clearName: 'clear_ga4_api_secret' | 'clear_meta_access_token';
    label: string;
    help: string;
    isSet: boolean;
    hint: string | null;
    source: 'settings' | 'env' | null;
};

/** A write-only credential: a password field that starts blank; blank keeps the saved value. */
function SecretField({ form, name, clearName, label, help, isSet, hint, source }: SecretFieldProps) {
    const { t } = useI18n();
    const clearing = form.watch(clearName);
    const status = !isSet ? t('tracking.secret.none') : source === 'settings' ? t('tracking.secret.saved', { hint: hint ?? '••••' }) : t('tracking.secret.env', { hint: hint ?? '••••' });

    return (
        <div className="flex flex-col gap-1.5">
            <Field label={label} hint={clearing ? t('tracking.secret.willRemove') : `${status} ${help}`} error={form.formState.errors[name]?.message}>
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
                        {...form.register(name)}
                    />
                )}
            </Field>
            {source === 'settings' && (
                <div>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            form.setValue(name, '');
                            form.setValue(clearName, !clearing, { shouldDirty: true });
                        }}
                    >
                        {clearing ? t('tracking.secret.undo') : t('tracking.secret.remove')}
                    </Button>
                </div>
            )}
        </div>
    );
}

/** "Send test event": the provider's own validation messages, verbatim (scrubbed server-side). */
function TestEvent({ destination, dirty, blocked }: { destination: Destination; dirty: boolean; blocked: boolean }) {
    const { t } = useI18n();
    const send = useSendTestEvent();
    const [result, setResult] = useState<TrackingTestResult | null>(null);
    const [failure, setFailure] = useState<string | null>(null);

    const run = () => {
        setResult(null);
        setFailure(null);
        send.mutate(
            { destination },
            {
                onSuccess: setResult,
                onError: (error) => {
                    if (error instanceof ApiError && error.status === 429) setFailure(t('tracking.test.tooMany'));
                    else setFailure(error instanceof ApiError ? error.problem.title : t('error.title'));
                },
            },
        );
    };

    return (
        <section className="flex flex-col gap-2 border-t border-line-default pt-3.5" aria-labelledby={`test-${destination}`}>
            <h3 id={`test-${destination}`} className="t-admin-body-strong text-fg-primary">
                {t('tracking.test.title')}
            </h3>
            <p className="t-admin-caption text-fg-secondary">{t(`tracking.test.${destination}Help` as MessageKey)}</p>
            {dirty && <p className="t-admin-caption text-fg-secondary">{t('tracking.test.unsaved')}</p>}
            {blocked && <p className="t-admin-caption text-fg-primary">{t('tracking.test.metaNeedsCode')}</p>}
            <div>
                <Button variant="outline" onClick={run} disabled={send.isPending || blocked}>
                    {send.isPending ? t('tracking.test.sending') : t('tracking.test.send')}
                </Button>
            </div>
            <div aria-live="polite" className="flex flex-col gap-1">
                {result && (
                    <>
                        <p className="flex items-center gap-1.5 t-admin-body-strong text-fg-primary">
                            {result.valid ? (
                                <CheckCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-success" />
                            ) : (
                                <XCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-danger" />
                            )}
                            {t(result.valid ? 'tracking.test.valid' : 'tracking.test.invalid')}
                            {result.http_status !== null && <span className="t-admin-caption text-fg-tertiary">{t('tracking.test.http', { status: String(result.http_status) })}</span>}
                        </p>
                        {result.reason && <p className="t-admin-caption text-fg-secondary">{t(`tracking.test.reason.${result.reason}` as MessageKey)}</p>}
                        {result.messages.length > 0 && (
                            <ul className="flex list-disc flex-col gap-0.5 ps-5 t-admin-caption text-fg-secondary">
                                {result.messages.map((message) => (
                                    <li key={message} className="break-words">
                                        {message}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </>
                )}
                {failure && (
                    <p className="flex items-center gap-1.5 t-admin-body text-fg-primary">
                        <XCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-danger" />
                        {failure}
                    </p>
                )}
            </div>
        </section>
    );
}
