import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { AiSettings } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
    vi.unstubAllGlobals();
});

const owner: Me = { ...viewer, roles: ['owner'], permissions: ['dashboard.view', 'settings.view', 'settings.update', 'settings.manage_integrations'] };
const readOnly: Me = { ...viewer, roles: ['viewer'], permissions: ['dashboard.view', 'settings.view'] };

const models = {
    anthropic: [
        { id: 'claude-opus-5-5', label: 'Claude Opus 5.5', input_usd_per_million: '5.00', output_usd_per_million: '25.00' },
        { id: 'claude-haiku-5-5', label: 'Claude Haiku 5.5', input_usd_per_million: '1.00', output_usd_per_million: '5.00' },
    ],
    openai: [
        { id: 'gpt-6-astra', label: 'GPT-6 Astra', input_usd_per_million: '5.00', output_usd_per_million: '20.00' },
        { id: 'gpt-6-luna', label: 'GPT-6 Luna', input_usd_per_million: '0.50', output_usd_per_million: '2.00' },
    ],
    gemini: [
        { id: 'gemini-4-carbon', label: 'Gemini 4 Carbon', input_usd_per_million: '2.00', output_usd_per_million: '12.00' },
        { id: 'gemini-3.5-flash-lite', label: 'Gemini 3.5 Flash-Lite', input_usd_per_million: '0.10', output_usd_per_million: '0.40' },
    ],
};

const minutesAgo = (n: number) => new Date(Date.now() - n * 60_000).toISOString();

function settings(over: Partial<AiSettings> = {}): AiSettings {
    return {
        providers: [
            { provider: 'anthropic', label: 'Anthropic Claude', connected: true, last4: '7Hk2', checked_at: minutesAgo(5), check_ok: true, default_model: 'claude-opus-5-5', models: models.anthropic },
            { provider: 'openai', label: 'OpenAI', connected: false, last4: null, checked_at: null, check_ok: null, default_model: 'gpt-6-astra', models: models.openai },
            { provider: 'gemini', label: 'Google Gemini', connected: true, last4: 'Zz90', checked_at: minutesAgo(60), check_ok: false, default_model: 'gemini-4-carbon', models: models.gemini },
        ],
        features: [
            { feature: 'advisor', provider: 'anthropic', model: 'claude-opus-5-5', connected: true },
            { feature: 'product_copy', provider: 'anthropic', model: 'claude-opus-5-5', connected: true },
            { feature: 'inbox_reply', provider: 'openai', model: 'gpt-6-astra', connected: false },
            { feature: 'restock_note', provider: 'anthropic', model: 'claude-haiku-5-5', connected: true },
        ],
        cap_usd: '50.00',
        share: { figures: true, products: true, first_names: true },
        usage: { spent_usd: '12.40', requests: 37, failed: 0, by_feature: { advisor: '8.10', product_copy: '3.20', inbox_reply: '0.00', restock_note: '1.10' }, since: '2026-10-31T18:00:00+00:00' },
        can: { update: true, manage_keys: true },
        ...over,
    };
}

type Override = (url: string, init?: RequestInit) => { status: number; body?: unknown } | undefined;

function aiApi(me: Me, data: AiSettings = settings(), override?: Override) {
    return mockApi((url, init) => {
        const custom = override?.(url, init);
        if (custom) return custom;
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/settings/ai') && init?.method === 'PUT') return { status: 200, body: data };
        if (url.endsWith('/settings/ai')) return { status: 200, body: data };
        if (url.endsWith('/settings/ai/providers/openai') && init?.method === 'PUT') {
            return {
                status: 200,
                body: { ...data, providers: data.providers.map((p) => (p.provider === 'openai' ? { ...p, connected: true, last4: 'abcd', checked_at: new Date().toISOString(), check_ok: true } : p)) },
            };
        }
        if (url.includes('/integrations')) return { status: 200, body: { data: [] } };
        return { status: 404 };
    });
}

const sent = (calls: ReturnType<typeof aiApi>, path: string, method: string) =>
    calls.mock.calls
        .filter(([url, init]) => String(url).endsWith(path) && (init as RequestInit | undefined)?.method === method)
        .map(([, init]) => JSON.parse(String((init as RequestInit).body)) as unknown);

