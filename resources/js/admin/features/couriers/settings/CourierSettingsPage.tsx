import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Link } from 'react-router';
import {
    ArrowsClockwiseIcon,
    CaretDownIcon,
    ChartBarIcon,
    CheckCircleIcon,
    CheckIcon,
    CircleIcon,
    CopyIcon,
    InfoIcon,
    KeyIcon,
    LightningIcon,
    MapTrifoldIcon,
    StarIcon,
    StorefrontIcon,
    TruckIcon,
    WarningIcon,
    XCircleIcon,
} from '@phosphor-icons/react';
import { updateCourierCredentialsRequestSchema, updateCourierRatesRequestSchema, updateDeliverySettingsRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button, buttonClass } from '@/components/ui/Button';
import { inputClass, monoInputClass } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import {
    useCheckConnection,
    useCourierSettings,
    useDeliverySettings,
    useSaveCredentials,
    useSaveDelivery,
    useSaveRates,
    useUpdateProvider,
    type ConnectionCheck,
    type CourierProvider,
    type RatesInput,
} from '../api';
import { GRAM, TAKA, dhaka, takaText } from '../format';
import { LOCATION_MAPPING_PATH } from '../parts';

const RATES = 'shipments.manage_rates';
const INTEGRATIONS = 'settings.manage_integrations';
type Tab = 'connection' | 'charges';

/**
 * Settings › Integrations › Couriers (Figma B · 41 premium 513:77225 — Steadfast 513:77228 /
 * 513:77768, Pathao 517:78002, 390 513:78306). At the top, whose prices customers pay and the
 * free-delivery amount; each courier as a three-step checklist (account connected · delivery
 * charges set · first real booking); the chosen courier's connection as numbered steps with a
 * "Check connection" that asks the courier with the SAVED keys; its charges as sentences.
 *
 * Credentials are write-only (rule 20): the API returns `set` and a last-4 hint, a blank field
 * keeps the saved value. Rates need shipments.manage_rates; credentials and the check need
 * settings.manage_integrations. Every price shown is the server's (rule 1).
 */
export function CourierSettingsPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const allowed = can(me, RATES) || can(me, INTEGRATIONS);
    const providers = useCourierSettings(allowed);
    const delivery = useDeliverySettings(allowed);
    const [selected, setSelected] = useState<number | null>(null);
    const [tab, setTab] = useState<Tab | null>(null);

    if (me && !allowed) return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;
    if (providers.isError) return <QueryError error={providers.error} onRetry={() => void providers.refetch()} />;
    if (!providers.data) return <p aria-busy="true" className="t-admin-body text-fg-tertiary">{t('common.loading')}</p>;

    const rows = providers.data;
    const current = rows.find((p) => p.id === selected) ?? rows.find((p) => p.is_default) ?? rows[0];
    const checkout = rows.find((p) => p.is_default) ?? null;
    // Someone who cannot touch credentials lands on the charges they can edit.
    const activeTab: Tab = tab ?? (current?.can.update_credentials ? 'connection' : 'charges');

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('nav.group.settings') }, { label: t('nav.integrations'), to: '/integrations' }, { label: t('couriers.settings.crumb') }]}
                title={t('couriers.settings.title')}
                subtitle={t('couriers.settings.subtitle')}
                actions={
                    <>
                        <Link to={LOCATION_MAPPING_PATH} className={buttonClass('outline', 'md')}>
                            <MapTrifoldIcon size={16} aria-hidden />
                            {t('couriers.page.mapping')}
                        </Link>
                        <Link to="/analytics/couriers" className={buttonClass('outline', 'md')}>
                            <ChartBarIcon size={16} aria-hidden />
                            {t('couriers.page.performance')}
                        </Link>
                    </>
                }
            />

            <CheckoutCard provider={checkout} threshold={delivery.data?.free_shipping_threshold ?? null} loaded={delivery.isSuccess} editable={can(me, RATES)} />

            <ul className="grid gap-5 lg:grid-cols-2" aria-label={t('couriers.settings.providers')}>
                {rows.map((p) => (
                    <li key={p.id} className="flex">
                        <ProviderCard provider={p} selected={p.id === current?.id} onSelect={() => setSelected(p.id)} />
                    </li>
                ))}
            </ul>

            {current && (
                <section className="flex flex-col gap-5 rounded-md border border-line-default bg-surface p-5 shadow-e1 sm:p-6" aria-labelledby="courier-detail">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="courier-detail" className="t-admin-section text-fg-primary">
                            {current.name}
                        </h2>
                        <SegmentedControl
                            label={t('couriers.page.tabs', { name: current.name })}
                            value={activeTab}
                            onChange={setTab}
                            options={[
                                { value: 'connection', label: t('couriers.page.tab.connection') },
                                { value: 'charges', label: t('couriers.page.tab.charges') },
                            ]}
                        />
                    </div>
                    {activeTab === 'connection' ? (
                        current.can.update_credentials ? (
                            <ConnectionSection key={`creds-${current.id}`} provider={current} />
                        ) : (
                            <p className="t-admin-body text-fg-secondary">{t('couriers.credentials.noPermission')}</p>
                        )
                    ) : (
                        <ChargesSection key={`rates-${current.id}`} provider={current} />
                    )}
                </section>
            )}
        </section>
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

