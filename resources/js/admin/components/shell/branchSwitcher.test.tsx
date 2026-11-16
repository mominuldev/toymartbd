import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { afterEach, describe, expect, it } from 'vitest';
import { I18nProvider } from '@/i18n/I18nProvider';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import { BranchSwitcher } from './BranchSwitcher';
import type { WorkingBranchLocation } from './workingBranch';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const dhaka: WorkingBranchLocation = { id: 1, name: 'Dhaka HQ', name_bn: 'ঢাকা এইচকিউ', area: 'Mirpur', type: 'warehouse', is_default: true };
const ctg: WorkingBranchLocation = { id: 2, name: 'Chattogram', name_bn: null, area: 'Agrabad', type: 'retail', is_default: false };
const syl: WorkingBranchLocation = { id: 3, name: 'Sylhet pickup point', name_bn: null, area: 'Zindabazar', type: 'pickup', is_default: false };

const owner: Me = {
    ...viewer,
    permissions: [...viewer.permissions, 'settings.view'],
    branches: { multi_branch: true, locations: [dhaka, ctg, syl] },
};

const signedInAs = (me: Me) => mockApi((url) => (url.endsWith('/auth/me') ? { status: 200, body: me } : { status: 404 }));

/** The expanded sidebar (the first of the rail and the drawer copies). */
async function sidebarButton() {
    return (await screen.findAllByRole('button', { name: /^Working branch:/ }))[0]!;
}

describe('sidebar branch switcher', () => {
    it('shows the working branch, opens the menu and remembers a new choice on this device', async () => {
        signedInAs(owner);
        renderApp('/');

        const button = await sidebarButton();
        expect(button.textContent).toContain('Working branch: Dhaka HQ');
        expect(within(button).getByText('Mirpur · 3 branches')).toBeTruthy();
        expect(button.getAttribute('aria-haspopup')).toBe('menu');
        expect(button.getAttribute('aria-expanded')).toBe('false');

        fireEvent.click(button);
        const menu = screen.getByRole('menu', { name: 'Working branch' });
        expect(button.getAttribute('aria-expanded')).toBe('true');
        const items = within(menu).getAllByRole('menuitemradio');
        expect(items.map((i) => i.getAttribute('aria-checked'))).toEqual(['true', 'false', 'false']);
        expect(within(items[1]!).getByText('Agrabad · Retail branch')).toBeTruthy();
        expect(within(items[2]!).getByText('Zindabazar · Pickup only')).toBeTruthy();
        // The chosen branch takes focus first.
        expect(document.activeElement).toBe(items[0]);
        expect(within(menu).getByRole('menuitem', { name: 'Manage branches' }).getAttribute('href')).toBe('/settings/branches');
        expect(within(menu).getByText(/The default branch for POS, purchases/)).toBeTruthy();

        fireEvent.click(items[1]!);
        expect(screen.queryByRole('menu')).toBeNull();
        expect(localStorage.getItem('tm-admin-working-branch')).toBe('2');
        await waitFor(async () => expect((await sidebarButton()).textContent).toContain('Working branch: Chattogram'));
        expect(document.activeElement).toBe(await sidebarButton());
    });

    it('moves with the arrow keys, closes on Escape and returns focus', async () => {
        signedInAs(owner);
        renderApp('/');

        const button = await sidebarButton();
        fireEvent.keyDown(button, { key: 'ArrowDown' });
        const menu = screen.getByRole('menu', { name: 'Working branch' });
        const radios = within(menu).getAllByRole('menuitemradio');
        expect(document.activeElement).toBe(radios[0]);
        fireEvent.keyDown(menu, { key: 'ArrowDown' });
        expect(document.activeElement).toBe(radios[1]);
        fireEvent.keyDown(menu, { key: 'End' });
        expect(document.activeElement).toBe(within(menu).getByRole('menuitem', { name: 'Manage branches' }));
        fireEvent.keyDown(menu, { key: 'ArrowDown' });
        expect(document.activeElement).toBe(radios[0]);
        fireEvent.keyDown(menu, { key: 'Escape' });
        expect(screen.queryByRole('menu')).toBeNull();
        expect(document.activeElement).toBe(button);

        // A click outside closes it too.
        fireEvent.click(button);
        expect(screen.getByRole('menu')).toBeTruthy();
        fireEvent.mouseDown(document.body);
        expect(screen.queryByRole('menu')).toBeNull();
    });

    it('ignores a stored branch that is no longer active and hides Manage branches without settings.view', async () => {
        localStorage.setItem('tm-admin-working-branch', '42');
        signedInAs({ ...owner, permissions: viewer.permissions });
        renderApp('/');

        const button = await sidebarButton();
        expect(button.textContent).toContain('Working branch: Dhaka HQ');
        fireEvent.click(button);
        expect(screen.queryByRole('menuitem', { name: 'Manage branches' })).toBeNull();
    });

    it('shows no branch block at all for a single shop — the nav follows the brand row', async () => {
        signedInAs({ ...owner, branches: { multi_branch: false, locations: [dhaka] } });
        renderApp('/');

        await screen.findAllByRole('navigation', { name: 'Main navigation' });
        expect(screen.queryByRole('button', { name: /^Working branch:/ })).toBeNull();
        expect(screen.queryByText('Dhaka HQ')).toBeNull();
        expect(screen.queryByText(/Mirpur/)).toBeNull();
    });

    it('speaks Bangla names and labels', async () => {
        signedInAs(owner);
        renderApp('/', { locale: 'bn' });

        const button = (await screen.findAllByRole('button', { name: /^কাজের শাখা:/ }))[0]!;
        expect(button.textContent).toContain('কাজের শাখা: ঢাকা এইচকিউ');
        expect(within(button).getByText('Mirpur · 3টি শাখা')).toBeTruthy();
    });

    it('in the collapsed rail is an icon button named by the branch, its menu opening beside the rail', () => {
        const router = createMemoryRouter([
            { path: '/', element: <BranchSwitcher multi locations={[dhaka, ctg]} current={ctg} onSelect={() => undefined} manageHref={null} mode="never" /> },
        ]);
        render(
            <I18nProvider initialLocale="en">
                <RouterProvider router={router} />
            </I18nProvider>,
        );

        const button = screen.getByRole('button', { name: /^Working branch:\s*Chattogram/ });
        expect(button.getAttribute('title')).toBe('Chattogram');
        // The name and caret are display:none in the rail (the label carries the name).
        expect(button.className).toContain('size-10');
        fireEvent.click(button);
        expect(screen.getByRole('menu', { name: 'Working branch' })).toBeTruthy();
        expect(screen.getAllByRole('menuitemradio')).toHaveLength(2);
    });
});
