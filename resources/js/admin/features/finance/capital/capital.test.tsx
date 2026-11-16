import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { Me } from '@/features/auth/api';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { CapitalEntry, CapitalHolder, CapitalSummary } from './api';
import { formatMoney } from '@/lib/money';
import { taka, typedAmount } from './parts';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const accountant: Me = { ...viewer, roles: ['accountant'], permissions: ['dashboard.view', 'finance.view', 'finance.manage_capital', 'reports.view'] };
const reader: Me = { ...viewer, roles: ['custom'], permissions: ['dashboard.view', 'finance.view'] };

const mominul = { id: 1, name: 'Mominul Islam', name_bn: 'মমিনুল ইসলাম', role: 'owner' as const, is_active: true };
const farhana = { id: 2, name: 'Farhana Akter', name_bn: 'ফারহানা আক্তার', role: 'partner' as const, is_active: true };

const summary: CapitalSummary = {
    from: null,
    to: null,
    holders: [
        { holder: { ...mominul, has_staff_login: true }, put_in: '900000.00', taken_out: '140000.00', net: '760000.00', entries_count: 4, last_entry: { id: 12, kind: 'contribution', amount: '200000.00', net_effect: '200000.00', occurred_on: '2026-11-15', is_reversal: false } },
        { holder: { ...farhana, has_staff_login: false }, put_in: '250000.00', taken_out: '30000.00', net: '220000.00', entries_count: 2, last_entry: { id: 11, kind: 'drawing', amount: '30000.00', net_effect: '-30000.00', occurred_on: '2026-11-13', is_reversal: false } },
    ],
    totals: { put_in: '1150000.00', taken_out: '170000.00', net: '980000.00', entries_count: 6, last_entry: { id: 12, kind: 'contribution', amount: '200000.00', net_effect: '200000.00', occurred_on: '2026-11-15', is_reversal: false, holder_id: 1, holder_name: 'Mominul Islam', holder_name_bn: 'মমিনুল ইসলাম' } },
};

const entry = (over: Partial<CapitalEntry>): CapitalEntry => ({
    id: 12,
    kind: 'contribution',
    holder: mominul,
    amount: '200000.00',
    net_effect: '200000.00',
    occurred_on: '2026-11-15',
    method: 'bkash',
    reference: '9KQ7H2LM4P',
    note: 'Stock for the winter season',
    ledger_type: 'owner_capital_in',
    is_reversal: false,
    reversal_of_id: null,
    reversed_by_id: null,
    reversed_at: null,
    reversed_on: null,
    recorded_by: { id: 3, name: 'Rina Akter' },
    created_at: '2026-11-15T04:00:00Z',
    can: { reverse: true },
    ...over,
});

const entries: CapitalEntry[] = [
    entry({}),
    entry({ id: 11, kind: 'drawing', holder: farhana, amount: '30000.00', net_effect: '-30000.00', occurred_on: '2026-11-13', method: 'bank_transfer', reference: 'DBBL-CHQ-004512', note: 'Monthly drawing', ledger_type: 'owner_drawing' }),
    entry({ id: 14, kind: 'drawing', amount: '25000.00', net_effect: '25000.00', occurred_on: '2026-11-16', method: 'cash', reference: null, note: 'Entered twice by mistake', is_reversal: true, reversal_of_id: 10, ledger_type: 'owner_drawing', can: { reverse: false } }),
    entry({ id: 10, kind: 'drawing', amount: '25000.00', net_effect: '-25000.00', occurred_on: '2026-10-28', method: 'cash', reference: null, note: 'Personal — entered twice', reversed_by_id: 14, reversed_at: '2026-11-16T05:00:00Z', reversed_on: '2026-11-16', ledger_type: 'owner_drawing', can: { reverse: false } }),
];
const holders: CapitalHolder[] = [
    { ...mominul, user: { id: 3, name: 'Mominul Islam' }, entries_count: 4, can: { update: true } },
    { ...farhana, user: null, entries_count: 2, can: { update: true } },
];

