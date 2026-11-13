import type { ReactNode } from 'react';
import { ArrowDownIcon, ArrowUpIcon, TrashIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { controlHeight, Field, inputClass } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { Select } from '@/components/ui/Select';
import { str, type Content } from './content';

/*
 * Form pieces for the B · 26 settings panel (AdminField 185:151, AdminInput 184:338, AdminSelect
 * 184:369, toggle row). Every field reports `content.<path>` server errors under it.
 */

export type FieldCtx = {
    errors: Record<string, string>;
    disabled: boolean;
};

/** A switch row: body text left, the 36 × 20 toggle right (Figma "Hide sold-out products"). */
export function SwitchRow({ label, checked, onChange, disabled }: { label: string; checked: boolean; onChange: (next: boolean) => void; disabled?: boolean }) {
    return (
        <label className="flex items-center gap-2">
            <span className="min-w-0 flex-1 t-admin-body text-fg-primary">{label}</span>
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                disabled={disabled}
                onClick={() => onChange(!checked)}
                className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors duration-150 ease-standard disabled:cursor-not-allowed disabled:opacity-60 ${checked ? 'bg-brand' : 'bg-line-strong'}`}
            >
                <span aria-hidden className={`size-4 rounded-full bg-surface shadow-e1 transition-transform duration-150 ease-standard ${checked ? 'translate-x-4' : 'translate-x-0'}`} />
                <span className="sr-only">{label}</span>
            </button>
        </label>
    );
}

/** One text field. `path` is the content path (`title`, `slides.0.headline`), for errors. */
export function TextField({
    label,
    value,
    onChange,
    path,
    ctx,
    hint,
    lang,
    multiline = false,
    rows = 3,
    mono = false,
}: {
    label: string;
    value: string;
    onChange: (next: string) => void;
    path: string;
    ctx: FieldCtx;
    hint?: string;
    lang?: 'en' | 'bn';
    multiline?: boolean;
    rows?: number;
    mono?: boolean;
}) {
    return (
        <Field label={label} hint={hint} error={ctx.errors[`content.${path}`]}>
            {({ id, describedBy, invalid }) =>
                multiline ? (
                    <textarea
                        id={id}
                        rows={rows}
                        lang={lang}
                        aria-describedby={describedBy}
                        aria-invalid={invalid || undefined}
                        disabled={ctx.disabled}
                        className={`py-2 ${inputClass} ${mono ? 't-admin-mono' : ''}`}
                        value={value}
                        onChange={(e) => onChange(e.target.value)}
                    />
                ) : (
                    <input
                        id={id}
                        lang={lang}
                        aria-describedby={describedBy}
                        aria-invalid={invalid || undefined}
                        disabled={ctx.disabled}
                        className={`${controlHeight.md} ${inputClass}`}
                        value={value}
                        onChange={(e) => onChange(e.target.value)}
                    />
                )
            }
        </Field>
    );
}

/** The English field and its Bangla twin (`key`, `key_bn`), labelled from `homeBuilder.f.<labelKey>`. */
export function Bilingual({
    content,
    prefix = '',
    keyName,
    labelKey = keyName,
    set,
    ctx,
    multiline,
    hint,
}: {
    content: Content;
    prefix?: string;
    keyName: string;
    labelKey?: string;
    set: (key: string, value: string) => void;
    ctx: FieldCtx;
    multiline?: boolean;
    hint?: string;
}) {
    const { t } = useI18n();
    return (
        <>
            <TextField label={t(`homeBuilder.f.${labelKey}` as MessageKey)} lang="en" value={str(content[keyName])} onChange={(v) => set(keyName, v)} path={`${prefix}${keyName}`} ctx={ctx} multiline={multiline} hint={hint} />
            <TextField label={t(`homeBuilder.f.${labelKey}_bn` as MessageKey)} lang="bn" value={str(content[`${keyName}_bn`])} onChange={(v) => set(`${keyName}_bn`, v)} path={`${prefix}${keyName}_bn`} ctx={ctx} multiline={multiline} />
        </>
    );
}

export function SelectField<T extends string | number>({
    label,
    value,
    options,
    onChange,
    path,
    ctx,
    placeholder,
}: {
    label: string;
    value: T | null;
    options: ReadonlyArray<{ value: T; label: string }>;
    onChange: (next: T) => void;
    path: string;
    ctx: FieldCtx;
    placeholder?: string;
}) {
    return (
        <Field label={label} error={ctx.errors[`content.${path}`]}>
            {({ id, describedBy, invalid }) => (
                <Select
                    id={id}
                    aria-describedby={describedBy}
                    aria-invalid={invalid || undefined}
                    disabled={ctx.disabled}
                    value={value === null ? '' : String(value)}
                    onChange={(e) => {
                        const picked = options.find((o) => String(o.value) === e.target.value);
                        if (picked) onChange(picked.value);
                    }}
                >
                    {value === null && <option value="">{placeholder}</option>}
                    {options.map((o) => (
                        <option key={String(o.value)} value={String(o.value)}>
                            {o.label}
                        </option>
                    ))}
                </Select>
            )}
        </Field>
    );
}

/** A repeatable group (slides, USP items, FAQ questions): heading, move up/down, remove. */
export function Repeater({
    title,
    index,
    count,
    min,
    onMove,
    onRemove,
    disabled,
    children,
}: {
    title: string;
    index: number;
    count: number;
    min: number;
    onMove: (from: number, to: number) => void;
    onRemove: (index: number) => void;
    disabled: boolean;
    children: ReactNode;
}) {
    const { t } = useI18n();
    return (
        <fieldset className="flex flex-col gap-3 rounded-sm border border-line-default p-3">
            <legend className="flex w-full items-center gap-1 px-1 t-admin-body-strong text-fg-primary">
                <span className="flex-1">{title}</span>
            </legend>
            <div className="-mt-2 flex justify-end gap-1">
                <IconButton size="sm" aria-label={t('homeBuilder.f.moveUp', { name: title })} disabled={disabled || index === 0} onClick={() => onMove(index, index - 1)}>
                    <ArrowUpIcon size={16} aria-hidden />
                </IconButton>
                <IconButton size="sm" aria-label={t('homeBuilder.f.moveDown', { name: title })} disabled={disabled || index === count - 1} onClick={() => onMove(index, index + 1)}>
                    <ArrowDownIcon size={16} aria-hidden />
                </IconButton>
                <IconButton size="sm" aria-label={t('homeBuilder.f.remove', { name: title })} disabled={disabled || count <= min} onClick={() => onRemove(index)}>
                    <TrashIcon size={16} aria-hidden />
                </IconButton>
            </div>
            {children}
        </fieldset>
    );
}

/** Move one entry of a list. */
export function moved<T>(list: readonly T[], from: number, to: number): T[] {
    const next = [...list];
    const [item] = next.splice(from, 1);
    if (item !== undefined) next.splice(to, 0, item);
    return next;
}