/** "At checkout": whose prices customers pay, and from what subtotal delivery is free. */
function CheckoutCard({ provider, threshold, loaded, editable }: { provider: CourierProvider | null; threshold: string | null; loaded: boolean; editable: boolean }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSaveDelivery();
    const [draft, setDraft] = useState('');
    const [error, setError] = useState<string | undefined>();

    useEffect(() => setDraft((threshold ?? '').replace(/\.00$/, '')), [threshold]);

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
        <section className="flex flex-col gap-5 rounded-md border border-line-default bg-surface p-5 shadow-e1 lg:flex-row lg:items-center sm:p-6" aria-labelledby="courier-checkout">
            <div className="flex min-w-0 flex-1 items-center gap-4">
                <span aria-hidden className="hidden size-11 shrink-0 place-items-center rounded-md bg-brand-subtle sm:grid">
                    <StorefrontIcon size={22} className="text-fg-brand" />
                </span>
                <div className="flex min-w-0 flex-col gap-1">
                    <p id="courier-checkout" className="t-admin-overline text-fg-secondary">
                        {t('couriers.page.checkout')}
                    </p>
                    <p className="t-admin-body-strong text-fg-primary">{provider ? t('couriers.page.checkoutBy', { name: provider.name }) : t('couriers.page.checkoutNone')}</p>
                    {provider && (
                        <p className="t-admin-caption tabular text-fg-secondary">
                            {t('couriers.page.checkoutPrices', {
                                dhaka: takaText(provider.rates.base_charge_dhaka),
                                suburb: takaText(provider.rates.base_charge_suburb),
                                outside: takaText(provider.rates.base_charge_outside),
                            })}
                        </p>
                    )}
                </div>
            </div>
            <form onSubmit={submit} noValidate className="flex flex-col gap-1.5 border-line-default lg:border-s lg:ps-6">
                <label htmlFor="free-delivery" className="t-admin-caption-strong text-fg-primary">
                    {t('couriers.threshold.label')}
                </label>
                <span className="flex items-center gap-2">
                    <Affixed prefix={TAKA}>
                        <input
                            id="free-delivery"
                            inputMode="decimal"
                            aria-describedby="free-delivery-hint"
                            aria-invalid={error ? true : undefined}
                            disabled={!editable || !loaded || save.isPending}
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            className={`h-10 w-36 ps-8 tabular ${inputClass}`}
                        />
                    </Affixed>
                    {editable && (
                        <Button type="submit" variant="outline" disabled={save.isPending}>
                            {t('couriers.save')}
                        </Button>
                    )}
                </span>
                <p id="free-delivery-hint" className={`t-admin-caption ${error ? 'text-feedback-danger' : 'text-fg-tertiary'}`} role={error ? 'alert' : undefined}>
                    {error ?? t('couriers.threshold.hint')}
                </p>
            </form>
        </section>
    );
}

