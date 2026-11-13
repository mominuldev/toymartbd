import { describe, expect, it } from 'vitest';

import { adminOrigin, BRIDGE_SOURCE, parseToCanvas } from '@/lib/content/preview-bridge';

describe('homepage builder preview bridge', () => {
    it('accepts the builder messages', () => {
        expect(parseToCanvas({ source: BRIDGE_SOURCE, type: 'refresh' })).toEqual({ source: BRIDGE_SOURCE, type: 'refresh' });
        expect(parseToCanvas({ source: BRIDGE_SOURCE, type: 'select', id: '0ea19831-1638-4e7e-b3ad-443bd3edbdd8', scroll: true })).toEqual({ source: BRIDGE_SOURCE, type: 'select', id: '0ea19831-1638-4e7e-b3ad-443bd3edbdd8', scroll: true });
        expect(parseToCanvas({ source: BRIDGE_SOURCE, type: 'select', id: null })).toEqual({ source: BRIDGE_SOURCE, type: 'select', id: null, scroll: false });
    });

    it('keeps only block-id keys and string names in the label map, Bangla included', () => {
        const parsed = parseToCanvas({ source: BRIDGE_SOURCE, type: 'labels', labels: { 'a-1': 'Hero', 'b-2': 'নতুন খেলনা', 'x y': 'no', '"]': 'no', 'c-3': 9 } });
        expect(parsed).toEqual({ source: BRIDGE_SOURCE, type: 'labels', labels: { 'a-1': 'Hero', 'b-2': 'নতুন খেলনা' } });
    });

    it('ignores anything else', () => {
        expect(parseToCanvas(null)).toBeNull();
        expect(parseToCanvas('refresh')).toBeNull();
        expect(parseToCanvas({ type: 'refresh' })).toBeNull();
        expect(parseToCanvas({ source: 'other', type: 'refresh' })).toBeNull();
        expect(parseToCanvas({ source: BRIDGE_SOURCE, type: 'navigate', href: '/x' })).toBeNull();
        expect(parseToCanvas({ source: BRIDGE_SOURCE, type: 'select', id: 'a"] , body' })).toBeNull();
        expect(parseToCanvas({ source: BRIDGE_SOURCE, type: 'select', id: 3 })).toBeNull();
        expect(parseToCanvas({ source: BRIDGE_SOURCE, type: 'labels', labels: ['a'] })).toBeNull();
    });

    it('takes the admin origin from ADMIN_URL, else API_URL', () => {
        expect(adminOrigin({ ADMIN_URL: 'https://admin.example.com/admin', API_URL: 'http://api.internal' })).toBe('https://admin.example.com');
        expect(adminOrigin({ API_URL: 'http://localhost:8000' })).toBe('http://localhost:8000');
        expect(adminOrigin({ ADMIN_URL: 'not a url', API_URL: 'http://localhost:8000/' })).toBe('http://localhost:8000');
        expect(adminOrigin({})).toBeNull();
    });
});
