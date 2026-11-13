import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { useForm, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { InfoIcon, PulseIcon } from '@phosphor-icons/react';
import { updateTrackingSettingsRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Field, inputClass } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { useToast } from '@/components/ui/Toast';
import {
    useSaveTrackingSettings,
    useTrackingSettings,
    useTrackingSummary,
    type ConsentMode,
    type DestinationHealth,
    type PurchaseTrigger,
    type TrackingSettings,
    type TrackingSettingsInput,
} from './api';
import { DestinationSettingsDrawer, type DrawerTarget } from './DestinationSettingsDrawer';
import { DIAGNOSTICS_PATH, DestinationCard, Switch, timeAgo, type CardState } from './parts';

const PERMISSION = 'settings.manage_integrations';
export const TRACKING_FORM_ID = 'tracking-settings';

export type TrackingFormValues = {
    server_side_enabled: boolean;
    ga4_enabled: boolean;
    ga4_measurement_id: string;
    ga4_api_secret: string;
    clear_ga4_api_secret: boolean;
    meta_enabled: boolean;
    meta_pixel_id: string;
    meta_access_token: string;
    clear_meta_access_token: boolean;
    meta_test_event_code: string;
    gtm_container_id: string;
    purchase_trigger: PurchaseTrigger;
    consent_mode: ConsentMode;
    consent_policy_version: string;
};

const fields = [
    'server_side_enabled',
    'ga4_enabled',
    'ga4_measurement_id',
    'ga4_api_secret',
    'clear_ga4_api_secret',
    'meta_enabled',
    'meta_pixel_id',
    'meta_access_token',
    'clear_meta_access_token',
    'meta_test_event_code',
    'gtm_container_id',
    'purchase_trigger',
    'consent_mode',
    'consent_policy_version',
] as const satisfies readonly (keyof TrackingFormValues)[];

const validateInput = zodResolver(updateTrackingSettingsRequestSchema);

/** Secrets start blank: the API never sends them, and blank means "keep" on save. */
function toForm(s: TrackingSettings): TrackingFormValues {
    return {
        server_side_enabled: s.server_side_enabled,
        ga4_enabled: s.ga4_enabled,
        ga4_measurement_id: s.ga4_measurement_id ?? '',
        ga4_api_secret: '',
        clear_ga4_api_secret: false,
        meta_enabled: s.meta_enabled,
        meta_pixel_id: s.meta_pixel_id ?? '',
        meta_access_token: '',
        clear_meta_access_token: false,
        meta_test_event_code: s.meta_test_event_code ?? '',
        gtm_container_id: s.gtm_container_id ?? '',
        purchase_trigger: s.purchase_trigger,
        consent_mode: s.consent_mode,
        consent_policy_version: s.consent_policy_version ?? '',
    };
}

function toInput(v: TrackingFormValues): TrackingSettingsInput {
    // `?? ''`: a disabled control (a secret being cleared) reads back as undefined.
    const orNull = (value: string | undefined) => ((value ?? '').trim() === '' ? null : (value ?? '').trim());
    return {
        server_side_enabled: v.server_side_enabled,
        ga4_enabled: v.ga4_enabled,
        ga4_measurement_id: orNull(v.ga4_measurement_id)?.toUpperCase() ?? null,
        ga4_api_secret: orNull(v.ga4_api_secret),
        clear_ga4_api_secret: v.clear_ga4_api_secret,
        meta_enabled: v.meta_enabled,
        meta_pixel_id: orNull(v.meta_pixel_id),
        meta_access_token: orNull(v.meta_access_token),
        clear_meta_access_token: v.clear_meta_access_token,
        meta_test_event_code: orNull(v.meta_test_event_code),
        gtm_container_id: orNull(v.gtm_container_id)?.toUpperCase() ?? null,
        purchase_trigger: v.purchase_trigger,
        consent_mode: v.consent_mode,
        consent_policy_version: orNull(v.consent_policy_version),
    };
}

/**
 * Integrations → Tracking & pixels (Figma B · 25 241:31086; Theme A 217:10806): destination
 * cards (GA4, GTM, Meta Pixel + Conversions API), the event map with the purchase trigger,
 * consent, and a link to Diagnostics. Credentials open in a drawer from each card's
 * "Settings" (IntegrationCard 203:2257). Secrets are write-only: the server returns whether
 * one is set and at most its last 4 characters.
 *
 * Not built from the frame: TikTok Pixel, Google Search Console, Google Merchant Center
 * (no backing integration) and Custom scripts (stored-XSS surface — owner decision).
 */
export function TrackingSettingsPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const allowed = can(me, PERMISSION);
    const settings = useTrackingSettings(allowed);

    if (me && !allowed) {
        return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;
    }
    if (settings.isError) {
        return <QueryError error={settings.error} onRetry={() => void settings.refetch()} />;
    }
    if (!settings.data) {
        return <p aria-busy="true" className="sr-only" />;
    }

    return <TrackingForm settings={settings.data} canDiagnose={can(me, 'settings.view')} />;
}

