import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { renderApp, viewer } from '@/test/renderApp';
import { formatFixed, formatMoney } from './parts';
import { accountant, ads, financeApi, manager, pendingRent, rent } from './fixtures';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const urls = (calls: ReturnType<typeof financeApi>) => calls.mock.calls.map(([url]) => String(url));
const posted = (calls: ReturnType<typeof financeApi>, part: string) => calls.mock.calls.find(([url, init]) => String(url).includes(part) && (init as RequestInit | undefined)?.method === 'POST');
const cellText = (value: string) => (_: string, el: Element | null) => el?.tagName === 'TD' && el.textContent === value;
const spanText = (value: string) => (_: string, el: Element | null) => el?.tagName === 'SPAN' && el.textContent === value;

describe('finance formatting', () => {
    it('groups and trims the server decimal string without computing', () => {
        expect(formatMoney('35000.00')).toBe('35,000');
        expect(formatMoney('1050.50')).toBe('1,050.5');
        expect(formatMoney('-25000.00')).toBe('−25,000');
    });

    it('sets Figma B · 32 money: two places, lakh grouping, a true minus', () => {
        expect(formatFixed('186400.00')).toBe('1,86,400.00');
        expect(formatFixed('1073620')).toBe('10,73,620.00');
        expect(formatFixed('950.5')).toBe('950.50');
        expect(formatFixed('-120.00')).toBe('−120.00');
        expect(formatFixed('-0.00')).toBe('0.00');
    });
});

