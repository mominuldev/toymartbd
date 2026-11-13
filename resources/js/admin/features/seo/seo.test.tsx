import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Redirect, RobotsTxt, SeoDocument, SitemapStatus, SlugRedirect } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const editor: Me = { ...viewer, roles: ['content_editor'], permissions: ['dashboard.view', 'catalog.view', 'catalog.update', 'seo.view', 'seo.manage'] };
const seoViewer: Me = { ...viewer, permissions: [...viewer.permissions, 'seo.view'] };

const brand = {
    id: 1,
    name: 'LEGO',
    name_bn: 'লেগো',
    slug: 'lego',
    description: null,
    description_bn: null,
    is_active: true,
    logo_url: null,
    created_at: '2026-11-10T18:30:00Z',
    updated_at: '2026-11-10T18:30:00Z',
};

const serp = (title: string, display: string, truncated: boolean, url: string) => ({
    title,
    title_display: display,
    title_px: truncated ? 720 : 300,
    title_max: 580,
    title_truncated: truncated,
    description: 'Genuine LEGO sets.',
    description_display: 'Genuine LEGO sets.',
    description_px: 150,
    description_max: 920,
    description_truncated: false,
    url_display: url,
});

const doc: SeoDocument = {
    type: 'brand',
    id: 1,
    slug: 'lego',
    path: '/brands/lego',
    is_live: true,
    urls: { en: 'https://toymartbd.com/brands/lego', bn: 'https://toymartbd.com/bn/brands/lego' },
    title: 'A very long LEGO brand title that Google will certainly cut',
    title_bn: null,
    description: null,
    description_bn: null,
    og_title: null,
    og_title_bn: null,
    og_description: null,
    og_description_bn: null,
    og_image: null,
    canonical_url: null,
    robots: 'index,follow',
    sitemap_exclude: false,
    twitter_card: 'summary_large_image',
    focus_keyword: null,
    defaults: { title: 'LEGO | Toy Mart BD', title_bn: 'লেগো | Toy Mart BD', description: 'Genuine LEGO sets.', description_bn: 'আসল লেগো সেট।', og_image: null },
    serp: {
        en: serp('A very long LEGO brand title that Google will certainly cut', 'A very long LEGO brand title that Google …', true, 'toymartbd.com › brands › lego'),
        bn: serp('লেগো | Toy Mart BD', 'লেগো | Toy Mart BD', false, 'toymartbd.com › bn › brands › lego'),
    },
    in_sitemap: true,
};

function brandApi(me: Me, onPut?: (body: unknown) => { status: number; body?: unknown }) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/brands/1')) return { status: 200, body: brand };
        if (url.endsWith('/seo/meta/brand/1') && init?.method === 'PUT') return onPut ? onPut(JSON.parse(String(init.body))) : { status: 200, body: doc };
        if (url.endsWith('/seo/meta/brand/1')) return { status: 200, body: doc };
        if (url.endsWith('/seo/serp-preview')) return { status: 200, body: doc.serp.en };
        return { status: 404 };
    });
}

