import { render } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { createQueryClient } from '@/lib/queryClient';
import { RouterProvider, createMemoryRouter } from 'react-router';
import { vi } from 'vitest';
import { routes } from '@/app/routes';
import type { Me } from '@/features/auth/api';
import { I18nProvider, type Locale } from '@/i18n/I18nProvider';

type Reply = { status: number; body?: unknown };
type Handler = (url: string, init?: RequestInit) => Reply;

/** Stub fetch with a URL → reply handler. Returns the mock so tests can count calls. */
export function mockApi(handler: Handler) {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === 'string' ? input : input.toString();
        const reply = handler(url, init);
        return new Response(reply.body === undefined ? null : JSON.stringify(reply.body), {
            status: reply.status,
            headers: { 'Content-Type': 'application/json' },
        });
    });
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

export function renderApp(path: string, { locale = 'en' }: { locale?: Locale } = {}) {
    const client = createQueryClient();
    client.setDefaultOptions({ queries: { ...client.getDefaultOptions().queries, retry: false } });
    const router = createMemoryRouter(routes, { initialEntries: [path] });

    const view = render(
        <QueryClientProvider client={client}>
            <I18nProvider initialLocale={locale}>
                <RouterProvider router={router} />
            </I18nProvider>
        </QueryClientProvider>,
    );

    return { ...view, client };
}

export const viewer: Me = {
    id: 7,
    name: 'Rina Akter',
    email: 'rina@example.com',
    locale: null,
    roles: ['viewer'],
    permissions: ['dashboard.view', 'orders.view', 'catalog.view'],
};
