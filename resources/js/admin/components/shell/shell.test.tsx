import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import { bn, en } from '@/i18n/messages';
import type { Me } from '@/features/auth/api';

afterEach(() => {
    cleanup();
    localStorage.clear();
    document.documentElement.removeAttribute('data-theme');
});

const signedInAs = (me: Me) => mockApi((url) => (url.endsWith('/auth/me') ? { status: 200, body: me } : { status: 404 }));

describe('app shell', () => {
    it('shows only the sections the user has permission for', async () => {
        signedInAs(viewer);
        renderApp('/');

        const nav = await screen.findAllByRole('navigation', { name: 'Main navigation' });
        const links = within(nav[0]!).getAllByRole('link').map((a) => a.textContent);

        expect(links).toEqual(['Overview', 'Orders', 'Products', 'Invoices']);
    });

    it('switches the whole UI to Bangla and sets <html lang>', async () => {
        signedInAs(viewer);
        renderApp('/');

        fireEvent.click((await screen.findAllByRole('button', { name: 'বাং' }))[0]!);

        expect(await screen.findByRole('heading', { name: 'সংক্ষিপ্ত চিত্র' })).toBeTruthy();
        expect(document.documentElement.lang).toBe('bn');
        expect(localStorage.getItem('tm-admin-locale')).toBe('bn');
    });

    it('toggles the admin theme and remembers it', async () => {
        document.documentElement.setAttribute('data-theme', 'admin-light');
        signedInAs(viewer);
        renderApp('/');

        fireEvent.click(await screen.findByRole('button', { name: 'Switch to dark theme' }));

        expect(document.documentElement.getAttribute('data-theme')).toBe('admin-dark');
        expect(localStorage.getItem('tm-admin-theme')).toBe('admin-dark');
    });

    it('opens the mobile drawer and closes it with Escape, returning focus', async () => {
        signedInAs(viewer);
        renderApp('/');

        const menu = await screen.findByRole('button', { name: 'Open menu' });
        fireEvent.click(menu);
        const dialog = await screen.findByRole('dialog');
        fireEvent.keyDown(dialog, { key: 'Escape' });

        expect(screen.queryByRole('dialog')).toBeNull();
        expect(document.activeElement).toBe(menu);
    });

    it('opens the Online store submenu inside the store and marks the current sub-page', async () => {
        signedInAs({ ...viewer, permissions: [...viewer.permissions, 'content.view'] });
        renderApp('/store/banners');

        const nav = (await screen.findAllByRole('navigation', { name: 'Main navigation' }))[0]!;
        const submenu = await within(nav).findByRole('list', { name: 'Online store' });

        expect(within(submenu).getAllByRole('link').map((a) => a.textContent)).toEqual([
            'Homepage',
            'Pages',
            'Navigation',
            'Blog',
            'Banners',
            'Testimonials',
            'Media library',
        ]);
        expect(within(submenu).getByRole('link', { name: 'Banners' }).getAttribute('aria-current')).toBe('page');
        expect(within(submenu).getByRole('link', { name: 'Pages' }).getAttribute('aria-current')).toBeNull();
    });

    it('keeps the Online store submenu closed outside the store', async () => {
        signedInAs({ ...viewer, permissions: [...viewer.permissions, 'content.view'] });
        renderApp('/');

        const nav = (await screen.findAllByRole('navigation', { name: 'Main navigation' }))[0]!;
        expect(within(nav).getByRole('link', { name: 'Online store' })).toBeTruthy();
        expect(within(nav).queryByRole('list', { name: 'Online store' })).toBeNull();
    });

    it('renders a placeholder for designed-but-unbuilt sections', async () => {
        signedInAs(viewer);
        renderApp('/inbox');

        expect(await screen.findByText('This section is being built.')).toBeTruthy();
    });
});

describe('messages', () => {
    it('has the same placeholders in Bangla as in English', () => {
        const vars = (s: string) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();
        for (const key of Object.keys(en) as (keyof typeof en)[]) {
            expect(vars(bn[key]), key).toEqual(vars(en[key]));
        }
    });

    it('uses no Bengali digits (rule 36)', () => {
        expect(Object.values(bn).join(' ')).not.toMatch(/[০-৯]/);
    });
});
