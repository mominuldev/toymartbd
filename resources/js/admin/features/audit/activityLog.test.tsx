import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import { auditBn, auditEn } from '@/i18n/messages.audit';
import { daysBefore, dhakaToday, presetRange, type AuditEntry } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const owner: Me = { ...viewer, id: 1, name: 'Mominul Islam', roles: ['super_admin'], permissions: ['dashboard.view', 'staff.view', 'staff.manage', 'audit.view', 'reports.export'] };
const accountant: Me = { ...viewer, id: 4, name: 'Kamrul Bari', roles: ['accountant'], permissions: ['dashboard.view', 'audit.view'] };

const today = dhakaToday();
const yesterday = daysBefore(today, 1);

function entry(id: number, extra: Partial<AuditEntry>): AuditEntry {
    return {
        id,
        action: 'cost_changed',
        kind: 'cost_changed',
        area: 'catalog',
        actor: { id: 1, name: 'Mominul Islam' },
        subject: { type: 'variant', id: 10, label: 'Brick Box 500 / Classic' },
        changes: [{ field: 'cost_price', before: '1050.00', after: '1120.00' }],
        created_at: `${today}T04:04:00Z`,
        day: today,
        ip: '103.108.xx.xx',
        device: 'Chrome · macOS',
        ...extra,
    };
}

const rows: AuditEntry[] = [
    entry(3, {}),
    entry(2, { action: 'credential_changed', kind: 'messaging_settings', area: 'integrations', actor: null, subject: null, changes: [], ip: null, device: null }),
    entry(1, {
        action: 'role_permissions_changed',
        kind: 'permissions_changed',
        area: 'staff',
        subject: { type: 'role', id: 3, label: 'order_operator' },
        changes: [{ field: 'permissions', before: null, after: '+refunds.approve' }],
        created_at: `${yesterday}T12:40:00Z`,
        day: yesterday,
    }),
];

function auditApi(me: Me, onList?: (url: URL) => void) {
    return mockApi((url) => {
        const parsed = new URL(url, 'http://x');
        const path = parsed.pathname.replace('/api/v1/admin', '');
        if (path === '/auth/me') return { status: 200, body: me };
        if (path === '/staff') return { status: 200, body: { data: [{ id: 1, name: 'Mominul Islam' }, { id: 2, name: 'Rina Akter' }], links: {}, meta: { next_cursor: null } } };
        if (path === '/audit') {
            onList?.(parsed);
            if (parsed.searchParams.get('cursor') === 'next') return { status: 200, body: { data: [entry(0, { day: '2026-01-05', created_at: '2026-01-05T03:00:00Z' })], links: {}, meta: { next_cursor: null } } };
            return { status: 200, body: { data: rows, links: {}, meta: { next_cursor: 'next' } } };
        }
        return { status: 404, body: { type: 'about:blank', title: 'Not found', status: 404 } };
    });
}

describe('Activity log (B · 39)', () => {
    it('groups rows by Dhaka day with sentences, change chips, captions and the Audited badge', async () => {
        auditApi(owner);
        renderApp('/staff/activity');

        expect(await screen.findByRole('heading', { level: 1, name: 'Activity log' })).toBeTruthy();
        const todayGroup = await screen.findByRole('heading', { name: /^Today · / });
        expect(screen.getByRole('heading', { name: /^Yesterday · / })).toBeTruthy();

        const firstDay = todayGroup.closest('section')!;
        expect(within(firstDay).getByText('changed cost price on Brick Box 500 / Classic', { exact: false })).toBeTruthy();
        expect(within(firstDay).getByText('cost_price: 1050.00 → 1120.00', { exact: false })).toBeTruthy();
        expect(within(firstDay).getByText(/Products · 103\.108\.xx\.xx · Chrome · macOS/)).toBeTruthy();
        expect(within(firstDay).getByText('System')).toBeTruthy();
        expect(within(firstDay).getByText('changed the messaging settings', { exact: false })).toBeTruthy();
        expect(within(firstDay).getAllByText('Audited')).toHaveLength(2);

        // A role subject shows its translated name.
        expect(screen.getByText('changed what the Order operator role can do', { exact: false })).toBeTruthy();
        expect(screen.getByText('permissions: +refunds.approve', { exact: false })).toBeTruthy();
    });

    it('sends the last 7 Dhaka days by default, then the person and area filters', async () => {
        const seen: URL[] = [];
        auditApi(owner, (url) => seen.push(url));
        renderApp('/staff/activity');

        await screen.findByRole('heading', { name: /^Today · / });
        const week = presetRange('7d', today);
        expect(seen[0]!.searchParams.get('filter[from]')).toBe(week.from);
        expect(seen[0]!.searchParams.get('filter[to]')).toBe(today);

        fireEvent.change(await screen.findByLabelText('Person'), { target: { value: '2' } });
        await waitFor(() => expect(seen.at(-1)!.searchParams.get('filter[user_id]')).toBe('2'));

        fireEvent.change(screen.getByLabelText('Area'), { target: { value: 'refunds' } });
        await waitFor(() => expect(seen.at(-1)!.searchParams.get('filter[area]')).toBe('refunds'));

        const exportLink = screen.getByRole('link', { name: /Export/ });
        expect(exportLink.getAttribute('href')).toContain('filter%5Barea%5D=refunds');
    });

    it('loads older rows through the cursor', async () => {
        auditApi(owner);
        renderApp('/staff/activity');

        fireEvent.click(await screen.findByRole('button', { name: 'Load more' }));
        expect(await screen.findByRole('heading', { name: /05 Jan/ })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Load more' })).toBeNull();
    });

    it('hides the person filter and the export from someone without staff.view / reports.export', async () => {
        auditApi(accountant);
        renderApp('/staff/activity');

        await screen.findByRole('heading', { name: /^Today · / });
        expect(screen.queryByLabelText('Person')).toBeNull();
        expect(screen.queryByRole('link', { name: /Export/ })).toBeNull();
        expect(screen.getByLabelText('Area')).toBeTruthy();
    });

    it('shows the empty state and refuses without audit.view', async () => {
        mockApi((url) => {
            const path = new URL(url, 'http://x').pathname.replace('/api/v1/admin', '');
            if (path === '/auth/me') return { status: 200, body: owner };
            return { status: 200, body: { data: [], links: {}, meta: { next_cursor: null } } };
        });
        renderApp('/staff/activity');
        expect(await screen.findByText('No activity in this range')).toBeTruthy();
        cleanup();

        auditApi(viewer);
        renderApp('/staff/activity');
        expect(await screen.findByText('You do not have access to this page.')).toBeTruthy();
    });

    it('links the Staff & roles header to the log for audit.view holders', async () => {
        mockApi((url) => {
            const path = new URL(url, 'http://x').pathname.replace('/api/v1/admin', '');
            if (path === '/auth/me') return { status: 200, body: owner };
            if (path === '/staff/roles') return { status: 200, body: { roles: [], permissions: [] } };
            return { status: 200, body: { data: [], links: {}, meta: { next_cursor: null } } };
        });
        renderApp('/staff');

        const link = await screen.findByRole('link', { name: 'Audit log' });
        expect(link.getAttribute('href')).toBe('/staff/activity');
    });

    it('has a Bangla string for every key', () => {
        expect(Object.keys(auditBn).sort()).toEqual(Object.keys(auditEn).sort());
        expect(Object.values(auditBn).every((s) => s.trim() !== '')).toBe(true);
    });
});
