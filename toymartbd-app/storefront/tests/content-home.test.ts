import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

import { HomeBlocks } from '@/components/content/HomeBlocks';
import { deviceClass, normalizeHome, safeHref, type HomeBlock } from '@/lib/content/home-blocks';
import { HOME_PREVIEW_COOKIE, previewTokenLifetime } from '@/lib/content/preview';
import { en } from '@/lib/i18n/en';
import { bn } from '@/lib/i18n/bn';

// Phase 7 CMS homepage: the response guard, the preview-token pre-check and every block type
// rendered in both locales.

vi.mock('@/public/images/home/hero.jpg', () => ({ default: { src: '/_next/static/media/hero.jpg', width: 1216, height: 800, blurDataURL: '' } }));

const image = { url: 'https://cdn.test/media/1/800.webp', srcset_webp: 'https://cdn.test/media/1/800.webp 800w', srcset_avif: 'https://cdn.test/media/1/800.avif 800w', width: 800, height: 500, alt: 'Bricks' };
const card = {
    id: 7, slug: 'wooden-train', name: 'Wooden Train', name_bn: 'কাঠের ট্রেন', brand: null, price: '650.00', compare_at_price: null, price_max: '650.00', discount_percent: null,
    age_min_months: 24, age_max_months: 72, rating_avg: null, rating_count: 0, is_featured: false, variant_count: 1, in_stock: true, image: null,
};
const post = {
    id: 3, type: 'buying_guide', slug: 'first-toys', title: 'First toys', title_bn: 'প্রথম খেলনা', excerpt: null, excerpt_bn: null, cover: null,
    category: { name: 'Guides', name_bn: 'গাইড', slug: 'guides' }, author: null, reading_minutes: 4, reading_minutes_bn: null, published_at: null, is_featured: false,
};

function body(locale: 'en' | 'bn') {
    const bnText = locale === 'bn';
    const base = (id: string) => ({ id, show_mobile: true, show_desktop: true });
    return {
        version: 3,
        preview: false,
        blocks: [
            { ...base('h'), type: 'hero', content: { show_age_pills: true, slides: [{ overline: null, headline: bnText ? 'ঈদের খেলনা' : 'Eid toys', sub: null, cta_label: 'Shop', cta_href: '/toys', image }] } },
            { ...base('c'), type: 'category_tiles', content: { title: bnText ? 'ক্যাটাগরি' : 'Categories', show_see_all: true }, categories: [{ id: 1, name: 'Puzzles', name_bn: 'পাজল', slug: 'puzzles', icon: null, image_url: null, children: [] }] },
            { ...base('a'), type: 'age_tiles', content: { title: 'Shop by age', show_see_all: true }, age_counts: { '0-12': 1, '12-24': 5 } },
            { ...base('r'), type: 'product_rail', content: { title: 'New arrivals' }, products: [card], see_all_href: '/toys?sort=newest' },
            { ...base('b'), type: 'brand_strip', content: { title: 'Brands' }, brands: [{ name: 'LEGO', slug: 'lego', logo_url: null }] },
            { ...base('u'), type: 'usp_band', content: { eyebrow: 'Our promise', title: 'Why parents shop with us', items: [{ icon: 'cod', title: 'Cash on delivery', detail: 'Call {phone}' }, { icon: 'gift', title: 'Gift wrap', detail: null }] } },
            { ...base('p'), type: 'promo_split', content: { overline: null, headline: 'Learn', body: 'Science kits', cta_label: 'Go', cta_href: 'https://example.com/x', image: null, image_side: 'right' } },
            { ...base('t'), type: 'testimonials', content: { title: 'Parents say' }, testimonials: [{ id: 1, name: 'Rina', location: 'Dhaka', quote: 'Fast.', rating: 5, image: null }] },
            { ...base('g'), type: 'blog_teaser', content: { title: 'Blog', sub: null }, posts: [post] },
            { ...base('x'), type: 'rich_text', content: { html: '<p>About <script>alert(1)</script>us</p>' } },
            { ...base('f'), type: 'faq', content: { title: 'FAQ', items: [{ question: 'COD?', answer: 'Yes.' }] } },
        ],
    };
}

const render = (locale: 'en' | 'bn') => renderToStaticMarkup(createElement(HomeBlocks, { blocks: normalizeHome(body(locale)).blocks, locale }));