async function open(me: Me = owner, data?: AiSettings, override?: Override, locale: 'en' | 'bn' = 'en') {
    const calls = aiApi(me, data, override);
    renderApp('/integrations/ai', { locale });
    await screen.findByRole('heading', { level: 1, name: locale === 'bn' ? 'AI সহকারী' : 'AI assistant' }, { timeout: 10_000 });
    await screen.findByRole('listitem', { name: 'Anthropic Claude' }, { timeout: 10_000 });
    return calls;
}

const card = (name: string) => screen.getByRole('listitem', { name });

describe('Integrations › AI assistant', () => {
    it('shows each provider with its status, key ending and default model', async () => {
        await open();

        const claude = within(card('Anthropic Claude'));
        expect(claude.getByText('Connected')).toBeTruthy();
        expect(claude.getByText(/Key ••••7Hk2 · checked/)).toBeTruthy();
        expect(claude.getByText('Default model Claude Opus 5.5')).toBeTruthy();
        expect(claude.getByRole('button', { name: 'Check connection' })).toBeTruthy();
        expect(claude.getByRole('button', { name: 'Replace key' })).toBeTruthy();
        expect(claude.getByRole('button', { name: 'Remove Anthropic Claude key' })).toBeTruthy();

        const openai = within(card('OpenAI'));
        expect(openai.getByText('Not connected')).toBeTruthy();
        const input = openai.getByLabelText('OpenAI API key') as HTMLInputElement;
        expect(input.type).toBe('password');
        expect(input.autocomplete).toBe('off');
        expect(openai.getByText('Paste an API key from platform.openai.com')).toBeTruthy();

        expect(within(card('Google Gemini')).getByText('Key rejected')).toBeTruthy();

        // A feature routed to an unconnected provider says so.
        const inbox = document.querySelector('[data-feature="inbox_reply"]') as HTMLElement;
        expect(within(inbox).getByText('Not connected')).toBeTruthy();
        expect(document.querySelector('[data-feature="advisor"]')?.textContent).not.toContain('Not connected');
    });

    it('connects a key with the exact body, clears the field and never echoes the key', async () => {
        const calls = await open();
        const openai = within(card('OpenAI'));
        const input = openai.getByLabelText('OpenAI API key') as HTMLInputElement;

        fireEvent.change(input, { target: { value: 'sk-proj-test-key-0000abcd' } });
        fireEvent.click(openai.getByRole('button', { name: 'Connect' }));

        await waitFor(() => expect(sent(calls, '/settings/ai/providers/openai', 'PUT')).toEqual([{ api_key: 'sk-proj-test-key-0000abcd' }]));
        await waitFor(() => expect(within(card('OpenAI')).getByText('Connected')).toBeTruthy());
        expect(within(card('OpenAI')).queryByLabelText('OpenAI API key')).toBeNull();
        expect(document.body.textContent).not.toContain('sk-proj-test-key');
        expect(within(card('OpenAI')).getByText(/Key ••••abcd/)).toBeTruthy();
    });

    it('shows a rejected key inline and sends nothing for a too-short key', async () => {
        const calls = await open(owner, settings(), (url, init) =>
            url.endsWith('/settings/ai/providers/openai') && init?.method === 'PUT'
                ? {
                      status: 503,
                      body: { type: 'https://toymartbd.com/problems/ai-unavailable', title: 'AI unavailable', status: 503, errors: [{ field: 'provider', message: 'auth_failed' }] },
                  }
                : undefined,
        );
        const openai = within(card('OpenAI'));
        const input = openai.getByLabelText('OpenAI API key') as HTMLInputElement;

        fireEvent.change(input, { target: { value: 'short' } });
        fireEvent.click(openai.getByRole('button', { name: 'Connect' }));
        expect(sent(calls, '/settings/ai/providers/openai', 'PUT')).toEqual([]);
        expect(input.getAttribute('aria-invalid')).toBe('true');

        fireEvent.change(input, { target: { value: 'bad-key-0000000000000000' } });
        fireEvent.click(openai.getByRole('button', { name: 'Connect' }));
        expect(await openai.findByText('The provider rejected this key')).toBeTruthy();
        expect(within(card('OpenAI')).getByText('Not connected')).toBeTruthy();
    });

    it('saves routing, cap and sharing with the generated-schema body', async () => {
        const calls = await open();

        fireEvent.change(screen.getByRole('combobox', { name: 'Model for Growth advisor' }), { target: { value: 'claude-haiku-5-5' } });
        fireEvent.click(screen.getByRole('switch', { name: 'Customer first names' }));
        const cap = screen.getByLabelText('Monthly cap');
        fireEvent.change(cap, { target: { value: '20' } });

        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() =>
            expect(sent(calls, '/settings/ai', 'PUT')).toEqual([
                {
                    features: {
                        advisor: { provider: 'anthropic', model: 'claude-haiku-5-5' },
                        product_copy: { provider: 'anthropic', model: 'claude-opus-5-5' },
                        inbox_reply: { provider: 'openai', model: 'gpt-6-astra' },
                        restock_note: { provider: 'anthropic', model: 'claude-haiku-5-5' },
                    },
                    cap_usd: '20',
                    share: { figures: true, products: true, first_names: false },
                },
            ]),
        );
    });

    it('rejects a malformed cap before sending', async () => {
        const calls = await open();
        fireEvent.change(screen.getByLabelText('Monthly cap'), { target: { value: '12.345' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(screen.getByLabelText('Monthly cap').getAttribute('aria-invalid')).toBe('true'));
        expect(sent(calls, '/settings/ai', 'PUT')).toEqual([]);
    });

    it("switching provider resets the model to that provider's default", async () => {
        await open();
        const provider = screen.getByRole('combobox', { name: 'Provider for Restock notes' }) as HTMLSelectElement;
        const model = screen.getByRole('combobox', { name: 'Model for Restock notes' }) as HTMLSelectElement;
        expect(model.value).toBe('claude-haiku-5-5');

        fireEvent.change(provider, { target: { value: 'gemini' } });
        expect(provider.value).toBe('gemini');
        expect(model.value).toBe('gemini-4-carbon');
        expect(within(model).getAllByRole('option').map((o) => o.textContent)).toEqual(['Gemini 4 Carbon', 'Gemini 3.5 Flash-Lite']);
    });

    it('lists phone numbers, addresses and payment details as locked, never sent', async () => {
        await open();
        for (const item of ['Phone numbers', 'Addresses', 'Payment details']) {
            const toggle = screen.getByRole('switch', { name: `${item} — never sent` }) as HTMLButtonElement;
            expect(toggle.disabled).toBe(true);
            expect(toggle.getAttribute('aria-checked')).toBe('false');
        }
        expect(screen.getAllByText('Never sent')).toHaveLength(3);
        expect(screen.getByText(/never sends a message, changes a price/)).toBeTruthy();
    });

    it('is read-only without settings.update and hides key controls without manage_keys', async () => {
        await open(readOnly, settings({ can: { update: false, manage_keys: false } }));

        expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
        expect(screen.getByText(/You can see these settings but not change them/)).toBeTruthy();
        expect((screen.getByRole('combobox', { name: 'Provider for Growth advisor' }) as HTMLSelectElement).disabled).toBe(true);
        expect((screen.getByRole('switch', { name: 'Product details' }) as HTMLButtonElement).disabled).toBe(true);
        expect((screen.getByLabelText('Monthly cap') as HTMLInputElement).disabled).toBe(true);
        expect(within(card('OpenAI')).queryByLabelText('OpenAI API key')).toBeNull();
        expect(within(card('Anthropic Claude')).queryByRole('button', { name: 'Check connection' })).toBeNull();
        expect(within(card('Anthropic Claude')).getByText('Connected')).toBeTruthy();
    });

    it('renders in Bangla with Western digits', async () => {
        await open(owner, undefined, undefined, 'bn');

        expect(screen.getByRole('heading', { name: 'কোন AI কী কাজ করবে' })).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'এ মাসের খরচ' })).toBeTruthy();
        expect(screen.getByTestId('ai-spent').textContent).toBe('$12.40');
        expect(screen.getByTestId('ai-requests').textContent).toBe('37');
        expect(screen.getByText('$50.00 সীমার মধ্যে')).toBeTruthy();
        expect(within(card('Anthropic Claude')).getByText(/কী ••••7Hk2/)).toBeTruthy();
        expect(document.querySelector('main, body')?.textContent ?? '').not.toMatch(/[০-৯]/);
    });
});

describe('key links', () => {
    it('links each unconnected provider to its own API-key page, in a new tab', async () => {
        await open();
        const link = await screen.findByRole('link', { name: /Get an API key from OpenAI/ });
        expect(link.getAttribute('href')).toBe('https://platform.openai.com/api-keys');
        expect(link.getAttribute('target')).toBe('_blank');
        expect(link.getAttribute('rel')).toBe('noopener noreferrer');
    });
});

