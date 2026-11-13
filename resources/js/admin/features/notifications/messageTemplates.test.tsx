import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { NotificationTemplate, TemplatePreview, TemplateVersion } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const manager: Me = { ...viewer, roles: ['manager'], permissions: ['dashboard.view', 'settings.view', 'settings.update'] };
const settingsViewer: Me = { ...viewer, roles: ['viewer'], permissions: ['dashboard.view', 'settings.view'] };

const sms: NotificationTemplate = {
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
    kind: 'system',
    is_custom: false,
    name_en: null,
    name_bn: null,
    whatsapp_status: null,
    variables: ['name', 'order_number', 'total'],
    required_variables: [],
    chars_en: 109,
    chars_bn: 111,
    segments_en: 1,
    segments_bn: 2,
    encoding_en: 'gsm7',
    encoding_bn: 'ucs2',
    cost_en: '0.26',
    cost_bn: '0.52',
    updated_by_name: 'Rafi Ahmed',
    archived_at: null,
    updated_at: '2026-11-12T10:00:00Z',
};
const whatsapp: NotificationTemplate = {
    ...sms,
    id: 2,
    channel: 'whatsapp',
    provider_template: 'order_placed',
    whatsapp_status: 'approved',
    required_variables: ['name', 'order_number'],
    segments_en: null,
    segments_bn: null,
    encoding_en: 'utf8',
    encoding_bn: 'utf8',
    cost_en: null,
    cost_bn: null,
};
// A key this admin build has no label for — the catalog is read dynamically.
const future: NotificationTemplate = { ...sms, id: 3, key: 'order.future_event', updated_by_name: null };
const custom: NotificationTemplate = {
    ...sms,
    id: 4,
    key: 'custom.eid_greeting',
    category: 'marketing',
    kind: 'marketing',
    is_custom: true,
    name_en: 'Eid greeting',
    name_bn: 'ঈদ শুভেচ্ছা',
    variables: ['name', 'store_name'],
    body_en: 'Eid Mubarak {name}!',
    body_bn: 'ঈদ মোবারক {name}!',
};

const preview: TemplatePreview = {
    en: { rendered: 'Toy Mart BD: order TMB-26-00482 received.', chars: 41, encoding: 'gsm7', segments: 1, per_segment_limit: 160, cost: '0.26' },
    bn: { rendered: 'টয় মার্ট বিডি: অর্ডার TMB-26-00482 পেয়েছি।', chars: 150, encoding: 'ucs2', segments: 3, per_segment_limit: 67, cost: '0.78' },
    unknown_variables: ['coupon'],
    missing_variables: [],
    warnings: ['long_sms_bn'],
};

const versions: TemplateVersion[] = [
    { id: 12, version: 2, subject_en: null, subject_bn: null, preheader_en: null, preheader_bn: null, body_en: sms.body_en, body_bn: sms.body_bn, restored_from: null, created_by_name: 'Rafi Ahmed', created_at: '2026-11-12T10:00:00Z' },
    { id: 11, version: 1, subject_en: null, subject_bn: null, preheader_en: null, preheader_bn: null, body_en: 'Original {order_number}', body_bn: 'মূল {order_number}', restored_from: null, created_by_name: null, created_at: '2026-11-11T10:00:00Z' },
];

function libraryApi(me: Me, rows: NotificationTemplate[] = [sms, whatsapp, future, custom]) {
    return mockApi((url, init) => {
        const method = init?.method ?? 'GET';
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/preview')) return { status: 200, body: preview };
        if (url.includes('/versions/') && url.endsWith('/restore')) return { status: 200, body: { ...sms, body_en: 'Original {order_number}', updated_at: '2026-11-13T10:00:00Z' } };
        if (url.includes('/versions')) return { status: 200, body: { data: versions } };
        if (url.endsWith('/whatsapp-status')) return { status: 200, body: { ...whatsapp, whatsapp_status: JSON.parse(String(init?.body)).status } };
        if (/\/notifications\/templates\/\d+$/.test(url) && method === 'GET') {
            const id = Number(url.split('/').pop());
            return { status: 200, body: rows.find((row) => row.id === id) ?? sms };
        }
        if (/\/notifications\/templates\/\d+$/.test(url) && method === 'PUT') return { status: 200, body: { ...sms, ...JSON.parse(String(init?.body)) } };
        if (url.endsWith('/notifications/templates') && method === 'POST') return { status: 201, body: { data: [{ ...custom, id: 9 }] } };
        if (url.includes('/notifications/templates')) return { status: 200, body: { data: rows } };
        if (url.includes('/notifications/logs')) return { status: 200, body: { data: [], meta: { next_cursor: null } } };
        return { status: 404 };
    });
}

