import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { CheckIcon, CopyIcon, StarIcon, TruckIcon } from '@phosphor-icons/react';
import { updateCourierCredentialsRequestSchema, updateCourierRatesRequestSchema, updateDeliverySettingsRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button } from '@/components/ui/Button';
import { Field, inputClass, monoInputClass } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { IconButton } from '@/components/ui/IconButton';
import { QueryError } from '@/components/ui/QueryError';
import { TabButton, TabList } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';
import {
    useCourierSettings,
    useDeliverySettings,
    useSaveCredentials,
    useSaveDelivery,
    useSaveRates,
    useUpdateProvider,
    type CourierProvider,
    type RatesInput,
} from '../api';
import { GRAM, TAKA, dhaka, kilos, takaText } from '../format';
import { TrustChip, UnverifiedBanner } from '../parts';

const RATES = 'shipments.manage_rates';
const INTEGRATIONS = 'settings.manage_integrations';

/**
 * Settings → Courier settings & delivery charges (06-admin-feature-checklist §2.3).
 * No Figma frame is drawn for this screen; it is composed from drawn parts: the courier
 * cards of B · 37 Integrations (241:50294) and B · 07's provider strip (241:34066), the
 * CourierProviderCard trust chip (194:1014), the UnverifiedBanner of CourierBookingPanel
 * (196:754) and FormSection (198:901) for the rate card and credentials.
 *
 * Credentials are write-only (rule 20): the API returns `set` and a last-4 hint, a blank
 * field keeps the saved value. Rate cards need shipments.manage_rates; credentials need
 * settings.manage_integrations — a role with only manage_rates sees the rate card but not the
 * credential form (super_admin and, since 2026-11-13, manager hold both).
 */
export function CourierSettingsPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const allowed = can(me, RATES) || can(me, INTEGRATIONS);
    const providers = useCourierSettings(allowed);
    const delivery = useDeliverySettings(allowed);
    const [selected, setSelected] = useState<number | null>(null);

    if (me && !allowed) return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;
    if (providers.isError) return <QueryError error={providers.error} onRetry={() => void providers.refetch()} />;
    if (!providers.data) return <p aria-busy="true" className="t-admin-body text-fg-tertiary">{t('common.loading')}</p>;

    const rows = providers.data;
    const current = rows.find((p) => p.id === selected) ?? rows.find((p) => p.is_default) ?? rows[0];
    const untested = rows.filter((p) => p.is_active && p.trust !== 'verified');

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('nav.group.settings') }, { label: t('nav.integrations'), to: '/integrations' }, { label: t('couriers.settings.crumb') }]}
                title={t('couriers.settings.title')}
                subtitle={t('couriers.settings.subtitle')}
            />

            {untested.map((p) => (
                <UnverifiedBanner key={p.id} name={p.name} settingsLink={false} className="rounded-md border border-line-default" />
            ))}

            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3" role="list" aria-label={t('couriers.settings.providers')}>
                {rows.map((p) => (
                    <ProviderCard key={p.id} provider={p} selected={p.id === current?.id} onSelect={() => setSelected(p.id)} />
                ))}
            </div>

            <DeliveryThresholdSection value={delivery.data?.free_shipping_threshold ?? null} loaded={delivery.isSuccess} editable={can(me, RATES)} />

            {current && (
                <>
                    <TabList label={t('couriers.settings.providers')}>
                        {rows.map((p) => (
                            <TabButton key={p.id} active={p.id === current.id} label={p.name} onClick={() => setSelected(p.id)} />
                        ))}
                    </TabList>
                    <div className="grid items-start gap-6 xl:grid-cols-2">
                        <RateCardSection key={`rates-${current.id}`} provider={current} />
                        {current.can.update_credentials ? (
                            <CredentialsSection key={`creds-${current.id}`} provider={current} />
                        ) : (
                            <FormSection title={t('couriers.credentials.title', { name: current.name })}>
                                <p className="t-admin-body text-fg-secondary">{t('couriers.credentials.noPermission')}</p>
                            </FormSection>
                        )}
                    </div>
                </>
            )}
        </section>
    );
}

