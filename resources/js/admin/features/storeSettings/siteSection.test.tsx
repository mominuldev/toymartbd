import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import { brickBox, folders } from '@/features/media/fixtures';
import { imageProblem } from './SiteSection';
import type { SiteBranding } from './siteApi';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const owner: Me = { ...viewer, roles: ['super_admin'], permissions: ['dashboard.view', 'settings.view', 'settings.update'] };
const reader: Me = { ...viewer, roles: ['custom'], permissions: ['dashboard.view', 'settings.view'] };

const logo = { id: 7, url: 'https://cdn.test/logo/480.webp', width: 1200, height: 360, filename: 'toymart-logo.png' };

const saved: SiteBranding = {
    name: null,
    name_bn: null,
    tagline: 'Toys & learning for every age',
    tagline_bn: null,
    description: null,
    description_bn: null,
    logo,
    logo_dark: null,
    icon: null,
    social_image: null,
    fallback_names: { name: 'Toy Mart BD', name_bn: 'টয় মার্ট বিডি' },
    max_lengths: { name: 60, name_bn: 60, tagline: 80, tagline_bn: 80, description: 160, description_bn: 160 },
};

function siteApi(me: Me, onPut?: (body: Record<string, unknown>) => { status: number; body?: unknown }) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/settings/site') && init?.method === 'PUT') {
            const body = JSON.parse(String(init.body)) as Record<string, unknown>;
            return onPut?.(body) ?? { status: 200, body: { ...saved, name: body.name, tagline: body.tagline, icon: body.icon_media_id ? { id: brickBox.id, url: brickBox.urls.medium, width: 1600, height: 1600, filename: brickBox.filename } : null, logo: body.logo_media_id ? logo : null } };
        }
        if (url.endsWith('/settings/site')) return { status: 200, body: saved };
        if (url.endsWith('/media/folders')) return { status: 200, body: { data: folders } };
        if (url.endsWith('/media/counts')) return { status: 200, body: { data: { all: 1, unused: 0, unfiled: 0, folders: [], kinds: { image: 1, document: 0 }, missing_alt: { bn: 0, en: 0 } } } };
        if (url.includes('/media?') || url.endsWith('/media')) return { status: 200, body: { data: [brickBox], links: { next: null }, meta: { per_page: 30, next_cursor: null, prev_cursor: null, total: 1 } } };
        return { status: 404 };
    });
}

const putBody = (calls: ReturnType<typeof siteApi>) => {
    const put = calls.mock.calls.find(([url, init]) => String(url).endsWith('/settings/site') && (init as RequestInit | undefined)?.method === 'PUT');
    return JSON.parse(String((put?.[1] as RequestInit).body)) as Record<string, unknown>;
};