function TrackingForm({ settings, canDiagnose }: { settings: TrackingSettings; canDiagnose: boolean }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const save = useSaveTrackingSettings();
    const summary = useTrackingSummary(canDiagnose);
    const [drawer, setDrawer] = useState<DrawerTarget | null>(null);

    const form = useForm<TrackingFormValues>({
        defaultValues: toForm(settings),
        // Validate the payload the API will receive, against the API's own schema (rule 27).
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values), context, options as never);
            const errors = result.errors as Partial<Record<keyof TrackingFormValues, { message?: string }>>;
            // The schema's regex messages are generic; say what a valid id looks like.
            if (errors.ga4_measurement_id) errors.ga4_measurement_id = { message: t('tracking.ga4.idInvalid') };
            if (errors.meta_pixel_id) errors.meta_pixel_id = { message: t('tracking.meta.idInvalid') };
            if (errors.gtm_container_id) errors.gtm_container_id = { message: t('tracking.gtm.idInvalid') };
            return Object.keys(errors).length > 0 ? { values: {}, errors: errors as never } : { values, errors: {} };
        },
    });
    const { handleSubmit, setError, formState, reset, watch, setValue, register } = form;

    useEffect(() => reset(toForm(settings)), [settings, reset]);

    const v = watch();
    const health = (key: DestinationHealth['destination']) => summary.data?.destinations.find((d) => d.destination === key);

    const submit = handleSubmit(
        (values) =>
            save.mutate(toInput(values), {
                onSuccess: () => {
                    setDrawer(null);
                    notify(t('tracking.saved'));
                },
                onError: (error) => {
                    if (error instanceof ApiError && error.status === 422) {
                        let first: DrawerTarget | null = null;
                        for (const field of fields) {
                            const message = error.fieldError(field);
                            if (message) {
                                setError(field as FieldPath<TrackingFormValues>, { message });
                                first ??= drawerFor(field);
                            }
                        }
                        if (first) setDrawer(first);
                    } else {
                        notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
                    }
                },
            }),
        // A client-side error on a drawer field: open that drawer so it can be seen.
        (errors) => {
            const field = fields.find((name) => errors[name]);
            const target = field ? drawerFor(field) : null;
            if (target) setDrawer(target);
        },
    );

    const settingsButton = (target: DrawerTarget) => (
        <Button variant="ghost" size="sm" onClick={() => setDrawer(target)} aria-label={t('tracking.card.settingsFor', { name: t(`tracking.${target}.title` as MessageKey) })}>
            {t('tracking.card.settings')}
        </Button>
    );

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('tracking.crumb.settings') }, { label: t('tracking.crumb.integrations'), to: '/integrations' }, { label: t('tracking.crumb.tracking') }]}
                title={t('tracking.title')}
                subtitle={t('tracking.subtitle')}
                actions={
                    <>
                        {canDiagnose && (
                            <Link to={DIAGNOSTICS_PATH} className={buttonClass('outline')}>
                                <PulseIcon size={16} aria-hidden />
                                {t('tracking.diagnostics')}
                            </Link>
                        )}
                        <Button type="submit" form={TRACKING_FORM_ID} disabled={save.isPending}>
                            {save.isPending ? t('tracking.saving') : t('tracking.save')}
                        </Button>
                    </>
                }
            />

            <form id={TRACKING_FORM_ID} onSubmit={submit} noValidate className="flex min-w-0 flex-col gap-6">
                <fieldset disabled={save.isPending} className="flex min-w-0 flex-col gap-6">
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <DestinationCard
                            title={t('tracking.ga4.title')}
                            id={settings.ga4_measurement_id}
                            state={cardState(v.ga4_enabled, settings.ga4_measurement_id !== null && settings.ga4_api_secret_set, health('ga4'), false)}
                            caption={destinationCaption(t, locale, health('ga4'), v.server_side_enabled ? 'tracking.ga4.mpOn' : 'tracking.ga4.mpOff')}
                            toggle={<Switch checked={v.ga4_enabled} label={t('tracking.ga4.enable')} onChange={(on) => setValue('ga4_enabled', on, { shouldDirty: true })} />}
                            action={settingsButton('ga4')}
                        />
                        <DestinationCard
                            title={t('tracking.gtm.title')}
                            id={settings.gtm_container_id}
                            state={settings.gtm_container_id ? 'connected' : 'notConnected'}
                            caption={t(settings.gtm_container_id ? 'tracking.gtm.caption' : 'tracking.gtm.captionNone')}
                            action={settingsButton('gtm')}
                        />
                        <DestinationCard
                            title={t('tracking.meta.title')}
                            id={settings.meta_pixel_id ? t('tracking.meta.pixelId', { id: settings.meta_pixel_id }) : null}
                            state={cardState(v.meta_enabled, settings.meta_pixel_id !== null && settings.meta_access_token_set, health('meta'), settings.meta_test_event_code !== null)}
                            caption={destinationCaption(t, locale, health('meta'), v.server_side_enabled ? 'tracking.meta.capiOn' : 'tracking.meta.capiOff')}
                            toggle={<Switch checked={v.meta_enabled} label={t('tracking.meta.enable')} onChange={(on) => setValue('meta_enabled', on, { shouldDirty: true })} />}
                            action={settingsButton('meta')}
                        />
                    </div>

                    <EventMap trigger={v.purchase_trigger} onTrigger={(value) => setValue('purchase_trigger', value, { shouldDirty: true })} />

                    <FormSection title={t('tracking.consent.title')} description={t('tracking.consent.description')}>
                        <div className="flex flex-col gap-1.5">
                            <span className="t-admin-body-strong text-fg-primary">{t('tracking.consent.mode')}</span>
                            <SegmentedControl
                                label={t('tracking.consent.mode')}
                                value={v.consent_mode}
                                onChange={(value) => setValue('consent_mode', value, { shouldDirty: true })}
                                className="self-start"
                                options={[
                                    { value: 'opt_in', label: t('tracking.consent.optIn') },
                                    { value: 'notice', label: t('tracking.consent.notice') },
                                ]}
                            />
                            <p className="t-admin-caption text-fg-secondary">{t(v.consent_mode === 'opt_in' ? 'tracking.consent.optInHelp' : 'tracking.consent.noticeHelp')}</p>
                        </div>
                        <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                            <Field label={t('tracking.consent.version')} hint={t('tracking.consent.versionHint')} error={formState.errors.consent_policy_version?.message}>
                                {({ id, describedBy, invalid }) => (
                                    <input id={id} spellCheck={false} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} {...register('consent_policy_version')} />
                                )}
                            </Field>
                        </div>
                        <div className="flex items-start gap-4 border-t border-line-default pt-3.5">
                            <div className="flex min-w-0 flex-1 flex-col gap-1">
                                <p className="t-admin-body text-fg-primary">{t('tracking.server.toggle')}</p>
                                <p className="t-admin-caption text-fg-secondary">{t('tracking.server.explain')}</p>
                            </div>
                            <Switch checked={v.server_side_enabled} label={t('tracking.server.toggle')} onChange={(on) => setValue('server_side_enabled', on, { shouldDirty: true })} />
                        </div>
                    </FormSection>
                </fieldset>

                <DestinationSettingsDrawer
                    target={drawer}
                    form={form}
                    settings={settings}
                    saving={save.isPending}
                    onClose={() => setDrawer(null)}
                    onCancel={(target) => {
                        // Put this destination's fields back to the saved values.
                        const saved = toForm(settings);
                        for (const field of drawerFields[target]) setValue(field, saved[field] as never);
                        setDrawer(null);
                    }}
                />
            </form>
        </section>
    );
}