/** A provider card (B · 37 Integrations courier card): icon tile, name, on/off, status line, trust chip, default. */
function ProviderCard({ provider, selected, onSelect }: { provider: CourierProvider; selected: boolean; onSelect: () => void }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const update = useUpdateProvider();
    const p = provider;
    const r = p.rates;

    const write = (input: { is_active?: boolean; is_default?: true }, done: MessageKey) =>
        update.mutate(
            { id: p.id, input },
            {
                onSuccess: () => notify(t(done, { name: p.name })),
                onError: (error) => notify(error instanceof ApiError ? (error.fieldError('is_active') ?? error.problem.detail ?? error.problem.title) : t('error.title'), 'danger'),
            },
        );

    return (
        <article
            role="listitem"
            aria-label={p.name}
            className={`flex flex-col gap-3 rounded-lg border bg-surface p-4 shadow-e1 ${selected ? 'border-line-brand' : 'border-line-default'}`}
        >
            <div className="flex items-center gap-3">
                <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-sm border border-line-default bg-subtle">
                    <TruckIcon size={18} className="text-fg-primary" />
                </span>
                <div className="flex min-w-0 flex-1 flex-col">
                    <h2 className="t-admin-body-strong text-fg-primary">{p.name}</h2>
                    {p.is_default && <p className="t-admin-caption text-fg-tertiary">{t('couriers.settings.defaultLine')}</p>}
                </div>
                <Switch
                    checked={p.is_active}
                    label={t(p.is_active ? 'couriers.settings.turnOff' : 'couriers.settings.turnOn', { name: p.name })}
                    disabled={!p.can.update_rates || update.isPending}
                    onChange={(value) => write({ is_active: value }, value ? 'couriers.settings.turnedOn' : 'couriers.settings.turnedOff')}
                />
            </div>
            <p className="t-admin-caption text-fg-secondary">
                {t('couriers.rates.summary', {
                    dhaka: takaText(r.base_charge_dhaka),
                    suburb: takaText(r.base_charge_suburb),
                    outside: takaText(r.base_charge_outside),
                    perKg: takaText(r.per_kg_charge),
                    kg: kilos(r.included_weight_grams),
                    cod: r.cod_fee_percent.replace(/\.?0+$/, ''),
                    ret: takaText(r.return_charge),
                })}
            </p>
            <p className="t-admin-caption text-fg-tertiary">
                {p.requires_location_mapping ? t('couriers.settings.needsMapping') : t('couriers.settings.noMapping')}
                {p.rate_card_updated_at && ` · ${t('couriers.settings.ratesEdited', { date: dhaka(p.rate_card_updated_at, locale).dayYear })}`}
            </p>
            <div className="mt-auto flex flex-wrap items-center gap-2">
                <TrustChip trust={p.trust} />
                <span className="flex-1" />
                {!p.is_default && p.can.update_rates && (
                    <Button variant="ghost" size="sm" disabled={update.isPending} onClick={() => write({ is_default: true }, 'couriers.settings.madeDefault')}>
                        <StarIcon size={16} aria-hidden />
                        {t('couriers.settings.makeDefault')}
                    </Button>
                )}
                <Button variant={selected ? 'secondary' : 'outline'} size="sm" aria-pressed={selected} onClick={onSelect}>
                    {t('couriers.settings.manage')}
                </Button>
            </div>
        </article>
    );
}

function problemFields(error: unknown, fields: string[]): Record<string, string> {
    const out: Record<string, string> = {};
    if (error instanceof ApiError && error.status === 422) {
        for (const f of fields) {
            const message = error.fieldError(f);
            if (message) out[f] = message;
        }
    }
    return out;
}

