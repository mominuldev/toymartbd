import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { updateStoreBrandingRequestSchema } from '@toymart/api-types/zod';
import { renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import { brickBox, mediaApi } from '@/features/media/fixtures';
import type { StoreBranding } from './brandingApi';
import { charCount } from './brandingParts';
import { tabTitle } from './BrandingPreview';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    localStorage.clear();
});

const owner: Me = { ...viewer, roles: ['super_admin'], permissions: ['dashboard.view', 'settings.view', 'settings.update', 'media.view', 'media.upload'] };
const readOnly: Me = { ...viewer, roles: ['viewer'], permissions: ['dashboard.view', 'settings.view'] };

const logo = { id: 7, filename: 'toymart-logo.png', width: 1200, height: 360, url: '/storage/media/7/320.webp' };
const icon = { id: 8, filename: 'toymart-icon.png', width: 1024, height: 1024, url: '/storage/media/8/320.webp' };

/** Mirrors the StoreBranding contract (brief-p10-branding.md), Bangla and English values. */
const branding: StoreBranding = {
    values: {
        site_name_en: 'Smart Junior',
        site_name_bn: null,
        tagline_en: 'Toys & learning for every age',
        tagline_bn: 'সব বয়সের খেলনা ও শেখা',
        description_en: null,
        description_bn: null,
        logo_media_id: 7,
        logo_dark_media_id: null,
        icon_media_id: 8,
        share_image_media_id: null,
    },
    media: { logo, logo_dark: null, icon, share_image: null },
    fallbacks: { site_name_en: 'Smart Junior', site_name_bn: 'Smart Junior' },
    storefront_host: 'toymartbd.com',
    limits: { site_name: 60, tagline: 80, description: 160 },
};

type Reply = { status: number; body?: unknown };

function brandingApi(me: Me, put?: (body: Record<string, unknown>) => Reply) {
    return mediaApi(me, (url, init) => {
        const path = new URL(url, 'http://x').pathname.replace('/api/v1/admin', '');
        if (path === '/settings/branding' && init?.method === 'PUT') {
            const body = JSON.parse(String(init.body)) as Record<string, unknown>;
            if (put) return put(body);
            const values = { ...branding.values, ...body } as StoreBranding['values'];
            return { status: 200, body: { ...branding, values, media: { ...branding.media, logo: values.logo_media_id === null ? null : branding.media.logo, logo_dark: values.logo_dark_media_id === 42 ? { id: 42, filename: brickBox.filename, width: 1600, height: 1600, url: brickBox.urls.medium } : null } } };
        }
        if (path === '/settings/branding') return { status: 200, body: branding };
        if (path === '/settings/business') return { status: 200, body: { name_en: 'Smart Junior', name_bn: null, trade_licence: null, bin: null, hotline: null, whatsapp: null, messenger: null, default_names: { name_en: 'Smart Junior', name_bn: 'Smart Junior' }, hotline_default: null, placeholders: [] } };
        return undefined;
    });
}

function putBody(calls: ReturnType<typeof brandingApi>): Record<string, unknown> {
    const call = calls.mock.calls.find(([url, init]) => String(url).endsWith('/settings/branding') && (init as RequestInit | undefined)?.method === 'PUT');
    return JSON.parse(String((call![1] as RequestInit).body)) as Record<string, unknown>;
}

const bar = () => screen.queryByRole('region', { name: 'Save changes to Site & branding' });

