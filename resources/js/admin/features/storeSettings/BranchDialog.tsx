import { useId, useMemo, useState } from 'react';
import { BuildingsIcon } from '@phosphor-icons/react';
import { storeStockLocationRequestSchema, updateStockLocationRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { locationTypes, useLocationDistricts, useSaveLocation, type LocationDistrict, type LocationType, type StoreLocation } from './api';
import { issuesOf, problemErrors, useDialog } from './parts';

type Values = {
    name: string;
    name_bn: string;
    code: string;
    type: LocationType | '';
    district_id: string;
    upazila_id: string;
    area: string;
    address_line: string;
    landmark: string;
    notes: string;
    is_active: boolean;
};

const empty: Values = { name: '', name_bn: '', code: '', type: '', district_id: '', upazila_id: '', area: '', address_line: '', landmark: '', notes: '', is_active: true };

function valuesOf(l: StoreLocation | null): Values {
    if (!l) return empty;
    return {
        name: l.name,
        name_bn: l.name_bn ?? '',
        code: l.code,
        type: l.type ?? '',
        district_id: l.district ? String(l.district.id) : '',
        upazila_id: l.upazila ? String(l.upazila.id) : '',
        area: l.area ?? '',
        address_line: l.address_line ?? '',
        landmark: l.landmark ?? '',
        notes: l.notes ?? '',
        is_active: l.is_active,
    };
}

const optional = (v: string) => (v.trim() === '' ? null : v.trim());

/** Divisions in first-seen order, each with its districts — for <optgroup>s. */
function byDivision(districts: LocationDistrict[], locale: string) {
    const groups = new Map<number, { label: string; districts: LocationDistrict[] }>();
    for (const d of districts) {
        const group = groups.get(d.division.id) ?? { label: locale === 'bn' ? d.division.name_bn : d.division.name, districts: [] };
        group.districts.push(d);
        groups.set(d.division.id, group);
    }
    return [...groups.values()].sort((a, b) => a.label.localeCompare(b.label));
}

type BranchDialogProps = {
    open: boolean;
    /** null adds a new location. */
    location: StoreLocation | null;
    onClose: () => void;
};

/**
 * Add / edit a branch, warehouse or pickup point. No frame of its own — built like the staff
 * dialog from ConfirmDialog 203:1387's frame, AdminField 185:151, AdminInput 184:338 and
 * AdminSelect 184:369. The address follows rule 31: division (from the district) → district
 * → upazila → area + line + landmark. Switching off is a checkbox here; the server refuses it
 * (409) for the default location or one that still holds stock, and the box says so up front.
 */
export function BranchDialog({ open, location, onClose }: BranchDialogProps) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const titleId = useId();
    const save = useSaveLocation();
    const districts = useLocationDistricts(open);
    const [values, setValues] = useState<Values>(empty);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const ref = useDialog(open, () => {
        setValues(valuesOf(location));
        setErrors({});
        save.reset();
    });

    const adding = location === null;
    const set = <K extends keyof Values>(key: K, value: Values[K]) => setValues((v) => ({ ...v, [key]: value }));
    const groups = useMemo(() => byDivision(districts.data ?? [], locale), [districts.data, locale]);
    const district = districts.data?.find((d) => String(d.id) === values.district_id) ?? null;
    const placeName = (p: { name: string; name_bn: string }) => (locale === 'bn' ? p.name_bn : p.name);
    const lockActive = location !== null && location.is_active && (location.is_default || location.holds_stock);

    function submit() {
        const shared = {
            name: values.name.trim(),
            name_bn: optional(values.name_bn),
            type: values.type,
            district_id: values.district_id === '' ? Number.NaN : Number(values.district_id),
            upazila_id: values.upazila_id === '' ? null : Number(values.upazila_id),
            area: optional(values.area),
            address_line: values.address_line.trim(),
            landmark: optional(values.landmark),
            notes: optional(values.notes),
            is_active: values.is_active,
        };
        const parsed = adding ? storeStockLocationRequestSchema.safeParse({ ...shared, code: values.code.trim().toLowerCase() }) : updateStockLocationRequestSchema.safeParse(shared);
        if (!parsed.success) return setErrors(issuesOf(parsed.error.issues));
        setErrors({});
        save.mutate(
            { id: location?.id ?? null, input: parsed.data },
            {
                onSuccess: (saved) => {
                    notify(t(adding ? 'storeSettings.branches.added' : 'storeSettings.branches.saved', { name: saved.name }));
                    onClose();
                },
                onError: (error) => {
                    const fields = problemErrors(error);
                    if (fields) setErrors(fields);
                    else notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
                },
            },
        );
    }

    const text = (key: 'name' | 'name_bn' | 'code' | 'area' | 'address_line' | 'landmark' | 'notes', opts: { required?: boolean; hint?: string; bn?: boolean; label?: MessageKey } = {}) => (
        <Field label={t(opts.label ?? (`storeSettings.branches.field.${key}` as MessageKey))} hint={opts.hint} error={errors[key]} required={opts.required} optional={!opts.required}>
            {({ id, describedBy, invalid }) => (
                <Input
                    id={id}
                    lang={opts.bn ? 'bn' : undefined}
                    aria-describedby={describedBy}
                    aria-invalid={invalid || undefined}
                    autoComplete="off"
                    value={values[key]}
                    onChange={(e) => set(key, e.target.value)}
                />
            )}
        </Field>
    );

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            onCancel={onClose}
            className="m-auto max-h-[calc(100dvh-2rem)] w-[min(100%-2rem,40rem)] rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay"
        >
            <form
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                <div className="flex flex-col items-start gap-2.5 px-6 pt-6 pb-4">
                    <span aria-hidden className="grid size-11 place-items-center rounded-sm bg-brand-subtle text-fg-brand">
                        <BuildingsIcon size={22} />
                    </span>
                    <h2 id={titleId} className="t-admin-section text-fg-primary">
                        {adding ? t('storeSettings.branches.dialog.addTitle') : t('storeSettings.branches.dialog.editTitle', { name: location.name })}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">{t(adding ? 'storeSettings.branches.dialog.addBody' : 'storeSettings.branches.dialog.editBody')}</p>
                </div>

                <fieldset disabled={save.isPending} className="grid min-w-0 gap-x-4 gap-y-3.5 px-6 pb-5 sm:grid-cols-2">
                    {text('name', { required: true })}
                    {text('name_bn', { bn: true })}
                    {adding && text('code', { required: true, hint: t('storeSettings.branches.field.codeHint') })}
                    <Field label={t('storeSettings.branches.field.type')} error={errors.type} required>
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={values.type} onChange={(e) => set('type', e.target.value as LocationType | '')}>
                                <option value="" disabled>
                                    {t('storeSettings.branches.type.none')}
                                </option>
                                {locationTypes.map((type) => (
                                    <option key={type} value={type}>
                                        {t(`storeSettings.branches.type.${type}` as MessageKey)}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                    <Field label={t('storeSettings.branches.field.district')} error={errors.district_id} required>
                        {({ id, describedBy, invalid }) => (
                            <Select
                                id={id}
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                                aria-busy={districts.isPending || undefined}
                                value={values.district_id}
                                onChange={(e) => setValues((v) => ({ ...v, district_id: e.target.value, upazila_id: '' }))}
                            >
                                <option value="">{t('storeSettings.branches.field.districtChoose')}</option>
                                {/* Until the list loads, keep the saved district selectable. */}
                                {!districts.data && location?.district && <option value={String(location.district.id)}>{placeName(location.district)}</option>}
                                {groups.map((g) => (
                                    <optgroup key={g.label} label={g.label}>
                                        {g.districts.map((d) => (
                                            <option key={d.id} value={String(d.id)}>
                                                {placeName(d)}
                                            </option>
                                        ))}
                                    </optgroup>
                                ))}
                            </Select>
                        )}
                    </Field>
                    <Field label={t('storeSettings.branches.field.upazila')} error={errors.upazila_id} optional>
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={values.upazila_id} disabled={values.district_id === ''} onChange={(e) => set('upazila_id', e.target.value)}>
                                <option value="">{t('storeSettings.branches.field.upazilaNone')}</option>
                                {!district && location?.upazila && <option value={String(location.upazila.id)}>{placeName(location.upazila)}</option>}
                                {district?.upazilas.map((u) => (
                                    <option key={u.id} value={String(u.id)}>
                                        {placeName(u)}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                    {text('area')}
                    <div className="sm:col-span-2">{text('address_line', { required: true, hint: t('storeSettings.branches.field.addressHint') })}</div>
                    {text('landmark', { hint: t('storeSettings.branches.field.landmarkHint') })}
                    {text('notes', { hint: t('storeSettings.branches.field.notesHint') })}
                    <div className="flex flex-col gap-1 sm:col-span-2">
                        <label className="flex items-center gap-2 t-admin-body-strong text-fg-primary">
                            <input type="checkbox" className="size-4 accent-brand" checked={values.is_active} disabled={lockActive} onChange={(e) => set('is_active', e.target.checked)} />
                            {t('storeSettings.branches.field.active')}
                        </label>
                        <p className="t-admin-caption text-fg-secondary">
                            {location?.is_default
                                ? t('storeSettings.branches.field.defaultActive')
                                : lockActive
                                  ? t('storeSettings.branches.field.holdsStock')
                                  : t('storeSettings.branches.field.activeHint')}
                        </p>
                        {errors.is_active && <p className="t-admin-caption text-fg-primary">{errors.is_active}</p>}
                    </div>
                </fieldset>

                <div className="flex justify-end gap-3 border-t border-line-default px-6 py-4">
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" disabled={save.isPending}>
                        {adding ? t('storeSettings.branches.submitAdd') : t('storeSettings.branches.submitSave')}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}
