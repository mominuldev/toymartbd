/**
 * Test fixtures for the product screens (Vitest only — imported by *.test.tsx). Bangla
 * names in every fixture (rule 34).
 */
import { mockApi, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Attribute } from '../attributes/api';
import type { Category } from '../categories/api';
import type { Product, ProductListItem, ProductVariant } from './api';

export const owner: Me = {
    ...viewer,
    roles: ['super_admin'],
    permissions: ['dashboard.view', 'catalog.view', 'catalog.create', 'catalog.update', 'catalog.archive', 'catalog.update_price', 'catalog.view_cost'],
};

/** A content editor: can save copy, cannot see cost or change prices. */
export const contentEditor: Me = {
    ...viewer,
    roles: ['content_editor'],
    permissions: ['dashboard.view', 'catalog.view', 'catalog.update'],
};

const value = (id: number, attribute_id: number, v: string, bn: string, position: number) => ({ id, attribute_id, value: v, value_bn: bn, slug: v.toLowerCase(), color_hex: null, position });

export const color: Attribute = {
    id: 1,
    name: 'Color',
    name_bn: 'রং',
    slug: 'color',
    type: 'color',
    is_variant_defining: true,
    is_filterable: true,
    position: 0,
    values: [value(10, 1, 'Red', 'লাল', 0), value(11, 1, 'Blue', 'নীল', 1), value(12, 1, 'Green', 'সবুজ', 2)],
};

export const size: Attribute = {
    ...color,
    id: 2,
    name: 'Size',
    name_bn: 'সাইজ',
    slug: 'size',
    type: 'select',
    position: 1,
    values: [value(20, 2, 'Small', 'ছোট', 0), value(21, 2, 'Medium', 'মাঝারি', 1), value(22, 2, 'Large', 'বড়', 2), value(23, 2, 'Extra Large', 'অনেক বড়', 3)],
};

export const category: Category = {
    id: 5,
    parent_id: null,
    name: 'Building & Construction',
    name_bn: 'বিল্ডিং ও কনস্ট্রাকশন',
    slug: 'building',
    description: null,
    description_bn: null,
    icon: null,
    image_url: null,
    is_featured: false,
    show_in_menu: true,
    is_active: true,
    position: 0,
    depth: 0,
    created_at: '2026-11-10T18:30:00Z',
    updated_at: '2026-11-10T18:30:00Z',
    children: [],
} as Category;

export const listItem: ProductListItem = {
    id: 42,
    name: 'Brick Box 500',
    name_bn: 'ব্রিক বক্স 500',
    slug: 'brick-box-500',
    type: 'variable',
    status: 'active',
    brand: { id: 3, name: 'Toy Mart Originals' },
    primary_category_id: 5,
    sku: 'TM-00042-RED-S',
    price_min: '1250.00',
    price_max: '2400.50',
    variants_count: 4,
    image_url: null,
    is_featured: false,
    created_at: '2026-11-10T18:30:00Z',
    updated_at: '2026-11-10T18:30:00Z',
};

export function variant(id: number, valueIds: [number, number], overrides: Partial<ProductVariant> = {}): ProductVariant {
    return {
        id,
        sku: `TM-00042-${id}`,
        barcode: null,
        name: null,
        name_bn: null,
        price: '1250.00',
        compare_at_price: null,
        cost_price: '800.00',
        wholesale_price: null,
        weight_grams: 400,
        is_default: id === 100,
        position: 0,
        status: 'active',
        attribute_values: [
            { attribute_id: 1, attribute_value_id: valueIds[0] },
            { attribute_id: 2, attribute_value_id: valueIds[1] },
        ],
        ...overrides,
    };
}

