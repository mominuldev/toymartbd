import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderApp } from '@/test/renderApp';
import { owner, product, productApi, sentBody } from '@/features/catalog/products/fixtures';
import { productFieldsFromCopy, type ProductCopyDraft } from './api';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    localStorage.clear();
});

const draft: ProductCopyDraft = {
    en: {
        title: 'Brick Box 500 — Building Set',
        description_html: '<p>Build <strong>anything</strong>.</p><ul><li>For ages 4 to 8 years</li></ul>',
        seo_title: 'Brick Box 500 Building Set | Smart Junior',
        meta_description: 'A building set for ages 4 to 8 years.',
        too_long: [],
        unknown_figures: [],
    },
    bn: {
        title: 'ব্রিক বক্স 500 — বিল্ডিং সেট',
        description_html: '<p>যা খুশি <strong>বানাও</strong>।</p><ul><li>4 থেকে 8 বছর বয়সের জন্য</li></ul>',
        seo_title: 'ব্রিক বক্স 500 বিল্ডিং সেট',
        meta_description: '4 থেকে 8 বছরের শিশুদের বিল্ডিং সেট।',
        too_long: [],
        unknown_figures: ['250'],
    },
    model: 'claude-opus-5-5',
    facts: [
        { key: 'name', value: 'Brick Box 500' },
        { key: 'age', value: '4 years to 8 years' },
        { key: 'option', value: 'Colour: Red, Blue' },
        { key: 'price', value: '৳1,850' },
    ],
};

type Reply = { status: number; body?: unknown };

function setup(aiReply: () => Reply, locale: 'en' | 'bn' = 'en') {
    const posts: unknown[] = [];
    const calls = productApi(owner, (url, init) => {
        if (url.endsWith('/products/42/ai-copy')) {
            posts.push(JSON.parse(String(init?.body)));
            return aiReply();
        }
        if (url.endsWith('/products/42') && init?.method === 'PUT') return { status: 200, body: product };
        return undefined;
    });
    renderApp('/products/42', { locale });
    return { posts, calls };
}

async function openDialog(name = 'AI write', title = 'Write with AI') {
    fireEvent.click(await screen.findByRole('button', { name }));
    return screen.findByRole('dialog', { name: title, hidden: true });
}

