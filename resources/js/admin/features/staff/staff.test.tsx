import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import { en, bn } from '@/i18n/messages';
import type { RoleMatrix, StaffMember, StaffRole } from './api';
import { groupPermissions } from './parts';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const owner: Me = { ...viewer, id: 1, name: 'Mominul Islam', roles: ['super_admin'], permissions: ['dashboard.view', 'staff.view', 'staff.manage', 'roles.manage'] };
const manager: Me = { ...viewer, roles: ['manager'], permissions: ['dashboard.view', 'staff.view'] };

const people: StaffMember[] = [
    { id: 1, name: 'Mominul Islam', email: 'owner@toymartbd.com', is_active: true, roles: ['super_admin'], last_login_at: new Date().toISOString(), created_at: null, is_self: true, two_factor_enabled: true, two_factor_required: true },
    { id: 2, name: 'Rina Akter', email: 'rina@toymartbd.com', is_active: true, roles: ['order_operator'], last_login_at: null, created_at: null, is_self: false, two_factor_enabled: false, two_factor_required: false },
    { id: 3, name: 'Tanvir Nabi', email: 'tanvir@toymartbd.com', is_active: false, roles: ['inventory_staff'], last_login_at: null, created_at: null, is_self: false, two_factor_enabled: false, two_factor_required: false },
    { id: 4, name: 'Kamrul Bari', email: 'kamrul@toymartbd.com', is_active: true, roles: ['accountant'], last_login_at: null, created_at: null, is_self: false, two_factor_enabled: false, two_factor_required: true },
    { id: 5, name: 'Sadia Haque', email: 'sadia@toymartbd.com', is_active: true, roles: ['accountant'], last_login_at: null, created_at: null, is_self: false, two_factor_enabled: true, two_factor_required: true },
];

/** Every permission the API knows, in the server's order (App\Models\Enums\Permission). */
const allPermissions = Object.keys(en)
    .filter((key) => key.startsWith('perm.') && !key.startsWith('perm.group.'))
    .map((key) => key.slice('perm.'.length));

const ownerOnly = ['staff.manage', 'roles.manage'];
const money = ['catalog.view_cost', 'refunds.approve', 'finance.view', 'reports.view'];

function role(key: string, permissions: string[], extra: Partial<StaffRole> = {}): StaffRole {
    return { key, permissions, default_permissions: permissions, users_count: 0, bypasses_all: false, editable: true, warnings: [], ...extra };
}

const roles: StaffRole[] = [
    role('super_admin', allPermissions, { users_count: 1, bypasses_all: true, editable: false }),
    role('manager', allPermissions.filter((p) => !ownerOnly.includes(p))),
    role('order_operator', ['dashboard.view', 'orders.view', 'refunds.create'], { users_count: 1 }),
    role('inventory_staff', ['dashboard.view', 'inventory.view']),
    role('accountant', ['dashboard.view', 'refunds.approve'], { users_count: 2 }),
    role('content_editor', ['dashboard.view']),
    role('viewer', ['dashboard.view']),
];

const matrix: RoleMatrix = {
    roles,
    permissions: allPermissions.map((key) => ({ key, owner_only: ownerOnly.includes(key), reveals_money: money.includes(key) })) as RoleMatrix['permissions'],
};

type Reply = { status: number; body?: unknown };

