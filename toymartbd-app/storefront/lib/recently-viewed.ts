import type { ProductCard } from './listing/response';
import type { StorefrontProduct } from './product/variants';

// Recently viewed (P4-STO-07, storefront spec B2.2 #18 / B2.3 #17). The browser keeps only a list
// of product slugs in localStorage; the cards — names, photos, prices — always come from the API
// through GET /api/recently-viewed, so nothing about money is ever stored or computed client-side
// (rule 1). Storage can be unavailable (private mode, blocked site data, quota): every access is
// guarded and a failure simply means an empty rail.

export const STORAGE_KEY = 'tm:recently-viewed';
/** Slugs kept in storage. */
export const MAX_STORED = 12;
/** Cards shown (and slugs the route handler accepts). */
export const MAX_SHOWN = 8;

const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

/** A clean slug list from whatever storage held: strings only, valid slugs, no repeats, capped. */
export function sanitizeSlugs(raw: unknown, limit = MAX_STORED): string[] {
    if (!Array.isArray(raw)) return [];
    const out: string[] = [];
    for (const v of raw) if (typeof v === 'string' && SLUG.test(v) && v.length <= 200 && !out.includes(v)) out.push(v);
    return out.slice(0, limit);
}

/** The list with `slug` moved to the front. */
export function remember(list: string[], slug: string): string[] {
    return sanitizeSlugs([slug, ...list.filter((s) => s !== slug)]);
}

interface StorageLike {
    getItem(key: string): string | null;
    setItem(key: string, value: string): void;
}

export function readSlugs(storage: StorageLike | null | undefined): string[] {
    try {
        const raw = storage?.getItem(STORAGE_KEY);
        return raw ? sanitizeSlugs(JSON.parse(raw)) : [];
    } catch {
        return [];
    }
}

export function writeSlugs(storage: StorageLike | null | undefined, slugs: string[]): void {
    try {
        storage?.setItem(STORAGE_KEY, JSON.stringify(sanitizeSlugs(slugs)));
    } catch {
        // Storage full or blocked: the rail is a convenience, never an error.
    }
}

/** `?slugs=a,b,c` → valid slugs, capped at MAX_SHOWN. */
export function parseSlugParam(value: string | null): string[] {
    return sanitizeSlugs((value ?? '').split(','), MAX_SHOWN);
}

/**
 * A listing card from a product page response. Every value is copied from the API — the price is
 * the default variant's, exactly as the product page shows it first; nothing is calculated.
 */
export function cardFromProduct(product: StorefrontProduct): ProductCard {
    const variant = product.variants.find((v) => v.id === product.default_variant_id) ?? product.variants.find((v) => v.is_default) ?? product.variants[0];
    const image = product.images[0] ?? null;
    return {
        id: product.id,
        slug: product.slug,
        name: product.name,
        name_bn: product.name_bn,
        brand: product.brand ? { name: product.brand.name, name_bn: product.brand.name_bn, slug: product.brand.slug } : null,
        price: variant?.price ?? '',
        compare_at_price: variant?.compare_at_price ?? null,
        price_max: variant?.price ?? '',
        discount_percent: variant?.discount_percent ?? null,
        age_min_months: product.age_min_months,
        age_max_months: product.age_max_months,
        rating_avg: product.rating_avg,
        rating_count: product.rating_count,
        is_featured: false,
        variant_count: product.variants.length,
        in_stock: product.in_stock,
        // P7: the product's cheapest flash offer, copied as the API sent it (no per-variant map on a card).
        flash_sale: product.flash_sale
            ? {
                  promotion_id: product.flash_sale.promotion_id,
                  name: product.flash_sale.name,
                  name_bn: product.flash_sale.name_bn,
                  ends_at: product.flash_sale.ends_at,
                  variant_id: product.flash_sale.variant_id,
                  sale_price: product.flash_sale.sale_price,
                  regular_price: product.flash_sale.regular_price,
                  discount_percent: product.flash_sale.discount_percent,
                  remaining: product.flash_sale.remaining,
              }
            : null,
        image: image
            ? { url: image.url, srcset_webp: image.srcset_webp, srcset_avif: image.srcset_avif, alt: image.alt, alt_bn: image.alt_bn, width: image.width, height: image.height }
            : null,
    };
}
