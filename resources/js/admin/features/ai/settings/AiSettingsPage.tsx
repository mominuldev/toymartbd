import { useEffect, useId, useState, type ComponentType, type FormEvent, type ReactNode } from 'react';
import {
    ChartLineUpIcon,
    ChatCircleTextIcon,
    CheckCircleIcon,
    ArrowSquareOutIcon,
    KeyIcon,
    LockSimpleIcon,
    PackageIcon,
    PencilSimpleLineIcon,
    ShieldCheckIcon,
    TrashIcon,
    WarningIcon,
    type IconProps,
} from '@phosphor-icons/react';
import { saveAiKeyRequestSchema, updateAiSettingsRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { timeAgo } from '@/lib/format';
import { ProviderLogo } from '@/features/ai/logos/ProviderLogo';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { Note, UnsavedChangesGuard, problemErrors, useSavedFlash } from '@/features/storeSettings/parts';
import { aiProblem, modelLabel } from '../AiNotice';
import {
    useAiSettings,
    useCheckAiProvider,
    useConnectAiProvider,
    useForgetAiProvider,
    useSaveAiSettings,
    type AiFeatureId,
    type AiProviderId,
    type AiProviderInfo,
    type AiSettings,
    type AiSettingsInput,
} from './api';

const FORM_ID = 'ai-settings';

const FEATURES: readonly AiFeatureId[] = ['advisor', 'product_copy', 'inbox_reply', 'restock_note'];

const featureIcons: Record<AiFeatureId, ComponentType<IconProps>> = {
    advisor: ChartLineUpIcon,
    product_copy: PencilSimpleLineIcon,
    inbox_reply: ChatCircleTextIcon,
    restock_note: PackageIcon,
};

/** Each provider's own API-key page (opened in a new tab; nothing is sent from here). */
const keyPages: Record<AiProviderId, string> = {
    anthropic: 'https://platform.claude.com/settings/keys',
    openai: 'https://platform.openai.com/api-keys',
    gemini: 'https://aistudio.google.com/apikey',
};

const keyHints: Record<AiProviderId, MessageKey> = {
    anthropic: 'aiSettings.key.hint.anthropic',
    openai: 'aiSettings.key.hint.openai',
    gemini: 'aiSettings.key.hint.gemini',
};

const SHARES = ['figures', 'products', 'first_names'] as const;
const LOCKED: readonly MessageKey[] = ['aiSettings.share.phones', 'aiSettings.share.addresses', 'aiSettings.share.payments'];

type Route = { provider: AiProviderId; model: string };
type Draft = { features: Record<AiFeatureId, Route>; cap_usd: string; share: AiSettings['share'] };

function toDraft(s: AiSettings): Draft {
    const features = {} as Record<AiFeatureId, Route>;
    for (const f of FEATURES) {
        const row = s.features.find((r) => r.feature === f);
        features[f] = row ? { provider: row.provider, model: row.model } : { provider: 'anthropic', model: s.providers[0]?.default_model ?? '' };
    }
    return { features, cap_usd: s.cap_usd, share: { ...s.share } };
}

/** The PUT /settings/ai body, in the generated schema's shape (rule 27). */
function toInput(d: Draft): AiSettingsInput {
    return {
        features: {
            advisor: { ...d.features.advisor },
            product_copy: { ...d.features.product_copy },
            inbox_reply: { ...d.features.inbox_reply },
            restock_note: { ...d.features.restock_note },
        },
        cap_usd: d.cap_usd.trim(),
        share: { figures: d.share.figures, products: d.share.products, first_names: d.share.first_names },
    };
}

/** Display only: "$12.40" from the server's own string. */
function useUsd() {
    const { t } = useI18n();
    return (amount: string) => t('aiSettings.usd', { amount });
}

const card = 'flex min-w-0 flex-col gap-4 rounded-lg border border-line-default bg-surface p-5 shadow-e1';

/**
 * Integrations › AI assistant (Figma 536:82215): the provider keys, which provider and model each
 * AI feature uses, what the AI may see, and this month's estimated spend against the cap.
 * Read with settings.view, routing/cap/sharing changed with settings.update, keys with
 * settings.manage_integrations — the server says which through `can`. A key is never shown again
 * once sent; the server keeps only its last four characters for display.
 */
export function AiSettingsPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const allowed = can(me, 'settings.view');
    const settings = useAiSettings(allowed);

    if (me && !allowed) return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;

    const header = (actions?: ReactNode) => (
        <ContentHeader
            breadcrumb={[{ label: t('aiSettings.crumb.integrations'), to: '/integrations' }, { label: t('aiSettings.crumb.ai') }]}
            title={t('aiSettings.title')}
            subtitle={t('aiSettings.subtitle')}
            actions={actions}
        />
    );

    if (settings.isError) {
        return (
            <section className="flex flex-col gap-6">
                {header()}
                <QueryError error={settings.error} onRetry={() => void settings.refetch()} />
            </section>
        );
    }
    if (!settings.data) {
        return (
            <section className="flex flex-col gap-6">
                {header()}
                <p aria-busy="true" className="t-admin-body text-fg-tertiary">
                    {t('common.loading')}
                </p>
            </section>
        );
    }

    return <AiSettingsForm data={settings.data} header={header} />;
}