type StepState = 'done' | 'warn' | 'todo';
const stepIcon: Record<StepState, ReactNode> = {
    done: <CheckCircleIcon size={20} aria-hidden className="shrink-0 text-feedback-success" />,
    warn: <WarningIcon size={20} aria-hidden className="shrink-0 text-feedback-warning" />,
    todo: <CircleIcon size={20} aria-hidden className="shrink-0 text-fg-tertiary" />,
};

/** A courier as a three-step checklist, its on/off switch, and whether it prices checkout. */
function ProviderCard({ provider, selected, onSelect }: { provider: CourierProvider; selected: boolean; onSelect: () => void }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const update = useUpdateProvider();
    const p = provider;

    const write = (input: { is_active?: boolean; is_default?: true }, done: MessageKey) =>
        update.mutate(
            { id: p.id, input },
            {
                onSuccess: () => notify(t(done, { name: p.name })),
                onError: (error) => notify(error instanceof ApiError ? (error.fieldError('is_active') ?? error.problem.detail ?? error.problem.title) : t('error.title'), 'danger'),
            },
        );

    const status: { tone: StatusTone; key: MessageKey } =
        p.trust === 'verified' ? { tone: 'success', key: 'couriers.page.status.live' } : p.trust === 'unverified' ? { tone: 'warning', key: 'couriers.page.status.test' } : { tone: 'neutral', key: 'couriers.page.status.off' };
    const balance = p.balance !== null && p.balance_checked_at ? t('couriers.page.step.connectedBalance', { name: p.name, when: dhaka(p.balance_checked_at, locale).time, balance: takaText(p.balance) }) : null;
    const steps: [StepState, MessageKey, string][] = [
        [p.credentials_complete ? 'done' : 'todo', 'couriers.page.step.account', p.credentials_complete ? (balance ?? t('couriers.page.step.accountDone')) : t('couriers.page.step.accountTodo', { name: p.name })],
        [
            p.rate_card_updated_at ? 'done' : 'warn',
            'couriers.page.step.charges',
            p.rate_card_updated_at ? t('couriers.page.step.chargesDone', { date: dhaka(p.rate_card_updated_at, locale).dayYear }) : t('couriers.page.step.chargesTodo'),
        ],
        [p.verified ? 'done' : 'todo', 'couriers.page.step.booking', p.verified ? t('couriers.page.step.bookingDone') : p.credentials_complete ? t('couriers.page.step.bookingTodo') : t('couriers.page.step.bookingBlocked')],
    ];

    return (
        <article
            aria-label={p.name}
            className={`flex w-full flex-col gap-4 rounded-md bg-surface p-5 shadow-e1 ${selected ? 'border-2 border-line-brand' : 'border border-line-default'}`}
        >
            <div className="flex items-center gap-3">
                <span aria-hidden className="grid size-10 shrink-0 place-items-center rounded-sm bg-subtle">
                    <TruckIcon size={20} className="text-fg-primary" />
                </span>
                <div className="flex min-w-0 flex-1 flex-col">
                    <h2 className="t-admin-body-strong text-fg-primary">{p.name}</h2>
                    <p className="t-admin-caption text-fg-secondary">{p.requires_location_mapping ? t('couriers.settings.needsMapping') : t('couriers.settings.noMapping')}</p>
                </div>
                <StatusChip label={t(status.key)} tone={status.tone} />
            </div>
            <ol className="flex flex-col gap-3 rounded-md bg-subtle p-4" aria-label={t('couriers.page.steps', { name: p.name })}>
                {steps.map(([state, title, line]) => (
                    <li key={title} className="flex items-start gap-3">
                        {stepIcon[state]}
                        <span className="flex min-w-0 flex-col">
                            <span className={`t-admin-body-strong ${state === 'todo' ? 'text-fg-secondary' : 'text-fg-primary'}`}>
                                {t(title)}
                                <span className="sr-only">{` — ${t(`couriers.page.state.${state}` as MessageKey)}`}</span>
                            </span>
                            <span className="t-admin-caption tabular text-fg-secondary">{line}</span>
                        </span>
                    </li>
                ))}
            </ol>
            <div className="mt-auto flex flex-wrap items-center gap-3">
                <Switch
                    checked={p.is_active}
                    label={t(p.is_active ? 'couriers.settings.turnOff' : 'couriers.settings.turnOn', { name: p.name })}
                    disabled={!p.can.update_rates || update.isPending}
                    onChange={(value) => write({ is_active: value }, value ? 'couriers.settings.turnedOn' : 'couriers.settings.turnedOff')}
                />
                <span aria-hidden className="t-admin-body text-fg-secondary">
                    {t(p.is_active ? 'couriers.page.bookingsOn' : 'couriers.page.bookingsOff')}
                </span>
                <span className="flex-1" />
                {p.is_default ? (
                    <StatusChip label={t('couriers.page.pricesCheckout')} tone="brand" />
                ) : (
                    p.can.update_rates && (
                        <Button variant="ghost" size="sm" disabled={update.isPending} onClick={() => write({ is_default: true }, 'couriers.settings.madeDefault')}>
                            <StarIcon size={16} aria-hidden />
                            {t('couriers.settings.makeDefault')}
                        </Button>
                    )
                )}
                <Button variant={selected ? 'primary' : 'outline'} size="sm" aria-pressed={selected} onClick={onSelect}>
                    {p.credentials_complete ? t('couriers.settings.manage') : t('couriers.page.setUp')}
                </Button>
            </div>
        </article>
    );
}