describe('SeoPanel', () => {
    it('loads the document and shows the server’s truncation, per locale', async () => {
        brandApi(editor);
        renderApp('/products/brands/1');

        const preview = await screen.findByTestId('serp-preview');
        expect(within(preview).getByText('A very long LEGO brand title that Google …')).toBeTruthy();
        expect(within(preview).getByText('toymartbd.com › brands › lego')).toBeTruthy();
        expect(screen.getByText('Title 720 / 580 px · Google will cut it')).toBeTruthy();

        fireEvent.click(screen.getByRole('tab', { name: 'বাংলা' }));
        const bnPreview = await screen.findByTestId('serp-preview');
        expect(within(bnPreview).getByText('toymartbd.com › bn › brands › lego')).toBeTruthy();
        expect((screen.getByLabelText(/Meta title \(বাংলা\)/) as HTMLInputElement).placeholder).toBe('লেগো | Toy Mart BD');
    });

    it('saves the whole document with empty fields as null', async () => {
        let sent: Record<string, unknown> | null = null;
        brandApi(editor, (body) => {
            sent = body as Record<string, unknown>;
            return { status: 200, body: { ...doc, og_title_bn: 'টয় মার্টে লেগো' } };
        });
        renderApp('/products/brands/1');

        fireEvent.click(await screen.findByRole('tab', { name: 'বাংলা' }));
        fireEvent.change(screen.getByLabelText(/Share title \(বাংলা\)/), { target: { value: 'টয় মার্টে লেগো' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save SEO' }));

        expect(await screen.findByText('SEO saved.')).toBeTruthy();
        expect(sent).toMatchObject({ og_title_bn: 'টয় মার্টে লেগো', title_bn: null, robots: 'index,follow', title: doc.title });
    });

    it('puts a 422 on the matching field', async () => {
        brandApi(editor, () => ({
            status: 422,
            body: { type: 'https://toymartbd.com/problems/validation-error', title: 'Validation failed', status: 422, errors: [{ field: 'title', message: 'Too long for the server.' }] },
        }));
        renderApp('/products/brands/1');
        fireEvent.click(await screen.findByRole('button', { name: 'Save SEO' }));
        expect(await screen.findByText('Too long for the server.')).toBeTruthy();
    });

    it('is read-only without seo.manage', async () => {
        brandApi(seoViewer);
        renderApp('/products/brands/1');
        await screen.findByTestId('serp-preview');
        expect(screen.queryByRole('button', { name: 'Save SEO' })).toBeNull();
        expect(screen.getByLabelText(/^Meta title(?! \()/).matches(':disabled')).toBe(true);
    });
});

const manualRow: Redirect = {
    id: 5,
    from_path: '/sale',
    to_path: '/eid-collection',
    status_code: 302,
    is_active: true,
    source: 'manual',
    hit_count: 1208,
    last_hit_at: '2026-11-12T10:00:00Z',
    note: 'Campaign',
    created_at: '2026-11-01T10:00:00Z',
    updated_at: '2026-11-01T10:00:00Z',
};
const autoRow: SlugRedirect = {
    id: 9,
    type: 'product',
    entity_id: 3,
    entity_name: 'Classic brick box',
    old_slug: 'brick-box-500-pcs',
    new_slug: 'classic-brick-box-500',
    from_path: '/products/brick-box-500-pcs',
    to_path: '/products/classic-brick-box-500',
    status_code: 301,
    created_at: '2026-11-01T10:00:00Z',
};
const paged = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length } });

function redirectsApi(extra?: (url: string, init?: RequestInit) => { status: number; body?: unknown } | null) {
    return mockApi((url, init) => {
        const hit = extra?.(url, init);
        if (hit) return hit;
        if (url.endsWith('/auth/me')) return { status: 200, body: editor };
        if (url.includes('/seo/redirects?')) return { status: 200, body: paged([manualRow]) };
        if (url.includes('/seo/slug-history')) return { status: 200, body: paged([autoRow]) };
        return { status: 404 };
    });
}

describe('RedirectsPage', () => {
    it('lists manual and automatic redirects with counts', async () => {
        redirectsApi();
        renderApp('/seo/redirects');

        const table = await screen.findByRole('table');
        expect(await within(table).findByText('/sale')).toBeTruthy();
        expect(within(table).getByText('/products/classic-brick-box-500')).toBeTruthy();
        expect(within(table).getByText('1,208')).toBeTruthy();
        expect(within(table).getByText('Slug change · auto · Classic brick box')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: /Manual/ }));
        await waitFor(() => expect(within(screen.getByRole('table')).queryByText('/products/classic-brick-box-500')).toBeNull());
    });

    it('shows the server’s loop error inline on quick add', async () => {
        redirectsApi((url, init) =>
            url.endsWith('/seo/redirects') && init?.method === 'POST'
                ? {
                      status: 422,
                      body: {
                          type: 'https://toymartbd.com/problems/validation-error',
                          title: 'Validation failed',
                          status: 422,
                          errors: [{ field: 'to_path', message: 'This redirect would create a loop: /b → /a → … → /b.' }],
                      },
                  }
                : null,
        );
        renderApp('/seo/redirects');
        await screen.findByText('/sale');

        const form = screen.getByRole('form', { name: 'Quick add a redirect' });
        fireEvent.change(within(form).getByLabelText('From path'), { target: { value: '/b' } });
        fireEvent.change(within(form).getByLabelText('To path or https:// URL'), { target: { value: '/a' } });
        fireEvent.click(within(form).getByRole('button', { name: 'Add' }));

        expect(await screen.findByText('This redirect would create a loop: /b → /a → … → /b.')).toBeTruthy();
    });

    it('validates with the generated schema before calling the API', async () => {
        const calls = redirectsApi();
        renderApp('/seo/redirects');
        await screen.findByText('/sale');
        const form = screen.getByRole('form', { name: 'Quick add a redirect' });
        fireEvent.click(within(form).getByRole('button', { name: 'Add' }));
        await waitFor(() => expect(within(form).getAllByText('This field is required.').length).toBe(2));
        expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'POST')).toBe(false);
    });

    it('lists every row error of a refused CSV import', async () => {
        redirectsApi((url, init) =>
            url.endsWith('/seo/redirects/import') && init?.method === 'POST'
                ? { status: 422, body: { created: 0, updated: 0, errors: [{ line: 3, field: 'from_path', message: '/cart is a built-in storefront route and cannot be redirected.' }] } }
                : null,
        );
        renderApp('/seo/redirects');
        fireEvent.click(await screen.findByRole('button', { name: 'Import CSV' }));

        const file = new File(['from_path,to_path,status_code\n/cart,/toys,301\n'], 'r.csv', { type: 'text/csv' });
        fireEvent.change(screen.getByLabelText('CSV file'), { target: { files: [file] } });
        fireEvent.click(screen.getByRole('button', { name: 'Import' }));

        expect(await screen.findByText('Nothing imported — 1 problems to fix:')).toBeTruthy();
        expect(screen.getByText('Row 3: /cart is a built-in storefront route and cannot be redirected.')).toBeTruthy();
    });

    it('hides write actions without seo.manage', async () => {
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: seoViewer };
            if (url.includes('/seo/redirects?')) return { status: 200, body: paged([manualRow]) };
            if (url.includes('/seo/slug-history')) return { status: 200, body: paged([]) };
            return { status: 404 };
        });
        renderApp('/seo/redirects');
        await screen.findByText('/sale');
        expect(screen.queryByRole('button', { name: 'Add redirect' })).toBeNull();
        expect(screen.queryByRole('form', { name: 'Quick add a redirect' })).toBeNull();
    });
});

