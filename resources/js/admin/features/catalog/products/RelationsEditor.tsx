import { useEffect, useId, useState } from 'react';
import { ImageIcon, PlusIcon, WarningCircleIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { relationTypes, useProductSearch, type RelationType } from './api';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { ProductSection } from './ProductSection';
import { formatMoney, type RelationRow } from './document';
import { TAKA } from './parts';

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
 * read-only. The server owns every guardrail and the caps (rule 25b/25c): an upsell that
 * is not more expensive comes back as a 422 on its row.
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

    return (
        <ProductSection id={id} title={t('products.section.recommendations')} description={t('products.section.recommendationsHint')} forceOpen={hasError}>
            {value.length === 0 && !adding && <p className="t-admin-body text-fg-secondary">{t('products.relation.empty')}</p>}

            {value.length > 0 && (
                <ul className="flex flex-col gap-3.5">
                    {value.map((row) => {
                        const name = locale === 'bn' && row.name_bn ? row.name_bn : row.name;
                        const error = errors[row.uid];
                        return (
                            <li key={row.uid} className="flex flex-col gap-1.5">
                                <div className="flex items-center gap-2.5">
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
                                    </div>
                                    {!row.is_auto && (
                                        <IconButton size="sm" disabled={disabled} onClick={() => onChange(value.filter((r) => r.uid !== row.uid))} aria-label={t('products.relation.remove', { name: row.name })}>
                                            <XIcon size={16} aria-hidden />
                                        </IconButton>
                                    )}
                                </div>
                                {error && (
                                    <p role="alert" className="flex items-start gap-1 t-admin-caption text-fg-primary">
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
                    <Select id={typeId} size="sm" value={type} onChange={(e) => setType(e.target.value as RelationType)}>
                        {relationTypes.map((value) => (
                            <option key={value} value={value}>
                                {t(typeLabel[value])}
                            </option>
                        ))}
                    </Select>
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
                                        <PlusIcon size={14} aria-hidden className="text-fg-tertiary" />
                                        <span className="min-w-0 flex-1 t-admin-body text-fg-primary">{product.name}</span>
                                        {product.sku && <span className="t-admin-mono-sm text-fg-tertiary">{product.sku}</span>}
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
