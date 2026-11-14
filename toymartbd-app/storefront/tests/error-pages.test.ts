import { readFileSync } from 'node:fs';

import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

// P10 launch checklist "404 and 500 pages styled" (storefront spec §B8, Figma 163:9166 / 169:10286):
// the 404 and 500 bodies in English and Bangla, and the four Next.js entry points that use them.
// The HTTP status + noindex of a 404 is covered by e2e/ssr.spec.ts; this covers the markup.

vi.mock('@/lib/categories', () => ({
    getCategoryTree: async () => [
        { id: 1, name: 'Building', name_bn: 'বিল্ডিং', slug: 'building', icon: null, image_url: null, children: [] },
        { id: 2, name: 'Dolls', name_bn: 'পুতুল', slug: 'dolls', icon: null, image_url: null, children: [] },
    ],
}));

const { ServerErrorContent } = await import('@/components/status/ServerErrorContent');
const { NotFoundContent } = await import('@/components/status/NotFoundContent');
const { contact } = await import('@/lib/site-config');

const source = (path: string) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

describe('500 body', () => {
    it.each([
        ['en', 'Something broke on our side', 'Try again'],
        ['bn', 'আমাদের দিকে কিছু সমস্যা হয়েছে', 'আবার চেষ্টা করুন'],
    ] as const)('renders the %s copy with a retry and the hotline', (locale, title, retry) => {
        const html = renderToStaticMarkup(createElement(ServerErrorContent, { locale, onRetry: () => undefined }));

        expect(html).toContain('<h1');
        expect(html).toContain(title);
        expect(html).toContain(retry);
        expect(html).toContain(contact.hotline.display);
        expect(html).not.toMatch(/[০-৯]/); // Western numerals in both locales (rule 36)
    });

    it('never makes a placeholder hotline dialable', () => {
        const html = renderToStaticMarkup(createElement(ServerErrorContent, { locale: 'en' }));

        if (contact.hotline.placeholder) {
            expect(html).toContain('data-placeholder="owner-to-confirm"');
            expect(html).not.toContain(`href="${contact.hotline.href}"`);
        } else {
            expect(html).toContain(`href="${contact.hotline.href}"`);
        }
    });

    it('links home in the right locale when there is nothing to retry (global-error)', () => {
        expect(renderToStaticMarkup(createElement(ServerErrorContent, { locale: 'bn' }))).toContain('href="/bn"');
        expect(renderToStaticMarkup(createElement(ServerErrorContent, { locale: 'en' }))).toContain('href="/"');
    });
});

describe('404 body', () => {
    it.each([
        ['en', "This page isn&#x27;t here", '/categories', 'Building'],
        ['bn', 'এই পাতাটি নেই', '/bn/categories', 'বিল্ডিং'],
    ] as const)('renders the %s copy, search, ways back and category tiles', async (locale, title, browse, category) => {
        const html = renderToStaticMarkup(await NotFoundContent({ locale }));

        expect(html).toContain('<h1');
        expect(html).toContain(title);
        expect(html).toContain(`href="${browse}"`);
        expect(html).toContain('id="search-not-found"');
        expect(html).toContain(category);
    });
});

describe('Next.js error entry points', () => {
    it('app/global-not-found.tsx renders a full localised document with the shared 404 body', () => {
        const file = source('app/global-not-found.tsx');
        expect(file).toContain('<html lang={htmlLang[locale]}');
        expect(file).toContain("locale === 'bn' ? ' lang-bn' : ''");
        expect(file).toContain('<NotFoundContent locale={locale} />');
    });

    it('app/[locale]/not-found.tsx and error.tsx use the shared bodies', () => {
        expect(source('app/[locale]/not-found.tsx')).toContain('<NotFoundContent locale={locale} />');
        expect(source('app/[locale]/error.tsx')).toContain('<ErrorShell locale={locale} onRetry={reset} />');
    });

    it('app/global-error.tsx replaces the document, keeps the locale and the Bangla line-height class', () => {
        const file = source('app/global-error.tsx');
        expect(file).toContain("'use client'");
        expect(file).toContain('<html lang={locale}');
        expect(file).toContain("locale === 'bn' ? ' lang-bn' : ''");
        expect(file).toContain('<ErrorShell locale={locale} onRetry={reset} />');
        expect(file).toContain("import './globals.css'");
    });

    it('never prints the error message on the page', () => {
        for (const path of ['app/global-error.tsx', 'app/[locale]/error.tsx']) {
            expect(source(path)).not.toMatch(/\{\s*error\.message\s*\}/);
        }
    });
});