const robots: RobotsTxt = {
    content: 'User-agent: *\nDisallow: /cart\n',
    is_default: true,
    default_content: 'User-agent: *\nAllow: /\n',
    sitemap_url: 'https://toymartbd.com/sitemap.xml',
    served: 'User-agent: *\nDisallow: /cart\n\nSitemap: https://toymartbd.com/sitemap.xml\n',
};
const status: SitemapStatus = {
    sitemap_url: 'https://toymartbd.com/sitemap.xml',
    generated_at: '2026-11-12T21:00:00Z',
    total_urls: 372,
    duration_ms: 420,
    files: [
        { file: 'sitemap-products-en-1.xml', partition: 'products', locale: 'en', part: 1, urls: 186, url: 'https://toymartbd.com/sitemaps/sitemap-products-en-1.xml' },
        { file: 'sitemap-products-bn-1.xml', partition: 'products', locale: 'bn', part: 1, urls: 186, url: 'https://toymartbd.com/sitemaps/sitemap-products-bn-1.xml' },
    ],
};

describe('Sitemap & robots.txt', () => {
    it('shows the build, queues a rebuild and lists robots errors line by line', async () => {
        mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/seo/sitemap')) return { status: 200, body: status };
            if (url.endsWith('/seo/sitemap/regenerate')) return { status: 202, body: { queued: true } };
            if (url.endsWith('/seo/robots') && init?.method === 'PUT')
                return {
                    status: 422,
                    body: {
                        type: 'https://toymartbd.com/problems/validation-error',
                        title: 'Validation failed',
                        status: 422,
                        errors: [
                            { field: 'content', message: 'Line 2: "Noindex" is not a supported directive.' },
                            { field: 'content', message: 'Line 3: a rule must follow a User-agent line.' },
                        ],
                    },
                };
            if (url.endsWith('/seo/robots')) return { status: 200, body: robots };
            return { status: 404 };
        });
        renderApp('/seo/sitemap');

        expect(await screen.findByText('sitemap-products-bn-1.xml')).toBeTruthy();
        expect(screen.getByTestId('robots-served').textContent).toContain('Sitemap: https://toymartbd.com/sitemap.xml');

        fireEvent.click(screen.getByRole('button', { name: 'Rebuild now' }));
        expect(await screen.findByText('Rebuild queued — the files update in a minute or two.')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Rules'), { target: { value: 'User-agent: *\nNoindex: /x\n' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save robots.txt' }));
        expect(await screen.findByText('Line 2: "Noindex" is not a supported directive.')).toBeTruthy();
        expect(screen.getByText('Line 3: a rule must follow a User-agent line.')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Reset to default' }));
        expect((screen.getByLabelText('Rules') as HTMLTextAreaElement).value).toBe('User-agent: *\nAllow: /\n');
    });

    it('groups sitemap files per type on the overview and never shows a zero for Search Console', async () => {
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: seoViewer };
            if (url.endsWith('/seo/sitemap')) return { status: 200, body: status };
            if (url.endsWith('/seo/robots')) return { status: 200, body: robots };
            return { status: 404 };
        });
        renderApp('/seo');
        expect(await screen.findByText('sitemap-products.xml')).toBeTruthy();
        expect(screen.getByText('186 URLs × 2 languages')).toBeTruthy();
        expect(screen.getByText('Search Console not connected')).toBeTruthy();
    });
});
