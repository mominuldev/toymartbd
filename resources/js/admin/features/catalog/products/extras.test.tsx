import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { renderApp } from '@/test/renderApp';
import type { Product } from './api';
import { contentEditor, owner, product, productApi, sentBody, variant } from './fixtures';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

/** The same product as a simple one (one default variant). */
const simple: Product = {
    ...product,
    type: 'simple',
    options: [],
    variants: [variant(100, [10, 20], { attribute_values: [], price: '1850.00', cost_price: '1120.00' })],
    relations: [
        {
            id: 7,
            related_product_id: 50,
            related_product: { id: 50, name: 'Brick Box 1000', name_bn: null, slug: 'brick-box-1000', status: 'active', image_url: 'https://cdn.example/brick-1000-160.webp', price: '3200.00' },
            type: 'upsell',
            position: 0,
            reason_note: null,
            is_auto: false,
        },
    ],
};

const serve = (body: Product) => (url: string, init?: RequestInit) => {
    if (url.endsWith('/products/42') && (!init?.method || init.method === 'GET')) return { status: 200, body };
    if (url.endsWith('/products/42') && init?.method === 'PUT') return { status: 200, body };
    return undefined;
};

const urls = (calls: ReturnType<typeof productApi>) => calls.mock.calls.map(([url]) => String(url));

