import { act, cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
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

    it('collapses and expands the sidebar from the topbar, before the search, and remembers it', async () => {
        signedInAs(viewer);
        renderApp('/');

        const header = (await screen.findAllByRole('banner'))[0]!;
        const toggle = within(header).getByRole('button', { name: 'Collapse sidebar' });
        // It comes before the search field in the topbar's reading order.
        const search = within(header).getByRole('searchbox');
        expect(toggle.compareDocumentPosition(search) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(toggle.getAttribute('aria-pressed')).toBe('false');

        fireEvent.click(toggle);
        const expand = within(header).getByRole('button', { name: 'Expand sidebar' });
        expect(expand.getAttribute('aria-pressed')).toBe('true');
        expect(localStorage.getItem('tm-admin-sidebar-collapsed')).toBe('1');

        fireEvent.click(expand);
        expect(within(header).getByRole('button', { name: 'Collapse sidebar' })).toBeTruthy();
        expect(localStorage.getItem('tm-admin-sidebar-collapsed')).toBe('0');
        // One toggle only: the sidebar footer no longer carries its own.
        expect(screen.getAllByRole('button', { name: /^(Collapse|Expand) sidebar$/ })).toHaveLength(1);
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

    it('names the account menu by its content and returns focus to it on Escape (Phase 9 a11y)', async () => {
        signedInAs(viewer);
        renderApp('/');

        const button = await screen.findByRole('button', { name: new RegExp(`Account menu for ${viewer.name}`) });
        expect(button.getAttribute('aria-label')).toBeNull(); // WCAG 2.5.3: no aria-label hiding the visible name
        fireEvent.click(button);
        expect(await screen.findByRole('menu')).toBeTruthy();
        fireEvent.keyDown(document, { key: 'Escape' });

        expect(screen.queryByRole('menu')).toBeNull();
        expect(document.activeElement).toBe(button);
    });

    it('opens the Online store submenu inside the store and marks the current sub-page', async () => {
        signedInAs({ ...viewer, permissions: [...viewer.permissions, 'content.view'] });
        renderApp('/store/banners');

        const nav = (await screen.findAllByRole('navigation', { name: 'Main navigation' }))[0]!;
        const submenu = await within(nav).findByRole('list', { name: 'Online store' });

        expect(within(submenu).getAllByRole('link').map((a) => a.textContent)).toEqual([
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

    it('opens Online store on Pages, not the media library', async () => {
        signedInAs({ ...viewer, permissions: [...viewer.permissions, 'content.view'] });
        renderApp('/store');

        const nav = (await screen.findAllByRole('navigation', { name: 'Main navigation' }))[0]!;
        const submenu = await within(nav).findByRole('list', { name: 'Online store' });

        await waitFor(() => expect(within(submenu).getByRole('link', { name: 'Pages' }).getAttribute('aria-current')).toBe('page'));
        expect(within(submenu).getByRole('link', { name: 'Media library' }).getAttribute('aria-current')).toBeNull();
    });

    it('keeps the Online store submenu closed outside the store', async () => {
        signedInAs({ ...viewer, permissions: [...viewer.permissions, 'content.view'] });
        renderApp('/');

        const nav = (await screen.findAllByRole('navigation', { name: 'Main navigation' }))[0]!;
        expect(within(nav).getByRole('link', { name: 'Online store' })).toBeTruthy();
        expect(within(nav).queryByRole('list', { name: 'Online store' })).toBeNull();
    });

    it('in the collapsed rail, hovering Online store shows its sub-pages beside the icon', async () => {
        localStorage.setItem('tm-admin-sidebar-collapsed', '1');
        signedInAs({ ...viewer, permissions: [...viewer.permissions, 'content.view'] });
        renderApp('/');

        const nav = (await screen.findAllByRole('navigation', { name: 'Main navigation' }))[0]!;
        const parent = within(nav).getByRole('link', { name: 'Online store' });
        // Closed until hovered; the parent drops its tooltip because the panel names it.
        expect(within(nav).queryByRole('list', { name: 'Online store' })).toBeNull();
        expect(parent.getAttribute('title')).toBeNull();

        fireEvent.mouseEnter(parent.closest('li')!);
        const panel = await within(nav).findByRole('list', { name: 'Online store' });
        expect(within(panel).getAllByRole('link').map((a) => a.textContent)).toEqual(['Pages', 'Navigation', 'Blog', 'Banners', 'Testimonials', 'Media library']);

        fireEvent.mouseLeave(parent.closest('li')!);
        await waitFor(() => expect(within(nav).queryByRole('list', { name: 'Online store' })).toBeNull());
    });

    it('opens the rail sub-pages on keyboard focus, and Escape closes them back onto the icon', async () => {
        localStorage.setItem('tm-admin-sidebar-collapsed', '1');
        signedInAs({ ...viewer, permissions: [...viewer.permissions, 'content.view'] });
        renderApp('/');

        const nav = (await screen.findAllByRole('navigation', { name: 'Main navigation' }))[0]!;
        const parent = within(nav).getByRole('link', { name: 'Online store' });
        act(() => parent.focus());
        const panel = await within(nav).findByRole('list', { name: 'Online store' });
        act(() => within(panel).getByRole('link', { name: 'Blog' }).focus());
        fireEvent.keyDown(document.activeElement!, { key: 'Escape' });

        await waitFor(() => expect(within(nav).queryByRole('list', { name: 'Online store' })).toBeNull());
        expect(document.activeElement).toBe(parent);
    });

    it('gives every sidebar section its own screen — no section falls back to the placeholder', async () => {
        signedInAs(viewer);
        // Shipments & tracking (Figma B · 08) was the last one (Phase 10 batch 5).
        renderApp('/shipments');

        await waitFor(() => expect(screen.queryByText('This section is being built.')).toBeNull());
    });
});

describe('inbox nav badge (owner decision 2026-11-15)', () => {
    const inboxViewer: Me = { ...viewer, permissions: [...viewer.permissions, 'inbox.view'] };
    const withInbox = (me: Me, count: number) =>
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: me };
            if (url.endsWith('/inbox/unread-count')) return { status: 200, body: { count } };
            return { status: 404 };
        });
    const badgeCalls = (calls: ReturnType<typeof mockApi>) => calls.mock.calls.filter(([url]) => String(url).endsWith('/inbox/unread-count'));

    it('shows the server count of conversations needing attention, with a screen-reader label', async () => {
        withInbox(inboxViewer, 4);
        renderApp('/');

        const nav = (await screen.findAllByRole('navigation', { name: 'Main navigation' }))[0]!;
        await waitFor(() => expect(within(nav).getByRole('link', { name: /Inbox.*4 conversations need attention/ })).toBeTruthy());
    });

    it('shows no badge at zero', async () => {
        const calls = withInbox(inboxViewer, 0);
        renderApp('/');

        const nav = (await screen.findAllByRole('navigation', { name: 'Main navigation' }))[0]!;
        await waitFor(() => expect(badgeCalls(calls)).not.toHaveLength(0));
        expect(within(nav).getByRole('link', { name: 'Inbox' })).toBeTruthy();
    });

    it('never asks for the count without inbox.view', async () => {
        const calls = withInbox(viewer, 9);
        renderApp('/');

        await screen.findAllByRole('navigation', { name: 'Main navigation' });
        expect(badgeCalls(calls)).toHaveLength(0);
    });

    it('labels the badge in Bangla with Western digits', async () => {
        withInbox(inboxViewer, 12);
        renderApp('/');
        fireEvent.click((await screen.findAllByRole('button', { name: 'বাং' }))[0]!);

        const nav = (await screen.findAllByRole('navigation', { name: 'প্রধান নেভিগেশন' }))[0]!;
        await waitFor(() => expect(within(nav).getByRole('link', { name: /12টি কথোপকথনে মনোযোগ দরকার/ })).toBeTruthy());
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