const calledWith = (calls: ReturnType<typeof libraryApi>, predicate: (url: string, init: RequestInit | undefined) => boolean) =>
    calls.mock.calls.find(([url, init]) => predicate(String(url), init as RequestInit | undefined));

describe('message templates library', () => {
    it('is linked from Automations, as in Figma', async () => {
        libraryApi(manager);
        renderApp('/automations');
        const link = await screen.findByRole('link', { name: 'Message templates' });
        expect(link.getAttribute('href')).toBe('/automations/templates');
    });

    it('groups whatever the catalog returns, with channel chips, EN/BN status and the server’s SMS cost', async () => {
        libraryApi(manager);
        renderApp('/automations/templates');

        const orders = await screen.findByRole('region', { name: 'Order updates' });
        expect(within(orders).getByText('Order confirmation')).toBeTruthy();
        // Unknown key: shown by its key, never a crash or blank.
        expect(within(orders).getAllByText('order.future_event').length).toBeGreaterThan(0);
        expect(within(orders).getAllByRole('link', { name: 'WhatsApp · On' }).length).toBe(1);
        // Segment counts and cost per send are the server's numbers, shown as is.
        expect(within(orders).getAllByText('1 / 2 segments (EN / BN)').length).toBeGreaterThan(0);
        expect(within(orders).getAllByText((_, el) => el?.tagName === 'P' && (el.textContent ?? '').startsWith('৳0.26 / ৳0.52')).length).toBeGreaterThan(0);
        expect(within(orders).getAllByText('Rafi Ahmed').length).toBeGreaterThan(0);

        const marketing = screen.getByRole('region', { name: 'Marketing' });
        expect(within(marketing).getByText('Eid greeting')).toBeTruthy();
    });

    it('sends channel, category and status to the server allowlist and searches translated names', async () => {
        const calls = libraryApi(manager);
        renderApp('/automations/templates?channel=sms&group=manual&status=archived');

        await screen.findByRole('heading', { name: 'Message templates', level: 1 });
        await waitFor(() =>
            expect(
                calledWith(calls, (url) => url.includes('filter%5Bchannel%5D=sms') && url.includes('filter%5Bkind%5D=manual') && url.includes('filter%5Barchived%5D=1')),
            ).toBeTruthy(),
        );

        cleanup();
        libraryApi(manager);
        renderApp('/automations/templates');
        fireEvent.change(await screen.findByRole('searchbox', { name: 'Search templates' }), { target: { value: 'eid' } });
        await waitFor(() => expect(screen.queryByText('Order confirmation')).toBeNull());
        expect(screen.getByText('Eid greeting')).toBeTruthy();
    });

    it('hides “New template” without settings.update', async () => {
        libraryApi(settingsViewer);
        renderApp('/automations/templates');
        await screen.findByRole('region', { name: 'Order updates' });
        expect(screen.queryByRole('link', { name: 'New template' })).toBeNull();
    });
});

