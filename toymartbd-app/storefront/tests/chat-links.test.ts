import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import { ChatLinks, ChatSection } from '@/components/ChatLinks';
import { chatLinks } from '@/lib/chat-links';
import { format, getMessages } from '@/lib/i18n';
import { sampleContact } from '@/lib/site-config';
import { toStoreInfo } from '@/lib/store-info';

// WhatsApp click-to-chat and Messenger m.me links: server-rendered, each only once the owner has set
// it, no script or SDK, new tab with noopener noreferrer, named in both languages.

const real = toStoreInfo({ whatsapp: '+8801812345678', whatsapp_display: '01812-345678', messenger: 'toymart.bd' }).contact;
const waOnly = toStoreInfo({ whatsapp: '+8801812345678' }).contact;
const messengerOnly = toStoreInfo({ messenger: '104857234567890' }).contact;

describe('chatLinks', () => {
    it('offers nothing while WhatsApp is a sample and no Messenger Page is set', () => {
        expect(chatLinks(sampleContact)).toEqual([]);
    });

    it('builds wa.me from the E.164 digits and m.me from the Page, each only when set', () => {
        expect(chatLinks(real)).toEqual([
            { channel: 'whatsapp', href: 'https://wa.me/8801812345678' },
            { channel: 'messenger', href: 'https://m.me/toymart.bd' },
        ]);
        expect(chatLinks(waOnly).map((l) => l.channel)).toEqual(['whatsapp']);
        expect(chatLinks(messengerOnly)).toEqual([{ channel: 'messenger', href: 'https://m.me/104857234567890' }]);
    });

    it('prefills the WhatsApp text, URL-encoded, Bangla included', () => {
        const text = format(getMessages('bn').chat.productText, { name: 'লেগো সিটি ফায়ার ট্রাক', url: 'https://toymartbd.com/bn/products/lego-city-fire-truck' });
        const [wa] = chatLinks(real, text);
        expect(wa?.href.startsWith('https://wa.me/8801812345678?text=')).toBe(true);
        expect(decodeURIComponent(new URL(wa!.href).search.slice('?text='.length))).toBe(text);
        expect(text).toContain('https://toymartbd.com/bn/products/lego-city-fire-truck');
        expect(chatLinks(real, text)[1]?.href).toBe('https://m.me/toymart.bd'); // m.me takes no text
    });
});

describe('ChatLinks', () => {
    it('renders plain new-tab links with accessible names, in each locale', () => {
        for (const locale of ['en', 'bn'] as const) {
            const t = getMessages(locale).chat;
            const html = renderToStaticMarkup(createElement(ChatLinks, { locale, contact: real }));
            expect(html.match(/target="_blank" rel="noopener noreferrer"/g)).toHaveLength(2);
            expect(html).toContain(t.whatsapp);
            expect(html).toContain(t.messenger);
            expect(html).toContain(`<span class="sr-only"> ${t.newTab}</span>`);
            expect(html).toContain('h-control-md'); // 44 px touch target
            expect(html).not.toContain('<script');
        }
    });

    it('renders nothing at all when no channel is configured', () => {
        expect(renderToStaticMarkup(createElement(ChatLinks, { locale: 'en', contact: sampleContact }))).toBe('');
        expect(renderToStaticMarkup(createElement(ChatSection, { id: 'c', title: 'Chat', locale: 'en', contact: sampleContact }))).toBe('');
    });

    it('labels the section by its heading', () => {
        const html = renderToStaticMarkup(createElement(ChatSection, { id: 'pdp-chat', title: getMessages('en').chat.productTitle, locale: 'en', contact: messengerOnly }));
        expect(html).toContain('aria-labelledby="pdp-chat"');
        expect(html).toContain('id="pdp-chat"');
        expect(html).not.toContain('wa.me');
    });
});

describe('the Content-Security-Policy', () => {
    it('needs no new source: the chat buttons are links, not scripts or frames', () => {
        const csp = readFileSync(fileURLToPath(new URL('../lib/csp.ts', import.meta.url)), 'utf8');
        expect(csp).not.toMatch(/https:\/\/(?:wa|m)\.me/);
        const component = readFileSync(fileURLToPath(new URL('../components/ChatLinks.tsx', import.meta.url)), 'utf8');
        expect(component).not.toMatch(/use client|<script|next\/script/);
    });
});