function AiSettingsForm({ data, header }: { data: AiSettings; header: (actions?: ReactNode) => ReactNode }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSaveAiSettings();
    const editable = data.can.update;
    const savedDraft = toDraft(data);
    const savedBody = JSON.stringify(toInput(savedDraft));
    const [draft, setDraft] = useState<Draft>(savedDraft);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [justSaved, flashSaved] = useSavedFlash();

    // Reset only when the saved routing/cap/sharing change — connecting a key keeps unsaved edits.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect(() => setDraft(toDraft(data)), [savedBody]);

    const dirty = editable && JSON.stringify(toInput(draft)) !== savedBody;
    const providerOf = (id: AiProviderId) => data.providers.find((p) => p.provider === id);

    const setRoute = (feature: AiFeatureId, route: Route) => setDraft((d) => ({ ...d, features: { ...d.features, [feature]: route } }));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!editable || !dirty) return;
        const input = toInput(draft);
        const parsed = updateAiSettingsRequestSchema.safeParse(input);
        if (!parsed.success) {
            const next: Record<string, string> = {};
            for (const issue of parsed.error.issues) next[issue.path.map(String).join('.')] ??= issue.message;
            setErrors(next);
            return;
        }
        setErrors({});
        save.mutate(input, {
            onSuccess: () => flashSaved(),
            onError: (error) => {
                const fields = problemErrors(error);
                if (fields) setErrors(fields);
                if (!fields?.cap_usd) notify(t('aiSettings.saveFailed'), 'danger');
            },
        });
    };

    const discard = () => {
        setDraft(toDraft(data));
        setErrors({});
    };

    const actions = editable ? (
        <>
            {justSaved && !dirty && (
                <p role="status" className="flex items-center gap-1.5 t-admin-body text-fg-primary">
                    <CheckCircleIcon size={16} aria-hidden className="text-feedback-success" />
                    {t('aiSettings.saved')}
                </p>
            )}
            {dirty && (
                <Button variant="ghost" disabled={save.isPending} onClick={discard}>
                    {t('aiSettings.discard')}
                </Button>
            )}
            <Button type="submit" form={FORM_ID} disabled={!dirty || save.isPending}>
                {save.isPending ? t('aiSettings.saving') : t('aiSettings.save')}
            </Button>
        </>
    ) : undefined;

    return (
        <section className="flex flex-col gap-6">
            {header(actions)}
            {editable && <UnsavedChangesGuard when={dirty} />}
            {!editable && <Note>{t('aiSettings.readOnly')}</Note>}

            <section aria-labelledby="ai-providers" className="flex flex-col gap-3">
                <h2 id="ai-providers" className="sr-only">
                    {t('aiSettings.providers')}
                </h2>
                <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {data.providers.map((p) => (
                        <ProviderCard key={p.provider} provider={p} canManage={data.can.manage_keys} />
                    ))}
                </ul>
            </section>

            <form id={FORM_ID} onSubmit={submit} noValidate className="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_400px]">
                <fieldset disabled={!editable} className="contents">
                    <div className="flex min-w-0 flex-col gap-6">
                        <RoutingCard data={data} draft={draft} disabled={!editable} providerOf={providerOf} onChange={setRoute} />
                        <ShareCard share={draft.share} disabled={!editable} onChange={(share) => setDraft((d) => ({ ...d, share }))} />
                    </div>
                    <div className="flex min-w-0 flex-col gap-6">
                        <SpendCard data={data} disabled={!editable} cap={draft.cap_usd} capError={errors.cap_usd} onCap={(cap_usd) => setDraft((d) => ({ ...d, cap_usd }))} />
                        <section className={`${card} bg-subtle`} aria-labelledby="ai-suggest-only">
                            <div className="flex items-center gap-2">
                                <ShieldCheckIcon size={20} aria-hidden className="text-fg-brand" />
                                <h2 id="ai-suggest-only" className="t-admin-section text-fg-primary">
                                    {t('aiSettings.suggest.title')}
                                </h2>
                            </div>
                            <p className="t-admin-body text-fg-secondary">{t('aiSettings.suggest.body')}</p>
                        </section>
                    </div>
                </fieldset>
            </form>
        </section>
    );
}