const drawerFields: Record<DrawerTarget, (keyof TrackingFormValues)[]> = {
    ga4: ['ga4_measurement_id', 'ga4_api_secret', 'clear_ga4_api_secret'],
    meta: ['meta_pixel_id', 'meta_access_token', 'clear_meta_access_token', 'meta_test_event_code'],
    gtm: ['gtm_container_id'],
};

function drawerFor(field: keyof TrackingFormValues): DrawerTarget | null {
    for (const [target, names] of Object.entries(drawerFields) as [DrawerTarget, (keyof TrackingFormValues)[]][]) {
        if (names.includes(field)) return target;
    }
    return null;
}

/**
 * Card state from the saved credentials and the server's health numbers (rule 1: the client
 * only picks a label): an error newer than the last success is "Needs attention".
 */
function cardState(enabled: boolean, configured: boolean, health: DestinationHealth | undefined, testMode: boolean): CardState {
    if (!enabled) return 'off';
    if (!configured) return 'notConnected';
    if (health?.last_error_at && (!health.last_success_at || health.last_error_at > health.last_success_at)) return 'attention';
    if (testMode) return 'testMode';
    return 'connected';
}

function destinationCaption(
    t: ReturnType<typeof useI18n>['t'],
    locale: string,
    health: DestinationHealth | undefined,
    channel: MessageKey,
): string {
    const last = health?.last_success_at ? t('tracking.card.lastEvent', { ago: timeAgo(health.last_success_at, locale) }) : t('tracking.card.noEvents');
    if (health?.last_error_at && (!health.last_success_at || health.last_error_at > health.last_success_at)) {
        return t('tracking.card.lastError', { ago: timeAgo(health.last_error_at, locale), error: health.last_error ?? '—' });
    }
    return `${last} · ${t(channel)}`;
}

