import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { I18nProvider, type Locale } from '@/i18n/I18nProvider';
import { createQueryClient } from '@/lib/queryClient';
import { mockApi } from '@/test/renderApp';
import type { ProductImage } from './api';
import { cropRect } from './CropDialog';
import { ImageUploader } from './ImageUploader';

beforeAll(() => {
    // jsdom has no <dialog> methods.
    HTMLDialogElement.prototype.close ??= function close(this: HTMLDialogElement) {
        this.removeAttribute('open');
    };
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const image = (id: number, position: number, overrides: Partial<ProductImage> = {}): ProductImage => ({
    id,
    variant_id: null,
    url: `/storage/products/9/${id}/1200.webp`,
    urls: { thumb: `/storage/products/9/${id}/320.webp`, medium: `/storage/products/9/${id}/480.webp`, large: `/storage/products/9/${id}/1200.webp` },
    srcset: { webp: `/storage/products/9/${id}/160.webp 160w`, avif: `/storage/products/9/${id}/160.avif 160w` },
    width: 1200,
    height: 1200,
    processing_status: 'ready',
    alt_text: `Brick Box — image ${position + 1}`,
    alt_text_bn: `ব্রিক বক্স — ছবি ${position + 1}`,
    position,
    is_primary: position === 0,
    ...overrides,
});

const variants = [
    { id: 31, label: 'Red / S' },
    { id: 32, label: 'Blue / M' },
];

function renderUploader(images: ProductImage[], { locale = 'en' as Locale, disabled = false } = {}) {
    const calls = mockApi((url, init) => {
        const method = init?.method ?? 'GET';
        if (url.endsWith('/products/9/images') && method === 'GET') return { status: 200, body: { data: images } };
        if (url.endsWith('/images/reorder')) return { status: 200, body: { data: images } };
        if (method === 'PATCH') return { status: 200, body: images[0] };
        if (method === 'DELETE') return { status: 204 };
        return { status: 404 };
    });
    const client = createQueryClient();
    client.setDefaultOptions({ queries: { ...client.getDefaultOptions().queries, retry: false } });
    const invalidate = vi.spyOn(client, 'invalidateQueries');

    render(
        <QueryClientProvider client={client}>
            <I18nProvider initialLocale={locale}>
                <ImageUploader productId={9} variants={variants} disabled={disabled} productName="Brick Box" />
            </I18nProvider>
        </QueryClientProvider>,
    );

    const writes = () =>
        calls.mock.calls
            .filter(([, init]) => (init as RequestInit | undefined)?.method && (init as RequestInit).method !== 'GET')
            .map(([url, init]) => ({ url: String(url), method: (init as RequestInit).method, body: (init as RequestInit).body ? JSON.parse(String((init as RequestInit).body)) : undefined }));

    return { calls, writes, invalidate, client };
}

describe('ImageUploader', () => {
    it('renders tiles with the primary chip, renditions and the count', async () => {
        renderUploader([image(1, 0), image(2, 1)]);

        expect(await screen.findByRole('heading', { name: 'Images (2 of 10)' })).toBeTruthy();
        expect(screen.getByText('Primary')).toBeTruthy();
        const img = screen.getAllByRole('img')[0] as HTMLImageElement;
        expect(img.getAttribute('src')).toBe('/storage/products/9/1/320.webp');
        expect(img.getAttribute('width')).toBe('1200');
        expect(screen.getByRole('button', { name: 'Image 1 is primary' }).getAttribute('aria-pressed')).toBe('true');
        expect(screen.queryByTestId('alt-required')).toBeNull();
    });

    it('flags missing alt text as required before publish', async () => {
        renderUploader([image(1, 0), image(2, 1, { alt_text_bn: null }), image(3, 2, { alt_text: '  ' })]);

        expect((await screen.findByTestId('alt-required')).textContent).toContain('2 image(s) missing it');
        expect(screen.getByLabelText('Alt text (Bangla), image 2').getAttribute('aria-invalid')).toBe('true');
        expect(screen.getByLabelText('Alt text (English), image 3').getAttribute('aria-invalid')).toBe('true');
        expect(screen.getByText('Alt text missing in Bangla')).toBeTruthy();
    });

    it('saves trimmed alt text on blur, and only when it changed', async () => {
        const { writes, invalidate } = renderUploader([image(1, 0, { alt_text_bn: null })]);

        const bn = await screen.findByLabelText('Alt text (Bangla), image 1');
        expect(bn.getAttribute('lang')).toBe('bn');
        fireEvent.blur(screen.getByLabelText('Alt text (English), image 1')); // unchanged → no request
        fireEvent.change(bn, { target: { value: '  ব্রিক বক্স  ' } });
        fireEvent.blur(bn);

        await waitFor(() => expect(writes()).toEqual([{ url: '/api/v1/admin/products/9/images/1', method: 'PATCH', body: { alt_text_bn: 'ব্রিক বক্স' } }]));
        await waitFor(() => expect(invalidate).toHaveBeenCalledWith({ queryKey: ['products', 9] }));
    });

    it('makes an image primary and assigns a variant', async () => {
        const { writes } = renderUploader([image(1, 0), image(2, 1)]);

        fireEvent.click(await screen.findByRole('button', { name: 'Make image 2 primary' }));
        fireEvent.change(screen.getByLabelText('Variant for image 2'), { target: { value: '32' } });

        await waitFor(() =>
            expect(writes().map((w) => w.body)).toEqual([{ is_primary: true }, { variant_id: 32 }]),
        );
    });

    it('reorders with the keyboard and announces the move', async () => {
        const { writes } = renderUploader([image(1, 0), image(2, 1), image(3, 2)]);

        const handle = await screen.findByRole('button', { name: /Reorder image 1/ });
        fireEvent.keyDown(handle, { key: ' ' });
        expect(handle.getAttribute('aria-pressed')).toBe('true');
        fireEvent.keyDown(handle, { key: 'ArrowRight' });

        await waitFor(() => expect(writes()).toEqual([{ url: '/api/v1/admin/products/9/images/reorder', method: 'POST', body: { ids: [2, 1, 3] } }]));
        expect(screen.getByText('Image 1 of 3 moved to position 2.')).toBeTruthy();
    });

    it('moves the selected image with the touch buttons', async () => {
        const { writes } = renderUploader([image(1, 0), image(2, 1)]);

        await screen.findByRole('heading', { name: 'Images (2 of 10)' });
        fireEvent.click(screen.getAllByRole('listitem')[1]!);
        fireEvent.click(screen.getByRole('button', { name: 'Move left' }));

        await waitFor(() => expect(writes()[0]?.body).toEqual({ ids: [2, 1] }));
    });

    it('deletes only after the typed confirmation', async () => {
        const { writes } = renderUploader([image(1, 0), image(2, 1)]);

        fireEvent.click(await screen.findByRole('button', { name: 'Delete image 2' }));
        const dialog = screen.getByRole('dialog');
        const confirm = within(dialog).getByRole('button', { name: 'Delete image' });
        expect((confirm as HTMLButtonElement).disabled).toBe(true);

        fireEvent.change(within(dialog).getByLabelText('Type “delete” to confirm'), { target: { value: 'delete' } });
        fireEvent.click(confirm);

        await waitFor(() => expect(writes()).toEqual([{ url: '/api/v1/admin/products/9/images/2', method: 'DELETE', body: undefined }]));
    });

    it('pre-checks type and size in the browser and uploads the rest with progress', async () => {
        const sent: FormData[] = [];
        const instances: FakeXhr[] = [];
        class FakeXhr {
            status = 0;
            responseText = '';
            statusText = '';
            withCredentials = false;
            upload: { onprogress: ((e: { lengthComputable: boolean; loaded: number; total: number }) => void) | null } = { onprogress: null };
            onload: (() => void) | null = null;
            onerror: (() => void) | null = null;
            headers: Record<string, string> = {};
            url = '';
            constructor() {
                instances.push(this);
            }
            open(_method: string, url: string) {
                this.url = url;
            }
            setRequestHeader(name: string, value: string) {
                this.headers[name] = value;
            }
            send(body: FormData) {
                sent.push(body);
            }
        }
        vi.stubGlobal('XMLHttpRequest', FakeXhr);
        const { calls } = renderUploader([]);

        const input = await screen.findByTestId('image-file-input');
        const ok = new File([new Uint8Array(2 * 1024 * 1024)], 'brick.jpg', { type: 'image/jpeg' });
        const gif = new File(['GIF89a'], 'anim.gif', { type: 'image/gif' });
        const huge = new File([new Uint8Array(11 * 1024 * 1024)], 'huge.png', { type: 'image/png' });
        fireEvent.change(input, { target: { files: [ok, gif, huge] } });

        expect(await screen.findByText('Not a JPG, PNG, WebP or AVIF image')).toBeTruthy();
        expect(screen.getByText('11.0 MB — over the 10 MB limit')).toBeTruthy();
        expect(sent).toHaveLength(1);
        expect(sent[0]!.get('file')).toBe(ok);
        expect(instances[0]!.url).toBe('/api/v1/admin/products/9/images');
        expect(instances[0]!.headers.Accept).toBe('application/json');

        act(() => instances[0]!.upload.onprogress?.({ lengthComputable: true, loaded: 1024 * 1024, total: 2 * 1024 * 1024 }));
        expect(screen.getByRole('progressbar').getAttribute('aria-valuenow')).toBe('50');
        expect(screen.getByText('50% · 2.0 MB')).toBeTruthy();

        const listCalls = () => calls.mock.calls.filter(([url]) => String(url).endsWith('/products/9/images')).length;
        const before = listCalls();
        act(() => {
            instances[0]!.status = 201;
            instances[0]!.responseText = JSON.stringify(image(5, 0, { processing_status: 'processing' }));
            instances[0]!.onload?.();
        });
        await waitFor(() => expect(screen.queryByRole('progressbar')).toBeNull());
        await waitFor(() => expect(listCalls()).toBeGreaterThan(before));
    });

    it('shows the server’s reason when it refuses an upload', async () => {
        const instances: { onload: (() => void) | null; status: number; responseText: string }[] = [];
        vi.stubGlobal(
            'XMLHttpRequest',
            class {
                status = 0;
                responseText = '';
                upload = { onprogress: null };
                onload: (() => void) | null = null;
                constructor() {
                    instances.push(this);
                }
                open() {}
                setRequestHeader() {}
                send() {}
            },
        );
        renderUploader([]);

        fireEvent.change(await screen.findByTestId('image-file-input'), { target: { files: [new File(['<?php'], 'shell.jpg', { type: 'image/jpeg' })] } });
        act(() => {
            instances[0]!.status = 422;
            instances[0]!.responseText = JSON.stringify({
                type: 'https://toymartbd.com/problems/validation-error',
                title: 'Validation failed',
                status: 422,
                errors: [{ field: 'file', message: 'Only JPEG, PNG, WebP or AVIF images are accepted.' }],
            });
            instances[0]!.onload?.();
        });

        expect(await screen.findByText('Only JPEG, PNG, WebP or AVIF images are accepted.')).toBeTruthy();
    });

    it('hides every write control when disabled', async () => {
        renderUploader([image(1, 0)], { disabled: true });

        await screen.findByRole('heading', { name: 'Images (1 of 10)' });
        expect(screen.queryByText('Add images')).toBeNull();
        expect((screen.getByRole('button', { name: 'Delete image 1' }) as HTMLButtonElement).disabled).toBe(true);
        expect((screen.getByLabelText('Alt text (English), image 1') as HTMLInputElement).disabled).toBe(true);
    });

    it('speaks Bangla', async () => {
        renderUploader([image(1, 0)], { locale: 'bn' });

        expect(await screen.findByRole('heading', { name: 'ছবি (1/10)' })).toBeTruthy();
        expect(screen.getByText('ছবি যোগ করুন')).toBeTruthy();
        expect(screen.getByText('প্রধান')).toBeTruthy();
    });
});

describe('cropRect', () => {
    it('fits the largest box of the aspect, zooms in and stays inside the image', () => {
        expect(cropRect(1200, 800, 1, 1, 0.5, 0.5)).toEqual({ x: 200, y: 0, width: 800, height: 800 });
        expect(cropRect(1200, 800, 1, 2, 0, 0)).toEqual({ x: 0, y: 0, width: 400, height: 400 });
        expect(cropRect(1200, 800, 16 / 9, 1, 1, 1)).toEqual({ x: 0, y: 125, width: 1200, height: 675 });
    });
});
