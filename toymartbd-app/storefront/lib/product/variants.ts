import type { Schema } from '@toymart/api-types';

// Variant selection for the product page (VariantSelector, storefront spec §A10; Figma 117:2519).
// Pure and server-safe: the page renders every state from the URL (`?variant=`), so selection is
// shareable and works with JavaScript off. No stock or price is decided here — the API sends each
// variant's `stock_state`, `available`, price and discount (rule 1); this only maps them onto axes.

export type StorefrontProduct = Schema<'StorefrontProduct'>;
export type ProductVariant = StorefrontProduct['variants'][number];
export type ProductAxis = StorefrontProduct['attributes'][number];
export type AxisValue = ProductAxis['values'][number];

/**
 * Figma VariantOption states. `Unavailable` = sold out in every combination; `OtherAxis` = valid
 * value, but out of stock or impossible with the current choice on the other axes (spec
 * "Disabled-by-other-axis", same look as Unavailable).
 */
export type OptionState = 'Selected' | 'Available' | 'LowStock' | 'Unavailable' | 'OtherAxis';

/** Why an option cannot be chosen — the words come from the i18n layer. */
export type OptionReason =
    | { kind: 'soldOut'; value: AxisValue }
    | { kind: 'comboSoldOut'; value: AxisValue; others: AxisValue[] }
    | { kind: 'impossible'; value: AxisValue; others: AxisValue[] }
    | { kind: 'lowStock'; value: AxisValue; count: number };

export interface AxisOption {
    value: AxisValue;
    state: OptionState;
    /** The variant this option switches to — null when it cannot be chosen or is already chosen. */
    variantId: number | null;
    reason: OptionReason | null;
}

export interface AxisView {
    axis: ProductAxis;
    /** The selected variant's value on this axis. */
    selected: AxisValue | null;
    options: AxisOption[];
}

export const VARIANT_PARAM = 'variant';

/**
 * The selected variant: `?variant=<id>` when it names one of this product's variants, otherwise
 * the default, otherwise the first. Null only for a product with no variants (the API never sends
 * one). Garbage in the parameter is ignored rather than a 404, so an old shared link still opens.
 */
export function resolveVariant(product: Pick<StorefrontProduct, 'variants' | 'default_variant_id'>, param: string | string[] | undefined): ProductVariant | null {
    const raw = Array.isArray(param) ? param[0] : param;
    if (raw !== undefined && /^\d{1,10}$/.test(raw)) {
        const found = product.variants.find((v) => v.id === Number(raw));
        if (found) return found;
    }
    return product.variants.find((v) => v.id === product.default_variant_id) ?? product.variants[0] ?? null;
}

/** True when the URL's `?variant=` named a real variant (the page then links the toggle and bar to it). */
export function isExplicitVariant(product: Pick<StorefrontProduct, 'variants'>, param: string | string[] | undefined): boolean {
    const raw = Array.isArray(param) ? param[0] : param;
    return raw !== undefined && product.variants.some((v) => String(v.id) === raw);
}

function valueOn(variant: ProductVariant, axis: ProductAxis): AxisValue | null {
    return axis.values.find((value) => variant.attribute_value_ids.includes(value.id)) ?? null;
}

/** The variant whose values are exactly `ids` (any order), if one exists. */
function variantWith(variants: ProductVariant[], ids: number[]): ProductVariant | undefined {
    return variants.find((v) => v.attribute_value_ids.length === ids.length && ids.every((id) => v.attribute_value_ids.includes(id)));
}

const sellable = (v: ProductVariant): boolean => v.stock_state !== 'out_of_stock';

/**
 * Every axis with each option's state, the variant it leads to, and its reason. An option keeps
 * the other axes' current values; it is never silently re-mapped to a different combination,
 * because that would change a choice the parent already made.
 */
export function axisViews(product: Pick<StorefrontProduct, 'attributes' | 'variants'>, selected: ProductVariant): AxisView[] {
    return product.attributes.map((axis) => {
        const current = valueOn(selected, axis);
        const otherIds = selected.attribute_value_ids.filter((id) => !axis.values.some((v) => v.id === id));
        const others = product.attributes
            .filter((a) => a.id !== axis.id)
            .map((a) => valueOn(selected, a))
            .filter((v): v is AxisValue => v !== null);

        const options = axis.values.map((value): AxisOption => {
            if (current?.id === value.id) {
                return { value, state: 'Selected', variantId: null, reason: null };
            }
            const withValue = product.variants.filter((v) => v.attribute_value_ids.includes(value.id));
            if (!withValue.some(sellable)) {
                return { value, state: 'Unavailable', variantId: null, reason: { kind: 'soldOut', value } };
            }
            const target = variantWith(product.variants, [...otherIds, value.id]);
            if (!target) {
                return { value, state: 'OtherAxis', variantId: null, reason: { kind: 'impossible', value, others } };
            }
            if (!sellable(target)) {
                return { value, state: 'OtherAxis', variantId: null, reason: { kind: 'comboSoldOut', value, others } };
            }
            if (target.stock_state === 'low_stock') {
                return { value, state: 'LowStock', variantId: target.id, reason: { kind: 'lowStock', value, count: target.available } };
            }
            return { value, state: 'Available', variantId: target.id, reason: null };
        });

        return { axis, selected: current, options };
    });
}

/** `?variant=` link for an option, keeping nothing else from the query (the page has no other state). */
export function variantHref(path: string, variantId: number): string {
    return `${path}?${VARIANT_PARAM}=${variantId}`;
}

/** "Red · 484 pcs" — the selected values in axis order, localised (AddToCartBar summary). */
export function variantSummary(product: Pick<StorefrontProduct, 'attributes'>, variant: ProductVariant, locale: 'en' | 'bn'): string {
    return product.attributes
        .map((axis) => valueOn(variant, axis))
        .filter((v): v is AxisValue => v !== null)
        .map((v) => (locale === 'bn' && v.value_bn ? v.value_bn : v.value))
        .join(' · ');
}
