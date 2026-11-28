import { useId, useState } from 'react';
import { useWatch, type UseFormReturn } from 'react-hook-form';
import {
    ArrowSquareOutIcon,
    ArrowsClockwiseIcon,
    ArrowCounterClockwiseIcon,
    CheckCircleIcon,
    ClockIcon,
    CopyIcon,
    InfoIcon,
    KeyIcon,
    LockSimpleIcon,
    PlugIcon,
    TrashIcon,
    WarningCircleIcon,
    XCircleIcon,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Switch } from '@/features/settings/messaging/Switch';
import { Button, buttonClass } from '@/components/ui/Button';
import { inputClass, monoInputClass } from '@/components/ui/Field';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { gatewayModes, type GatewayCheck, type GatewayMode, type PaymentGateway } from './api';
import { PaymentLogo } from './logos/PaymentLogo';
import { checkoutFact, diff, envChip, savedCount, verificationFact, type Change, type Fact, type FormValues, type SecretAction } from './model';
import { Dot, Section, dhakaStamp } from './parts';

/** Each gateway's own reference, opened from the panel header. */
const docsUrl: Record<PaymentGateway['code'], string> = {
    sslcommerz: 'https://sandbox-gw.sslcommerz.com/docs',
    paystation: 'https://paystation.com.bd/documentation',
    bkash: 'https://developer.bka.sh/docs',
};
const SSL_SANDBOX_SIGNUP = 'https://sandbox-gw.sslcommerz.com/register';
const SSL_LIVE_SIGNUP = 'https://signup.sslcommerz.com/register';

/** Field key → label; the gateway's own parameter name (from its docs) is shown untranslated in the help. */
const fieldLabels: Record<string, MessageKey> = {
    store_id: 'paymentGateways.field.store_id',
    store_password: 'paymentGateways.field.store_password',
    merchant_id: 'paymentGateways.field.merchant_id',
    password: 'paymentGateways.field.password',
    username: 'paymentGateways.field.username',
    app_key: 'paymentGateways.field.app_key',
    app_secret: 'paymentGateways.field.app_secret',
};
const docNames: Record<string, string> = {
    store_id: 'store_id',
    store_password: 'store_passwd',
    merchant_id: 'merchantId',
    password: 'password',
    username: 'username',
    app_key: 'app_key',
    app_secret: 'app_secret',
};

type Props = {
    gateway: PaymentGateway;
    form: UseFormReturn<FormValues>;
    saving: boolean;
    formError: string | null;
    result: GatewayCheck | null;
    checking: boolean;
    onSave: () => void;
    onDiscard: () => void;
    onCheck: () => void;
};

