import type { UseQueryResult } from '@tanstack/react-query';
import { ImageIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import type { PosCatalogItem } from './api';
import { takaText } from './format';

/**
 * The tile grid (Figma B · 33): photo, name, price and "N here" — stock AVAILABLE at this
 * drawer's branch. A tile with none here is dimmed, its count in the danger tint with the
 * words saying so (never colour alone), and cannot be added.
 */
export function ProductGrid({ query, onAdd }: { query: UseQueryResult<{ data: PosCatalogItem[] }>; onAdd: (variantId: number, name: string) => void }) {
    const { t, locale } = useI18n();

    if (query.isError) return <QueryError error={query.error} onRetry={() => void query.refetch()} />;
    const items = query.data?.data;
    if (!items) return <p className="t-admin-body text-fg-secondary">{t('pos.loading')}</p>;
    if (items.length === 0) return <EmptyState reason="notFound" title={t('pos.grid.empty')} />;

    return (
        <ul className="grid grid-cols-[repeat(auto-fill,minmax(9.5rem,1fr))] gap-3">
            {items.map((item) => {
                const name = locale === 'bn' && item.name_bn ? item.name_bn : item.name;
                const label = item.variant_name ? `${name} · ${item.variant_name}` : name;
                const none = item.available <= 0;
                return (
                    <li key={item.variant_id}>
                        <button
                            type="button"
                            disabled={none}
                            onClick={() => onAdd(item.variant_id, label)}
                            aria-label={t('pos.tile.add', { name: label, price: takaText(item.price), count: item.available })}
                            className="flex w-full flex-col gap-1.5 rounded-md border border-line-default bg-surface p-2 text-start shadow-e1 transition-colors hover:border-line-focus disabled:cursor-not-allowed disabled:shadow-none"
                        >
                            <span className={`grid aspect-[4/3] w-full place-items-center overflow-hidden rounded-sm bg-subtle ${none ? 'opacity-50' : ''}`}>
                                {item.image_url ? <img src={item.image_url} alt="" className="size-full object-cover" loading="lazy" /> : <ImageIcon size={28} aria-hidden className="text-fg-tertiary" />}
                            </span>
                            <span className={`line-clamp-1 t-admin-body ${none ? 'text-fg-tertiary' : 'text-fg-primary'}`}>{label}</span>
                            <span className="flex items-baseline justify-between gap-2">
                                <span className={`t-admin-body-strong ${none ? 'text-fg-tertiary' : 'text-fg-primary'}`}>{takaText(item.price)}</span>
                                <span className={`t-admin-caption ${none ? 'text-feedback-danger' : 'text-fg-secondary'}`}>{t('pos.tile.here', { count: item.available })}</span>
                            </span>
                        </button>
                    </li>
                );
            })}
        </ul>
    );
}
