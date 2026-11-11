import { useEffect, useState } from 'react';
import { PlusIcon, WarningCircleIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { relationTypes, useProductSearch, type RelationType } from './api';
import { FormSection } from '@/components/ui/FormSection';
import { IconButton } from '@/components/ui/IconButton';
import { SearchField } from '@/components/ui/SearchField';
import { controlHeight, inputClass } from '@/components/ui/Field';
import { ProductStatusChip } from './parts';
import type { RelationRow } from './document';

const typeLabel: Record<RelationType, MessageKey> = {
    related: 'products.relation.related',
    upsell: 'products.relation.upsell',
    cross_sell: 'products.relation.cross_sell',
    bundle: 'products.relation.bundle',
    age_progression: 'products.relation.age_progression',
};
const typeHint: Record<RelationType, MessageKey> = {
    related: 'products.relation.relatedHint',
    upsell: 'products.relation.upsellHint',
    cross_sell: 'products.relation.cross_sellHint',
    bundle: 'products.relation.bundleHint',
    age_progression: 'products.relation.age_progressionHint',
};

let uid = 0;

/**
 * Related tab (B4 "Related"): one block per relation type, each a product search plus
 * the hand-curated list. Rule-generated rows (`is_auto`) are shown read-only. The
 * server owns every guardrail — an upsell that is not more expensive comes back as a
 * 422 on its row.
 */
export function RelationsEditor({ productId, value, onChange, errors, disabled }: { productId: number | null; value: RelationRow[]; onChange: (next: RelationRow[]) => void; errors: Record<string, string>; disabled: boolean }) {
    const { t } = useI18n();

    return (
        <div className="flex flex-col gap-6">
            {relationTypes.map((type) => (
                <RelationBlock
                    key={type}
                    type={type}
                    title={t(typeLabel[type])}
                    hint={t(typeHint[type])}
                    productId={productId}
                    rows={value.filter((r) => r.type === type)}
                    errors={errors}
                    disabled={disabled}
                    onAdd={(row) => onChange([...value, row])}
                    onPatch={(id, patch) => onChange(value.map((r) => (r.uid === id ? { ...r, ...patch } : r)))}
                    onRemove={(id) => onChange(value.filter((r) => r.uid !== id))}
                />
            ))}
        </div>
    );
}

function useDebounced<T>(value: T, ms = 250): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(value), ms);
        return () => window.clearTimeout(id);
    }, [value, ms]);
    return debounced;
}

type BlockProps = {
    type: RelationType;
    title: string;
    hint: string;
    productId: number | null;
    rows: RelationRow[];
    errors: Record<string, string>;
    disabled: boolean;
    onAdd: (row: RelationRow) => void;
    onPatch: (uid: string, patch: Partial<RelationRow>) => void;
    onRemove: (uid: string) => void;
};

function RelationBlock({ type, title, hint, productId, rows, errors, disabled, onAdd, onPatch, onRemove }: BlockProps) {
    const { t, locale } = useI18n();
    const [term, setTerm] = useState('');
    const search = useProductSearch(useDebounced(term.trim()));
    const taken = new Set(rows.map((r) => r.related_product_id));
    const results = term.trim().length >= 2 ? (search.data ?? []).filter((p) => p.id !== productId && !taken.has(p.id)).slice(0, 8) : [];

    return (
        <FormSection title={title} description={hint}>
            {rows.length > 0 && (
                <ul className="flex flex-col divide-y divide-line-default rounded-sm border border-line-default">
                    {rows.map((row) => {
                        const name = locale === 'bn' && row.name_bn ? row.name_bn : row.name;
                        const error = errors[row.uid];
                        return (
                            <li key={row.uid} className={`flex flex-col gap-2 p-3 ${error ? 'border-l-2 border-line-danger' : ''}`}>
                                <div className="flex flex-wrap items-center gap-3">
                                    <div className="min-w-0 flex-1">
                                        <p className="t-admin-body-strong text-fg-primary" lang={locale === 'bn' && row.name_bn ? 'bn' : undefined}>
                                            {name}
                                        </p>
                                        <p className="t-admin-caption text-fg-tertiary">{row.is_auto ? t('products.relation.auto') : t('products.relation.manual')}</p>
                                    </div>
                                    <ProductStatusChip status={row.product_status} />
                                    <input
                                        aria-label={t('products.relation.position', { name: row.name })}
                                        value={row.position}
                                        inputMode="numeric"
                                        disabled={disabled || row.is_auto}
                                        onChange={(e) => onPatch(row.uid, { position: e.target.value })}
                                        className={`${controlHeight.sm} ${inputClass} w-16! text-end tabular`}
                                    />
                                    {!row.is_auto && (
                                        <IconButton size="sm" disabled={disabled} onClick={() => onRemove(row.uid)} aria-label={t('products.relation.remove', { name: row.name })}>
                                            <XIcon size={16} aria-hidden />
                                        </IconButton>
                                    )}
                                </div>
                                {!row.is_auto && (
                                    <input
                                        aria-label={t('products.relation.reason', { name: row.name })}
                                        placeholder={t('products.relation.reasonPlaceholder')}
                                        value={row.reason_note}
                                        disabled={disabled}
                                        onChange={(e) => onPatch(row.uid, { reason_note: e.target.value })}
                                        className={`${controlHeight.sm} ${inputClass}`}
                                    />
                                )}
                                {error && (
                                    <p role="alert" className="flex items-center gap-1.5 t-admin-caption-strong text-fg-primary">
                                        <WarningCircleIcon size={14} weight="fill" aria-hidden className="shrink-0 text-feedback-danger" />
                                        {error}
                                    </p>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}

            {!disabled && (
                <div className="flex flex-col gap-2">
                    <SearchField
                        label={t('products.relation.search', { type: title })}
                        value={term}
                        onChange={(e) => setTerm(e.target.value)}
                        placeholder={t('products.relation.searchPlaceholder')}
                    />
                    {results.length > 0 && (
                        <ul aria-label={t('products.relation.results')} className="flex flex-col rounded-sm border border-line-default bg-surface-raised shadow-e3">
                            {results.map((product) => (
                                <li key={product.id}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            onAdd({
                                                uid: `n${++uid}`,
                                                related_product_id: product.id,
                                                name: product.name,
                                                name_bn: product.name_bn,
                                                product_status: product.status,
                                                type,
                                                position: String(rows.length),
                                                reason_note: '',
                                                is_auto: false,
                                            });
                                            setTerm('');
                                        }}
                                        className="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-subtle"
                                    >
                                        <PlusIcon size={14} aria-hidden className="text-fg-tertiary" />
                                        <span className="flex-1 t-admin-body text-fg-primary">{product.name}</span>
                                        {product.sku && <span className="t-admin-mono-sm text-fg-tertiary">{product.sku}</span>}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </FormSection>
    );
}