/** A key the provider refused, a 422 on the field, or a plain "try again". The key itself never appears. */
function keyError(error: unknown, t: (key: MessageKey) => string): string {
    if (error instanceof ApiError) {
        if (error.status === 422) return error.fieldError('api_key') ?? error.problem.detail ?? error.problem.title;
        if (aiProblem(error) === 'unavailable' && error.problem.errors?.[0]?.message === 'auth_failed') return t('aiSettings.key.rejected');
        if (error.status === 401) return t('aiSettings.key.rejected');
    }
    return t('aiSettings.key.failed');
}

function ProviderCard({ provider: p, canManage }: { provider: AiProviderInfo; canManage: boolean }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const connect = useConnectAiProvider();
    const check = useCheckAiProvider();
    const forget = useForgetAiProvider();
    const [replacing, setReplacing] = useState(false);
    const [apiKey, setApiKey] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [confirming, setConfirming] = useState(false);

    const status = !p.connected ? 'notConnected' : p.check_ok === false ? 'rejected' : 'connected';
    const tone = status === 'connected' ? 'success' : status === 'rejected' ? 'danger' : 'neutral';
    const showForm = canManage && (!p.connected || replacing);
    const defaultModel = p.models.find((m) => m.id === p.default_model)?.label ?? modelLabel(p.default_model);

    const submitKey = (event: FormEvent) => {
        event.preventDefault();
        const value = apiKey.trim();
        const parsed = saveAiKeyRequestSchema.safeParse({ api_key: value });
        if (!parsed.success) {
            setError(parsed.error.issues[0]?.message ?? t('aiSettings.key.failed'));
            return;
        }
        setError(null);
        connect.mutate(
            { provider: p.provider, apiKey: value },
            {
                onSuccess: () => {
                    setApiKey('');
                    setReplacing(false);
                    notify(t('aiSettings.key.connected', { provider: p.label }));
                },
                onError: (e) => setError(keyError(e, t)),
            },
        );
    };

    const recheck = () => {
        setError(null);
        check.mutate(p.provider, {
            onSuccess: (next) => {
                const row = next.providers.find((r) => r.provider === p.provider);
                if (row?.check_ok === false) setError(t('aiSettings.key.rejected'));
                else notify(t('aiSettings.key.checkedOk', { provider: p.label }));
            },
            onError: (e) => setError(keyError(e, t)),
        });
    };

    return (
        <li aria-label={p.label} className={card}>
            <div className="flex items-center gap-3">
                <span aria-hidden className="grid size-10 shrink-0 place-items-center rounded-md border border-line-default bg-surface text-fg-primary">
                    <ProviderLogo provider={p.provider} />
                </span>
                <h3 className="min-w-0 flex-1 truncate t-admin-body-strong text-fg-primary">{p.label}</h3>
                <StatusChip tone={tone} label={t(`aiSettings.status.${status}`)} />
            </div>

            {p.connected && (
                <div className="flex flex-col gap-1">
                    {p.last4 && (
                        <p className="t-admin-caption text-fg-secondary">
                            {p.checked_at ? t('aiSettings.key.meta', { last4: p.last4, ago: timeAgo(p.checked_at, locale) }) : t('aiSettings.key.metaUnchecked', { last4: p.last4 })}
                        </p>
                    )}
                    <p className="t-admin-caption text-fg-secondary">{t('aiSettings.key.defaultModel', { model: defaultModel })}</p>
                    {p.limited && (
                        <p role="status" className="flex items-start gap-1.5 t-admin-caption text-fg-primary">
                            <WarningIcon size={14} aria-hidden className="mt-0.5 shrink-0 text-feedback-warning" />
                            {t('aiSettings.key.limited', { provider: p.label })}
                        </p>
                    )}
                </div>
            )}

            {showForm && (
                <form onSubmit={submitKey} noValidate className="flex flex-col gap-2">
                    <Field label={t('aiSettings.key.label', { provider: p.label })} hint={t(keyHints[p.provider])} error={error ?? undefined}>
                        {({ id, describedBy, invalid }) => (
                            <div className="flex flex-wrap gap-2">
                                <Input
                                    id={id}
                                    type="password"
                                    autoComplete="off"
                                    spellCheck={false}
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    placeholder={t('aiSettings.key.placeholder')}
                                    leading={<KeyIcon size={16} aria-hidden />}
                                    value={apiKey}
                                    onChange={(e) => setApiKey(e.target.value)}
                                    className="min-w-40 flex-1"
                                />
                                <Button type="submit" disabled={connect.isPending}>
                                    {connect.isPending ? t('aiSettings.key.connecting') : t('aiSettings.key.connect')}
                                </Button>
                            </div>
                        )}
                    </Field>
                    <a href={keyPages[p.provider]} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 self-start t-admin-caption-strong text-fg-link hover:underline">
                        {t('aiSettings.key.getKey', { provider: p.label })}
                        <ArrowSquareOutIcon size={14} aria-hidden />
                        <span className="sr-only">{t('aiSettings.key.newTab')}</span>
                    </a>
                    {replacing && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="self-start"
                            onClick={() => {
                                setReplacing(false);
                                setApiKey('');
                                setError(null);
                            }}
                        >
                            {t('aiSettings.key.cancel')}
                        </Button>
                    )}
                </form>
            )}

            {canManage && p.connected && !replacing && (
                <>
                    {error && (
                        <p role="alert" className="flex items-start gap-1 t-admin-caption text-fg-primary">
                            <WarningIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                            {error}
                        </p>
                    )}
                    <div className="mt-auto flex flex-wrap items-center gap-2">
                        <Button variant="outline" size="sm" disabled={check.isPending} onClick={recheck}>
                            {check.isPending ? t('aiSettings.key.checking') : t('aiSettings.key.check')}
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setError(null);
                                setReplacing(true);
                            }}
                        >
                            {t('aiSettings.key.replace')}
                        </Button>
                        <Button variant="ghost" size="sm" className="ms-auto" aria-label={t('aiSettings.key.remove', { provider: p.label })} onClick={() => setConfirming(true)}>
                            <TrashIcon size={16} aria-hidden />
                        </Button>
                    </div>
                    <ConfirmDialog
                        open={confirming}
                        title={t('aiSettings.key.removeTitle', { provider: p.label })}
                        body={t('aiSettings.key.removeBody', { provider: p.label })}
                        confirmLabel={t('aiSettings.key.removeConfirm')}
                        pending={forget.isPending}
                        onClose={() => setConfirming(false)}
                        onConfirm={() =>
                            forget.mutate(p.provider, {
                                onSuccess: () => {
                                    setConfirming(false);
                                    setError(null);
                                    notify(t('aiSettings.key.removed', { provider: p.label }));
                                },
                                onError: () => {
                                    setConfirming(false);
                                    notify(t('aiSettings.key.failed'), 'danger');
                                },
                            })
                        }
                    />
                </>
            )}
        </li>
    );
}