function DeliveryThresholdSection({ value, loaded, editable }: { value: string | null; loaded: boolean; editable: boolean }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSaveDelivery();
    const [draft, setDraft] = useState('');
    const [error, setError] = useState<string | undefined>();

    useEffect(() => setDraft((value ?? '').replace(/\.00$/, '')), [value]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const input = { free_shipping_threshold: draft.trim() === '' ? null : draft.trim() };
        const parsed = updateDeliverySettingsRequestSchema.safeParse(input);
        if (!parsed.success) {
            setError(t('couriers.rates.invalidMoney'));
            return;
        }
        setError(undefined);
        save.mutate(parsed.data, {
            onSuccess: () => notify(t('couriers.threshold.saved')),
            onError: (e) => setError(problemFields(e, ['free_shipping_threshold']).free_shipping_threshold ?? t('error.title')),
        });
    };

    return (
        <FormSection title={t('couriers.threshold.title')} description={t('couriers.threshold.description')}>
            <form onSubmit={submit} noValidate className="flex flex-wrap items-end gap-4">
                <div className="w-full max-w-xs">
                    <Field label={t('couriers.threshold.label')} hint={t('couriers.threshold.hint')} error={error}>
                        {({ id, describedBy, invalid }) => (
                            <MoneyInput id={id} describedBy={describedBy} invalid={invalid} value={draft} onChange={setDraft} disabled={!editable || !loaded || save.isPending} />
                        )}
                    </Field>
                </div>
                {editable && (
                    <Button type="submit" variant="outline" disabled={save.isPending} className="mb-6">
                        {t('couriers.save')}
                    </Button>
                )}
            </form>
        </FormSection>
    );
}

const rateFields = ['base_charge_dhaka', 'base_charge_suburb', 'base_charge_outside', 'per_kg_charge', 'cod_fee_percent', 'return_charge'] as const;
type RateField = (typeof rateFields)[number];
type RateDraft = Record<RateField, string> & { included_weight_grams: string };

function toDraft(p: CourierProvider): RateDraft {
    const trim = (v: string) => v.replace(/\.00$/, '');
    return {
        base_charge_dhaka: trim(p.rates.base_charge_dhaka),
        base_charge_suburb: trim(p.rates.base_charge_suburb),
        base_charge_outside: trim(p.rates.base_charge_outside),
        per_kg_charge: trim(p.rates.per_kg_charge),
        cod_fee_percent: trim(p.rates.cod_fee_percent),
        return_charge: trim(p.rates.return_charge),
        included_weight_grams: String(p.rates.included_weight_grams),
    };
}

