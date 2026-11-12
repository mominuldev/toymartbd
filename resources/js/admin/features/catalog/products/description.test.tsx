import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { I18nProvider } from '@/i18n/I18nProvider';
import { createQueryClient } from '@/lib/queryClient';
import { ToastProvider } from '@/components/ui/Toast';
import { mockApi, renderApp } from '@/test/renderApp';
import { DescriptionEditor, isAllowedLink, isOwnMediaUrl } from './DescriptionEditor';
import { owner, product, productApi } from './fixtures';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    localStorage.clear();
});

function renderEditor(value = { en: '<p>Hello bricks</p>', bn: '<p>রঙিন ব্লক</p>' }) {
    mockApi(() => ({ status: 200, body: { data: [], meta: { next_cursor: null } } }));
    const onChange = vi.fn();
    const client = createQueryClient();
    render(
        <QueryClientProvider client={client}>
            <I18nProvider initialLocale="en">
                <ToastProvider>
                    <DescriptionEditor value={value} onChange={onChange} />
                </ToastProvider>
            </I18nProvider>
        </QueryClientProvider>,
    );
    return onChange;
}

const toolbar = () => screen.getByRole('toolbar', { name: 'Description formatting' });
const englishEditor = () => document.querySelector<HTMLElement>('[role="textbox"][lang="en"]')!;
const banglaEditor = () => document.querySelector<HTMLElement>('[role="textbox"][lang="bn"]')!;

/** Paste HTML at the caret, as from the clipboard (ProseMirror reads clipboardData). */
function pasteHtml(editor: HTMLElement, html: string) {
    act(() => {
        editor.focus();
        const event = new Event('paste', { bubbles: true, cancelable: true });
        Object.defineProperty(event, 'clipboardData', { value: { types: ['text/html', 'text/plain'], getData: (type: string) => (type === 'text/html' ? html : html.replace(/<[^>]+>/g, '')) } });
        editor.dispatchEvent(event);
    });
}

/** Select the editor's whole text, as the reader would with ⌘A. */
function selectAll(editor: HTMLElement) {
    act(() => {
        editor.focus();
        const range = document.createRange();
        range.selectNodeContents(editor);
        const selection = window.getSelection()!;
        selection.removeAllRanges();
        selection.addRange(range);
        document.dispatchEvent(new Event('selectionchange'));
    });
}

