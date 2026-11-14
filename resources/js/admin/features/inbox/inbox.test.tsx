import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Conversation, ConversationDetail, InboxMessage } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const operator: Me = {
    ...viewer,
    id: 3,
    name: 'Rina Akter',
    roles: ['order_operator'],
    permissions: ['dashboard.view', 'inbox.view', 'inbox.reply', 'customers.view', 'customers.view_pii', 'orders.create', 'risk.view', 'catalog.view'],
};
const reader: Me = { ...viewer, id: 4, roles: ['custom'], permissions: ['dashboard.view', 'inbox.view'] };

const soon = new Date(Date.now() + 20 * 3600_000).toISOString();
const ago = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString();

const nusrat: Conversation = {
    id: 1,
    channel: 'whatsapp',
    status: 'open',
    contact: { name: 'Nusrat Jahan', phone_masked: '018****3907', phone: '+8801841523907' },
    customer_id: 11,
    assignee: null,
    needs_human: true,
    unread_count: 3,
    last_message_at: ago(2),
    last_message_preview: 'আমার পার্সেল কোথায়? অর্ডার TMB-26-00121',
    last_message_direction: 'in',
    window: { applies: true, open: true, expires_at: soon },
};
const shahriar: Conversation = {
    ...nusrat,
    id: 2,
    channel: 'messenger',
    contact: { name: 'Shahriar Kabir', phone_masked: null, phone: null },
    customer_id: null,
    needs_human: false,
    unread_count: 0,
    last_message_at: ago(12),
    last_message_preview: 'Can you deliver tonight in Bashundhara?',
};
const farhana: Conversation = { ...nusrat, id: 3, channel: 'live_chat', status: 'resolved', needs_human: false, unread_count: 0, contact: { name: 'Farhana Yasmin', phone_masked: null, phone: null }, last_message_at: ago(60), last_message_preview: 'Thanks! Received the doll set' };

const detail = (c: Conversation, extra: Partial<ConversationDetail> = {}): ConversationDetail => ({
    ...c,
    customer:
        c.customer_id === null
            ? null
            : {
                  id: c.customer_id,
                  name: 'Nusrat Jahan',
                  orders_count: 2,
                  delivered_orders_count: 1,
                  lifetime_value: '2180.00',
                  preferred_language: 'bn',
                  risk: { score: 23, level: 'low', source: 'ours', computed_at: ago(60) },
                  last_order: { id: 121, number: 'TMB-26-00121', status: 'returned', grand_total: '2280.00', placed_at: ago(9000), items_summary: 'Baby Activity Gym Mat × 1', items_count: 1 },
              },
    ...extra,
});

const msg = (m: Partial<InboxMessage> & Pick<InboxMessage, 'id'>): InboxMessage => ({
    direction: 'in',
    kind: 'text',
    body: null,
    system_event: null,
    assignee_name: null,
    actor: null,
    unsupported_type: null,
    sender: null,
    delivery_status: null,
    failure_code: null,
    failure_reason: null,
    at: ago(5),
    read_at: null,
    ...m,
});

// Newest first, as the API pages them.
const thread: InboxMessage[] = [
    msg({ id: 14, direction: 'out', body: 'Apa, ami Rina. Apnar order ta abar pathate pari.', sender: { id: 3, name: 'Rina Akter' }, delivery_status: 'read' }),
    msg({ id: 13, direction: 'out', kind: 'note', body: 'Customer refused at door on 06 Nov.', sender: { id: 3, name: 'Rina Akter' } }),
    msg({ id: 12, direction: 'out', kind: 'system', system_event: 'assigned', assignee_name: 'Rina Akter', actor: 'Mominul Islam' }),
    msg({ id: 11, body: 'আমার পার্সেল কোথায়? অর্ডার TMB-26-00121' }),
];

type Reply = { status: number; body?: unknown };

