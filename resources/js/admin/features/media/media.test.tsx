import { act, cleanup, fireEvent, renderHook, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { renderApp } from '@/test/renderApp';
import { useUploadQueue } from './parts/useUploadQueue';
import { altState } from './parts/AltChip';
import { listQuery } from './api';
import { bodyOf, bulkDeleteResult, contentEditor, crayonSet, inUseProblem, mediaApi, mediaManager, validationProblem } from './fixtures';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const urls = (calls: ReturnType<typeof mediaApi>) => calls.mock.calls.map(([url]) => decodeURIComponent(String(url)));

describe('media library screen', () => {
    it('shows the counts line, the rail and the tiles from the server', async () => {
        mediaApi(mediaManager);
        renderApp('/store/media');

        expect(await screen.findByRole('heading', { name: 'Media library', level: 1 })).toBeTruthy();
        expect(await screen.findByText('1,284 files · 3.2 GB used · 37 images missing Bangla alt text')).toBeTruthy();

        const rail = screen.getByRole('navigation', { name: 'Media filters' });
        expect(within(rail).getByRole('button', { name: /^All media\s*1,284$/ }).getAttribute('aria-pressed')).toBe('true');
        expect(within(rail).getByRole('button', { name: /^Products\s*846$/ })).toBeTruthy();
        expect(within(rail).getByRole('button', { name: /^ঈদ ক্যাম্পেইন 2026\s*0$/ })).toBeTruthy();
        expect(within(rail).getByRole('button', { name: /^Missing Bangla alt\s*37$/ })).toBeTruthy();
        // No quota configured: usage only, never "of X GB".
        expect(within(rail).getByText('3.2 GB used')).toBeTruthy();

        const grid = await screen.findByRole('list', { name: 'Media files' });
        const tile = within(grid).getByRole('button', { name: 'brick-box-front.jpg' });
        expect(within(tile).getByText('Used in 3')).toBeTruthy();
        expect(within(tile).getByText('Alt EN · বাং')).toBeTruthy();
        expect(within(tile).getByText('1600×1600 · 180 KB')).toBeTruthy();
        expect(within(grid).getByText('বাং alt missing')).toBeTruthy();
        expect(within(grid).getByText('No alt needed')).toBeTruthy();
        expect(within(grid).getByText('Unused')).toBeTruthy();
        expect(screen.getByText('Showing 3 of 846')).toBeTruthy();
    });

    it('filters on the server through allowlisted params, with the state in the URL', async () => {
        const calls = mediaApi(mediaManager);
        renderApp('/store/media');
        const rail = await screen.findByRole('navigation', { name: 'Media filters' });

        fireEvent.click(within(rail).getByRole('button', { name: /^Missing Bangla alt/ }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter[missing_alt]=bn'))).toBe(true));

        fireEvent.click(within(rail).getByRole('button', { name: /^Products/ }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter[folder]=1') && u.includes('filter[missing_alt]=bn'))).toBe(true));

        fireEvent.change(screen.getByRole('searchbox', { name: 'Search media' }), { target: { value: 'ব্রিক' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter[search]=ব্রিক'))).toBe(true));

        fireEvent.change(screen.getAllByRole('combobox', { name: 'Sort' })[0]!, { target: { value: 'size' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('sort=size'))).toBe(true));
    });

    it('builds the list query from the allowlist only', () => {
        expect(listQuery({ folder: null, unused: true, kind: 'document', missingAlt: null, search: '  ', sort: 'newest' }, 'abc')).toBe('/media?filter%5Bunused%5D=1&filter%5Bkind%5D=document&cursor=abc');
        expect(listQuery({ folder: 'none', unused: false, kind: null, missingAlt: 'en', search: 'x', sort: 'name' }, null)).toBe(
            '/media?filter%5Bfolder%5D=none&filter%5Bmissing_alt%5D=en&filter%5Bsearch%5D=x&sort=name',
        );
    });

    it('disables Delete with the reason while a file is in use', async () => {
        mediaApi(mediaManager);
        renderApp('/store/media?asset=42');

        const panel = await screen.findByRole('complementary', { name: 'Asset details' });
        expect(await within(panel).findByText('Used in 3 places')).toBeTruthy();
        const del = within(panel).getByRole('button', { name: 'Delete file' }) as HTMLButtonElement;
        expect(del.disabled).toBe(true);
        expect(within(panel).getByText('Can’t delete while it’s used in 3 places. Remove it from them first, or use Replace file.')).toBeTruthy();
        // Usages link to the product, built from structured fields.
        expect(within(panel).getAllByRole('link', { name: 'Open Brick Box 500 pcs' })[0]!.getAttribute('href')).toBe('/products/12');
        expect(within(panel).getByText('Product · image 1 (primary)')).toBeTruthy();
        expect(within(panel).getByText('toymartbd.com/m/42/brick-box-front.webp')).toBeTruthy();
    });

    it('deletes an unused file after confirming, and shows the usages when the server says it is in use', async () => {
        let inUse = false;
        const calls = mediaApi(mediaManager, (url, init) => {
            if (/\/media\/43$/.test(url) && init?.method === 'DELETE') return inUse ? { status: 409, body: inUseProblem } : { status: 204 };
            return undefined;
        });
        renderApp('/store/media?asset=43');
        const panel = await screen.findByRole('complementary', { name: 'Asset details' });
        await within(panel).findByText('Not used anywhere yet');

        // The server is the authority: a usage added since the panel loaded → 409 → blocked dialog.
        inUse = true;
        fireEvent.click(within(panel).getByRole('button', { name: 'Delete file' }));
        let dialog = await screen.findByRole('dialog', { name: 'Delete crayon-set-old-03.jpg?' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Delete file' }));
        dialog = await screen.findByRole('dialog', { name: 'Can’t delete crayon-set-old-03.jpg' });
        expect(within(dialog).getByText(/Brick Box 500 pcs — Product · image 1 \(primary\)/)).toBeTruthy();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Close' }));

        inUse = false;
        fireEvent.click(within(panel).getByRole('button', { name: 'Delete file' }));
        dialog = await screen.findByRole('dialog', { name: 'Delete crayon-set-old-03.jpg?' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Delete file' }));
        expect(await screen.findByText('crayon-set-old-03.jpg deleted')).toBeTruthy();
        expect(calls.mock.calls.filter(([url, init]) => /\/media\/43$/.test(String(url)) && (init as RequestInit)?.method === 'DELETE')).toHaveLength(2);
    });

    it('marks a missing Bangla alt as an error and saves only that key; a 422 shows verbatim', async () => {
        let reject = true;
        const calls = mediaApi(mediaManager, (url, init) => {
            if (/\/media\/43$/.test(url) && init?.method === 'PATCH') {
                return reject ? validationProblem('alt_text_bn', 'The alt text (Bangla) may not be greater than 255 characters.') : { status: 200, body: { ...crayonSet, alt_text_bn: 'মোম রং সেট', alt_status: 'complete' } };
            }
            return undefined;
        });
        renderApp('/store/media?asset=43');
        const panel = await screen.findByRole('complementary', { name: 'Asset details' });
        const bn = (await within(panel).findByLabelText(/বাংলা/)) as HTMLInputElement;
        expect(bn.getAttribute('aria-invalid')).toBe('true');
        expect(within(panel).getByText('Missing — products using this image cannot be published.')).toBeTruthy();

        fireEvent.change(bn, { target: { value: 'মোম রং সেট' } });
        fireEvent.blur(bn);
        expect(await within(panel).findByText('The alt text (Bangla) may not be greater than 255 characters.')).toBeTruthy();
        expect(bodyOf(calls, 'PATCH', /\/media\/43$/)).toEqual({ alt_text_bn: 'মোম রং সেট' });

        reject = false;
        fireEvent.blur(bn);
        fireEvent.change(bn, { target: { value: 'মোম রং সেট ' } });
        fireEvent.blur(bn);
        await waitFor(() => expect(within(panel).queryByText('The alt text (Bangla) may not be greater than 255 characters.')).toBeNull());
    });

    it('reports a bulk delete as two numbers and names what was skipped', async () => {
        const calls = mediaApi(mediaManager, (url, init) => (url.endsWith('/media/bulk/delete') && init?.method === 'POST' ? { status: 200, body: { data: bulkDeleteResult } } : undefined));
        renderApp('/store/media?view=list');
        const table = await screen.findByRole('table');

        fireEvent.click(within(table).getByRole('checkbox', { name: 'Select brick-box-front.jpg' }));
        fireEvent.click(within(table).getByRole('checkbox', { name: 'Select crayon-set-old-03.jpg' }));
        const bar = screen.getByRole('region', { name: 'Bulk actions' });
        expect(within(bar).getByText('2 files selected')).toBeTruthy();
        expect(within(bar).getByText('1 unused · 1 in use — Delete skips files that are in use')).toBeTruthy();

        fireEvent.click(within(bar).getByRole('button', { name: 'Delete' }));
        const dialog = await screen.findByRole('dialog', { name: 'Delete crayon-set-old-03.jpg?' });
        expect(within(dialog).getByText('1 of the selected files are in use and will be skipped.')).toBeTruthy();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Delete file' }));

        expect(await within(bar).findByText('1 deleted')).toBeTruthy();
        expect(within(bar).getByText('1 skipped')).toBeTruthy();
        const failed = within(bar).getByRole('list', { name: 'Not deleted' });
        expect(within(failed).getByText('brick-box-front.jpg')).toBeTruthy();
        expect(within(failed).getByText(/Used in 3/)).toBeTruthy();
        // Both ids go to the server; the server decides what is in use.
        expect(bodyOf(calls, 'POST', /\/media\/bulk\/delete$/)).toEqual({ ids: [42, 43] });
    });

    it('hides delete and bulk download from a content editor', async () => {
        mediaApi(contentEditor);
        renderApp('/store/media?view=list&asset=43');
        const table = await screen.findByRole('table');
        fireEvent.click(within(table).getByRole('checkbox', { name: 'Select crayon-set-old-03.jpg' }));
        const bar = screen.getByRole('region', { name: 'Bulk actions' });
        expect(within(bar).getByRole('button', { name: 'Move to folder' })).toBeTruthy();
        expect(within(bar).queryByRole('button', { name: 'Delete' })).toBeNull();
        expect(within(bar).queryByRole('button', { name: 'Download .zip' })).toBeNull();
        const panel = await screen.findByRole('complementary', { name: 'Asset details' });
        await within(panel).findByText('Not used anywhere yet');
        expect(within(panel).queryByRole('button', { name: 'Delete file' })).toBeNull();
    });

    it('shows the no-results state with the filters echoed and a way to widen', async () => {
        mediaApi(mediaManager, undefined, []);
        renderApp('/store/media?folder=1&kind=image&q=duplo');
        expect(await screen.findByRole('heading', { name: 'No files match “duplo”' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Remove filter Folder: Products' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Search all 1,284 files' })).toBeTruthy();
    });

    it('renders in Bangla with Western digits', async () => {
        mediaApi(mediaManager);
        renderApp('/store/media', { locale: 'bn' });
        expect(await screen.findByRole('heading', { name: 'মিডিয়া লাইব্রেরি', level: 1 })).toBeTruthy();
        expect(await screen.findByText('1,284টি ফাইল · 3.2 GB ব্যবহৃত · 37টি ছবিতে বাংলা alt নেই')).toBeTruthy();
        expect(screen.getByRole('button', { name: /^পণ্য\s*846$/ })).toBeTruthy();
    });
});

describe('alt rules', () => {
    it('derives the chip state from both languages; documents need none', () => {
        expect(altState('image', 'Box', 'বাক্স')).toBe('complete');
        expect(altState('image', 'Box', '  ')).toBe('missingBn');
        expect(altState('image', null, 'বাক্স')).toBe('missingEn');
        expect(altState('image', '', null)).toBe('missingBoth');
        expect(altState('document', null, null)).toBe('notNeeded');
    });
});

describe('upload queue', () => {
    const file = (name: string, size = 1000) => new File([new Uint8Array(size)], name, { type: 'image/png' });

    it('surfaces the server’s 422 reason verbatim and never reports a failed file as uploaded', async () => {
        const onUploaded = vi.fn();
        const upload = vi.fn(async (f: File) => {
            if (f.name === 'fake.png') {
                throw new ApiError(422, {
                    type: 'https://toymartbd.com/problems/validation-error',
                    title: 'Validation failed',
                    status: 422,
                    errors: [{ field: 'file', message: 'Not a real image or PDF — the content is not JPG, PNG, WebP, AVIF or PDF.' }],
                });
            }
            return { id: 1, name: f.name };
        });
        const { result } = renderHook(() => useUploadQueue({ upload, onUploaded }));

        act(() => void result.current.add([file('good.png'), file('fake.png')]));
        await waitFor(() => expect(result.current.counts.done + result.current.counts.failed).toBe(2));

        const failed = result.current.items.find((i) => i.file.name === 'fake.png')!;
        expect(failed.status).toBe('failed');
        expect(failed.error).toBe('Not a real image or PDF — the content is not JPG, PNG, WebP, AVIF or PDF.');
        expect(failed.result).toBeNull();
        expect(onUploaded).toHaveBeenCalledTimes(1);
        expect(onUploaded.mock.calls[0]![1].name).toBe('good.png');
    });

    it('refuses an oversize file before sending, retries a server failure, and caps concurrency', async () => {
        let attempts = 0;
        let inFlight = 0;
        let peak = 0;
        const upload = vi.fn(async (f: File) => {
            inFlight += 1;
            peak = Math.max(peak, inFlight);
            await new Promise((r) => setTimeout(r, 5));
            inFlight -= 1;
            if (f.name === 'flaky.png' && attempts++ === 0) throw new ApiError(500, { type: 'about:blank', title: 'Server Error', status: 500 });
            return { id: f.name };
        });
        const { result } = renderHook(() =>
            useUploadQueue({ upload, concurrency: 2, precheck: (f) => (f.size > 10 * 1024 * 1024 ? '11.4 MB — over the 10 MB limit' : null) }),
        );

        act(() => void result.current.add([file('a.png'), file('b.png'), file('c.png'), file('flaky.png'), file('huge.png', 11.4 * 1024 * 1024)]));
        await waitFor(() => expect(result.current.busy).toBe(false));
        expect(peak).toBeLessThanOrEqual(2);
        expect(result.current.items.find((i) => i.file.name === 'huge.png')!.error).toBe('11.4 MB — over the 10 MB limit');
        expect(upload.mock.calls.some(([f]) => f.name === 'huge.png')).toBe(false);

        const flaky = result.current.items.find((i) => i.file.name === 'flaky.png')!;
        expect(flaky.status).toBe('failed');
        act(() => result.current.retry(flaky.key));
        await waitFor(() => expect(result.current.items.find((i) => i.file.name === 'flaky.png')!.status).toBe('done'));
    });
});