/** The selected gateway's settings panel (B · 37a `640:53053` / `640:53378`). */
export function GatewayPanel({ gateway: g, form, saving, formError, result, checking, onSave, onDiscard, onCheck }: Props) {
    const { t, locale } = useI18n();
    const base = `gateways.${g.code}` as const;
    const draft = useWatch({ control: form.control, name: base });
    const errors = form.formState.errors.gateways?.[g.code];
    const [view, setView] = useState<GatewayMode>(g.mode);
    const { changes } = diff(g, draft);
    const dirty = changes.length > 0;
    const env = envChip(g);
    const idBase = useId();
    const label = (key: string) => (fieldLabels[key] ? t(fieldLabels[key]!) : key);
    // A key-set error (`credentials.live` / `clear.live`) rather than one field's.
    const setMessage = errors?.[view]?.values?.message;
    const setError = typeof setMessage === 'string' ? setMessage : undefined;

    return (
        <form
            noValidate
            aria-labelledby={`${idBase}-title`}
            onSubmit={(event) => {
                event.preventDefault();
                onSave();
            }}
            className="flex min-w-0 flex-col overflow-hidden rounded-md border border-line-default bg-surface shadow-e1"
        >
            <header className="flex min-w-0 flex-col gap-4 p-4 sm:p-6 md:flex-row md:items-center">
                <div className="flex min-w-0 flex-1 items-center gap-4">
                    <span aria-hidden className="grid size-12 shrink-0 place-items-center rounded-sm border border-line-default bg-surface">
                        <PaymentLogo code={g.code} className="size-7" />
                    </span>
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <div className="flex min-w-0 flex-wrap items-center gap-2">
                            <h2 id={`${idBase}-title`} className="t-admin-card-title text-fg-primary">
                                {g.name}
                            </h2>
                            <StatusChip tone={env.tone} label={t(env.label)} />
                        </div>
                        <p className="t-admin-body text-fg-secondary">{t(`paymentGateways.${g.code}.description`)}</p>
                    </div>
                </div>
                <a href={docsUrl[g.code]} target="_blank" rel="noreferrer" className={`${buttonClass('outline', 'sm')} self-start md:self-auto`}>
                    {t('paymentGateways.docs', { name: g.name })}
                    <ArrowSquareOutIcon size={16} aria-hidden />
                </a>
            </header>

            <fieldset disabled={saving} className="flex min-w-0 flex-col">
                <StatusSection gateway={g} on={draft.is_active} error={errors?.is_active?.message} onToggle={(on) => {
                    form.setValue(`${base}.is_active`, on, { shouldDirty: true });
                    form.clearErrors(`${base}.is_active`);
                }} />

                <EnvironmentSection gateway={g} mode={draft.mode} error={errors?.mode?.message} onPick={(mode) => {
                    form.setValue(`${base}.mode`, mode, { shouldDirty: true });
                    form.clearErrors(`${base}.mode`);
                }} />

                <Section id={`${idBase}-keys`} title={t('paymentGateways.keys.title')} about={t(`paymentGateways.${g.code}.keysAbout`)}>
                    <div className="flex min-w-0 flex-wrap items-center gap-3">
                        <SegmentedControl
                            label={t('paymentGateways.keys.set')}
                            options={gatewayModes.map((mode) => ({ value: mode, label: t(`paymentGateways.keys.${mode}`) }))}
                            value={view}
                            onChange={setView}
                        />
                        {view === draft.mode && <StatusChip tone="brand" label={t(`paymentGateways.keys.inUse.${view}`)} />}
                        <span className="flex-1" />
                        {gatewayModes
                            .filter((mode) => mode !== view)
                            .map((mode) => {
                                const count = savedCount(g.credentials[mode]);
                                return (
                                    <p key={mode} className="t-admin-caption text-fg-tertiary">
                                        {t(`paymentGateways.keys.count.${mode}`, { saved: count.saved, total: count.total })}
                                    </p>
                                );
                            })}
                    </div>
                    {g.credentials[view].fields.map((field) => (
                        <KeyField
                            key={`${view}.${field.key}`}
                            gateway={g}
                            form={form}
                            mode={view}
                            field={field}
                            label={label(field.key)}
                            action={draft[view].secrets[field.key] ?? 'keep'}
                            error={errors?.[view]?.values?.[field.key]?.message}
                        />
                    ))}
                    {setError && (
                        <p role="alert" className="flex items-start gap-2 t-admin-body-strong text-feedback-danger">
                            <WarningCircleIcon size={16} aria-hidden className="mt-0.5 shrink-0" />
                            {setError}
                        </p>
                    )}
                    <WhereKeysComeFrom gateway={g} />
                </Section>

                {g.ipn_url && <NotificationsSection gateway={g} url={g.ipn_url} />}

                <Section
                    id={`${idBase}-connection`}
                    title={t('paymentGateways.connection.title')}
                    about={g.code === 'paystation' ? t('paymentGateways.connection.aboutPaystation') : t('paymentGateways.connection.about', { name: g.name, mode: t(`paymentGateways.mode.${g.mode}`).toLowerCase() })}
                >
                    <ConnectionRow gateway={g} dirty={dirty} result={result} checking={checking} label={label} onCheck={onCheck} />
                </Section>
            </fieldset>

            {formError && (
                <p role="alert" className="flex items-start gap-2 border-t border-line-default px-4 py-3 t-admin-body-strong text-feedback-danger sm:px-6">
                    <WarningCircleIcon size={16} aria-hidden className="mt-0.5 shrink-0" />
                    {formError}
                </p>
            )}

            <footer className="flex min-w-0 flex-col gap-3 border-t border-line-default bg-subtle px-4 py-4 sm:flex-row sm:items-center sm:px-6">
                <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                    {dirty ? (
                        <UnsavedSummary changes={changes} label={label} />
                    ) : (
                        <>
                            <ClockIcon size={14} aria-hidden className="shrink-0 text-fg-secondary" />
                            <p className="min-w-0 flex-1 t-admin-caption text-fg-secondary">
                                {g.updated_at
                                    ? g.updated_by
                                        ? t('paymentGateways.footer.savedBy', { date: dhakaStamp(g.updated_at, locale), who: g.updated_by.name, name: g.name })
                                        : t('paymentGateways.footer.saved', { date: dhakaStamp(g.updated_at, locale), name: g.name })
                                    : t('paymentGateways.footer.scope', { name: g.name })}
                            </p>
                        </>
                    )}
                </div>
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:gap-3">
                    <Button variant="secondary" disabled={!dirty || saving} onClick={onDiscard} className="max-sm:w-full">
                        {t('paymentGateways.discard')}
                    </Button>
                    {/* Always the solid Primary (owner request 2026-11-20); with nothing changed it says so instead of greying out. */}
                    <Button type="submit" disabled={saving} className="max-sm:w-full">
                        {saving ? t('paymentGateways.saving') : t('paymentGateways.save')}
                    </Button>
                </div>
            </footer>
        </form>
    );
}

