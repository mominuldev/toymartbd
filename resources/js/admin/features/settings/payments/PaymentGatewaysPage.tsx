import { useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useSearchParams } from 'react-router';
import { HandCoinsIcon, LockSimpleIcon } from '@phosphor-icons/react';
import { updatePaymentGatewayRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Switch } from '@/features/settings/messaging/Switch';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { gatewayModes, useCheckPaymentGateway, usePaymentGateways, useSavePaymentGateway, type GatewayCheck, type PaymentGateway, type PaymentGatewayCode } from './api';
import { GatewayPanel } from './GatewayPanel';
import { PaymentLogo } from './logos/PaymentLogo';
import { diff, envChip, tileStatus, toDraft, type FormValues } from './model';
import { Dot } from './parts';

const PERMISSION = 'settings.manage_integrations';

/**
 * Integrations › Payments (Figma B · 37a, SSLCommerz `634:54154`, PayStation `640:52732`, states
 * `636:52583`, 390 `636:52657`): a tile per gateway plus Cash on delivery, and the settings panel
 * of the selected gateway (`?gateway=` in the URL). One form holds every gateway's draft, so a
 * tile's switch and the panel's "Show at checkout" are the same value and switching tiles keeps
 * unsaved edits; Save sends only the selected gateway's changes. Secrets are write-only (rule
 * 20); what checkout offers comes from the server's `status`, never from this screen.
 */
export function PaymentGatewaysPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const allowed = can(me, PERMISSION);
    const gateways = usePaymentGateways(allowed);

    if (me && !allowed) return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;

    return (
        <section className="flex min-w-0 flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('paymentGateways.crumb.settings') }, { label: t('paymentGateways.crumb.integrations'), to: '/integrations' }, { label: t('paymentGateways.title') }]}
                title={t('paymentGateways.title')}
                subtitle={t('paymentGateways.subtitle')}
            />
            {gateways.isError ? (
                <QueryError error={gateways.error} onRetry={() => void gateways.refetch()} />
            ) : !gateways.data ? (
                <p aria-busy="true" className="t-admin-body text-fg-tertiary">
                    {t('common.loading')}
                </p>
            ) : (
                <Workspace gateways={gateways.data} />
            )}
        </section>
    );
}