type MapRow = { event: string; fires: MessageKey; ga4Server: boolean; meta: string; metaServerOnly: boolean; value: MessageKey };

/**
 * Event map (Figma 241:31160): one definition per store event, the GA4 / Meta name and which
 * channel sends it — the dedup matrix the server enforces (docs/api/tracking.md §1.2). The
 * purchase trigger replaces the frame's separate `order_delivered` row: exactly one of the
 * two fires `purchase`, never both. As in the frame, a bare ✓ / name is the browser tag (and,
 * for Meta, its de-duplicated server twin); "(server)" marks a server-only send.
 */
function EventMap({ trigger, onTrigger }: { trigger: PurchaseTrigger; onTrigger: (value: PurchaseTrigger) => void }) {
    const { t } = useI18n();
    const delivered = trigger === 'order_delivered';
    const rows: MapRow[] = [
        { event: 'view_item', fires: 'tracking.map.fires.view_item', ga4Server: false, meta: 'ViewContent', metaServerOnly: false, value: 'tracking.map.value.price' },
        { event: 'add_to_cart', fires: 'tracking.map.fires.add_to_cart', ga4Server: false, meta: 'AddToCart', metaServerOnly: false, value: 'tracking.map.value.items' },
        { event: 'begin_checkout', fires: 'tracking.map.fires.begin_checkout', ga4Server: false, meta: 'InitiateCheckout', metaServerOnly: false, value: 'tracking.map.value.cart' },
        {
            event: 'purchase',
            fires: delivered ? 'tracking.map.fires.purchaseDelivered' : 'tracking.map.fires.purchasePlaced',
            ga4Server: true,
            meta: 'Purchase',
            metaServerOnly: delivered,
            value: delivered ? 'tracking.map.value.delivered' : 'tracking.map.value.order',
        },
        { event: 'refund', fires: 'tracking.map.fires.refund', ga4Server: true, meta: '—', metaServerOnly: false, value: 'tracking.map.value.refund' },
    ];
    const th = 'h-9 px-3 text-start align-middle t-admin-overline whitespace-nowrap text-fg-secondary';
    const td = 'h-10 px-3 py-2.5 align-middle';

    return (
        <section className="flex flex-col rounded-md border border-line-default bg-surface p-5 shadow-e1" aria-labelledby="tracking-event-map">
            <div className="flex flex-col gap-0.5 pb-3.5">
                <h2 id="tracking-event-map" className="t-admin-section text-fg-primary">
                    {t('tracking.map.title')}
                </h2>
                <p className="t-admin-caption text-fg-tertiary">{t('tracking.map.description')}</p>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full min-w-180 border-collapse">
                    <thead className="bg-subtle">
                        <tr>
                            <th className={`${th} w-55`}>{t('tracking.map.col.event')}</th>
                            <th className={`${th} w-75`}>{t('tracking.map.col.fires')}</th>
                            <th className={`${th} w-30`}>{t('tracking.map.col.ga4')}</th>
                            <th className={`${th} w-40`}>{t('tracking.map.col.meta')}</th>
                            <th className={th}>{t('tracking.map.col.value')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.event} className="border-b border-line-default">
                                <td className={`${td} t-admin-mono-sm text-fg-primary`}>{row.event}</td>
                                <td className={`${td} t-admin-body text-fg-secondary`}>{t(row.fires)}</td>
                                <td className={`${td} t-admin-body whitespace-nowrap text-fg-secondary`}>
                                    <span aria-hidden>✓</span>
                                    <span className={row.ga4Server ? undefined : 'sr-only'}> {t(row.ga4Server ? 'tracking.map.serverMark' : 'tracking.map.browser')}</span>
                                </td>
                                <td className={`${td} t-admin-body whitespace-nowrap text-fg-secondary`}>
                                    {row.meta}
                                    {row.metaServerOnly && ` ${t('tracking.map.serverMark')}`}
                                </td>
                                <td className={`${td} t-admin-body text-fg-secondary`}>{t(row.value)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <p className="px-3 pt-2.5 t-admin-caption text-fg-tertiary">{t('tracking.map.channels')}</p>
            <fieldset className="flex flex-col gap-2 p-3">
                <legend className="sr-only">{t('tracking.trigger.legend')}</legend>
                <div className="flex flex-wrap items-center gap-3">
                    <span className="t-admin-body-strong text-fg-primary" aria-hidden>
                        {t('tracking.trigger.legend')}
                    </span>
                    <SegmentedControl
                        label={t('tracking.trigger.legend')}
                        value={trigger}
                        onChange={onTrigger}
                        options={[
                            { value: 'order_placed', label: t('tracking.trigger.placed') },
                            { value: 'order_delivered', label: t('tracking.trigger.delivered') },
                        ]}
                    />
                </div>
                <p className="flex items-start gap-2 t-admin-caption text-fg-primary">
                    <InfoIcon size={16} aria-hidden className="mt-px shrink-0 text-fg-secondary" />
                    <span>{t(delivered ? 'tracking.trigger.deliveredHelp' : 'tracking.trigger.placedHelp')}</span>
                </p>
            </fieldset>
        </section>
    );
}