function UnsavedSummary({ changes, label }: { changes: Change[]; label: (key: string) => string }) {
    const { t } = useI18n();
    const names = [
        ...new Set(
            changes.map((c) => (c.kind === 'is_active' ? t('paymentGateways.status.show') : c.kind === 'mode' ? t('paymentGateways.env.title') : label(c.key))),
        ),
    ];
    const mac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.userAgent);
    return (
        <>
            <Dot tone="brand" />
            <p className="t-admin-body-strong text-fg-primary">
                {changes.length === 1 ? t('paymentGateways.footer.unsavedOne') : t('paymentGateways.footer.unsaved', { count: changes.length })}
            </p>
            <p className="t-admin-caption text-fg-secondary">{names.join(', ')}</p>
            <p className="t-admin-caption text-fg-tertiary max-sm:hidden">{t('paymentGateways.footer.shortcut', { keys: mac ? '⌘S' : 'Ctrl+S' })}</p>
        </>
    );
}

function FactRow({ title, fact, text }: { title: string; fact: Fact; text: string }) {
    const { t } = useI18n();
    return (
        <div className="flex min-w-0 flex-col gap-1 sm:flex-row sm:gap-4">
            <p className="t-admin-body text-fg-secondary sm:w-33 sm:shrink-0">{title}</p>
            <div className="flex min-w-0 flex-1 flex-col items-start gap-1">
                <StatusChip tone={fact.tone} label={t(fact.chip)} />
                <p className={`t-admin-caption ${fact.danger ? 'text-fg-primary' : 'text-fg-secondary'}`}>{text}</p>
            </div>
        </div>
    );
}

function StatusSection({ gateway: g, on, error, onToggle }: { gateway: PaymentGateway; on: boolean; error?: string; onToggle: (on: boolean) => void }) {
    const { t, locale } = useI18n();
    const id = useId();
    const checkout = checkoutFact(g);
    const verification = verificationFact(g);
    const helper = g.mode === 'live' && !g.verified ? 'paymentGateways.status.showHelpUnverified' : 'paymentGateways.status.showHelp';

    return (
        <Section id={`${id}-title`} title={t('paymentGateways.status.title')} about={t('paymentGateways.status.about', { name: g.name })}>
            <div className="flex min-w-0 items-start gap-3">
                <Switch checked={on} label={t('paymentGateways.status.show')} onChange={onToggle} />
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <p className="t-admin-body-strong text-fg-primary">{t('paymentGateways.status.show')}</p>
                    <p className="t-admin-caption text-fg-secondary">{t(helper, { name: g.name })}</p>
                    {error && (
                        <p role="alert" className="flex items-start gap-1 t-admin-caption text-fg-primary">
                            <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                            {error}
                        </p>
                    )}
                </div>
            </div>
            <div className={`flex min-w-0 flex-col gap-4 rounded-sm p-4 ${checkout.danger ? 'bg-danger-subtle' : 'bg-subtle'}`}>
                <FactRow title={t('paymentGateways.fact.checkout')} fact={checkout} text={t(checkout.text, { name: g.name })} />
                <FactRow
                    title={t('paymentGateways.fact.verification')}
                    fact={verification}
                    text={t(verification.text, { name: g.name, date: g.verified_at ? dhakaStamp(g.verified_at, locale) : '' })}
                />
            </div>
        </Section>
    );
}