describe('normalizeHome', () => {
    it('keeps every well-formed block type, in order', () => {
        expect(normalizeHome(body('en')).blocks.map((b) => b.type)).toEqual([
            'hero', 'category_tiles', 'age_tiles', 'product_rail', 'brand_strip', 'usp_band', 'promo_split', 'testimonials', 'blog_teaser', 'rich_text', 'faq',
        ]);
        expect(normalizeHome(body('en')).version).toBe(3);
    });

    it('drops unknown, malformed and empty blocks instead of failing the page', () => {
        const home = normalizeHome({
            version: 1,
            blocks: [
                { id: 'a', type: 'carousel_of_doom', content: {} },
                { id: 'b', type: 'product_rail', content: { title: 'Empty' }, products: [] },
                { id: 'c', type: 'hero', content: { slides: [{ sub: 'no headline' }] } },
                { id: 'd', type: 'faq', content: { title: 'FAQ', items: [{ question: 'Q' }] } },
                'nonsense',
                { id: 'e', type: 'rich_text', content: { html: '<p>ok</p>' } },
            ],
        });
        expect(home.blocks.map((b) => b.id)).toEqual(['e']);
        expect(normalizeHome(null)).toEqual({ version: null, preview: false, blocks: [] });
    });

    it('never passes on an unsafe link', () => {
        expect(safeHref('/toys?age=24-48')).toBe('/toys?age=24-48');
        expect(safeHref('https://example.com/a')).toBe('https://example.com/a');
        for (const bad of ['javascript:alert(1)', '//evil.example', 'http://plain.example', ' /space', 'data:text/html,x']) expect(safeHref(bad)).toBeNull();
        const hero = normalizeHome({ blocks: [{ id: 'h', type: 'hero', content: { slides: [{ headline: 'x', cta_label: 'Go', cta_href: 'javascript:alert(1)' }] } }] }).blocks[0] as Extract<HomeBlock, { type: 'hero' }>;
        expect(hero.content.slides[0]).toMatchObject({ cta_href: null, cta_label: null });
    });

    it('maps the device switches to the 1024 breakpoint', () => {
        expect(deviceClass({ show_mobile: true, show_desktop: true })).toBe('');
        expect(deviceClass({ show_mobile: false, show_desktop: true })).toBe('max-lg:hidden');
        expect(deviceClass({ show_mobile: true, show_desktop: false })).toBe('lg:hidden');
        expect(deviceClass({ show_mobile: false, show_desktop: false })).toBe('hidden');
    });
});

describe('preview token pre-check', () => {
    const sig = 'a'.repeat(64);
    it('accepts only a well-formed, unexpired homepage token', () => {
        expect(previewTokenLifetime(`home_draft.4.1000.${sig}`, 400)).toBe(600);
        expect(previewTokenLifetime(`home_draft.4.1000.${sig}`, 1000)).toBeNull(); // expired
        expect(previewTokenLifetime(`blog_post.4.1000.${sig}`, 400)).toBeNull(); // another type
        expect(previewTokenLifetime('home_draft.4.1000.short', 400)).toBeNull();
        expect(previewTokenLifetime(null, 400)).toBeNull();
        expect(HOME_PREVIEW_COOKIE).toBe('tm_home_preview');
    });
});

describe('HomeBlocks', () => {
    it('renders every block type in English', () => {
        const html = render('en');
        expect(html).toContain('<h1 id="hero-h-0-title" class="t-display-1 text-fg-primary">Eid toys</h1>');
        expect(html).toContain('fetchPriority="high"'); // the first hero image is the LCP element
        expect(html).toContain('href="/toys?age=24-48"');
        expect(html).toContain('href="/categories/puzzles"');
        expect(html).toContain('1–2 years');
        expect(html).toContain('href="/products/wooden-train"');
        expect(html).toContain('href="/toys?sort=newest"');
        expect(html).toContain('href="/brands/lego"');
        expect(html).toContain('09610-123456');
        expect(html).toContain('href="https://example.com/x" rel="noopener"');
        expect(html).toContain('lg:flex-row-reverse');
        expect(html).toContain('Rated 5 out of 5');
        expect(html).toContain('href="/guides/first-toys"');
        expect(html).toContain('4 min read');
        expect(html).not.toContain('<script>alert');
        expect(html).toContain('"@type":"FAQPage"');
        for (const type of ['hero', 'category_tiles', 'age_tiles', 'product_rail', 'brand_strip', 'usp_band', 'promo_split', 'testimonials', 'blog_teaser', 'rich_text', 'faq']) {
            expect(html).toContain(`data-block="${type}"`);
        }
    });

    it('renders every block type in Bangla, under /bn and with the Bangla line height', () => {
        const html = render('bn');
        expect(html).toContain('ঈদের খেলনা');
        expect(html).toContain('href="/bn/toys?age=24-48"');
        expect(html).toContain('href="/bn/categories/puzzles"');
        expect(html).toContain('পাজল');
        expect(html).toContain('কাঠের ট্রেন');
        expect(html).toContain('href="/bn/guides/first-toys"');
        expect(html).toContain('প্রথম খেলনা');
        expect(html).toContain('lang-bn');
        expect(html).toContain(bn.home.ageChooser);
        expect(html).not.toMatch(/[০-৯]/); // Western numerals in UI chrome (rule 36)
    });

    it('hides a block per device with a class, never by leaving it out', () => {
        const blocks = normalizeHome({ blocks: [{ id: 'f', type: 'faq', show_mobile: false, content: { title: 'FAQ', items: [{ question: 'Q', answer: 'A' }] } }] }).blocks;
        expect(renderToStaticMarkup(createElement(HomeBlocks, { blocks, locale: 'en' }))).toContain('max-lg:hidden');
    });

    it('has every new home string in both catalogues', () => {
        for (const key of ['previewBanner', 'previewExit', 'slidesLabel', 'slideOf', 'ratingLabel', 'readingTime'] as const) {
            expect(en.home[key].length).toBeGreaterThan(0);
            expect(bn.home[key].length).toBeGreaterThan(0);
            expect(bn.home[key]).not.toMatch(/[০-৯]/);
        }
    });
});
