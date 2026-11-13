import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { CartSummary } from '@/components/cart/CartSummary';
import { CountdownTimer } from '@/components/flash-sale/CountdownTimer';
import { ProductCard } from '@/components/listing/ProductCard';
import { PriceBlock } from '@/components/product/ProductSummary';
import type { Cart } from '@/lib/cart/types';
import { normalizeFlashSales } from '@/lib/flash-sale/fetch';
import { announcementCrossed, clock, countdownState, dhakaDateTime, dhakaShort, remaining, tiles, variantFlash } from '@/lib/flash-sale/format';
import { getMessages } from '@/lib/i18n';
import type { ProductCard as Card } from '@/lib/listing/response';
import type { ProductVariant } from '@/lib/product/variants';

// P7 flash sales: countdown maths and states, the server render (end time without JS, reserved
// box), the card / PDP badge and prices (the API's strings only, rule 1), the cart's promotion
// rows, and the abandoned-cart restore Route Handler.

const END = '2026-11-14T17:59:00Z'; // 23:59 in Dhaka (UTC+6)

describe('countdown maths', () => {
    it('splits the time left and never goes negative', () => {
        const r = remaining(END, Date.parse('2026-11-12T15:44:51Z'));
        expect(r).toMatchObject({ days: 2, hours: 2, minutes: 14, seconds: 9 });
        expect(clock(r!)).toBe('50:14:09');
        expect(tiles(r!)).toEqual(['02', '02', '14', '09']);
        expect(remaining(END, Date.parse('2026-11-15T00:00:00Z'))?.totalMs).toBe(0);
        expect(remaining('not a date', 0)).toBeNull();
    });

    it('is Normal above an hour, Urgent below, Ended at zero', () => {
        expect(countdownState(remaining(END, Date.parse(END) - 3_600_000))).toBe('normal');
        expect(countdownState(remaining(END, Date.parse(END) - 3_599_000))).toBe('urgent');
        expect(countdownState(remaining(END, Date.parse(END)))).toBe('ended');
        expect(countdownState(null)).toBe('ended');
    });

    it('announces once at 60, 10 and 1 minute left', () => {
        expect(announcementCrossed(3_601_000, 3_600_000)).toBe(60);
        expect(announcementCrossed(601_000, 599_000)).toBe(10);
        expect(announcementCrossed(61_000, 60_000)).toBe(1);
        expect(announcementCrossed(599_000, 598_000)).toBeNull();
        expect(announcementCrossed(1000, 0)).toBeNull();
    });

    it('formats the end time in Dhaka with Western numerals in both locales', () => {
        expect(dhakaDateTime(END)).toBe('14/11/2026, 23:59');
        expect(dhakaShort(END)).toBe('14/11 23:59');
        expect(dhakaDateTime('nope')).toBe('');
    });
});

describe('CountdownTimer server render', () => {
    const t = getMessages('bn').flashSale;

    it('shows the end time (no JS) inside a fixed reserved box', () => {
        const html = renderToStaticMarkup(createElement(CountdownTimer, { endsAt: END, size: 'sm', t }));
        expect(html).toContain('শেষ হবে 14/11/2026, 23:59');
        expect(html.toLowerCase()).toContain(`datetime="${END.toLowerCase()}"`);
        expect(html).toContain('14/11 23:59');
        expect(html).toMatch(/class="[^"]*\bh-8 w-35\b/);
        expect(html).toContain('aria-live="off"');
        expect(html).toContain('data-state="static"');
        expect(html).not.toMatch(/[০-৯]/);
    });

    it('renders "Sale ended" when the render time is past the end', () => {
        const html = renderToStaticMarkup(createElement(CountdownTimer, { endsAt: END, size: 'md', t, renderedAt: '2026-11-15T00:00:00Z' }));
        expect(html).toContain('সেল শেষ');
        expect(html).toContain('data-state="ended"');
        expect(html).toMatch(/\bw-72\b/);
    });

    it('fills the card photo width in the rail overlay', () => {
        const html = renderToStaticMarkup(createElement(CountdownTimer, { endsAt: END, size: 'xs', t, fill: true }));
        expect(html).toMatch(/\bw-full\b/);
        expect(html).toContain('14/11 23:59');
    });
});

