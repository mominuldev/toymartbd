import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { renderApp, viewer } from '@/test/renderApp';
import { formatMoney } from './parts';
import { accountant, financeApi, manager } from './fixtures';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const urls = (calls: ReturnType<typeof financeApi>) => calls.mock.calls.map(([url]) => String(url));
const posted = (calls: ReturnType<typeof financeApi>, part: string) => calls.mock.calls.find(([url, init]) => String(url).includes(part) && (init as RequestInit | undefined)?.method === 'POST');

describe('finance formatting', () => {
    it('groups and trims the server decimal string without computing', () => {
        expect(formatMoney('35000.00')).toBe('35,000');
        expect(formatMoney('1050.50')).toBe('1,050.5');
        expect(formatMoney('-25000.00')).toBe('−25,000');
    });
});

describe('expenses', () => {
    it('shows the server-computed stat cards and the expense rows', async () => {
        financeApi(accountant);
        renderApp('/finance/expenses');

        const table = await screen.findByRole('table');
        expect(within(table).getByText('Rent')).toBeTruthy();
        expect(within(table).getByText('Pending approval')).toBeTruthy();
        expect(within(table).getByText((_, el) => el?.tagName === 'TD' && el.textContent === '৳35,000')).toBeTruthy();
        expect(within(table).getByRole('link', { name: /Open receipt rent-nov.pdf/ }).getAttribute('href')).toBe('/api/v1/admin/finance/expenses/41/receipt');
        expect(await screen.findByText('10.1%')).toBeTruthy();
        expect(screen.getAllByText((_, el) => el?.tagName === 'SPAN' && el.textContent === '৳68,400').length).toBeGreaterThan(0);
        expect(screen.getByRole('heading', { name: 'Add expense' })).toBeTruthy();
    });

    it('keeps Figma’s columns: no status column, a non-approved state as a chip, secondary actions in a row menu', async () => {
        financeApi(manager);
        renderApp('/finance/expenses');
        const table = await screen.findByRole('table');

        expect(within(table).queryByRole('columnheader', { name: 'Status' })).toBeNull();
        expect(within(table).getByText('Pending approval')).toBeTruthy();
        expect(within(table).getAllByTitle('Actions').every((el) => el.tagName === 'SUMMARY')).toBe(true);
        expect(within(table).getByRole('button', { name: 'Reject' }).closest('details')).toBeTruthy();
    });

    it('filters on the server with the filter in the URL', async () => {
        const calls = financeApi(accountant);
        renderApp('/finance/expenses');
        await screen.findByRole('table');

        fireEvent.change(screen.getByRole('combobox', { name: 'Status' }), { target: { value: 'pending' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Bstatus%5D=pending'))).toBe(true));
    });

    it('approves through the API', async () => {
        const calls = financeApi(manager);
        renderApp('/finance/expenses');
        const table = await screen.findByRole('table');

        fireEvent.click(within(table).getByRole('button', { name: 'Approve' }));
        await waitFor(() => expect(posted(calls, '/finance/expenses/41/approve')).toBeTruthy());
    });

    it('requires a reason to reject, then sends it', async () => {
        const calls = financeApi(manager);
        renderApp('/finance/expenses');
        const table = await screen.findByRole('table');

        fireEvent.click(within(table).getByRole('button', { name: 'Reject' }));
        const dialog = await screen.findByRole('dialog', { hidden: true });
        fireEvent.change(within(dialog).getByLabelText(/Reason/), { target: { value: 'Wrong amount' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Reject', hidden: true }));
        await waitFor(() => {
            const call = posted(calls, '/reject');
            expect(call).toBeTruthy();
            expect(JSON.parse(String((call![1] as RequestInit).body))).toEqual({ reason: 'Wrong amount' });
        });
    });

    it('creates an expense as multipart, never computing money in the browser', async () => {
        const calls = financeApi(accountant);
        renderApp('/finance/expenses');
        await screen.findByRole('table');

        const form = screen.getByRole('heading', { name: 'Add expense' }).closest('form')!;
        fireEvent.change(within(form).getByLabelText(/^Amount/), { target: { value: '1,500' } });
        fireEvent.change(within(form).getByLabelText(/^Category/), { target: { value: '2' } });
        fireEvent.click(within(form).getByRole('button', { name: 'Save expense' }));

        await waitFor(() => {
            const call = calls.mock.calls.find(([url, init]) => String(url).endsWith('/finance/expenses') && (init as RequestInit | undefined)?.method === 'POST');
            expect(call).toBeTruthy();
            const body = (call![1] as RequestInit).body as FormData;
            expect(body.get('amount')).toBe('1500');
            expect(body.get('category_id')).toBe('2');
            expect(body.get('is_recurring')).toBe('0');
        });
    });

    it('hides the form and actions from a viewer without finance rights', async () => {
        financeApi({ ...viewer, permissions: [...viewer.permissions, 'finance.view'] });
        renderApp('/finance/expenses');
        await screen.findByRole('table');
        expect(screen.queryByRole('button', { name: 'Save expense' })).toBeNull();
        expect(screen.getByText('You can view expenses but not add them.')).toBeTruthy();
    });

    it('renders Bangla with Western digits', async () => {
        financeApi(accountant);
        renderApp('/finance/expenses', { locale: 'bn' });
        const table = await screen.findByRole('table');
        expect(within(table).getByText('ভাড়া')).toBeTruthy();
        expect(within(table).getByText((_, el) => el?.tagName === 'TD' && el.textContent === '৳35,000')).toBeTruthy();
    });
});

describe('ledger and COD receivables', () => {
    it('shows entries with their P&L / cash flags and the receivables card', async () => {
        financeApi(accountant);
        renderApp('/finance/ledger');
        const table = await screen.findByRole('table');
        expect(within(table).getByText('Sale revenue')).toBeTruthy();
        expect(within(table).getByText('COD received')).toBeTruthy();
        expect(within(table).getByText('Order #1042')).toBeTruthy();
        expect(within(table).getByText('P&L')).toBeTruthy();
        expect(within(table).getByText('Cash')).toBeTruthy();
        expect(await screen.findByText('COD receivables')).toBeTruthy();
        expect(screen.getByText('1 orders not fully remitted')).toBeTruthy();
    });

    it('records COD received with an Idempotency-Key', async () => {
        const calls = financeApi(accountant);
        renderApp('/finance/ledger');
        await screen.findByRole('table');

        fireEvent.click(screen.getByRole('button', { name: 'Record COD received' }));
        const dialog = await screen.findByRole('dialog', { hidden: true });
        fireEvent.change(within(dialog).getByLabelText(/^Order ID/), { target: { value: '1042' } });
        fireEvent.change(within(dialog).getByLabelText(/^Amount received/), { target: { value: '100' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Record receipt', hidden: true }));

        await waitFor(() => {
            const call = posted(calls, '/finance/orders/1042/cod-receipts');
            expect(call).toBeTruthy();
            const init = call![1] as RequestInit;
            expect((init.headers as Record<string, string>)['Idempotency-Key']).toBeTruthy();
            expect(JSON.parse(String(init.body))).toMatchObject({ amount: '100' });
        });
    });
});
