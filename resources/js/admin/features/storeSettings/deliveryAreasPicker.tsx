import { useId, useMemo, useState } from 'react';
import { WarningCircleIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { SearchField } from '@/components/ui/SearchField';

export type PickerOption = { id: number; label: string; /** Searchable too (the other script's name). */ alt: string; note?: string; disabled?: boolean };
export type PickerGroup = { key: string; label: string; options: PickerOption[] };

type AreaPickerProps = {
    label: string;
    hint: string;
    groups: PickerGroup[];
    selected: number[];
    onChange: (ids: number[]) => void;
    disabled?: boolean;
    error?: string;
};

/**
 * A searchable, grouped checklist with the chosen items as removable chips above it — the
 * district (division-grouped) and upazila (district-grouped) pickers of Delivery areas &
 * timing. Built from AdminField 185:151, SearchField 185:28 and native checkboxes; no
 * Figma frame of its own. Names follow the UI language; search matches either script.
 */
export function AreaPicker({ label, hint, groups, selected, onChange, disabled, error }: AreaPickerProps) {
    const { t } = useI18n();
    const id = useId();
    const [query, setQuery] = useState('');
    const chosen = useMemo(() => new Set(selected), [selected]);

    const byId = useMemo(() => {
        const map = new Map<number, PickerOption>();
        for (const g of groups) for (const o of g.options) map.set(o.id, o);
        return map;
    }, [groups]);

    const needle = query.trim().toLocaleLowerCase();
    const visible = useMemo(
        () =>
            groups
                .map((g) => {
                    if (needle === '' || g.label.toLocaleLowerCase().includes(needle)) return g;
                    return { ...g, options: g.options.filter((o) => o.label.toLocaleLowerCase().includes(needle) || o.alt.toLocaleLowerCase().includes(needle)) };
                })
                .filter((g) => g.options.length > 0),
        [groups, needle],
    );

    const toggle = (optionId: number, on: boolean) => onChange(on ? [...selected, optionId] : selected.filter((s) => s !== optionId));

    return (
        <fieldset className="flex min-w-0 flex-col gap-1.5" aria-describedby={`${id}-hint${error ? ` ${id}-error` : ''}`} aria-invalid={error ? true : undefined}>
            <legend className="mb-1.5 t-admin-body-strong text-fg-primary">{label}</legend>
            <p id={`${id}-hint`} className="t-admin-caption text-fg-secondary">
                {hint}
            </p>
            <ul aria-label={t('deliverySettings.picker.chosen', { label })} className="flex min-h-8 flex-wrap items-center gap-1.5">
                {selected.length === 0 && <li className="t-admin-caption text-fg-tertiary">{t('deliverySettings.picker.none')}</li>}
                {selected.map((s) => {
                    const name = byId.get(s)?.label ?? `#${s}`;
                    return (
                        <li key={s} className="inline-flex h-7 items-center gap-1 rounded-xs border border-line-default bg-subtle ps-2 pe-1 t-admin-caption-strong text-fg-primary">
                            {name}
                            {!disabled && (
                                <button
                                    type="button"
                                    onClick={() => toggle(s, false)}
                                    aria-label={t('deliverySettings.picker.remove', { name })}
                                    className="inline-flex size-5 items-center justify-center rounded-xs text-fg-secondary hover:bg-surface hover:text-fg-primary"
                                >
                                    <XIcon size={12} aria-hidden />
                                </button>
                            )}
                        </li>
                    );
                })}
            </ul>
            {!disabled && (
                <>
                    <SearchField label={t('deliverySettings.picker.search', { label })} placeholder={t('deliverySettings.picker.searchPlaceholder')} value={query} onChange={(e) => setQuery(e.target.value)} />
                    <div className="max-h-64 overflow-y-auto rounded-sm border border-line-default">
                        {visible.length === 0 && <p className="px-3 py-2.5 t-admin-caption text-fg-tertiary">{t('deliverySettings.picker.noMatch')}</p>}
                        {visible.map((g) => (
                            <fieldset key={g.key} className="border-b border-line-default px-3 py-2 last:border-b-0">
                                <legend className="float-left w-full pb-1 t-admin-caption-strong text-fg-secondary">{g.label}</legend>
                                <div className="clear-left grid gap-x-4 gap-y-0.5 sm:grid-cols-2 xl:grid-cols-3">
                                    {g.options.map((o) => (
                                        <label key={o.id} className={`flex min-h-8 items-center gap-2 t-admin-body ${o.disabled ? 'text-fg-disabled' : 'text-fg-primary'}`}>
                                            <input
                                                type="checkbox"
                                                className="size-4 shrink-0 accent-brand"
                                                checked={chosen.has(o.id)}
                                                disabled={o.disabled && !chosen.has(o.id)}
                                                onChange={(e) => toggle(o.id, e.target.checked)}
                                            />
                                            <span className="min-w-0 truncate">{o.label}</span>
                                            {o.note && <span className="shrink-0 t-admin-caption text-fg-tertiary">{o.note}</span>}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                        ))}
                    </div>
                </>
            )}
            {error && (
                <p id={`${id}-error`} className="flex items-start gap-1 t-admin-caption text-fg-primary">
                    <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                    {error}
                </p>
            )}
        </fieldset>
    );
}