describe('site & branding', () => {
    it('is the second section, marked New, and shows the saved identity with counters and the fallback name', async () => {
        brandingApi(owner);
        renderApp('/settings/branding');

        const nav = await screen.findByRole('navigation', { name: 'Store settings sections' });
        const branding = within(nav).getAllByRole('link')[1]!;
        expect(branding.getAttribute('href')).toBe('/settings/branding');
        expect(branding.getAttribute('aria-current')).toBe('page');
        expect(within(branding).getByText('New')).toBeTruthy();

        expect(await screen.findByRole('heading', { level: 2, name: 'Site & branding' })).toBeTruthy();
        expect(screen.getByText('Live on the storefront')).toBeTruthy();
        const name = screen.getByLabelText('Store name') as HTMLInputElement;
        expect(name.value).toBe('Smart Junior');
        expect(name.maxLength).toBe(60);
        expect(screen.getByText('12 / 60')).toBeTruthy();
        // A blank Bangla name shows the business name the storefront falls back to.
        expect((screen.getByLabelText('Store name · বাংলা') as HTMLInputElement).placeholder).toBe('Smart Junior');
        expect(screen.getByText('22 / 80')).toBeTruthy();
        // Saved images: file name and size, Replace and Library; Remove is named.
        const logoTile = screen.getByRole('group', { name: 'Logo' });
        expect(within(logoTile).getByText('toymart-logo.png · 1200 × 360')).toBeTruthy();
        expect(within(logoTile).getByRole('button', { name: 'Replace Logo' })).toBeTruthy();
        expect(within(logoTile).getByRole('button', { name: 'Remove Logo' })).toBeTruthy();
        // An empty tile is a dropzone plus "Choose from library".
        const dark = screen.getByRole('group', { name: 'Dark-background logo' });
        expect(within(dark).getByText('Drop an image or browse')).toBeTruthy();
        expect(within(dark).getByText('Optional')).toBeTruthy();
        // Read-only palette, five swatches with token names.
        expect(screen.getByText('bg/brand')).toBeTruthy();
        expect(screen.getByText('bg/canvas')).toBeTruthy();
        // Clean: no save bar.
        expect(bar()).toBeNull();
    });

    it('updates the live preview as the owner types, in English and Bangla', async () => {
        brandingApi(owner);
        renderApp('/settings/branding');

        const title = await screen.findByTestId('preview-tab-title');
        expect(title.textContent).toBe('Smart Junior — Toys & learning for every age');
        expect(screen.getAllByText('toymartbd.com').length).toBeGreaterThan(0);

        fireEvent.change(screen.getByLabelText('Tagline'), { target: { value: 'STEM toys' } });
        expect(title.textContent).toBe('Smart Junior — STEM toys');

        fireEvent.click(screen.getByRole('button', { name: 'বাংলা' }));
        expect(screen.getByTestId('preview-tab-title').textContent).toBe('Smart Junior — সব বয়সের খেলনা ও শেখা');
        expect(screen.getByTestId('preview-tab-title').getAttribute('lang')).toBe('bn');
    });

    it('shows the save bar on a change, says what changed, and Discard puts the saved values back', async () => {
        brandingApi(owner);
        renderApp('/settings/branding');

        const tagline = (await screen.findByLabelText('Tagline')) as HTMLInputElement;
        fireEvent.change(tagline, { target: { value: 'Learning toys' } });
        fireEvent.click(within(screen.getByRole('group', { name: 'Logo' })).getByRole('button', { name: 'Remove Logo' }));

        const region = bar();
        expect(region).toBeTruthy();
        expect(region!.textContent).toContain('Unsaved changes in Site & branding');
        expect(region!.textContent).toContain('Tagline and logo changed');
        // The bar never takes focus.
        expect(document.activeElement).not.toBe(within(region!).getByRole('button', { name: 'Save changes' }));

        fireEvent.click(within(region!).getByRole('button', { name: 'Discard' }));
        expect(tagline.value).toBe('Toys & learning for every age');
        expect(within(screen.getByRole('group', { name: 'Logo' })).getByText('toymart-logo.png · 1200 × 360')).toBeTruthy();
        expect(bar()).toBeNull();
    });

    it('saves the whole section through the generated schema, blanks as null, then says Saved', async () => {
        const calls = brandingApi(owner);
        renderApp('/settings/branding');

        fireEvent.change(await screen.findByLabelText('Store name'), { target: { value: '  ' } });
        fireEvent.change(screen.getByLabelText('Search & social description'), { target: { value: ' Educational toys, delivered across Bangladesh. ' } });
        fireEvent.change(screen.getByLabelText('Description · বাংলা'), { target: { value: 'শিক্ষামূলক খেলনা' } });
        fireEvent.click(screen.getByRole('button', { name: 'Remove Store icon & favicon' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(screen.getByText('Saved')).toBeTruthy());
        const body = putBody(calls);
        expect(updateStoreBrandingRequestSchema.safeParse(body).success).toBe(true);
        expect(body).toEqual({
            site_name_en: null,
            site_name_bn: null,
            tagline_en: 'Toys & learning for every age',
            tagline_bn: 'সব বয়সের খেলনা ও শেখা',
            description_en: 'Educational toys, delivered across Bangladesh.',
            description_bn: 'শিক্ষামূলক খেলনা',
            logo_media_id: 7,
            logo_dark_media_id: null,
            icon_media_id: null,
            share_image_media_id: null,
        });
        expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
    });

    it('picks a library image for an empty tile and saves its id', async () => {
        const calls = brandingApi(owner);
        renderApp('/settings/branding');

        const dark = await screen.findByRole('group', { name: 'Dark-background logo' });
        fireEvent.click(within(dark).getByRole('button', { name: 'Choose from library: Dark-background logo' }));
        const dialog = await screen.findByRole('dialog', { name: 'Choose the dark-background logo' });
        fireEvent.click(await within(dialog).findByRole('button', { name: /^brick-box-front\.jpg/ }));
        fireEvent.click(within(dialog).getByRole('button', { name: /^Insert/ }));

        await waitFor(() => expect(within(screen.getByRole('group', { name: 'Dark-background logo' })).getByText('brick-box-front.jpg · 1600 × 1600')).toBeTruthy());
        expect(bar()!.textContent).toContain('Dark-background logo changed');
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(putBody(calls).logo_dark_media_id).toBe(42));
    });

    it('shows a 422 on the image it belongs to', async () => {
        brandingApi(owner, () => ({
            status: 422,
            body: { type: 'validation', title: 'The given data was invalid.', status: 422, errors: [{ field: 'icon_media_id', message: 'The store icon must be square.' }] },
        }));
        renderApp('/settings/branding');

        fireEvent.change(await screen.findByLabelText('Tagline · বাংলা'), { target: { value: 'খেলনা' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        const tile = screen.getByRole('group', { name: 'Store icon & favicon' });
        expect(await within(tile).findByText('The store icon must be square.')).toBeTruthy();
        // Still unsaved.
        expect(bar()).toBeTruthy();
    });

    it('asks before leaving with unsaved changes', async () => {
        brandingApi(owner);
        renderApp('/settings/branding');

        fireEvent.change(await screen.findByLabelText('Tagline'), { target: { value: 'New tagline' } });
        fireEvent.click(screen.getByRole('link', { name: 'Business' }));

        const dialog = await screen.findByRole('dialog', { name: 'Leave without saving?' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Keep editing' }));
        expect((screen.getByLabelText('Tagline') as HTMLInputElement).value).toBe('New tagline');

        fireEvent.click(screen.getByRole('link', { name: 'Business' }));
        fireEvent.click(within(await screen.findByRole('dialog', { name: 'Leave without saving?' })).getByRole('button', { name: 'Leave and discard' }));
        expect(await screen.findByLabelText('Trade licence no.')).toBeTruthy();
    });

    it('is read-only without settings.update: disabled fields, no image actions, no save bar', async () => {
        brandingApi(readOnly);
        renderApp('/settings/branding');

        expect(((await screen.findByLabelText('Store name')) as HTMLInputElement).disabled).toBe(true);
        expect(screen.queryByRole('button', { name: 'Replace Logo' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Remove Logo' })).toBeNull();
        expect(bar()).toBeNull();
    });
});

describe('branding helpers', () => {
    it('counts code points, as the server does', () => {
        expect(charCount('Smart Junior')).toBe([...'Smart Junior'].length);
        expect(charCount('Smart Junior')).toBe(12);
    });

    it('builds the tab title with or without a tagline', () => {
        expect(tabTitle('Smart Junior', 'Toys')).toBe('Smart Junior — Toys');
        expect(tabTitle('Smart Junior', '')).toBe('Smart Junior');
    });
});
