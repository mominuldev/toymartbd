import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

function body(init: RequestInit | undefined): unknown {
    return init?.body ? JSON.parse(String(init.body)) : undefined;
}

describe('my account', () => {
    it('renames without a password, but asks for one before changing the sign-in email', async () => {
        let me = { ...viewer };
        const calls = mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: me };
            if (url.endsWith('/auth/profile')) {
                const sent = body(init) as { name: string; email: string };
                me = { ...me, name: sent.name, email: sent.email };
                return { status: 200, body: me };
            }
            return { status: 404 };
        });
        renderApp('/account');

        const name = await screen.findByLabelText('Full name');
        expect(screen.queryByLabelText('Current password', { selector: 'input[autocomplete="current-password"]' })).toBeTruthy(); // the password card's
        fireEvent.change(name, { target: { value: 'Rina Begum' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save profile' }));
        await waitFor(() => expect(calls.mock.calls.some(([url]) => String(url).endsWith('/auth/profile'))).toBe(true));
        const first = calls.mock.calls.find(([url]) => String(url).endsWith('/auth/profile'));
        expect(body(first![1] as RequestInit)).toEqual({ name: 'Rina Begum', email: viewer.email });

        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'new@example.com' } });
        expect(await screen.findByText('Needed to change your sign-in email')).toBeTruthy();
    });

    it('checks the two new passwords match before sending', async () => {
        const calls = mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: viewer };
            if (url.endsWith('/auth/password')) return { status: 204 };
            return { status: 404 };
        });
        renderApp('/account');

        fireEvent.change(await screen.findByLabelText('New password'), { target: { value: 'a-long-new-secret' } });
        fireEvent.change(screen.getByLabelText('Confirm new password'), { target: { value: 'a-different-secret' } });
        const current = screen.getAllByLabelText('Current password').at(-1)!;
        fireEvent.change(current, { target: { value: 'old-secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Update password' }));
        expect(await screen.findByText('The two passwords don’t match.')).toBeTruthy();
        expect(calls.mock.calls.some(([url]) => String(url).endsWith('/auth/password'))).toBe(false);

        fireEvent.change(screen.getByLabelText('Confirm new password'), { target: { value: 'a-long-new-secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Update password' }));
        expect(await screen.findByText('Password updated')).toBeTruthy();
    });

    it('offers only the media library for the photo, opening the picker', async () => {
        mockApi((url) => {
            if (url.includes('/auth/me')) return { status: 200, body: viewer };
            if (url.includes('/media/folders')) return { status: 200, body: { data: [] } };
            if (url.includes('/media')) return { status: 200, body: { data: [], meta: { current_page: 1, last_page: 1, per_page: 24, total: 0 } } };
            return { status: 404 };
        });
        renderApp('/account');

        fireEvent.click(await screen.findByRole('button', { name: 'Choose from library' }));
        expect(screen.queryByRole('button', { name: /Upload photo|Change photo/ })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Remove' })).toBeNull();
        expect(await screen.findByRole('dialog', { name: 'Choose your profile photo' })).toBeTruthy();
    });
});