function api(me: Me = accountant, empty = false) {
    return mockApi((url, init) => {
        const method = (init?.method ?? 'GET').toUpperCase();
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (method === 'POST' && url.endsWith('/finance/capital/entries')) {
            const body = JSON.parse(String(init?.body)) as { kind: 'contribution' | 'drawing'; amount: string };
            return { status: 201, body: entry({ id: 20, kind: body.kind, amount: body.amount, net_effect: body.kind === 'drawing' ? `-${body.amount}` : body.amount }) };
        }
        if (method === 'POST' && /\/finance\/capital\/entries\/11\/reverse$/.test(url)) return { status: 201, body: entry({ id: 21, is_reversal: true, reversal_of_id: 11 }) };
        if (method === 'POST' && url.endsWith('/finance/capital/holders')) return { status: 201, body: { id: 5, name: 'Tanvir Hasan', name_bn: null, role: 'partner', user: null, is_active: true, entries_count: 0, can: { update: true } } };
        if (url.includes('/finance/capital/entries')) return { status: 200, body: { data: empty ? [] : entries, links: {}, meta: { next_cursor: 'abc', total: empty ? 0 : 15 } } };
        if (url.includes('/finance/capital/holders')) return { status: 200, body: { data: holders } };
        if (url.includes('/finance/capital/staff')) return { status: 200, body: { data: [{ id: 9, name: 'Rina Akter' }] } };
        if (url.includes('/finance/capital')) return { status: 200, body: empty ? { ...summary, holders: [], totals: { put_in: '0.00', taken_out: '0.00', net: '0.00', entries_count: 0, last_entry: null } } : summary };
        return { status: 404 };
    });
}

const posts = (calls: ReturnType<typeof api>) =>
    calls.mock.calls
        .filter(([, init]) => (init as RequestInit | undefined)?.method === 'POST')
        .map(([url, init]) => ({ url: String(url), body: JSON.parse(String((init as RequestInit).body)) as Record<string, unknown>, headers: (init as RequestInit).headers as Record<string, string> }));

describe('capital formatting', () => {
    const lakhFixed = (decimal: string) => formatMoney(decimal, 'fixed');
    it('groups taka in lakhs from the server string, never computing', () => {
        expect(lakhFixed('1250000.00')).toBe('12,50,000.00');
        expect(lakhFixed('85000.00')).toBe('85,000.00');
        expect(lakhFixed('999.50')).toBe('999.50');
        expect(lakhFixed('200000')).toBe('2,00,000.00');
        expect(taka('-30000.00', true)).toBe('−৳30,000.00');
        expect(taka('200000.00', true)).toBe('+৳2,00,000.00');
        expect(typedAmount('2,00,000')).toBe('200000');
        expect(typedAmount('0')).toBeNull();
        expect(typedAmount('10.555')).toBeNull();
    });
});