const card = (over: Partial<Card> = {}): Card => ({
    id: 9,
    slug: 'rubiks-cube',
    name: "Rubik's Cube",
    name_bn: 'রুবিকস কিউব',
    brand: null,
    price: '1150.00',
    compare_at_price: null,
    price_max: '1150.00',
    discount_percent: null,
    age_min_months: 96,
    age_max_months: null,
    rating_avg: null,
    rating_count: 0,
    is_featured: false,
    variant_count: 1,
    in_stock: true,
    image: null,
    flash_sale: null,
    ...over,
});

const flash = { promotion_id: 3, name: 'Puja flash', name_bn: 'পূজার ফ্ল্যাশ', ends_at: END, variant_id: 40, sale_price: '890.00', regular_price: '1150.00', discount_percent: 22, remaining: 4 };

describe('flash-sale badge and prices', () => {
    it('shows the sale price over the struck regular price, the badge and the countdown on a card', () => {
        const html = renderToStaticMarkup(createElement(ProductCard, { product: card({ flash_sale: flash }), locale: 'en', t: getMessages('en').listing }));
        expect(html).toContain('Flash sale');
        expect(html).toContain('−22%');
        expect(html).toContain('৳890');
        expect(html).toMatch(/<s[^>]*>৳1,150<\/s>/);
        expect(html).toContain('role="timer"');
    });

    it('shows no flash badge without an offer, or when sold out', () => {
        expect(renderToStaticMarkup(createElement(ProductCard, { product: card(), locale: 'en', t: getMessages('en').listing }))).not.toContain('Flash sale');
        const soldOut = renderToStaticMarkup(createElement(ProductCard, { product: card({ flash_sale: flash, in_stock: false }), locale: 'en', t: getMessages('en').listing }));
        expect(soldOut).not.toContain('Flash sale');
        expect(soldOut).not.toContain('role="timer"');
    });

    it('renders the Bangla badge', () => {
        const html = renderToStaticMarkup(createElement(ProductCard, { product: card({ flash_sale: flash }), locale: 'bn', t: getMessages('bn').listing }));
        expect(html).toContain('ফ্ল্যাশ সেল');
    });

    it('takes the selected variant\'s own offer on the product page', () => {
        const productFlash = { ...flash, variants: { '40': { sale_price: '890.00', discount_percent: 22, remaining: 4 }, '41': { sale_price: '950.00', discount_percent: 17, remaining: 2 } } };
        expect(variantFlash(productFlash, 41)).toEqual({ salePrice: '950.00', discountPercent: 17, remaining: 2, endsAt: END });
        expect(variantFlash(productFlash, 42)).toBeNull();
        expect(variantFlash(null, 40)).toBeNull();

        const variant = { id: 41, price: '1150.00', compare_at_price: null, discount_percent: null, saving: null } as unknown as ProductVariant;
        const html = renderToStaticMarkup(createElement(PriceBlock, { variant, t: getMessages('en'), flash: variantFlash(productFlash, 41) }));
        expect(html).toContain('৳950');
        expect(html).toMatch(/<s[^>]*>৳1,150<\/s>/);
        expect(html).toContain('Only 2 left at this price');
    });
});

describe('cart promotion rows', () => {
    const cart = {
        token: 'x',
        item_count: 2,
        items: [],
        coupon: null,
        delivery: null,
        adjustments: [
            { type: 'subtotal', id: null, label: null, label_bn: null, amount: '2000.00' },
            { type: 'promotion', id: 3, label: 'Puja sale', label_bn: 'পূজার ছাড়', amount: '-200.00' },
            { type: 'promotion', id: 4, label: 'Free delivery over ৳1500', label_bn: '৳1500-এর বেশিতে ফ্রি ডেলিভারি', amount: '0.00' },
        ],
        totals: { subtotal: '2000.00', promotion_discount: '200.00', coupon_discount: '0.00', discount_total: '200.00', shipping_charge: null, grand_total: '1800.00' },
    } as unknown as Cart;

    it('names each promotion and shows the free-delivery row as Free', () => {
        const en = renderToStaticMarkup(createElement(CartSummary, { cart, locale: 'en', t: getMessages('en') }));
        expect(en).toContain('Promotion: Puja sale');
        expect(en).toContain('Promotion: Free delivery over ৳1500');
        expect(en).toMatch(/Promotion: Free delivery over ৳1500<\/dt><dd[^>]*>Free</);
        const bn = renderToStaticMarkup(createElement(CartSummary, { cart, locale: 'bn', t: getMessages('bn') }));
        expect(bn).toContain('অফার: পূজার ছাড়');
    });
});

