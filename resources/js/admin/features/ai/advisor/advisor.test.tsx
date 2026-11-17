import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { AdvisorAction, AdvisorAnswer, GrowthAdvisor } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const manager: Me = { ...viewer, roles: ['manager'], permissions: ['dashboard.view', 'reports.view', 'reports.export'] };

const restock: AdvisorAction = {
    id: 11,
    priority: 'high',
    kind: 'restock',
    title: 'Restock Kids Tricycle · Red',
    why: '12 left and 28 sold in 7 days. It runs out before new stock arrives.',
    figures: ['12 in stock', '28 sold in 7 days'],
    link: { type: 'product', path: '/products/4' },
    status: 'open',
    decided_at: null,
};
const verify: AdvisorAction = {
    ...restock,
    id: 12,
    priority: 'medium',
    kind: 'verify_orders',
    title: 'Check first orders before sending',
    why: '9 orders wait for a call.',
    figures: ['9 waiting'],
    link: { type: 'verification_queue', path: '/risk/queue' },
};

function advisor(over: Partial<GrowthAdvisor> = {}): GrowthAdvisor {
    return {
        ai: { configured: true, figures_shared: true, provider: 'anthropic', model: 'claude-opus-5-5' },
        brief: {
            id: 1,
            week_start: '2026-11-09',
            from: '2026-11-09',
            to: '2026-11-15',
            locale: 'en',
            provider: 'anthropic',
            model: 'claude-opus-5-5',
            sentences: ['Sales were ৳2,48,600 from 212 orders.', 'Kids Tricycle · Red sold 28 and has 12 left.'],
            generated_at: '2026-11-16T03:00:00+00:00',
            scheduled: true,
        },
        actions: [restock, verify],
        open_count: 2,
        done_this_month: 2,
        ...over,
    };
}

const answer: AdvisorAnswer = {
    answer_sentences: ['Tuesday had 19 orders against 31 the Tuesday before.'],
    sources: [
        { section: 'sales_by_day', detail: 'Tue' },
        { section: 'marketing', detail: '' },
    ],
    model: 'claude-opus-5-5',
    trimmed: false,
};

type Call = { url: string; method: string; body: unknown };

function api(body: GrowthAdvisor = advisor(), handlers: { ask?: () => { status: number; body: unknown } } = {}) {
    const calls: Call[] = [];
    const fetchMock = mockApi((url, init) => {
        const method = (init?.method ?? 'GET').toUpperCase();
        calls.push({ url, method, body: init?.body ? JSON.parse(String(init.body)) : undefined });
        if (url.endsWith('/auth/me')) return { status: 200, body: manager };
        if (url.endsWith('/analytics/advisor/ask')) return handlers.ask ? handlers.ask() : { status: 200, body: answer };
        if (url.includes('/analytics/advisor/actions/')) return { status: 200, body: { ...restock, status: 'done', decided_at: '2026-11-16T04:00:00+00:00' } };
        if (url.endsWith('/analytics/advisor/brief')) return { status: 200, body };
        if (url.endsWith('/analytics/advisor')) return { status: 200, body };
        if (url.includes('/inventory/low-stock-count')) return { status: 200, body: { location_id: 1, count: 0 } };
        return { status: 200, body: {} };
    });
    return { fetchMock, calls };
}

