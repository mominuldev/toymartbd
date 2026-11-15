import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { beforeEach, describe, expect, it, vi } from 'vitest';

import { sampleContact, sampleStoreInfo, whatsappHref } from '@/lib/site-config';
import { getStoreInfo, STORE_INFO_TAG, toStoreInfo } from '@/lib/store-info';

// Launch item F4: the storefront shows the contact and legal details the owner entered in
// Admin → Settings → Store → Business (GET /storefront/store-info), and a marked, non-link sample
// for anything still unset.

const UNSET = {
    name_en: 'Toy Mart BD',
    name_bn: 'টয় মার্ট বিডি',
    hotline: null,
    hotline_tel: null,
    whatsapp: null,
    whatsapp_display: null,
    email: null,
    address_en: null,
    address_bn: null,
    trade_licence: null,
    bin: null,
};

const SET = {
    name_en: 'Toy Mart BD Ltd',
    name_bn: 'টয় মার্ট বিডি লিমিটেড',
    hotline: '09666-700800',
    hotline_tel: 'tel:+8809666700800',
    whatsapp: '+8801812345678',
    whatsapp_display: '01812-345678',
    email: 'care@toymartbd.com',
    address_en: 'House 5, Road 2, Mirpur, Dhaka 1216',
    address_bn: 'বাসা ৫, রোড ২, মিরপুর, ঢাকা ১২১৬',
    trade_licence: 'TRAD/DNCC/012345/2026',
    bin: '004567891-0101',
};

describe('toStoreInfo', () => {
    it('turns every unset value into the marked sample, never a link', () => {
        const info = toStoreInfo(UNSET);
        expect(info.contact).toEqual(sampleContact);
        for (const value of Object.values(info.contact)) expect(value.placeholder).toBe(true);
        expect(info.legal).toEqual({ tradeLicence: null, bin: null });
        expect(info.address).toEqual({ en: null, bn: null });
        expect(info.name).toEqual({ en: 'Toy Mart BD', bn: 'টয় মার্ট বিডি' });
    });

    it('treats blank strings and missing fields as unset', () => {
        const info = toStoreInfo({ hotline: '  ', hotline_tel: 'tel:+880', email: 'not-an-email', trade_licence: '' });
        expect(info.contact.hotline).toBe(sampleContact.hotline);
        expect(info.contact.email).toBe(sampleContact.email);
        expect(info.contact.whatsapp).toBe(sampleContact.whatsapp);
        expect(info.legal.tradeLicence).toBeNull();
        expect(info.name).toEqual(sampleStoreInfo.name);
    });

    it('a hotline without its tel: target stays a sample (no link to a number we cannot dial)', () => {
        expect(toStoreInfo({ ...UNSET, hotline: '09666-700800', hotline_tel: null }).contact.hotline).toBe(sampleContact.hotline);
    });

    it('makes confirmed values real links in the API’s own normalised form', () => {
        const info = toStoreInfo(SET);
        expect(info.contact.hotline).toEqual({ display: '09666-700800', href: 'tel:+8809666700800', placeholder: false });
        expect(info.contact.whatsapp).toEqual({ display: '01812-345678', href: 'https://wa.me/8801812345678', placeholder: false });
        expect(info.contact.email).toEqual({ display: 'care@toymartbd.com', href: 'mailto:care@toymartbd.com', placeholder: false });
        expect(info.legal).toEqual({ tradeLicence: 'TRAD/DNCC/012345/2026', bin: '004567891-0101' });
        expect(info.address).toEqual({ en: SET.address_en, bn: SET.address_bn });
        expect(info.name).toEqual({ en: 'Toy Mart BD Ltd', bn: 'টয় মার্ট বিডি লিমিটেড' });
    });

    it('mixes: a set hotline beside an unset email', () => {
        const info = toStoreInfo({ ...UNSET, hotline: SET.hotline, hotline_tel: SET.hotline_tel });
        expect(info.contact.hotline.placeholder).toBe(false);
        expect(info.contact.email).toBe(sampleContact.email);
    });

    it('shows the E.164 WhatsApp number when the API sends no display form', () => {
        expect(toStoreInfo({ ...UNSET, whatsapp: '+8801812345678' }).contact.whatsapp.display).toBe('+8801812345678');
    });
});

describe('whatsappHref', () => {
    it('sends a sample number to the fallback page, a confirmed one to wa.me', () => {
        expect(whatsappHref(sampleContact.whatsapp, '/contact')).toBe('/contact');
        expect(whatsappHref(toStoreInfo(SET).contact.whatsapp, '/contact')).toBe('https://wa.me/8801812345678');
    });
});

describe('getStoreInfo', () => {
    const fetchMock = vi.fn();
    const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

    beforeEach(() => {
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
        vi.stubEnv('API_URL', 'https://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'tok');
        vi.spyOn(console, 'error').mockImplementation(() => undefined);
    });

    it('reads /storefront/store-info, ISR-tagged store-info', async () => {
        fetchMock.mockResolvedValue(json(SET));
        const info = await getStoreInfo();
        expect(info.contact.hotline.href).toBe('tel:+8809666700800');
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit & { next?: { tags?: string[] } }];
        expect(url).toBe('https://api.test/api/v1/storefront/store-info');
        expect(init.next?.tags).toEqual([STORE_INFO_TAG]);
        expect(STORE_INFO_TAG).toBe('store-info');
    });

    it('accepts a { data } envelope too', async () => {
        fetchMock.mockResolvedValue(json({ data: SET }));
        expect((await getStoreInfo()).legal.bin).toBe('004567891-0101');
    });

    it('never throws: an API error falls back to the samples', async () => {
        fetchMock.mockResolvedValue(json({ type: 'about:blank', title: 'Server Error', status: 500 }, 500));
        await expect(getStoreInfo()).resolves.toEqual(sampleStoreInfo);
    });

    it('never throws: a network failure falls back to the samples', async () => {
        fetchMock.mockRejectedValue(new TypeError('fetch failed'));
        await expect(getStoreInfo()).resolves.toEqual(sampleStoreInfo);
    });

    it('never throws: a missing API config falls back to the samples', async () => {
        vi.stubEnv('API_URL', '');
        await expect(getStoreInfo()).resolves.toEqual(sampleStoreInfo);
        expect(fetchMock).not.toHaveBeenCalled();
    });
});

describe('where the values may be read', () => {
    const root = fileURLToPath(new URL('..', import.meta.url));
    const files = (dir: string): string[] =>
        readdirSync(join(root, dir)).flatMap((name) => {
            const path = join(dir, name);
            return statSync(join(root, path)).isDirectory() ? files(path) : /\.tsx?$/.test(name) ? [path] : [];
        });
    const sources = ['app', 'components', 'lib'].flatMap(files).map((path) => ({ path, text: readFileSync(join(root, path), 'utf8') }));

    it('no client component imports the server-only store-info reader (rule 28)', () => {
        for (const { path, text } of sources) {
            if (/^\s*['"]use client['"]/.test(text)) expect(text, path).not.toMatch(/from ['"][^'"]*store-info['"]/);
        }
    });

    it('only the client error shell and the reader itself use the samples for display', () => {
        const users = sources.filter(({ text }) => /\bsample(StoreInfo|Contact)\b/.test(text)).map(({ path }) => path.replaceAll('\\', '/'));
        expect(users.sort()).toEqual(['components/status/ErrorShell.tsx', 'lib/site-config.ts', 'lib/store-info.ts']);
    });
});