describe('Owner capital', () => {
    it('shows the server totals, each person and every entry, with reversals marked', async () => {
        api();
        renderApp('/finance/capital');

        expect(await screen.findByRole('heading', { level: 1, name: 'Owner capital' })).toBeTruthy();
        expect(screen.getByText('Owner money is cash, not income')).toBeTruthy();
        expect((await screen.findAllByText('৳11,50,000.00')).length).toBeGreaterThan(0);
        expect(screen.getAllByText('৳9,80,000.00').length).toBeGreaterThan(0);
        expect(screen.getByText('৳2,00,000.00 in · Mominul Islam')).toBeTruthy();
        expect(screen.getByText('Owner · has a staff login')).toBeTruthy();
        expect(screen.getAllByText('Partner').length).toBeGreaterThan(0);
        expect(screen.getByText('৳7,60,000.00')).toBeTruthy();

        const table = await screen.findByRole('table', { name: 'Every entry' });
        expect(await within(table).findByText('+৳2,00,000.00')).toBeTruthy();
        expect(within(table).getByText('−৳30,000.00')).toBeTruthy();
        expect(within(table).getByText('Cancels entry #10')).toBeTruthy();
        expect(within(table).getByText('Reversed')).toBeTruthy();
        expect(screen.getByText('Showing 4 of 15 entries · newest first')).toBeTruthy();
        // Only the reversible rows carry the menu.
        expect(within(table).getByLabelText('Actions for entry #12')).toBeTruthy();
        expect(within(table).queryByLabelText('Actions for entry #10')).toBeNull();
        expect(within(table).queryByLabelText('Actions for entry #14')).toBeNull();
    });

    it('records a new investment with an Idempotency-Key, echoing the typed amount', async () => {
        const calls = api();
        renderApp('/finance/capital');

        fireEvent.click((await screen.findAllByRole('button', { name: 'New investment' }))[0]!);
        const dialog = await screen.findByRole('dialog', { name: 'New investment — money in' });
        expect(within(dialog).getByText('This is cash coming in — not income')).toBeTruthy();
        await waitFor(() => expect(within(dialog).getByRole('option', { name: 'Mominul Islam · মমিনুল ইসলাম' })).toBeTruthy());

        fireEvent.change(within(dialog).getByLabelText(/Who put the money in/), { target: { value: '1' } });
        fireEvent.change(within(dialog).getByLabelText(/^Amount/), { target: { value: '2,00,000' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'bKash' }));
        fireEvent.change(within(dialog).getByLabelText(/^Reference/), { target: { value: '9KQ7H2LM4P' } });
        expect(within(dialog).getByText('Cash +৳2,00,000.00')).toBeTruthy();
        expect(within(dialog).getByText('Profit unchanged')).toBeTruthy();

        fireEvent.click(within(dialog).getByRole('button', { name: 'Record ৳2,00,000.00 in' }));
        await waitFor(() => expect(posts(calls).length).toBe(1));
        const [post] = posts(calls);
        expect(post!.url).toMatch(/\/finance\/capital\/entries$/);
        expect(post!.body).toMatchObject({ kind: 'contribution', holder_id: 1, amount: '200000', method: 'bkash', reference: '9KQ7H2LM4P' });
        expect(post!.headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
        expect(await screen.findByText('Recorded ৳2,00,000.00 in from Mominul Islam')).toBeTruthy();
    });

    it('records an owner drawing and shows what the person put in and took out so far', async () => {
        const calls = api();
        renderApp('/finance/capital');

        fireEvent.click((await screen.findAllByRole('button', { name: 'Owner drawing' }))[0]!);
        const dialog = await screen.findByRole('dialog', { name: 'Owner drawing — money out' });
        await waitFor(() => expect(within(dialog).getByRole('option', { name: 'Farhana Akter · ফারহানা আক্তার' })).toBeTruthy());
        fireEvent.change(within(dialog).getByLabelText(/Who took the money/), { target: { value: '2' } });
        expect(within(dialog).getByText('Put in ৳2,50,000.00 · taken out ৳30,000.00 so far')).toBeTruthy();

        // Client validation from the generated schema: nothing is sent without an amount.
        fireEvent.click(within(dialog).getByRole('button', { name: 'Record money out' }));
        expect(await within(dialog).findByText('Amount is required')).toBeTruthy();
        expect(posts(calls)).toHaveLength(0);

        fireEvent.change(within(dialog).getByLabelText(/^Amount/), { target: { value: '30000' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Record ৳30,000.00 out' }));
        await waitFor(() => expect(posts(calls).length).toBe(1));
        expect(posts(calls)[0]!.body).toMatchObject({ kind: 'drawing', holder_id: 2, amount: '30000', method: 'cash' });
    });

    it('reverses an entry only with a reason, showing the mirror it will write', async () => {
        const calls = api();
        renderApp('/finance/capital');

        const table = await screen.findByRole('table', { name: 'Every entry' });
        const menu = await within(table).findByLabelText('Actions for entry #11');
        fireEvent.click(menu);
        fireEvent.click(within(menu.closest('details')!).getByRole('button', { name: 'Reverse entry' }));

        const dialog = await screen.findByRole('dialog', { name: 'Reverse this entry?' });
        expect(within(dialog).getByText('Entry #11 · the original')).toBeTruthy();
        expect(within(dialog).getByText('Reversal · money in')).toBeTruthy();
        expect(within(dialog).getByText('+৳30,000.00')).toBeTruthy();
        expect(within(dialog).getByText(/Farhana Akter’s “taken out” total drops back by ৳30,000.00/)).toBeTruthy();

        fireEvent.click(within(dialog).getByRole('button', { name: 'Reverse entry' }));
        expect(await within(dialog).findByText('Why are you reversing it? is required')).toBeTruthy();
        expect(posts(calls)).toHaveLength(0);

        fireEvent.change(within(dialog).getByLabelText(/Why are you reversing it/), { target: { value: 'Was a supplier payment' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Reverse entry' }));
        await waitFor(() => expect(posts(calls).length).toBe(1));
        expect(posts(calls)[0]!.url).toMatch(/\/finance\/capital\/entries\/11\/reverse$/);
        expect(posts(calls)[0]!.body).toEqual({ reason: 'Was a supplier payment' });
        expect(posts(calls)[0]!.headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
    });

    it('adds an owner or partner from a separate dialog and preselects them', async () => {
        const calls = api();
        renderApp('/finance/capital');

        fireEvent.click((await screen.findAllByRole('button', { name: 'New investment' }))[0]!);
        const entryDialog = await screen.findByRole('dialog', { name: 'New investment — money in' });
        fireEvent.click(within(entryDialog).getByRole('button', { name: 'Add a new owner or partner' }));
        const dialog = await screen.findByRole('dialog', { name: 'Add an owner or partner' });
        await waitFor(() => expect(within(dialog).getByRole('option', { name: 'Rina Akter' })).toBeTruthy());
        fireEvent.change(within(dialog).getByLabelText(/^Name(\s\*)?$/), { target: { value: 'Tanvir Hasan' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Partner' }));
        fireEvent.click(within(dialog).getByRole('button', { name: 'Add person' }));
        await waitFor(() => expect(posts(calls).some((p) => p.url.endsWith('/finance/capital/holders'))).toBe(true));
        expect(posts(calls).find((p) => p.url.endsWith('/finance/capital/holders'))!.body).toEqual({ name: 'Tanvir Hasan', name_bn: null, role: 'partner', user_id: null });
    });

    it('is read-only without finance.manage_capital', async () => {
        api(reader);
        renderApp('/finance/capital');

        const table = await screen.findByRole('table', { name: 'Every entry' });
        expect(await within(table).findByText('+৳2,00,000.00')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'New investment' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Owner drawing' })).toBeNull();
        expect(screen.queryByRole('button', { name: /Add owner or partner/ })).toBeNull();
        expect(within(table).queryByLabelText('Actions for entry #12')).toBeNull();
        expect(screen.getByText(/You can see owner capital but not record it/)).toBeTruthy();
    });

    it('invites adding the first person when there is nothing yet', async () => {
        api(accountant, true);
        renderApp('/finance/capital');
        expect(await screen.findByText('No owners or partners yet')).toBeTruthy();
        expect(await screen.findAllByText('Nothing recorded yet. Money in and out will show here.')).toBeTruthy();
        expect(screen.getByText('None yet')).toBeTruthy();
    });

    it('speaks Bangla with Western digits', async () => {
        api();
        renderApp('/finance/capital', { locale: 'bn' });

        expect(await screen.findByRole('heading', { level: 1, name: 'মালিকের মূলধন' })).toBeTruthy();
        expect(screen.getByText('মালিকের টাকা নগদ, আয় নয়')).toBeTruthy();
        expect((await screen.findAllByText('৳11,50,000.00')).length).toBeGreaterThan(0);
        expect(screen.getAllByRole('button', { name: 'নতুন বিনিয়োগ' }).length).toBeGreaterThan(0);
        expect(screen.getByText('মালিক · স্টাফ লগইন আছে')).toBeTruthy();
        expect(await screen.findByText('এন্ট্রি #10 বাতিল করে')).toBeTruthy();
    });

    it('shows a Capital tab in the finance tab row', async () => {
        api();
        renderApp('/finance/capital');
        const tabs = await screen.findByRole('navigation', { name: 'Finance sections' }).catch(() => screen.findByLabelText('Finance sections'));
        expect(within(tabs).getByRole('link', { name: 'Capital' }).getAttribute('href')).toBe('/finance/capital');
    });
});
