import { useState } from 'react';
import { useQueries } from '@tanstack/react-query';
import { ArrowDownIcon, ArrowUpIcon, PlusIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { api } from '@/lib/api';
import { IconButton } from '@/components/ui/IconButton';
import { SearchField } from '@/components/ui/SearchField';
import { flatten, useCategoryTree } from '@/features/catalog/categories/api';
import { useBrandOptions, useProductSearch, type Product } from '@/features/catalog/products/api';
import type { FieldCtx } from './fields';
import { moved } from './fields';

/*
 * Pickers for the block settings: categories and brands by checkbox (pick order is display
 * order), products by search with an ordered picked list. Archived records stay pickable here;
 * the storefront skips them (the API's guardrails), so a pick never breaks the page.
 */

function PickedList({ items, onChange, ctx, path }: { items: Array<{ id: number; name: string }>; onChange: (ids: number[]) => void; ctx: FieldCtx; path: string }) {
    const { t } = useI18n();
    const ids = items.map((i) => i.id);
    if (items.length === 0) return <p className="t-admin-caption text-fg-tertiary">{t('homeBuilder.f.noPicks')}</p>;
    return (
        <ol className="flex flex-col gap-1">
            {items.map((item, i) => (
                <li key={item.id} className="flex items-center gap-1 rounded-xs bg-subtle py-1 ps-2 pe-1">
                    <span className="min-w-0 flex-1 truncate t-admin-body text-fg-primary">
                        {i + 1}. {item.name}
                    </span>
                    {ctx.errors[`content.${path}.${i}`] && <span className="sr-only">{ctx.errors[`content.${path}.${i}`]}</span>}
                    <IconButton size="sm" aria-label={t('homeBuilder.f.moveUp', { name: item.name })} disabled={ctx.disabled || i === 0} onClick={() => onChange(moved(ids, i, i - 1))}>
                        <ArrowUpIcon size={14} aria-hidden />
                    </IconButton>
                    <IconButton size="sm" aria-label={t('homeBuilder.f.moveDown', { name: item.name })} disabled={ctx.disabled || i === items.length - 1} onClick={() => onChange(moved(ids, i, i + 1))}>
                        <ArrowDownIcon size={14} aria-hidden />
                    </IconButton>
                    <IconButton size="sm" aria-label={t('homeBuilder.f.remove', { name: item.name })} disabled={ctx.disabled} onClick={() => onChange(ids.filter((id) => id !== item.id))}>
                        <XIcon size={14} aria-hidden />
                    </IconButton>
                </li>
            ))}
        </ol>
    );
}

function CheckList({ legend, options, picked, onChange, ctx, path }: { legend: string; options: Array<{ id: number; name: string; depth?: number }>; picked: number[]; onChange: (ids: number[]) => void; ctx: FieldCtx; path: string }) {
    const error = ctx.errors[`content.${path}`];
    return (
        <fieldset className="flex flex-col gap-1.5">
            <legend className="t-admin-body-strong text-fg-primary">{legend}</legend>
            <ul className="flex max-h-56 flex-col gap-1 overflow-y-auto rounded-sm border border-line-default p-2">
                {options.map((o) => {
                    const at = picked.indexOf(o.id);
                    return (
                        <li key={o.id} style={{ paddingInlineStart: `${(o.depth ?? 0) * 0.75}rem` }}>
                            <label className="flex items-center gap-2 t-admin-body text-fg-primary">
                                <input
                                    type="checkbox"
                                    className="size-4 accent-brand"
                                    disabled={ctx.disabled}
                                    checked={at >= 0}
                                    onChange={(e) => onChange(e.target.checked ? [...picked, o.id] : picked.filter((id) => id !== o.id))}
                                />
                                <span className="min-w-0 flex-1 truncate">{o.name}</span>
                                {at >= 0 && <span className="t-admin-caption-strong text-fg-tertiary">{at + 1}</span>}
                            </label>
                        </li>
                    );
                })}
            </ul>
            {error && <p className="t-admin-caption text-fg-primary">{error}</p>}
        </fieldset>
    );
}

export function CategoryPicker({ picked, onChange, ctx, path, legend }: { picked: number[]; onChange: (ids: number[]) => void; ctx: FieldCtx; path: string; legend: string }) {
    const { locale } = useI18n();
    const tree = useCategoryTree();
    const options = flatten(tree.data ?? []).map(({ node, depth }) => ({ id: node.id, name: (locale === 'bn' && node.name_bn) || node.name, depth }));
    return <CheckList legend={legend} options={options} picked={picked} onChange={onChange} ctx={ctx} path={path} />;
}

/** Flat category options for a single select. */
export function useCategoryOptions(): Array<{ value: number; label: string }> {
    const { locale } = useI18n();
    const tree = useCategoryTree();
    return flatten(tree.data ?? []).map(({ node, depth }) => ({ value: node.id, label: `${'— '.repeat(depth)}${(locale === 'bn' && node.name_bn) || node.name}` }));
}

export function useBrandSelectOptions(): Array<{ value: number; label: string }> {
    const { locale } = useI18n();
    const brands = useBrandOptions();
    return (brands.data ?? []).map((b) => ({ value: b.id, label: (locale === 'bn' && b.name_bn) || b.name }));
}

export function BrandPicker({ picked, onChange, ctx, path, legend }: { picked: number[]; onChange: (ids: number[]) => void; ctx: FieldCtx; path: string; legend: string }) {
    const options = useBrandSelectOptions().map((o) => ({ id: o.value, name: o.label }));
    return <CheckList legend={legend} options={options} picked={picked} onChange={onChange} ctx={ctx} path={path} />;
}

export function ProductPicker({ picked, onChange, ctx, path }: { picked: number[]; onChange: (ids: number[]) => void; ctx: FieldCtx; path: string }) {
    const { t, locale } = useI18n();
    const [term, setTerm] = useState('');
    const results = useProductSearch(term.trim());
    const details = useQueries({
        queries: picked.map((id) => ({ queryKey: ['products', 'one', id], queryFn: (): Promise<Product> => api(`/products/${id}`), staleTime: 5 * 60 * 1000 })),
    });
    const nameOf = (p: { name: string; name_bn: string | null }) => (locale === 'bn' && p.name_bn) || p.name;
    const items = picked.map((id, i) => {
        const product = details[i]?.data;
        return { id, name: product ? nameOf(product) : `#${id}` };
    });

    return (
        <div className="flex flex-col gap-2">
            <SearchField label={t('homeBuilder.f.searchProducts')} placeholder={t('homeBuilder.f.searchProducts')} value={term} onChange={(e) => setTerm(e.target.value)} disabled={ctx.disabled} />
            {(results.data ?? []).length > 0 && (
                <ul className="flex max-h-48 flex-col gap-1 overflow-y-auto rounded-sm border border-line-default p-1">
                    {(results.data ?? []).filter((p) => !picked.includes(p.id)).slice(0, 8).map((p) => (
                        <li key={p.id}>
                            <button
                                type="button"
                                disabled={ctx.disabled || picked.length >= 24}
                                onClick={() => onChange([...picked, p.id])}
                                aria-label={t('homeBuilder.f.add', { name: nameOf(p) })}
                                className="flex w-full items-center gap-2 rounded-xs px-2 py-1.5 text-start t-admin-body text-fg-primary hover:bg-subtle disabled:text-fg-disabled"
                            >
                                <PlusIcon size={14} aria-hidden />
                                <span className="min-w-0 flex-1 truncate">{nameOf(p)}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            <p className="t-admin-body-strong text-fg-primary">{t('homeBuilder.f.pickedProducts')}</p>
            <PickedList items={items} onChange={onChange} ctx={ctx} path={path} />
            {ctx.errors[`content.${path}`] && <p className="t-admin-caption text-fg-primary">{ctx.errors[`content.${path}`]}</p>}
        </div>
    );
}
