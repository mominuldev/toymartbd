import type { ComponentType, ReactNode } from 'react';
import {
    ArrowLineDownIcon,
    ArrowLineUpIcon,
    ArrowUUpLeftIcon,
    ArrowsLeftRightIcon,
    BuildingsIcon,
    ImageIcon,
    PackageIcon,
    SlidersHorizontalIcon,
    TrashIcon,
    WarehouseIcon,
    type IconProps,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import type { InventoryLocation, MovementType, StockRow } from './api';

/** The taka sign — a currency symbol, not translatable copy. */
export const TAKA = '৳';

/**
 * "1250.00" → "1,250", "1050.4000" → "1,050.4". Groups and trims the server's decimal string;
 * never parses it into a float, never computes (rule 1).
 */
export function formatMoney(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const trimmed = fraction.replace(/0+$/, '');
    return `${negative ? '-' : ''}${grouped}${trimmed ? `.${trimmed}` : ''}`;
}

/** Western digits with grouping in both locales (rule 36). */
export function formatUnits(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

/** "+3" / "−2" (a real minus sign, U+2212) / "0". */
export function signed(value: number): string {
    if (value > 0) return `+${formatUnits(value)}`;
    if (value < 0) return `−${formatUnits(-value)}`;
    return '0';
}

/** A taka amount as Figma sets it: the sign in the currency face, the figures in the surrounding style. */
export function Taka({ amount, signClass = 't-admin-currency-num' }: { amount: string; signClass?: string }) {
    return (
        <>
            <span className={signClass}>{TAKA}</span>
            {formatMoney(amount)}
        </>
    );
}

/**
 * Figma StatCard 189:360 (Inventory 213:6955 stats): overline label, 26px value, caption.
 * `edge` swaps the hairline for the 3px status edge the Low stock card uses.
 */
export function StatCard({ label, value, hint, edge }: { label: string; value: ReactNode; hint: ReactNode; edge?: 'low-stock' }) {
    const frame = edge === 'low-stock' ? 'border-l-3 border-status-low-stock' : 'border border-line-default';
    return (
        <div className={`flex min-w-0 flex-col gap-1.5 overflow-hidden rounded-md bg-surface p-4 shadow-e1 ${frame}`}>
            <p className="t-admin-overline text-fg-secondary">{label}</p>
            <p className="tabular text-fg-primary">{value}</p>
            <p className="t-admin-caption text-fg-tertiary">{hint}</p>
        </div>
    );
}

/** The location cards row (Inventory 213:6955 "branches"): the selected one in brand-subtle with a 2px brand edge. */
export function LocationCards({ locations, selected, showValue, onSelect }: { locations: InventoryLocation[]; selected: number; showValue: boolean; onSelect: (id: number) => void }) {
    const { t, locale } = useI18n();
    return (
        <div role="group" aria-label={t('inventory.locations')} className="flex flex-wrap gap-4">
            {locations.map((location) => {
                const active = location.id === selected;
                const Icon = location.is_default ? WarehouseIcon : BuildingsIcon;
                return (
                    <button
                        key={location.id}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onSelect(location.id)}
                        className={`flex min-w-0 flex-1 basis-64 items-center gap-3 rounded-md px-4 py-3.5 text-start transition-colors duration-150 ease-standard ${
                            active ? 'border-2 border-brand bg-brand-subtle' : 'border border-line-default bg-surface hover:bg-subtle'
                        }`}
                    >
                        <Icon size={22} aria-hidden className="shrink-0 text-fg-secondary" />
                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span className="truncate t-admin-body-strong text-fg-primary">{locale === 'bn' ? (location.name_bn ?? location.name) : location.name}</span>
                            <span className="truncate t-admin-caption text-fg-tertiary">{location.is_default ? t('inventory.location.default') : location.code.toUpperCase()}</span>
                        </span>
                        <span className="flex shrink-0 flex-col items-end">
                            <span className="t-admin-body-strong tabular text-fg-primary">{t('inventory.location.units', { count: formatUnits(location.on_hand) })}</span>
                            {showValue && location.value !== undefined && (
                                <span className="t-admin-caption tabular text-fg-tertiary">
                                    <Taka amount={location.value} signClass="t-admin-currency-caption" />
                                </span>
                            )}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

/** Thumbnail + product · variant name + SKU (DataTable product cell). */
export function VariantCell({ row }: { row: Pick<StockRow, 'image_url' | 'product_name' | 'product_name_bn' | 'variant_name' | 'variant_name_bn' | 'sku'> }) {
    const { locale } = useI18n();
    const bn = locale === 'bn';
    const product = bn ? (row.product_name_bn ?? row.product_name) : row.product_name;
    const variant = bn ? (row.variant_name_bn ?? row.variant_name) : row.variant_name;
    return (
        <div className="flex min-w-0 items-center gap-2.5">
            {row.image_url ? (
                <img src={row.image_url} alt="" className="size-9 shrink-0 rounded-xs-plus object-cover" />
            ) : (
                <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-xs-plus bg-subtle text-fg-tertiary">
                    <ImageIcon size={16} />
                </span>
            )}
            <div className="flex min-w-0 flex-col gap-0.5">
                <p className="truncate t-admin-body-strong text-fg-primary">{variant ? `${product} / ${variant}` : product}</p>
                <p className="truncate t-admin-mono-sm text-fg-tertiary">{row.sku}</p>
            </div>
        </div>
    );
}

/** Available with its state: a 2px status edge + "low"/"out" word, never colour alone. */
export function AvailableCell({ available, state }: { available: number; state: StockRow['state'] }) {
    const { t } = useI18n();
    if (!state) return <span className="t-admin-num tabular text-fg-primary">{formatUnits(available)}</span>;
    const edge = state === 'low' ? 'border-status-low-stock' : 'border-status-out-of-stock';
    return (
        <span className={`inline-flex items-center gap-1 rounded-xs border-l-2 bg-subtle py-0.75 ps-1.5 pe-2 whitespace-nowrap ${edge}`}>
            <span className="t-admin-num tabular text-fg-primary">{formatUnits(available)}</span>
            <span className="t-admin-caption text-fg-secondary">{t(state === 'low' ? 'inventory.state.low' : 'inventory.state.out')}</span>
        </span>
    );
}

type TypeLook = { icon: ComponentType<IconProps>; tint: string };

/* Stock ledger 213:7516 type chips: icon shape + label; the feedback colour tints the 14px icon only. */
const typeLooks: Record<MovementType, TypeLook> = {
    purchase_in: { icon: ArrowLineDownIcon, tint: 'text-feedback-success' },
    sale_out: { icon: ArrowLineUpIcon, tint: 'text-feedback-info' },
    sale_cancelled_in: { icon: ArrowUUpLeftIcon, tint: 'text-fg-secondary' },
    customer_return_in: { icon: ArrowUUpLeftIcon, tint: 'text-feedback-danger' },
    courier_return_in: { icon: ArrowUUpLeftIcon, tint: 'text-feedback-danger' },
    adjustment_in: { icon: SlidersHorizontalIcon, tint: 'text-fg-secondary' },
    adjustment_out: { icon: SlidersHorizontalIcon, tint: 'text-fg-secondary' },
    damage_out: { icon: TrashIcon, tint: 'text-feedback-danger' },
    transfer_in: { icon: ArrowsLeftRightIcon, tint: 'text-fg-secondary' },
    transfer_out: { icon: ArrowsLeftRightIcon, tint: 'text-fg-secondary' },
    opening_balance: { icon: PackageIcon, tint: 'text-fg-secondary' },
};

export function movementTypeLabel(type: MovementType): MessageKey {
    return `movement.type.${type}` as MessageKey;
}

/** StatusChip 185:79 shape (bg/subtle, hairline, radius/xs, caption-strong) with the movement's own icon. */
export function MovementTypeChip({ type }: { type: MovementType }) {
    const { t } = useI18n();
    const { icon: Icon, tint } = typeLooks[type];
    return (
        <span className="inline-flex items-center gap-1 rounded-xs border border-line-default bg-subtle px-2 py-0.75 t-admin-caption-strong whitespace-nowrap text-fg-primary">
            <Icon size={14} aria-hidden className={`shrink-0 ${tint}`} />
            {t(movementTypeLabel(type))}
        </span>
    );
}
