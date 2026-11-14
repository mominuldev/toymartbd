import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { ReportSchedule, ReportScheduleRun } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const accountant: Me = { ...viewer, roles: ['accountant'], permissions: ['dashboard.view', 'reports.view', 'reports.export', 'shipments.view', 'finance.view'] };

const weekly: ReportSchedule = {
    id: 7,
    name: 'Weekly P&L',
    report_type: 'pnl',
    frequency: 'weekly',
    send_time: '09:15',
    locale: 'bn',
    format: 'xlsx',
    is_active: true,
    recipients: [{ id: 3, name: 'Arif Accounts', roles: ['accountant'] }],
    next_send_at: '2026-11-14T03:15:00+00:00',
    latest_run: {
        id: 1,
        period_from: '2026-10-31',
        period_to: '2026-11-06',
        status: 'sent',
        recipients_sent: 1,
        recipients_skipped: 0,
        error: null,
        due_at: '2026-11-07T03:15:00+00:00',
        sent_at: '2026-11-07T03:16:00+00:00',
        created_at: null,
        resend_count: 0,
        last_resent_at: null,
        last_resent_by: null,
        deliveries: [],
    },
    created_at: null,
    updated_at: null,
};

/** P9: a failed run — Rina's copy went out, Arif's failed. */
const failedRun: ReportScheduleRun = {
    id: 2,
    period_from: '2026-11-07',
    period_to: '2026-11-13',
    status: 'failed',
    recipients_sent: 1,
    recipients_skipped: 0,
    error: 'TransportException: 550 for <[email]>',
    due_at: '2026-11-14T03:15:00+00:00',
    sent_at: '2026-11-14T03:16:00+00:00',
    created_at: null,
    resend_count: 1,
    last_resent_at: '2026-11-14T04:00:00+00:00',
    last_resent_by: { id: 4, name: 'Rina Manager' },
    deliveries: [
        { user_id: 4, name: 'Rina Manager', status: 'sent', attempts: 1, error: null, sent_at: '2026-11-14T03:16:00+00:00', resent_at: null, resent_by: null },
        { user_id: 3, name: 'Arif Accounts', status: 'failed', attempts: 2, error: 'TransportException', sent_at: null, resent_at: '2026-11-14T04:00:00+00:00', resent_by: 'Rina Manager' },
    ],
};

function api(schedules: ReportSchedule[] = [weekly], runs: ReportScheduleRun[] = [weekly.latest_run!]) {
    return mockApi((url, init) => {
        if (url.includes('/resend') && init?.method === 'POST') return { status: 202, body: { ...failedRun, status: 'queued' } };
        if (url.endsWith('/auth/me')) return { status: 200, body: accountant };
        if (url.includes('/reports/schedules/recipients')) {
            return { status: 200, body: { data: [{ id: 3, name: 'Arif Accounts', roles: ['accountant'] }, { id: 4, name: 'Rina Manager', roles: ['manager'] }] } };
        }
        if (url.includes('/runs')) return { status: 200, body: { data: runs } };
        if (url.endsWith('/reports/schedules') && init?.method === 'POST') return { status: 201, body: { ...weekly, id: 8, name: 'Daily sales' } };
        if (url.includes('/reports/schedules/7') && init?.method === 'PUT') return { status: 200, body: { ...weekly, is_active: false } };
        if (url.endsWith('/reports/schedules')) return { status: 200, body: { data: schedules } };
        if (url.includes('/inventory/low-stock-count')) return { status: 200, body: { location_id: 1, count: 0 } };
        return { status: 404 };
    });
}

const bodies = (calls: ReturnType<typeof mockApi>, method: string) =>
    calls.mock.calls.filter(([, init]) => (init as RequestInit | undefined)?.method === method).map(([url, init]) => [String(url), JSON.parse(String((init as RequestInit).body ?? '{}'))] as const);