function CardTitle({ id, title, subtitle }: { id: string; title: string; subtitle?: string }) {
    return (
        <div className="flex flex-col gap-1">
            <h2 id={id} className="t-admin-section text-fg-primary">
                {title}
            </h2>
            {subtitle && <p className="t-admin-caption text-fg-secondary">{subtitle}</p>}
        </div>
    );
}

function RoutingCard({
    data,
    draft,
    providerOf,
    onChange,
    disabled,
}: {
    data: AiSettings;
    draft: Draft;
    disabled: boolean;
    providerOf: (id: AiProviderId) => AiProviderInfo | undefined;
    onChange: (feature: AiFeatureId, route: Route) => void;
}) {
    const { t } = useI18n();
    const id = useId();

    return (
        <section aria-labelledby={id} className={card}>
            <CardTitle id={id} title={t('aiSettings.routing.title')} subtitle={t('aiSettings.routing.subtitle')} />
            <div aria-hidden className="hidden gap-4 border-b border-line-default pb-2 t-admin-caption-strong text-fg-tertiary md:grid md:grid-cols-[minmax(0,1fr)_200px_220px]">
                <span />
                <span>{t('aiSettings.routing.provider')}</span>
                <span>{t('aiSettings.routing.model')}</span>
            </div>
            <ul className="flex flex-col divide-y divide-line-default">
                {FEATURES.map((feature) => {
                    const route = draft.features[feature];
                    const provider = providerOf(route.provider);
                    const name = t(`aiSettings.feature.${feature}`);
                    const Icon = featureIcons[feature];
                    return (
                        <li key={feature} data-feature={feature} className="grid gap-3 py-4 first:pt-0 last:pb-0 md:grid-cols-[minmax(0,1fr)_200px_220px] md:items-center md:gap-4">
                            <div className="flex min-w-0 items-start gap-3">
                                <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-sm bg-subtle text-fg-primary">
                                    <Icon size={18} />
                                </span>
                                <div className="flex min-w-0 flex-col gap-0.5">
                                    <span className="t-admin-body-strong text-fg-primary">{name}</span>
                                    <span className="t-admin-caption text-fg-secondary">{t(`aiSettings.feature.${feature}Hint`)}</span>
                                </div>
                            </div>
                            <Select
                                aria-label={t('aiSettings.routing.providerFor', { feature: name })}
                                disabled={disabled}
                                value={route.provider}
                                onChange={(e) => {
                                    const next = providerOf(e.target.value as AiProviderId);
                                    if (next) onChange(feature, { provider: next.provider, model: next.default_model });
                                }}
                            >
                                {data.providers.map((p) => (
                                    <option key={p.provider} value={p.provider}>
                                        {p.label}
                                    </option>
                                ))}
                            </Select>
                            <Select aria-label={t('aiSettings.routing.modelFor', { feature: name })} disabled={disabled} value={route.model} onChange={(e) => onChange(feature, { ...route, model: e.target.value })}>
                                {(provider?.models ?? []).map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.label}
                                    </option>
                                ))}
                            </Select>
                            {provider && !provider.connected && (
                                <p className="flex items-start gap-1.5 t-admin-caption text-fg-secondary md:col-span-2 md:col-start-2">
                                    <WarningIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-warning" />
                                    <span>
                                        <span className="t-admin-caption-strong text-fg-primary">{t('aiSettings.routing.notConnected')}</span>
                                        {' · '}
                                        {t('aiSettings.routing.notConnectedHint', { provider: provider.label })}
                                    </span>
                                </p>
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

/** Figma toggle: 36×20 track, bg/brand on, border/strong off; disabled follows the fieldset or `disabled`. */
function Switch({ checked, label, onChange, disabled = false }: { checked: boolean; label: string; onChange?: (value: boolean) => void; disabled?: boolean }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={() => onChange?.(!checked)}
            className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors duration-150 ease-standard disabled:cursor-not-allowed disabled:opacity-60 ${
                checked ? 'bg-brand' : 'bg-line-strong'
            }`}
        >
            <span aria-hidden className={`size-4 rounded-full bg-surface shadow-e1 transition-transform duration-150 ease-standard ${checked ? 'translate-x-4' : 'translate-x-0'}`} />
        </button>
    );
}

function ShareCard({ share, onChange, disabled }: { share: AiSettings['share']; disabled: boolean; onChange: (share: AiSettings['share']) => void }) {
    const { t } = useI18n();
    const id = useId();

    return (
        <section aria-labelledby={id} className={card}>
            <CardTitle id={id} title={t('aiSettings.share.title')} subtitle={t('aiSettings.share.subtitle')} />
            <ul className="flex flex-col divide-y divide-line-default">
                {SHARES.map((key) => {
                    const label = t(`aiSettings.share.${key}`);
                    return (
                        <li key={key} className="flex items-center gap-3 py-3 first:pt-0">
                            <span className="min-w-0 flex-1 t-admin-body text-fg-primary">{label}</span>
                            <Switch checked={share[key]} label={label} disabled={disabled} onChange={(value) => onChange({ ...share, [key]: value })} />
                        </li>
                    );
                })}
                {LOCKED.map((key) => {
                    const item = t(key);
                    return (
                        <li key={key} data-locked className="flex items-center gap-3 py-3 last:pb-0">
                            <LockSimpleIcon size={16} aria-hidden className="shrink-0 text-fg-tertiary" />
                            <span className="min-w-0 flex-1 t-admin-body text-fg-secondary">{item}</span>
                            <span className="t-admin-caption text-fg-tertiary">{t('aiSettings.share.never')}</span>
                            <Switch checked={false} disabled label={t('aiSettings.share.lockedLabel', { item })} />
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

function SpendCard({ data, cap, capError, onCap, disabled }: { data: AiSettings; disabled: boolean; cap: string; capError?: string; onCap: (value: string) => void }) {
    const { t } = useI18n();
    const usd = useUsd();
    const id = useId();
    const { usage } = data;
    // Display only: the bar's width from the two strings the server sent (rule 1 — no money is computed here).
    const spentNum = Number(usage.spent_usd);
    const capNum = Number(data.cap_usd);
    const pct = capNum > 0 ? Math.min(100, Math.round((spentNum / capNum) * 100)) : spentNum > 0 ? 100 : 0;
    const spent = usd(usage.spent_usd);
    const capText = usd(data.cap_usd);

    return (
        <section aria-labelledby={id} className={card}>
            <CardTitle id={id} title={t('aiSettings.spend.title')} />
            <div className="grid grid-cols-2 gap-3">
                <div className="flex flex-col gap-1 rounded-md bg-subtle p-3">
                    <span className="t-admin-caption text-fg-secondary">{t('aiSettings.spend.spent')}</span>
                    <span data-testid="ai-spent" className="t-admin-num-lg text-fg-primary">
                        {spent}
                    </span>
                    <span className="t-admin-caption text-fg-tertiary">{t('aiSettings.spend.of', { cap: capText })}</span>
                </div>
                <div className="flex flex-col gap-1 rounded-md bg-subtle p-3">
                    <span className="t-admin-caption text-fg-secondary">{t('aiSettings.spend.requests')}</span>
                    <span data-testid="ai-requests" className="t-admin-num-lg text-fg-primary">
                        {usage.requests}
                    </span>
                    <span className="t-admin-caption text-fg-tertiary">{usage.failed > 0 ? t('aiSettings.spend.failed', { count: usage.failed }) : t('aiSettings.spend.thisMonth')}</span>
                </div>
            </div>
            <div
                role="progressbar"
                aria-label={t('aiSettings.spend.title')}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={pct}
                aria-valuetext={t('aiSettings.spend.bar', { spent, cap: capText })}
                className="h-2 overflow-hidden rounded-full bg-subtle"
            >
                <div className={`h-full rounded-full ${pct >= 90 ? 'bg-feedback-warning' : 'bg-brand'}`} style={{ width: `${pct}%` }} />
            </div>

            <Field label={t('aiSettings.cap.label')} hint={t('aiSettings.cap.hint')} error={capError}>
                {({ id: inputId, describedBy, invalid }) => (
                    <Input
                        id={inputId}
                        inputMode="decimal"
                        disabled={disabled}
                        aria-describedby={describedBy}
                        aria-invalid={invalid || undefined}
                        prefix="$"
                        suffix={t('aiSettings.cap.suffix')}
                        value={cap}
                        onChange={(e) => onCap(e.target.value)}
                    />
                )}
            </Field>

            <div className="flex flex-col gap-2 border-t border-line-default pt-4">
                <h3 className="t-admin-caption-strong text-fg-secondary">{t('aiSettings.spend.byFeature')}</h3>
                <ul className="flex flex-col gap-1.5">
                    {FEATURES.map((feature) => (
                        <li key={feature} className="flex items-center gap-3 t-admin-body">
                            <span className="min-w-0 flex-1 text-fg-secondary">{t(`aiSettings.feature.${feature}`)}</span>
                            <span className="t-admin-num text-fg-primary">{usd(usage.by_feature[feature] ?? '0.00')}</span>
                        </li>
                    ))}
                </ul>
                <p className="t-admin-caption text-fg-tertiary">{t('aiSettings.spend.estimate')}</p>
            </div>
        </section>
    );
}
