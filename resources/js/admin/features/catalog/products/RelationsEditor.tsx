import { useEffect, useId, useState } from 'react';
import { ImageIcon, PlusIcon, WarningCircleIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { relationTypes, useProductSearch, type RelationType } from './api';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { ProductSection } from './ProductSection';
import { formatMoney, type RelationRow } from './document';
import { TAKA } from './parts';

const typeHint: Record<RelationType, MessageKey> = {
    related: 'products.relation.relatedHint',
    upsell: 'products.relation.upsellHint',
    cross_sell: 'products.relation.cross_sellHint',
    bundle: 'products.relation.bundleHint',
    age_progression: 'products.relation.age_progressionHint',
};

/** The server's `relations.*.reason_note` limit (ProductDocumentRequest). */
const REASON_MAX = 500;

const typeLabel: Record<RelationType, MessageKey> = {
    related: 'products.relation.related',
    upsell: 'products.relation.upsell',
    cross_sell: 'products.relation.cross_sell',
    bundle: 'products.relation.bundle',
    age_progression: 'products.relation.age_progression',
};

let uid = 0;

type Props = {
    id?: string;
    productId: number | null;
    value: RelationRow[];
    onChange: (next: RelationRow[]) => void;
    errors: Record<string, string>;
    disabled: boolean;
};

/**
 * The Recommendations rail card (Figma "B · 12 Product edit" 241:37999): one list of the
 * curated targets, each with its type as the caption and a remove button, then
 * "Add product" — a type and a product search. Rule-generated rows (`is_auto`) are shown
 * read-only. Each curated row carries an editable reason note (X-09: why the pair exists,
 * staff only); the picker shows each match's thumbnail and price. The server owns every
 * guardrail and the caps (rule 25b/25c): an upsell that is not more expensive comes back as a
 * 422 on its row, in the admin's language and naming both prices — the browser never compares
 * prices itself (rule 1).
 */
export function RelationsEditor({ id, productId, value, onChange, errors, disabled }: Props) {
    const { t, locale } = useI18n();
    const typeId = useId();
    const [adding, setAdding] = useState(false);
    const [type, setType] = useState<RelationType>('cross_sell');
    const [term, setTerm] = useState('');
    const search = useProductSearch(useDebounced(term.trim()));
    const taken = new Set(value.filter((r) => r.type === type).map((r) => r.related_product_id));
    const results = term.trim().length >= 2 ? (search.data ?? []).filter((p) => p.id !== productId && !taken.has(p.id)).slice(0, 8) : [];
    const hasError = value.some((r) => errors[r.uid]);
    const hintId = useId();
    const update = (uid: string, patch: Partial<RelationRow>) => onChange(value.map((r) => (r.uid === uid ? { ...r, ...patch } : r)));

    return (
        <ProductSection id={id} title={t('products.section.recommendations')} description={t('products.section.recommendationsHint')} forceOpen={hasError}>
            {value.length === 0 && !adding && <p className="t-admin-body text-fg-secondary">{t('products.relation.empty')}</p>}

            {value.length > 0 && (
                <ul className="flex flex-col gap-3.5">
                    {value.map((row) => {
                        const name = locale === 'bn' && row.name_bn ? row.name_bn : row.name;
                        const error = errors[row.uid];
                        const errorId = `${row.uid}-error`;
                        return (
                            <li key={row.uid} className="flex flex-col gap-1.5">
                                <div className="flex items-start gap-2.5">
                                    {/* 36 px thumbnail (241:38005) — the API's 160 px rendition. */}
                                    <span className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-xs bg-subtle">
                                        {row.image_url ? <img src={row.image_url} alt="" loading="lazy" className="size-full object-cover" /> : <ImageIcon size={16} aria-hidden className="text-fg-tertiary" />}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate t-admin-body text-fg-primary" lang={locale === 'bn' && row.name_bn ? 'bn' : undefined}>
                                            {name}
                                            {row.price !== null && (
                                                <>
                                                    {' — '}
                                                    <span className="t-admin-currency-num">{TAKA}</span>
                                                    {formatMoney(row.price)}
                                                </>
                                            )}
                                        </p>
                                        <p className="t-admin-caption text-fg-tertiary">
                                            {t(typeLabel[row.type])}
                                            {row.is_auto && ` · ${t('products.relation.auto')}`}
                                        </p>
                                        {/* X-09: why the pair exists, for the next staff member. Read-only on a rule's row. */}
                                        {row.is_auto ? (
                                            row.reason_note !== '' && <p className="t-admin-caption text-fg-secondary">{row.reason_note}</p>
                                        ) : (
                                            <Input
                                                size="sm"
                                                className="mt-1.5"
                                                value={row.reason_note}
                                                maxLength={REASON_MAX}
                                                disabled={disabled}
                                                onChange={(e) => update(row.uid, { reason_note: e.target.value })}
                                                placeholder={t('products.relation.reasonPlaceholder')}
                                                aria-label={t('products.relation.reason', { name })}
                                                aria-invalid={error ? true : undefined}
                                                aria-describedby={error ? errorId : undefined}
                                            />
                                        )}
                                    </div>
                                    {!row.is_auto && (
                                        <IconButton size="sm" disabled={disabled} onClick={() => onChange(value.filter((r) => r.uid !== row.uid))} aria-label={t('products.relation.remove', { name: row.name })}>
                                            <XIcon size={16} aria-hidden />
                                        </IconButton>
                                    )}
                                </div>
                                {error && (
                                    <p id={errorId} role="alert" className="flex items-start gap-1 t-admin-caption text-fg-primary">
                                        <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                                        {error}
                                    </p>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}

            {!disabled && adding && (
                <div className="flex flex-col gap-2 rounded-sm border border-line-default p-3">
                    <label htmlFor={typeId} className="t-admin-body-strong text-fg-primary">
                        {t('products.relation.type')}
                    </label>
                    <Select id={typeId} size="sm" value={type} aria-describedby={hintId} onChange={(e) => setType(e.target.value as RelationType)}>
                        {relationTypes.map((value) => (
                            <option key={value} value={value}>
                                {t(typeLabel[value])}
                            </option>
                        ))}
                    </Select>
                    <p id={hintId} className="t-admin-caption text-fg-tertiary">
                        {t(typeHint[type])}
                    </p>
                    <SearchField
                        label={t('products.relation.search', { type: t(typeLabel[type]) })}
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
                                            onChange([
                                                ...value,
                                                {
                                                    uid: `n${++uid}`,
                                                    related_product_id: product.id,
                                                    name: product.name,
                                                    name_bn: product.name_bn,
                                                    product_status: product.status,
                                                    image_url: product.image_url,
                                                    price: product.price_min,
                                                    type,
                                                    position: String(value.filter((r) => r.type === type).length),
                                                    reason_note: '',
                                                    is_auto: false,
                                                },
                                            ]);
                                            setTerm('');
                                        }}
                                        className="flex w-full items-center gap-2 px-3 py-2 text-start hover:bg-subtle"
                                    >
                                        <span className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-xs bg-subtle">
                                            {product.image_url ? <img src={product.image_url} alt="" loading="lazy" className="size-full object-cover" /> : <ImageIcon size={14} aria-hidden className="text-fg-tertiary" />}
                                        </span>
                                        <span className="flex min-w-0 flex-1 flex-col">
                                            <span className="truncate t-admin-body text-fg-primary">{product.name}</span>
                                            {product.sku && <span className="t-admin-mono-sm text-fg-tertiary">{product.sku}</span>}
                                        </span>
                                        {product.price_min !== null && (
                                            <span className="shrink-0 t-admin-num text-fg-secondary">
                                                <span className="t-admin-currency-num">{TAKA}</span>
                                                {formatMoney(product.price_min)}
                                            </span>
                                        )}
                                        <PlusIcon size={14} aria-hidden className="shrink-0 text-fg-tertiary" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <Button variant="ghost" size="sm" className="self-end" onClick={() => setAdding(false)}>
                        {t('products.relation.done')}
                    </Button>
                </div>
            )}

            {!disabled && !adding && (
                <Button variant="ghost" size="sm" className="self-start" onClick={() => setAdding(true)}>
                    <PlusIcon size={16} aria-hidden />
                    {t('products.relation.add')}
                </Button>
            )}
        </ProductSection>
    );
}

function useDebounced<T>(value: T, ms = 250): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const timer = window.setTimeout(() => setDebounced(value), ms);
        return () => window.clearTimeout(timer);
    }, [value, ms]);
    return debounced;
}
