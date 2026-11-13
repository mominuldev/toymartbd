import 'server-only';

import { cached } from '../api';
import type { Locale } from '../i18n/config';
import type { ProductCard } from '../listing/response';

// GET /storefront/flash-sales (P7) — running flash sales with their product cards, and the next
// scheduled one. ISR under `flash-sale` + `products`: Laravel revalidates both on every promotion
// save and at each start/end boundary (docs/api/revalidation.md), so the page flips at the
// boundary rather than at the next unrelated write.

export const flashSaleTags = ['flash-sale', 'products'];

export interface FlashSale {
    id: number;
    name: string;
    nameBn: string | null;
    startsAt: string | null;
    endsAt: string;
    products: ProductCard[];
}

export interface FlashSales {
    serverTime: string;
    sales: FlashSale[];
    next: { id: number; name: string; nameBn: string | null; startsAt: string } | null;
}

const obj = (v: unknown): Record<string, unknown> | null => (typeof v === 'object' && v !== null && !Array.isArray(v) ? (v as Record<string, unknown>) : null);
const str = (v: unknown): string | null => (typeof v === 'string' && v !== '' ? v : null);
const isCard = (v: unknown): v is ProductCard => {
    const o = obj(v);
    return o !== null && typeof o.id === 'number' && typeof o.slug === 'string' && typeof o.name === 'string' && typeof o.price === 'string';
};

/** Tolerant parse: a malformed sale is dropped, never the page. */
export function normalizeFlashSales(body: unknown): FlashSales {
    const root = obj(body) ?? {};
    const sales: FlashSale[] = [];
    for (const raw of Array.isArray(root.sales) ? root.sales : []) {
        const s = obj(raw);
        const endsAt = str(s?.ends_at);
        if (!s || typeof s.id !== 'number' || !endsAt || Number.isNaN(Date.parse(endsAt))) continue;
        sales.push({
            id: s.id,
            name: str(s.name) ?? '',
            nameBn: str(s.name_bn),
            startsAt: str(s.starts_at),
            endsAt,
            products: Array.isArray(s.products) ? s.products.filter(isCard) : [],
        });
    }
    const n = obj(root.next);
    const nextStarts = str(n?.starts_at);
    return {
        serverTime: str(root.server_time) ?? new Date().toISOString(),
        sales,
        next: n && typeof n.id === 'number' && nextStarts ? { id: n.id, name: str(n.name) ?? '', nameBn: str(n.name_bn), startsAt: nextStarts } : null,
    };
}

/** The flash-sale page's data, or null when the API cannot answer (the page shows its empty state). */
export async function getFlashSales(locale: Locale): Promise<FlashSales | null> {
    try {
        return normalizeFlashSales(await cached<unknown>('storefront/flash-sales', flashSaleTags, { locale }));
    } catch (error) {
        console.error('[flash-sale] unavailable:', error instanceof Error ? error.message : error);
        return null;
    }
}