describe('Write with AI (Figma 538:82911)', () => {
    it('sends exactly the choices and shows the facts, the model and the draft per language', async () => {
        const { posts } = setup(() => ({ status: 200, body: draft }));
        const dialog = within(await openDialog());
        expect(dialog.getByText('Facts appear after the first draft: the name, category, brand, age range, options and price as saved.')).toBeTruthy();

        fireEvent.click(dialog.getByRole('checkbox', { name: 'Description' }));
        fireEvent.click(dialog.getByRole('button', { name: 'Playful' }));
        fireEvent.change(dialog.getByRole('textbox', { name: 'Keywords to include' }), { target: { value: '  lego, building blocks ' } });
        fireEvent.click(dialog.getByRole('button', { name: 'Write draft' }));

        await dialog.findByRole('button', { name: 'Write again' });
        expect(posts).toEqual([{ fields: ['title', 'seo'], language: 'both', tone: 'playful', keywords: 'lego, building blocks' }]);
        expect(dialog.getByText('Claude Opus 5.5')).toBeTruthy();
        expect(dialog.getByText('Price ৳1,850 (not changed by AI)')).toBeTruthy();
        expect(dialog.getByText('Colour: Red, Blue')).toBeTruthy();
        expect(dialog.getByText('The AI only uses these facts. It is told never to invent safety claims or certifications.')).toBeTruthy();
        expect((dialog.getByRole('textbox', { name: 'Title' }) as HTMLInputElement).value).toBe('Brick Box 500 — Building Set');
        expect(dialog.getByText('28 / 70')).toBeTruthy();
        expect(dialog.getByText('41 / 60')).toBeTruthy();

        fireEvent.click(within(dialog.getByRole('group', { name: 'Draft language' })).getByRole('button', { name: 'বাংলা' }));
        expect((dialog.getByRole('textbox', { name: 'Title' }) as HTMLInputElement).value).toBe('ব্রিক বক্স 500 — বিল্ডিং সেট');
        expect(dialog.getByText('Check these figures — they are not in the product’s facts: 250')).toBeTruthy();

        fireEvent.click(dialog.getByRole('button', { name: 'Write again' }));
        await waitFor(() => expect(posts).toHaveLength(2));
    });

    it('“Use these texts” fills the English, Bangla and SEO fields, marks the form dirty and saves nothing until Save', async () => {
        const { calls } = setup(() => ({ status: 200, body: draft }));
        const dialog = within(await openDialog());
        fireEvent.click(dialog.getByRole('button', { name: 'Write draft' }));
        const title = await dialog.findByRole('textbox', { name: 'Title' });
        fireEvent.change(title, { target: { value: 'Brick Box 500 — edited' } });
        fireEvent.click(dialog.getByRole('button', { name: 'Use these texts' }));

        await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Write with AI', hidden: true })).toBeNull());
        expect((screen.getByRole('textbox', { name: 'Name (English)' }) as HTMLInputElement).value).toBe('Brick Box 500 — edited');
        expect((screen.getByRole('textbox', { name: 'নাম (বাংলা)' }) as HTMLInputElement).value).toBe('ব্রিক বক্স 500 — বিল্ডিং সেট');
        expect(document.querySelector('[role="textbox"][lang="en"]')!.innerHTML).toContain('<strong>anything</strong>');
        expect(screen.getByText('AI texts added to the form. Review them, then save the product.')).toBeTruthy();
        expect(sentBody(calls, 'PUT')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(sentBody(calls, 'PUT')).not.toBeNull());
        const put = sentBody(calls, 'PUT')!;
        expect(put.name).toBe('Brick Box 500 — edited');
        expect(put.name_bn).toBe('ব্রিক বক্স 500 — বিল্ডিং সেট');
        expect(put.slug).toBe('brick-box-500');
        expect(put.description).toBe('<p>Build <strong>anything</strong>.</p><ul><li>For ages 4 to 8 years</li></ul>');
        expect(put.seo).toMatchObject({ title: 'Brick Box 500 Building Set | Smart Junior', title_bn: 'ব্রিক বক্স 500 বিল্ডিং সেট', description: 'A building set for ages 4 to 8 years.', description_bn: '4 থেকে 8 বছরের শিশুদের বিল্ডিং সেট।' });
    });

    it('Cancel closes without touching the form', async () => {
        const { calls } = setup(() => ({ status: 200, body: draft }));
        const dialog = within(await openDialog());
        fireEvent.click(dialog.getByRole('button', { name: 'Write draft' }));
        await dialog.findByRole('button', { name: 'Write again' });
        fireEvent.click(dialog.getByRole('button', { name: 'Cancel' }));
        await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Write with AI', hidden: true })).toBeNull());
        expect((screen.getByRole('textbox', { name: 'Name (English)' }) as HTMLInputElement).value).toBe('Brick Box 500');
        expect(sentBody(calls, 'PUT')).toBeNull();
    });

    it('shows the AI notice on a provider failure, and the sharing notice when product details are off', async () => {
        let reply: Reply = { status: 503, body: { type: 'https://toymartbd.com/problems/ai-unavailable', title: 'AI is unavailable', status: 503, detail: 'busy' } };
        setup(() => reply);
        const dialog = within(await openDialog());
        fireEvent.click(dialog.getByRole('button', { name: 'Write draft' }));
        expect(await dialog.findByText('The AI could not answer just now')).toBeTruthy();
        expect(dialog.getByRole('button', { name: 'Use these texts' }).hasAttribute('disabled')).toBe(true);

        reply = { status: 409, body: { type: 'https://toymartbd.com/problems/ai-not-shared', title: 'Not shared with AI', status: 409, detail: 'Product details are not shared with AI.' } };
        fireEvent.click(dialog.getByRole('button', { name: 'Try again' }));
        expect(await dialog.findByText('Product details are not shared with AI')).toBeTruthy();
        expect(dialog.getByRole('link', { name: 'Open AI settings' }).getAttribute('href')).toBe('/integrations/ai');
    });

    it('runs in Bangla with Western digits', async () => {
        setup(() => ({ status: 200, body: draft }), 'bn');
        const element = await openDialog('AI লিখুন', 'AI দিয়ে লিখুন');
        const dialog = within(element);
        fireEvent.click(dialog.getByRole('button', { name: 'খসড়া লিখুন' }));
        await dialog.findByRole('button', { name: 'আবার লিখুন' });
        expect(dialog.getByText('দাম ৳1,850 (AI বদলায় না)')).toBeTruthy();
        expect(dialog.getByText('28 / 70')).toBeTruthy();
        expect(element.textContent ?? '').not.toMatch(/[০-৯]/);
    });

    it('maps each text to its product field and never to the slug', () => {
        expect(productFieldsFromCopy({ en: { title: 'A', meta_description: 'M' }, bn: { description_html: '<p>B</p>', seo_title: 'S' } })).toEqual([
            ['name', 'A'],
            ['seo.description', 'M'],
            ['description_bn', '<p>B</p>'],
            ['seo.title_bn', 'S'],
        ]);
    });
});
