import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Conversation, ConversationDetail, InboxMessage } from '@/features/inbox/api';
import type { AiInboxDraft } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const operator: Me = {
    ...viewer,
    id: 3,
    name: 'Rina Akter',
    roles: ['order_operator'],
    permissions: ['dashboard.view', 'inbox.view', 'inbox.reply', 'customers.view'],
};

const soon = new Date(Date.now() + 20 * 3600_000).toISOString();
const ago = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString();

const rafi: Conversation = {
    id: 1,
    channel: 'whatsapp',
    status: 'open',
    contact: { name: 'Rafi Ahmed', phone_masked: '017****5678', phone: null },
    customer_id: null,
    assignee: null,
    needs_human: true,
    unread_count: 0,
    last_message_at: ago(2),
    last_message_preview: 'আমার অর্ডার TMB-26-00126 কবে পাব?',
    last_message_direction: 'in',
    window: { applies: true, open: true, expires_at: soon },
    reply: { state: 'open', window_closes_at: soon },
};
const detail: ConversationDetail = { ...rafi, customer: null };

const message: InboxMessage = {
    id: 11,
    direction: 'in',
    kind: 'text',
    body: 'আমার অর্ডার TMB-26-00126 কবে পাব?',
    system_event: null,
    assignee_name: null,
    actor: null,
    unsupported_type: null,
    template: null,
    tag: null,
    sender: null,
    delivery_status: null,
    failure_code: null,
    failure_reason: null,
    at: ago(2),
    read_at: null,
};

const banglaDraft: AiInboxDraft = {
    text: 'ওয়ালাইকুম আসসালাম রাফি ভাই! আপনার অর্ডার TMB-26-00126 Steadfast-এ পথে আছে। ট্র্যাক করতে: toymartbd.com/track',
    language: 'bn',
    based_on: ['Order TMB-26-00126', 'Steadfast · In transit · 2 h ago'],
    model: 'claude-opus-5-5',
    seconds: 3.2,
    window_open: true,
    reply_state: 'open',
};
const englishDraft: AiInboxDraft = { ...banglaDraft, text: 'Hi Rafi, your order TMB-26-00126 is on the way with Steadfast.', language: 'en' };

type Reply = { status: number; body?: unknown };

function api(draft: (body: Record<string, unknown>) => Reply) {
    return mockApi((url, init) => {
        const method = init?.method ?? 'GET';
        const path = new URL(url, 'http://x').pathname.replace('/api/v1/admin', '');
        if (path === '/auth/me') return { status: 200, body: operator };
        if (path === '/inbox/conversations') return { status: 200, body: { data: [rafi], links: {}, meta: { next_cursor: null, prev_cursor: null, per_page: 30, counts: { all: 1, mine: 0, needs_human: 1 } } } };
        if (path === '/inbox/conversations/1' || path === '/inbox/conversations/1/read') return { status: 200, body: detail };
        if (path === '/inbox/conversations/1/messages' && method === 'GET') return { status: 200, body: { data: [message], links: {}, meta: { next_cursor: null, prev_cursor: null, per_page: 50 } } };
        if (path === '/inbox/conversations/1/ai-draft' && method === 'POST') return draft(JSON.parse(String(init?.body ?? '{}')) as Record<string, unknown>);
        return { status: 404, body: { type: 'https://toymartbd.com/problems/not-found', title: 'Not found', status: 404 } };
    });
}

const calls = (fetchMock: ReturnType<typeof mockApi>, method: string, fragment: string) =>
    fetchMock.mock.calls.filter(([u, init]) => (init?.method ?? 'GET') === method && String(u).includes(fragment));

async function openDraft() {
    fireEvent.click(await screen.findByRole('button', { name: 'Draft with AI' }));
    return screen.findByRole('region', { name: 'Suggested reply' });
}

