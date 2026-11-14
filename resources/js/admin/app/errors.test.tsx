import { render, screen } from '@testing-library/react';
import { RouterProvider, createMemoryRouter } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { I18nProvider, type Locale } from '@/i18n/I18nProvider';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import { RouteError } from './RouteError';

// P10 launch checklist "404 and 500 pages styled" — the admin SPA's not-found screen and its
// router error boundary, in both locales.

function signedIn() {
    return mockApi((url) => (url.endsWith('/auth/me') ? { status: 200, body: viewer } : { status: 404 }));
}

describe('admin not-found screen', () => {
    it('renders inside the shell with a heading and a way back, in English', async () => {
        signedIn();
        renderApp('/no-such-screen');

        expect(await screen.findByRole('heading', { level: 1, name: 'Page not found' })).toBeTruthy();
        expect(screen.getByText('That admin page does not exist.')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Back to dashboard' }).getAttribute('href')).toBe('/');
        // The shell (sidebar navigation) stays, so staff are never stranded.
        expect(screen.getAllByRole('navigation').length).toBeGreaterThan(0);
    });

    it('renders in Bangla', async () => {
        signedIn();
        renderApp('/x-unknown', { locale: 'bn' });

        expect(await screen.findByRole('heading', { level: 1, name: 'পৃষ্ঠা পাওয়া যায়নি' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'ড্যাশবোর্ডে ফিরুন' })).toBeTruthy();
    });
});

function Boom(): never {
    throw new Error('render crash with secret-token-123');
}

function renderCrash(locale: Locale) {
    const router = createMemoryRouter([{ path: '/', element: <Boom />, errorElement: <RouteError /> }], { initialEntries: ['/'] });
    return render(
        <I18nProvider initialLocale={locale}>
            <RouterProvider router={router} />
        </I18nProvider>,
    );
}

describe('admin router error boundary', () => {
    it('shows a recoverable message instead of a blank page, never the error text', () => {
        vi.spyOn(console, 'error').mockImplementation(() => undefined);
        renderCrash('en');

        expect(screen.getByRole('alert')).toBeTruthy();
        expect(screen.getByText('Something went wrong')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Try again' })).toBeTruthy();
        expect(document.body.textContent).not.toContain('secret-token-123');
    });

    it('is translated', () => {
        vi.spyOn(console, 'error').mockImplementation(() => undefined);
        renderCrash('bn');

        expect(screen.getByText('কিছু একটা সমস্যা হয়েছে')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'আবার চেষ্টা করুন' })).toBeTruthy();
    });

    it('is wired as the errorElement of the signed-in tree and the login route', async () => {
        const { routes } = await import('./routes');
        expect(routes.every((r) => r.errorElement !== undefined)).toBe(true);
    });
});
