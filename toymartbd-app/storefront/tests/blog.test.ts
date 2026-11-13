import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { BlogArticle } from '@/components/blog/BlogArticle';
import { BlogCard } from '@/components/blog/BlogCard';
import { BlogPagination } from '@/components/blog/BlogPagination';
import { listMetadata, postMetadata } from '@/components/blog/pages';
import type { SeoDocument } from '@/lib/seo/types';
import { BLOG_TAG, blogPostTags, getBlogTeaser, getPost, getPreview, listPosts } from '@/lib/blog/fetch';
import { budgetLabel, pagePath, parsePage, postDate, readTime } from '@/lib/blog/format';
import { blogMessages } from '@/lib/blog/messages';
import { postPath, type BlogCard as Card, type BlogPost } from '@/lib/blog/types';
import { validateJsonLd } from '@/lib/seo/validate';
import { articleJsonLd } from '@/lib/seo/jsonld';

// Phase 7 blog — storefront side: fetch tags (ISR), pagination, locale fallbacks, sanitisation,
// guide picks, metadata and Article schema.

vi.mock('next/headers', () => ({ headers: async () => new Headers() }));

const fetchMock = vi.fn<typeof fetch>();
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

const card: Card = {
    id: 7,
    type: 'article',
    slug: 'best-toys-3-5',
    title: 'Best toys for 3–5 year olds',
    title_bn: '৩–৫ বছরের সেরা খেলনা',
    excerpt: 'What Dhaka parents told us.',
    excerpt_bn: null,
    cover: { media_id: 1, url: 'https://cdn.test/m/1/800.webp', srcset_webp: null, srcset_avif: null, alt: 'Puzzle', alt_bn: 'পাজল', width: 800, height: 450 },
    category: { name: 'Buying guides', name_bn: 'কেনার গাইড', slug: 'buying-guides' },
    author: { name: 'Rina Akter', name_bn: null, slug: 'rina' },
    reading_minutes: 6,
    reading_minutes_bn: 7,
    published_at: '2026-11-07T19:00:00+00:00',
    is_featured: false,
};

const productCard = {
    id: 3, slug: 'wooden-puzzle', name: 'Wooden Alphabet Puzzle', name_bn: 'কাঠের বর্ণমালা পাজল', brand: null, price: '650.00', compare_at_price: null, price_max: '650.00',
    discount_percent: null, age_min_months: 36, age_max_months: 60, rating_avg: null, rating_count: 0, is_featured: false, variant_count: 1, in_stock: true, image: null, flash_sale: null,
};

const post: BlogPost = {
    ...card,
    type: 'buying_guide',
    body: '<p>Choosing is hard.</p><script>alert(1)</script><p onclick="x()">Hi</p>',
    body_bn: '<p>বাছাই করা কঠিন।</p>',
    tags: [{ name: 'STEM', name_bn: null, slug: 'stem' }],
    author: { name: 'Rina Akter', name_bn: 'রিনা আক্তার', slug: 'rina', bio: 'Parent of two.', bio_bn: null, avatar: null },
    guide_age_band: '36-60',
    guide_budget_min: null,
    guide_budget_max: '1500.00',
    sections: [
        { heading: 'Under ৳1,000', heading_bn: '৳1,000-এর নিচে', body: '<p>Small budgets.</p>', body_bn: null, age_band: '24-48', budget_min: null, budget_max: '1000.00', picks: [{ note: 'Thick pieces', note_bn: 'মোটা টুকরো', product: productCard }] },
    ],
    related_products: [],
    related_posts: [{ ...card, id: 8, slug: 'lego-real-or-fake' }],
    seo: { title: null, title_bn: 'সেরা খেলনা', description: null, description_bn: null, canonical_url: null, robots: 'index,follow', og_image: null },
    modified_at: '2026-11-10T04:00:00+00:00',
} as BlogPost;