function Workspace({ gateways }: { gateways: PaymentGateway[] }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const [params, setParams] = useSearchParams();
    const save = useSavePaymentGateway();
    const check = useCheckPaymentGateway();
    const [results, setResults] = useState<Partial<Record<PaymentGatewayCode, GatewayCheck>>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<FormValues>({ defaultValues: { gateways: Object.fromEntries(gateways.map((g) => [g.code, toDraft(g)])) } });
    const { setValue, getValues, setError, clearErrors, watch } = form;

    // A gateway the server changed (after a save) starts a fresh draft; the others keep theirs.
    const seen = useRef(new Map(gateways.map((g) => [g.code, JSON.stringify(g)])));
    useEffect(() => {
        for (const g of gateways) {
            const json = JSON.stringify(g);
            if (seen.current.get(g.code) !== json) {
                seen.current.set(g.code, json);
                setValue(`gateways.${g.code}`, toDraft(g));
            }
        }
    }, [gateways, setValue]);

    const requested = params.get('gateway');
    const selected = gateways.find((g) => g.code === requested) ?? gateways[0];
    const drafts = watch('gateways');
    const select = (code: PaymentGatewayCode) => {
        setFormError(null);
        setParams((current) => {
            const next = new URLSearchParams(current);
            next.set('gateway', code);
            return next;
        }, { replace: true });
    };

    const submit = () => {
        if (!selected || save.isPending) return;
        const draft = getValues(`gateways.${selected.code}`);
        const { input, changes } = diff(selected, draft);
        if (changes.length === 0) {
            notify(t('paymentGateways.nothingToSave'));
            return;
        }
        setFormError(null);
        clearErrors(`gateways.${selected.code}`);
        // The body the API receives, checked against the API's own generated schema (rule 27).
        if (!updatePaymentGatewayRequestSchema.safeParse(input).success) {
            setFormError(t('error.title'));
            return;
        }
        const g = selected;
        save.mutate(
            { code: g.code, input },
            {
                onSuccess: () => {
                    setResults((current) => ({ ...current, [g.code]: undefined }));
                    notify(t('paymentGateways.saved', { name: g.name }));
                },
                onError: (error) => {
                    if (!(error instanceof ApiError) || error.status !== 422) {
                        notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
                        return;
                    }
                    let placed = false;
                    const place = (path: Parameters<typeof setError>[0], message: string | undefined) => {
                        if (!message) return;
                        setError(path, { type: 'server', message });
                        placed = true;
                    };
                    place(`gateways.${g.code}.mode`, error.fieldError('mode'));
                    place(`gateways.${g.code}.is_active`, error.fieldError('is_active'));
                    for (const mode of gatewayModes) {
                        for (const field of g.credentials[mode].fields) {
                            place(`gateways.${g.code}.${mode}.values.${field.key}`, error.fieldError(`credentials.${mode}.${field.key}`));
                        }
                        place(`gateways.${g.code}.${mode}.values`, error.fieldError(`credentials.${mode}`) ?? error.fieldError(`clear.${mode}`));
                    }
                    if (!placed) setFormError(error.problem.detail ?? error.problem.title);
                },
            },
        );
    };

    const runCheck = (g: PaymentGateway) =>
        check.mutate(g.code, {
            onSuccess: (result) => setResults((current) => ({ ...current, [g.code]: result })),
            onError: (error) => notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger'),
        });

    // ⌘S / Ctrl+S saves the selected gateway (A7 FormActions), as on the product form.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
                event.preventDefault();
                submit();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    const toggle = (g: PaymentGateway, on: boolean) => {
        if (g.code !== selected?.code) select(g.code);
        setValue(`gateways.${g.code}.is_active`, on, { shouldDirty: true });
        clearErrors(`gateways.${g.code}.is_active`);
    };

    return (
        <>
            <ul aria-label={t('paymentGateways.tiles')} className="flex min-w-0 flex-col overflow-hidden rounded-md border border-line-default bg-surface md:grid md:grid-cols-3 md:gap-4 md:overflow-visible md:rounded-none md:border-0 md:bg-transparent">
                {gateways.map((g) => {
                    const draft = drafts[g.code];
                    if (!draft) return null;
                    return (
                        <GatewayTile
                            key={g.code}
                            gateway={g}
                            on={draft.is_active}
                            selected={g.code === selected?.code}
                            unsaved={diff(g, draft).changes.length > 0}
                            onSelect={() => select(g.code)}
                            onToggle={(on) => toggle(g, on)}
                        />
                    );
                })}
                <CodTile />
            </ul>

            {selected && drafts[selected.code] && (
                <GatewayPanel
                    key={selected.code}
                    gateway={selected}
                    form={form}
                    saving={save.isPending}
                    formError={formError}
                    result={results[selected.code] ?? null}
                    checking={check.isPending}
                    onSave={submit}
                    onDiscard={() => {
                        setFormError(null);
                        clearErrors(`gateways.${selected.code}`);
                        setValue(`gateways.${selected.code}`, toDraft(selected));
                    }}
                    onCheck={() => runCheck(selected)}
                />
            )}
        </>
    );
}

const tileFrame = 'relative flex min-w-0 gap-3 border-line-default bg-surface p-4 max-md:items-center max-md:border-b max-md:last:border-b-0 md:flex-col md:gap-4 md:rounded-md md:border';

type TileProps = { gateway: PaymentGateway; on: boolean; selected: boolean; unsaved: boolean; onSelect: () => void; onToggle: (on: boolean) => void };

