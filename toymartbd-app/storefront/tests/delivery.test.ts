import { describe, expect, it } from 'vitest';

import { parseQuoteRequest, quotePath } from '@/lib/delivery/fetch';
import { arrivalLine, publishedLine, shortDate, workingDaysLine } from '@/lib/delivery/format';
import type { DeliveryQuote, DeliveryRates } from '@/lib/delivery/types';
import { getMessages } from '@/lib/i18n';

// DeliveryEstimator display helpers (P4-STO-06). Figures come from the API; these only format.

const en = getMessages('en').delivery;
const bn = getMessages('bn').delivery;

const eta: NonNullable<DeliveryQuote['eta']> = {
    dispatch_date: '2026-11-09',
    earliest: '2026-11-10',
    latest: '2026-11-11',
    min_days: 1,
    max_days: 2,
    cutoff_time: '17:00',
    cutoff_passed: false,
    timezone: 'Asia/Dhaka',
};

const rates: DeliveryRates = {
    courier: { code: 'steadfast', name: 'Steadfast', name_bn: 'স্টেডফাস্ট' },
    zones: [
        { zone: 'inside_dhaka_metro', charge: '70.00', min_days: 1, max_days: 2 },
        { zone: 'dhaka_suburb', charge: '100.00', min_days: 2, max_days: 3 },
        { zone: 'outside_dhaka', charge: '130.00', min_days: 3, max_days: 4 },
    ],
    free_shipping_threshold: '3000.00',
    included_weight_grams: 1000,
    per_kg_charge: '20.00',
    cutoff_time: '17:00',
    timezone: 'Asia/Dhaka',
};

describe('delivery dates', () => {
    it('renders absolute dates with Western digits in both locales', () => {
        expect(shortDate('2026-11-10', en)).toBe('10 Nov');
        expect(shortDate('2026-11-10', bn)).toBe('10 নভেম্বর');
        expect(arrivalLine(eta, en)).toBe('Arrives 10 – 11 Nov');
        expect(arrivalLine(eta, bn)).toBe('10 – 11 নভেম্বর এর মধ্যে পৌঁছাবে');
        expect(workingDaysLine(eta, en)).toBe('(1–2 working days)');
    });

    it('names both months across a month end, and one date when the window is a day', () => {
        expect(arrivalLine({ ...eta, earliest: '2026-11-30', latest: '2026-12-01' }, en)).toBe('Arrives 30 Nov – 1 Dec');
        expect(arrivalLine({ ...eta, latest: '2026-11-10', max_days: 1 }, en)).toBe('Arrives 10 Nov');
        expect(workingDaysLine({ ...eta, max_days: 1 }, en)).toBe('(1 working day)');
    });
});

describe('published fallback line', () => {
    it('states the Dhaka and outside charges and the threshold from the API', () => {
        expect(publishedLine(rates, en)).toBe('৳70 in Dhaka · ৳130 outside · free over ৳3,000');
        expect(publishedLine(rates, bn)).toBe('ঢাকায় ৳70 · ঢাকার বাইরে ৳130 · ৳3,000+ অর্ডারে ফ্রি');
        expect(publishedLine({ ...rates, free_shipping_threshold: null }, en)).toBe('৳70 in Dhaka · ৳130 outside');
        expect(publishedLine(null, en)).toBeNull();
    });
});

describe('quote request', () => {
    it('accepts whole positive ids only and defaults the quantity', () => {
        const q = (params: Record<string, string>) => parseQuoteRequest((k) => params[k]);
        expect(q({ district_id: '47', upazila_id: '365', variant_id: '12', quantity: '2' })).toEqual({ districtId: 47, upazilaId: 365, variantId: 12, quantity: 2 });
        expect(q({ district_id: '8' })).toEqual({ districtId: 8, upazilaId: null, variantId: null, quantity: 1 });
        expect(q({ district_id: '8', quantity: '500' })?.quantity).toBe(1);
        expect(q({ district_id: '0' })).toBeNull();
        expect(q({ district_id: '8;drop' })).toBeNull();
        expect(q({})).toBeNull();
    });

    it('builds the API path', () => {
        expect(quotePath({ districtId: 47, upazilaId: null, variantId: 3, quantity: 1 })).toBe('storefront/delivery-quote?district_id=47&quantity=1&variant_id=3');
    });
});
