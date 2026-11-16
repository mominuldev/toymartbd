import { readFileSync } from 'node:fs';

import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { SiteHeader } from '@/components/layout/SiteHeader';
import { getMessages, locales } from '@/lib/i18n';
import { organizationJsonLd, websiteJsonLd } from '@/lib/seo/jsonld';
import { composeMetadata, contentMetadata, homeMetadata, privateMetadata, productMetadata, siteMetadata, staticMetadata } from '@/lib/seo/metadata';
import { sampleContact, sampleStoreInfo, unsetBranding, type StoreBranding } from '@/lib/site-config';
import { toStoreInfo } from '@/lib/store-info';

// What getStoreInfo() answers in this file; each test sets `site.branding`.
const site = vi.hoisted(() => ({ branding: null as StoreBranding | null }));
vi.mock('@/lib/store-info', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/store-info')>();
    return { ...actual, getStoreInfo: async () => ({ ...sampleStoreInfo, branding: site.branding ?? unsetBranding }) };
});
beforeEach(() => {
    site.branding = null;
});

// Site & branding (Admin → Settings → Store → Site & branding, GET /storefront/store-info): the
// root layout's metadata, the home page's, the JSON-LD and the header logo. Unset branding must
// leave the storefront exactly as before (catalog copy, wordmark, no icons, no share image).

const BRANDED: StoreBranding = toStoreInfo({
    site_name_en: 'ToyMart',
    site_name_bn: 'টয়মার্ট',
    tagline_en: 'Toys that grow with your child',
    tagline_bn: 'শিশুর সাথে বেড়ে ওঠা খেলনা',
    description_en: 'Original toys, cash on delivery across Bangladesh.',
    description_bn: 'আসল খেলনা, সারা বাংলাদেশে ক্যাশ অন ডেলিভারি।',
    logo: { url: 'https://media.toymartbd.com/b/logo-480.webp', width: 480, height: 120 },
    share_image: { url: 'https://media.toymartbd.com/b/share-1200.jpg', width: 1200, height: 630 },
    icons: { png32: 'https://media.toymartbd.com/i/32.png', png180: 'https://media.toymartbd.com/i/180.png', png192: 'https://media.toymartbd.com/i/192.png', png512: 'https://media.toymartbd.com/i/512.png' },
}).branding;

describe.each(locales)('siteMetadata (%s)', (locale) => {
    const t = getMessages(locale);

    it('unset branding: the catalog name and tagline, no share image, no icons', () => {
        const meta = siteMetadata(locale, unsetBranding, ' | ');
        expect(meta.title).toEqual({ default: t.site.name, template: `%s | ${t.site.name}` });
        expect(meta.description).toBe(t.site.tagline);
        expect(meta.openGraph).not.toHaveProperty('images');
        expect(meta.twitter).toMatchObject({ card: 'summary' });
        expect(meta).not.toHaveProperty('icons');
    });

    it('the owner’s site name, description, share image and PNG icons', () => {
        const meta = siteMetadata(locale, BRANDED, ' · ');
        const name = locale === 'bn' ? 'টয়মার্ট' : 'ToyMart';
        expect(meta.title).toEqual({ default: name, template: `%s · ${name}` });
        expect(meta.description).toBe(BRANDED.description[locale]);
        expect(meta.openGraph).toMatchObject({
            siteName: name,
            description: BRANDED.description[locale],
            images: [{ url: 'https://media.toymartbd.com/b/share-1200.jpg', width: 1200, height: 630, alt: name }],
        });
        expect(meta.twitter).toMatchObject({ card: 'summary_large_image', images: ['https://media.toymartbd.com/b/share-1200.jpg'] });
        expect(meta.icons).toEqual({
            icon: [
                { url: 'https://media.toymartbd.com/i/32.png', sizes: '32x32', type: 'image/png' },
                { url: 'https://media.toymartbd.com/i/192.png', sizes: '192x192', type: 'image/png' },
                { url: 'https://media.toymartbd.com/i/512.png', sizes: '512x512', type: 'image/png' },
            ],
            apple: [{ url: 'https://media.toymartbd.com/i/180.png', sizes: '180x180', type: 'image/png' }],
        });
    });

    it('no description → the tagline; no tagline either → the catalog tagline', () => {
        expect(siteMetadata(locale, { ...BRANDED, description: { en: null, bn: null } }).description).toBe(BRANDED.tagline[locale]);
        expect(siteMetadata(locale, { ...BRANDED, description: { en: null, bn: null }, tagline: { en: null, bn: null } }).description).toBe(t.site.tagline);
    });

    it('only the icons the job has rendered', () => {
        const meta = siteMetadata(locale, { ...BRANDED, icons: { png32: 'https://m.test/32.png', png180: null, png192: null, png512: null } });
        expect(meta.icons).toEqual({ icon: [{ url: 'https://m.test/32.png', sizes: '32x32', type: 'image/png' }] });
    });
});

describe.each(locales)('homeMetadata with branding (%s)', (locale) => {
    const t = getMessages(locale);

    it('without branding: unchanged', async () => {
        const meta = await homeMetadata(locale, ' | ');
        expect(meta.title).toEqual({ absolute: `${t.site.name} | ${t.site.tagline}` });
        expect(meta.description).toBe(t.home.metaDescription);
        expect(meta.openGraph).not.toHaveProperty('images');
        expect((meta.openGraph as Record<string, unknown>).siteName).toBe(t.site.name);
    });

    it('site name + tagline in the title, the owner’s description, the share image', async () => {
        site.branding = BRANDED;
        const meta = await homeMetadata(locale, ' | ');
        expect(meta.title).toEqual({ absolute: `${BRANDED.siteName[locale]} | ${BRANDED.tagline[locale]}` });
        expect(meta.description).toBe(BRANDED.description[locale]);
        expect(meta.openGraph).toMatchObject({ siteName: BRANDED.siteName[locale], images: [{ url: 'https://media.toymartbd.com/b/share-1200.jpg', width: 1200, height: 630 }] });
        expect(meta.twitter).toMatchObject({ card: 'summary_large_image' });
    });
});