describe('Growth advisor', () => {
    it('renders the brief, its byline and the suggested actions with server-built links', async () => {
        api();
        renderApp('/analytics/advisor');

        expect(await screen.findByText('Sales were ৳2,48,600 from 212 orders.')).toBeTruthy();
        expect(screen.getByText(/this week in plain words · 9 Nov – 15 Nov/i)).toBeTruthy();
        expect(screen.getByText(/Written by Claude Opus 5\.5/)).toBeTruthy();
        expect(screen.getByText('2 open · 2 done this month')).toBeTruthy();

        const card = screen.getByRole('heading', { name: 'Restock Kids Tricycle · Red' }).closest('article')!;
        expect(within(card).getByText('High')).toBeTruthy();
        expect(within(card).getByText('12 in stock')).toBeTruthy();
        expect(within(card).getByRole('link', { name: 'Open product' })?.getAttribute('href')).toBe('/products/4');
        const other = screen.getByRole('heading', { name: 'Check first orders before sending' }).closest('article')!;
        expect(within(other).getByRole('link', { name: 'Open verification queue' })?.getAttribute('href')).toBe('/risk/queue');
        // The tab strip leads with the advisor.
        const tabs = screen.getAllByRole('link').filter((a) => a.getAttribute('href')?.startsWith('/analytics/'));
        expect(tabs[0]?.getAttribute('href')).toBe('/analytics/advisor');
    });

    it('marks a suggestion done with a POST and re-reads the screen', async () => {
        const { calls } = api();
        renderApp('/analytics/advisor');

        const card = (await screen.findByRole('heading', { name: 'Restock Kids Tricycle · Red' })).closest('article')!;
        fireEvent.click(within(card).getByRole('button', { name: 'Mark done' }));

        await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url.endsWith('/analytics/advisor/actions/11/done'))).toBe(true));
        expect(await screen.findByText('Marked done.')).toBeTruthy();
        await waitFor(() => expect(calls.filter((c) => c.method === 'GET' && c.url.endsWith('/analytics/advisor')).length).toBeGreaterThan(1));

        fireEvent.click(within(card).getByRole('button', { name: 'Not useful' }));
        await waitFor(() => expect(calls.some((c) => c.url.endsWith('/analytics/advisor/actions/11/dismiss'))).toBe(true));
    });

    it('asks a question and shows the answer with source chips', async () => {
        const { calls } = api();
        renderApp('/analytics/advisor');

        fireEvent.change(await screen.findByLabelText('Your question'), { target: { value: 'Why were Tuesday’s orders low?' } });
        fireEvent.click(screen.getByRole('button', { name: 'Send question' }));

        expect(await screen.findByText('Tuesday had 19 orders against 31 the Tuesday before.')).toBeTruthy();
        expect(screen.getByText('Why were Tuesday’s orders low?')).toBeTruthy();
        expect(screen.getByText('Sales · Tue')).toBeTruthy();
        expect(screen.getByText('Marketing')).toBeTruthy();
        expect(calls.find((c) => c.url.endsWith('/ask'))?.body).toEqual({ question: 'Why were Tuesday’s orders low?' });
        expect(screen.getByText(/Phone numbers and addresses are never sent/)).toBeTruthy();
    });

    it('sends a suggestion chip as the question and says when sentences were trimmed', async () => {
        const { calls } = api(advisor(), { ask: () => ({ status: 200, body: { ...answer, trimmed: true } }) });
        renderApp('/analytics/advisor');

        fireEvent.click(await screen.findByRole('button', { name: 'Which products should I restock first?' }));
        expect(await screen.findByText(/were left out because they used figures/)).toBeTruthy();
        expect(calls.find((c) => c.url.endsWith('/ask'))?.body).toEqual({ question: 'Which products should I restock first?' });
    });

    it('shows the AI notice instead of an answer when AI is over its cap', async () => {
        api(advisor(), { ask: () => ({ status: 409, body: { type: 'https://toymartbd.com/problems/ai-cap-reached', title: 'AI spending cap reached', status: 409 } }) });
        renderApp('/analytics/advisor');

        fireEvent.click(await screen.findByRole('button', { name: 'What should I promote this weekend?' }));
        expect(await screen.findByText('This month’s AI budget is used up')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Open AI settings' })?.getAttribute('href')).toBe('/integrations/ai');
    });

    it('explains how to set AI up when no provider is connected and there is no brief', async () => {
        api(advisor({ ai: { configured: false, figures_shared: true, provider: 'anthropic', model: 'claude-opus-5-5' }, brief: null, actions: [], open_count: 0, done_this_month: 0 }));
        renderApp('/analytics/advisor');

        expect(await screen.findByText('AI is not set up for this')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Refresh brief' })).toBeNull();
        expect(screen.queryByText('Ask AI')).toBeNull();
    });

    it('offers to write the first brief, then shows it', async () => {
        const empty = advisor({ brief: null, actions: [], open_count: 0, done_this_month: 0 });
        const { calls, fetchMock } = api(empty);
        renderApp('/analytics/advisor');

        const write = await screen.findByRole('button', { name: 'Write this week’s brief' });
        fetchMock.mockImplementation(async (input: RequestInfo | URL) => {
            const url = typeof input === 'string' ? input : input.toString();
            calls.push({ url, method: 'POST', body: undefined });
            const body = url.endsWith('/auth/me') ? manager : advisor();
            return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
        });
        fireEvent.click(write);

        expect(await screen.findByText('Sales were ৳2,48,600 from 212 orders.')).toBeTruthy();
        expect(calls.some((c) => c.url.endsWith('/analytics/advisor/brief'))).toBe(true);
    });

    it('asks for report figures to be allowed when sharing them is off', async () => {
        api(advisor({ ai: { configured: true, figures_shared: false, provider: 'anthropic', model: 'claude-opus-5-5' } }));
        renderApp('/analytics/advisor');

        expect(await screen.findByText('The advisor needs report figures')).toBeTruthy();
        expect(screen.queryByText('Ask AI')).toBeNull();
    });

    it('keeps Western digits in Bangla (rule 36)', async () => {
        api(advisor({ brief: { ...advisor().brief!, locale: 'bn', sentences: ['এ সপ্তাহে 212টি অর্ডার, মোট ৳2,48,600।'] } }));
        renderApp('/analytics/advisor', { locale: 'bn' });

        expect(await screen.findByText('এ সপ্তাহে 212টি অর্ডার, মোট ৳2,48,600।')).toBeTruthy();
        expect(screen.getByText('2টি বাকি · এ মাসে 2টি শেষ')).toBeTruthy();
        const title = screen.getByText(/এ সপ্তাহ সহজ কথায়/);
        expect(title.textContent).toMatch(/9.*15/);
        expect(document.body.textContent ?? '').not.toMatch(/[০-৯]/);
    });
});