function inboxApi(me: Me, overrides: Partial<Record<string, Reply | ((init?: RequestInit, url?: string) => Reply)>> = {}) {
    const details: Record<number, ConversationDetail> = { 1: detail(nusrat), 2: detail(shahriar), 3: detail(farhana) };
    return mockApi((url, init) => {
        const method = init?.method ?? 'GET';
        const parsed = new URL(url, 'http://x');
        const path = parsed.pathname.replace('/api/v1/admin', '');
        const override = overrides[`${method} ${path}`];
        if (override) return typeof override === 'function' ? override(init, url) : override;
        if (path === '/auth/me') return { status: 200, body: me };
        if (path === '/inbox/conversations') {
            const view = parsed.searchParams.get('filter[view]');
            const rows = view === 'mine' ? [] : view === 'needs_human' ? [nusrat] : [nusrat, shahriar, farhana];
            return { status: 200, body: { data: rows, links: {}, meta: { next_cursor: null, prev_cursor: null, per_page: 30, counts: { all: 3, mine: 0, needs_human: 1 } } } };
        }
        const m = /^\/inbox\/conversations\/(\d+)(?:\/(\w[\w-]*))?$/.exec(path);
        if (m) {
            const id = Number(m[1]);
            if (!m[2]) return { status: 200, body: details[id] };
            if (m[2] === 'messages' && method === 'GET') return { status: 200, body: { data: id === 1 ? thread : [], links: {}, meta: { next_cursor: null, prev_cursor: null, per_page: 50 } } };
            if (m[2] === 'read') return { status: 200, body: { ...details[id], unread_count: 0 } };
            if (m[2] === 'messages') return { status: 202, body: msg({ id: 99, direction: 'out', body: JSON.parse(String(init?.body)).body, delivery_status: 'queued' }) };
            if (m[2] === 'notes') return { status: 201, body: msg({ id: 98, direction: 'out', kind: 'note', body: JSON.parse(String(init?.body)).body }) };
            if (m[2] === 'assign') return { status: 200, body: { ...details[id], assignee: { id: me.id, name: me.name } } };
            if (m[2] === 'resolve') return { status: 200, body: { ...details[id], status: 'resolved' } };
            if (m[2] === 'quick-replies') return { status: 202, body: msg({ id: 97, direction: 'out', body: 'x', delivery_status: 'queued' }) };
        }
        if (path === '/inbox/assignees') return { status: 200, body: { data: [{ id: 3, name: 'Rina Akter' }] } };
        if (path === '/products') return { status: 200, body: { data: [{ id: 5, name: 'Baby Activity Gym Mat', name_bn: null, image_url: null }] } };
        return { status: 404, body: { type: 'https://toymartbd.com/problems/not-found', title: 'Not found', status: 404 } };
    });
}

const calls = (fetchMock: ReturnType<typeof mockApi>, method: string, fragment: string) =>
    fetchMock.mock.calls.filter(([u, init]) => (init?.method ?? 'GET') === method && String(u).includes(fragment));

describe('Inbox list', () => {
    it('lists conversations with channel chips, needs-human, unread and the tab counts', async () => {
        inboxApi(operator);
        renderApp('/inbox');

        const list = await screen.findByRole('region', { name: 'Conversations' });
        await within(list).findByText('Nusrat Jahan');
        expect(within(list).getByText('Shahriar Kabir')).toBeTruthy();
        expect(within(list).getAllByText('WhatsApp').length).toBeGreaterThan(0);
        expect(within(list).getByText('Messenger')).toBeTruthy();
        expect(within(list).getByText('Live chat')).toBeTruthy();
        expect(within(list).getAllByText('Needs human')).toHaveLength(2); // the tab and the chip
        expect(within(list).getByText('Resolved')).toBeTruthy();
        expect(within(list).getByText('3 unread')).toBeTruthy();
        expect(within(list).getByText('আমার পার্সেল কোথায়? অর্ডার TMB-26-00121').getAttribute('lang')).toBe('bn');

        const tabs = within(list).getByRole('group', { name: 'Conversation views' });
        expect(within(tabs).getByRole('button', { name: /All\s*3/ }).getAttribute('aria-pressed')).toBe('true');
        // No AI tab: the owner has not chosen an AI provider.
        expect(within(tabs).queryByRole('button', { name: /AI/ })).toBeNull();
        expect(screen.getByText('Pick a conversation')).toBeTruthy();
    });

    it('sends the tab and filters to the server allowlist', async () => {
        const fetchMock = inboxApi(operator);
        renderApp('/inbox');

        const list = await screen.findByRole('region', { name: 'Conversations' });
        fireEvent.click(await within(list).findByRole('button', { name: /Needs human/ }));
        await waitFor(() => expect(calls(fetchMock, 'GET', 'filter%5Bview%5D=needs_human').length).toBeGreaterThan(0));

        fireEvent.click(within(list).getByRole('button', { name: 'Filter conversations' }));
        fireEvent.change(within(list).getByLabelText('Channel'), { target: { value: 'messenger' } });
        await waitFor(() => expect(calls(fetchMock, 'GET', 'filter%5Bchannel%5D=messenger').length).toBeGreaterThan(0));
    });
});

