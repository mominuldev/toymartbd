import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Testimonial } from './api';
import { toTestimonialForm, toTestimonialPayload } from './TestimonialFormPage';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const editor: Me = { ...viewer, roles: ['content_editor'], permissions: ['dashboard.view', 'content.view', 'content.manage'] };

const make = (id: number, name: string, patch: Partial<Testimonial> = {}): Testimonial => ({
    id,
    name,
    name_bn: null,
    location: 'Dhanmondi',
    location_bn: 'ধানমন্ডি',
    quote: `${name} says hi`,
    quote_bn: null,
    rating: 5,
    media_id: null,
    photo: null,
    is_published: true,
    position: id,
    updated_at: '2026-11-10T00:00:00Z',
    ...patch,
});

describe('testimonials', () => {
    it('lists in display order and reorders with the keyboard path', { timeout: 20_000 }, async () => {
        const list = [make(1, 'Nusrat'), make(2, 'Rafi', { is_published: false })];
        const calls = mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/reorder') && init?.method === 'POST') return { status: 200, body: { data: [list[1], list[0]] } };
            if (url.endsWith('/content/testimonials')) return { status: 200, body: { data: list } };
            return { status: 404 };
        });
        renderApp('/store/testimonials');

        const ordered = await screen.findByRole('list', { name: 'Display order' }, { timeout: 5000 });
        expect(within(ordered).getByText('Hidden')).toBeTruthy();
        expect(within(ordered).getAllByLabelText('5 of 5 stars').length).toBe(2);

        fireEvent.click(screen.getByRole('button', { name: 'Move Rafi up' }));

        await waitFor(() => expect(calls.mock.calls.some(([url]) => String(url).endsWith('/reorder'))).toBe(true));
        const call = calls.mock.calls.find(([url]) => String(url).endsWith('/reorder'))!;
        expect(JSON.parse(String(call[1]!.body))).toEqual({ ids: [2, 1] });
        await waitFor(() => expect(within(ordered).getAllByRole('listitem')[0]!.textContent).toContain('Rafi'));
    });

    it('builds the payload and validates the quote before calling the API', { timeout: 20_000 }, async () => {
        expect(toTestimonialPayload({ ...toTestimonialForm(), name: ' Nusrat ', quote: 'Great', rating: '4', photo: { media_id: 9, url: null, alt: null } })).toEqual({
            name: 'Nusrat',
            name_bn: null,
            location: null,
            location_bn: null,
            quote: 'Great',
            quote_bn: null,
            rating: 4,
            media_id: 9,
            is_published: false,
        });

        const calls = mockApi((url) => (url.endsWith('/auth/me') ? { status: 200, body: editor } : { status: 404 }));
        renderApp('/store/testimonials/new');
        fireEvent.change(await screen.findByLabelText(/^Name \(English\)/, {}, { timeout: 5000 }), { target: { value: 'Nusrat' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(await screen.findByText('This field is required.')).toBeTruthy();
        expect(calls.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false);
    });
});
