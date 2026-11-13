import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import { eventsPath, emptyFilters, type TrackingEvent, type TrackingSettings, type TrackingSummary, type TrackingTestResult } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const owner: Me = { ...viewer, roles: ['super_admin'], permissions: ['dashboard.view', 'settings.view', 'settings.manage_integrations'] };
const accountant: Me = { ...viewer, roles: ['accountant'], permissions: ['dashboard.view', 'reports.view'] };

const saved: TrackingSettings = {
    server_side_enabled: true,
    ga4_enabled: true,
    ga4_measurement_id: 'G-TMB8X2K4QZ',
    ga4_api_secret_set: true,
    ga4_api_secret_hint: '••••1234',
    ga4_api_secret_source: 'settings',
    meta_enabled: true,
    meta_pixel_id: '104822917731',
    meta_access_token_set: true,
    meta_access_token_hint: '••••9876',
    meta_access_token_source: 'env',
    meta_test_event_code: null,
    meta_graph_version: 'v27.0',
    gtm_container_id: 'GTM-W7K3PL9',
    purchase_trigger: 'order_placed',
    consent_mode: 'opt_in',
    consent_policy_version: '2026-11',
    sources: {},
};

const counts = { sent: 0, failed: 0, pending: 0, skipped_consent: 0, skipped_other: 0, total: 0 };
const now = new Date().toISOString();
const summary: TrackingSummary = {
    generated_at: now,
    destinations: [
        { destination: 'ga4', last_success_at: now, last_error: null, last_error_at: null, window_24h: { ...counts, sent: 12, total: 12 }, window_7d: { ...counts, sent: 80, total: 80 }, consent_skip_rate: '0.0', pairing_rate: null },
        {
            destination: 'meta',
            last_success_at: new Date(Date.now() - 7_200_000).toISOString(),
            last_error: 'Meta: (#190) Error validating access token',
            last_error_at: now,
            window_24h: { ...counts, sent: 30, failed: 4, total: 34 },
            window_7d: { ...counts, sent: 200, failed: 9, skipped_consent: 20, total: 229 },
            consent_skip_rate: '8.7',
            pairing_rate: '91.5',
        },
    ],
};

const event: TrackingEvent = {
    id: 41,
    event_id: '0b8f2c1e-5a8e-4a37-9d5e-2f6a1c9e7b10',
    event_name: 'purchase',
    source: 'server',
    order_id: 9,
    order_number: 'TMB-26-00042',
    value: '2450.00',
    currency: 'BDT',
    consent: { analytics: true, ads: false, mode: 'opt_in' },
    ga4: { status: 'sent', attempts: 1, error: null },
    meta: { status: 'skipped_consent', attempts: 0, error: null },
    occurred_at: now,
    dispatched_at: now,
    created_at: now,
};

type Replies = { events?: { status: number; body?: unknown }; test?: TrackingTestResult; settings?: TrackingSettings };

function trackingApi(me: Me, replies: Replies = {}) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/settings/tracking/test')) return { status: 200, body: replies.test };
        if (url.endsWith('/settings/tracking') && init?.method === 'PUT') {
            const sent = JSON.parse(String(init.body)) as Partial<TrackingSettings>;
            return { status: 200, body: { ...(replies.settings ?? saved), ...sent } };
        }
        if (url.endsWith('/settings/tracking')) return { status: 200, body: replies.settings ?? saved };
        if (url.endsWith('/tracking/summary')) return { status: 200, body: summary };
        if (url.includes('/tracking/events/41')) return { status: 200, body: { ...event, session_id: null, consent_policy_version: '2026-11', payload: { sent: { ga4: { events: [{ name: 'purchase' }] } } }, ga4_response: { http_status: 204 }, meta_response: null } };
        if (url.includes('/tracking/events')) return replies.events ?? { status: 200, body: { data: [event], meta: { next_cursor: null } } };
        return { status: 404 };
    });
}

function sentBody(calls: ReturnType<typeof mockApi>, method: string, path: string): Record<string, unknown> {
    const call = calls.mock.calls.find(([url, init]) => String(url).endsWith(path) && (init as RequestInit | undefined)?.method === method)!;
    return JSON.parse(String((call[1] as RequestInit).body)) as Record<string, unknown>;
}