function staffApi(me: Me, overrides: Partial<Record<string, Reply>> = {}) {
    return mockApi((url, init) => {
        const method = init?.method ?? 'GET';
        const path = new URL(url, 'http://x').pathname.replace('/api/v1/admin', '');
        const override = overrides[`${method} ${path}`];
        if (override) return override;
        if (path === '/auth/me') return { status: 200, body: me };
        if (path === '/staff' && method === 'GET') return { status: 200, body: { data: people, links: {}, meta: { next_cursor: null, per_page: 25 } } };
        if (path === '/staff/roles') return { status: 200, body: matrix };
        if (path.startsWith('/staff/roles/') && method === 'PUT') {
            const key = path.split('/').pop()!;
            const sent = JSON.parse(String(init?.body)) as { permissions: string[] };
            return { status: 200, body: { ...matrix, roles: roles.map((r) => (r.key === key ? { ...r, permissions: sent.permissions } : r)) } };
        }
        if (path === '/staff' && method === 'POST') {
            const sent = JSON.parse(String(init?.body)) as { name: string; email: string; role: string };
            return { status: 201, body: { ...people[1], id: 9, name: sent.name, email: sent.email, roles: [sent.role] } };
        }
        const match = /^\/staff\/(\d+)(?:\/(\w+))?(?:-factor\/reset)?$/.exec(path);
        if (match) {
            const member = people.find((p) => p.id === Number(match[1]))!;
            if (match[2] === 'password') return { status: 204 };
            if (match[2] === 'two') return { status: 200, body: { ...member, two_factor_enabled: false } };
            if (match[2] === 'deactivate') return { status: 200, body: { ...member, is_active: false } };
            if (match[2] === 'reactivate') return { status: 200, body: { ...member, is_active: true } };
            return { status: 200, body: { ...member, ...(JSON.parse(String(init?.body)) as object) } };
        }
        return { status: 404 };
    });
}

function sent(calls: ReturnType<typeof mockApi>, method: string, suffix: string): { body: unknown } | undefined {
    const call = calls.mock.calls.find(([url, init]) => String(url).endsWith(suffix) && ((init as RequestInit | undefined)?.method ?? 'GET') === method);
    if (!call) return undefined;
    const body = (call[1] as RequestInit).body;
    return { body: body ? (JSON.parse(String(body)) as unknown) : undefined };
}

