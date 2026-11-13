import { useEffect, useId, useState } from 'react';
import { CheckCircleIcon, EyeSlashIcon, PlusIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { IconButton } from '@/components/ui/IconButton';
import { SearchField } from '@/components/ui/SearchField';
import { inputClass } from '@/components/ui/Field';
import { useProductSearch } from '@/features/catalog/products/api';
import type { ProductLink } from './form';

function useDebounced<T>(value: T, ms = 250): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(value), ms);
        return () => window.clearTimeout(id);
    }, [value, ms]);
    return debounced;
}

type Props = {
    label: string;
    value: ProductLink[];
    onChange: (next: ProductLink[]) => void;
    max: number;
    /** Guide picks carry a "why we picked it" note per product. */
    notes?: boolean;
    disabled?: boolean;
};

/**
 * Linked products — the editor's "product embed" row (Figma 241:46034): a bg/subtle row with a
 * 56 tile, the product name and a caption, and a "Live price" badge: the storefront renders each
 * as a product card with the catalogue's current price and stock. A product the shop would hide
 * (out of stock, archived, unpublished) shows "Hidden on the site" instead. Search reuses the
 * product picker query (2+ letters).
 */
export function ProductLinksField({ label, value, onChange, max, notes = false, disabled = false }: Props) {
    const { t, locale } = useI18n();
    const listId = useId();
    const [term, setTerm] = useState('');
    const search = useProductSearch(useDebounced(term.trim()));
    const taken = new Set(value.map((p) => p.product_id));
    const results = term.trim().length >= 2 ? (search.data ?? []).filter((p) => !taken.has(p.id)).slice(0, 8) : [];
    const full = value.length >= max;
    const patch = (index: number, next: Partial<ProductLink>) => onChange(value.map((p, i) => (i === index ? { ...p, ...next } : p)));

    return (
        <div className="flex flex-col gap-2">
            <span id={listId} className="t-admin-body-strong text-fg-primary">
                {label}
            </span>
            {value.length === 0 && <p className="t-admin-caption text-fg-tertiary">{t('blog.products.empty')}</p>}
            <ul aria-labelledby={listId} className="flex flex-col gap-2">
                {value.map((product, index) => {
                    const name = locale === 'bn' && product.name_bn ? product.name_bn : product.name;
                    return (
                        <li key={product.product_id} className="flex flex-col gap-2 rounded-sm bg-subtle p-3">
                            <div className="flex items-center gap-3">
                                <span aria-hidden className="size-14 shrink-0 rounded-xs bg-tile-mint" />
                                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <p className="truncate t-admin-body-strong text-fg-primary" lang={locale === 'bn' && product.name_bn ? 'bn' : undefined}>
                                        {name}
                                    </p>
                                    {!product.visible && <p className="t-admin-caption text-fg-tertiary">{t('blog.products.hidden')}</p>}
                                </div>
                                {product.visible ? (
                                    <span className="inline-flex shrink-0 items-center gap-1 rounded-xs border border-line-default bg-subtle px-2 py-0.75 t-admin-caption-strong text-fg-primary">
                                        <CheckCircleIcon size={14} aria-hidden className="text-feedback-success" />
                                        {t('blog.products.livePrice')}
                                    </span>
                                ) : (
                                    <EyeSlashIcon size={16} aria-hidden className="shrink-0 text-feedback-warning" />
                                )}
                                {!disabled && (
                                    <IconButton size="sm" aria-label={t('blog.products.remove', { name })} onClick={() => onChange(value.filter((_, i) => i !== index))}>
                                        <XIcon size={16} aria-hidden />
                                    </IconButton>
                                )}
                            </div>
                            {notes && (
                                <div className="grid gap-2 md:grid-cols-2">
                                    <input
                                        aria-label={`${t('blog.guide.pickNote')} — ${name}`}
                                        placeholder={t('blog.guide.pickNote')}
                                        className={`h-8 ${inputClass}`}
                                        value={product.note}
                                        disabled={disabled}
                                        onChange={(e) => patch(index, { note: e.target.value })}
                                    />
                                    <input
                                        aria-label={`${t('blog.guide.pickNoteBn')} — ${name}`}
                                        placeholder={t('blog.guide.pickNoteBn')}
                                        lang="bn"
                                        className={`h-8 ${inputClass}`}
                                        value={product.note_bn}
                                        disabled={disabled}
                                        onChange={(e) => patch(index, { note_bn: e.target.value })}
                                    />
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
            {!disabled && !full && (
                <div className="flex flex-col gap-1.5">
                    <SearchField label={t('blog.products.search')} placeholder={t('blog.products.searchPlaceholder')} value={term} onChange={(e) => setTerm(e.target.value)} />
                    {results.length > 0 && (
                        <ul aria-label={t('blog.products.results')} className="flex flex-col rounded-sm border border-line-default bg-surface-raised shadow-e3">
                            {results.map((product) => (
                                <li key={product.id}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            onChange([...value, { product_id: product.id, name: product.name, name_bn: product.name_bn, visible: product.status === 'active', note: '', note_bn: '' }]);
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
                </div>
            )}
        </div>
    );
}