/** The rate card: three zone charges, the included weight + per-started-kg step, COD fee, return charge. */
function RateCardSection({ provider }: { provider: CourierProvider }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const save = useSaveRates();
    const editable = provider.can.update_rates;
    const [draft, setDraft] = useState<RateDraft>(() => toDraft(provider));
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => setDraft(toDraft(provider)), [provider]);

    const set = (field: keyof RateDraft) => (value: string) => setDraft((d) => ({ ...d, [field]: value }));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const input: RatesInput = {
            ...Object.fromEntries(rateFields.map((f) => [f, draft[f].trim()])),
            included_weight_grams: Number(draft.included_weight_grams),
        } as RatesInput;
        const parsed = updateCourierRatesRequestSchema.safeParse(input);
        if (!parsed.success) {
            const next: Record<string, string> = {};
            for (const issue of parsed.error.issues) next[String(issue.path[0])] ??= issue.path[0] === 'included_weight_grams' ? t('couriers.rates.invalidWeight') : t('couriers.rates.invalidMoney');
            setErrors(next);
            return;
        }
        setErrors({});
        save.mutate(
            { id: provider.id, input: parsed.data },
            {
                onSuccess: () => notify(t('couriers.rates.saved', { name: provider.name })),
                onError: (e) => {
                    const fieldErrors = problemFields(e, [...rateFields, 'included_weight_grams']);
                    setErrors(fieldErrors);
                    if (Object.keys(fieldErrors).length === 0) notify(e instanceof ApiError ? e.problem.title : t('error.title'), 'danger');
                },
            },
        );
    };

    const money = (field: RateField, label: MessageKey, hint?: string) => (
        <Field label={t(label)} hint={hint} error={errors[field]}>
            {({ id, describedBy, invalid }) => <MoneyInput id={id} describedBy={describedBy} invalid={invalid} value={draft[field]} onChange={set(field)} disabled={!editable || save.isPending} />}
        </Field>
    );

    return (
        <FormSection
            title={t('couriers.rates.title', { name: provider.name })}
            description={provider.rate_card_updated_at ? t('couriers.rates.edited', { date: dhaka(provider.rate_card_updated_at, locale).dayYear }) : t('couriers.rates.placeholder')}
        >
            <form onSubmit={submit} noValidate className="flex flex-col gap-3.5">
                <p className="t-admin-caption text-fg-secondary">{provider.is_default ? t('couriers.rates.pricesStorefront') : t('couriers.rates.costOnly')}</p>
                <div className="grid gap-x-4 gap-y-3.5 sm:grid-cols-3">
                    {money('base_charge_dhaka', 'couriers.rates.dhaka')}
                    {money('base_charge_suburb', 'couriers.rates.suburb')}
                    {money('base_charge_outside', 'couriers.rates.outside')}
                </div>
                <div className="grid gap-x-4 gap-y-3.5 sm:grid-cols-2">
                    <Field label={t('couriers.rates.included')} hint={t('couriers.rates.includedHint')} error={errors.included_weight_grams}>
                        {({ id, describedBy, invalid }) => (
                            <Affixed suffix={GRAM}>
                                <input
                                    id={id}
                                    inputMode="numeric"
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    disabled={!editable || save.isPending}
                                    value={draft.included_weight_grams}
                                    onChange={(e) => set('included_weight_grams')(e.target.value)}
                                    className={`h-10 pe-8 tabular ${inputClass}`}
                                />
                            </Affixed>
                        )}
                    </Field>
                    {money('per_kg_charge', 'couriers.rates.perKg', t('couriers.rates.perKgHint', { kg: kilos(Number(draft.included_weight_grams) || 0) }))}
                </div>
                <div className="grid gap-x-4 gap-y-3.5 sm:grid-cols-2">
                    <Field label={t('couriers.rates.codFee')} hint={t('couriers.rates.codFeeHint')} error={errors.cod_fee_percent}>
                        {({ id, describedBy, invalid }) => (
                            <Affixed suffix="%">
                                <input
                                    id={id}
                                    inputMode="decimal"
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    disabled={!editable || save.isPending}
                                    value={draft.cod_fee_percent}
                                    onChange={(e) => set('cod_fee_percent')(e.target.value)}
                                    className={`h-10 pe-8 tabular ${inputClass}`}
                                />
                            </Affixed>
                        )}
                    </Field>
                    {money('return_charge', 'couriers.rates.return', t('couriers.rates.returnHint'))}
                </div>
                {editable && (
                    <div className="flex justify-end">
                        <Button type="submit" disabled={save.isPending} aria-busy={save.isPending || undefined}>
                            {save.isPending ? t('couriers.saving') : t('couriers.rates.save')}
                        </Button>
                    </div>
                )}
            </form>
        </FormSection>
    );
}

