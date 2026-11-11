import { describe, expect, it } from 'vitest';

import { bn } from '@/lib/i18n/bn';
import { en } from '@/lib/i18n/en';
import { format, localizedPath, otherLocale } from '@/lib/i18n';
import { resolveLocaleRoute } from '@/lib/locale-route';
import { alternatesFor } from '@/lib/seo';

function leaves(value: unknown, prefix = ''): Array<[string, unknown]> {
    if (typeof value !== 'object' || value === null) return [[prefix, value]];
    return Object.entries(value).flatMap(([k, v]) => leaves(v, prefix ? `${prefix}.${k}` : k));
}

describe('message catalogs', () => {
    it('bn has exactly the keys of en, all non-empty strings', () => {
        const enKeys = leaves(en).map(([k]) => k).sort();
        const bnLeaves = leaves(bn);
        expect(bnLeaves.map(([k]) => k).sort()).toEqual(enKeys);
        for (const [key, value] of bnLeaves) expect(typeof value === 'string' && value.length > 0, key).toBe(true);
    });

    it('no catalog string contains a Bengali digit (rule 36: numerals stay Western)', () => {
        for (const [key, value] of [...leaves(en), ...leaves(bn)]) expect(/[০-৯]/.test(String(value)), key).toBe(false);
    });

    it('formats placeholders', () => {
        expect(format(bn.brands.logoAlt, { name: 'লেগো' })).toBe('লেগো লোগো');
    });
});

describe('locale paths', () => {
    it('keeps English at the root and Bangla under /bn', () => {
        expect(localizedPath('en', '/')).toBe('/');
        expect(localizedPath('en', '/brands/lego')).toBe('/brands/lego');
        expect(localizedPath('bn', '/')).toBe('/bn');
        expect(localizedPath('bn', '/brands/lego')).toBe('/bn/brands/lego');
        expect(otherLocale('en')).toBe('bn');
    });

    it('emits reciprocal hreflang plus x-default → en', () => {
        const forEn = alternatesFor('en', '/brands');
        const forBn = alternatesFor('bn', '/brands');
        expect(forEn?.languages).toEqual({ en: '/brands', bn: '/bn/brands', 'x-default': '/brands' });
        expect(forBn?.languages).toEqual(forEn?.languages);
        expect(forEn?.canonical).toBe('/brands');
        expect(forBn?.canonical).toBe('/bn/brands');
    });
});

describe('proxy routing (never a locale redirect)', () => {
    it.each([
        ['/', '/en'],
        ['/brands', '/en/brands'],
        ['/brands/lego', '/en/brands/lego'],
        ['/bnx', '/en/bnx'],
    ])('rewrites %s internally to %s', (from, to) => {
        expect(resolveLocaleRoute(from)).toEqual({ action: 'rewrite', pathname: to });
    });

    it.each(['/bn', '/bn/', '/bn/brands', '/bn/brands/lego'])('serves %s as-is', (path) => {
        expect(resolveLocaleRoute(path)).toEqual({ action: 'next' });
    });

    it('canonicalises a hand-typed /en prefix to the unprefixed URL', () => {
        expect(resolveLocaleRoute('/en')).toEqual({ action: 'redirect', pathname: '/' });
        expect(resolveLocaleRoute('/en/brands')).toEqual({ action: 'redirect', pathname: '/brands' });
    });
});