describe('flash-sales response', () => {
    it('keeps valid sales and drops malformed ones', () => {
        const parsed = normalizeFlashSales({
            server_time: '2026-11-13T10:00:00Z',
            sales: [{ id: 1, name: 'Eid', name_bn: null, starts_at: null, ends_at: END, products: [card(), { bogus: true }] }, { id: 2, ends_at: 'never' }],
            next: { id: 5, name: 'Next', name_bn: 'পরের', starts_at: '2026-11-20T04:00:00Z' },
        });
        expect(parsed.sales).toHaveLength(1);
        expect(parsed.sales[0]?.products).toHaveLength(1);
        expect(parsed.next?.startsAt).toBe('2026-11-20T04:00:00Z');
        expect(normalizeFlashSales(null)).toMatchObject({ sales: [], next: null });
    });
});

describe('GET /cart/restore/{token}', () => {
    const TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWx';
    const CART_TOKEN = '2f9c8f8e-1111-4222-8333-444455556666';

    beforeEach(() => {
        vi.stubEnv('API_URL', 'http://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'server-token');
    });

    const params = (token: string, locale = 'bn') => ({ params: Promise.resolve({ locale, token }) });

    it('exchanges the token server-side, sets the httpOnly cart cookie and lands on the cart', async () => {
        const calls: Array<{ url: string; init: RequestInit }> = [];
        vi.stubGlobal('fetch', async (url: string, init: RequestInit) => {
            calls.push({ url, init });
            return new Response(JSON.stringify({ data: { token: CART_TOKEN, item_count: 2, items: [] } }), { status: 200, headers: { 'Content-Type': 'application/json' } });
        });
        const log = vi.spyOn(console, 'error');
        const { GET } = await import('@/app/[locale]/cart/restore/[token]/route');
        const res = await GET(new Request(`http://storefront.test/bn/cart/restore/${TOKEN}`), params(TOKEN));

        expect(res.status).toBe(303);
        expect(res.headers.get('location')).toBe('/bn/cart');
        expect(calls[0]?.url).toBe('http://api.test/api/v1/storefront/cart/restore');
        expect(calls[0]?.init.method).toBe('POST');
        expect(JSON.parse(String(calls[0]?.init.body))).toEqual({ token: TOKEN });
        const cookies = res.headers.getSetCookie().join('\n');
        expect(cookies).toMatch(new RegExp(`tm_cart=${CART_TOKEN};[^\\n]*HttpOnly`, 'i'));
        expect(cookies).toMatch(/tm_cart_count=2/);
        expect(log.mock.calls.flat().join(' ')).not.toContain(TOKEN);
    });

    it('lands on the cart with a notice for an expired link, without touching the cart cookie', async () => {
        vi.stubGlobal('fetch', async () => new Response(JSON.stringify({ type: 'https://toymartbd.com/problems/not-found', title: 'Not found', status: 404 }), { status: 404 }));
        const { GET } = await import('@/app/[locale]/cart/restore/[token]/route');
        const res = await GET(new Request(`http://storefront.test/cart/restore/${TOKEN}`), params(TOKEN, 'en'));
        expect(res.headers.get('location')).toBe('/cart');
        const cookies = res.headers.getSetCookie().join('\n');
        expect(cookies).not.toMatch(/tm_cart=/);
        expect(cookies).toMatch(/tm_flash=/);
    });

    it('never calls the API for a malformed token', async () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const { GET } = await import('@/app/[locale]/cart/restore/[token]/route');
        const res = await GET(new Request('http://storefront.test/cart/restore/..%2F'), params('../etc', 'en'));
        expect(fetchSpy).not.toHaveBeenCalled();
        expect(res.status).toBe(303);
    });
});
