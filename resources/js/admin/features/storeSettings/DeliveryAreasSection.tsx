import { useEffect, useId, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { WarningCircleIcon } from '@phosphor-icons/react';
import { updateDeliveryAreasRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { Field, controlHeight, inputClass } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { useLocationDistricts, type LocationDistrict } from './api';
import { deliveryZones, useDeliveryAreas, useSaveDeliveryAreas, type DeliveryAreas, type DeliveryAreasInput, type DeliveryZone } from './deliveryAreasApi';
import { AreaPicker, type PickerGroup } from './deliveryAreasPicker';
import { Note, SaveRow, useSettingsAccess } from './parts';

/** `PUT /settings/delivery-areas` needs settings.update AND shipments.manage_rates on the server. */
const RATES = 'shipments.manage_rates';

/** The taka sign — a currency symbol, not translatable copy. */
const TAKA = '৳';

type Draft = {
    cutoff: string;
    eta: Record<DeliveryZone, { min: string; max: string }>;
    metro: number[];
    suburb: number[];
    notServiced: number[];
    codOn: boolean;
    codZones: DeliveryZone[];
    threshold: string;
    amount: string;
};

const money = (value: string | null) => (value ?? '').replace(/\.00$/, '');

function toDraft(s: DeliveryAreas): Draft {
    const eta = {} as Draft['eta'];
    for (const zone of deliveryZones) eta[zone] = { min: String(s.eta_days[zone].min), max: String(s.eta_days[zone].max) };

    return {
        cutoff: s.cutoff_time,
        eta,
        metro: s.metro_district_ids,
        suburb: s.suburb_district_ids,
        notServiced: s.not_serviced_upazila_ids,
        codOn: s.cod_advance.enabled,
        codZones: s.cod_advance.zones,
        threshold: money(s.cod_advance.threshold),
        amount: money(s.cod_advance.amount),
    };
}

/** "3" → 3; anything else NaN, which the generated schema rejects. */
const days = (value: string) => (/^\d+$/.test(value.trim()) ? Number(value.trim()) : Number.NaN);

/** The body the API receives — validated against the API's own schema before sending (rule 27). */
function toInput(d: Draft): DeliveryAreasInput {
    const eta = {} as DeliveryAreasInput['eta_days'];
    for (const zone of deliveryZones) eta[zone] = { min: days(d.eta[zone].min), max: days(d.eta[zone].max) };
    const sorted = (ids: number[]) => [...ids].sort((a, b) => a - b);

    return {
        cutoff_time: d.cutoff,
        eta_days: eta,
        metro_district_ids: sorted(d.metro),
        suburb_district_ids: sorted(d.suburb),
        not_serviced_upazila_ids: sorted(d.notServiced),
        cod_advance: d.codOn
            ? { enabled: true, zones: deliveryZones.filter((z) => d.codZones.includes(z)), threshold: d.threshold.trim(), amount: d.amount.trim() }
            : { enabled: false },
    };
}

/** First message per error field, keyed by the full dotted path (`eta_days.outside_dhaka.max`). */
type Errors = Record<string, string>;

/** The first error at `path` or below it (`metro_district_ids` covers `metro_district_ids.3`). */
function errorAt(errors: Errors, path: string): string | undefined {
    if (errors[path]) return errors[path];
    const key = Object.keys(errors).find((k) => k.startsWith(`${path}.`));
    return key ? errors[key] : undefined;
}

/**
 * Store settings › Delivery areas & timing: the order cut-off, ETA days per zone, which
 * districts are metro or suburb, which upazilas are not serviced and the COD advance rule —
 * the `delivery.*` settings every quote and checkout read (GET/PUT /settings/delivery-areas).
 * No Figma frame: built from the B · 38 card pattern. The server decides every zone, date and
 * advance (rule 1); this screen only edits the inputs. Values are placeholders until the owner
 * confirms them (UI-UX-DESIGN-SPEC §10).
 */
export function DeliveryAreasSection() {
    const { me, canView, canEdit } = useSettingsAccess();
    const settings = useDeliveryAreas(canView);
    const districts = useLocationDistricts(canView);

    if (!canView) return null;
    if (settings.isError) return <QueryError error={settings.error} onRetry={() => void settings.refetch()} />;
    if (districts.isError) return <QueryError error={districts.error} onRetry={() => void districts.refetch()} />;
    if (!settings.data || !districts.data) return <p aria-busy="true" className="sr-only" />;

    return <DeliveryAreasForm settings={settings.data} districts={districts.data} editable={canEdit && can(me, RATES)} needsRates={canEdit && !can(me, RATES)} />;
}

function DeliveryAreasForm({ settings, districts, editable, needsRates }: { settings: DeliveryAreas; districts: LocationDistrict[]; editable: boolean; needsRates: boolean }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const save = useSaveDeliveryAreas();
    const [draft, setDraft] = useState(() => toDraft(settings));
    const [errors, setErrors] = useState<Errors>({});
    const ids = useId();

    useEffect(() => {
        setDraft(toDraft(settings));
        setErrors({});
    }, [settings]);

    const set = (patch: Partial<Draft>) => setDraft((d) => ({ ...d, ...patch }));
    const dirty = JSON.stringify(toInput(draft)) !== JSON.stringify(toInput(toDraft(settings)));
    const locked = !editable || save.isPending;
    const zoneLabel = (z: DeliveryZone) => t(`checkoutSettings.zone.${z}` as MessageKey);

    const place = (p: { name: string; name_bn: string }) => (locale === 'bn' ? p.name_bn : p.name);
    const other = (p: { name: string; name_bn: string }) => (locale === 'bn' ? p.name : p.name_bn);

    /** Divisions (by name) with their districts; a district in the other list is shown but not selectable. */
    const districtGroups = useMemo(() => {
        const build = (blocked: number[], note: string): PickerGroup[] => {
            const groups = new Map<number, PickerGroup>();
            for (const d of districts) {
                const group = groups.get(d.division.id) ?? { key: String(d.division.id), label: place(d.division), options: [] };
                const isBlocked = blocked.includes(d.id);
                group.options.push({ id: d.id, label: place(d), alt: other(d), disabled: isBlocked, note: isBlocked ? note : undefined });
                groups.set(d.division.id, group);
            }
            const list = [...groups.values()].sort((a, b) => a.label.localeCompare(b.label, locale));
            for (const g of list) g.options.sort((a, b) => a.label.localeCompare(b.label, locale));
            return list;
        };
        return { metro: build(draft.suburb, t('deliverySettings.zones.inSuburb')), suburb: build(draft.metro, t('deliverySettings.zones.inMetro')) };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [districts, draft.metro, draft.suburb, locale, t]);

    /** Districts (by name) with their upazilas, for the not-serviced picker. */
    const upazilaGroups = useMemo(
        () =>
            [...districts]
                .map((d) => ({
                    key: String(d.id),
                    label: place(d),
                    options: d.upazilas.map((u) => ({ id: u.id, label: place(u), alt: other(u) })).sort((a, b) => a.label.localeCompare(b.label, locale)),
                }))
                .sort((a, b) => a.label.localeCompare(b.label, locale)),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [districts, locale],
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const input = toInput(draft);
        const parsed = updateDeliveryAreasRequestSchema.safeParse(input);
        if (!parsed.success) {
            const next: Errors = {};
            for (const issue of parsed.error.issues) {
                const path = issue.path.map(String).join('.');
                next[path] ??= path.startsWith('eta_days') ? t('deliverySettings.error.days') : path.startsWith('cod_advance.') ? t('deliverySettings.error.money') : issue.message;
            }
            setErrors(next);
            return;
        }
        setErrors({});
        save.mutate(parsed.data, {
            onSuccess: () => notify(t('deliverySettings.saved')),
            onError: (e) => {
                if (e instanceof ApiError && e.status === 422) {
                    const next: Errors = {};
                    for (const { field, message } of e.problem.errors ?? []) next[field] ??= message;
                    setErrors(next);
                } else {
                    notify(e instanceof ApiError ? (e.problem.detail ?? e.problem.title) : t('error.title'), 'danger');
                }
            },
        });
    };

    return (
        <form onSubmit={submit} noValidate aria-label={t('deliverySettings.title')} className="flex min-w-0 flex-col gap-6">
            <Note>{t('deliverySettings.placeholders')}</Note>
            {needsRates && <Note>{t('deliverySettings.needsRates')}</Note>}

            <FormSection title={t('deliverySettings.timing.title')} description={t('deliverySettings.timing.description')}>
                <fieldset disabled={locked} className="flex min-w-0 flex-col gap-3.5">
                    <div className="max-w-xs">
                        <Field label={t('deliverySettings.cutoff.label')} hint={t('deliverySettings.cutoff.hint', { timezone: settings.timezone })} error={errorAt(errors, 'cutoff_time')}>
                            {({ id, describedBy, invalid }) => (
                                <input
                                    id={id}
                                    type="time"
                                    step={60}
                                    value={draft.cutoff}
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    onChange={(e) => set({ cutoff: e.target.value })}
                                    className={`${controlHeight.md} ${inputClass} t-admin-num`}
                                />
                            )}
                        </Field>
                    </div>
                    <div className="grid min-w-0 gap-x-4 gap-y-3.5 md:grid-cols-3">
                        {deliveryZones.map((zone) => (
                            <EtaRange
                                key={zone}
                                legend={zoneLabel(zone)}
                                value={draft.eta[zone]}
                                error={errorAt(errors, `eta_days.${zone}`)}
                                onChange={(range) => set({ eta: { ...draft.eta, [zone]: range } })}
                            />
                        ))}
                    </div>
                    <p className="t-admin-caption text-fg-secondary">{t('deliverySettings.eta.caption')}</p>
                </fieldset>
            </FormSection>

            <FormSection title={t('deliverySettings.zones.title')} description={t('deliverySettings.zones.description')}>
                <div className="grid min-w-0 gap-x-4 gap-y-5 xl:grid-cols-2">
                    <AreaPicker
                        label={t('deliverySettings.zones.metro')}
                        hint={t('deliverySettings.zones.metroHint')}
                        groups={districtGroups.metro}
                        selected={draft.metro}
                        onChange={(metro) => set({ metro })}
                        disabled={locked}
                        error={errorAt(errors, 'metro_district_ids')}
                    />
                    <AreaPicker
                        label={t('deliverySettings.zones.suburb')}
                        hint={t('deliverySettings.zones.suburbHint')}
                        groups={districtGroups.suburb}
                        selected={draft.suburb}
                        onChange={(suburb) => set({ suburb })}
                        disabled={locked}
                        error={errorAt(errors, 'suburb_district_ids')}
                    />
                </div>
                <p className="t-admin-caption text-fg-secondary">{t('deliverySettings.zones.upazilaFlags')}</p>
            </FormSection>

            <FormSection title={t('deliverySettings.notServiced.title')} description={t('deliverySettings.notServiced.description')}>
                <AreaPicker
                    label={t('deliverySettings.notServiced.label')}
                    hint={t('deliverySettings.notServiced.hint')}
                    groups={upazilaGroups}
                    selected={draft.notServiced}
                    onChange={(notServiced) => set({ notServiced })}
                    disabled={locked}
                    error={errorAt(errors, 'not_serviced_upazila_ids')}
                />
            </FormSection>

            <FormSection
                title={t('deliverySettings.cod.title')}
                description={t('deliverySettings.cod.description')}
                aside={<Switch checked={draft.codOn} labelledBy={`${ids}-cod`} disabled={locked} onChange={(codOn) => set({ codOn })} />}
            >
                <span id={`${ids}-cod`} className="sr-only">
                    {t('deliverySettings.cod.toggle')}
                </span>
                {draft.codOn ? (
                    <fieldset disabled={locked} className="flex min-w-0 flex-col gap-3.5">
                        <fieldset className="flex flex-col gap-1.5" aria-describedby={errorAt(errors, 'cod_advance.zones') ? `${ids}-zones-error` : undefined}>
                            <legend className="mb-1.5 t-admin-body-strong text-fg-primary">{t('deliverySettings.cod.zones')}</legend>
                            <div className="flex flex-wrap gap-x-5 gap-y-2">
                                {deliveryZones.map((zone) => (
                                    <label key={zone} className="flex min-h-8 items-center gap-2 t-admin-body text-fg-primary">
                                        <input
                                            type="checkbox"
                                            className="size-4 shrink-0 accent-brand"
                                            checked={draft.codZones.includes(zone)}
                                            onChange={(e) => set({ codZones: e.target.checked ? [...draft.codZones, zone] : draft.codZones.filter((z) => z !== zone) })}
                                        />
                                        {zoneLabel(zone)}
                                    </label>
                                ))}
                            </div>
                            <ErrorLine id={`${ids}-zones-error`} message={errorAt(errors, 'cod_advance.zones')} />
                        </fieldset>
                        <div className="grid min-w-0 gap-x-4 gap-y-3.5 md:grid-cols-2">
                            <Field label={t('deliverySettings.cod.threshold')} hint={t('deliverySettings.cod.thresholdHint')} error={errorAt(errors, 'cod_advance.threshold')}>
                                {({ id, describedBy, invalid }) => (
                                    <Input
                                        id={id}
                                        prefix={TAKA}
                                        inputMode="decimal"
                                        value={draft.threshold}
                                        aria-describedby={describedBy}
                                        aria-invalid={invalid || undefined}
                                        onChange={(e) => set({ threshold: e.target.value })}
                                    />
                                )}
                            </Field>
                            <Field label={t('deliverySettings.cod.amount')} hint={t('deliverySettings.cod.amountHint')} error={errorAt(errors, 'cod_advance.amount')}>
                                {({ id, describedBy, invalid }) => (
                                    <Input
                                        id={id}
                                        prefix={TAKA}
                                        inputMode="decimal"
                                        value={draft.amount}
                                        aria-describedby={describedBy}
                                        aria-invalid={invalid || undefined}
                                        onChange={(e) => set({ amount: e.target.value })}
                                    />
                                )}
                            </Field>
                        </div>
                    </fieldset>
                ) : (
                    <p className="t-admin-caption text-fg-secondary">{t('deliverySettings.cod.off')}</p>
                )}
                {editable && (
                    <SaveRow>
                        <Button type="submit" disabled={!dirty || save.isPending}>
                            {save.isPending ? t('storeSettings.saving') : t('storeSettings.save')}
                        </Button>
                    </SaveRow>
                )}
            </FormSection>
        </form>
    );
}

/** One zone's working-day window: two whole-number inputs, "from" and "to". */
function EtaRange({ legend, value, error, onChange }: { legend: string; value: { min: string; max: string }; error?: string; onChange: (range: { min: string; max: string }) => void }) {
    const { t } = useI18n();
    const id = useId();

    return (
        <fieldset className="flex min-w-0 flex-col gap-1.5" aria-describedby={error ? `${id}-error` : undefined}>
            <legend className="mb-1.5 t-admin-body-strong text-fg-primary">{legend}</legend>
            <div className="flex items-center gap-2">
                {(['min', 'max'] as const).map((end, i) => (
                    <div key={end} className="flex min-w-0 flex-1 items-center gap-2">
                        {i === 1 && (
                            <span aria-hidden className="t-admin-body text-fg-tertiary">
                                –
                            </span>
                        )}
                        <label htmlFor={`${id}-${end}`} className="sr-only">
                            {t(end === 'min' ? 'deliverySettings.eta.min' : 'deliverySettings.eta.max', { zone: legend })}
                        </label>
                        <Input
                            id={`${id}-${end}`}
                            className="min-w-0"
                            inputMode="numeric"
                            suffix={t('deliverySettings.eta.days')}
                            value={value[end]}
                            aria-invalid={error ? true : undefined}
                            onChange={(e) => onChange({ ...value, [end]: e.target.value })}
                        />
                    </div>
                ))}
            </div>
            <ErrorLine id={`${id}-error`} message={error} />
        </fieldset>
    );
}

function ErrorLine({ id, message }: { id: string; message?: string }): ReactNode {
    if (!message) return null;
    return (
        <p id={id} role="alert" className="flex items-start gap-1 t-admin-caption text-fg-primary">
            <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
            {message}
        </p>
    );
}

/** Figma toggle (as in Checkout & delivery): 36×20 track, bg/brand on, border/strong off. */
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