describe('template editor', () => {
    it('shows EN + BN side by side with server-measured counts, the 3-segment warning and unknown variables', async () => {
        libraryApi(manager);
        renderApp('/automations/templates/1');

        expect(await screen.findByLabelText(/Message — English/)).toBeTruthy();
        expect(screen.getByLabelText(/Message — Bangla/)).toBeTruthy();
        await screen.findByText((_, el) => el?.tagName === 'P' && (el.textContent ?? '').startsWith('150 characters · UCS-2 · 3 segment(s) of 67 · ৳0.78'));
        const alert = await screen.findByRole('alert');
        expect(within(alert).getByText(/Bangla SMS is 3 or more segments/)).toBeTruthy();
        expect(within(alert).getByText('Not a variable for this template: {coupon}')).toBeTruthy();
    });

    it('lists only the allowlisted variables and inserts one at the caret', async () => {
        libraryApi(manager);
        renderApp('/automations/templates/1');

        const panel = await screen.findByRole('region', { name: 'Variables' });
        expect(within(panel).getAllByRole('button').map((b) => b.textContent)).toEqual(['{name}', '{order_number}', '{total}']);

        const body = (await screen.findByLabelText(/Message — English/)) as HTMLTextAreaElement;
        fireEvent.change(body, { target: { value: 'Hi ' } });
        fireEvent.focus(body);
        body.setSelectionRange(3, 3);
        fireEvent.click(within(panel).getByRole('button', { name: 'Insert {name}' }));
        await waitFor(() => expect(body.value).toBe('Hi {name}'));
    });

    it('records the WhatsApp approval status without calling Meta', async () => {
        const calls = libraryApi(manager);
        renderApp('/automations/templates/2');

        expect(await screen.findByText('Approved by Meta', { selector: 'span' })).toBeTruthy();
        fireEvent.change(screen.getByRole('combobox', { name: 'Meta approval' }), { target: { value: 'submitted' } });
        await waitFor(() => expect(calledWith(calls, (url, init) => url.endsWith('/notifications/templates/2/whatsapp-status') && init?.method === 'PUT')).toBeTruthy());
        const put = calledWith(calls, (url) => url.endsWith('/whatsapp-status'))!;
        expect(JSON.parse(String((put[1] as RequestInit).body))).toEqual({ status: 'submitted' });
        expect(await screen.findByText('Approval status saved')).toBeTruthy();
    });

    it('shows version history and restores an older version', async () => {
        const calls = libraryApi(manager);
        renderApp('/automations/templates/1');

        const history = await screen.findByRole('region', { name: 'Version history' });
        await within(history).findByText('Version 1');
        expect(within(history).queryByRole('button', { name: 'Restore version 2' })).toBeNull(); // the current one
        fireEvent.click(within(history).getByRole('button', { name: 'Restore version 1' }));
        await waitFor(() => expect(calledWith(calls, (url, init) => url.endsWith('/notifications/templates/1/versions/1/restore') && init?.method === 'POST')).toBeTruthy());
        expect(await screen.findByText('Version 1 restored')).toBeTruthy();
    });

    it('is read-only for settings.view', async () => {
        libraryApi(settingsViewer);
        renderApp('/automations/templates/1');
        expect(await screen.findByText('You can view templates; changing them needs settings permission.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Save template' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Archive' })).toBeNull();
    });

    it('offers archive only on a custom template', async () => {
        libraryApi(manager);
        renderApp('/automations/templates/4');
        expect(await screen.findByRole('button', { name: 'Archive' })).toBeTruthy();
        expect(screen.getByLabelText(/Name — Bangla/)).toBeTruthy();

        cleanup();
        libraryApi(manager);
        renderApp('/automations/templates/1');
        await screen.findByLabelText(/Message — English/);
        expect(screen.queryByRole('button', { name: 'Archive' })).toBeNull();
    });
});

describe('new template', () => {
    it('posts the generated request shape and opens the editor', async () => {
        const calls = libraryApi(manager);
        renderApp('/automations/templates/new');

        fireEvent.change(await screen.findByLabelText(/Name — English/), { target: { value: 'Eid greeting' } });
        fireEvent.change(screen.getByLabelText(/Name — Bangla/), { target: { value: 'ঈদ শুভেচ্ছা' } });
        fireEvent.click(screen.getByRole('checkbox', { name: /WhatsApp/ }));
        fireEvent.change(screen.getByLabelText(/Message — English/), { target: { value: 'Eid Mubarak {name}!' } });
        fireEvent.change(screen.getByLabelText(/Message — Bangla/), { target: { value: 'ঈদ মোবারক {name}!' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create template' }));

        await waitFor(() => expect(calledWith(calls, (url, init) => url.endsWith('/notifications/templates') && init?.method === 'POST')).toBeTruthy());
        const post = calledWith(calls, (url, init) => url.endsWith('/notifications/templates') && init?.method === 'POST')!;
        expect(JSON.parse(String((post[1] as RequestInit).body))).toEqual({
            name_en: 'Eid greeting',
            name_bn: 'ঈদ শুভেচ্ছা',
            kind: 'marketing',
            channels: ['sms', 'whatsapp'],
            variables: ['name', 'store_name'],
            body_en: 'Eid Mubarak {name}!',
            body_bn: 'ঈদ মোবারক {name}!',
            subject_en: null,
            subject_bn: null,
        });
        expect(await screen.findByText('Template created')).toBeTruthy();
    });

    it('renders in Bangla', async () => {
        libraryApi(manager);
        renderApp('/automations/templates', { locale: 'bn' });
        expect(await screen.findByRole('heading', { name: 'মেসেজ টেমপ্লেট', level: 1 })).toBeTruthy();
        expect(await screen.findByText('ঈদ শুভেচ্ছা')).toBeTruthy();
    });
});
