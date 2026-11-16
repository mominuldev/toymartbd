import { useEffect, useId, useState, type FormEvent, type ReactNode } from 'react';
import { Link } from 'react-router';
import { WarningCircleIcon } from '@phosphor-icons/react';
import { updateDeliverySettingsRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Field } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { useCheckoutSettings, useSaveFreeDelivery, type CheckoutSettings } from './checkoutApi';
import { StickySaveBar, UnsavedChangesGuard, useChangeSummary, useSavedFlash } from './parts';

const FORM_ID = 'checkout-settings-form';

const VIEW = 'settings.view';
const UPDATE = 'settings.update';
/** `PUT /settings/delivery` is guarded by shipments.manage_rates on the server. */
const RATES = 'shipments.manage_rates';
const COURIER_SETTINGS = ['shipments.manage_rates', 'settings.manage_integrations'];

/** The taka sign — a currency symbol, not translatable copy. */
const TAKA = '৳';

/** "3000.00" → "3,000"; display only, never arithmetic (rule 1). */
function formatMoney(decimal: string): string {
    const [whole = '', fraction = ''] = decimal.split('.');
    const trimmed = fraction.replace(/0+$/, '');
    return `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}${trimmed ? `.${trimmed}` : ''}`;
}

const draftOf = (value: string | null) => (value ?? '').replace(/\.00$/, '');

/**
 * Store settings › Checkout & delivery rules (Figma B · 38, 241:50827). Every row says what
 * checkout really does (GET /settings/checkout): rows checkout cannot change are shown as
 * fixed, the bKash advance as not available yet — never a switch that does nothing. The one
 * editable rule, free delivery, saves to PUT /settings/delivery, the store's single copy of
 * that threshold. Zone charges are the default courier's rate card, edited there.
 */
export function CheckoutSection() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const allowed = can(me, VIEW);
    const settings = useCheckoutSettings(allowed);

    if (me && !allowed) {
        return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;
    }
    if (settings.isError) {
        return <QueryError error={settings.error} onRetry={() => void settings.refetch()} />;
    }
    if (!settings.data) {
        return <p aria-busy="true" className="sr-only" />;
    }

    return (
        <CheckoutCard
            settings={settings.data}
            editable={can(me, UPDATE) && can(me, RATES)}
            courierLink={COURIER_SETTINGS.some((permission) => can(me, permission))}
        />
    );
}

