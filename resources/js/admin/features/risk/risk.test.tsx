import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { renderApp } from '@/test/renderApp';
import { manager, operator, riskApi } from './fixtures';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const posts = (calls: ReturnType<typeof riskApi>) =>
    calls.mock.calls
        .filter(([, init]) => ['POST', 'PUT'].includes(String((init as RequestInit | undefined)?.method)))
        .map(([url, init]) => ({
            url: String(url),
            body: JSON.parse(String((init as RequestInit).body ?? 'null')) as unknown,
        }));

describe('verification queue', () => {
    it('shows the held order, why it is held, our breakdown and — separately — the courier panel as not connected', async () => {
        riskApi(operator);
        renderApp('/risk/queue');

        expect(await screen.findByText('TMB-26-00126')).toBeTruthy();
        const list = screen.getByRole('list', { name: 'Held orders' });
        expect(within(list).getByText('High risk score')).toBeTruthy();
        expect(within(list).getByText('First order above the threshold')).toBeTruthy();
        expect(within(list).getByText('High 80')).toBeTruthy();

        const courier = await screen.findByRole('region', {
            name: 'Courier-verified delivery record',
        });
        expect(within(courier).getByText('Provider not connected')).toBeTruthy();
        expect(within(courier).getByText(/Steadfast: needs a merchant account/)).toBeTruthy();
        expect(within(courier).getByText(/Pathao: publishes no official delivery-history API/)).toBeTruthy();
        // Never a zero for a courier we cannot ask (rules 24, 33b).
        expect(within(courier).queryByText('0')).toBeNull();
        // Our score is its own block with its own timestamp.
        const ours = screen.getByRole('region', {
            name: 'Toy Mart risk score',
        });
        expect(within(ours).getByText(/Computed by us from this phone’s order history/)).toBeTruthy();
        expect(within(ours).getByText('17 / 100')).toBeTruthy();
    });

    it('verifies and confirms, and logs a call — never cancels by itself', async () => {
        const calls = riskApi(operator);
        renderApp('/risk/queue');
        await screen.findByRole('heading', { name: /Call শাহরিয়ার কবির/ });

        fireEvent.change(screen.getByRole('combobox', { name: 'Call outcome' }), { target: { value: 'no_answer' } });
        fireEvent.click(screen.getByRole('button', { name: 'Log call' }));
        await waitFor(() => expect(posts(calls).some((p) => p.url.endsWith('/risk/orders/126/calls'))).toBe(true));
        expect(posts(calls).find((p) => p.url.endsWith('/calls'))?.body).toEqual({ outcome: 'no_answer', note: null });

        fireEvent.click(screen.getByRole('button', { name: 'Verify & confirm' }));
        await waitFor(() => expect(posts(calls).some((p) => p.url.endsWith('/risk/orders/126/verify'))).toBe(true));
        expect(posts(calls).some((p) => p.url.includes('/reject'))).toBe(false);
    });

    it('cancels only with a reason a person chose', async () => {
        const calls = riskApi(operator);
        renderApp('/risk/queue');
        fireEvent.click(await screen.findByRole('button', { name: 'Cancel order' }));

        const dialog = screen.getByRole('dialog');
        const submit = within(dialog).getByRole('button', {
            name: 'Cancel order',
        }) as HTMLButtonElement;
        expect(submit.disabled).toBe(true);
        fireEvent.change(within(dialog).getByRole('combobox', { name: /Reason/ }), { target: { value: 'fake_order' } });
        fireEvent.click(submit);
        await waitFor(() => expect(posts(calls).some((p) => p.url.endsWith('/reject'))).toBe(true));
        expect(posts(calls).find((p) => p.url.endsWith('/reject'))?.body).toEqual({ reason: 'fake_order', note: null });
    });
});

describe('phone lookup', () => {
    it('refuses an 011 number before asking the server (rule 30) and shows both panels for a valid one', async () => {
        const calls = riskApi(manager);
        renderApp('/risk/lookup');
        const input = await screen.findByRole('textbox', {
            name: 'Phone number',
        });

        fireEvent.change(input, { target: { value: '01112345678' } });
        fireEvent.click(screen.getByRole('button', { name: 'Look up' }));
        expect(await screen.findByRole('alert')).toBeTruthy();
        expect(calls.mock.calls.some(([u]) => String(u).includes('/risk/lookup'))).toBe(false);

        fireEvent.change(input, { target: { value: '01712 046318' } });
        fireEvent.click(screen.getByRole('button', { name: 'Look up' }));
        await screen.findByRole('region', { name: 'Toy Mart risk score' });
        expect(calls.mock.calls.some(([u]) => String(u).includes('/risk/lookup?phone=01712046318'))).toBe(true);
        expect(
            screen.getByRole('region', {
                name: 'Courier-verified delivery record',
            }),
        ).toBeTruthy();
        expect(screen.getByText('TMB-26-00126')).toBeTruthy();
        expect(screen.getByText('No list')).toBeTruthy();
    });

    it('renders in Bangla with Western digits', async () => {
        riskApi(manager);
        renderApp('/risk/lookup?phone=01712046318', { locale: 'bn' });
        expect(await screen.findByText('প্রোভাইডার সংযুক্ত নয়')).toBeTruthy();
        expect(screen.getByText('17 / 100')).toBeTruthy();
    });
});

describe('risk rules', () => {
    it('saves weights and thresholds as numbers; the server validates the total', async () => {
        const calls = riskApi(manager);
        renderApp('/risk/rules');
        const first = await screen.findByRole('spinbutton', {
            name: 'First-ever order',
        });
        await waitFor(() => expect((first as HTMLInputElement).value).toBe('10'));
        fireEvent.change(first, { target: { value: '5' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save rules' }));

        await waitFor(() => expect(posts(calls)).toHaveLength(1));
        expect(posts(calls)[0]).toMatchObject({
            url: '/api/v1/admin/risk/rules',
            body: {
                weights: { first_order: 5, cancellation_rate: 25 },
                high_threshold: 70,
                first_order_hold_threshold: '3000.00',
            },
        });
    });
});