/** Red/Blue × Small/Medium = 4 variants. */
export const product: Product = {
    id: 42,
    name: 'Brick Box 500',
    name_bn: 'ব্রিক বক্স 500',
    slug: 'brick-box-500',
    type: 'variable',
    status: 'active',
    brand_id: 3,
    brand: { id: 3, name: 'Toy Mart Originals', name_bn: null },
    primary_category_id: 5,
    category_ids: [5],
    categories: [{ id: 5, name: 'Building & Construction', name_bn: null, slug: 'building' }],
    short_description: null,
    short_description_bn: null,
    description: null,
    description_bn: null,
    specifications: null,
    age_min_months: 48,
    age_max_months: 96,
    gender: null,
    is_educational: true,
    safety_note: null,
    safety_note_bn: null,
    warranty_months: null,
    is_featured: false,
    is_live: true,
    sales_channels: { online_store: true, pos: true, facebook_instagram: true, google_merchant: false },
    vat_rate: '0.00',
    compliance: { bsti_certificate_no: 'CM-2026-04417', bsti_expires_on: '2027-06-30', expired: false, certificate: { name: 'bsti-cm-2026-04417.pdf', size_bytes: 421888 } },
    margin: { percent: '39.5', profit: '730.00', net_price: '1850.00' },
    margin_lowest: { percent: '39.5', variant_id: 100 },
    stock_by_location: [
        { location_id: 1, code: 'HQ', name: 'Dhaka HQ · Mirpur', name_bn: 'ঢাকা প্রধান কার্যালয় · মিরপুর', is_default: true, on_hand: 21, reserved: 2, available: 19 },
        { location_id: 2, code: 'CTG', name: 'Chattogram · Agrabad', name_bn: null, is_default: false, on_hand: 7, reserved: 0, available: 7 },
    ],
    slug_history: [],
    seo: {
        title: null,
        title_bn: null,
        description: null,
        description_bn: null,
        canonical_url: null,
        robots: 'index,follow',
        sitemap_exclude: false,
        og_title: null,
        og_description: null,
        og_image: null,
        twitter_card: 'summary_large_image',
        schema_override: null,
        focus_keyword: null,
        defaults: { title: 'Brick Box 500 | Toy Mart BD', title_bn: 'ব্রিক বক্স 500 | Toy Mart BD', description: '500 colourful bricks.', description_bn: '500টি রঙিন ব্রিক।', og_image: null },
        url: 'https://toymartbd.com/products/brick-box-500',
        measure: { title: { px: 280, chars: 27, max: 580, fits: true }, description: { px: 150, chars: 21, max: 920, fits: true, fits_mobile: true } },
        checklist: {
            passed: 6,
            total: 7,
            items: [
                { key: 'title_fits', status: 'pass', params: { px: 280, max: 580 } },
                { key: 'description_fits', status: 'pass', params: { px: 150, max: 920 } },
                { key: 'primary_image', status: 'pass', params: { width: 1200, height: 1200, min: 1200 } },
                { key: 'image_alt', status: 'warn', params: { missing_en: 0, missing_bn: 2 } },
                { key: 'slug', status: 'pass', params: { slug: 'brick-box-500' } },
                { key: 'product_schema', status: 'pass', params: {} },
                { key: 'sitemap', status: 'pass', params: { reason: '' } },
            ],
        },
        schema: { '@type': 'Product', offers: { availability: 'https://schema.org/InStock' } },
    },
    published_at: '2026-11-01T00:00:00Z',
    options: [
        { attribute_id: 1, value_ids: [10, 11] },
        { attribute_id: 2, value_ids: [20, 21] },
    ],
    variants: [variant(100, [10, 20]), variant(101, [10, 21]), variant(102, [11, 20]), variant(103, [11, 21])],
    images: [],
    relations: [],
    created_at: '2026-11-10T18:30:00Z',
    updated_at: '2026-11-10T18:30:00Z',
};

/** Strip cost from a product as the API does for staff without catalog.view_cost. */
export function withoutCost(p: Product): Product {
    const { margin: _m, margin_lowest: _l, ...rest } = p;
    return { ...rest, variants: p.variants.map(({ cost_price: _cost, margin: _margin, ...variant }) => variant) };
}

export const page = <T,>(data: T[], next: string | null = null) => ({ data, links: { next: null }, meta: { next_cursor: next } });

export const unprocessable = (errors: { field: string; message: string }[]) => ({
    status: 422,
    body: { type: 'https://toymartbd.com/problems/validation-error', title: 'Validation failed', status: 422, errors },
});

type Reply = { status: number; body?: unknown };

/** The catalog API with sensible defaults; `handler` answers first. */
export function productApi(me: Me, handler?: (url: string, init?: RequestInit) => Reply | undefined) {
    return mockApi((url, init) => {
        const custom = handler?.(url, init);
        if (custom) return custom;
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (/\/products\/\d+\/images$/.test(url)) return { status: 200, body: { data: [] } };
        if (url.endsWith('/attributes')) return { status: 200, body: { data: [color, size] } };
        if (url.includes('/categories')) return { status: 200, body: { data: [category] } };
        if (url.includes('/brands')) return { status: 200, body: page([{ id: 3, name: 'Toy Mart Originals', name_bn: null, slug: 'tmo', description: null, description_bn: null, is_active: true, logo_url: null, created_at: '', updated_at: '' }]) };
        if (/\/products\/42$/.test(url) && (!init?.method || init.method === 'GET')) return { status: 200, body: me.permissions.includes('catalog.view_cost') ? product : withoutCost(product) };
        if (url.includes('/products?') || url.endsWith('/products')) return { status: 200, body: page([listItem]) };
        if (url.endsWith('/products/seo-measure')) return { status: 200, body: product.seo.measure };
        if (url.endsWith('/products/margin-preview')) {
            const rows = (JSON.parse(String(init?.body ?? '{}')) as { rows?: unknown[] }).rows ?? [];
            return { status: 200, body: { rows: rows.map(() => ({ percent: '39.5', profit: '730.00', net_price: '1850.00' })) } };
        }
        return { status: 404 };
    });
}

export function sentBody(calls: ReturnType<typeof mockApi>, method: string): Record<string, unknown> | null {
    // The form's live previews (margin, SERP width) are POSTs too; they are not the document.
    const call = calls.mock.calls.find(([url, init]) => (init as RequestInit | undefined)?.method === method && !/\/products\/(margin-preview|seo-measure)$/.test(String(url)));
    return call ? (JSON.parse(String((call[1] as RequestInit).body)) as Record<string, unknown>) : null;
}
