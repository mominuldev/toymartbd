import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { renderApp, viewer } from '@/test/renderApp';
import { storeProductRequestSchema } from '@toymart/api-types/zod';
import { listItem, owner, product, productApi, sentBody, unprocessable } from './fixtures';
import { formatMoney } from './document';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const urls = (calls: ReturnType<typeof productApi>) => calls.mock.calls.map(([url]) => String(url));

describe('products list', () => {
    it('shows EN + BN names and the server’s price range as given', async () => {
        productApi(owner);
        renderApp('/products');

        const table = await screen.findByRole('table');
        expect(within(table).getByRole('link', { name: 'Brick Box 500' })).toBeTruthy();
        expect(within(table).getByText('ব্রিক বক্স 500')).toBeTruthy();
        // The taka sign is its own span (Admin/currency/num), so match the cell's whole text.
        expect(within(table).getByText((_, el) => el?.tagName === 'TD' && el.textContent === '৳1,250 – ৳2,400.50')).toBeTruthy();
        expect(within(table).getByText('Toy Mart Originals')).toBeTruthy();
        expect(await within(table).findByText('Building & Construction')).toBeTruthy();
        expect(within(table).getByText('Published')).toBeTruthy();
    });

    it('filters, searches and sorts on the server, with the filters in the URL', async () => {
        const calls = productApi(owner);
        renderApp('/products?type=variable');

        await screen.findByRole('table');
        expect(urls(calls).some((u) => u.includes('filter%5Btype%5D=variable'))).toBe(true);

        fireEvent.click(screen.getByRole('button', { name: 'Drafts' }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Bstatus%5D=draft&filter%5Btype%5D=variable'))).toBe(true));

        fireEvent.change(screen.getByRole('searchbox', { name: 'Search products' }), { target: { value: 'brick' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Bsearch%5D=brick'))).toBe(true));

        fireEvent.change(screen.getByLabelText('Sort'), { target: { value: 'name' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('sort=name'))).toBe(true));

        fireEvent.change(screen.getByLabelText('Brand'), { target: { value: '3' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Bbrand_id%5D=3'))).toBe(true));
    });

    it('loads more with the cursor', async () => {
        const calls = productApi(owner, (url) => {
            if (url.includes('cursor=abc')) return { status: 200, body: { data: [{ ...listItem, id: 43, name: 'Puzzle 100' }], links: { next: null }, meta: { next_cursor: null } } };
            if (url.includes('/products?') || url.endsWith('/products')) return { status: 200, body: { data: [listItem], links: { next: null }, meta: { next_cursor: 'abc' } } };
            return undefined;
        });
        renderApp('/products');

        fireEvent.click(await screen.findByRole('button', { name: 'Load more' }));
        expect(await screen.findByRole('link', { name: 'Puzzle 100' })).toBeTruthy();
        expect(urls(calls).some((u) => u.includes('cursor=abc'))).toBe(true);
    });

    it('hides Add product from a read-only role', async () => {
        productApi(viewer);
        renderApp('/products');
        await screen.findByRole('table');
        expect(screen.queryByRole('link', { name: 'Add product' })).toBeNull();
    });

    it('formats money strings without turning them into numbers', () => {
        expect(formatMoney('1250.00')).toBe('1,250');
        expect(formatMoney('1234567.5')).toBe('1,234,567.5');
        expect(formatMoney('99')).toBe('99');
    });
});

describe('product form', () => {
    it('validates with the generated schema before calling the API', async () => {
        const calls = productApi(owner);
        renderApp('/products/new');

        fireEvent.click(await screen.findByRole('button', { name: 'Create product' }));

        expect((await screen.findAllByText('This field is required.')).length).toBeGreaterThan(0);
        expect(screen.getByLabelText(/^Name \(English\)/).getAttribute('aria-invalid')).toBe('true');
        expect(sentBody(calls, 'POST')).toBeNull();
        // The section tab with the error is marked.
        expect(screen.getByRole('link', { name: /General.*has errors/ })).toBeTruthy();
    });

    it('rejects a malformed money string on the field (generated money pattern)', async () => {
        const calls = productApi(owner);
        renderApp('/products/new?tab=pricing');

        fireEvent.change(await screen.findByLabelText(/^Price/), { target: { value: '1,250' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create product' }));
        // Every section is on the page; General (the name is missing) and Pricing are both marked.
        expect(await screen.findByRole('link', { name: /Pricing.*has errors/ })).toBeTruthy();
        expect(screen.getByRole('link', { name: /General.*has errors/ })).toBeTruthy();

        expect(await screen.findByText('This format is not valid.')).toBeTruthy();
        expect(screen.getByLabelText(/^Price/).getAttribute('aria-invalid')).toBe('true');
        expect(sentBody(calls, 'POST')).toBeNull();
    });

    it('creates a simple product: money as strings, blank SKU left to the server', async () => {
        let posted: Record<string, unknown> | null = null;
        const calls = productApi(owner, (url, init) => {
            if (url.endsWith('/products') && init?.method === 'POST') {
                posted = JSON.parse(String(init.body)) as Record<string, unknown>;
                return { status: 201, body: { ...product, id: 42, type: 'simple', options: [], variants: [{ ...product.variants[0]!, attribute_values: [] }] } };
            }
            return undefined;
        });
        renderApp('/products/new');

        fireEvent.change(await screen.findByLabelText(/^Name \(English\)/), { target: { value: 'Wooden Puzzle' } });
        fireEvent.change(screen.getByLabelText('নাম (বাংলা)'), { target: { value: 'কাঠের ধাঁধা' } });
        await screen.findByRole('option', { name: /Building & Construction/ });
        fireEvent.change(screen.getByLabelText('Category'), { target: { value: '5' } });
        fireEvent.change(screen.getByLabelText(/^Price/), { target: { value: '650' } });
        fireEvent.change(screen.getByLabelText(/^Cost price/), { target: { value: '400.50' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create product' }));

        await waitFor(() => expect(posted).not.toBeNull());
        expect(posted).toMatchObject({
            name: 'Wooden Puzzle',
            name_bn: 'কাঠের ধাঁধা',
            type: 'simple',
            category_ids: [5],
            primary_category_id: 5,
            options: [],
            variants: [{ sku: null, price: '650', cost_price: '400.50', compare_at_price: null }],
        });
        expect(storeProductRequestSchema.safeParse(posted).success).toBe(true);
        expect(await screen.findByText('Product created.')).toBeTruthy();
        expect(sentBody(calls, 'POST')).toEqual(posted);
    });

    it('loads the saved categories and primary category once the tree arrives', async () => {
        productApi(owner);
        renderApp('/products/42');

        await screen.findByRole('option', { name: /Building & Construction/ });
        await waitFor(() => expect((screen.getByLabelText('Category') as HTMLSelectElement).value).toBe('5'));
        expect((screen.getByLabelText(/^Brand/) as HTMLSelectElement).value).toBe('3');
        expect((screen.getByLabelText('Minimum age') as HTMLSelectElement).value).toBe('48');
        expect(screen.getByRole('option', { name: '4 years', selected: true })).toBeTruthy();
    });

    it('duplicates and opens the copy', async () => {
        productApi(owner, (url, init) => {
            if (url.endsWith('/products/42/duplicate') && init?.method === 'POST') return { status: 201, body: { ...product, id: 77, name: 'Brick Box 500 (copy)', status: 'draft' } };
            if (url.endsWith('/products/77')) return { status: 200, body: { ...product, id: 77, name: 'Brick Box 500 (copy)', status: 'draft' } };
            return undefined;
        });
        renderApp('/products/42');

        fireEvent.click(await screen.findByRole('button', { name: 'Duplicate' }));
        expect(await screen.findByRole('heading', { name: 'Brick Box 500 (copy)' }, { timeout: 3000 })).toBeTruthy();
        expect(screen.getByText('Copy created as a draft.')).toBeTruthy();
    });

    it('archives only after the product name is typed', async () => {
        let deleted = false;
        productApi(owner, (url, init) => {
            if (url.endsWith('/products/42') && init?.method === 'DELETE') {
                deleted = true;
                return { status: 204 };
            }
            return undefined;
        });
        renderApp('/products/42');

        fireEvent.click((await screen.findAllByRole('button', { name: 'Archive product' }))[0]!);
        const confirm = screen.getAllByRole('button', { name: 'Archive product' }).at(-1)!;
        expect(confirm.hasAttribute('disabled')).toBe(true);
        fireEvent.change(screen.getByLabelText('Type Brick Box 500 to confirm'), { target: { value: 'Brick Box 500' } });
        fireEvent.click(confirm);

        await waitFor(() => expect(deleted).toBe(true));
        expect(await screen.findByText('Product archived.')).toBeTruthy();
    });

    it('puts an upsell 422 on its relation row', async () => {
        productApi(owner, (url, init) => {
            if (url.includes('filter%5Bsearch%5D=puz')) return { status: 200, body: { data: [{ ...listItem, id: 50, name: 'Puzzle 100', sku: 'TM-00050' }], links: {}, meta: { next_cursor: null } } };
            if (url.endsWith('/products/42') && init?.method === 'PUT') return unprocessable([{ field: 'relations.0.related_product_id', message: 'An upsell must cost more than this product.' }]);
            return undefined;
        });
        renderApp('/products/42?tab=related');

        fireEvent.click(await screen.findByRole('button', { name: 'Add product' }));
        fireEvent.change(screen.getByLabelText('Recommendation type'), { target: { value: 'upsell' } });
        fireEvent.change(screen.getByRole('searchbox', { name: 'Search products to add to Upsell' }), { target: { value: 'puz' } });
        fireEvent.click(await screen.findByRole('button', { name: /Puzzle 100/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        const alert = await screen.findByText('An upsell must cost more than this product.');
        expect(alert.closest('li')?.textContent).toContain('Puzzle 100');
        expect(screen.getByRole('link', { name: /Related.*has errors/ })).toBeTruthy();
    });

    it('embeds the image uploader on a saved product, and asks to save first on a new one', async () => {
        productApi(owner);
        renderApp('/products/42?tab=images');
        expect(await screen.findByRole('region', { name: 'Product images' })).toBeTruthy();
        cleanup();

        productApi(owner);
        renderApp('/products/new?tab=images');
        expect(await screen.findByText('Save the product first to add images.')).toBeTruthy();
    });

    it('shows every section on one page (Figma 241:37883) and saves Featured from its switch', { timeout: 20_000 }, async () => {
        const calls = productApi(owner, (url, init) => (url.endsWith('/products/42') && init?.method === 'PUT' ? { status: 200, body: product } : undefined));
        renderApp('/products/42');

        // Tabs jump to sections; General, Media, Variants and Recommendations are all rendered.
        expect(await screen.findByRole('link', { name: 'General' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Media' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Related' })).toBeTruthy();
        expect(await screen.findByRole('region', { name: 'Product images' })).toBeTruthy();
        expect(screen.getByRole('region', { name: 'Recommendations' })).toBeTruthy();
        expect(await screen.findByRole('heading', { name: 'Variants' })).toBeTruthy();

        const featured = screen.getByRole('switch', { name: 'Featured on the store' });
        const before = featured.getAttribute('aria-checked') === 'true';
        fireEvent.click(featured);
        expect(screen.getByText('1 unsaved change')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Save and add another' }));

        await waitFor(() => expect(sentBody(calls, 'PUT')).not.toBeNull());
        expect(sentBody(calls, 'PUT')).toMatchObject({ is_featured: !before });
        expect(await screen.findByRole('heading', { name: 'Add product' }, { timeout: 5_000 })).toBeTruthy();
    });

    it('shows the form in Bangla', async () => {
        productApi(owner);
        renderApp('/products/new', { locale: 'bn' });
        expect(await screen.findByLabelText(/^নাম \(ইংরেজি\)/)).toBeTruthy();
        expect(screen.getByRole('link', { name: 'দাম' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'মিডিয়া' })).toBeTruthy();
    });
});