function EnvironmentSection({ gateway: g, mode, error, onPick }: { gateway: PaymentGateway; mode: GatewayMode; error?: string; onPick: (mode: GatewayMode) => void }) {
    const { t } = useI18n();
    const id = useId();
    const liveComplete = g.credentials.live.complete;

    return (
        <Section id={`${id}-title`} title={t('paymentGateways.env.title')} about={t('paymentGateways.env.about')}>
            <div role="radiogroup" aria-labelledby={`${id}-title`} className="grid min-w-0 gap-4 sm:grid-cols-2">
                {gatewayModes.map((m) => {
                    const picked = m === mode;
                    const invalid = picked && m === 'live' && Boolean(error);
                    const host = g.hosts[m];
                    return (
                        <label
                            key={m}
                            className={`flex min-w-0 cursor-pointer items-start gap-3 rounded-sm p-4 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-line-focus ${
                                picked ? `border-2 bg-brand-subtle ${invalid ? 'border-line-danger' : 'border-line-brand'}` : 'border border-line-default bg-surface'
                            }`}
                        >
                            <input type="radio" name={`${id}-env`} value={m} checked={picked} onChange={() => onPick(m)} aria-invalid={invalid || undefined} className="sr-only" />
                            <span
                                aria-hidden
                                className={`mt-px grid size-4.5 shrink-0 place-items-center rounded-full bg-surface ${picked ? 'border-2 border-line-brand' : 'border border-line-strong'}`}
                            >
                                {picked && <span className="size-2 rounded-full bg-brand" />}
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col gap-1">
                                <span className="t-admin-body-strong text-fg-primary">{t(`paymentGateways.mode.${m}`)}</span>
                                <span className="t-admin-body text-fg-secondary">{t(`paymentGateways.env.${m}Line`)}</span>
                                {host && <span className="break-all t-admin-mono-sm text-fg-tertiary">{host}</span>}
                                {m === 'live' && (
                                    <span className={`flex items-center gap-1 t-admin-caption-strong ${invalid ? 'text-feedback-danger' : 'text-fg-secondary'}`}>
                                        {liveComplete ? (
                                            <CheckCircleIcon size={14} aria-hidden className="shrink-0 text-feedback-success" />
                                        ) : (
                                            <WarningCircleIcon size={14} aria-hidden className={`shrink-0 ${invalid ? '' : 'text-feedback-warning'}`} />
                                        )}
                                        {t(liveComplete ? 'paymentGateways.env.liveComplete' : 'paymentGateways.env.liveIncomplete')}
                                    </span>
                                )}
                            </span>
                        </label>
                    );
                })}
            </div>
            {error && (
                <p role="alert" className="flex items-start gap-2 t-admin-body-strong text-feedback-danger">
                    <WarningCircleIcon size={16} aria-hidden className="mt-0.5 shrink-0" />
                    {error}
                </p>
            )}
            {g.code === 'sslcommerz' && (
                <p className="flex items-start gap-2 t-admin-caption text-fg-secondary">
                    <InfoIcon size={14} aria-hidden className="mt-px shrink-0" />
                    {t('paymentGateways.env.sslcommerzLimits')}
                </p>
            )}
            {g.code === 'bkash' && !g.hosts.live && (
                // bKash does not publish its live address; it is given at onboarding and set on the server.
                <p className="flex items-start gap-2 t-admin-caption text-fg-secondary">
                    <InfoIcon size={14} aria-hidden className="mt-px shrink-0" />
                    {t('paymentGateways.bkash.liveHostMissing')}
                </p>
            )}
        </Section>
    );
}