describe('settings → staff & roles', () => {
    it('lists people with their role, status, last sign-in and a "You" marker', async () => {
        staffApi(owner);
        renderApp('/staff');

        const rina = (await screen.findByText('Rina Akter')).closest('tr')!;
        expect(within(rina).getByText('Order operator')).toBeTruthy();
        expect(within(rina).getByText('Never signed in')).toBeTruthy();
        expect(within(rina).getByText('Active')).toBeTruthy();
        expect(within(screen.getByText('Tanvir Nabi').closest('tr')!).getByText('Deactivated')).toBeTruthy();
        expect(within(within(screen.getByRole('table')).getByText('Mominul Islam').closest('tr')!).getByText('You')).toBeTruthy();
        expect(await screen.findByText('5 people · 7 roles')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Add staff' })).toBeTruthy();
    });

    it('lets staff.view read without any write controls', async () => {
        staffApi(manager);
        renderApp('/staff');

        await screen.findByText('Rina Akter');
        expect(screen.queryByRole('button', { name: 'Add staff' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Manage Rina Akter' })).toBeNull();
    });

    it('shows no data to a role without staff.view', async () => {
        const calls = staffApi(viewer);
        renderApp('/staff');

        expect(await screen.findByText('You do not have access to this page.')).toBeTruthy();
        expect(sent(calls, 'GET', '/staff')).toBeUndefined();
    });

    it('validates with the generated schema, then adds a person', async () => {
        const calls = staffApi(owner);
        renderApp('/staff');

        fireEvent.click(await screen.findByRole('button', { name: 'Add staff' }));
        const dialog = screen.getByRole('dialog', { name: 'Add staff' });
        fireEvent.change(within(dialog).getByLabelText(/Full name/), { target: { value: 'Sadia Haque' } });
        fireEvent.change(within(dialog).getByLabelText(/Email/), { target: { value: 'sadia@toymartbd.com' } });
        fireEvent.change(within(dialog).getByLabelText(/Role/), { target: { value: 'accountant' } });
        fireEvent.change(within(dialog).getByLabelText(/Temporary password/), { target: { value: 'short' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Add staff' }));

        await waitFor(() => expect(within(dialog).getByLabelText(/Temporary password/).getAttribute('aria-invalid')).toBe('true'));
        expect(sent(calls, 'POST', '/staff')).toBeUndefined();

        fireEvent.change(within(dialog).getByLabelText(/Temporary password/), { target: { value: 'a-long-temp-pass-1' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Add staff' }));

        await waitFor(() => expect(sent(calls, 'POST', '/staff')).toBeDefined());
        expect(sent(calls, 'POST', '/staff')?.body).toEqual({ name: 'Sadia Haque', email: 'sadia@toymartbd.com', role: 'accountant', password: 'a-long-temp-pass-1' });
        expect(await screen.findByText('Sadia Haque added')).toBeTruthy();
    });

    it('sends only the changed role when managing someone', async () => {
        const calls = staffApi(owner);
        renderApp('/staff');

        fireEvent.click(await screen.findByRole('button', { name: 'Manage Rina Akter' }));
        const dialog = screen.getByRole('dialog', { name: 'Manage Rina Akter' });
        expect(within(dialog).queryByLabelText(/^Email/)).toBeNull();
        fireEvent.change(within(dialog).getByLabelText(/Role/), { target: { value: 'viewer' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(sent(calls, 'PATCH', '/staff/2')).toBeDefined());
        expect(sent(calls, 'PATCH', '/staff/2')?.body).toEqual({ role: 'viewer' });
    });

    it('on your own account offers only the name', async () => {
        staffApi(owner);
        renderApp('/staff');

        fireEvent.click(await screen.findByRole('button', { name: 'Manage Mominul Islam' }));
        const dialog = screen.getByRole('dialog', { name: 'Manage Mominul Islam' });
        expect(within(dialog).queryByLabelText(/Role/)).toBeNull();
        expect(within(dialog).queryByRole('button', { name: 'Deactivate' })).toBeNull();
        expect(within(dialog).queryByRole('button', { name: 'Set password' })).toBeNull();
        expect(within(dialog).getByText('This is your account. Another owner must change your role or status.')).toBeTruthy();
    });

    it('sets a new temporary password', async () => {
        const calls = staffApi(owner);
        renderApp('/staff');

        fireEvent.click(await screen.findByRole('button', { name: 'Manage Rina Akter' }));
        const dialog = screen.getByRole('dialog', { name: 'Manage Rina Akter' });
        const input = within(dialog).getAllByLabelText(/Temporary password/)[0]!;
        fireEvent.change(input, { target: { value: 'brand-new-pass-77' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Set password' }));

        await waitFor(() => expect(sent(calls, 'POST', '/staff/2/password')).toBeDefined());
        expect(sent(calls, 'POST', '/staff/2/password')?.body).toEqual({ password: 'brand-new-pass-77' });
        expect(await screen.findByText('New temporary password set for Rina Akter')).toBeTruthy();
    });

    it('deactivates only after confirming, and reactivates directly', async () => {
        const calls = staffApi(owner);
        renderApp('/staff');

        fireEvent.click(await screen.findByRole('button', { name: 'Manage Rina Akter' }));
        fireEvent.click(within(screen.getByRole('dialog', { name: 'Manage Rina Akter' })).getByRole('button', { name: 'Deactivate' }));
        expect(sent(calls, 'POST', '/staff/2/deactivate')).toBeUndefined();

        const confirm = await screen.findByRole('dialog', { name: 'Deactivate Rina Akter?' });
        fireEvent.click(within(confirm).getByRole('button', { name: 'Deactivate' }));
        await waitFor(() => expect(sent(calls, 'POST', '/staff/2/deactivate')).toBeDefined());
        expect(await screen.findByText('Rina Akter deactivated')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Manage Tanvir Nabi' }));
        fireEvent.click(within(screen.getByRole('dialog', { name: 'Manage Tanvir Nabi' })).getByRole('button', { name: 'Reactivate' }));
        await waitFor(() => expect(sent(calls, 'POST', '/staff/3/reactivate')).toBeDefined());
    });

    it("shows the server's reason when a change is refused (409)", async () => {
        staffApi(owner, {
            'PATCH /staff/2': {
                status: 409,
                body: { type: 'https://toymartbd.com/problems/conflict', title: 'Conflict', status: 409, detail: 'This is the last active owner. Make someone else an owner first.' },
            },
        });
        renderApp('/staff');

        fireEvent.click(await screen.findByRole('button', { name: 'Manage Rina Akter' }));
        const dialog = screen.getByRole('dialog', { name: 'Manage Rina Akter' });
        fireEvent.change(within(dialog).getByLabelText(/Role/), { target: { value: 'viewer' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }));

        expect(await screen.findByText('This is the last active owner. Make someone else an owner first.')).toBeTruthy();
    });

    it('shows the two-factor column: On, Off, and Off flagged where the role sees money', async () => {
        staffApi(owner);
        renderApp('/staff');

        const table = await screen.findByRole('table');
        expect(within(within(table).getByText('Sadia Haque').closest('tr')!).getByText('On')).toBeTruthy();
        const kamrul = within(table).getByText('Kamrul Bari').closest('tr')!;
        expect(within(kamrul).getByText('Off')).toBeTruthy();
        expect(within(kamrul).getByText('Their role can see money: they must set up two-factor at their next sign-in.')).toBeTruthy();
        expect(within(within(table).getByText('Rina Akter').closest('tr')!).queryByText(/must set up two-factor/)).toBeNull();
    });

    it("resets someone's two-factor only after confirming", async () => {
        const calls = staffApi(owner);
        renderApp('/staff');

        fireEvent.click(await screen.findByRole('button', { name: 'Manage Sadia Haque' }));
        fireEvent.click(within(screen.getByRole('dialog', { name: 'Manage Sadia Haque' })).getByRole('button', { name: 'Reset two-factor' }));
        expect(sent(calls, 'POST', '/staff/5/two-factor/reset')).toBeUndefined();

        fireEvent.click(within(await screen.findByRole('dialog', { name: "Reset Sadia Haque's two-factor?" })).getByRole('button', { name: 'Reset two-factor' }));
        await waitFor(() => expect(sent(calls, 'POST', '/staff/5/two-factor/reset')).toBeDefined());
        expect(await screen.findByText("Sadia Haque's two-factor was reset")).toBeTruthy();
    });

    it('shows the matrix read-only without roles.manage: marks, no checkboxes', async () => {
        staffApi(manager);
        renderApp('/staff/roles');

        const table = await screen.findByRole('table', { name: 'Permissions by role' });
        expect(screen.getByText('Only an owner can change what a role can do.')).toBeTruthy();
        expect(within(table).queryAllByRole('checkbox')).toHaveLength(0);

        const refunds = within(table).getByRole('rowheader', { name: /Issue refunds/ }).closest('tr')!;
        const cells = within(refunds).getAllByRole('img');
        expect(cells[0]!.getAttribute('aria-label')).toBe('Owner role: every permission, always');
        expect(cells[1]!.getAttribute('aria-label')).toBe('Allowed');
        expect(cells[2]!.getAttribute('aria-label')).toBe('Allowed');
        expect(cells[3]!.getAttribute('aria-label')).toBe('Not allowed');
        expect(within(table).getAllByText('1 active')).toHaveLength(2);
    });

    it('lets an owner edit: tick, review, confirm, and one PUT per changed role', async () => {
        const calls = staffApi(owner);
        renderApp('/staff/roles');

        const table = await screen.findByRole('table', { name: 'Permissions by role' });
        // Owner-only rows and the owner column are locked.
        const manageStaff = within(table).getByRole('rowheader', { name: /Manage staff/ }).closest('tr')!;
        expect(within(manageStaff).queryAllByRole('checkbox')).toHaveLength(0);
        expect(within(manageStaff).getAllByRole('img', { name: 'Owner only: never given to another role' })).toHaveLength(6);
        // Money rows carry the two-factor mark.
        expect(within(within(table).getByRole('rowheader', { name: /View finance/ })).getByRole('img', { name: 'Shows money: holders must use two-factor sign-in' })).toBeTruthy();

        fireEvent.click(within(table).getByRole('checkbox', { name: 'View finance for Viewer' }));
        fireEvent.click(within(table).getByRole('checkbox', { name: 'Approve refunds for Order operator' }));
        fireEvent.click(within(table).getByRole('checkbox', { name: 'Approve refunds for Order operator' })); // undone
        expect(screen.getByText('Unsaved changes to 1 role(s)')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Save permissions' }));
        const confirm = await screen.findByRole('dialog', { name: 'Change permissions for 1 role(s)?' });
        expect(sent(calls, 'PUT', '/staff/roles/viewer')).toBeUndefined();
        fireEvent.click(within(confirm).getByRole('button', { name: 'Save permissions' }));

        await waitFor(() => expect(sent(calls, 'PUT', '/staff/roles/viewer')).toBeDefined());
        expect(sent(calls, 'PUT', '/staff/roles/viewer')?.body).toEqual({ permissions: ['dashboard.view', 'finance.view'] });
        expect(sent(calls, 'PUT', '/staff/roles/order_operator')).toBeUndefined();
        expect(await screen.findByText('Permissions saved for 1 role(s)')).toBeTruthy();
    });

    it('restores a role to its defaults and can discard edits', async () => {
        const edited = { ...matrix, roles: roles.map((r) => (r.key === 'viewer' ? { ...r, permissions: ['dashboard.view', 'finance.view'] } : r)) };
        const calls = staffApi(owner, { 'GET /staff/roles': { status: 200, body: edited } });
        renderApp('/staff/roles');

        fireEvent.click(await screen.findByRole('button', { name: 'Restore the default permissions for Viewer' }));
        expect(screen.getByText('Unsaved changes to 1 role(s)')).toBeTruthy();
        expect((screen.getByRole('checkbox', { name: 'View finance for Viewer' }) as HTMLInputElement).checked).toBe(false);

        fireEvent.click(screen.getByRole('button', { name: 'Discard' }));
        expect(screen.queryByText(/Unsaved changes/)).toBeNull();
        expect((screen.getByRole('checkbox', { name: 'View finance for Viewer' }) as HTMLInputElement).checked).toBe(true);
        expect(sent(calls, 'PUT', '/staff/roles/viewer')).toBeUndefined();
    });

    it('shows the separation-of-duties warning the server reports', async () => {
        const warned = { ...matrix, roles: roles.map((r) => (r.key === 'order_operator' ? { ...r, warnings: [['refunds.create', 'refunds.approve'] as [string, string]] } : r)) };
        staffApi(manager, { 'GET /staff/roles': { status: 200, body: warned } });
        renderApp('/staff/roles');

        expect(await screen.findByText('Order operator can both “Issue refunds” and “Approve refunds”. Separate those two.')).toBeTruthy();
    });

    it('puts every permission in exactly one group, so none can vanish from the matrix', () => {
        const grouped = groupPermissions(allPermissions).flatMap((g) => g.codes);
        expect(grouped).toHaveLength(allPermissions.length);
        expect(new Set(grouped)).toEqual(new Set(allPermissions));
        expect(groupPermissions([...allPermissions, 'newthing.view']).flatMap((g) => g.codes)).not.toContain('newthing.view');
    });

    it('has a Bangla string for every staff key, and renders in Bangla', async () => {
        for (const key of Object.keys(en).filter((k) => k.startsWith('staff.') || k.startsWith('roles.') || k.startsWith('perm.'))) {
            expect(bn[key as keyof typeof bn], key).toBeTruthy();
        }
        staffApi(owner);
        renderApp('/staff', { locale: 'bn' });
        expect(await screen.findByRole('heading', { name: 'স্টাফ ও ভূমিকা' })).toBeTruthy();
    });
});
