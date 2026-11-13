import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import { DeliveryDetails, OrderTimeline } from '@/components/order/OrderParts';
import type { StorefrontOrder } from '@/lib/cart/types';
import { getMessages } from '@/lib/i18n';
import { courierLabel, timelineSteps, type Shipment } from '@/lib/order/format';

// P6 — courier milestones on the order page and public tracking (Figma OrderTimeline 121:1558): the
// six steps with "out for delivery" from the courier, the courier line (name · consignment · Track),
// picked-up / in-transit times, in both locales with Western digits (rule 36).

const shipment = (over: Partial<Shipment> = {}): Shipment => ({
    courier: { code: 'steadfast', name: 'Steadfast', name_bn: 'স্টেডফাস্ট' },
    status: 'in_transit',
    consignment_id: 'CN-4471982',
    tracking_url: 'https://steadfast.com.bd/t/15BAEB8A',
    milestones: [
        { milestone: 'booked', at: '2026-11-10T08:14:00Z' },
        { milestone: 'picked_up', at: '2026-11-10T10:00:00Z' },
        { milestone: 'in_transit', at: '2026-11-11T03:30:00Z' },
    ],
    ...over,
});

const order = (over: Partial<StorefrontOrder> = {}): StorefrontOrder => ({
    number: 'TMB-26-00042',
    stage: 'shipped',
    placed_at: '2026-11-10T05:02:00Z',
    payment: { method: 'cod', status: 'unpaid', next_action: null, online: false, retry_allowed: false, expires_at: null },
    contact: { name: 'Sabrina', phone_masked: '017****5678', email: null },
    shipping: { name: 'Sabrina', phone_masked: '017****5678', division: null, district: null, upazila: null, area: 'Dhanmondi', address_line: null, landmark: null, postcode: null },
    items: [],
    coupon_code: null,
    totals: { subtotal: '1.00', promotion_discount: '0.00', coupon_discount: '0.00', discount_total: '0.00', shipping_charge: '0.00', grand_total: '1.00', paid_total: '0.00', due_total: '1.00' },
    timeline: [
        { stage: 'placed', at: '2026-11-10T05:02:00Z' },
        { stage: 'confirmed', at: '2026-11-10T05:20:00Z' },
        { stage: 'processing', at: '2026-11-10T07:05:00Z' },
        { stage: 'shipped', at: '2026-11-10T08:14:00Z' },
    ],
    shipment: shipment(),
    ...over,
});

describe('courier milestones in the timeline', () => {
    it('keeps "handed to courier" current until the courier is out for delivery, with the courier line and sub-times', () => {
        const steps = timelineSteps(order());
        expect(steps.map((s) => [s.stage, s.state])).toEqual([
            ['placed', 'complete'],
            ['confirmed', 'complete'],
            ['processing', 'complete'],
            ['shipped', 'current'],
            ['out', 'upcoming'],
            ['delivered', 'upcoming'],
        ]);
        expect(steps[3]?.courier?.sub.map((m) => m.milestone)).toEqual(['picked_up', 'in_transit']);
    });

    it('makes "out for delivery" current with the courier time, then delivered completes all', () => {
        const out = shipment({ status: 'out_for_delivery', milestones: [...shipment().milestones, { milestone: 'out_for_delivery', at: '2026-11-11T04:00:00Z' }] });
        const steps = timelineSteps(order({ shipment: out }));
        expect(steps[4]).toMatchObject({ stage: 'out', state: 'current', at: '2026-11-11T04:00:00Z' });
        expect(steps[3]?.state).toBe('complete');

        const delivered = timelineSteps(order({ stage: 'delivered', shipment: { ...out, status: 'delivered', milestones: [...out.milestones, { milestone: 'delivered', at: '2026-11-11T09:00:00Z' }] } }));
        expect(delivered.every((s) => s.state === 'complete')).toBe(true);
        expect(delivered[5]?.at).toBe('2026-11-11T09:00:00Z');
    });

    it('ends a returned parcel at the stop, after the courier steps it reached', () => {
        const returned = shipment({ status: 'returned', milestones: [...shipment().milestones, { milestone: 'out_for_delivery', at: '2026-11-11T04:00:00Z' }, { milestone: 'returned', at: '2026-11-13T04:00:00Z' }] });
        const steps = timelineSteps(order({ stage: 'returned', shipment: returned }));
        expect(steps.map((s) => [s.stage, s.state])).toEqual([
            ['placed', 'complete'],
            ['confirmed', 'complete'],
            ['processing', 'complete'],
            ['shipped', 'complete'],
            ['out', 'complete'],
            ['returned', 'stopped'],
        ]);
        expect(steps[5]?.at).toBe('2026-11-13T04:00:00Z');
    });

    it('draws no courier line before a courier has the parcel', () => {
        const steps = timelineSteps(order({ stage: 'processing', shipment: null }));
        expect(steps.some((s) => s.courier)).toBe(false);
        expect(steps).toHaveLength(6);
    });

    it('says "booking" instead of inventing a consignment for a bulk-submitted parcel (rule 24d)', () => {
        const submitted = shipment({ status: 'submitted', consignment_id: null, tracking_url: null, milestones: [] });
        expect(courierLabel(submitted, 'en', getMessages('en'))).toBe('Booking with Steadfast');
        expect(courierLabel(submitted, 'bn', getMessages('bn'))).toBe('স্টেডফাস্ট-এ বুকিং হচ্ছে');
    });
});

describe('rendered timeline, both locales', () => {
    const render = (locale: 'en' | 'bn', o = order()) => renderToStaticMarkup(createElement(OrderTimeline, { order: o, locale, t: getMessages(locale) }));

    it('shows the courier, consignment, a safe Track link and the milestone times in English', () => {
        const html = render('en');
        expect(html).toContain('Handed to courier');
        expect(html).toContain('Steadfast · CN-4471982');
        expect(html).toContain('href="https://steadfast.com.bd/t/15BAEB8A"');
        expect(html).toContain('rel="noopener noreferrer nofollow"');
        expect(html).toContain('>Track<');
        expect(html).toContain('Picked up by the courier · 10 Nov, 4:00 pm');
        expect(html).toContain('In transit · 11 Nov, 9:30 am');
        expect(html).toContain('Out for delivery');
    });

    it('uses the Bangla courier name and labels with Western digits', () => {
        const html = render('bn');
        expect(html).toContain('কুরিয়ারে দেওয়া হয়েছে');
        expect(html).toContain('স্টেডফাস্ট · CN-4471982');
        expect(html).toContain('ট্র্যাক করুন');
        expect(html).toContain('কুরিয়ার পিকআপ করেছে · 10 ');
        expect(html).toMatch(/ট্রানজিটে আছে · 11 .+, 9:30/);
        expect(html).not.toMatch(/[০-৯]/);
    });

    it('fills the Courier column of the delivery details', () => {
        const en = renderToStaticMarkup(createElement(DeliveryDetails, { order: order(), locale: 'en', t: getMessages('en') }));
        expect(en).toContain('Steadfast · CN-4471982');
        expect(en).toContain('Track with the courier');
        const none = renderToStaticMarkup(createElement(DeliveryDetails, { order: order({ shipment: null }), locale: 'en', t: getMessages('en') }));
        expect(none).toContain(getMessages('en').order.courierPending);
    });
});