function Step({ n, title, body, children }: { n: number; title: string; body?: string; children?: ReactNode }) {
    return (
        <li className="flex items-start gap-3">
            <span aria-hidden className="grid size-7 shrink-0 place-items-center rounded-full bg-brand-subtle t-admin-caption-strong text-fg-brand">
                {n}
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <h3 className="t-admin-body-strong text-fg-primary">{title}</h3>
                {body && <p className="t-admin-body text-fg-secondary">{body}</p>}
                {children}
            </div>
        </li>
    );
}

/** The answer of "Check connection", in words. */
function CheckResult({ provider, result, dirty }: { provider: CourierProvider; result: ConnectionCheck | null; dirty: boolean }) {
    const { t, locale } = useI18n();
    if (dirty)
        return (
            <p className="flex items-center gap-2 rounded-sm bg-subtle px-3 py-2 t-admin-body text-fg-secondary">
                <InfoIcon size={18} aria-hidden className="shrink-0 text-feedback-info" />
                {t('couriers.check.saveFirst')}
            </p>
        );
    if (!result) return null;
    const when = dhaka(result.checked_at, locale).time;
    const text = result.ok
        ? result.store_name
            ? t('couriers.check.okStore', { name: provider.name, store: result.store_name })
            : result.balance !== null
              ? t('couriers.check.okBalance', { name: provider.name, balance: takaText(result.balance), when })
              : t('couriers.check.ok', { name: provider.name })
        : t(`couriers.check.reason.${result.reason}` as MessageKey, { name: provider.name });
    return (
        <p role="status" className={`flex items-center gap-2 rounded-sm px-3 py-2 t-admin-body text-fg-primary ${result.ok ? 'bg-success-subtle' : 'bg-danger-subtle'}`}>
            {result.ok ? <CheckCircleIcon size={18} aria-hidden className="shrink-0 text-feedback-success" /> : <XCircleIcon size={18} aria-hidden className="shrink-0 text-feedback-danger" />}
            <span>
                {text}
                {result.sandbox ? ` · ${t('couriers.check.sandbox')}` : ''}
            </span>
        </p>
    );
}