type KeyFieldProps = {
    gateway: PaymentGateway;
    form: UseFormReturn<FormValues>;
    mode: GatewayMode;
    field: PaymentGateway['credentials']['sandbox']['fields'][number];
    label: string;
    action: SecretAction;
    error?: string;
};

/**
 * One key. A non-secret is a plain input with its saved value. A saved secret is never sent
 * back: it shows as a masked row with Replace / Remove, Replace swaps in an empty input with
 * Cancel; a secret not saved yet is an empty input with a "Not set" chip (B · 37a states).
 */
function KeyField({ gateway: g, form, mode, field, label, action, error }: KeyFieldProps) {
    const { t } = useI18n();
    const id = useId();
    const valuePath = `gateways.${g.code}.${mode}.values.${field.key}` as const;
    const actionPath = `gateways.${g.code}.${mode}.secrets.${field.key}` as const;
    const doc = docNames[field.key] ?? field.key;
    const setAction = (next: SecretAction) => {
        form.setValue(actionPath, next, { shouldDirty: true });
        if (next !== 'replace') form.setValue(valuePath, '', { shouldDirty: true });
    };
    const help = !field.secret
        ? t('paymentGateways.field.help', { doc, name: g.name })
        : field.set
          ? action === 'replace'
              ? t('paymentGateways.field.replaceHelp')
              : action === 'remove'
                ? t('paymentGateways.field.removeHelp')
                : t('paymentGateways.field.secretHelp', { doc, name: g.name })
          : t('paymentGateways.field.notSetHelp', { doc, name: g.name });
    const describedBy = `${id}-help`;
    const helpLine = error ? (
        <p id={describedBy} className="flex items-start gap-1 t-admin-caption text-fg-primary">
            <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
            {error}
        </p>
    ) : (
        <p id={describedBy} className="t-admin-caption text-fg-secondary">
            {help}
        </p>
    );

    if (field.secret && field.set && action !== 'replace') {
        const removing = action === 'remove';
        return (
            <div role="group" aria-labelledby={`${id}-label`} aria-describedby={describedBy} className="flex min-w-0 flex-col gap-2">
                <p id={`${id}-label`} className="t-admin-body-strong text-fg-primary">
                    {label}
                </p>
                <div className="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                    <div className="flex h-10 min-w-0 sm:flex-1 items-center gap-2 rounded-sm border border-line-default bg-subtle pr-2 pl-3">
                        <KeyIcon size={14} aria-hidden className="shrink-0 text-fg-secondary" />
                        {/* bKash keys carry a last-four hint ("••••1234") so the owner can tell which account is saved. */}
                        <span aria-hidden={!field.hint} data-key-hint={field.hint ?? undefined} className={`t-admin-mono text-fg-secondary ${removing ? 'line-through' : ''}`}>
                            {field.hint ?? '••••••••••••'}
                        </span>
                        <span className="flex-1" />
                        {removing ? (
                            <StatusChip tone="warning" label={t('paymentGateways.secret.willRemove')} />
                        ) : (
                            <StatusChip tone="success" label={t('paymentGateways.secret.saved')} />
                        )}
                    </div>
                    <div className="grid grid-cols-2 gap-2 sm:flex">
                        {removing ? (
                            <Button variant="ghost" onClick={() => setAction('keep')} aria-label={t('paymentGateways.secret.undoLabel', { field: label })} className="col-span-2">
                                <ArrowCounterClockwiseIcon size={16} aria-hidden />
                                {t('paymentGateways.secret.undo')}
                            </Button>
                        ) : (
                            <>
                                <Button variant="outline" onClick={() => setAction('replace')} aria-label={t('paymentGateways.secret.replaceLabel', { field: label })}>
                                    <ArrowsClockwiseIcon size={16} aria-hidden />
                                    {t('paymentGateways.secret.replace')}
                                </Button>
                                <Button variant="ghost" onClick={() => setAction('remove')} aria-label={t('paymentGateways.secret.removeLabel', { field: label })}>
                                    <TrashIcon size={16} aria-hidden />
                                    {t('paymentGateways.secret.remove')}
                                </Button>
                            </>
                        )}
                    </div>
                </div>
                {helpLine}
            </div>
        );
    }

    const input = (
        <input
            id={id}
            type={field.secret ? 'password' : 'text'}
            autoComplete={field.secret ? 'new-password' : 'off'}
            spellCheck={false}
            // A replace was asked for: focus the empty input straight away.
            autoFocus={field.secret && field.set}
            placeholder={field.secret ? (field.set ? t('paymentGateways.secret.newPlaceholder', { field: label }) : t('paymentGateways.secret.placeholder', { field: label, name: g.name })) : undefined}
            aria-describedby={describedBy}
            aria-invalid={Boolean(error) || undefined}
            className={`h-10 min-w-0 ${field.secret && field.set ? 'flex-1' : ''} ${field.secret ? inputClass : monoInputClass}`}
            {...form.register(valuePath)}
        />
    );

    return (
        <div className="flex min-w-0 flex-col gap-2">
            <div className="flex min-w-0 items-center gap-2">
                <label htmlFor={id} className="t-admin-body-strong text-fg-primary">
                    {label}
                </label>
                {field.secret && !field.set && <StatusChip tone="neutral" showIcon={false} label={t('paymentGateways.secret.notSet')} />}
            </div>
            {field.secret && field.set ? (
                <div className="flex min-w-0 items-center gap-2">
                    {input}
                    <Button variant="ghost" onClick={() => setAction('keep')} aria-label={t('paymentGateways.secret.cancelLabel', { field: label })}>
                        {t('paymentGateways.secret.cancel')}
                    </Button>
                </div>
            ) : (
                input
            )}
            {helpLine}
        </div>
    );
}