describe('Inbox › Suggested reply', () => {
    it('drafts in Bangla with lang="bn", shows the model, seconds and the facts it used', async () => {
        api(() => ({ status: 200, body: banglaDraft }));
        renderApp('/inbox/1');

        const card = await openDraft();
        const text = await within(card).findByText(banglaDraft.text);
        expect(text.getAttribute('lang')).toBe('bn');
        expect(text.className).toContain('lang-bn');
        expect(within(card).getByText(/Suggested reply · বাংলা/)).toBeTruthy();
        expect(within(card).getByText('Claude Opus 5.5 · 3.2 s')).toBeTruthy();
        expect(within(card).getByText('Order TMB-26-00126')).toBeTruthy();
        expect(within(card).getByText('Steadfast · In transit · 2 h ago')).toBeTruthy();
        expect(within(card).getByRole('button', { name: 'In English' })).toBeTruthy();
        expect(within(card).getByText(/The AI never sends, and never sees the customer’s phone number or address/)).toBeTruthy();
    });

    it('Use draft fills the composer for editing and sends nothing', async () => {
        const fetchMock = api(() => ({ status: 200, body: banglaDraft }));
        renderApp('/inbox/1');

        const card = await openDraft();
        fireEvent.click(await within(card).findByRole('button', { name: 'Use draft' }));

        const box = screen.getByPlaceholderText(/reply/i) as HTMLTextAreaElement;
        await waitFor(() => expect(box.value).toBe(banglaDraft.text));
        expect(screen.queryByRole('region', { name: 'Suggested reply' })).toBeNull();
        expect(calls(fetchMock, 'POST', '/inbox/conversations/1/messages')).toHaveLength(0);
        expect(calls(fetchMock, 'POST', '/ai-draft')).toHaveLength(1);
    });

    it('Shorter and In English re-request with the style', async () => {
        const fetchMock = api((body) => ({ status: 200, body: body.style === 'english' ? englishDraft : banglaDraft }));
        renderApp('/inbox/1');

        const card = await openDraft();
        fireEvent.click(await within(card).findByRole('button', { name: 'Shorter' }));
        await waitFor(() => expect(calls(fetchMock, 'POST', '/ai-draft')).toHaveLength(2));
        expect(JSON.parse(String(calls(fetchMock, 'POST', '/ai-draft')[1]?.[1]?.body))).toEqual({ style: 'shorter' });

        fireEvent.click(await within(card).findByRole('button', { name: 'In English' }));
        const english = await within(card).findByText(englishDraft.text);
        expect(JSON.parse(String(calls(fetchMock, 'POST', '/ai-draft')[2]?.[1]?.body))).toEqual({ style: 'english' });
        expect(english.getAttribute('lang')).toBe('en');
        expect(within(card).getByRole('button', { name: 'In Bangla' })).toBeTruthy();
    });

    it('Discard closes the card', async () => {
        api(() => ({ status: 200, body: banglaDraft }));
        renderApp('/inbox/1');

        const card = await openDraft();
        fireEvent.click(await within(card).findByRole('button', { name: 'Discard' }));
        expect(screen.queryByRole('region', { name: 'Suggested reply' })).toBeNull();
    });

    it('says a template is needed when the window has closed, and offers no Use draft', async () => {
        api(() => ({ status: 200, body: { ...englishDraft, window_open: false, reply_state: 'template_only' } }));
        renderApp('/inbox/1');

        const card = await openDraft();
        await within(card).findByText(englishDraft.text);
        expect(within(card).getByText(/Send an approved template instead/)).toBeTruthy();
        expect(within(card).queryByRole('button', { name: 'Use draft' })).toBeNull();
    });

    it('shows a calm notice, not a raw error, when AI is not set up', async () => {
        api(() => ({ status: 409, body: { type: 'https://toymartbd.com/problems/ai-not-configured', title: 'AI is not set up', status: 409 } }));
        renderApp('/inbox/1');

        fireEvent.click(await screen.findByRole('button', { name: 'Draft with AI' }));
        const alert = await screen.findByRole('alert');
        expect(within(alert).getByText('AI is not set up for this')).toBeTruthy();
        expect(within(alert).getByRole('link', { name: 'Open AI settings' })).toBeTruthy();
    });

    it('offers a retry when the provider failed', async () => {
        let n = 0;
        const fetchMock = api(() => (++n === 1 ? { status: 503, body: { type: 'https://toymartbd.com/problems/ai-unavailable', title: 'AI is unavailable', status: 503 } } : { status: 200, body: englishDraft }));
        renderApp('/inbox/1');

        fireEvent.click(await screen.findByRole('button', { name: 'Draft with AI' }));
        const alert = await screen.findByRole('alert');
        fireEvent.click(within(alert).getByRole('button', { name: 'Try again' }));
        expect(await screen.findByText(englishDraft.text)).toBeTruthy();
        expect(calls(fetchMock, 'POST', '/ai-draft')).toHaveLength(2);
    });
});
