import { readFileSync } from 'node:fs';

import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import { ProductReviews } from '@/components/product/ProductReviews';
import { productJsonLd } from '@/lib/product/seo';
import type { StorefrontProduct } from '@/lib/product/variants';
import { validCursor, type ReviewPage } from '@/lib/reviews/fetch';
import { reviewMessagesByLocale } from '@/lib/reviews/messages';

// P4-CAT-12 — the product page's reviews section: copy, cursor guard, rendering and schema.org.

const { _related: _unused, ...raw } = JSON.parse(readFileSync(new URL('../e2e/fixtures/product-detail.json', import.meta.url), 'utf8')) as StorefrontProduct & { _related: string[] };
const product: StorefrontProduct = { ...raw, related: [] };

const page: ReviewPage = {
    data: [
        {
            id: 1,
            reviewer_name: 'নুসরাত জ.',
            rating: 5,
            title: 'খুব ভালো',
            body: 'আমার ছেলে খুব পছন্দ করেছে।',
            is_verified_purchase: true,
            locale: 'bn',
            admin_reply: 'ধন্যবাদ!',
            created_at: '2026-10-12T05:00:00Z',
        },
        { id: 2, reviewer_name: 'Tanvir A.', rating: 3, title: null, body: 'Good, but small.', is_verified_purchase: false, locale: 'en', admin_reply: null, created_at: '2026-10-13T05:00:00Z' },
    ],
    meta: { next_cursor: 'eyJpZCI6Mn0', summary: { rating_avg: '4.00', rating_count: 2, distribution: { '5': 1, '4': 0, '3': 1, '2': 0, '1': 0 } } },
};

const render = (p: ReviewPage, outcome: 'sent' | null = null) =>
    renderToStaticMarkup(createElement(ProductReviews, { page: p, productId: 7, slug: 'wooden-train', locale: 'en', m: reviewMessagesByLocale.en, moreHref: '/products/wooden-train?reviews=eyJpZCI6Mn0#reviews', outcome }));

describe('review copy', () => {
    it('has the same keys in both locales, non-empty, with Western numerals (rule 36)', () => {
        const { en, bn } = reviewMessagesByLocale;
        expect(Object.keys(bn).sort()).toEqual(Object.keys(en).sort());
        for (const value of [...Object.values(en), ...Object.values(bn)]) {
            expect(value.length).toBeGreaterThan(0);
            expect(/[০-৯]/.test(value)).toBe(false);
        }
    });
});

describe('validCursor', () => {
    it('passes an opaque Laravel cursor and refuses anything else', () => {
        expect(validCursor('eyJpZCI6Mn0')).toBe('eyJpZCI6Mn0');
        expect(validCursor('../../admin')).toBeNull();
        expect(validCursor(['a', 'b'])).toBeNull();
        expect(validCursor(undefined)).toBeNull();
    });
});

describe('ProductReviews', () => {
    it('renders the API summary, the verified badge only where earned, the shop reply and a no-JS form', () => {
        const html = render(page);
        expect(html).toContain('id="reviews"');
        expect(html).toContain('4.0');
        expect(html).toContain('from 2 reviews');
        expect(html.match(/Verified purchase/g)).toHaveLength(1);
        expect(html).toContain('ধন্যবাদ!');
        expect(html).toContain('lang="bn"');
        expect(html).toContain('action="/api/account/reviews"');
        expect(html).toContain('name="product_id" value="7"');
        expect(html).not.toContain('is_verified_purchase');
        expect(html).toContain('href="/products/wooden-train?reviews=eyJpZCI6Mn0#reviews"');
    });

    it('shows the empty state and no histogram without approved reviews', () => {
        const html = render({ data: [], meta: { next_cursor: null, summary: { rating_avg: null, rating_count: 0, distribution: { '5': 0, '4': 0, '3': 0, '2': 0, '1': 0 } } } });
        expect(html).toContain('No reviews yet');
        expect(html).not.toContain('5 stars:');
    });

    it('announces the outcome of a submitted review', () => {
        expect(render(page, 'sent')).toContain('Your review will appear after we check it.');
    });
});

describe('AggregateRating', () => {
    const graph = (p: StorefrontProduct) => JSON.stringify(productJsonLd(p, 'en', { home: 'Home' }));

    it('is present only when there are approved reviews', () => {
        expect(graph({ ...product, rating_avg: '4.50', rating_count: 12 })).toContain('"AggregateRating"');
        expect(graph({ ...product, rating_avg: null, rating_count: 0 })).not.toContain('AggregateRating');
    });
});
