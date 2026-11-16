import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import { FaqBlock } from '@/components/content/blocks/FaqBlock';
import { RichTextBlock } from '@/components/content/blocks/RichTextBlock';
import { normalizeHome } from '@/lib/content/home-blocks';
import { pageToc } from '@/lib/content/page-toc';

// "On this page" for section-built static pages (Figma Privacy 166:9992, FAQ 171:10747): entries come
// from the sections themselves, and the numbered rich text agrees with the list.

const section = (id: string, type: string, content: Record<string, unknown>) => ({ id, type, show_mobile: true, show_desktop: true, content });
const blocks = normalizeHome({
    version: 1,
    blocks: [
        section('a', 'rich_text', { html: '<p>Intro.</p><h2>What we collect</h2><ul><li>Name</li></ul><h2>How we use it</h2><p>To deliver.</p>' }),
        section('b', 'rich_text', { html: '<h2>Cookies</h2><p>A few.</p>' }),
        section('f', 'faq', { title: 'Orders', items: [{ question: 'COD?', answer: 'Yes.' }, { question: 'Cancel?', answer: 'Until packed.' }] }),
    ],
}).blocks;

describe('pageToc', () => {
    it('numbers rich-text headings across sections and counts FAQ questions', () => {
        const { entries, starts } = pageToc(blocks);
        expect(entries).toEqual([
            { id: 'section-1', label: 'What we collect', number: 1, count: null },
            { id: 'section-2', label: 'How we use it', number: 2, count: null },
            { id: 'section-3', label: 'Cookies', number: 3, count: null },
            { id: 'cms-f-title', label: 'Orders', number: null, count: 2 },
        ]);
        expect(starts.get(1)).toBe(3);
    });

    it('renders numbered sections whose anchors match the list', () => {
        const html = renderToStaticMarkup(createElement(RichTextBlock, { html: '<h2>Cookies</h2><p>A few.</p>', locale: 'en', numberFrom: 3 }));
        expect(html).toContain('id="section-3"');
        expect(html).toContain('>03<');
        // The number is decoration beside the heading, never part of its text.
        expect(html).toMatch(/<h2 id="section-3-h"[^>]*>Cookies<\/h2>/);
    });

    it('keeps the FAQ accordion a native <details> per question, anchored for the list', () => {
        const html = renderToStaticMarkup(createElement(FaqBlock, { id: 'f', title: 'Orders', items: [{ question: 'COD?', answer: 'Yes.' }], locale: 'en', jsonLd: false, fill: true }));
        expect(html).toContain('id="cms-f-title"');
        expect(html.match(/<details/g)).toHaveLength(1);
    });
});
