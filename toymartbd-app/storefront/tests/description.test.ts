import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { readFileSync } from 'node:fs';

import { describe, expect, it, vi } from 'vitest';

import { ProductInfoPanel } from '@/components/product/ProductInfoPanel';
import { getMessages } from '@/lib/i18n';
import { descriptionContext, ownMediaSrc, sanitizeDescription } from '@/lib/product/description';
import type { StorefrontProduct } from '@/lib/product/variants';

const context = descriptionContext('https://api.toymart.test/api/v1', ['https://media.toymart.test/media/ab/1200.webp']);

describe('sanitizeDescription', () => {
    it('keeps the allowlisted formatting', () => {
        const html = '<h2>Build</h2><p><strong>500</strong> <em>bricks</em><br />in a box</p><ul><li>One</li></ul><ol><li>Two</li></ol><blockquote>Fun</blockquote><h3>Care</h3>';
        expect(sanitizeDescription(html, context)).toBe(html);
    });

    it('drops script, style, iframe, handlers and javascript: links', () => {
        const clean = sanitizeDescription(
            '<p onclick="x()" style="color:red">Safe</p><script>alert(1)</script><style>p{}</style><iframe src="https://evil.example"></iframe><a href="javascript:alert(1)">bad</a>',
            context,
        );
        expect(clean).toBe('<p>Safe</p><a>bad</a>');
    });

    it('marks external links and leaves our own alone', () => {
        const clean = sanitizeDescription('<p><a href="https://example.com/x">ext</a> <a href="https://toymartbd.com/brands/lego">own</a> <a href="mailto:help@toymartbd.com">mail</a></p>', context)!;
        expect(clean).toContain('<a href="https://example.com/x" rel="noopener nofollow">ext</a>');
        expect(clean).toContain('<a href="https://toymartbd.com/brands/lego">own</a>');
        expect(clean).toContain('<a href="mailto:help@toymartbd.com">mail</a>');
    });

    it('keeps only our own media images, resolving /m/ against the API', () => {
        const clean = sanitizeDescription(
            '<img src="https://evil.example/x.png" alt="evil"><img src="/m/13/train.webp" alt="ট্রেন" width="800" height="600" onerror="x()"><img src="https://media.toymart.test/media/ab/800.webp" alt="cdn" width="12px">',
            context,
        )!;
        expect(clean).not.toContain('evil');
        expect(clean).not.toContain('onerror');
        expect(clean).toContain('<img src="https://api.toymart.test/m/13/train.webp" alt="ট্রেন" width="800" height="600" />');
        expect(clean).toContain('<img src="https://media.toymart.test/media/ab/800.webp" alt="cdn" />');
        expect(ownMediaSrc('//evil.example/m/1/x.webp', context)).toBeNull();
    });

    it('turns legacy plain text into paragraphs and keeps Bangla', () => {
        expect(sanitizeDescription('500 bricks.\nAges 4+.\n\n৫০০টি রঙিন ব্লক < 10', context)).toBe('<p>500 bricks.<br />Ages 4+.</p><p>৫০০টি রঙিন ব্লক &lt; 10</p>');
        expect(sanitizeDescription('<p></p>', context)).toBeNull();
        expect(sanitizeDescription(null, context)).toBeNull();
    });
});

describe('ProductInfoPanel description', () => {
    const { _related: _unused, ...raw } = JSON.parse(readFileSync(new URL('../e2e/fixtures/product-detail.json', import.meta.url), 'utf8')) as StorefrontProduct & { _related: string[] };
    const base: StorefrontProduct = { ...raw, related: [] };
    const render = (product: StorefrontProduct, locale: 'en' | 'bn') =>
        renderToStaticMarkup(createElement(ProductInfoPanel, { product, variant: product.variants[0]!, locale, t: getMessages(locale), rates: null }));

    it('renders the sanitised HTML, with Bangla line-height on the Bangla text', () => {
        vi.stubEnv('API_URL', 'https://api.toymart.test/api/v1');
        const product: StorefrontProduct = {
            ...base,
            description: '<p>Stacks to <strong>six rings</strong>.</p><script>alert(1)</script>',
            description_bn: '<p><em>ছয়টি</em> রিং</p>',
        };

        const en = render(product, 'en');
        expect(en).toContain('<p>Stacks to <strong>six rings</strong>.</p>');
        expect(en).not.toContain('<script');

        const bn = render(product, 'bn');
        expect(bn).toMatch(/class="pdp-description[^"]*lang-bn[^"]*"><p><em>ছয়টি<\/em> রিং<\/p>/);
    });

    it('falls back to the English text, marked lang="en", on the Bangla page', () => {
        const product: StorefrontProduct = { ...base, description: 'Plain text\n\nSecond para', description_bn: null };
        const bn = render(product, 'bn');
        expect(bn).toMatch(/<div lang="en" class="pdp-description[^"]*"><p>Plain text<\/p><p>Second para<\/p><\/div>/);
    });
});
