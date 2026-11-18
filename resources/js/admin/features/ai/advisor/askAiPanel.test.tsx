import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { AdvisorAnswer, GrowthAdvisor } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const manager: Me = { ...viewer, roles: ['manager'], permissions: ['dashboard.view', 'reports.view'] };

const overview: GrowthAdvisor = {
    ai: { configured: true, figures_shared: true, provider: 'anthropic', model: 'claude-opus-5-5' },
    brief: null,
    actions: [],
    open_count: 0,
    done_this_month: 0,
};

const answer: AdvisorAnswer = {
    answer_sentences: ['Gross order value was ৳1,21,202 from 46 orders.'],
    sources: [{ section: 'sales', detail: 'this week' }],
    model: 'claude-opus-5-5',
    trimmed: false,
};

type Call = { url: string; method: string; body: unknown };

function api({ me = manager, ai = overview.ai, ask }: { me?: Me; ai?: GrowthAdvisor['ai']; ask?: () => { status: number; body: unknown } } = {}) {
    const calls: Call[] = [];
    mockApi((url, init) => {
        const method = (init?.method ?? 'GET').toUpperCase();
        calls.push({ url, method, body: init?.body ? JSON.parse(String(init.body)) : undefined });
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/analytics/advisor/ask')) return ask ? ask() : { status: 200, body: answer };
        if (url.endsWith('/analytics/advisor')) return { status: 200, body: { ...overview, ai } };
        return { status: 200, body: {} };
    });
    return calls;
}

async function openPanel() {
    fireEvent.click(await screen.findByRole('button', { name: 'Ask' }));
    return screen.findByRole('region', { name: 'Ask Toy Mart AI' });
}

describe('Ask AI panel', () => {
    it('opens from the topbar, compacts it, and asks nothing of the API until opened', async () => {
        const calls = api();
        renderApp('/account');

        const button = await screen.findByRole('button', { name: 'Ask' });
        expect(button.getAttribute('aria-pressed')).toBe('false');
        expect(calls.some((c) => c.url.endsWith('/analytics/advisor'))).toBe(false);

        const panel = await openPanel();
        expect(button.getAttribute('aria-pressed')).toBe('true');
        expect(await within(panel).findByText('Claude')).toBeTruthy();
        // Compact topbar where the panel pushes the page (dock, 1720+): the Dhaka clock goes.
        expect(screen.getByText(/Dhaka/).closest('span')?.className).toMatch(/dock:hidden/);
    });

    it('sends with Enter, keeps Shift+Enter for a new line, and shows a sourced answer', async () => {
        const calls = api();
        renderApp('/account');
        const panel = await openPanel();

        const box = await within(panel).findByLabelText('Your question');
        fireEvent.change(box, { target: { value: 'How were sales this week?' } });
        fireEvent.keyDown(box, { key: 'Enter', shiftKey: true });
        expect(calls.some((c) => c.url.includes('/ask'))).toBe(false);

        fireEvent.keyDown(box, { key: 'Enter' });
        expect(await within(panel).findByText('Gross order value was ৳1,21,202 from 46 orders.')).toBeTruthy();
        expect(within(panel).getByText('How were sales this week?')).toBeTruthy();
        expect(within(panel).getByText('Sales · this week')).toBeTruthy();
        expect(within(panel).getByText('Claude Opus 5.5 · checked against your reports')).toBeTruthy();

        const ask = calls.find((c) => c.url.includes('/ask'))!;
        expect(ask.body).toEqual({ question: 'How were sales this week?' });
        expect(ask.url).toMatch(/\?locale=en$/);
    });

    it('answers in the chosen language and starts over with New', async () => {
        const calls = api();
        renderApp('/account');
        const panel = await openPanel();

        fireEvent.click(await within(panel).findByRole('button', { name: 'বাংলা' }));
        fireEvent.click(within(panel).getByRole('button', { name: 'What is low on stock?' }));
        expect(await within(panel).findByText('Gross order value was ৳1,21,202 from 46 orders.')).toBeTruthy();
        const ask = calls.find((c) => c.url.includes('/ask'))!;
        expect(ask.url).toMatch(/\?locale=bn$/);
        expect(ask.body).toEqual({ question: 'What is low on stock and selling?' });

        fireEvent.click(within(panel).getByRole('button', { name: 'New' }));
        expect(within(panel).queryByText('Gross order value was ৳1,21,202 from 46 orders.')).toBeNull();
    });

    it('shows the AI notice when the cap is reached, and closes with Escape back to the Ask button', async () => {
        api({ ask: () => ({ status: 409, body: { type: 'https://toymartbd.com/problems/ai-cap-reached', title: 'AI spending cap reached', status: 409 } }) });
        renderApp('/account');
        const button = await screen.findByRole('button', { name: 'Ask' });
        const panel = await openPanel();

        fireEvent.click(await within(panel).findByRole('button', { name: 'Today’s sales' }));
        expect(await within(panel).findByText('This month’s AI budget is used up')).toBeTruthy();

        fireEvent.keyDown(within(panel).getByLabelText('Your question'), { key: 'Escape' });
        await waitFor(() => expect(button.getAttribute('aria-pressed')).toBe('false'));
        expect(document.activeElement).toBe(button);
    });

    it('explains the setup instead of a question box when AI is not connected', async () => {
        api({ ai: { ...overview.ai, configured: false } });
        renderApp('/account');
        const panel = await openPanel();

        expect(await within(panel).findByText('AI is not set up for this')).toBeTruthy();
        expect(within(panel).queryByLabelText('Your question')).toBeNull();
    });

    it('is not offered to staff without reports.view', async () => {
        api({ me: { ...viewer, permissions: ['dashboard.view'] } });
        renderApp('/account');

        await screen.findByRole('button', { name: /notifications/i });
        expect(screen.queryByRole('button', { name: 'Ask' })).toBeNull();
    });
});