describe.each(locales)('every page template: share image + og:site_name from Site & branding (%s)', (locale) => {
    const t = getMessages(locale);
    const SHARE = 'https://media.toymartbd.com/b/share-1200.jpg';
    const og = (meta: { openGraph?: unknown }) => meta.openGraph as Record<string, unknown>;

    it('a page without an image of its own gets the share image (Open Graph + Twitter) and the branded site name', async () => {
        site.branding = BRANDED;
        for (const meta of [
            await staticMetadata(locale, '/brands', 'Brands', 'd'),
            await privateMetadata(locale, '/cart', 'Cart', { follow: true }),
            await contentMetadata({ locale, path: '/returns-policy', title: 'Returns' }),
        ]) {
            expect(og(meta).images).toEqual([{ url: SHARE, width: 1200, height: 630, alt: BRANDED.siteName[locale] }]);
            expect(og(meta).siteName).toBe(BRANDED.siteName[locale]);
            expect(meta.twitter).toMatchObject({ card: 'summary_large_image', images: [SHARE] });
        }
    });

    it('a page with its own image keeps it', async () => {
        site.branding = BRANDED;
        const own = { url: 'https://media.toymartbd.com/p/lego.webp', width: 800, height: 800, alt: 'LEGO' };
        const meta = await productMetadata({ locale, slug: 'lego', name: 'LEGO', description: 'd', image: own });
        expect(og(meta).images).toEqual([own]);
        expect(meta.twitter).toMatchObject({ images: [own.url] });
        expect(og(meta).siteName).toBe(BRANDED.siteName[locale]);
    });

    it('no share image → no og:image (and a summary card); site name still branded', async () => {
        site.branding = { ...BRANDED, shareImage: null };
        const meta = await staticMetadata(locale, '/brands', 'Brands', 'd');
        expect(og(meta)).not.toHaveProperty('images');
        expect(meta.twitter).toMatchObject({ card: 'summary' });
        expect(meta.twitter).not.toHaveProperty('images');
        expect(og(meta).siteName).toBe(BRANDED.siteName[locale]);
    });

    it('nothing set (or store-info unreachable → samples): the catalog name, no image', async () => {
        const meta = await staticMetadata(locale, '/brands', 'Brands', 'd');
        expect(og(meta)).not.toHaveProperty('images');
        expect(og(meta).siteName).toBe(t.site.name);
    });

    it('composeMetadata is pure: no branding = catalog name, no default image', () => {
        const meta = composeMetadata({ locale, path: '/brands', title: 'Brands' });
        expect(og(meta).siteName).toBe(t.site.name);
        expect(og(meta)).not.toHaveProperty('images');
        expect(og(composeMetadata({ locale, path: '/brands', title: 'Brands' }, BRANDED)).images).toHaveLength(1);
    });
});

describe('JSON-LD with branding', () => {
    it.each(locales)('Organization takes the site name and uploaded logo; WebSite the site name (%s)', (locale) => {
        const org = organizationJsonLd(locale, sampleContact, BRANDED);
        expect(org.name).toBe(BRANDED.siteName[locale]);
        expect(org.logo).toBe('https://media.toymartbd.com/b/logo-480.webp');
        expect(websiteJsonLd(locale, BRANDED).name).toBe(BRANDED.siteName[locale]);
        expect(organizationJsonLd(locale, sampleContact, unsetBranding)).not.toHaveProperty('logo');
    });
});

describe('header logo', () => {
    const render = (branding?: StoreBranding, locale: 'en' | 'bn' = 'en') =>
        renderToStaticMarkup(SiteHeader({ locale, path: '/', menu: [], hotline: sampleContact.hotline, ...(branding ? { branding } : {}) }));

    it.each(locales)('the uploaded logo, sized and named, instead of the wordmark (%s)', (locale) => {
        const html = render(BRANDED, locale);
        const name = BRANDED.siteName[locale];
        expect(html).toContain(`<img src="https://media.toymartbd.com/b/logo-480.webp" alt="${name}" width="480" height="120"`);
        expect(html).toContain(`aria-label="${name} — ${locale === 'bn' ? 'হোম' : 'home'}"`);
        expect(html).not.toContain('t-wordmark');
    });

    it('no logo (unset, or the client error shell with no branding) → the wordmark', () => {
        for (const html of [render(unsetBranding), render()]) {
            expect(html).not.toContain('data-store-logo');
            expect(html).toContain('t-wordmark');
            expect(html).toContain('aria-label="ToyMart BD — home"');
        }
    });

    it('a plain <img> (no remotePatterns for the media host), never next/image', () => {
        const source = readFileSync(new URL('../components/layout/SiteHeader.tsx', import.meta.url), 'utf8');
        expect(source).not.toMatch(/from ['"]next\/image['"]/);
        expect(source).toMatch(/width=\{logo\.width\}/);
        expect(source).toMatch(/height=\{logo\.height\}/);
        // Not preloaded by React: the hero stays the only image preload (LCP budget).
        expect(source).toMatch(/fetchPriority="low"/);
    });
});
