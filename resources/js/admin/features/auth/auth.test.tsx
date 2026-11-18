import { fireEvent, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup } from '@testing-library/react';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import { api } from '@/lib/api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

describe('auth guard', () => {
    it('sends a visitor without a session to the login page', async () => {
        mockApi(() => ({ status: 401, body: { type: 'https://toymartbd.com/problems/unauthenticated', title: 'Unauthenticated', status: 401 } }));

        renderApp('/orders');

        expect(await screen.findByRole('heading', { name: 'Welcome back' })).toBeTruthy();
    });

    it('signs in and lands on the page the user was going to', async () => {
        let signedIn = false;
        mockApi((url, init) => {
            if (url.endsWith('/sanctum/csrf-cookie')) return { status: 204 };
            if (url.endsWith('/auth/login') && init?.method === 'POST') {
                signedIn = true;
                return { status: 200, body: viewer };
            }
            if (url.endsWith('/auth/me')) return signedIn ? { status: 200, body: viewer } : { status: 401, body: { type: 'x', title: 'x', status: 401 } };
            return { status: 404 };
        });

        renderApp('/orders');

        fireEvent.change(await screen.findByLabelText('Email'), { target: { value: 'rina@example.com' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret-pass' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

        expect(await screen.findByRole('heading', { name: 'Orders' })).toBeTruthy();
    });

    it('shows one generic message for bad credentials', async () => {
        mockApi((url) => {
            if (url.endsWith('/auth/login')) {
                return {
                    status: 422,
                    body: { type: 'https://toymartbd.com/problems/validation-error', title: 'Validation failed', status: 422, errors: [{ field: 'email', message: 'These credentials do not match our records.' }] },
                };
            }
            if (url.endsWith('/auth/me')) return { status: 401, body: { type: 'x', title: 'x', status: 401 } };
            return { status: 204 };
        });

        renderApp('/login');
        fireEvent.change(await screen.findByLabelText('Email'), { target: { value: 'nobody@example.com' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'wrong' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

        expect((await screen.findByRole('alert')).textContent).toBe('Those details did not match an active staff account.');
        expect(screen.getByText('Check the email and password, then try again.')).toBeTruthy();
        expect(screen.getByLabelText('Email').getAttribute('aria-describedby')).toBe((await screen.findByRole('alert')).id);
    });

    it('shows and hides the password with a labelled toggle button', async () => {
        mockApi((url) => (url.endsWith('/auth/me') ? { status: 401, body: { type: 'x', title: 'x', status: 401 } } : { status: 204 }));
        renderApp('/login');

        const field = (await screen.findByLabelText('Password')) as HTMLInputElement;
        expect(field.type).toBe('password');
        const toggle = screen.getByRole('button', { name: 'Show password' });
        expect(toggle.getAttribute('aria-pressed')).toBe('false');

        fireEvent.click(toggle);
        expect(field.type).toBe('text');
        expect(screen.getByRole('button', { name: 'Hide password' }).getAttribute('aria-pressed')).toBe('true');

        fireEvent.click(screen.getByRole('button', { name: 'Hide password' }));
        expect(field.type).toBe('password');
    });

    it('warns while Caps Lock is on in the password field', async () => {
        mockApi((url) => (url.endsWith('/auth/me') ? { status: 401, body: { type: 'x', title: 'x', status: 401 } } : { status: 204 }));
        renderApp('/login');

        const field = await screen.findByLabelText('Password');
        expect(screen.queryByText('Caps Lock is on')).toBeNull();
        fireEvent.keyDown(field, { key: 'A', modifierCapsLock: true });
        const warning = await screen.findByText('Caps Lock is on');
        expect(field.getAttribute('aria-describedby')).toContain(warning.id);
        fireEvent.blur(field);
        expect(screen.queryByText('Caps Lock is on')).toBeNull();
    });
});

describe('session loss', () => {
    it('sends the user to login when any request answers 401', async () => {
        mockApi((url) => (url.endsWith('/auth/me') ? { status: 200, body: viewer } : { status: 401, body: { type: 'https://toymartbd.com/problems/unauthenticated', title: 'Unauthenticated', status: 401 } }));
        const { client } = renderApp('/');
        await screen.findByRole('heading', { name: 'Overview' });

        await client.fetchQuery({ queryKey: ['orders'], queryFn: () => api('/orders') }).catch(() => undefined);

        expect(await screen.findByRole('heading', { name: 'Welcome back' })).toBeTruthy();
    });
});

describe('failed queries', () => {
    it('surfaces a retryable error, and retry recovers', async () => {
        let calls = 0;
        const fetchMock = mockApi(() => {
            calls += 1;
            return calls === 1 ? { status: 500, body: { type: 'https://toymartbd.com/problems/server-error', title: 'Server error', status: 500 } } : { status: 200, body: viewer };
        });

        renderApp('/');

        fireEvent.click(await screen.findByRole('button', { name: 'Try again' }));

        expect(await screen.findByRole('heading', { name: 'Overview' })).toBeTruthy();
        // The session query ran twice (failed, then retried); the dashboard's own query is not counted.
        expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/auth/me'))).toHaveLength(2);
    });
});
