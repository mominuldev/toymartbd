import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { I18nProvider } from '@/i18n/I18nProvider';
import { createQueryClient } from '@/lib/queryClient';
import { ToastProvider } from '@/components/ui/Toast';
import { mockApi } from '@/test/renderApp';
import type { Media, MediaFolder } from '../api';
import { fitFlags, togglePick } from './flags';
import { MediaPickerModal, type InsertFailure, type MediaPickerContext } from './MediaPickerModal';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

/** Mirrors the `Media` example in docs/api/media-library.md. */
const media = (id: number, overrides: Partial<Media> = {}): Media => ({
    id,
    kind: 'image',
    filename: `toy-${id}.webp`,
    caption: null,
    alt_text: `Toy ${id}, front`,
    alt_text_bn: `খেলনা ${id}, সামনে`,
    alt_status: 'complete',
    folder_id: 1,
    mime: 'image/webp',
    original_mime: 'image/jpeg',
    width: 1600,
    height: 1600,
    size_bytes: 184320,
    original_size_bytes: 2411724,
    processing_status: 'ready',
    urls: { thumb: `/storage/media/${id}/320.webp`, medium: `/storage/media/${id}/480.webp`, large: `/storage/media/${id}/1200.webp` },
    srcset: { webp: `/storage/media/${id}/160.webp 160w`, avif: `/storage/media/${id}/160.avif 160w` },
    public_url: `https://toymartbd.com/m/${id}/toy-${id}.webp`,
    used_in_count: 0,
    origin: 'library',
    uploaded_by: { id: 7, name: 'Nusrat Jahan' },
    created_at: '2026-11-12T08:30:00+00:00',
    updated_at: '2026-11-12T08:30:00+00:00',
    ...overrides,
});

const folders: MediaFolder[] = [
    { id: 1, name: 'Products', system_key: 'products', is_system: true, position: 0, created_at: '2026-11-12T08:30:00+00:00' },
    { id: 2, name: 'Category banners', system_key: 'category_banners', is_system: true, position: 1, created_at: '2026-11-12T08:30:00+00:00' },
];

const library = [
    media(11, { filename: 'puzzle-box-front.webp' }),
    media(12, { filename: 'puzzle-box-side.webp', alt_text_bn: null, alt_status: 'missing_bn' }),
    media(13, { filename: 'eid-banner-2026.webp', width: 1800, height: 1200 }),
    media(14, { filename: 'doctor-kit-small.webp', width: 900, height: 900 }),
];

const productContext = (max = 9): MediaPickerContext => ({
    title: 'Choose product images',
    subtitle: 'Puzzle Box · gallery has 1 of 10 images',
    hint: 'Product images: square 1:1',
    ratio: [1, 1],
    minSize: 1200,
    multiple: true,
    max,
    kinds: ['image'],
    defaultFolder: 'products',
    warnMissingAlt: true,
});

function mediaApi() {
    return mockApi((url) => {
        if (url.endsWith('/media/folders')) return { status: 200, body: { data: folders } };
        if (url.endsWith('/media/counts'))
            return { status: 200, body: { data: { all: 4, unused: 4, unfiled: 0, folders: [{ id: 1, count: 4 }, { id: 2, count: 0 }], kinds: { image: 4, document: 0 }, missing_alt: { bn: 1, en: 0 } } } };
        if (url.includes('/media?') || url.endsWith('/media'))
            return { status: 200, body: { data: library, links: { next: null }, meta: { per_page: 30, next_cursor: null, prev_cursor: null, total: library.length } } };
        const one = /\/media\/(\d+)$/.exec(url);
        if (one) return { status: 200, body: { ...media(Number(one[1])), used_in: [], renditions: [], master_bytes: 1, renditions_bytes: 1, download_path: '' } };
        return { status: 404 };
    });
}

function renderPicker(context: MediaPickerContext, onInsert = vi.fn(async (_: Media[]): Promise<InsertFailure[]> => []), onClose = vi.fn()) {
    const calls = mediaApi();
    const client = createQueryClient();
    client.setDefaultOptions({ queries: { ...client.getDefaultOptions().queries, retry: false } });
    render(
        <QueryClientProvider client={client}>
            <I18nProvider initialLocale="en">
                <ToastProvider>
                    <MediaPickerModal context={context} onInsert={onInsert} onClose={onClose} />
                </ToastProvider>
            </I18nProvider>
        </QueryClientProvider>,
    );
    return { calls, onInsert, onClose };
}

const tile = (name: string) => screen.getByRole('button', { name: new RegExp(`^${name.replace('.', '\\.')}`) });
const insertButton = () => screen.getByRole('button', { name: /^Insert/ });

