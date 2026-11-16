import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ContactFormBlock } from '@/components/content/blocks/ContactFormBlock';
import { ContactHeroBlock } from '@/components/content/blocks/ContactHeroBlock';
import { decodeFlash } from '@/lib/account/flash';
import { normalizeHome } from '@/lib/content/home-blocks';

// Contact us (Figma 165:9657, 2026-11-16): the contact hero and the "Send us a message" section
// (CMS `contact_hero`, `contact_form`), and POST /api/contact — a plain form post to the API's
// POST /storefront/contact-messages (→ admin Inbox) answered with a 303 and a one-shot flash.

const parse = (type: string, content: Record<string, unknown>) =>
    normalizeHome({ version: 1, blocks: [{ id: 'b1', type, show_mobile: true, show_desktop: true, content }] }).blocks;

describe('contact_hero', () => {
    const content = { title: 'Contact us', lead: 'Ask us.', status: 'Online now', bubbles: [
        { from: 'customer', label: 'Parent', text: 'OK for 2?' },
        { from: 'store', label: 'Toy Mart', text: 'Yes.' },
        { from: 'robot', text: 'Treated as a customer' },
        { from: 'store', text: '' },
        { from: 'store', text: 'Four' },
        { from: 'store', text: 'Five is too many' },
    ] };

    it('keeps at most four bubbles with text; an unknown sender is the customer', () => {
        const [block] = parse('contact_hero', content);
        if (block?.type !== 'contact_hero') throw new Error('not parsed');
        expect(block.content.bubbles.map((b) => b.from)).toEqual(['customer', 'store', 'customer', 'store']);
    });

    it('is the page H1 when it opens the page, and hides the bubbles from screen readers', () => {
        const [block] = parse('contact_hero', content);
        if (block?.type !== 'contact_hero') throw new Error('not parsed');
        const html = renderToStaticMarkup(createElement(ContactHeroBlock, { content: block.content, locale: 'en', heading: 'h1' }));
        expect(html).toContain('<h1');
        expect(html).toContain('Online now');
        expect(html).toMatch(/aria-hidden="true" class="hidden w-105/);
    });
});

describe('contact_form', () => {
    it('drops side cards without a title, links without a safe href', () => {
        const [block] = parse('contact_form', {
            title: 'Send us a message',
            visit_title: null,
            hours: [{ label: 'Friday', value: 'Closed' }],
            links_title: 'Quicker than a message',
            links: [{ icon: 'clock', label: 'Delivery', href: '/delivery-policy' }, { icon: 'gift', label: 'Bad', href: 'javascript:alert(1)' }],
        });
        if (block?.type !== 'contact_form') throw new Error('not parsed');
        expect(block.content.visit).toBeNull();
        expect(block.content.links?.items).toEqual([{ icon: 'clock', label: 'Delivery', href: '/delivery-policy' }]);
    });

    it('renders a plain POST form to /api/contact with the fixed fields, errors and the typed draft', () => {
        const [block] = parse('contact_form', { title: 'Send us a message' });
        if (block?.type !== 'contact_form') throw new Error('not parsed');
        const html = renderToStaticMarkup(
            createElement(ContactFormBlock, {
                id: 'f',
                content: block.content,
                locale: 'bn',
                flash: { errors: { phone: 'বাংলাদেশি মোবাইল নম্বর দিন' }, draft: { name: 'Nusrat', phone: '01112345678', message: 'Hello there!' }, message: null },
            })
        );
        expect(html).toMatch(/<form[^>]*method="post"/);
        expect(html).toMatch(/<form[^>]*action="\/api\/contact"/);
        for (const name of ['name', 'phone', 'topic', 'order_number', 'message', 'locale']) expect(html).toContain(`name="${name}"`);
        expect(html).toContain('value="Nusrat"');
        expect(html).toContain('aria-invalid="true"');
        expect(html).toContain('role="alert"');
        expect(html).toContain('12 / 500');
    });
});

describe('POST /api/contact', () => {
    beforeEach(() => {
        vi.stubEnv('API_URL', 'http://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'server-token');
    });

    const post = (fields: Record<string, string>) =>
        new Request('http://storefront.test/api/contact', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields) });
    const flashOf = (res: Response) => decodeFlash(decodeURIComponent(/tm_account_flash=([^;]+)/.exec(res.headers.get('set-cookie') ?? '')?.[1] ?? ''));
    const fields = { locale: 'bn', name: 'Nusrat', phone: '01712345678', topic: 'order', order_number: 'TMB-26-00042', message: 'Where is my order?' };

    it('sends the fields to the API and 303s to the thank-you', async () => {
        const calls: Array<{ url: string; init: RequestInit }> = [];
        vi.stubGlobal('fetch', async (url: string, init: RequestInit) => {
            calls.push({ url, init });
            return new Response(JSON.stringify({ status: 'received' }), { status: 202, headers: { 'Content-Type': 'application/json' } });
        });
        const { POST } = await import('@/app/api/contact/route');
        const res = await POST(post(fields));
        expect(res.status).toBe(303);
        expect(res.headers.get('location')).toBe('/bn/contact-message');
        expect(calls[0]?.url).toBe('http://api.test/api/v1/storefront/contact-messages');
        expect(JSON.parse(String(calls[0]?.init.body))).toEqual({ name: 'Nusrat', phone: '01712345678', topic: 'order', order_number: 'TMB-26-00042', message: 'Where is my order?', locale: 'bn' });
        expect(flashOf(res)?.kind).toBe('success');
    });

    it('turns a 422 into field error codes and keeps the draft; 429 and failures get a message', async () => {
        const problem = (status: number) => new Response(JSON.stringify({ type: 'x', title: 'x', status, errors: [{ field: 'phone', message: 'The phone format is invalid.' }] }), { status, headers: { 'Content-Type': 'application/problem+json' } });
        const { POST } = await import('@/app/api/contact/route');

        vi.stubGlobal('fetch', async () => problem(422));
        const invalid = flashOf(await POST(post({ ...fields, phone: '01112345678', topic: 'nonsense' })));
        expect(invalid?.errors).toEqual({ phone: 'invalid' });
        expect(invalid?.draft.phone).toBe('01112345678');
        expect(invalid?.draft.topic).toBe('other');

        vi.stubGlobal('fetch', async () => problem(429));
        expect(flashOf(await POST(post(fields)))?.message).toBe('limited');

        vi.stubGlobal('fetch', async () => problem(500));
        expect(flashOf(await POST(post(fields)))?.message).toBe('failed');
    });
});