function WhereKeysComeFrom({ gateway: g }: { gateway: PaymentGateway }) {
    const { t } = useI18n();
    const link = (href: string, text: string) => (
        <a href={href} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 t-admin-caption-strong text-fg-link hover:underline">
            {text}
            <ArrowSquareOutIcon size={12} aria-hidden />
        </a>
    );

    return (
        <div className="flex min-w-0 flex-col gap-2 rounded-sm bg-info-subtle px-4 py-3">
            {g.code === 'sslcommerz' ? (
                <>
                    <p className="flex flex-wrap items-center gap-x-2 gap-y-1 t-admin-caption text-fg-secondary">
                        <span>{t('paymentGateways.sslcommerz.sandboxKeys')}</span>
                        {link(SSL_SANDBOX_SIGNUP, t('paymentGateways.sslcommerz.sandboxKeysLink'))}
                    </p>
                    <p className="flex flex-wrap items-center gap-x-2 gap-y-1 t-admin-caption text-fg-secondary">
                        <span>{t('paymentGateways.sslcommerz.liveKeys')}</span>
                        {link(SSL_LIVE_SIGNUP, t('paymentGateways.sslcommerz.liveKeysLink'))}
                    </p>
                </>
            ) : (
                <p className="t-admin-caption text-fg-secondary">{t(`paymentGateways.${g.code}.keysSource`)}</p>
            )}
        </div>
    );
}