describe('Inbox thread', () => {
    it('shows bubbles, the internal note, a system line and delivery status, and marks it read', async () => {
        const fetchMock = inboxApi(operator);
        renderApp('/inbox/1');

        const thread = await screen.findByRole('region', { name: 'Messages with Nusrat Jahan' });
        expect(within(thread).getByText('01841 523 907', { exact: false })).toBeTruthy();
        expect((await within(thread).findByText('আমার পার্সেল কোথায়? অর্ডার TMB-26-00121')).getAttribute('lang')).toBe('bn');
        expect(within(thread).getByText('Customer refused at door on 06 Nov.').closest('[data-kind="note"]')).toBeTruthy();
        expect(within(thread).getByText(/not visible to customer/)).toBeTruthy();
        expect(within(thread).getByText('Mominul Islam assigned this conversation to Rina Akter')).toBeTruthy();
        expect(within(thread).getByText('· seen')).toBeTruthy();
        await waitFor(() => expect(calls(fetchMock, 'POST', '/inbox/conversations/1/read')).toHaveLength(1));
    });

    it('shows the masked phone when the API sends no full number', async () => {
        inboxApi(operator, { 'GET /inbox/conversations/1': { status: 200, body: detail({ ...nusrat, unread_count: 0, contact: { ...nusrat.contact, phone: null } }) } });
        renderApp('/inbox/1');

        const thread = await screen.findByRole('region', { name: 'Messages with Nusrat Jahan' });
        expect(within(thread).getByText('018****3907', { exact: false })).toBeTruthy();
        expect(within(thread).queryByText('01841 523 907', { exact: false })).toBeNull();
    });

    it('assigns to me and resolves', async () => {
        const fetchMock = inboxApi(operator);
        renderApp('/inbox/1');

        fireEvent.click(await screen.findByRole('button', { name: 'Assign to me' }));
        await waitFor(() => expect(calls(fetchMock, 'POST', '/inbox/conversations/1/assign')).toHaveLength(1));
        expect(JSON.parse(String(calls(fetchMock, 'POST', '/assign')[0]![1]!.body))).toEqual({ user_id: 3 });

        fireEvent.click(screen.getByRole('button', { name: 'Resolve' }));
        await waitFor(() => expect(calls(fetchMock, 'POST', '/inbox/conversations/1/resolve')).toHaveLength(1));
    });
});

