import { useId, type ComponentType, type ReactNode } from 'react';
import {
    ArrowLineDownIcon,
    ArrowLineUpIcon,
    ArrowUUpLeftIcon,
    ArrowsLeftRightIcon,
    BuildingsIcon,
    CheckCircleIcon,
    ImageIcon,
    PackageIcon,
    SlidersHorizontalIcon,
    TrashIcon,
    WarehouseIcon,
    type IconProps,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button, type ButtonSize, type ButtonVariant } from '@/components/ui/Button';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import type { InventoryLocation, MovementType, StockRow } from './api';

/** The taka sign — a currency symbol, not translatable copy. */
export const TAKA = '৳';

/** "0995" → "0996", "999" → "1000": a decimal-string increment for display rounding, never a float. */
function increment(digits: string): string {
    const chars = digits.split('');
    for (let i = chars.length - 1; i >= 0; i--) {
        if (chars[i] !== '9') {
            chars[i] = String(Number(chars[i]) + 1);
            return chars.join('');
        }
        chars[i] = '0';
    }
    return `1${chars.join('')}`;
}

const group = (whole: string): string => whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

/**
 * "1250" → "1,250.00", "2937.7371" → "2,937.74", "-100.5" → "-100.50". Money always shows two
 * decimals (04-admin-spec: unit cost 4 dp in the tooltip, 2 dp displayed). The server's decimal
 * string is rounded half-up on its digits for display; it is never parsed into a float and no
 * figure is computed (rule 1).
 */
export function formatMoney(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    let cents = `${whole}${fraction.padEnd(2, '0').slice(0, 2)}`;
    if ((fraction[2] ?? '0') >= '5') cents = increment(cents);
    cents = cents.replace(/^0+/, '').padStart(3, '0');
    const zero = /^0+$/.test(cents);
    return `${negative && !zero ? '-' : ''}${group(cents.slice(0, -2))}.${cents.slice(-2)}`;
}

