import { act, cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderApp } from '@/test/renderApp';
import { owner, product, productApi, sentBody } from './fixtures';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    localStorage.clear();
});

const tabs = () => screen.getByRole('navigation', { name: 'Product sections' });
const tab = (name: string) => within(tabs()).getByRole('link', { name: new RegExp(`^${name}`) });

describe('Variants section (VariantMatrixEditor 201:1201, 241:38029)', () => {
    it('is on Add product from the start; the first option makes the product variable', async () => {
        const calls = productApi(owner);
        renderApp('/products/new');

        const variants = await screen.findByRole('region', { name: 'Variants' });
        expect(within(variants).getByText(/Sold as one item\. Add an option/)).toBeTruthy();
        expect(screen.queryByLabelText('Product type')).toBeNull();
        expect(tab('Variants')).toBeTruthy();
        expect(tab('Pricing')).toBeTruthy();
        // No stock to show yet: opening stock lives in Pricing.
        expect(within(tabs()).queryByRole('link', { name: /^Inventory/ })).toBeNull();

        fireEvent.change(await within(variants).findByLabelText('Add option'), { target: { value: '1' } });
        fireEvent.change(within(variants).getByLabelText('Add a Color value'), { target: { value: '10' } });
        expect(within(variants).getAllByRole('switch', { name: /^Sell / })).toHaveLength(1);

        fireEvent.click(screen.getByRole('button', { name: 'Create product' }));
        // The schema check stops it (no name), but the document it built is variable.
        expect(await screen.findAllByText('This field is required.')).not.toHaveLength(0);
        expect(sentBody(calls, 'POST')).toBeNull();

        // Removing the option makes it simple again.
        fireEvent.click(within(variants).getByRole('button', { name: 'Remove Color' }));
        expect(within(variants).getByText(/Sold as one item/)).toBeTruthy();
    });

    it('sends type variable once an option is added on create', async () => {
        let posted: Record<string, unknown> | null = null;
        productApi(owner, (url, init) => {
            if (url.endsWith('/products') && init?.method === 'POST') {
                posted = JSON.parse(String(init.body)) as Record<string, unknown>;
                return { status: 201, body: product };
            }
            return undefined;
        });
        renderApp('/products/new');

        fireEvent.change(await screen.findByLabelText(/^Name \(English\)/), { target: { value: 'Rings' } });
        await screen.findByRole('option', { name: /Building & Construction/ });
        fireEvent.change(screen.getByLabelText('Category'), { target: { value: '5' } });
        const variants = screen.getByRole('region', { name: 'Variants' });
        fireEvent.change(await within(variants).findByLabelText('Add option'), { target: { value: '1' } });
        fireEvent.change(within(variants).getByLabelText('Add a Color value'), { target: { value: '10' } });
        fireEvent.change(within(variants).getByLabelText('Price — Red'), { target: { value: '500' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create product' }));

        await waitFor(() => expect(posted).not.toBeNull());
        expect(posted).toMatchObject({ type: 'variable', options: [{ attribute_id: 1, value_ids: [10] }] });
    });

    it('shows on a saved simple product, explaining the type is fixed, with no Add option', async () => {
        const simple = { ...product, type: 'simple' as const, options: [], variants: [{ ...product.variants[0]!, attribute_values: [], is_default: true }] };
        productApi(owner, (url, init) => (/\/products\/42$/.test(url) && (!init?.method || init.method === 'GET') ? { status: 200, body: simple } : undefined));
        renderApp('/products/42');

        const variants = await screen.findByRole('region', { name: 'Variants' });
        expect(within(variants).getByText(/type cannot change after creation/)).toBeTruthy();
        expect(within(variants).queryByLabelText('Add option')).toBeNull();
        expect(tab('Variants')).toBeTruthy();
    });
});

describe('section tabs', () => {
    it('a tab click makes it active and keeps it while the scroll-spy reports other sections', async () => {
        // jsdom has no IntersectionObserver: a controllable one stands in for the scroll-spy's.
        let report: ((entries: { target: Element; isIntersecting: boolean }[]) => void) | null = null;
        vi.stubGlobal(
            'IntersectionObserver',
            class {
                constructor(callback: (entries: { target: Element; isIntersecting: boolean }[]) => void) {
                    report = callback;
                }
                observe() {}
                disconnect() {}
            },
        );
        const scrolled: string[] = [];
        const original = Element.prototype.scrollIntoView;
        Element.prototype.scrollIntoView = function (this: Element) {
            scrolled.push(this.id);
        };

        try {
            productApi(owner);
            renderApp('/products/42');
            await screen.findByRole('region', { name: 'Stock by branch' });

            fireEvent.click(tab('Inventory'));
            expect(tab('Inventory').getAttribute('aria-current')).toBe('location');
            expect(scrolled.at(-1)).toBe('product-inventory');

            // The form column's Pricing card shares the top band with the rail's Inventory card.
            const at = (id: string, isIntersecting: boolean) => ({ target: document.getElementById(id)!, isIntersecting });
            act(() => report!([at('product-pricing', true), at('product-inventory', true)]));
            expect(tab('Inventory').getAttribute('aria-current')).toBe('location');
            expect(tab('Pricing').getAttribute('aria-current')).toBeNull();

            // Scrolling by hand hands the tab back to the spy.
            act(() => {
                window.dispatchEvent(new Event('wheel'));
            });
            expect(tab('Pricing').getAttribute('aria-current')).toBe('location');

            // Every tab jumps to its own anchor.
            for (const [name, id] of [['General', 'general'], ['Media', 'media'], ['Pricing', 'pricing'], ['Variants', 'variants'], ['Inventory', 'inventory'], ['SEO', 'seo'], ['Related', 'related']] as const) {
                fireEvent.click(tab(name));
                expect(scrolled.at(-1)).toBe(`product-${id}`);
                expect(document.getElementById(`product-${id}`)).not.toBeNull();
                expect(tab(name).getAttribute('aria-current')).toBe('location');
            }
        } finally {
            Element.prototype.scrollIntoView = original;
        }
    });
});
