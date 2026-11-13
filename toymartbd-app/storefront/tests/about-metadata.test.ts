import type { Metadata } from 'next';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { CmsPage } from '@/lib/content/types';
import { locales, type Locale } from '@/lib/i18n';
import type { SeoDocument } from '@/lib/seo/types';

// The About route (app/[locale]/pages/about) builds its <head> through the lib/seo builder in
// BOTH branches — the CMS `about` page and the built-in fallback copy — so it always carries the
// canonical for its locale and hreflang en / bn / x-default (rule 35), like every other page.

const SITE = 'https://toymartbd.com';
const PATH = '/pages/about';

const findPage = vi.fn<(slug: string) => Promise<CmsPage | null>>();
const getSeo = vi.fn<(type: string, slug: string) => Promise<SeoDocument | null>>();

vi.mock('@/lib/content/api', () => ({ findPage: (slug: string) => findPage(slug) }));
vi.mock('@/lib/seo/fetch', () => ({ getSeo: (type: string, slug: string) => getSeo(type, slug) }));

const about: CmsPage = {
    slug: 'about',
    title: 'About Toy Mart BD',
    title_bn: 'টয় মার্ট বিডি সম্পর্কে',
    template: 'default',
    content: '<p>Toys for every age.</p>',
    content_bn: '<p>সব বয়সের খেলনা।</p>',
    faq_items: [],
    published_at: '2026-11-01T00:00:00Z',
    updated_at: '2026-11-10T00:00:00Z',
    seo: {
        title: 'About us',
        title_bn: 'আমাদের সম্পর্কে',
        description: 'Who we are.',
        description_bn: 'আমরা কারা।',
        canonical_url: null,
        robots: 'index,follow',
        og_title: null,
        og_description: null,
        og_image: `${SITE}/media/about-og.webp`,
        twitter_card: 'summary_large_image',
    },
};

function expectHead(meta: Metadata, locale: Locale): void {
    const canonical = locale === 'en' ? PATH : `/bn${PATH}`;
    expect(meta.alternates?.canonical).toBe(canonical);
    expect(meta.alternates?.languages).toEqual({ en: PATH, bn: `/bn${PATH}`, 'x-default': PATH });
    const og = meta.openGraph as Record<string, unknown>;
    expect(og.url).toBe(`${SITE}${canonical}`);
    expect(og.locale).toBe(locale === 'en' ? 'en_US' : 'bn_BD');
}

async function metadataFor(locale: Locale): Promise<Metadata> {
    const { generateMetadata } = await import('@/app/[locale]/pages/about/page');
    return generateMetadata({ params: Promise.resolve({ locale }) });
}

describe.each(locales)('About page metadata (%s)', (locale) => {
    beforeEach(() => {
        vi.stubEnv('SITE_URL', SITE);
        findPage.mockReset();
        getSeo.mockReset();
    });

    it('uses the lib/seo builder for the CMS about page, with its SEO document overlay', async () => {
        findPage.mockResolvedValue(about);
        getSeo.mockResolvedValue(null);

        const meta = await metadataFor(locale);

        expectHead(meta, locale);
        expect(meta.title).toBe(locale === 'bn' ? 'আমাদের সম্পর্কে' : 'About us');
        expect(meta.description).toBe(locale === 'bn' ? 'আমরা কারা।' : 'Who we are.');
        expect((meta.openGraph as Record<string, unknown>).images).toEqual([{ url: `${SITE}/media/about-og.webp` }]);
        expect(getSeo).toHaveBeenCalledWith('page', 'about');
    });

    it('still emits canonical and hreflang for the built-in fallback copy', async () => {
        findPage.mockResolvedValue(null);

        const meta = await metadataFor(locale);

        expectHead(meta, locale);
        expect(typeof meta.title).toBe('string');
    });
});
