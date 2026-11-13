import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { NotificationTemplate } from './api';
import type { AutomationSettings } from './automationsApi';

/* P8 batch 3 — Settings → Automations, birthday reminder: lead days and the personal-coupon offer
   (off, with placeholder values, until the owner saves it). Every value is the server's. */

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const manager: Me = { ...viewer, roles: ['manager'], permissions: ['dashboard.view', 'settings.view', 'settings.update'] };
const settingsViewer: Me = { ...viewer, roles: ['viewer'], permissions: ['dashboard.view', 'settings.view'] };

const birthday: NotificationTemplate = {
    id: 9,
    key: 'customer.birthday',
    channel: 'sms',
    category: 'marketing',
    can_opt_out: true,
    subject_en: null,
    subject_bn: null,
    preheader_en: null,
    preheader_bn: null,
    body_en: 'Toy Mart BD: {child_possessive} birthday is on {birthday_date}! {offer_sentence} Gifts: {store_url}',
    body_bn: 'টয় মার্ট বিডি: {birthday_date} {child_possessive} জন্মদিন! {offer_sentence} {store_url}',
    provider_template: null,
    is_active: true,
    variables: ['name', 'child_possessive', 'birthday_date', 'offer_sentence', 'coupon_code', 'coupon_expiry', 'store_url'],
    chars_en: 132,
    chars_bn: 124,
    segments_en: 1,
    segments_bn: 2,
    encoding_en: 'gsm7',
    encoding_bn: 'ucs2',
    updated_at: '2026-11-12T10:00:00Z',
    kind: 'system',
    is_custom: false,
    name_en: null,
    name_bn: null,
    whatsapp_status: null,
    required_variables: [],
    cost_en: '0.26',
    cost_bn: '0.52',
    updated_by_name: null,
    archived_at: null,
};

const settings: AutomationSettings = {
    review_delay_days: 3,
    quiet_from: '21:00',
    quiet_to: '09:00',
    birthday_send_at: '10:00',
    birthday_lead_days: 7,
    birthday_offer: { enabled: false, type: 'percent', value: '10.00', max_discount: '300.00', min_order_amount: '0.00', valid_days: 14, is_placeholder: true },
    stats: { review_request: { queued: 0, skipped: 0, skip_reasons: {} }, birthday: { queued: 4, skipped: 1, skip_reasons: { opted_out: 1 } } },
};

function automationsApi(me: Me) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/notifications/templates')) return { status: 200, body: { data: [birthday] } };
        if (url.endsWith('/notifications/automations') && init?.method === 'PUT') {
            const sent = JSON.parse(String(init.body)) as { birthday_lead_days: number; birthday_offer: AutomationSettings['birthday_offer'] };
            return { status: 200, body: { ...settings, birthday_lead_days: sent.birthday_lead_days, birthday_offer: { ...sent.birthday_offer, is_placeholder: false } } };
        }
        if (url.endsWith('/notifications/automations')) return { status: 200, body: settings };
        if (url.includes('/notifications/logs')) return { status: 200, body: { data: [], meta: { next_cursor: null } } };
        return { status: 404 };
    });
}

const panel = async () => (await screen.findByRole('heading', { name: 'Schedule' }, { timeout: 5000 })).closest('section')!;

describe('birthday reminder schedule and offer', () => {
    it('shows the placeholders as placeholders and saves lead days and the offer in the generated shape', async () => {
        const calls = automationsApi(manager);
        renderApp('/automations?template=customer.birthday&channel=sms');

        const card = await panel();
        expect(await within(card).findByText(/These values are placeholders, not a decision/)).toBeTruthy();
        expect((within(card).getByLabelText(/Include a personal single-use coupon code/) as HTMLInputElement).checked).toBe(false);
        expect((within(card).getByLabelText(/Days before the birthday/) as HTMLInputElement).value).toBe('7');

        fireEvent.change(within(card).getByLabelText(/Days before the birthday/), { target: { value: '5' } });
        fireEvent.click(within(card).getByLabelText(/Include a personal single-use coupon code/));
        fireEvent.change(within(card).getByLabelText(/Discount type/), { target: { value: 'fixed' } });
        expect(within(card).queryByLabelText(/At most/)).toBeNull(); // no cap on a taka offer
        fireEvent.change(within(card).getByLabelText(/Amount/), { target: { value: '200' } });
        fireEvent.click(within(card).getByRole('button', { name: 'Save schedule' }));

        await waitFor(() => {
            const put = calls.mock.calls.find(([url, init]) => String(url).endsWith('/notifications/automations') && init?.method === 'PUT');
            expect(put && JSON.parse(String(put[1]?.body))).toEqual({
                review_delay_days: 3,
                quiet_from: '21:00',
                quiet_to: '09:00',
                birthday_lead_days: 5,
                birthday_offer: { enabled: true, type: 'fixed', value: '200', max_discount: null, min_order_amount: '0.00', valid_days: 14 },
            });
        });
        await waitFor(() => expect(within(card).queryByText(/These values are placeholders/)).toBeNull());
    });

    it('is read-only without settings.update', async () => {
        automationsApi(settingsViewer);
        renderApp('/automations?template=customer.birthday&channel=sms');

        const card = await panel();
        expect((await within(card).findByLabelText(/Days before the birthday/)).hasAttribute('disabled')).toBe(true);
        expect(within(card).queryByRole('button', { name: 'Save schedule' })).toBeNull();
    });

    it('renders in Bangla with Western digits', async () => {
        automationsApi(manager);
        renderApp('/automations?template=customer.birthday&channel=sms', { locale: 'bn' });
        const card = (await screen.findByRole('heading', { name: 'সময়সূচি' })).closest('section')!;
        expect(await within(card).findByText('রিমাইন্ডারে উপহার অফার')).toBeTruthy();
        expect(card.textContent ?? '').not.toMatch(/[০-৯]/);
    });
});