/** Connection as numbered steps: get the keys, paste them, (Pathao: the pickup store), check, (Pathao: match areas). */
function ConnectionSection({ provider }: { provider: CourierProvider }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSaveCredentials();
    const check = useCheckConnection();
    const [values, setValues] = useState<Record<string, string>>({});
    const [clear, setClear] = useState<string[]>([]);
    const [webhook, setWebhook] = useState('');
    const [clearWebhook, setClearWebhook] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [copied, setCopied] = useState(false);
    const [result, setResult] = useState<ConnectionCheck | null>(null);
    const pathao = provider.code === 'pathao';
    const keyFields = provider.credential_fields.filter((f) => f.key !== 'store_id');
    const storeField = provider.credential_fields.find((f) => f.key === 'store_id');
    const dirty = Object.values(values).some((v) => v.trim() !== '') || clear.length > 0 || webhook.trim() !== '' || clearWebhook;

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
                    setResult(null);
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

    const credentialInput = (field: CourierProvider['credential_fields'][number], wide = false) => {
        const clearing = clear.includes(field.key);
        const id = `credential-${field.key}`;
        const status = clearing ? t('couriers.credentials.willRemove') : field.set ? t('couriers.credentials.saved.hint', { hint: field.hint ?? '••••' }) : t('couriers.credentials.notSet');
        return (
            <div key={field.key} className={`flex flex-col gap-1.5 ${wide ? '' : 'min-w-0 flex-1'}`}>
                <label htmlFor={id} className="t-admin-caption-strong text-fg-primary">
                    {t(`couriers.credentials.field.${field.key}` as MessageKey)}
                </label>
                <span className="relative">
                    {field.key !== 'store_id' && <KeyIcon size={16} aria-hidden className="pointer-events-none absolute inset-y-0 start-3 my-auto text-fg-tertiary" />}
                    <input
                        id={id}
                        type={field.secret ? 'password' : 'text'}
                        autoComplete={field.secret ? 'new-password' : 'off'}
                        spellCheck={false}
                        inputMode={field.key === 'store_id' ? 'numeric' : undefined}
                        data-credential={field.key}
                        placeholder={field.set && !clearing ? (field.hint ?? '••••') : undefined}
                        disabled={clearing || save.isPending}
                        aria-describedby={`${id}-hint`}
                        aria-invalid={errors[field.key] ? true : undefined}
                        value={values[field.key] ?? ''}
                        onChange={(e) => setValues((v) => ({ ...v, [field.key]: e.target.value }))}
                        className={`h-10 ${field.key === 'store_id' ? 'w-56' : 'w-full ps-9'} ${monoInputClass}`}
                    />
                </span>
                <span className="flex items-center gap-2">
                    <span id={`${id}-hint`} className={`min-w-0 flex-1 t-admin-caption ${errors[field.key] ? 'text-feedback-danger' : 'text-fg-tertiary'}`}>
                        {errors[field.key] ?? status}
                    </span>
                    {field.set && (
                        <Button variant="ghost" size="sm" onClick={() => toggleClear(field.key)}>
                            {clearing ? t('couriers.credentials.undo') : t('couriers.credentials.remove')}
                        </Button>
                    )}
                </span>
            </div>
        );
    };

    let n = 0;
    return (
        <form onSubmit={submit} noValidate className="flex flex-col gap-6">
            <ol className="flex flex-col gap-6">
                <Step n={++n} title={t('couriers.connect.keys', { name: provider.name })} body={t(`couriers.credentials.help.${provider.code}` as MessageKey, { name: provider.name })} />
                <Step n={++n} title={t('couriers.connect.paste')} body={t('couriers.connect.pasteBody')}>
                    <div className="grid gap-4 sm:grid-cols-2">{keyFields.map((f) => credentialInput(f))}</div>
                </Step>
                {pathao && storeField && (
                    <Step n={++n} title={t('couriers.connect.store')} body={t('couriers.connect.storeBody')}>
                        {credentialInput(storeField, true)}
                    </Step>
                )}
                <Step n={++n} title={t('couriers.connect.check')} body={t('couriers.connect.checkBody', { name: provider.name })}>
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <Button
                            variant="outline"
                            disabled={dirty || check.isPending || !provider.credentials_complete}
                            aria-busy={check.isPending || undefined}
                            onClick={() =>
                                check.mutate(provider.id, {
                                    onSuccess: setResult,
                                    onError: (e) => notify(e instanceof ApiError ? (e.problem.detail ?? e.problem.title) : t('error.title'), 'danger'),
                                })
                            }
                        >
                            <ArrowsClockwiseIcon size={16} aria-hidden />
                            {check.isPending ? t('couriers.check.checking') : t('couriers.check.button')}
                        </Button>
                        <div className="min-w-0 flex-1">
                            {!provider.credentials_complete && !dirty ? (
                                <p className="flex items-center gap-2 rounded-sm bg-subtle px-3 py-2 t-admin-body text-fg-secondary">
                                    <InfoIcon size={18} aria-hidden className="shrink-0 text-feedback-info" />
                                    {t('couriers.check.needKeys')}
                                </p>
                            ) : (
                                <CheckResult provider={provider} result={result} dirty={dirty} />
                            )}
                        </div>
                    </div>
                </Step>
                {provider.requires_location_mapping && (
                    <Step n={++n} title={t('couriers.connect.areas', { name: provider.name })} body={t('couriers.connect.areasBody', { name: provider.name })}>
                        <Link to={LOCATION_MAPPING_PATH} className={`${buttonClass('outline', 'sm')} self-start`}>
                            <MapTrifoldIcon size={16} aria-hidden />
                            {t('couriers.page.mapping')}
                        </Link>
                    </Step>
                )}
            </ol>

            {provider.supports_webhook && (
                <details className="group rounded-md border border-line-default">
                    <summary className="flex cursor-pointer list-none flex-wrap items-center gap-3 px-5 py-4">
                        <LightningIcon size={18} aria-hidden className="shrink-0 text-fg-secondary" />
                        <span className="flex min-w-0 flex-1 basis-56 flex-col">
                            <span className="t-admin-body-strong text-fg-primary">{t('couriers.webhook.title')}</span>
                            <span className="t-admin-caption text-fg-secondary">{t(`couriers.webhook.body.${provider.code}` as MessageKey, { name: provider.name })}</span>
                        </span>
                        <StatusChip label={provider.webhook_secret_set ? t('couriers.webhook.on') : t('couriers.webhook.off')} tone={provider.webhook_secret_set ? 'success' : 'neutral'} />
                        <CaretDownIcon size={16} aria-hidden className="shrink-0 text-fg-tertiary transition-transform group-open:rotate-180" />
                    </summary>
                    <div className="grid gap-4 border-t border-line-default px-5 py-4 sm:grid-cols-2">
                        {provider.webhook_url && (
                            <div className="flex flex-col gap-1.5">
                                <span className="t-admin-caption-strong text-fg-primary">{t('couriers.webhook.url', { name: provider.name })}</span>
                                <div className="flex items-center gap-2">
                                    <code className="min-w-0 flex-1 truncate rounded-sm border border-line-default bg-subtle px-3 py-2 t-admin-mono-sm text-fg-primary">{provider.webhook_url}</code>
                                    <IconButton aria-label={t('couriers.credentials.copyUrl')} onClick={() => void copyUrl()}>
                                        {copied ? <CheckIcon size={18} aria-hidden className="text-feedback-success" /> : <CopyIcon size={18} aria-hidden />}
                                    </IconButton>
                                </div>
                            </div>
                        )}
                        <div className="flex flex-col gap-1.5">
                            <label htmlFor="webhook-secret" className="t-admin-caption-strong text-fg-primary">
                                {t('couriers.webhook.secret')}
                            </label>
                            <input
                                id="webhook-secret"
                                type="password"
                                autoComplete="new-password"
                                spellCheck={false}
                                disabled={clearWebhook || save.isPending}
                                aria-describedby="webhook-secret-hint"
                                aria-invalid={errors.webhook_secret ? true : undefined}
                                value={webhook}
                                onChange={(e) => setWebhook(e.target.value)}
                                className={`h-10 ${monoInputClass}`}
                            />
                            <span className="flex items-center gap-2">
                                <span id="webhook-secret-hint" className={`min-w-0 flex-1 t-admin-caption ${errors.webhook_secret ? 'text-feedback-danger' : 'text-fg-tertiary'}`}>
                                    {errors.webhook_secret ??
                                        (clearWebhook ? t('couriers.credentials.willRemove') : provider.webhook_secret_set ? t('couriers.credentials.webhookSet') : t('couriers.webhook.secretHint'))}
                                </span>
                                {provider.webhook_secret_set && (
                                    <Button variant="ghost" size="sm" onClick={() => setClearWebhook((v) => !v)}>
                                        {clearWebhook ? t('couriers.credentials.undo') : t('couriers.credentials.remove')}
                                    </Button>
                                )}
                            </span>
                        </div>
                    </div>
                </details>
            )}

            <div className="flex flex-col gap-3 border-t border-line-default pt-4 sm:flex-row sm:items-center">
                <p className="min-w-0 flex-1 t-admin-caption text-fg-tertiary">{t('couriers.credentials.verifiedNote')}</p>
                <Button type="submit" disabled={save.isPending} aria-busy={save.isPending || undefined}>
                    <CheckIcon size={16} aria-hidden />
                    {save.isPending ? t('couriers.saving') : t('couriers.credentials.save')}
                </Button>
            </div>
        </form>
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

/** What the courier charges, as three zone prices and three sentences with their inputs inside. */
function ChargesSection({ provider }: { provider: CourierProvider }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const save = useSaveRates();
    const editable = provider.can.update_rates;
    const [draft, setDraft] = useState<RateDraft>(() => toDraft(provider));
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => setDraft(toDraft(provider)), [provider]);

    const set = (field: keyof RateDraft) => (value: string) => setDraft((d) => ({ ...d, [field]: value }));
    const dirty = JSON.stringify(draft) !== JSON.stringify(toDraft(provider));

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

    const inline = (field: keyof RateDraft, label: string, opts: { prefix?: string; suffix?: string; width: string; inputMode?: 'decimal' | 'numeric' }) => (
        <Affixed prefix={opts.prefix} suffix={opts.suffix}>
            <input
                aria-label={label}
                inputMode={opts.inputMode ?? 'decimal'}
                aria-invalid={errors[field] ? true : undefined}
                disabled={!editable || save.isPending}
                value={draft[field]}
                onChange={(e) => set(field)(e.target.value)}
                className={`h-9 ${opts.width} tabular ${opts.prefix ? 'ps-8' : ''} ${opts.suffix ? 'pe-8' : ''} ${inputClass}`}
            />
        </Affixed>
    );
    const fieldErrors = Object.entries(errors);

    return (
        <form onSubmit={submit} noValidate className="flex flex-col gap-5">
            {!provider.rate_card_updated_at && (
                <p className="flex items-start gap-3 rounded-md bg-warning-subtle px-4 py-3 t-admin-body text-fg-primary">
                    <WarningIcon size={18} aria-hidden className="mt-0.5 shrink-0 text-feedback-warning" />
                    {t('couriers.charges.placeholder', { name: provider.name })}
                </p>
            )}
            <div className="flex flex-col gap-1">
                <h3 className="t-admin-body-strong text-fg-primary">{t('couriers.charges.title', { name: provider.name })}</h3>
                <p className="t-admin-caption text-fg-tertiary">{t('couriers.charges.zones')}</p>
            </div>
            <div className="grid gap-4 sm:grid-cols-3">
                {(
                    [
                        ['base_charge_dhaka', 'couriers.rates.dhaka'],
                        ['base_charge_suburb', 'couriers.rates.suburb'],
                        ['base_charge_outside', 'couriers.rates.outside'],
                    ] as [RateField, MessageKey][]
                ).map(([field, label]) => (
                    <div key={field} className="flex flex-col gap-2 rounded-md bg-subtle p-4">
                        <label htmlFor={`rate-${field}`} className="t-admin-body-strong text-fg-primary">
                            {t(label)}
                        </label>
                        <Affixed prefix={TAKA}>
                            <input
                                id={`rate-${field}`}
                                inputMode="decimal"
                                aria-invalid={errors[field] ? true : undefined}
                                disabled={!editable || save.isPending}
                                value={draft[field]}
                                onChange={(e) => set(field)(e.target.value)}
                                className={`h-10 w-full ps-8 tabular ${inputClass}`}
                            />
                        </Affixed>
                    </div>
                ))}
            </div>
            <div className="flex flex-col gap-5 rounded-md border border-line-default p-5">
                <div className="flex flex-col gap-1.5">
                    <p className="t-admin-caption-strong text-fg-secondary">{t('couriers.charges.weight')}</p>
                    <p className="flex flex-wrap items-center gap-2 t-admin-body text-fg-primary">
                        <span>{t('couriers.charges.weightA')}</span>
                        {inline('included_weight_grams', t('couriers.rates.included'), { suffix: GRAM, width: 'w-28', inputMode: 'numeric' })}
                        <span>{t('couriers.charges.weightB')}</span>
                        {inline('per_kg_charge', t('couriers.rates.perKg'), { prefix: TAKA, width: 'w-28' })}
                        <span>{t('couriers.charges.weightC')}</span>
                    </p>
                </div>
                <div className="flex flex-col gap-1.5">
                    <p className="t-admin-caption-strong text-fg-secondary">{t('couriers.rates.codFee')}</p>
                    <p className="flex flex-wrap items-center gap-2 t-admin-body text-fg-primary">
                        <span>{t('couriers.charges.codA', { name: provider.name })}</span>
                        {inline('cod_fee_percent', t('couriers.rates.codFee'), { suffix: '%', width: 'w-24' })}
                        <span>{t('couriers.charges.codB')}</span>
                    </p>
                </div>
                <div className="flex flex-col gap-1.5">
                    <p className="t-admin-caption-strong text-fg-secondary">{t('couriers.charges.returns')}</p>
                    <p className="flex flex-wrap items-center gap-2 t-admin-body text-fg-primary">
                        <span>{t('couriers.charges.returnA')}</span>
                        {inline('return_charge', t('couriers.rates.return'), { prefix: TAKA, width: 'w-28' })}
                        <span>{t('couriers.charges.returnB')}</span>
                    </p>
                </div>
                <p className="t-admin-caption text-fg-tertiary">{t('couriers.rates.includedHint')}</p>
                {fieldErrors.length > 0 && (
                    <ul role="alert" className="flex flex-col gap-1">
                        {[...new Set(fieldErrors.map(([, m]) => m))].map((m) => (
                            <li key={m} className="t-admin-caption text-feedback-danger">
                                {m}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <div className="flex flex-col gap-3 border-t border-line-default pt-4 sm:flex-row sm:items-center">
                <p className="min-w-0 flex-1 t-admin-caption text-fg-tertiary">
                    {provider.is_default ? t('couriers.rates.pricesStorefront') : t('couriers.rates.costOnly')}
                    {provider.rate_card_updated_at ? ` ${t('couriers.rates.edited', { date: dhaka(provider.rate_card_updated_at, locale).dayYear })}` : ''}
                </p>
                {editable && (
                    <span className="flex gap-2">
                        <Button type="button" variant="outline" disabled={!dirty || save.isPending} onClick={() => (setDraft(toDraft(provider)), setErrors({}))}>
                            {t('couriers.charges.discard')}
                        </Button>
                        <Button type="submit" disabled={save.isPending} aria-busy={save.isPending || undefined}>
                            <CheckIcon size={16} aria-hidden />
                            {save.isPending ? t('couriers.saving') : t('couriers.rates.save')}
                        </Button>
                    </span>
                )}
            </div>
        </form>
    );
}

function Affixed({ prefix, suffix, children }: { prefix?: string; suffix?: string; children: ReactNode }) {
    return (
        <span className="relative inline-flex">
            {prefix && <span aria-hidden className="pointer-events-none absolute inset-y-0 start-3 flex items-center t-admin-currency-num text-fg-tertiary">{prefix}</span>}
            {children}
            {suffix && <span aria-hidden className="pointer-events-none absolute inset-y-0 end-3 flex items-center t-admin-caption text-fg-tertiary">{suffix}</span>}
        </span>
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