/** The server's figure in full ("2,937.7371"): the tooltip behind a rounded amount. */
export function exactMoney(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    return `${negative ? '-' : ''}${group(whole.replace(/^0+(?=\d)/, '') || '0')}.${fraction.replace(/0+$/, '').padEnd(2, '0')}`;
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

/** A Dhaka day and time ("08 Nov", "12:20 pm"), Western digits in both locales (rules 11, 36). */
export function dhakaWhen(iso: string | number, locale: string): { day: string; time: string } {
    const date = new Date(iso);
    const tag = locale === 'bn' ? 'bn-BD' : 'en-GB';
    return {
        day: new Intl.DateTimeFormat(tag, { timeZone: 'Asia/Dhaka', day: '2-digit', month: 'short', numberingSystem: 'latn' }).format(date),
        time: new Intl.DateTimeFormat(tag, { timeZone: 'Asia/Dhaka', hour: 'numeric', minute: '2-digit', hour12: true, numberingSystem: 'latn' }).format(date).toLowerCase(),
    };
}

/**
 * A taka amount as Figma sets it: the sign in the currency face, the figures in the surrounding
 * style, always two decimals. When the server's figure is more precise (a 4 dp unit cost), the
 * full figure is the tooltip.
 */
export function Taka({ amount, signClass = 't-admin-currency-num' }: { amount: string; signClass?: string }) {
    const shown = formatMoney(amount);
    const exact = exactMoney(amount);
    return (
        <span title={exact !== shown ? `${TAKA}${exact}` : undefined}>
            <span className={signClass}>{TAKA}</span>
            {shown}
        </span>
    );
}

/**
 * The location cards row (B · 14 Inventory 241:39526 "branches"): one card per active location,
 * each a filter for the whole page (stats, tabs, table). The selected one is brand-subtle with a
 * brand hairline and a check in place of its icon, so the choice never rests on colour alone.
 * Below 1024 the row scrolls sideways instead of stacking three tall cards above the stock.
 */
export function LocationCards({ locations, selected, showValue, onSelect }: { locations: InventoryLocation[]; selected: number; showValue: boolean; onSelect: (id: number) => void }) {
    const { t, locale } = useI18n();
    return (
        <div role="group" aria-label={t('inventory.locations')} className="scrollbar-none -mx-4 flex snap-x gap-3 overflow-x-auto px-4 md:-mx-5 md:px-5 lg:mx-0 lg:flex-wrap lg:gap-4 lg:overflow-visible lg:px-0">
            {locations.map((location) => {
                const active = location.id === selected;
                const Icon = location.is_default ? WarehouseIcon : BuildingsIcon;
                return (
                    <button
                        key={location.id}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onSelect(location.id)}
                        className={`flex min-w-0 shrink-0 basis-80 snap-start items-center gap-3 rounded-md border px-4 py-3 text-start transition-colors duration-150 ease-standard lg:flex-1 ${
                            active ? 'border-line-brand bg-brand-subtle ring-1 ring-inset ring-line-brand' : 'border-line-default bg-surface hover:bg-subtle'
                        }`}
                    >
                        <span aria-hidden className={`grid size-9 shrink-0 place-items-center rounded-sm ${active ? 'bg-surface text-fg-brand' : 'bg-subtle text-fg-secondary'}`}>
                            {active ? <CheckCircleIcon size={20} weight="fill" /> : <Icon size={20} />}
                        </span>
                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span className="truncate t-admin-body-strong text-fg-primary">{locale === 'bn' ? (location.name_bn ?? location.name) : location.name}</span>
                            <span className="truncate t-admin-caption text-fg-tertiary">
                                {(locale === 'bn' ? (location.description_bn ?? location.description) : location.description) ??
                                    (location.is_default ? t('inventory.location.default') : location.code.toUpperCase())}
                            </span>
                        </span>
                        <span className="flex shrink-0 flex-col items-end">
                            <span className="t-admin-num tabular text-fg-primary">{t('inventory.location.units', { count: formatUnits(location.on_hand) })}</span>
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

type VariantNames = Pick<StockRow, 'image_url' | 'product_name' | 'product_name_bn' | 'variant_name' | 'variant_name_bn' | 'sku'>;

/** "LEGO Classic Creative Bricks / 90 pcs · Box" in the current language: accessible names and tooltips. */
export function variantLabel(row: Pick<VariantNames, 'product_name' | 'product_name_bn' | 'variant_name' | 'variant_name_bn'>, locale: string): string {
    const bn = locale === 'bn';
    const product = bn ? (row.product_name_bn ?? row.product_name) : row.product_name;
    const variant = bn ? (row.variant_name_bn ?? row.variant_name) : row.variant_name;
    return variant ? `${product} / ${variant}` : product;
}

/**
 * Thumbnail + product name + the variant as its own tag + SKU (DataTable product cell). Six
 * variants of one product now read apart at a glance: the product line is the same, the tag is
 * not. The full name is the tooltip when the line is cut.
 */
export function VariantCell({ row }: { row: VariantNames }) {
    const { locale } = useI18n();
    const bn = locale === 'bn';
    const product = bn ? (row.product_name_bn ?? row.product_name) : row.product_name;
    const variant = bn ? (row.variant_name_bn ?? row.variant_name) : row.variant_name;
    return (
        <div className="flex min-w-0 items-center gap-3">
            {row.image_url ? (
                <img src={row.image_url} alt="" loading="lazy" className="size-10 shrink-0 rounded-xs-plus border border-line-default object-cover" />
            ) : (
                <span aria-hidden className="grid size-10 shrink-0 place-items-center rounded-xs-plus border border-line-default bg-subtle text-fg-tertiary">
                    <ImageIcon size={18} />
                </span>
            )}
            <div className="flex min-w-0 flex-col gap-1">
                <p className="line-clamp-2 t-admin-body-strong text-fg-primary" title={variantLabel(row, locale)}>
                    {product}
                </p>
                {/* The variant tag keeps its full width; the SKU is what gets cut. */}
                <p className="flex min-w-0 items-center gap-2">
                    {variant && (
                        <span className="max-w-full shrink-0 truncate rounded-xs border border-line-default bg-subtle px-1.5 t-admin-caption-strong text-fg-primary">{variant}</span>
                    )}
                    <span className="min-w-0 truncate t-admin-mono-sm text-fg-tertiary">{row.sku}</span>
                </p>
            </div>
        </div>
    );
}

const stockLooks: Record<'ok' | 'low' | 'out' | 'overstock', { tone: StatusTone; label: MessageKey }> = {
    ok: { tone: 'success', label: 'inventory.status.ok' },
    low: { tone: 'warning', label: 'inventory.status.low' },
    out: { tone: 'danger', label: 'inventory.status.out' },
    overstock: { tone: 'info', label: 'inventory.status.overstock' },
};

/** One status per row — In stock / Low stock / Out of stock / Overstock: icon shape + label + tint, never colour alone. The state is the server's. */
export function StockStatusChip({ state }: { state: StockRow['state'] }) {
    const { t } = useI18n();
    const look = stockLooks[state ?? 'ok'];
    return <StatusChip tone={look.tone} label={t(look.label)} />;
}

/**
 * A drawn action whose feature is not built yet (transfers, purchase orders): Figma's
 * AdminButton in its Disabled state, with the reason as a hover tooltip and as the
 * button's accessible description. It never pretends to work.
 */
export function PendingButton({ variant = 'outline', size = 'md', reason, children }: { variant?: ButtonVariant; size?: ButtonSize; reason: string; children: ReactNode }) {
    const id = useId();
    return (
        <span title={reason} className="inline-flex cursor-not-allowed">
            <Button variant={variant} size={size} disabled aria-describedby={id} className="pointer-events-none">
                {children}
            </Button>
            <span id={id} hidden>
                {reason}
            </span>
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