/** A gateway tile (B · 37a tiles `640:52990`): the whole tile selects it; the switch is its own control. */
function GatewayTile({ gateway: g, on, selected, unsaved, onSelect, onToggle }: TileProps) {
    const { t } = useI18n();
    const env = envChip(g);
    const status = tileStatus(g);
    const showStatus = g.status.reason !== 'sandbox_in_production';

    return (
        <li className={`${tileFrame} ${selected ? 'border-line-brand ring-1 ring-line-brand ring-inset max-md:bg-brand-subtle' : ''}`}>
            <div className="flex min-w-0 flex-1 items-center gap-3 md:w-full md:flex-none">
                <span aria-hidden className="grid size-10 shrink-0 place-items-center rounded-sm border border-line-default bg-surface">
                    <PaymentLogo code={g.code} />
                </span>
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <div className="flex min-w-0 items-center gap-2">
                        {/* The stretched button makes the whole tile a target; the switch sits above it. */}
                        <button
                            type="button"
                            aria-pressed={selected}
                            aria-label={t('paymentGateways.tile.select', { name: g.name })}
                            onClick={onSelect}
                            className="truncate text-start t-admin-body-strong text-fg-primary after:absolute after:inset-0 after:rounded-md focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-line-focus"
                        >
                            {g.name}
                        </button>
                        <span className="md:hidden">
                            <StatusChip tone={env.tone} label={t(env.label)} />
                        </span>
                    </div>
                    <p className="truncate t-admin-caption text-fg-tertiary max-md:hidden">{t(`paymentGateways.${g.code}.short`)}</p>
                    {showStatus && (
                        <p className="flex min-w-0 items-center gap-1 t-admin-caption text-fg-secondary md:hidden">
                            <Dot tone={status.tone} />
                            <span className="truncate">{t(status.label)}</span>
                        </p>
                    )}
                </div>
                <span className="relative z-10 flex">
                    <Switch checked={on} label={t('paymentGateways.switch', { name: g.name })} onChange={onToggle} />
                </span>
            </div>
            <div className="flex min-w-0 items-center gap-2 max-md:hidden">
                <StatusChip tone={env.tone} label={t(env.label)} />
                {showStatus && (
                    <p className="flex min-w-0 flex-1 items-center gap-1 t-admin-caption text-fg-secondary">
                        <Dot tone={status.tone} />
                        <span className="truncate">{unsaved ? t('paymentGateways.tile.unsaved') : t(status.label)}</span>
                    </p>
                )}
            </div>
        </li>
    );
}

/** Cash on delivery: always on (rule 33), shown for completeness, nothing to set here. */
function CodTile() {
    const { t } = useI18n();
    return (
        <li className={tileFrame}>
            <div className="flex min-w-0 flex-1 items-center gap-3 md:w-full md:flex-none">
                <span aria-hidden className="grid size-10 shrink-0 place-items-center rounded-sm border border-line-default bg-subtle text-fg-primary">
                    <HandCoinsIcon size={20} />
                </span>
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <div className="flex min-w-0 items-center gap-2">
                        <h2 className="truncate t-admin-body-strong text-fg-primary">{t('paymentGateways.cod.name')}</h2>
                        <span className="md:hidden">
                            <StatusChip tone="success" label={t('paymentGateways.cod.alwaysOn')} />
                        </span>
                    </div>
                    <p className="truncate t-admin-caption text-fg-tertiary max-md:hidden">{t('paymentGateways.cod.short')}</p>
                    <p className="flex min-w-0 items-center gap-1 t-admin-caption text-fg-secondary md:hidden">
                        <Dot tone="success" />
                        <span className="truncate">{t('paymentGateways.tile.offered')}</span>
                    </p>
                </div>
                <LockSimpleIcon size={16} aria-hidden className="shrink-0 text-fg-secondary" />
            </div>
            <div className="flex min-w-0 items-center gap-2 max-md:hidden">
                <StatusChip tone="success" label={t('paymentGateways.cod.alwaysOn')} />
                <p className="flex min-w-0 flex-1 items-center gap-1 t-admin-caption text-fg-secondary">
                    <Dot tone="success" />
                    <span className="truncate">{t('paymentGateways.tile.offered')}</span>
                </p>
            </div>
        </li>
    );
}