describe('Inbox composer', () => {
    it('sends a reply in Reply mode and a note in Internal note mode', async () => {
        const fetchMock = inboxApi(operator);
        renderApp('/inbox/1');

        const box = await screen.findByRole('textbox', { name: 'Reply' });
        expect(screen.getByText(/WhatsApp 24-hour window open · closes/)).toBeTruthy();
        fireEvent.change(box, { target: { value: 'Delivery charge ৳130 lagbe.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await waitFor(() => expect(calls(fetchMock, 'POST', '/inbox/conversations/1/messages')).toHaveLength(1));
        expect(JSON.parse(String(calls(fetchMock, 'POST', '/messages')[0]![1]!.body))).toEqual({ body: 'Delivery charge ৳130 lagbe.' });

        fireEvent.click(screen.getByRole('radio', { name: 'Internal note' }));
        const note = screen.getByRole('textbox', { name: 'Internal note' });
        fireEvent.change(note, { target: { value: 'Ask for ৳300 advance' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add note' }));
        await waitFor(() => expect(calls(fetchMock, 'POST', '/inbox/conversations/1/notes')).toHaveLength(1));
        expect(calls(fetchMock, 'POST', '/inbox/conversations/1/messages')).toHaveLength(1);
    });

    it('disables replies outside the 24-hour window but still allows a note', async () => {
        inboxApi(operator, { 'GET /inbox/conversations/1': { status: 200, body: detail({ ...nusrat, unread_count: 0, window: { applies: true, open: false, expires_at: ago(60) } }) } });
        renderApp('/inbox/1');

        expect(await screen.findByText(/The 24-hour window has closed/)).toBeTruthy();
        expect(screen.getByRole('textbox', { name: 'Reply' })).toHaveProperty('disabled', true);
        expect(screen.getByRole('button', { name: 'Send' })).toHaveProperty('disabled', true);
        expect(screen.getByRole('button', { name: /Send product card/ })).toHaveProperty('disabled', true);

        fireEvent.click(screen.getByRole('radio', { name: 'Internal note' }));
        expect(screen.getByRole('textbox', { name: 'Internal note' })).toHaveProperty('disabled', false);
    });

    it('shows no composer to someone who can only read', async () => {
        inboxApi(reader);
        renderApp('/inbox/1');

        expect(await screen.findByText('You can read this conversation but not reply.')).toBeTruthy();
        expect(screen.queryByRole('textbox', { name: 'Reply' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Assign to me' })).toBeNull();
    });
});

describe('Inbox customer panel', () => {
    it('shows server figures with the risk labelled as ours, and the last order', async () => {
        inboxApi(operator);
        renderApp('/inbox/1');

        const panel = await screen.findByRole('complementary', { name: 'Customer' });
        expect(within(panel).getByText('2 · 1 delivered')).toBeTruthy();
        expect(within(panel).getByText('Risk (ours)')).toBeTruthy();
        expect(within(panel).getByText('Low 23')).toBeTruthy();
        expect(within(panel).getByText('2,180')).toBeTruthy();
        expect(within(panel).getByText('Returned')).toBeTruthy();
        expect(within(panel).getByText('Baby Activity Gym Mat × 1')).toBeTruthy();
    });

    it('disables bKash with its reason, links Create order, and sends a product card and tracking link', async () => {
        const fetchMock = inboxApi(operator);
        renderApp('/inbox/1');

        const panel = await screen.findByRole('complementary', { name: 'Customer' });
        expect(within(panel).getByRole('button', { name: /Send bKash payment link/ })).toHaveProperty('disabled', true);
        expect(within(panel).getByText('No payment gateway is connected yet.')).toBeTruthy();
        expect(within(panel).getByRole('link', { name: /Create order from chat/ }).getAttribute('href')).toBe('/orders/new?source=whatsapp&customer_id=11');

        fireEvent.click(within(panel).getByRole('button', { name: /Share tracking link/ }));
        await waitFor(() => expect(calls(fetchMock, 'POST', '/quick-replies')).toHaveLength(1));
        expect(JSON.parse(String(calls(fetchMock, 'POST', '/quick-replies')[0]![1]!.body))).toEqual({ kind: 'tracking_link', target_id: 121 });

        fireEvent.click(within(panel).getByRole('button', { name: /Send product card/ }));
        fireEvent.click(await within(panel).findByRole('button', { name: 'Send Baby Activity Gym Mat' }));
        await waitFor(() => expect(calls(fetchMock, 'POST', '/quick-replies')).toHaveLength(2));
        expect(JSON.parse(String(calls(fetchMock, 'POST', '/quick-replies')[1]![1]!.body))).toEqual({ kind: 'product_card', target_id: 5 });
    });

    it('explains a Messenger conversation with no linked customer and disables tracking', async () => {
        inboxApi(operator);
        renderApp('/inbox/2');

        const panel = await screen.findByRole('complementary', { name: 'Customer' });
        expect(within(panel).getByText(/Messenger does not share a phone number/)).toBeTruthy();
        expect(within(panel).getByRole('button', { name: /Share tracking link/ })).toHaveProperty('disabled', true);
    });

    it('renders in Bangla with Western digits', async () => {
        inboxApi(operator);
        renderApp('/inbox/1', { locale: 'bn' });

        const panel = await screen.findByRole('complementary', { name: 'কাস্টমার' });
        expect(within(panel).getByText('2 · 1টি ডেলিভারি')).toBeTruthy();
        expect(within(panel).getByText('ঝুঁকি (আমাদের)')).toBeTruthy();
    });
});