describe('DescriptionEditor (RichTextToolbar 201:1570)', () => {
    it('renders the Figma toolbar in order, with the no-backend buttons disabled and labelled “coming soon”', () => {
        renderEditor();
        const bar = within(toolbar());
        expect(bar.getByLabelText('Text style')).toBeTruthy();
        // Owner decision 2026-11-12: no clear-formatting ("Aa") button.
        expect(bar.queryByRole('button', { name: 'Clear formatting' })).toBeNull();
        const names = bar.getAllByRole('button').map((b) => b.getAttribute('aria-label') ?? b.textContent);
        expect(names.slice(0, 10)).toEqual(['Bold', 'Italic', 'Bulleted list', 'Numbered list', 'Quote', 'Link', 'Insert image', 'Embed a product', 'Embed a QR code', 'AI write']);

        for (const name of ['Embed a product', 'Embed a QR code']) {
            const button = bar.getByRole('button', { name });
            expect(button.getAttribute('aria-disabled')).toBe('true');
            expect(screen.getByRole('tooltip', { name: `${name} — coming soon`, hidden: true })).toBeTruthy();
        }
        expect(bar.getByRole('button', { name: 'AI write' }).getAttribute('aria-disabled')).toBe('true');
        expect(screen.getByText('AI write — coming soon')).toBeTruthy();
    });

    it('applies bold, lists, quote and headings to the selection and emits HTML', async () => {
        const onChange = renderEditor();
        selectAll(englishEditor());

        fireEvent.click(within(toolbar()).getByRole('button', { name: 'Bold' }));
        await waitFor(() => expect(onChange).toHaveBeenLastCalledWith('en', '<p><strong>Hello bricks</strong></p>'));
        expect(within(toolbar()).getByRole('button', { name: 'Bold' }).getAttribute('aria-pressed')).toBe('true');

        fireEvent.click(within(toolbar()).getByRole('button', { name: 'Bulleted list' }));
        await waitFor(() => expect(onChange).toHaveBeenLastCalledWith('en', '<ul><li><p><strong>Hello bricks</strong></p></li></ul>'));

        fireEvent.click(within(toolbar()).getByRole('button', { name: 'Bulleted list' }));
        fireEvent.click(within(toolbar()).getByRole('button', { name: 'Quote' }));
        await waitFor(() => expect(onChange).toHaveBeenLastCalledWith('en', '<blockquote><p><strong>Hello bricks</strong></p></blockquote>'));

        fireEvent.click(within(toolbar()).getByRole('button', { name: 'Quote' }));
        fireEvent.change(within(toolbar()).getByLabelText('Text style'), { target: { value: 'h2' } });
        await waitFor(() => expect(onChange).toHaveBeenLastCalledWith('en', '<h2><strong>Hello bricks</strong></h2>'));
    });

    it('links only http(s) and mailto addresses', async () => {
        const onChange = renderEditor();
        selectAll(englishEditor());

        fireEvent.click(within(toolbar()).getByRole('button', { name: 'Link' }));
        const url = screen.getByLabelText('Link address');
        fireEvent.change(url, { target: { value: 'javascript:alert(1)' } });
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
        expect(screen.getByText('Enter a link that starts with https://, http:// or mailto:.')).toBeTruthy();
        expect(onChange).not.toHaveBeenCalled();

        fireEvent.change(url, { target: { value: 'https://toymartbd.com/brands/lego' } });
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
        await waitFor(() => expect(onChange).toHaveBeenLastCalledWith('en', '<p><a href="https://toymartbd.com/brands/lego">Hello bricks</a></p>'));

        expect(isAllowedLink('mailto:help@toymartbd.com')).toBe(true);
        expect(isAllowedLink('data:text/html,x')).toBe(false);
    });

    it('switches between the English and Bangla editors, each with its own text', async () => {
        const onChange = renderEditor();
        expect(englishEditor().closest('[hidden]')).toBeNull();
        expect(banglaEditor().closest('[hidden]')).not.toBeNull();
        expect(banglaEditor().textContent).toBe('রঙিন ব্লক');
        expect(banglaEditor().className).toContain('lang-bn');

        fireEvent.click(screen.getByRole('button', { name: /^Editing English/ }));
        expect(englishEditor().closest('[hidden]')).not.toBeNull();
        expect(banglaEditor().closest('[hidden]')).toBeNull();

        // The toolbar now acts on the Bangla editor.
        selectAll(banglaEditor());
        fireEvent.click(within(toolbar()).getByRole('button', { name: 'Italic' }));
        await waitFor(() => expect(onChange).toHaveBeenLastCalledWith('bn', '<p><em>রঙিন ব্লক</em></p>'));
        expect(englishEditor().innerHTML).not.toContain('<em>');

        fireEvent.click(screen.getByRole('button', { name: /^Editing Bangla/ }));
        expect(englishEditor().closest('[hidden]')).toBeNull();
    });

    it('opens the media picker in single-select mode from Insert image', async () => {
        renderEditor();
        fireEvent.click(within(toolbar()).getByRole('button', { name: 'Insert image' }));

        const dialog = await screen.findByRole('dialog', { name: 'Insert an image into the description', hidden: true });
        expect(dialog).toBeTruthy();
        // Single select: no "Insert N" count, one image only.
        expect(within(dialog).getByText('One image from the media library. It is shown at its own width, up to the text column.')).toBeTruthy();
    });

    it('keeps only our own /m/ media images', () => {
        expect(isOwnMediaUrl('https://toymartbd.com/m/12/brick.webp')).toBe(true);
        expect(isOwnMediaUrl('/m/12/brick.webp')).toBe(true);
        expect(isOwnMediaUrl('https://evil.example/x.png')).toBe(false);
        expect(isOwnMediaUrl('javascript:alert(1)')).toBe(false);
    });
});

describe('product form description', () => {
    it('sends the editor HTML in the product document', async () => {
        let put: Record<string, unknown> | null = null;
        productApi(owner, (url, init) => {
            if (url.endsWith('/products/42') && init?.method === 'PUT') {
                put = JSON.parse(String(init.body)) as Record<string, unknown>;
                return { status: 200, body: product };
            }
            return undefined;
        });
        renderApp('/products/42');

        await screen.findByRole('toolbar', { name: 'Description formatting' });
        pasteHtml(englishEditor(), '<p>Bricks <strong>galore</strong></p><img src="https://evil.example/x.png">');
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(put).not.toBeNull());
        // The pasted foreign image never enters the editor; the API sanitises the rest again.
        expect(put!.description).toBe('<p>Bricks <strong>galore</strong></p>');
    });
});
