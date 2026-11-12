import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { renderApp } from '@/test/renderApp';
import type { ProductImage } from '@/features/catalog/media/api';
import { owner, productApi } from './fixtures';

beforeAll(() => {
    // jsdom has no <dialog> methods.
    HTMLDialogElement.prototype.close ??= function close(this: HTMLDialogElement) {
        this.removeAttribute('open');
    };
});

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    localStorage.clear();
});

const image: ProductImage = {
    id: 501,
    variant_id: null,
    url: '/storage/products/42/501/1200.webp',
    urls: { thumb: '/storage/products/42/501/320.webp', medium: '/storage/products/42/501/480.webp', large: '/storage/products/42/501/1200.webp' },
    srcset: { webp: '/storage/products/42/501/160.webp 160w', avif: null },
    width: 1200,
    height: 1200,
    processing_status: 'ready',
    alt_text: 'Brick Box — image 1',
    alt_text_bn: 'ব্রিক বক্স — ছবি 1',
    position: 0,
    is_primary: true,
};

/** React reports invalid DOM nesting through console.error. */
const nestingWarnings = (spy: { mock: { calls: unknown[][] } }) =>
    spy.mock.calls.map((args) => args.map(String).join(' ')).filter((message: string) => /cannot be a descendant of|cannot contain a nested/.test(message));

describe('product images panel', () => {
    it('renders no <form> inside the product form (React: "<form> cannot be a descendant of <form>")', async () => {
        const errors = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        productApi(owner, (url) => (/\/products\/42\/images$/.test(url) ? { status: 200, body: { data: [image] } } : undefined));
        renderApp('/products/42?tab=images');

        const region = await screen.findByRole('region', { name: 'Product images' });
        await within(region).findByRole('button', { name: 'Delete image 1' });

        expect(nestingWarnings(errors)).toEqual([]);
        // The DOM agrees: no form element has a form ancestor.
        expect(Array.from(document.querySelectorAll('form form'))).toHaveLength(0);
    });

    it('confirming an image delete never submits the product form', async () => {
        const calls = productApi(owner, (url, init) => {
            if (/\/products\/42\/images$/.test(url)) return { status: 200, body: { data: [image] } };
            if (url.endsWith('/images/501') && init?.method === 'DELETE') return { status: 204 };
            return undefined;
        });
        renderApp('/products/42?tab=images');

        fireEvent.click(await screen.findByRole('button', { name: 'Delete image 1' }));
        const dialog = await screen.findByRole('dialog');
        fireEvent.change(within(dialog).getByRole('textbox'), { target: { value: 'delete' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Delete image' }));

        await waitFor(() => expect(calls.mock.calls.some(([url, init]) => String(url).endsWith('/images/501') && (init as RequestInit | undefined)?.method === 'DELETE')).toBe(true));
        expect(calls.mock.calls.some(([url, init]) => /\/products\/42$/.test(String(url)) && (init as RequestInit | undefined)?.method === 'PUT')).toBe(false);
    });

    it('inserts library picks one request at a time, in pick order, then refreshes the gallery', async () => {
        const libraryItem = (id: number, filename: string) => ({
            id,
            kind: 'image',
            filename,
            caption: null,
            alt_text: 'Puzzle box',
            alt_text_bn: 'পাজল বক্স',
            alt_status: 'complete',
            folder_id: 1,
            mime: 'image/webp',
            original_mime: 'image/jpeg',
            width: 1600,
            height: 1600,
            size_bytes: 184320,
            original_size_bytes: 2411724,
            processing_status: 'ready',
            urls: { thumb: `/storage/media/${id}/320.webp`, medium: null, large: null },
            srcset: null,
            public_url: null,
            used_in_count: 0,
            origin: 'library',
            uploaded_by: null,
            created_at: '2026-11-12T08:30:00+00:00',
            updated_at: '2026-11-12T08:30:00+00:00',
        });
        let gallery: ProductImage[] = [image];
        const order: number[] = [];
        let inFlight = 0;
        const calls = productApi(owner, (url, init) => {
            if (url.endsWith('/media/folders')) return { status: 200, body: { data: [{ id: 1, name: 'Products', system_key: 'products', is_system: true, position: 0, created_at: '' }] } };
            if (url.endsWith('/media/counts')) return { status: 200, body: { data: { all: 2, unused: 2, unfiled: 0, folders: [{ id: 1, count: 2 }], kinds: { image: 2, document: 0 }, missing_alt: { bn: 0, en: 0 } } } };
            if (url.includes('/media?')) return { status: 200, body: { data: [libraryItem(71, 'puzzle-a.webp'), libraryItem(72, 'puzzle-b.webp')], links: { next: null }, meta: { per_page: 30, next_cursor: null, prev_cursor: null, total: 2 } } };
            if (/\/products\/42\/images$/.test(url) && init?.method === 'POST') {
                inFlight += 1;
                expect(inFlight).toBe(1);
                const { media_id } = JSON.parse(String(init.body)) as { media_id: number };
                order.push(media_id);
                gallery = [...gallery, { ...image, id: 600 + media_id, position: gallery.length, is_primary: false }];
                inFlight -= 1;
                return { status: 201, body: gallery[gallery.length - 1] };
            }
            if (/\/products\/42\/images$/.test(url)) return { status: 200, body: { data: gallery } };
            return undefined;
        });
        renderApp('/products/42?tab=images');

        const open = await screen.findByRole('button', { name: 'Choose from library' });
        await waitFor(() => expect(open.hasAttribute('disabled')).toBe(false));
        fireEvent.click(open);
        const dialog = await screen.findByRole('dialog', { name: 'Choose product images' });
        expect(within(dialog).getByText('Brick Box 500 · gallery has 1 of 10 images')).toBeTruthy();
        fireEvent.click(await within(dialog).findByRole('button', { name: /^puzzle-b/ }));
        fireEvent.click(within(dialog).getByRole('button', { name: /^puzzle-a/ }));
        const listsBefore = calls.mock.calls.filter(([url, init]) => /\/products\/42\/images$/.test(String(url)) && !(init as RequestInit | undefined)?.method).length;
        fireEvent.click(within(dialog).getByRole('button', { name: 'Insert 2 images' }));

        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(order).toEqual([72, 71]);
        expect(calls.mock.calls.filter(([url, init]) => /\/products\/42\/images$/.test(String(url)) && !(init as RequestInit | undefined)?.method).length).toBeGreaterThan(listsBefore);
        expect(await screen.findByRole('button', { name: 'Delete image 3' })).toBeTruthy();
    });
});