describe('expenses', () => {
    it('shows the server-computed KPI cards and the expense rows with a status column', async () => {
        financeApi(accountant);
        renderApp('/finance/expenses');

        const table = await screen.findByRole('table');
        expect(within(table).getByRole('columnheader', { name: 'Status' })).toBeTruthy();
        expect(within(table).getByText('Rent')).toBeTruthy();
        expect(within(table).getByText('Operating')).toBeTruthy();
        expect(within(table).getByText('Waiting for approval')).toBeTruthy();
        expect(within(table).getByText('Repeats monthly')).toBeTruthy();
        expect(within(table).getByText('Paid 01 Nov')).toBeTruthy();
        expect(within(table).getByText(cellText('৳35,000.00'))).toBeTruthy();
        const receipt = within(table).getByRole('link', { name: /Open receipt rent-nov.pdf/ });
        expect(receipt.getAttribute('href')).toBe('/api/v1/admin/finance/expenses/41/receipt');
        expect(receipt.textContent).toBe('rent-nov.pdf');
        expect(await screen.findByText('10.1%')).toBeTruthy();
        expect(screen.getAllByText(spanText('৳68,400.00')).length).toBeGreaterThan(0);
        expect(screen.getByText('Showing 1 expenses · newest first')).toBeTruthy();
    });

    it('keeps every row action, Approve included, behind the row menu', async () => {
        financeApi(manager);
        renderApp('/finance/expenses');
        const table = await screen.findByRole('table');

        expect(within(table).getAllByTitle('Actions').every((el) => el.tagName === 'SUMMARY')).toBe(true);
        expect(within(table).getByRole('button', { name: 'Approve' }).closest('details')).toBeTruthy();
        expect(within(table).getByRole('button', { name: 'Reject' }).closest('details')).toBeTruthy();
    });

    it('labels an approved expense with no payment as “Approved · unpaid” and “Not paid yet”', async () => {
        financeApi(accountant, (url) => (url.includes('/finance/expenses?') || url.endsWith('/finance/expenses') ? { status: 200, body: { data: [{ ...pendingRent, status: 'approved', payment_method: null, paid_on: null }], links: {}, meta: { next_cursor: null } } } : undefined));
        renderApp('/finance/expenses');
        const table = await screen.findByRole('table');
        expect(within(table).getByText('Approved · unpaid')).toBeTruthy();
        expect(within(table).getByText('Not paid yet')).toBeTruthy();
    });

    it('filters on the server with the filter in the URL', async () => {
        const calls = financeApi(accountant);
        renderApp('/finance/expenses');
        await screen.findByRole('table');

        fireEvent.change(screen.getByRole('combobox', { name: 'Status' }), { target: { value: 'pending' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Bstatus%5D=pending'))).toBe(true));
    });

    it('opens what waits for approval from the “to date” card', async () => {
        const calls = financeApi(accountant);
        renderApp('/finance/expenses');
        await screen.findByRole('table');

        const card = screen.getByRole('button', { name: /1 waiting for approval/ });
        expect(card.getAttribute('aria-pressed')).toBe('false');
        fireEvent.click(card);
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Bstatus%5D=pending'))).toBe(true));
        expect(screen.getByRole('button', { name: /1 waiting for approval/ }).getAttribute('aria-pressed')).toBe('true');
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
        const dialog = await screen.findByRole('dialog', { name: 'Reject this expense?', hidden: true });
        fireEvent.change(within(dialog).getByLabelText(/Reason/), { target: { value: 'Wrong amount' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Reject', hidden: true }));
        await waitFor(() => {
            const call = posted(calls, '/reject');
            expect(call).toBeTruthy();
            expect(JSON.parse(String((call![1] as RequestInit).body))).toEqual({ reason: 'Wrong amount' });
        });
    });

    it('adds an expense from the drawer as multipart, with the reference, never computing money in the browser', async () => {
        const calls = financeApi(accountant);
        renderApp('/finance/expenses');
        await screen.findByRole('table');

        fireEvent.click(screen.getByRole('button', { name: 'Add expense' }));
        const drawer = await screen.findByRole('dialog', { name: 'Add expense', hidden: true });
        expect(within(drawer).getByText('Approved and paid are two different steps')).toBeTruthy();
        expect(within(drawer).getByRole('option', { name: 'Facebook ads', hidden: true })).toBeTruthy();
        fireEvent.change(within(drawer).getByLabelText(/^Amount/), { target: { value: '1,500' } });
        fireEvent.change(within(drawer).getByLabelText(/^Category/), { target: { value: String(ads.id) } });
        fireEvent.change(within(drawer).getByLabelText(/^What was it for\?/), { target: { value: 'ফেসবুক বুস্ট — শীতের পাজল' } });
        fireEvent.change(within(drawer).getByLabelText(/^Reference/), { target: { value: 'FBADS-88213' } });
        fireEvent.click(within(drawer).getByRole('button', { name: 'Save expense', hidden: true }));

        await waitFor(() => {
            const call = calls.mock.calls.find(([url, init]) => String(url).endsWith('/finance/expenses') && (init as RequestInit | undefined)?.method === 'POST');
            expect(call).toBeTruthy();
            const body = (call![1] as RequestInit).body as FormData;
            expect(body.get('amount')).toBe('1500');
            expect(body.get('category_id')).toBe('2');
            expect(body.get('reference')).toBe('FBADS-88213');
            expect(body.get('description')).toBe('ফেসবুক বুস্ট — শীতের পাজল');
            expect(body.get('is_recurring')).toBe('0');
        });
    });

    it('edits an expense in the same drawer and saves it with PATCH', async () => {
        const calls = financeApi(accountant, (url, init) => (url.endsWith('/finance/expenses/41') && init?.method === 'PATCH' ? { status: 200, body: pendingRent } : undefined));
        renderApp('/finance/expenses');
        const table = await screen.findByRole('table');

        fireEvent.click(within(table).getByRole('button', { name: 'Edit' }));
        const drawer = await screen.findByRole('dialog', { name: 'Edit expense', hidden: true });
        expect((within(drawer).getByLabelText(/^Amount/) as HTMLInputElement).value).toBe('35000.00');
        expect((within(drawer).getByLabelText(/^Reference/) as HTMLInputElement).value).toBe('RENT-NOV');
        expect(within(drawer).getByText('rent-nov.pdf')).toBeTruthy();
        fireEvent.click(within(drawer).getByRole('button', { name: 'Save changes', hidden: true }));

        await waitFor(() => {
            const call = calls.mock.calls.find(([url, init]) => String(url).endsWith('/finance/expenses/41') && (init as RequestInit | undefined)?.method === 'PATCH');
            expect(call).toBeTruthy();
            expect(JSON.parse(String((call![1] as RequestInit).body))).toMatchObject({ amount: '35000.00', category_id: rent.id, reference: 'RENT-NOV' });
        });
    });

    it('hides Add expense and row actions from a viewer without finance rights', async () => {
        financeApi({ ...viewer, permissions: [...viewer.permissions, 'finance.view'] });
        renderApp('/finance/expenses');
        await screen.findByRole('table');
        expect(screen.queryByRole('button', { name: 'Add expense' })).toBeNull();
        expect(screen.getByText('You can view expenses but not add them.')).toBeTruthy();
    });

    it('renders Bangla with Western digits', async () => {
        financeApi(accountant);
        renderApp('/finance/expenses', { locale: 'bn' });
        const table = await screen.findByRole('table');
        expect(within(table).getByText('ভাড়া')).toBeTruthy();
        expect(within(table).getByText('অনুমোদনের অপেক্ষায়')).toBeTruthy();
        expect(within(table).getByText(cellText('৳35,000.00'))).toBeTruthy();
    });
});

describe('expense categories', () => {
    it('shows the server’s counts, the Fixed tag and filters the short list in place', async () => {
        financeApi(accountant, (url) =>
            url.includes('/finance/expense-categories')
                ? { status: 200, body: { data: [rent, { ...ads, is_active: false, expenses_count: 2 }], meta: { category_count: 2, active_count: 1, expense_count: 141 } } }
                : undefined,
        );
        renderApp('/finance/expenses/categories');
        const table = await screen.findByRole('table');
        expect(screen.getByText('2 categories · 141 expenses filed')).toBeTruthy();
        expect(screen.getByText('Why categories matter')).toBeTruthy();
        expect(within(table).getByText('ভাড়া')).toBeTruthy();
        expect(within(table).getByText('Fixed')).toBeTruthy();
        expect(within(table).getByText('Switched off')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Active' }));
        expect(within(screen.getByRole('table')).queryByText('Facebook ads')).toBeNull();

        fireEvent.change(screen.getByRole('searchbox', { name: 'Search categories' }), { target: { value: 'zzz' } });
        expect(await screen.findByText('No category matches')).toBeTruthy();
    });

    it('switches a category off from its row menu (never deletes it)', async () => {
        const calls = financeApi(accountant, (url, init) => (url.endsWith('/finance/expense-categories/1') && init?.method === 'DELETE' ? { status: 200, body: { ...rent, is_active: false } } : undefined));
        renderApp('/finance/expenses/categories');
        const table = await screen.findByRole('table');

        fireEvent.click(within(table).getAllByRole('button', { name: 'Switch off' })[0]!);
        await waitFor(() => expect(calls.mock.calls.some(([url, init]) => String(url).endsWith('/finance/expense-categories/1') && (init as RequestInit | undefined)?.method === 'DELETE')).toBe(true));
    });
});

describe('ledger and COD receivables', () => {
    it('leads with what couriers owe, then rows with what happened, the order number and where each counts', async () => {
        financeApi(accountant);
        renderApp('/finance/ledger');
        const table = await screen.findByRole('table');
        expect(within(table).getByText('Sale revenue')).toBeTruthy();
        expect(within(table).getByText('COD received')).toBeTruthy();
        expect(within(table).getByRole('link', { name: 'Order #TMB-26-01042' }).getAttribute('href')).toBe('/orders/1042');
        expect(within(table).getByText('COD receipt #3')).toBeTruthy();
        expect(within(table).getByText('Profit')).toBeTruthy();
        expect(within(table).getByText('Cash')).toBeTruthy();
        expect(within(table).getAllByText(spanText('৳1,200.00')).length).toBeGreaterThan(0);
        expect(screen.getByRole('heading', { name: 'Money still with couriers' })).toBeTruthy();
        expect(screen.getByText('1 delivered COD orders not paid yet')).toBeTruthy();
        expect(screen.getByText('Over 30 days')).toBeTruthy();
    });

    it('sends the kind filter to the server', async () => {
        const calls = financeApi(accountant);
        renderApp('/finance/ledger');
        await screen.findByRole('table');
        fireEvent.change(screen.getByRole('combobox', { name: 'Kind' }), { target: { value: 'cash' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Bcash%5D=true'))).toBe(true));
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