describe('MediaPickerModal', () => {
    it('opens on the context folder and numbers picks in order (1 = primary); deselecting renumbers', async () => {
        const { calls, onInsert } = renderPicker(productContext());
        await screen.findByRole('button', { name: /^puzzle-box-front/ });
        expect(calls.mock.calls.some(([url]) => String(url).includes('filter%5Bfolder%5D=1'))).toBe(true);
        expect(screen.getByRole('button', { name: /^Products/ }).getAttribute('aria-pressed')).toBe('true');

        fireEvent.click(tile('eid-banner-2026.webp'));
        fireEvent.click(tile('puzzle-box-front.webp'));
        fireEvent.click(tile('puzzle-box-side.webp'));
        expect(tile('eid-banner-2026.webp').getAttribute('aria-label')).toMatch(/pick 1$/);
        expect(tile('puzzle-box-front.webp').getAttribute('aria-label')).toMatch(/pick 2$/);
        expect(tile('puzzle-box-side.webp').getAttribute('aria-label')).toMatch(/pick 3$/);
        expect(screen.getByText('3 selected · numbers set the gallery order, 1 is the primary image')).toBeTruthy();

        fireEvent.click(tile('puzzle-box-front.webp'));
        expect(tile('puzzle-box-front.webp').getAttribute('aria-pressed')).toBe('false');
        expect(tile('puzzle-box-side.webp').getAttribute('aria-label')).toMatch(/pick 2$/);

        fireEvent.click(insertButton());
        await waitFor(() => expect(onInsert).toHaveBeenCalledTimes(1));
        expect(onInsert.mock.calls[0]![0].map((m) => m.id)).toEqual([13, 12]);
    });

    it('caps the picks at the gallery’s remaining slots and says why', async () => {
        const { onInsert } = renderPicker(productContext(2));
        await screen.findByRole('button', { name: /^puzzle-box-front/ });

        fireEvent.click(tile('puzzle-box-front.webp'));
        fireEvent.click(tile('puzzle-box-side.webp'));
        fireEvent.click(tile('eid-banner-2026.webp'));

        expect(tile('eid-banner-2026.webp').getAttribute('aria-pressed')).toBe('false');
        expect(screen.getByText('The gallery has room for 2 more. Deselect an image to pick another.')).toBeTruthy();
        expect(insertButton().textContent).toBe('Insert 2 images');
        fireEvent.click(insertButton());
        await waitFor(() => expect(onInsert.mock.calls[0]![0]).toHaveLength(2));
    });

    it('flags ratio and size from width × height, and warns (without blocking) on a missing Bangla alt', async () => {
        const { onInsert } = renderPicker(productContext());
        await screen.findByRole('button', { name: /^puzzle-box-front/ });

        expect(within(tile('eid-banner-2026.webp')).getByText('Not 1:1 — will crop')).toBeTruthy();
        expect(within(tile('eid-banner-2026.webp')).getByText('1800×1200 · 3:2 · 180 KB')).toBeTruthy();
        expect(within(tile('doctor-kit-small.webp')).getByText('Below 1200 px')).toBeTruthy();
        expect(within(tile('puzzle-box-front.webp')).queryByText('Not 1:1 — will crop')).toBeNull();
        expect(within(tile('puzzle-box-front.webp')).queryByText('Below 1200 px')).toBeNull();

        expect(screen.queryByTestId('picker-alt-warning')).toBeNull();
        fireEvent.click(tile('puzzle-box-side.webp'));
        expect(screen.getByTestId('picker-alt-warning').textContent).toContain('1 selected image has no বাংলা alt text');

        expect(insertButton().hasAttribute('disabled')).toBe(false);
        fireEvent.click(insertButton());
        await waitFor(() => expect(onInsert.mock.calls[0]![0].map((m) => m.id)).toEqual([12]));
    });

    it('keeps the files that failed to insert, with the server’s reason, and drops the ones that went in', async () => {
        const onInsert = vi.fn(async (picked: Media[]): Promise<InsertFailure[]> => [{ media: picked[1]!, reason: 'This image is already in the gallery.' }]);
        const { onClose } = renderPicker(productContext(), onInsert);
        await screen.findByRole('button', { name: /^puzzle-box-front/ });
        fireEvent.click(tile('puzzle-box-front.webp'));
        fireEvent.click(tile('eid-banner-2026.webp'));
        fireEvent.click(insertButton());

        expect((await screen.findByRole('alert')).textContent).toContain('eid-banner-2026.webp was not added: This image is already in the gallery.');
        expect(onClose).not.toHaveBeenCalled();
        expect(tile('puzzle-box-front.webp').getAttribute('aria-pressed')).toBe('false');
        expect(tile('eid-banner-2026.webp').getAttribute('aria-label')).toMatch(/pick 1$/);
    });

    it('selects uploaded files and never inserts a failed one', async () => {
        const xhrs: FakeXhr[] = [];
        class FakeXhr {
            status = 0;
            responseText = '';
            url = '';
            body: FormData | null = null;
            upload = { onprogress: null as ((e: { lengthComputable: boolean; loaded: number; total: number }) => void) | null };
            onload: (() => void) | null = null;
            onerror: (() => void) | null = null;
            onabort: (() => void) | null = null;
            withCredentials = false;
            constructor() {
                xhrs.push(this);
            }
            open(_method: string, url: string) {
                this.url = url;
            }
            setRequestHeader() {}
            abort() {}
            send(body: FormData) {
                this.body = body;
            }
        }
        vi.stubGlobal('XMLHttpRequest', FakeXhr);
        const { onInsert } = renderPicker(productContext());
        await screen.findByRole('button', { name: /^puzzle-box-front/ });

        fireEvent.click(screen.getByRole('tab', { name: 'Upload' }));
        const good = new File([new Uint8Array(1024)], 'puzzle-box-back.jpg', { type: 'image/jpeg' });
        const bad = new File(['II*'], 'puzzle-box-raw.jpg', { type: 'image/jpeg' });
        fireEvent.change(screen.getByTestId('picker-file-input'), { target: { files: [good, bad] } });

        await waitFor(() => expect(xhrs).toHaveLength(2));
        expect(xhrs[0]!.url).toBe('/api/v1/admin/media');
        // Uploads land in the context's folder (Products, id 1).
        expect(xhrs[0]!.body!.get('folder_id')).toBe('1');

        act(() => {
            xhrs[0]!.status = 201;
            xhrs[0]!.responseText = JSON.stringify(media(31, { filename: 'puzzle-box-back.jpg', alt_text: null, alt_text_bn: null, alt_status: 'missing' }));
            xhrs[0]!.onload?.();
            xhrs[1]!.status = 422;
            xhrs[1]!.responseText = JSON.stringify({
                type: 'https://toymartbd.com/problems/validation-error',
                title: 'Validation failed',
                status: 422,
                errors: [{ field: 'file', message: 'Not a real image or PDF — the content is not JPG, PNG, WebP, AVIF or PDF.' }],
            });
            xhrs[1]!.onload?.();
        });

        expect(await screen.findByText('Not a real image or PDF — the content is not JPG, PNG, WebP, AVIF or PDF.')).toBeTruthy();
        expect(screen.getByText('1 ready to insert · 1 failed are not inserted')).toBeTruthy();
        expect(screen.getByTestId('picker-alt-warning').textContent).toContain('1 new image(s) need alt text');

        fireEvent.click(insertButton());
        await waitFor(() => expect(onInsert).toHaveBeenCalledTimes(1));
        expect(onInsert.mock.calls[0]![0].map((m) => m.id)).toEqual([31]);
    });

    it('closes on Escape and from Cancel, and labels the dialog with the context', async () => {
        const { onClose } = renderPicker(productContext());
        const dialog = await screen.findByRole('dialog', { name: 'Choose product images' });
        expect(dialog.getAttribute('aria-describedby')).toBeTruthy();
        fireEvent(dialog, new Event('cancel', { cancelable: true }));
        expect(onClose).toHaveBeenCalledTimes(1);
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(onClose).toHaveBeenCalledTimes(2);
    });

    it('moves between tabs with the arrow keys', async () => {
        renderPicker(productContext());
        const libraryTab = await screen.findByRole('tab', { name: 'Library' });
        expect(libraryTab.getAttribute('aria-selected')).toBe('true');
        fireEvent.keyDown(libraryTab, { key: 'ArrowRight' });
        const uploadTab = screen.getByRole('tab', { name: 'Upload' });
        expect(uploadTab.getAttribute('aria-selected')).toBe('true');
        expect(document.activeElement).toBe(uploadTab);
    });
});

describe('picker rules', () => {
    it('computes fit flags from the API’s width and height only', () => {
        expect(fitFlags({ kind: 'image', width: 1600, height: 1600 }, [1, 1], 1200)).toEqual({ crop: false, small: false });
        expect(fitFlags({ kind: 'image', width: 1800, height: 1200 }, [1, 1], 1200)).toEqual({ crop: true, small: false });
        expect(fitFlags({ kind: 'image', width: 900, height: 900 }, [1, 1], 1200)).toEqual({ crop: false, small: true });
        expect(fitFlags({ kind: 'image', width: null, height: null }, [1, 1], 1200)).toEqual({ crop: false, small: false });
        expect(fitFlags({ kind: 'document', width: null, height: null }, [1, 1], 1200)).toEqual({ crop: false, small: false });
    });

    it('toggles single-select by replacing and multi-select up to the cap', () => {
        const a = { id: 1 };
        const b = { id: 2 };
        expect(togglePick([a], b, false, 1)).toEqual([b]);
        expect(togglePick([a], b, true, 1)).toBeNull();
        expect(togglePick([a, b], a, true, 2)).toEqual([b]);
    });
});
