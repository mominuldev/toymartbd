import type { UseQueryResult } from '@tanstack/react-query';
import { ImageIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import type { PosCatalogItem } from './api';
import { takaText } from './format';

/** At or below this many here, the tile says "N left" in the warning tint. */
const LOW_STOCK = 3;

/**
 * The tile grid (Figma POS/ProductTile 343:2538): photo, name, SKU, price and stock AVAILABLE
 * at this drawer's branch as a tinted pill — "N in stock", "N left" when low, "Out of stock"
 * (the words carry it, never colour alone). A tile already in the cart shows its quantity
 * badge and a brand border; one with none here is dimmed and cannot be added.
 */
export function ProductGrid({
    query,
    inCart,
    onAdd,
}: {
    query: UseQueryResult<{ data: PosCatalogItem[] }>;
    inCart: Record<number, number>;
    onAdd: (variantId: number, name: string) => void;
}) {
    const { t, locale } = useI18n();

    if (query.isError) return <QueryError error={query.error} onRetry={() => void query.refetch()} />;
    const items = query.data?.data;
    if (!items) return <p className="t-admin-body text-fg-secondary">{t('pos.loading')}</p>;
    if (items.length === 0) return <EmptyState reason="notFound" title={t('pos.grid.empty')} />;

    return (
        <ul className="grid grid-cols-[repeat(auto-fill,minmax(10rem,1fr))] gap-4">
            {items.map((item) => {
                const name = locale === 'bn' && item.name_bn ? item.name_bn : item.name;
                const label = item.variant_name ? `${name} · ${item.variant_name}` : name;
                const none = item.available <= 0;
                const low = !none && item.available <= LOW_STOCK;
                const stock = none ? t('pos.tile.out') : low ? t('pos.tile.low', { count: item.available }) : t('pos.tile.stock', { count: item.available });
                const qty = inCart[item.variant_id] ?? 0;
                const tone = none ? 'bg-danger-subtle text-feedback-danger' : low ? 'bg-warning-subtle text-feedback-warning' : 'bg-success-subtle text-feedback-success';
                return (
                    <li key={item.variant_id}>
                        <button
                            type="button"
                            disabled={none}
                            onClick={() => onAdd(item.variant_id, label)}
                            aria-label={t('pos.tile.add', {
                                name: label,
                                price: takaText(item.price),
                                stock,
                            })}
                            aria-describedby={qty > 0 ? `pos-tile-qty-${item.variant_id}` : undefined}
                            className={`flex w-full flex-col gap-1.5 rounded-md bg-surface p-2 text-start shadow-e1 transition-colors disabled:cursor-not-allowed disabled:shadow-none border ${
                                qty > 0 ? 'border-line-brand ring-1 ring-inset ring-line-brand' : 'border-line-default hover:border-line-focus'
                            }`}
                        >
                            <span className={`relative grid aspect-[4/3] w-full place-items-center overflow-hidden rounded-xs bg-image ${none ? 'opacity-50' : ''}`}>
                                {item.image_url ? (
                                    <img src={item.image_url} alt="" className="size-full object-cover" loading="lazy" />
                                ) : (
                                    <ImageIcon size={28} aria-hidden className="text-fg-tertiary" />
                                )}
                                {qty > 0 && (
                                    <span
                                        id={`pos-tile-qty-${item.variant_id}`}
                                        className="absolute end-1.5 top-1.5 grid h-5 min-w-5 place-items-center rounded-full bg-brand px-1.5 t-admin-badge text-fg-on-brand"
                                    >
                                        <span aria-hidden>{qty}</span>
                                        <span className="sr-only">
                                            {t('pos.tile.inCart', {
                                                count: qty,
                                            })}
                                        </span>
                                    </span>
                                )}
                            </span>
                            <span className={`line-clamp-1 t-admin-body ${none ? 'text-fg-tertiary' : 'text-fg-primary'}`}>{label}</span>
                            <span className="truncate t-admin-mono-sm text-fg-tertiary">{item.sku}</span>
                            <span className="flex items-center justify-between gap-2">
                                <span className={`t-admin-num ${none ? 'text-fg-tertiary' : 'text-fg-primary'}`}>{takaText(item.price)}</span>
                                <span className={`rounded-xs px-2 py-0.5 t-admin-caption-strong ${tone}`}>{stock}</span>
                            </span>
                        </button>
                    </li>
                );
            })}
        </ul>
    );
}