describe('scheduled reports', () => {
    it('lists schedules as Automations cards with when, then and the last send', async () => {
        api();
        renderApp('/analytics/schedules');
        const card = await screen.findByRole('button', { name: /Weekly P&L/ }, { timeout: 8000 });
        expect(within(card).getByText('When: Every Saturday at 09:15')).toBeTruthy();
        expect(within(card).getByText('Then: Profit and loss as XLSX to 1 staff')).toBeTruthy();
        expect(within(card).getByText(/Last sent: 2026-10-31 – 2026-11-06/)).toBeTruthy();
        expect(await screen.findByText('Recent runs')).toBeTruthy();
    });

    it('switches a schedule off with the full document', async () => {
        const calls = api();
        renderApp('/analytics/schedules');
        fireEvent.click(await screen.findByRole('switch', { name: 'Send Weekly P&L' }, { timeout: 8000 }));
        await waitFor(() => expect(bodies(calls, 'PUT').length).toBe(1));
        expect(bodies(calls, 'PUT')[0]![1]).toEqual({ name: 'Weekly P&L', report_type: 'pnl', frequency: 'weekly', send_time: '09:15', locale: 'bn', format: 'xlsx', is_active: false, recipient_ids: [3] });
    });

    it('creates a schedule for staff picked by name, and refuses one without recipients', async () => {
        const calls = api([]);
        renderApp('/analytics/schedules?new=1');
        fireEvent.change(await screen.findByLabelText(/^Name/), { target: { value: 'Daily sales' } });
        fireEvent.click(screen.getByRole('button', { name: 'Daily' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save schedule' }));
        await waitFor(() => expect(screen.getByRole('alert')).toBeTruthy());
        expect(bodies(calls, 'POST')).toHaveLength(0);

        fireEvent.click(await screen.findByRole('checkbox', { name: /Rina Manager/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Save schedule' }));
        await waitFor(() => expect(bodies(calls, 'POST')).toHaveLength(1));
        expect(bodies(calls, 'POST')[0]![1]).toEqual({ name: 'Daily sales', report_type: 'sales', frequency: 'daily', send_time: '09:00', locale: 'en', format: 'xlsx', is_active: true, recipient_ids: [4] });
    });

    it('lists a failed run\'s copies and resends the failed ones after confirming (P9)', async () => {
        const calls = api([weekly], [failedRun]);
        renderApp('/analytics/schedules');

        const copies = await screen.findByRole('list', { name: 'Recipients of 2026-11-07 – 2026-11-13' }, { timeout: 8000 });
        expect(within(copies).getByText('Arif Accounts')).toBeTruthy();
        expect(within(copies).getByText('Failed')).toBeTruthy();
        expect(within(copies).getByText('Sent')).toBeTruthy();
        // Only a failed copy offers a one-person resend.
        expect(within(copies).queryByRole('button', { name: 'Resend to Rina Manager' })).toBeNull();
        expect(screen.getByText('Resent 1× · last by Rina Manager')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Resend failed copies' }));
        expect(await screen.findByText('Resend the report for 2026-11-07 – 2026-11-13?')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Resend' }));
        await waitFor(() => expect(bodies(calls, 'POST')).toHaveLength(1));
        expect(bodies(calls, 'POST')[0]).toEqual(['/api/v1/admin/reports/schedules/7/runs/2/resend', {}]);
        expect(await screen.findByText('Resend queued')).toBeTruthy();
    });

    it('resends one recipient\'s failed copy', async () => {
        const calls = api([weekly], [failedRun]);
        renderApp('/analytics/schedules');

        fireEvent.click(await screen.findByRole('button', { name: 'Resend to Arif Accounts' }, { timeout: 8000 }));
        expect(await screen.findByText('Resend to Arif Accounts?')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Resend' }));
        await waitFor(() => expect(bodies(calls, 'POST')).toHaveLength(1));
        expect(bodies(calls, 'POST')[0]![1]).toEqual({ user_id: 3 });
    });

    it('offers no resend on a run that did not fail', async () => {
        api();
        renderApp('/analytics/schedules');
        expect(await screen.findByText('Recent runs', undefined, { timeout: 8000 })).toBeTruthy();
        await screen.findByText(/1 sent/);
        expect(screen.queryByRole('button', { name: 'Resend failed copies' })).toBeNull();
    });

    it('renders in Bangla with Western digits', async () => {
        api();
        renderApp('/analytics/schedules', { locale: 'bn' });
        expect(await screen.findByText('কখন: প্রতি শনিবার 09:15-এ')).toBeTruthy();
        expect(document.body.textContent ?? '').not.toMatch(/[০-৯]/);
    });
});
