import type { Schema } from '@toymart/api-types';

// The listing response (`GET /storefront/products`) as the page consumes it. Cards are the
// generated OpenAPI type; `meta.facets` is validated here because a malformed facet must cost the
// sidebar, not the page.

export type ProductCard = Schema<'StorefrontProductCard'>;

export interface FacetOption {
    value: string;
    /** Brand / category name (server-provided); bands are labelled by the storefront. */
    label: string | null;
    labelBn: string | null;
    count: number;
    selected: boolean;
    /** Price bands only: decimal-string bounds from the API. */
    min?: string;
    max?: string | null;
}

export interface Facets {
    category: FacetOption[];
    brand: FacetOption[];
    age: FacetOption[];
    price: FacetOption[];
    gender: FacetOption[];
    /** One option, `in_stock`, from the API's `availability` facet ({count, selected}). */
    availability: FacetOption[];
}

export interface Listing {
    products: ProductCard[];
    total: number;
    nextCursor: string | null;
    facets: Facets;
}

const obj = (v: unknown): Record<string, unknown> | null => (typeof v === 'object' && v !== null && !Array.isArray(v) ? (v as Record<string, unknown>) : null);
const str = (v: unknown): string | null => (typeof v === 'string' && v !== '' ? v : null);

function options(raw: unknown): FacetOption[] {
    if (!Array.isArray(raw)) return [];
    return raw.flatMap((item) => {
        const o = obj(item);
        if (!o) return [];
        const value = typeof o.value === 'number' ? String(o.value) : str(o.value);
        if (value === null || typeof o.count !== 'number') return [];
        const option: FacetOption = { value, label: str(o.label), labelBn: str(o.label_bn), count: o.count, selected: o.selected === true };
        if (typeof o.min === 'string') option.min = o.min;
        if (o.max === null || typeof o.max === 'string') option.max = o.max;
        return [option];
    });
}

function availabilityOptions(raw: unknown): FacetOption[] {
    const o = obj(raw);
    if (!o || typeof o.count !== 'number') return [];
    return [{ value: 'in_stock', label: null, labelBn: null, count: o.count, selected: o.selected === true }];
}

function isCard(v: unknown): v is ProductCard {
    const o = obj(v);
    return !!o && typeof o.id === 'number' && typeof o.slug === 'string' && typeof o.name === 'string' && typeof o.price === 'string';
}

export function normalizeListing(body: unknown): Listing {
    const root = obj(body) ?? {};
    const meta = obj(root.meta) ?? {};
    const facets = obj(meta.facets) ?? {};
    const products = Array.isArray(root.data) ? root.data.filter(isCard) : [];
    return {
        products,
        total: typeof meta.total === 'number' ? meta.total : products.length,
        nextCursor: str(meta.next_cursor),
        facets: {
            category: options(facets.category),
            brand: options(facets.brand),
            age: options(facets.age_band),
            price: options(facets.price_band),
            gender: options(facets.gender),
            availability: availabilityOptions(facets.availability),
        },
    };
}
