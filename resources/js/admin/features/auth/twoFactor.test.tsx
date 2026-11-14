import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from './api';
import type { TwoFactorStatus } from './twoFactorApi';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const accountant: Me = { ...viewer, roles: ['accountant'], permissions: ['dashboard.view', 'orders.view', 'finance.view'], two_factor: { enabled: false, required: true } };

function body(init: RequestInit | undefined): unknown {
    return init?.body ? JSON.parse(String(init.body)) : undefined;
}

describe('two-factor sign-in', () => {
    it('asks for the app code after the password, then lands where the user was going', async () => {
        let signedIn = false;
        const calls = mockApi((url, init) => {
            if (url.endsWith('/sanctum/csrf-cookie')) return { status: 204 };
            if (url.endsWith('/auth/login')) return { status: 202, body: { two_factor: true } };
            if (url.endsWith('/auth/two-factor-challenge')) {
                const sent = body(init) as { code?: string };
                if (sent.code !== '123456') return { status: 422, body: { type: 'https://toymartbd.com/problems/validation-error', title: 'Validation failed', status: 422, errors: [{ field: 'code', message: 'That code is not valid.' }] } };
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

        const code = await screen.findByLabelText('Code from the app');
        expect(screen.getByRole('heading', { name: 'Enter your sign-in code' })).toBeTruthy();
        expect(code.getAttribute('autocomplete')).toBe('one-time-code');

        fireEvent.change(code, { target: { value: '000 000' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));
        expect(await screen.findByText('That code is not valid.')).toBeTruthy();

        fireEvent.change(code, { target: { value: '123 456' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));
        expect(await screen.findByRole('heading', { name: 'Orders' })).toBeTruthy();
        const challenge = calls.mock.calls.filter(([url]) => String(url).endsWith('/auth/two-factor-challenge'));
        expect(body(challenge[1]![1] as RequestInit)).toEqual({ code: '123456' });
    });

    it('takes a recovery code instead, and can start again', async () => {
        const calls = mockApi((url) => {
            if (url.endsWith('/sanctum/csrf-cookie')) return { status: 204 };
            if (url.endsWith('/auth/login')) return { status: 202, body: { two_factor: true } };
            if (url.endsWith('/auth/two-factor-challenge')) return { status: 422, body: { type: 'x', title: 'x', status: 422, errors: [{ field: 'recovery_code', message: 'Used.' }] } };
            if (url.endsWith('/auth/me')) return { status: 401, body: { type: 'x', title: 'x', status: 401 } };
            return { status: 404 };
        });
        renderApp('/');

        fireEvent.change(await screen.findByLabelText('Email'), { target: { value: 'rina@example.com' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret-pass' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

        fireEvent.click(await screen.findByRole('button', { name: 'Use a recovery code' }));
        fireEvent.change(screen.getByLabelText('Recovery code'), { target: { value: 'abcde-fghij' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));
        await waitFor(() => expect(calls.mock.calls.some(([url, init]) => String(url).endsWith('/two-factor-challenge') && JSON.stringify(body(init as RequestInit)) === '{"recovery_code":"abcde-fghij"}')).toBe(true));

        fireEvent.click(screen.getByRole('button', { name: 'Start again' }));
        expect(await screen.findByRole('heading', { name: 'Sign in to Admin' })).toBeTruthy();
    });
});

describe('two-factor setup', () => {
    const notEnrolled: TwoFactorStatus = { enabled: false, required: true, confirmed_at: null, recovery_codes_left: 0 };

    function setupApi(me: Me, status: TwoFactorStatus) {
        let current = status;
        return mockApi((url, init) => {
            const method = init?.method ?? 'GET';
            if (url.endsWith('/auth/me')) return { status: 200, body: { ...me, two_factor: { enabled: current.enabled, required: current.required } } };
            if (url.endsWith('/auth/two-factor') && method === 'GET') return { status: 200, body: current };
            if (url.endsWith('/auth/two-factor') && method === 'POST') return { status: 200, body: { secret: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', otpauth_uri: 'otpauth://totp/x', qr_svg: '<svg xmlns="http://www.w3.org/2000/svg"/>' } };
            if (url.endsWith('/auth/two-factor/confirm')) {
                current = { ...current, enabled: true, recovery_codes_left: 8 };
                return { status: 200, body: { recovery_codes: ['aaaaa-bbbbb', 'ccccc-ddddd'] } };
            }
            return { status: 200, body: { data: [], meta: {} } };
        });
    }

    it('holds someone who must enrol on the setup page, wherever they go', async () => {
        setupApi(accountant, notEnrolled);
        renderApp('/orders');

        expect(await screen.findByRole('heading', { name: 'Two-factor sign-in' })).toBeTruthy();
        expect(screen.getByText(/Your role can see money/)).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Back to the admin' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Sign out' })).toBeTruthy();
    });

    it('sets up: QR and grouped key, a code, then the recovery codes once', async () => {
        const calls = setupApi(accountant, notEnrolled);
        renderApp('/account/two-factor');

        fireEvent.click(await screen.findByRole('button', { name: 'Set up' }));
        expect(await screen.findByRole('img', { name: 'QR code to add Toy Mart BD Admin to an authenticator app' })).toBeTruthy();
        expect(screen.getByText('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP')).toBeTruthy();

        fireEvent.change(screen.getByLabelText(/Code from the app/), { target: { value: '654 321' } });
        fireEvent.click(screen.getByRole('button', { name: 'Turn on' }));

        const list = await screen.findByRole('list', { name: 'Save your recovery codes' });
        expect(within(list).getAllByRole('listitem').map((li) => li.textContent)).toEqual(['aaaaa-bbbbb', 'ccccc-ddddd']);
        expect(screen.getByRole('link', { name: 'Download' }).getAttribute('download')).toBe('toymart-recovery-codes.txt');
        const confirm = calls.mock.calls.find(([url]) => String(url).endsWith('/auth/two-factor/confirm'))!;
        expect(body(confirm[1] as RequestInit)).toEqual({ code: '654321' });

        fireEvent.click(screen.getByRole('button', { name: 'I have saved them' }));
        await waitFor(() => expect(screen.queryByRole('list', { name: 'Save your recovery codes' })).toBeNull());
    });

    it('when on and required, offers new codes but no way to turn it off', async () => {
        setupApi(accountant, { enabled: true, required: true, confirmed_at: '2026-11-14T08:00:00+00:00', recovery_codes_left: 2 });
        renderApp('/account/two-factor');

        expect(await screen.findByText('On. Your role can see money, so it stays on.')).toBeTruthy();
        expect(screen.getByRole('img', { name: 'Running low: make new codes' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'New recovery codes' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Turn off' })).toBeNull();
    });

    it('when on and optional, turns off with a code', async () => {
        const calls = setupApi({ ...viewer, two_factor: { enabled: true, required: false } }, { enabled: true, required: false, confirmed_at: null, recovery_codes_left: 8 });
        renderApp('/account/two-factor');

        fireEvent.click(await screen.findByRole('button', { name: 'Turn off' }));
        fireEvent.change(screen.getByLabelText('Code from the app'), { target: { value: '111222' } });
        fireEvent.click(screen.getAllByRole('button', { name: 'Turn off' }).at(-1)!);

        await waitFor(() => expect(calls.mock.calls.some(([url, init]) => String(url).endsWith('/auth/two-factor') && (init as RequestInit | undefined)?.method === 'DELETE')).toBe(true));
    });

    it('is linked from the account menu', async () => {
        setupApi({ ...viewer, two_factor: { enabled: false, required: false } }, { enabled: false, required: false, confirmed_at: null, recovery_codes_left: 0 });
        renderApp('/');

        fireEvent.click(await screen.findByRole('button', { name: /Account menu for|Rina/ }));
        expect(screen.getByRole('menuitem', { name: 'Two-factor sign-in' }).getAttribute('href')).toBe('/account/two-factor');
    });
});