/** Write-only credentials (rule 20) + the webhook secret and URL. */
function CredentialsSection({ provider }: { provider: CourierProvider }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const save = useSaveCredentials();
    const [values, setValues] = useState<Record<string, string>>({});
    const [clear, setClear] = useState<string[]>([]);
    const [webhook, setWebhook] = useState('');
    const [clearWebhook, setClearWebhook] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [copied, setCopied] = useState(false);

    const reset = () => {
        setValues({});
        setClear([]);
        setWebhook('');
        setClearWebhook(false);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const credentials = Object.fromEntries(Object.entries(values).filter(([, v]) => v.trim() !== ''));
        const input = { credentials, clear, webhook_secret: webhook.trim() === '' ? null : webhook.trim(), clear_webhook_secret: clearWebhook };
        const parsed = updateCourierCredentialsRequestSchema.safeParse(input);
        if (!parsed.success) {
            setErrors({ webhook_secret: t('couriers.credentials.webhookShort') });
            return;
        }
        setErrors({});
        save.mutate(
            { id: provider.id, input: parsed.data },
            {
                onSuccess: () => {
                    reset();
                    notify(t('couriers.credentials.saved', { name: provider.name }));
                },
                onError: (e) => {
                    const next: Record<string, string> = {};
                    if (e instanceof ApiError && e.status === 422) {
                        for (const err of e.problem.errors ?? []) next[err.field.replace(/^credentials\./, '')] = err.message;
                    }
                    setErrors(next);
                    if (Object.keys(next).length === 0) notify(e instanceof ApiError ? e.problem.title : t('error.title'), 'danger');
                },
            },
        );
    };

    const copyUrl = async () => {
        if (!provider.webhook_url) return;
        try {
            await navigator.clipboard.writeText(provider.webhook_url);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            /* clipboard refused: the URL stays selectable */
        }
    };

    const toggleClear = (key: string) => {
        setValues((v) => ({ ...v, [key]: '' }));
        setClear((c) => (c.includes(key) ? c.filter((k) => k !== key) : [...c, key]));
    };

    return (
        <FormSection
            title={t('couriers.credentials.title', { name: provider.name })}
            description={provider.credentials_updated_at ? t('couriers.credentials.updated', { date: dhaka(provider.credentials_updated_at, locale).dayYear }) : t('couriers.credentials.never')}
        >
            <form onSubmit={submit} noValidate className="flex flex-col gap-3.5">
                <p className="t-admin-caption text-fg-secondary">{t(`couriers.credentials.help.${provider.code}` as MessageKey, { name: provider.name })}</p>
                <div className="grid gap-x-4 gap-y-3.5 sm:grid-cols-2">
                    {provider.credential_fields.map((field) => {
                        const clearing = clear.includes(field.key);
                        const status = clearing
                            ? t('couriers.credentials.willRemove')
                            : field.set
                              ? t('couriers.credentials.saved.hint', { hint: field.hint ?? '••••' })
                              : t('couriers.credentials.notSet');
                        return (
                            <div key={field.key} className="flex flex-col gap-1.5">
                                <Field label={t(`couriers.credentials.field.${field.key}` as MessageKey)} hint={status} error={errors[field.key]}>
                                    {({ id, describedBy, invalid }) => (
                                        <input
                                            id={id}
                                            type={field.secret ? 'password' : 'text'}
                                            autoComplete={field.secret ? 'new-password' : 'off'}
                                            spellCheck={false}
                                            data-credential={field.key}
                                            placeholder={field.set && !clearing ? (field.hint ?? '••••') : undefined}
                                            disabled={clearing || save.isPending}
                                            aria-describedby={describedBy}
                                            aria-invalid={invalid || undefined}
                                            value={values[field.key] ?? ''}
                                            onChange={(e) => setValues((v) => ({ ...v, [field.key]: e.target.value }))}
                                            className={`h-10 ${monoInputClass}`}
                                        />
                                    )}
                                </Field>
                                {field.set && (
                                    <div>
                                        <Button variant="ghost" size="sm" onClick={() => toggleClear(field.key)}>
                                            {clearing ? t('couriers.credentials.undo') : t('couriers.credentials.remove')}
                                        </Button>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
                {provider.supports_webhook && (
                    <div className="grid gap-x-4 gap-y-3.5 border-t border-line-default pt-3.5 sm:grid-cols-2">
                        <div className="flex flex-col gap-1.5">
                            <Field
                                label={t('couriers.credentials.webhookSecret')}
                                hint={clearWebhook ? t('couriers.credentials.willRemove') : provider.webhook_secret_set ? t('couriers.credentials.webhookSet') : t('couriers.credentials.notSet')}
                                error={errors.webhook_secret}
                            >
                                {({ id, describedBy, invalid }) => (
                                    <input
                                        id={id}
                                        type="password"
                                        autoComplete="new-password"
                                        spellCheck={false}
                                        disabled={clearWebhook || save.isPending}
                                        aria-describedby={describedBy}
                                        aria-invalid={invalid || undefined}
                                        value={webhook}
                                        onChange={(e) => setWebhook(e.target.value)}
                                        className={`h-10 ${monoInputClass}`}
                                    />
                                )}
                            </Field>
                            {provider.webhook_secret_set && (
                                <div>
                                    <Button variant="ghost" size="sm" onClick={() => setClearWebhook((v) => !v)}>
                                        {clearWebhook ? t('couriers.credentials.undo') : t('couriers.credentials.remove')}
                                    </Button>
                                </div>
                            )}
                        </div>
                        {provider.webhook_url && (
                            <div className="flex flex-col gap-1.5">
                                <span className="t-admin-body-strong text-fg-primary">{t('couriers.credentials.webhookUrl')}</span>
                                <div className="flex items-center gap-2">
                                    <code className="min-w-0 flex-1 truncate rounded-sm border border-line-default bg-subtle px-3 py-2 t-admin-mono-sm text-fg-primary">{provider.webhook_url}</code>
                                    <IconButton aria-label={t('couriers.credentials.copyUrl')} onClick={() => void copyUrl()}>
                                        {copied ? <CheckIcon size={18} aria-hidden className="text-feedback-success" /> : <CopyIcon size={18} aria-hidden />}
                                    </IconButton>
                                </div>
                                <p className="t-admin-caption text-fg-secondary">{t('couriers.credentials.webhookUrlHint', { name: provider.name })}</p>
                            </div>
                        )}
                    </div>
                )}
                <p className="t-admin-caption text-fg-tertiary">{t('couriers.credentials.verifiedNote')}</p>
                <div className="flex justify-end">
                    <Button type="submit" disabled={save.isPending} aria-busy={save.isPending || undefined}>
                        {save.isPending ? t('couriers.saving') : t('couriers.credentials.save')}
                    </Button>
                </div>
            </form>
        </FormSection>
    );
}

function Affixed({ prefix, suffix, children }: { prefix?: string; suffix?: string; children: ReactNode }) {
    return (
        <div className="relative">
            {prefix && <span aria-hidden className="pointer-events-none absolute inset-y-0 start-3 flex items-center t-admin-currency-num text-fg-tertiary">{prefix}</span>}
            {children}
            {suffix && <span aria-hidden className="pointer-events-none absolute inset-y-0 end-3 flex items-center t-admin-caption text-fg-tertiary">{suffix}</span>}
        </div>
    );
}

function MoneyInput({ id, describedBy, invalid, value, onChange, disabled }: { id: string; describedBy?: string; invalid: boolean; value: string; onChange: (v: string) => void; disabled?: boolean }) {
    return (
        <Affixed prefix={TAKA}>
            <input
                id={id}
                inputMode="decimal"
                aria-describedby={describedBy}
                aria-invalid={invalid || undefined}
                disabled={disabled}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className={`h-10 ps-8 tabular ${inputClass}`}
            />
        </Affixed>
    );
}

/** Figma toggle (Integrations card): 36×20 track, bg/brand on, border/strong off. */
function Switch({ checked, label, disabled, onChange }: { checked: boolean; label: string; disabled?: boolean; onChange: (value: boolean) => void }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors duration-150 ease-standard disabled:cursor-not-allowed disabled:opacity-60 ${checked ? 'bg-brand' : 'bg-line-strong'}`}
        >
            <span aria-hidden className={`size-4 rounded-full bg-surface shadow-e1 transition-transform duration-150 ease-standard ${checked ? 'translate-x-4' : 'translate-x-0'}`} />
        </button>
    );
}