describe('blog fetch (ISR tags)', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
        vi.stubEnv('API_URL', 'https://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'tok');
    });

    it('tags lists with content:blog and asks for numbered pages', async () => {
        fetchMock.mockResolvedValue(json({ data: [card], meta: { current_page: 2, last_page: 3, total: 30 } }));
        const page = await listPosts({ page: 2, tag: 'stem' }, 'bn');

        const [url, init] = fetchMock.mock.calls[0]!;
        expect(String(url)).toBe('https://api.test/api/v1/storefront/blog/posts?page=2&per_page=12&filter%5Btag%5D=stem');
        expect((init as RequestInit & { next?: { tags: string[] } }).next?.tags).toEqual([BLOG_TAG]);
        expect(page).toEqual({ posts: [card], page: 2, lastPage: 3, total: 30 });
    });

    it('returns null for a page past the end (the route 404s)', async () => {
        fetchMock.mockResolvedValue(json({ data: [], meta: { current_page: 9, last_page: 3, total: 30 } }));
        expect(await listPosts({ page: 9 }, 'en')).toBeNull();
    });

    it('tags a post page with the list, its slug and products (live picks)', async () => {
        fetchMock.mockResolvedValue(json(post));
        await getPost('best-toys-3-5', 'en');
        const init = fetchMock.mock.calls[0]![1] as RequestInit & { next?: { tags: string[] } };
        expect(init.next?.tags).toEqual(['content:blog', 'post:best-toys-3-5', 'products']);
        expect(blogPostTags('x')).toEqual(['content:blog', 'post:x', 'products']);
    });

    it('never calls the API for a malformed slug or token, and maps 404 to null', async () => {
        expect(await getPost('../etc', 'en')).toBeNull();
        expect(await getPreview('nope', 'en')).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
        fetchMock.mockResolvedValue(json({ type: 'x', title: 'Not Found', status: 404 }, 404));
        expect(await getPost('gone', 'en')).toBeNull();
    });

    it('fetches a preview uncached', async () => {
        fetchMock.mockResolvedValue(json(post));
        await getPreview(`blog_post.7.1999999999.${'a'.repeat(64)}`, 'en');
        expect((fetchMock.mock.calls[0]![1] as RequestInit).cache).toBe('no-store');
    });

    it('serves the teaser for the home page and degrades to nothing on failure', async () => {
        fetchMock.mockResolvedValue(json({ data: [card] }));
        expect(await getBlogTeaser('en')).toEqual([card]);
        fetchMock.mockRejectedValue(new Error('down'));
        expect(await getBlogTeaser('en')).toEqual([]);
    });
});

describe('blog format helpers', () => {
    it('parses ?page strictly and keeps page 1 on the bare URL', () => {
        expect(parsePage(undefined)).toBe(1);
        expect(parsePage('3')).toBe(3);
        expect(parsePage('0')).toBeNull();
        expect(parsePage('2x')).toBeNull();
        expect(parsePage(['2', '3'])).toBeNull();
        expect(pagePath('/blog', 1)).toBe('/blog');
        expect(pagePath('/blog', 2)).toBe('/blog?page=2');
    });

    it('formats Dhaka dates with Western digits in both locales', () => {
        // 19:00 UTC on 7 Nov is 01:00 on 8 Nov in Dhaka.
        expect(postDate(card.published_at, 'en')).toMatch(/^8 \S+ 2026$/);
        expect(postDate(card.published_at, 'bn')).toMatch(/^8 \S+ 2026$/);
        expect(postDate(card.published_at, 'bn')).not.toMatch(/[০-৯]/);
    });

    it('reads time per locale and budgets from the API strings', () => {
        expect(readTime(card, 'en', blogMessages('en'))).toBe('6 min read');
        expect(readTime(card, 'bn', blogMessages('bn'))).toContain('7');
        expect(budgetLabel(null, '1000.00', blogMessages('en'))).toBe('Budget Up to ৳1,000');
        expect(budgetLabel('500.00', '1500.50', blogMessages('en'))).toBe('Budget ৳500–৳1,500.50');
    });

    it('routes guides under /guides and articles under /blog', () => {
        expect(postPath(card)).toBe('/blog/best-toys-3-5');
        expect(postPath({ ...card, type: 'buying_guide' })).toBe('/guides/best-toys-3-5');
    });

    it('has the same message keys in English and Bangla', () => {
        expect(Object.keys(blogMessages('bn')).sort()).toEqual(Object.keys(blogMessages('en')).sort());
    });
});

