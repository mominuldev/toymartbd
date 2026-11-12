import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { AdminReview } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const moderator: Me = { ...viewer, roles: ['content_editor'], permissions: ['dashboard.view', 'catalog.view', 'reviews.moderate'] };

const review: AdminReview = {
    id: 5,
    product: { id: 9, name: 'Wooden Train', name_bn: 'কাঠের ট্রেন', slug: 'wooden-train', image_url: null },
    customer: { id: 3, name: 'নুসরাত জাহান', phone: '+880 17•• •••678' },
    reviewer_name: 'নুসরাত জ.',
    rating: 4,
    title: 'খুব ভালো',
    body: 'আমার ছেলে খুব পছন্দ করেছে।',
    is_verified_purchase: true,
    status: 'pending',
    locale: 'bn',
    admin_reply: null,
    admin_reply_at: null,
    moderated_by: null,
    moderated_at: null,
    created_at: '2026-11-12T08:00:00Z',
};

const page = (data: AdminReview[]) => ({ data, links: { next: null }, meta: { next_cursor: null, counts: { pending: data.length, approved: 2, rejected: 0 } } });

describe('review moderation', () => {
    it('opens on the pending queue and approves through the server', async () => {
        const calls = mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: moderator };
            if (url.endsWith('/reviews/5/approve') && init?.method === 'POST') return { status: 200, body: { ...review, status: 'approved' } };
            if (url.includes('/reviews')) return { status: 200, body: page([review]) };
            return { status: 404 };
        });

        renderApp('/products/reviews');

        const table = await screen.findByRole('table');
        expect(within(table).getByText('আমার ছেলে খুব পছন্দ করেছে।')).toBeTruthy();
        expect(within(table).getByText('Verified purchase')).toBeTruthy();
        expect(within(table).getByRole('img', { name: '4 out of 5 stars' })).toBeTruthy();
        expect(calls.mock.calls.some(([url]) => String(url).includes('filter%5Bstatus%5D=pending'))).toBe(true);

        fireEvent.click(within(table).getByRole('button', { name: 'Approve' }));
        await waitFor(() => expect(calls.mock.calls.some(([url, init]) => String(url).endsWith('/reviews/5/approve') && (init as RequestInit).method === 'POST')).toBe(true));
        expect(await screen.findByText('Review approved')).toBeTruthy();
    });

    it('hides moderation buttons from a viewer', async () => {
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: viewer };
            if (url.includes('/reviews')) return { status: 200, body: page([review]) };
            return { status: 404 };
        });

        renderApp('/products/reviews');
        const table = await screen.findByRole('table');
        expect(within(table).queryByRole('button', { name: 'Approve' })).toBeNull();
    });
});
