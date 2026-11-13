import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Banner } from './api';
import { bannerRuleErrors, toBannerForm, toBannerPayload } from './form';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const editor: Me = { ...viewer, roles: ['content_editor'], permissions: ['dashboard.view', 'content.view', 'content.manage'] };

const bar: Banner = {
    id: 1,
    placement: 'announcement_bar',
    name: 'Winter sale',
    title: 'Free delivery in Dhaka',
    title_bn: 'ঢাকায় ফ্রি ডেলিভারি',
    body: null,
    body_bn: null,
    media_id: null,
    image: null,
    link_url: null,
    link_label: null,
    link_label_bn: null,
    category_id: null,
    category: null,
    priority: 5,
    is_active: true,
    starts_at: '2026-12-15T18:00:00Z',
    ends_at: '2026-12-16T18:00:00Z',
    state: 'scheduled',
    updated_at: '2026-11-10T00:00:00Z',
};

describe('banners', () => {
    it('lists banners with placement, Dhaka schedule and state, filtered on the server', { timeout: 20_000 }, async () => {
        const calls = mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.includes('/content/banners')) return { status: 200, body: { data: url.includes('info_strip') ? [] : [bar] } };
            return { status: 404 };
        });
        renderApp('/store/banners');

        const table = await screen.findByRole('table', {}, { timeout: 5000 });
        expect(within(table).getByRole('link', { name: 'Winter sale' })).toBeTruthy();
        expect(within(table).getByText('Scheduled')).toBeTruthy();
        expect(within(table).getByText('16 Dec 2026, 00:00 – 17 Dec 2026, 00:00')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Info strip' }));
        expect(await screen.findByText('No banners here yet')).toBeTruthy();
        expect(calls.mock.calls.some(([url]) => String(url).includes('filter%5Bplacement%5D=info_strip'))).toBe(true);
    });

    it('builds the payload: Dhaka +06:00 schedule, no image or category where the placement forbids it', () => {
        const form = { ...toBannerForm(), name: 'Strip', title: 'Holiday', placement: 'info_strip' as const, image: { media_id: 4, url: null, alt: null }, category_id: '7', starts_at: '2026-12-16T00:00' };
        const payload = toBannerPayload(form);
        expect(payload).toMatchObject({ placement: 'info_strip', media_id: null, category_id: null, starts_at: '2026-12-16T00:00:00+06:00', ends_at: null, priority: 0 });
        expect(toBannerPayload({ ...form, placement: 'category_page' })).toMatchObject({ media_id: 4, category_id: 7 });
        expect(toBannerForm(bar).starts_at).toBe('2026-12-16T00:00');
    });

    it('keeps the info strip linked only to store pages and the schedule in order', () => {
        const base = { ...toBannerForm(), name: 'x', title: 't', placement: 'info_strip' as const };
        expect(bannerRuleErrors({ ...base, link_url: '/products/lego' })).toEqual({ link_url: 'infoLink' });
        expect(bannerRuleErrors({ ...base, link_url: '/pages/delivery-policy' })).toEqual({});
        expect(bannerRuleErrors({ ...base, placement: 'popup', link_url: 'http://x.test' })).toEqual({ link_url: 'link' });
        expect(bannerRuleErrors({ ...base, title: '', starts_at: '2026-12-16T00:00', ends_at: '2026-12-15T00:00' })).toEqual({ title: 'content', ends_at: 'ends' });
    });

    it('shows the checkout note for the info strip and refuses a product link before calling the API', { timeout: 20_000 }, async () => {
        const calls = mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.includes('/categories')) return { status: 200, body: { data: [] } };
            return { status: 404 };
        });
        renderApp('/store/banners/new');

        fireEvent.change(await screen.findByLabelText('Placement', {}, { timeout: 5000 }), { target: { value: 'info_strip' } });
        expect(screen.getByText(/only banner shown on cart and checkout/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Choose image' })).toBeNull();
        fireEvent.change(screen.getByLabelText(/Internal name/), { target: { value: 'Eid cut-off' } });
        fireEvent.change(screen.getByLabelText(/Headline \(English\)/), { target: { value: 'Order by 20 Dec for Eid' } });
        fireEvent.change(screen.getByLabelText(/^Link\s*Optional$/), { target: { value: '/products/lego' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(await screen.findByText('An info strip may only link to a store page (/pages/…).')).toBeTruthy();
        await waitFor(() => expect(calls.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false));
    });
});
