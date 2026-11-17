import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { renderApp } from '@/test/renderApp';
import { manager, operator, phoneRisk, riskApi } from './fixtures';

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
    it('says in one line why each order waits, then our record and — separately — the couriers’', async () => {
        riskApi(operator);
        renderApp('/risk/queue');

        expect(await screen.findByText('TMB-26-00126')).toBeTruthy();
        const list = screen.getByRole('list', { name: 'Held orders' });
        // The strongest reason, in words — not a factor code.
        expect(within(list).getByText('Cancelled 4 of their 7 past orders')).toBeTruthy();
        expect(within(list).getByText('High 80')).toBeTruthy();

        const panel = await screen.findByRole('region', { name: 'Chosen order' });
        const why = within(panel).getByRole('region', { name: 'Why this order is paused' });
        expect(within(why).getByText('+25')).toBeTruthy();
        expect(within(why).getByText('First order, and above the first-order limit — we always check these')).toBeTruthy();

        // Our record and the couriers' are separate blocks (rule 24); no courier answering is never a zero (rule 33b).
        expect(within(panel).getByRole('region', { name: 'From our orders' })).toBeTruthy();
        const courier = within(panel).getByRole('region', { name: 'What the couriers say' });
        expect(within(courier).getByText('Provider not connected')).toBeTruthy();
        expect(within(courier).getByText(/Steadfast: needs a merchant account/)).toBeTruthy();
        expect(within(courier).queryByText('0')).toBeNull();
    });

    it('confirms with one tap, logs other outcomes with one tap — never cancels by itself', async () => {
        const calls = riskApi(operator);
        renderApp('/risk/queue');
        await screen.findByRole('heading', { name: /Call শাহরিয়ার কবির/ });

        fireEvent.click(screen.getByRole('button', { name: 'No answer' }));
        await waitFor(() => expect(posts(calls).some((p) => p.url.endsWith('/risk/orders/126/calls'))).toBe(true));
        expect(posts(calls).find((p) => p.url.endsWith('/calls'))?.body).toEqual({ outcome: 'no_answer', note: null });

        fireEvent.change(screen.getByRole('textbox', { name: /Note for the call log/ }), { target: { value: 'Landmark checked' } });
        fireEvent.click(screen.getByRole('button', { name: 'Confirmed — send to courier' }));
        await waitFor(() => expect(posts(calls).some((p) => p.url.endsWith('/risk/orders/126/verify'))).toBe(true));
        expect(posts(calls).find((p) => p.url.endsWith('/verify'))?.body).toEqual({ note: 'Landmark checked' });
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
        await screen.findByRole('region', { name: 'Our score' });
        expect(calls.mock.calls.some(([u]) => String(u).includes('/risk/lookup?phone=01712046318'))).toBe(true);
        // A verdict from OUR level only; the couriers' record is its own block (rule 24).
        expect(screen.getByRole('heading', { name: 'Nothing worrying in our orders' })).toBeTruthy();
        expect(screen.getByRole('region', { name: 'What the couriers say' })).toBeTruthy();
        expect(screen.getByRole('region', { name: 'From our orders' })).toBeTruthy();
        expect(screen.getByText('TMB-26-00126')).toBeTruthy();
        expect(screen.getByText('No list')).toBeTruthy();
    });

    it('shows Steadfast’s rates once it answers, and lists Pathao by reason without a “nothing connected” banner', async () => {
        const [steadfast, pathao] = phoneRisk.courier.providers;
        const body = {
            ...phoneRisk,
            courier: {
                ...phoneRisk.courier,
                providers: [
                    {
                        ...steadfast,
                        connected: true,
                        reason: null,
                        total_fraud_reports: 4,
                        delivery_ratio: '0.1000',
                        cancellation_ratio: '0.9000',
                        volume: '10+',
                        fetched_at: '2026-11-17T05:06:32Z',
                    },
                    pathao,
                ],
            },
        };
        riskApi(manager, (url) => (url.includes('/risk/lookup') ? { status: 200, body } : undefined));
        renderApp('/risk/lookup?phone=01712046318');

        const courier = await screen.findByRole('region', { name: 'What the couriers say' });
        expect(await within(courier).findByText('10%')).toBeTruthy();
        expect(within(courier).getByText('90%')).toBeTruthy();
        expect(within(courier).getByText('10+')).toBeTruthy();
        expect(within(courier).getByText('90% were refused or cancelled')).toBeTruthy();
        expect(within(courier).getByText(/Pathao doesn’t share delivery history/)).toBeTruthy();
        expect(within(courier).queryByText('Provider not connected')).toBeNull();
        // Pathao cannot be fixed in settings, so no settings button for it.
        expect(within(courier).queryByRole('link', { name: 'Open courier settings' })).toBeNull();
    });

    it('renders in Bangla with Western digits', async () => {
        riskApi(manager);
        renderApp('/risk/lookup?phone=01712046318', { locale: 'bn' });
        expect(await screen.findByText('প্রোভাইডার সংযুক্ত নয়')).toBeTruthy();
        expect(screen.getByText('17')).toBeTruthy();
        expect(screen.getByText('আগের 3টি অর্ডারের 2টি বাতিল করেছেন')).toBeTruthy();
    });
});

describe('risk rules', () => {
    it('saves weights and thresholds as numbers; the server validates the total', async () => {
        const calls = riskApi(manager);
        renderApp('/risk/rules');
        const first = await screen.findByRole('textbox', {
            name: 'Points for First order',
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

    it('keeps each factor setting inside its sentence and shows the points used as you type', async () => {
        const calls = riskApi(manager);
        renderApp('/risk/rules');
        const minutes = await screen.findByRole('textbox', { name: 'Minutes between orders' });
        await waitFor(() => expect((minutes as HTMLInputElement).value).toBe('10'));
        expect(screen.getByText('95')).toBeTruthy();

        fireEvent.change(screen.getByRole('textbox', { name: 'Points for Thin address' }), { target: { value: '15' } });
        expect(screen.getByText('105')).toBeTruthy();
        expect(screen.getByText('Over 100 — lower some points before saving.')).toBeTruthy();

        fireEvent.change(minutes, { target: { value: '20' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save rules' }));
        await waitFor(() => expect(posts(calls)).toHaveLength(1));
        expect(posts(calls)[0]?.body).toMatchObject({ rapid_repeat_minutes: 20, value_norm_multiplier: 2, weights: { address_quality: 15 } });
    });
});