describe('store settings → site & branding', () => {
    it('is reachable from the section nav and previews what is typed, live', async () => {
        siteApi(owner);
        renderApp('/settings/site');

        const name = (await screen.findByLabelText('Store name')) as HTMLInputElement;
        expect(name.placeholder).toBe('Toy Mart BD');
        expect(screen.getByRole('link', { name: /Site & branding/ }).getAttribute('aria-current')).toBe('page');
        const preview = screen.getByRole('complementary', { name: 'Live preview' });
        // A blank name previews the fallback (business name); the tagline joins with the storefront's separator.
        expect(within(preview).getAllByText('Toy Mart BD | Toys & learning for every age').length).toBeGreaterThan(0);

        fireEvent.change(name, { target: { value: 'ToyMart' } });
        expect(within(preview).getAllByText('ToyMart | Toys & learning for every age').length).toBeGreaterThan(0);
        expect(screen.getByText('7 / 60')).toBeTruthy();
        expect(screen.getByText('Unsaved changes in Site & branding')).toBeTruthy();

        // The Bangla preview falls back to the English tagline until a Bangla one is typed.
        fireEvent.click(within(preview).getByRole('button', { name: 'বাংলা' }));
        expect(within(preview).getAllByText('টয় মার্ট বিডি | Toys & learning for every age').length).toBeGreaterThan(0);
    });

    it('saves the whole section with the generated-schema body; blank clears, a removed image sends null', async () => {
        const calls = siteApi(owner);
        renderApp('/settings/site');

        fireEvent.change(await screen.findByLabelText('Store name'), { target: { value: '  ToyMart  ' } });
        fireEvent.change(screen.getByLabelText('Tagline'), { target: { value: '   ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Remove Logo' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(screen.getByText('Site & branding saved — the storefront updates in a moment')).toBeTruthy());
        expect(putBody(calls)).toEqual({
            name: 'ToyMart',
            name_bn: null,
            tagline: null,
            tagline_bn: null,
            description: null,
            description_bn: null,
            logo_media_id: null,
            logo_dark_media_id: null,
            icon_media_id: null,
            social_image_media_id: null,
        });
    });

    it('picks the store icon from the media library and sends its id', async () => {
        const calls = siteApi(owner);
        renderApp('/settings/site');

        const tile = (await screen.findByRole('heading', { name: 'Store icon & favicon' })).closest('div')!.parentElement!;
        fireEvent.click(within(tile).getByRole('button', { name: 'Choose from library' }));
        const dialog = await screen.findByRole('dialog');
        fireEvent.click(await within(dialog).findByRole('button', { name: /^brick-box-front/ }));
        fireEvent.click(within(dialog).getByRole('button', { name: /^Insert/ }));
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(within(tile).getByText('brick-box-front.jpg · 1600 × 1600')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(putBody(calls).icon_media_id).toBe(brickBox.id));
    });

    it('opens the picker on its Upload tab for an empty image', async () => {
        siteApi(owner);
        renderApp('/settings/site');

        const tile = (await screen.findByRole('heading', { name: 'Social share image' })).closest('div')!.parentElement!;
        fireEvent.click(within(tile).getByRole('button', { name: /Upload an image/ }));
        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByRole('tab', { name: /Upload/ }).getAttribute('aria-selected')).toBe('true');
    });

    it('shows the server’s field errors', async () => {
        siteApi(owner, () => ({ status: 422, body: { type: 'validation', title: 'Invalid', status: 422, errors: [{ field: 'logo_media_id', message: 'The logo must be at least 120 px wide.' }] } }));
        renderApp('/settings/site');

        fireEvent.change(await screen.findByLabelText('Store name'), { target: { value: 'ToyMart' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(await screen.findByText('The logo must be at least 120 px wide.')).toBeTruthy();
    });

    it('is read-only without settings.update', async () => {
        siteApi(reader);
        renderApp('/settings/site');

        expect((await screen.findByLabelText('Store name')).matches(':disabled')).toBe(true);
        expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Remove Logo' })).toBeNull();
    });

    it('speaks Bangla, with Western digits in the counters', async () => {
        siteApi(owner);
        renderApp('/settings/site', { locale: 'bn' });

        expect(await screen.findByRole('heading', { name: 'সাইট ও ব্র্যান্ডিং' })).toBeTruthy();
        expect(screen.getByText('29 / 80')).toBeTruthy();
    });
});

describe('imageProblem (display only; the server decides)', () => {
    it('wants a square icon of at least 512, a ~1.91:1 share image and a 120-wide logo', () => {
        expect(imageProblem('icon', 512, 512)).toBeNull();
        expect(imageProblem('icon', 1024, 768)).toBe('siteSettings.image.iconShape');
        expect(imageProblem('icon', 256, 256)).toBe('siteSettings.image.iconShape');
        expect(imageProblem('social_image', 1200, 630)).toBeNull();
        expect(imageProblem('social_image', 1200, 1200)).toBe('siteSettings.image.socialShape');
        expect(imageProblem('logo', 100, 40)).toBe('siteSettings.image.logoSmall');
        expect(imageProblem('logo_dark', null, null)).toBe('siteSettings.image.notReady');
    });
});
