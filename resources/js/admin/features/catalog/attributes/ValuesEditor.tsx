import { useState } from 'react';
import { ArrowDownIcon, ArrowUpIcon, FloppyDiskIcon, PlusIcon, TrashIcon } from '@phosphor-icons/react';
import { storeAttributeValueRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { inputClass } from '@/components/ui/Field';
import { useToast } from '@/components/ui/Toast';
import { useValueMutations, type Attribute, type AttributeValue, type ValueInput } from './api';
import { Swatch } from './Swatch';

type Draft = { value: string; value_bn: string; slug: string; color_hex: string };
type Errors = Partial<Record<keyof Draft, string>>;

const emptyDraft: Draft = { value: '', value_bn: '', slug: '', color_hex: '' };

function toDraft(value: AttributeValue): Draft {
    return { value: value.value, value_bn: value.value_bn ?? '', slug: value.slug, color_hex: value.color_hex ?? '' };
}

function toInput(draft: Draft, isColor: boolean): ValueInput {
    const orNull = (text: string) => (text.trim() === '' ? null : text.trim());
    return {
        value: draft.value.trim(),
        value_bn: orNull(draft.value_bn),
        slug: orNull(draft.slug),
        ...(isColor ? { color_hex: orNull(draft.color_hex)?.toUpperCase() ?? null } : {}),
    };
}

/** Validate against the API's own generated schema (rule 27) before sending. */
function validate(input: ValueInput): Errors {
    const result = storeAttributeValueRequestSchema.safeParse(input);
    if (result.success) return {};
    const errors: Errors = {};
    for (const issue of result.error.issues) {
        const field = issue.path[0] as keyof Draft;
        errors[field] ??= issue.message;
    }
    return errors;
}

function serverErrors(error: unknown): Errors | null {
    if (!(error instanceof ApiError) || error.status !== 422) return null;
    const errors: Errors = {};
    for (const field of ['value', 'value_bn', 'slug', 'color_hex'] as const) {
        const message = error.fieldError(field);
        if (message) errors[field] = message;
    }
    return errors;
}

/**
 * Inline editor for an attribute's values: edit in place, reorder with up/down
 * buttons, delete (refused by the API with a 409 while a variant uses the value),
 * and a colour picker for colour attributes.
 */
export function ValuesEditor({ attribute }: { attribute: Attribute }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const mutations = useValueMutations(attribute.id);
    const [draft, setDraft] = useState<Draft>(emptyDraft);
    const [draftErrors, setDraftErrors] = useState<Errors>({});
    const isColor = attribute.type === 'color';
    const values = attribute.values;

    const toastError = (error: unknown) => notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');

    const add = () => {
        const input = toInput(draft, isColor);
        const errors = validate(input);
        setDraftErrors(errors);
        if (Object.keys(errors).length > 0) return;
        mutations.add.mutate(input, {
            onSuccess: () => {
                setDraft(emptyDraft);
                notify(t('attributes.values.added'));
            },
            onError: (error) => {
                const fieldErrors = serverErrors(error);
                if (fieldErrors) setDraftErrors(fieldErrors);
                else toastError(error);
            },
        });
    };

    const reorder = (index: number, offset: -1 | 1) => {
        const ids = values.map((value) => value.id);
        const [moved] = ids.splice(index, 1);
        ids.splice(index + offset, 0, moved!);
        mutations.reorder.mutate(ids, { onError: toastError });
    };

    return (
        <section aria-labelledby="values-heading" className="space-y-4 rounded-md border border-line-default bg-surface p-5 shadow-e1 md:p-6">
            <div>
                <h2 id="values-heading" className="t-admin-section text-fg-primary">
                    {t('attributes.values.title')}
                </h2>
                <p className="t-admin-caption text-fg-tertiary">{t('attributes.values.hint')}</p>
            </div>

            {values.length === 0 ? (
                <p className="t-admin-body text-fg-secondary">{t('attributes.values.empty')}</p>
            ) : (
                <ol className="divide-y divide-line-default rounded-sm border border-line-default">
                    {values.map((value, index) => (
                        <ValueRow
                            key={`${value.id}:${value.value}:${value.value_bn}:${value.slug}:${value.color_hex}`}
                            value={value}
                            isColor={isColor}
                            first={index === 0}
                            last={index === values.length - 1}
                            busy={mutations.reorder.isPending || mutations.remove.isPending}
                            canEdit={can(me, 'catalog.update')}
                            canDelete={can(me, 'catalog.archive')}
                            onMove={(offset) => reorder(index, offset)}
                            onSave={(input, setErrors) =>
                                mutations.update.mutate(
                                    { id: value.id, ...input },
                                    {
                                        onSuccess: () => notify(t('attributes.values.saved')),
                                        onError: (error) => {
                                            const fieldErrors = serverErrors(error);
                                            if (fieldErrors) setErrors(fieldErrors);
                                            else toastError(error);
                                        },
                                    },
                                )
                            }
                            onDelete={() => mutations.remove.mutate(value.id, { onSuccess: () => notify(t('attributes.values.deleted')), onError: toastError })}
                        />
                    ))}
                </ol>
            )}

            {can(me, 'catalog.create') && (
                <div className="space-y-2 border-t border-line-default pt-4">
                    <ValueFields draft={draft} errors={draftErrors} isColor={isColor} onChange={setDraft} idPrefix="new-value" />
                    <Button variant="secondary" size="sm" onClick={add} disabled={mutations.add.isPending}>
                        <PlusIcon size={16} aria-hidden />
                        {t('attributes.values.add')}
                    </Button>
                </div>
            )}
        </section>
    );
}

type RowProps = {
    value: AttributeValue;
    isColor: boolean;
    first: boolean;
    last: boolean;
    busy: boolean;
    canEdit: boolean;
    canDelete: boolean;
    onMove: (offset: -1 | 1) => void;
    onSave: (input: ValueInput, setErrors: (errors: Errors) => void) => void;
    onDelete: () => void;
};

function ValueRow({ value, isColor, first, last, busy, canEdit, canDelete, onMove, onSave, onDelete }: RowProps) {
    const { t } = useI18n();
    const [draft, setDraft] = useState<Draft>(() => toDraft(value));
    const [errors, setErrors] = useState<Errors>({});
    const dirty = JSON.stringify(draft) !== JSON.stringify(toDraft(value));
    const iconButton = 'inline-flex size-control-sm items-center justify-center rounded-sm text-fg-secondary hover:bg-subtle hover:text-fg-primary disabled:cursor-not-allowed disabled:opacity-40';

    const save = () => {
        const input = toInput(draft, isColor);
        const found = validate(input);
        setErrors(found);
        if (Object.keys(found).length === 0) onSave(input, setErrors);
    };

    return (
        <li className="flex flex-wrap items-start gap-3 p-3">
            {isColor && <Swatch hex={value.color_hex} label={t('attributes.values.swatch', { name: value.value })} />}
            <div className="min-w-0 flex-1">
                <ValueFields draft={draft} errors={errors} isColor={isColor} onChange={setDraft} idPrefix={`value-${value.id}`} disabled={!canEdit} />
            </div>
            <div className="flex items-center gap-0.5">
                {canEdit && (
                    <>
                        <button type="button" className={iconButton} aria-label={t('attributes.values.saveRow', { name: value.value })} title={t('attributes.values.saveRow', { name: value.value })} disabled={!dirty} onClick={save}>
                            <FloppyDiskIcon size={16} aria-hidden />
                        </button>
                        <button type="button" className={iconButton} aria-label={t('attributes.values.moveUp', { name: value.value })} title={t('attributes.values.moveUp', { name: value.value })} disabled={first || busy} onClick={() => onMove(-1)}>
                            <ArrowUpIcon size={16} aria-hidden />
                        </button>
                        <button type="button" className={iconButton} aria-label={t('attributes.values.moveDown', { name: value.value })} title={t('attributes.values.moveDown', { name: value.value })} disabled={last || busy} onClick={() => onMove(1)}>
                            <ArrowDownIcon size={16} aria-hidden />
                        </button>
                    </>
                )}
                {canDelete && (
                    <button type="button" className={`${iconButton} hover:text-feedback-danger`} aria-label={t('attributes.values.delete', { name: value.value })} title={t('attributes.values.delete', { name: value.value })} disabled={busy} onClick={onDelete}>
                        <TrashIcon size={16} aria-hidden />
                    </button>
                )}
            </div>
        </li>
    );
}

type FieldsProps = { draft: Draft; errors: Errors; isColor: boolean; onChange: (draft: Draft) => void; idPrefix: string; disabled?: boolean };

function ValueFields({ draft, errors, isColor, onChange, idPrefix, disabled }: FieldsProps) {
    const { t } = useI18n();
    const set = (field: keyof Draft) => (event: { target: { value: string } }) => onChange({ ...draft, [field]: event.target.value });
    const cells: { field: keyof Draft; label: string; lang?: string; mono?: boolean }[] = [
        { field: 'value', label: t('attributes.values.value') },
        { field: 'value_bn', label: t('attributes.values.valueBn'), lang: 'bn' },
        { field: 'slug', label: t('attributes.values.slug'), mono: true },
    ];

    return (
        <div className={`grid gap-2 ${isColor ? 'md:grid-cols-4' : 'md:grid-cols-3'}`}>
            {cells.map(({ field, label, lang, mono }) => (
                <div key={field} className="space-y-1">
                    <label htmlFor={`${idPrefix}-${field}`} className="t-admin-caption-strong text-fg-secondary">
                        {label}
                    </label>
                    <input
                        id={`${idPrefix}-${field}`}
                        lang={lang}
                        value={draft[field]}
                        disabled={disabled}
                        onChange={set(field)}
                        aria-invalid={errors[field] ? true : undefined}
                        aria-describedby={errors[field] ? `${idPrefix}-${field}-error` : undefined}
                        className={`h-control-sm ${mono ? 't-admin-mono' : ''} ${inputClass}`}
                    />
                    {errors[field] && (
                        <p id={`${idPrefix}-${field}-error`} className="t-admin-caption-strong text-feedback-danger">
                            {errors[field]}
                        </p>
                    )}
                </div>
            ))}
            {isColor && (
                <div className="space-y-1">
                    <label htmlFor={`${idPrefix}-color_hex`} className="t-admin-caption-strong text-fg-secondary">
                        {t('attributes.values.colorHex')}
                    </label>
                    <div className="flex items-center gap-2">
                        <input
                            type="color"
                            aria-label={t('attributes.values.color')}
                            value={/^#[0-9A-Fa-f]{6}$/.test(draft.color_hex) ? draft.color_hex.toLowerCase() : ''}
                            disabled={disabled}
                            onChange={(event) => onChange({ ...draft, color_hex: event.target.value.toUpperCase() })}
                            className="h-control-sm w-control-md shrink-0 cursor-pointer rounded-sm border border-line-strong bg-surface"
                        />
                        <input
                            id={`${idPrefix}-color_hex`}
                            value={draft.color_hex}
                            disabled={disabled}
                            spellCheck={false}
                            onChange={set('color_hex')}
                            aria-invalid={errors.color_hex ? true : undefined}
                            aria-describedby={errors.color_hex ? `${idPrefix}-color_hex-error` : undefined}
                            className={`h-control-sm t-admin-mono ${inputClass}`}
                        />
                    </div>
                    {errors.color_hex && (
                        <p id={`${idPrefix}-color_hex-error`} className="t-admin-caption-strong text-feedback-danger">
                            {errors.color_hex}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
