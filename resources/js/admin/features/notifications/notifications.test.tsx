import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { NotificationLog, NotificationSummary, NotificationTemplate, TemplatePreview } from './api';
import { formatMoney } from './parts';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const manager: Me = { ...viewer, roles: ['manager'], permissions: ['dashboard.view', 'settings.view', 'settings.update'] };
const settingsViewer: Me = { ...viewer, roles: ['viewer'], permissions: ['dashboard.view', 'settings.view'] };

const base: NotificationTemplate = {
    id: 1,
    key: 'order.placed',
    channel: 'sms',
    category: 'order_updates',
    can_opt_out: true,
    subject_en: null,
    subject_bn: null,
    preheader_en: null,
    preheader_bn: null,
    body_en: 'Toy Mart BD: order {order_number} received.',
    body_bn: 'টয় মার্ট বিডি: অর্ডার {order_number} পেয়েছি।',
    provider_template: null,
    is_active: true,
    variables: ['name', 'order_number', 'total'],
    chars_en: 109,
    chars_bn: 111,
    segments_en: 1,
    segments_bn: 2,
    encoding_en: 'gsm7',
    encoding_bn: 'ucs2',
    updated_at: '2026-11-12T10:00:00Z',
};
const whatsapp: NotificationTemplate = { ...base, id: 2, channel: 'whatsapp', provider_template: 'order_placed', segments_en: null, segments_bn: null, encoding_en: 'utf8', encoding_bn: 'utf8' };
const otp: NotificationTemplate = { ...base, id: 3, key: 'otp.login', category: 'security', can_opt_out: false, segments_bn: 1 };

const preview: TemplatePreview = {
    en: { rendered: 'Toy Mart BD: order TMB-26-00482 received.', chars: 41, encoding: 'gsm7', segments: 1, per_segment_limit: 160, cost: '0.64' },
    bn: { rendered: 'টয় মার্ট বিডি: অর্ডার TMB-26-00482 পেয়েছি।', chars: 106, encoding: 'ucs2', segments: 2, per_segment_limit: 67, cost: '1.28' },
    unknown_variables: [],
};

const deadLetter: NotificationLog = {
    id: 9,
    channel: 'sms',
    template: 'order.placed',
    category: 'order_updates',
    locale: 'bn',
    recipient: '017****5678',
    order_number: 'TMB-26-00482',
    order_id: 5,
    status: 'dead_lettered',
    provider: 'log',
    provider_message_id: null,
    segments: 2,
    encoding: 'ucs2',
    cost: '0.00',
    error: 'insufficient balance',
    attempts: 3,
    fallback_of_id: null,
    queued_at: '2026-11-12T08:00:00Z',
    sent_at: null,
    failed_at: '2026-11-12T08:05:00Z',
    created_at: '2026-11-12T08:00:00Z',
};

const summary: NotificationSummary = {
    month: '2026-11',
    dead_lettered: 1,
    channels: [
        { channel: 'sms', sent: 12, segments: 20, cost: '12.80' },
        { channel: 'whatsapp', sent: 40, segments: 0, cost: '18.00' },
        { channel: 'email', sent: 3, segments: 0, cost: '0.00' },
    ],
    total_cost: '30.80',
};

function notificationsApi(me: Me) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/notifications/templates')) return { status: 200, body: { data: [base, whatsapp, otp] } };
        if (url.includes('/preview')) return { status: 200, body: preview };
        if (/\/notifications\/templates\/\d+$/.test(url) && init?.method === 'PUT') {
            const sent = JSON.parse(String(init.body)) as Partial<NotificationTemplate>;
            return { status: 200, body: { ...base, ...sent } };
        }
        if (url.includes('/notifications/logs/summary')) return { status: 200, body: summary };
        if (url.includes('/retry')) return { status: 200, body: { ...deadLetter, status: 'queued' } };
        if (url.includes('/notifications/logs')) return { status: 200, body: { data: [deadLetter], meta: { next_cursor: null } } };
        return { status: 404 };
    });
}

