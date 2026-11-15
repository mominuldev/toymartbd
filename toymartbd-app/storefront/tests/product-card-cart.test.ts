import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import { ProductCard } from '@/components/listing/ProductCard';
import { getMessages } from '@/lib/i18n';
import type { ProductCard as Card } from '@/lib/listing/response';

// The product card's cart button (it rendered disabled before): a single-variant product posts
// straight to the cart route (no JavaScript needed), a product with options links to its page to
// choose, and a sold-out card shows "Notify me" instead.

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
    default_variant_id: 41,
    in_stock: true,
    image: null,
    flash_sale: null,
    ...over,
});

const render = (product: Card, locale: 'en' | 'bn' = 'en') => renderToStaticMarkup(createElement(ProductCard, { product, locale, t: getMessages(locale).listing }));

describe('product card cart button', () => {
    it('adds a single-variant product to the cart with a form that works without JavaScript', () => {
        const html = render(card());
        expect(html).toContain('action="/api/cart/items"');
        expect(html).toContain('name="variant_id" value="41"');
        expect(html).toContain('name="quantity" value="1"');
        expect(html).toContain('name="locale" value="en"');
        expect(html).toMatch(/<button type="submit" aria-label="Add to cart: Rubik(&#x27;|')s Cube"/);
        expect(html).not.toContain('disabled');
    });

    it('sends a product with options to its page to choose, in the page language', () => {
        const html = render(card({ variant_count: 3, default_variant_id: null }), 'bn');
        expect(html).not.toContain('/api/cart/items');
        expect(html).toContain('aria-label="রুবিকস কিউব-এর অপশন বেছে নিন"');
        expect(html).toContain('href="/bn/products/rubiks-cube"');
    });

    it('shows Notify me, not a cart button, when sold out', () => {
        const html = render(card({ in_stock: false }));
        expect(html).not.toContain('/api/cart/items');
        expect(html).toContain('Notify me');
    });
});