describe('integrations → tracking & pixels', () => {
    it('renders the destination cards, event map and consent from the Figma frame', async () => {
        trackingApi(owner);
        renderApp('/integrations/tracking');

        expect(await screen.findByRole('heading', { level: 1, name: 'Tracking & pixels' })).toBeTruthy();
        const ga4 = screen.getByRole('region', { name: 'Google Analytics 4' });
        expect(within(ga4).getByText('G-TMB8X2K4QZ')).toBeTruthy();
        expect(within(ga4).getByText('Connected')).toBeTruthy();
        // Meta's last error is newer than its last success → Needs attention, with the reason.
        const meta = screen.getByRole('region', { name: 'Meta Pixel + Conversions API' });
        expect(await within(meta).findByText('Needs attention')).toBeTruthy();
        expect(within(meta).getByText(/Error validating access token/)).toBeTruthy();
        expect(screen.getByRole('region', { name: 'Google Tag Manager' })).toBeTruthy();
        expect(screen.getByText('Event map')).toBeTruthy();
        expect(screen.getByText('InitiateCheckout')).toBeTruthy();
        // Not built (owner decision / no backing integration).
        expect(screen.queryByText('Custom scripts')).toBeNull();
        expect(screen.queryByText('TikTok Pixel')).toBeNull();
    });

    it('keeps secrets write-only: blank password fields, hints only, blank = keep (null)', async () => {
        const calls = trackingApi(owner);
        renderApp('/integrations/tracking');

        fireEvent.click(await screen.findByRole('button', { name: 'Google Analytics 4 settings' }));
        const secret = (await screen.findByLabelText(/Measurement Protocol API secret/)) as HTMLInputElement;
        expect(secret.type).toBe('password');
        expect(secret.value).toBe('');
        expect(screen.getByText(/Saved ••••1234 — leave blank to keep/)).toBeTruthy();

        fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')).toBe(true));
        const body = sentBody(calls, 'PUT', '/settings/tracking');
        expect(body.ga4_api_secret).toBeNull();
        expect(body.meta_access_token).toBeNull();
        expect(body.clear_ga4_api_secret).toBe(false);
        expect(JSON.stringify(body)).not.toContain('••••');
    });

    it('sends a typed secret once and never shows it back', async () => {
        const calls = trackingApi(owner);
        renderApp('/integrations/tracking');

        fireEvent.click(await screen.findByRole('button', { name: 'Meta Pixel + Conversions API settings' }));
        fireEvent.change(await screen.findByLabelText(/Conversions API access token/), { target: { value: 'EAAB-new-token' } });
        fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')).toBe(true));
        expect(sentBody(calls, 'PUT', '/settings/tracking').meta_access_token).toBe('EAAB-new-token');
    });

    it('switches the purchase trigger and explains the trade-off', async () => {
        const calls = trackingApi(owner);
        renderApp('/integrations/tracking');

        expect(await screen.findByText(/refused and cancelled COD orders are counted as sales/)).toBeTruthy();
        expect(screen.getByText('Order placed (COD included)')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Order delivered' }));
        expect(screen.getByText('Courier confirms delivery')).toBeTruthy();
        expect(screen.getByText(/only parcels the customer accepted count/)).toBeTruthy();

        fireEvent.click(screen.getAllByRole('button', { name: 'Save' })[0]!);
        await waitFor(() => expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')).toBe(true));
        expect(sentBody(calls, 'PUT', '/settings/tracking').purchase_trigger).toBe('order_delivered');
    });

    it('shows GA4 validation messages from the test event', async () => {
        trackingApi(owner, { test: { destination: 'ga4', valid: false, status: 'failed', http_status: 200, reason: null, messages: ['VALUE_REQUIRED events[0].params.items: Item id is required.'], response_excerpt: null } });
        renderApp('/integrations/tracking');

        fireEvent.click(await screen.findByRole('button', { name: 'Google Analytics 4 settings' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Send test event' }));
        expect(await screen.findByText('Not accepted')).toBeTruthy();
        expect(screen.getByText('VALUE_REQUIRED events[0].params.items: Item id is required.')).toBeTruthy();
    });

    it('shows a known reason in the admin language, not the server English', async () => {
        trackingApi(owner, { test: { destination: 'ga4', valid: false, status: 'skipped_disabled', http_status: null, reason: 'not_configured', messages: [], response_excerpt: null } });
        renderApp('/integrations/tracking', { locale: 'bn' });

        fireEvent.click(await screen.findByRole('button', { name: 'Google Analytics 4 সেটিংস' }));
        fireEvent.click(await screen.findByRole('button', { name: 'টেস্ট ইভেন্ট পাঠান' }));
        expect(await screen.findByText(/কিছু পাঠানো হয়নি: এই গন্তব্যটি বন্ধ/)).toBeTruthy();
    });

    it('blocks a Meta test event until a test event code is saved', async () => {
        trackingApi(owner);
        renderApp('/integrations/tracking');

        fireEvent.click(await screen.findByRole('button', { name: 'Meta Pixel + Conversions API settings' }));
        expect(await screen.findByText(/Save a test event code first/)).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Send test event' }) as HTMLButtonElement).disabled).toBe(true);
    });

    it('refuses the screen without settings.manage_integrations', async () => {
        trackingApi(accountant);
        renderApp('/integrations/tracking');
        expect(await screen.findByText('You do not have access to this page.')).toBeTruthy();
    });

    it('renders in Bangla with Western digits', async () => {
        trackingApi(owner);
        renderApp('/integrations/tracking', { locale: 'bn' });

        expect(await screen.findByRole('heading', { level: 1, name: 'ট্র্যাকিং ও পিক্সেল' })).toBeTruthy();
        expect(screen.getByText('ইভেন্ট ম্যাপ')).toBeTruthy();
        expect(document.body.textContent ?? '').not.toMatch(/[০-৯]/);
    });
});

describe('integrations → tracking → diagnostics', () => {
    it('shows health tiles and the event log', async () => {
        trackingApi(owner);
        renderApp('/integrations/tracking/diagnostics');

        expect(await screen.findByRole('heading', { level: 1, name: 'Tracking diagnostics' })).toBeTruthy();
        const meta = await screen.findByRole('region', { name: 'Meta Pixel + Conversions API' });
        expect(within(meta).getByText('91.5%')).toBeTruthy();
        expect(within(meta).getByText('8.7%')).toBeTruthy();
        expect(within(meta).getByText(/Error validating access token/)).toBeTruthy();
        const ga4 = screen.getByRole('region', { name: 'Google Analytics 4' });
        expect(within(ga4).getByText(/Not applicable: GA4 gets each event from one channel only/)).toBeTruthy();
        expect(await screen.findByText('TMB-26-00042')).toBeTruthy();
        expect(screen.getByText('৳2,450.00')).toBeTruthy();
        // The Meta status chip in the row (the filter <option> has the same words).
        expect(screen.getAllByText('No consent').some((el) => el.tagName !== 'OPTION')).toBe(true);
    });

    it('turns filters into allowlisted query params', async () => {
        const calls = trackingApi(owner);
        renderApp('/integrations/tracking/diagnostics?event_name=purchase&meta_status=failed&date_from=2026-11-01&bogus=1');

        await screen.findByText('TMB-26-00042');
        const urls = calls.mock.calls.map(([url]) => decodeURIComponent(String(url))).filter((url) => url.includes('/tracking/events'));
        expect(urls.some((url) => url.includes('filter[event_name]=purchase') && url.includes('filter[meta_status]=failed') && url.includes('filter[date_from]=2026-11-01'))).toBe(true);
        expect(urls.some((url) => url.includes('bogus'))).toBe(false);

        fireEvent.change(screen.getByRole('combobox', { name: 'Source' }), { target: { value: 'browser' } });
        await waitFor(() => expect(calls.mock.calls.some(([url]) => decodeURIComponent(String(url)).includes('filter[source]=browser'))).toBe(true));
    });

    it('builds the events path from filters', () => {
        expect(eventsPath(emptyFilters)).toBe('/tracking/events');
        expect(decodeURIComponent(eventsPath({ ...emptyFilters, order_number: 'TMB-26-00042' }, 'abc'))).toBe('/tracking/events?filter[order_number]=TMB-26-00042&cursor=abc');
    });

    it('opens an event in the detail drawer with the payload as sent', async () => {
        trackingApi(owner);
        renderApp('/integrations/tracking/diagnostics');

        fireEvent.click(await screen.findByRole('button', { name: 'Open purchase details' }));
        const dialog = await screen.findByRole('dialog');
        expect(await within(dialog).findByText('Payload as sent')).toBeTruthy();
        expect(within(dialog).getByText('Refused')).toBeTruthy();
    });

    it('shows an empty state and an error state', async () => {
        trackingApi(owner, { events: { status: 200, body: { data: [], meta: { next_cursor: null } } } });
        renderApp('/integrations/tracking/diagnostics');
        expect(await screen.findByText('No tracking events yet')).toBeTruthy();
        cleanup();

        trackingApi(owner, { events: { status: 500, body: { type: 'https://toymartbd.com/problems/server-error', title: 'Server error', status: 500 } } });
        renderApp('/integrations/tracking/diagnostics');
        expect(await screen.findByRole('button', { name: 'Try again' })).toBeTruthy();
    });
});