describe('product form extras (P4 batch 5)', () => {
    it('shows the server’s margin and sends channels, VAT and BSTI with the document', async () => {
        const calls = productApi(owner, serve(simple));
        renderApp('/products/42');

        expect(await screen.findByText('Margin 39.5% · profit ৳730 per unit before delivery and COD costs')).toBeTruthy();
        await waitFor(() => expect(urls(calls).some((u) => u.endsWith('/products/margin-preview'))).toBe(true));

        fireEvent.click(screen.getByRole('switch', { name: 'Google Merchant feed' }));
        fireEvent.change(screen.getByLabelText('VAT'), { target: { value: '15' } });
        fireEvent.change(screen.getByLabelText('BSTI certificate no.'), { target: { value: 'CM-2026-09999' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(sentBody(calls, 'PUT')).not.toBeNull());
        expect(sentBody(calls, 'PUT')).toMatchObject({
            sales_channels: { online_store: true, pos: true, facebook_instagram: true, google_merchant: true },
            vat_rate: '15',
            compliance: { bsti_certificate_no: 'CM-2026-09999', bsti_expires_on: '2027-06-30' },
            seo: { title: null, robots: 'index,follow' },
        });
        // An unchanged slug carries no acknowledgement.
        expect(sentBody(calls, 'PUT')).not.toHaveProperty('acknowledge_slug_redirect');
    });

    it('lists stock by branch read-only, links to Inventory and shows the certificate file', async () => {
        productApi(owner, serve(simple));
        renderApp('/products/42');

        const card = await screen.findByRole('region', { name: 'Stock by branch' });
        expect(within(card).getByText('Dhaka HQ · Mirpur')).toBeTruthy();
        expect(within(card).getByText('2 reserved')).toBeTruthy();
        expect(within(card).getByRole('link', { name: 'Adjust stock' }).getAttribute('href')).toBe('/inventory');
        expect(within(card).queryByRole('textbox')).toBeNull();

        expect(screen.getByText('Expires 30 Jun 2027')).toBeTruthy();
        expect(screen.getByRole('link', { name: /bsti-cm-2026-04417\.pdf · 412 KB/ }).getAttribute('href')).toBe('/api/v1/admin/products/42/compliance/certificate');
    });

    it('requires the redirect acknowledgement before a live product’s slug changes', async () => {
        const calls = productApi(owner, serve(simple));
        renderApp('/products/42');

        const slug = await screen.findByLabelText('URL');
        expect(screen.queryByText('Changing this URL creates a 301 redirect')).toBeNull();
        fireEvent.change(slug, { target: { value: 'classic-brick-box-500' } });

        expect(await screen.findByText('Changing this URL creates a 301 redirect')).toBeTruthy();
        // The default matcher collapses the two spaces either side of the arrow.
        expect(screen.getByText('toymartbd.com/products/brick-box-500 → toymartbd.com/products/classic-brick-box-500')).toBeTruthy();
        fireEvent.click(screen.getByRole('checkbox', { name: 'I understand the old URL will redirect' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(sentBody(calls, 'PUT')).not.toBeNull());
        expect(sentBody(calls, 'PUT')).toMatchObject({ slug: 'classic-brick-box-500', acknowledge_slug_redirect: true });
    });

    it('renders the SEO panel from the server: meter, checklist, tabs', async () => {
        const calls = productApi(owner, serve(simple));
        renderApp('/products/42');

        const panel = await screen.findByRole('region', { name: 'Search & social' });
        expect(within(panel).getByText('280 / 580 px · 27 chars')).toBeTruthy();
        expect(within(panel).getByText('SEO checklist · 6 of 7')).toBeTruthy();
        expect(within(panel).getByText('2 images missing Bangla alt text')).toBeTruthy();
        expect(within(panel).getByText('Brick Box 500 | Toy Mart BD')).toBeTruthy();

        fireEvent.change(within(panel).getByRole('textbox', { name: 'Meta title' }), { target: { value: 'Brick Box 500 pcs — Classic Building Set' } });
        await waitFor(() => expect(urls(calls).some((u) => u.endsWith('/products/seo-measure'))).toBe(true));

        fireEvent.click(within(panel).getByRole('tab', { name: 'Advanced' }));
        fireEvent.change(within(panel).getByLabelText('Search engines'), { target: { value: 'noindex,follow' } });
        fireEvent.click(within(panel).getByRole('tab', { name: 'Schema' }));
        fireEvent.change(within(panel).getByLabelText('JSON-LD override'), { target: { value: '{"brand":{"name":"Toy Mart"}}' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(sentBody(calls, 'PUT')).not.toBeNull());
        expect(sentBody(calls, 'PUT')).toMatchObject({
            seo: { title: 'Brick Box 500 pcs — Classic Building Set', robots: 'noindex,follow', schema_override: { brand: { name: 'Toy Mart' } } },
        });
    });

    it('refuses a JSON-LD override that is not a JSON object, on the field', async () => {
        const calls = productApi(owner, serve(simple));
        renderApp('/products/42');

        const panel = await screen.findByRole('region', { name: 'Search & social' });
        fireEvent.click(within(panel).getByRole('tab', { name: 'Schema' }));
        fireEvent.change(within(panel).getByLabelText('JSON-LD override'), { target: { value: '[1, 2' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(await within(panel).findByText(/Enter a JSON object/)).toBeTruthy();
        expect(sentBody(calls, 'PUT')).toBeNull();
    });

    it('shows recommendation thumbnails and prices', async () => {
        productApi(owner, serve(simple));
        renderApp('/products/42');

        const card = await screen.findByRole('region', { name: 'Recommendations' });
        expect(within(card).getByText((_, el) => el?.tagName === 'P' && el.textContent === 'Brick Box 1000 — ৳3,200')).toBeTruthy();
        expect(card.querySelector('img')?.getAttribute('src')).toBe('https://cdn.example/brick-1000-160.webp');
    });

    it('adds a Margin column to the variant matrix from the server', async () => {
        productApi(owner);
        renderApp('/products/42');

        const table = await screen.findByRole('table');
        expect(within(table).getByRole('columnheader', { name: 'Margin' })).toBeTruthy();
        await waitFor(() => expect(within(table).getAllByText('39.5%')).toHaveLength(4));
    });

    it('shows no margin to staff without catalog.view_cost, and never asks for one', async () => {
        const calls = productApi(contentEditor);
        renderApp('/products/42');

        const table = await screen.findByRole('table');
        expect(within(table).queryByRole('columnheader', { name: 'Margin' })).toBeNull();
        expect(screen.queryByText(/^Margin /)).toBeNull();
        expect(urls(calls).some((u) => u.endsWith('/products/margin-preview'))).toBe(false);
    });
});