function CheckoutCard({ settings, editable, courierLink }: { settings: CheckoutSettings; editable: boolean; courierLink: boolean }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSaveFreeDelivery();
    const amountId = useId();
    const [freeOn, setFreeOn] = useState(settings.free_shipping_threshold !== null);
    const [amount, setAmount] = useState(draftOf(settings.free_shipping_threshold));
    const [error, setError] = useState<string | undefined>();
    const [justSaved, flashSaved] = useSavedFlash();

    useEffect(() => {
        setFreeOn(settings.free_shipping_threshold !== null);
        setAmount(draftOf(settings.free_shipping_threshold));
    }, [settings.free_shipping_threshold]);

    const saved = draftOf(settings.free_shipping_threshold);
    const dirty = freeOn !== (settings.free_shipping_threshold !== null) || (freeOn && amount.trim() !== saved);
    const locked = !editable || save.isPending;
    const summary = useChangeSummary(dirty ? [t('storeSettings.changed.freeDelivery')] : []);

    const discard = () => {
        setFreeOn(settings.free_shipping_threshold !== null);
        setAmount(draftOf(settings.free_shipping_threshold));
        setError(undefined);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const input = { free_shipping_threshold: freeOn ? amount.trim() : null };
        // Validate the body the API will receive, against the API's own schema (rule 27).
        const parsed = updateDeliverySettingsRequestSchema.safeParse(input);
        if (!parsed.success || (freeOn && /^0+(\.0+)?$/.test(amount.trim()))) {
            setError(t('checkoutSettings.free.invalid'));
            return;
        }
        setError(undefined);
        save.mutate(parsed.data, {
            onSuccess: () => flashSaved(),
            onError: (e) => {
                if (e instanceof ApiError && e.status === 422) {
                    setError(e.fieldError('free_shipping_threshold') ?? t('checkoutSettings.free.invalid'));
                } else {
                    notify(e instanceof ApiError ? e.problem.title : t('error.title'), 'danger');
                }
            },
        });
    };

    const online = settings.online_methods.map((m) => t(`checkoutSettings.method.${m}` as MessageKey)).join(', ');
    const codAdvance = settings.cod_advance;
    const courier = settings.delivery_courier;

    return (
        <form id={FORM_ID} onSubmit={submit} noValidate aria-label={t('checkoutSettings.title')}>
            {editable && <UnsavedChangesGuard when={dirty} />}
            {editable && (
                <StickySaveBar section={t('storeSettings.nav.checkout')} dirty={dirty} saved={justSaved} savedLabel={t('checkoutSettings.saved')} summary={summary} saving={save.isPending} onDiscard={discard} form={FORM_ID} />
            )}
            <FormSection title={t('checkoutSettings.title')}>
                <ul className="flex flex-col gap-3">
                    <Row label={t('checkoutSettings.guest.label')} caption={t('checkoutSettings.guest.caption')}>
                        <StatusChip tone="success" label={t('checkoutSettings.alwaysOn')} />
                    </Row>
                    <Row
                        label={t('checkoutSettings.cod.label')}
                        caption={[
                            online === '' ? t('checkoutSettings.cod.noOnline') : t('checkoutSettings.cod.online', { methods: online }),
                            codAdvance
                                ? t('checkoutSettings.cod.advance', {
                                      zones: codAdvance.zones.map((z) => t(`checkoutSettings.zone.${z}` as MessageKey)).join(', '),
                                      threshold: `${TAKA}${formatMoney(codAdvance.threshold)}`,
                                      amount: `${TAKA}${formatMoney(codAdvance.amount)}`,
                                  })
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' ')}
                    >
                        <StatusChip tone="success" label={t('checkoutSettings.alwaysOn')} />
                    </Row>
                    <Row
                        label={t('checkoutSettings.advance.label')}
                        caption={t(
                            settings.risk_advance.blocker === 'no_bkash_gateway' ? 'checkoutSettings.advance.noGateway' : 'checkoutSettings.advance.noFlow',
                            { score: settings.risk_advance.hold_score },
                        )}
                    >
                        <StatusChip tone="warning" label={t('checkoutSettings.notAvailable')} />
                    </Row>
                    <Row
                        labelId={`${amountId}-label`}
                        label={
                            freeOn && amount.trim() !== ''
                                ? t('checkoutSettings.free.label', { amount: `${TAKA}${formatMoney(amount.trim())}` })
                                : t('checkoutSettings.free.labelOff')
                        }
                        caption={t('checkoutSettings.free.caption')}
                        error={error}
                        errorId={`${amountId}-error`}
                    >
                        {freeOn && (
                            <div className="w-28">
                                <label htmlFor={amountId} className="sr-only">
                                    {t('checkoutSettings.free.amount')}
                                </label>
                                <Input
                                    id={amountId}
                                    size="sm"
                                    prefix={TAKA}
                                    inputMode="decimal"
                                    value={amount}
                                    disabled={locked}
                                    aria-invalid={error ? true : undefined}
                                    aria-describedby={error ? `${amountId}-error` : undefined}
                                    onChange={(e) => setAmount(e.target.value)}
                                />
                            </div>
                        )}
                        <Switch checked={freeOn} labelledBy={`${amountId}-label`} disabled={locked} onChange={setFreeOn} />
                    </Row>
                    <Row label={t('checkoutSettings.notes.label')} caption={t('checkoutSettings.notes.caption', { max: settings.order_note_max })}>
                        <StatusChip tone="success" label={t('checkoutSettings.alwaysOn')} />
                    </Row>
                </ul>

                <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-3">
                    {settings.delivery_charges.map((row) => (
                        <Field key={row.zone} label={t(`checkoutSettings.zone.${row.zone}` as MessageKey)}>
                            {({ id, describedBy }) => (
                                <Input
                                    id={id}
                                    aria-describedby={describedBy}
                                    prefix={TAKA}
                                    readOnly
                                    value={row.charge === null ? '' : formatMoney(row.charge)}
                                    placeholder={row.charge === null ? t('checkoutSettings.charges.none') : undefined}
                                />
                            )}
                        </Field>
                    ))}
                </div>
                <p className="t-admin-caption text-fg-secondary">
                    {courier ? t('checkoutSettings.charges.caption', { courier }) : t('checkoutSettings.charges.noCourier')}{' '}
                    {/* Underlined always: a link inside a sentence must not rely on colour alone (WCAG 1.4.1, axe link-in-text-block). */}
                    {courierLink && (
                        <Link to="/integrations/couriers" className="text-fg-brand underline underline-offset-2">
                            {t('checkoutSettings.charges.edit')}
                        </Link>
                    )}
                </p>
                <p className="t-admin-caption text-fg-secondary">
                    <Link to="/settings/delivery" className="text-fg-brand underline underline-offset-2">
                        {t('deliverySettings.checkoutLink')}
                    </Link>
                </p>
            </FormSection>
        </form>
    );
}

function Row({
    label,
    labelId,
    caption,
    error,
    errorId,
    children,
}: {
    label: string;
    labelId?: string;
    caption: string;
    error?: string;
    errorId?: string;
    children: ReactNode;
}) {
    return (
        <li className="flex flex-col gap-1.5">
            <div className="flex items-start justify-between gap-4">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <p id={labelId} className="t-admin-body text-fg-primary">
                        {label}
                    </p>
                    <p className="t-admin-caption text-fg-tertiary">{caption}</p>
                </div>
                <div className="flex shrink-0 items-center gap-3">{children}</div>
            </div>
            {error && (
                <p id={errorId} role="alert" className="flex items-start gap-1 t-admin-caption text-fg-primary">
                    <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                    {error}
                </p>
            )}
        </li>
    );
}

/** Figma toggle: 36×20 track, bg/brand on, border/strong off. */
function Switch({ checked, labelledBy, disabled, onChange }: { checked: boolean; labelledBy: string; disabled?: boolean; onChange: (value: boolean) => void }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-labelledby={labelledBy}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors duration-150 ease-standard disabled:cursor-not-allowed disabled:opacity-60 ${checked ? 'bg-brand' : 'bg-line-strong'}`}
        >
            <span aria-hidden className={`size-4 rounded-full bg-surface shadow-e1 transition-transform duration-150 ease-standard ${checked ? 'translate-x-4' : 'translate-x-0'}`} />
        </button>
    );
}