function NotificationsSection({ gateway: g, url }: { gateway: PaymentGateway; url: string }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const id = useId();
    const fieldLabel = t(`paymentGateways.${g.code}.ipnLabel`);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(url);
            notify(t('paymentGateways.ipn.copied'));
        } catch {
            notify(t('paymentGateways.ipn.copyFailed'), 'danger');
        }
    };

    return (
        <Section id={`${id}-title`} title={t('paymentGateways.ipn.title')} about={t(`paymentGateways.${g.code}.ipnAbout`)}>
            <div className="flex min-w-0 flex-col gap-2">
                <p id={`${id}-label`} className="t-admin-body-strong text-fg-primary">
                    {fieldLabel}
                </p>
                <div className="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                    <div className="flex min-h-10 min-w-0 sm:flex-1 items-center gap-2 rounded-sm border border-line-default bg-subtle px-3 py-2">
                        <LockSimpleIcon size={14} aria-hidden className="shrink-0 text-fg-secondary" />
                        <code aria-labelledby={`${id}-label`} data-ipn-url={g.code} className="min-w-0 flex-1 break-all t-admin-mono-sm text-fg-primary">
                            {url}
                        </code>
                    </div>
                    <Button variant="outline" onClick={() => void copy()} aria-label={t('paymentGateways.ipn.copyLabel', { field: fieldLabel })} className="max-sm:w-full">
                        <CopyIcon size={16} aria-hidden />
                        {t('paymentGateways.ipn.copy')}
                    </Button>
                </div>
            </div>
            <p className="t-admin-caption text-fg-secondary">{t(`paymentGateways.${g.code}.ipnHint`)}</p>
            {g.code === 'paystation' && (
                <div className="flex min-w-0 items-start gap-3 rounded-sm bg-subtle px-4 py-3">
                    <InfoIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-fg-secondary" />
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <p className="flex flex-wrap items-center gap-x-2">
                            <span className="t-admin-body-strong text-fg-primary">{t('paymentGateways.paystation.returnTitle')}</span>
                            {/* i18n-ignore: PayStation's own parameter name, from its docs */}
                            <span className="t-admin-mono-sm text-fg-tertiary">{'callback_url'}</span>
                        </p>
                        <p className="t-admin-caption text-fg-secondary">{t('paymentGateways.paystation.returnText')}</p>
                    </div>
                </div>
            )}
        </Section>
    );
}

type ConnectionProps = { gateway: PaymentGateway; dirty: boolean; result: GatewayCheck | null; checking: boolean; label: (key: string) => string; onCheck: () => void };

/** "Test connection" checks the SAVED keys of the saved mode; PayStation can only be checked in sandbox. */
function ConnectionRow({ gateway: g, dirty, result, checking, label, onCheck }: ConnectionProps) {
    const { t, locale } = useI18n();
    const sandboxOnly = g.code === 'paystation' && g.mode === 'live';
    const mode = (m: GatewayMode) => t(`paymentGateways.mode.${m}`).toLowerCase();

    let line = null;
    if (dirty) {
        line = (
            <>
                <InfoIcon size={16} aria-hidden className="shrink-0 text-fg-secondary" />
                <span className="text-fg-secondary">{t('paymentGateways.check.saveFirst')}</span>
            </>
        );
    } else if (sandboxOnly) {
        line = (
            <>
                <InfoIcon size={16} aria-hidden className="shrink-0 text-fg-secondary" />
                <span className="text-fg-secondary">{t('paymentGateways.check.sandboxOnlyHint')}</span>
            </>
        );
    } else if (result) {
        const fields = g.credentials[result.mode].fields.map((f) => label(f.key));
        const text = t(`paymentGateways.check.${result.code}`, {
            name: g.name,
            mode: mode(result.mode),
            fields: fields.length > 1 ? t('paymentGateways.and', { a: fields.slice(0, -1).join(', '), b: fields[fields.length - 1]! }) : (fields[0] ?? ''),
        });
        line = (
            <>
                {result.ok ? (
                    <CheckCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-success" />
                ) : (
                    <XCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-danger" />
                )}
                <span className={result.ok ? 'text-fg-secondary' : 'text-fg-primary'}>{`${text} · ${dhakaStamp(result.checked_at, locale)}`}</span>
            </>
        );
    }

    return (
        <div className="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
            <Button variant="secondary" onClick={onCheck} disabled={checking || dirty || sandboxOnly} className="max-sm:w-full">
                <PlugIcon size={16} aria-hidden />
                {checking ? t('paymentGateways.check.running') : t('paymentGateways.check.button')}
            </Button>
            {line && (
                <p role="status" className="flex min-w-0 flex-1 items-start gap-2 t-admin-body">
                    {line}
                </p>
            )}
        </div>
    );
}