describe('automations & notification templates', () => {
    it('lists events with server-stored segment counts and shows the server-measured cost', async () => {
        notificationsApi(manager);
        renderApp('/automations');

        const list = await screen.findByRole('list', { name: 'Notification templates' });
        expect(within(list).getByText('Order confirmation')).toBeTruthy();
        expect(within(list).getByText('SMS 1 / 2 segments (EN / BN)')).toBeTruthy();
        expect(within(list).getByText('Security message — always sent')).toBeTruthy();
        // The OTP switch can never be turned off from here.
        expect((screen.getByRole('switch', { name: 'Send “Sign-in code”' }) as HTMLButtonElement).disabled).toBe(true);

        // WhatsApp first (rule 39): the editor opens on the WhatsApp template.
        expect(await screen.findByText('Template · WhatsApp')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'SMS' }));
        await screen.findByText('Template · SMS');
        // Counts and cost come from the preview endpoint, never from the browser.
        await screen.findByText((_, el) => el?.tagName === 'P' && (el.textContent ?? '').startsWith('106 characters · UCS-2 · 2 segment(s) of 67 · ৳1.28'));
        // The preview bubble renders the sample in the admin's language.
        expect(screen.getByText(preview.en.rendered, { selector: 'p' })).toBeTruthy();
    });

    it('saves both bodies through PUT with the generated request shape', async () => {
        const calls = notificationsApi(manager);
        renderApp('/automations?template=order.placed&channel=sms');

        const field = await screen.findByLabelText(/Message — English/);
        fireEvent.change(field, { target: { value: 'Order {order_number} received' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save template' }));

        await waitFor(() => expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')).toBe(true));
        const put = calls.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')!;
        expect(String(put[0])).toContain('/notifications/templates/1');
        expect(JSON.parse(String((put[1] as RequestInit).body))).toMatchObject({ body_en: 'Order {order_number} received', body_bn: base.body_bn, is_active: true });
        expect(await screen.findByText('Template saved')).toBeTruthy();
    });

    it('is read-only without settings.update', async () => {
        notificationsApi(settingsViewer);
        renderApp('/automations?template=order.placed&channel=sms');

        expect(await screen.findByText('You can view templates; changing them needs settings permission.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Save template' })).toBeNull();
    });

    it('renders in Bangla', async () => {
        notificationsApi(manager);
        renderApp('/automations', { locale: 'bn' });

        expect(await screen.findByText('অটোমেশন ও নোটিফিকেশন')).toBeTruthy();
        expect(await screen.findByText('অর্ডার নিশ্চিতকরণ', { selector: 'p' })).toBeTruthy();
    });
});

describe('message log', () => {
    it('shows this month’s spend, masked recipients and retries a dead letter', async () => {
        const calls = notificationsApi(manager);
        renderApp('/automations/log');

        const table = await screen.findByRole('table');
        expect(within(table).getByText('017****5678')).toBeTruthy();
        expect(within(table).getByText('TMB-26-00482')).toBeTruthy();
        expect(within(table).getByText('Dead letter')).toBeTruthy();
        expect(screen.getByText('12 sent · 20 segments')).toBeTruthy();
        expect(screen.getAllByText((_, el) => el?.tagName === 'P' && el.textContent === '৳12.80').length).toBeGreaterThan(0);

        fireEvent.click(within(table).getByRole('button', { name: /Retry/ }));
        await waitFor(() => expect(calls.mock.calls.some(([url]) => String(url).endsWith('/notifications/logs/9/retry'))).toBe(true));
        expect(await screen.findByText('Queued again')).toBeTruthy();
    });

    it('sends filters as allowlisted query parameters', async () => {
        const calls = notificationsApi(manager);
        renderApp('/automations/log?status=dead_lettered&template=order.placed');

        await screen.findByRole('table');
        expect(calls.mock.calls.map(([url]) => String(url)).some((url) => url.includes('filter%5Bstatus%5D=dead_lettered') && url.includes('filter%5Btemplate%5D=order.placed'))).toBe(true);
    });
});

describe('formatMoney', () => {
    it('formats decimal strings without computing', () => {
        expect(formatMoney('1.28')).toBe('1.28');
        expect(formatMoney('1.20')).toBe('1.20');
        expect(formatMoney('1250.00')).toBe('1,250');
        expect(formatMoney('0.45')).toBe('0.45');
    });
});