describe('blog rendering', () => {
    beforeEach(() => vi.stubEnv('API_URL', 'https://api.test'));

    it('renders a guide: body re-sanitised, Bangla body with lang-bn, sections with live pick cards', () => {
        const html = renderToStaticMarkup(createElement(BlogArticle, { post, locale: 'bn', t: blogMessages('bn') }));
        expect(html).toContain('বাছাই করা কঠিন।');
        expect(html).toContain('lang-bn');
        expect(html).not.toContain('<script');
        expect(html).toContain('৳1,000-এর নিচে');
        expect(html).toContain('কাঠের বর্ণমালা পাজল');
        expect(html).toContain('মোটা টুকরো');
        expect(html).toContain('/bn/blog/tag/stem');
        expect(html).toContain('/bn/blog/lego-real-or-fake');
    });

    it('falls back to the English body with lang="en" on a Bangla page without a translation', () => {
        const html = renderToStaticMarkup(createElement(BlogArticle, { post: { ...post, body_bn: null }, locale: 'bn', t: blogMessages('bn') }));
        expect(html).toContain('lang="en"');
        expect(html).toContain('Choosing is hard.');
        expect(html).not.toContain('onclick');
    });

    it('renders a card with category and read time, linking in the page locale', () => {
        const html = renderToStaticMarkup(createElement(BlogCard, { post: card, locale: 'bn', t: blogMessages('bn') }));
        expect(html).toContain('href="/bn/blog/best-toys-3-5"');
        expect(html).toContain('কেনার গাইড');
        expect(html).toContain('৩–৫ বছরের সেরা খেলনা');
    });

    it('paginates with real links and rel=prev/next', () => {
        const html = renderToStaticMarkup(createElement(BlogPagination, { locale: 'en', path: '/blog', page: 2, lastPage: 3, total: 30, perPage: 12, t: blogMessages('en') }));
        expect(html).toContain('<link rel="prev" href="/blog"/>');
        expect(html).toContain('<link rel="next" href="/blog?page=3"/>');
        expect(html).toContain('aria-current="page"');
        expect(html).toContain('Showing 13–24 of 30 posts');
    });
});

describe('blog metadata and schema', () => {
    beforeEach(() => vi.stubEnv('SITE_URL', 'https://toymartbd.com'));

    it('builds per-locale metadata with the seo override, canonical and hreflang', () => {
        const bn = postMetadata(post, 'bn');
        expect(bn.title).toBe('সেরা খেলনা');
        expect(bn.alternates?.canonical).toBe('/bn/guides/best-toys-3-5');
        expect(bn.alternates?.languages).toMatchObject({ en: '/guides/best-toys-3-5', bn: '/bn/guides/best-toys-3-5', 'x-default': '/guides/best-toys-3-5' });
        expect(postMetadata(post, 'en').title).toBe('Best toys for 3–5 year olds');
        expect(postMetadata(post, 'en', { preview: true }).robots).toEqual({ index: false, follow: false });
    });

    it('overlays a blog archive SEO document on page 1 only, keeping hreflang both ways', async () => {
        const doc: SeoDocument = {
            type: 'blog_category',
            slug: 'stem',
            path: '/blog/category/stem',
            robots: { index: false, follow: true },
            canonical_url: null,
            sitemap_exclude: false,
            twitter_card: 'summary_large_image',
            og_image: null,
            en: { title: 'STEM toys guide | Toy Mart BD', description: 'Admin description', og_title: 'STEM', og_description: 'OG' },
            bn: { title: 'স্টেম খেলনা | Toy Mart BD', description: 'বিবরণ', og_title: 'স্টেম', og_description: 'ওজি' },
            updated_at: null,
        };
        const spec = { locale: 'bn' as const, path: '/blog/category/stem', page: 1, title: 'Category: STEM', description: 'Default', seo: doc };
        const first = await listMetadata(spec);
        expect(first.title).toEqual({ absolute: 'স্টেম খেলনা | Toy Mart BD' });
        expect(first.description).toBe('বিবরণ');
        expect(first.robots).toEqual({ index: false, follow: true });
        expect(first.alternates?.languages).toMatchObject({ en: '/blog/category/stem', bn: '/bn/blog/category/stem', 'x-default': '/blog/category/stem' });

        const second = await listMetadata({ ...spec, page: 2 });
        expect(typeof second.title).toBe('string'); // its own "page 2" title, the layout adds the site name
        expect(second.alternates?.canonical).toBe('/bn/blog/category/stem?page=2');
        expect(second.robots).toEqual({ index: false, follow: true }); // a noindex still applies

        const none = await listMetadata({ ...spec, seo: null });
        expect(none.title).toBe('Category: STEM');
    });

    it('emits valid Article JSON-LD', () => {
        const node = articleJsonLd({ locale: 'en', path: postPath(card), headline: card.title, images: [card.cover!.url], datePublished: card.published_at!, author: { name: 'Rina Akter' } });
        expect(validateJsonLd(node).errors).toEqual([]);
    });
});
