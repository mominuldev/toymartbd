import { describe, expect, it } from 'vitest';

import { decodeFlash, decodeOtp, displayPhone, encodeFlash, encodeOtp, nationalPhone, safeNext } from '@/lib/account/flash';
import { clock, firstName, initials, noticeText } from '@/lib/account/format';
import { accountBn, accountEn, accountMessages } from '@/lib/account/messages';

// P4-STO-11 — the account pages' pure helpers and copy.

function leaves(value: unknown, prefix = ''): Array<[string, unknown]> {
    if (typeof value !== 'object' || value === null) return [[prefix, value]];
    return Object.entries(value).flatMap(([k, v]) => leaves(v, prefix ? `${prefix}.${k}` : k));
}

describe('account catalogs', () => {
    it('bn has exactly the keys of en, all non-empty strings', () => {
        expect(leaves(accountBn).map(([k]) => k).sort()).toEqual(leaves(accountEn).map(([k]) => k).sort());
        for (const [key, value] of leaves(accountBn)) expect(typeof value === 'string' && value.length > 0, key).toBe(true);
    });

    it('keeps numerals Western in both locales (rule 36)', () => {
        for (const [key, value] of [...leaves(accountEn), ...leaves(accountBn)]) expect(/[০-৯]/.test(String(value)), key).toBe(false);
    });

    it('never offers OTP over WhatsApp (rule 32b)', () => {
        expect(accountEn.login.smsNote).toContain('never WhatsApp');
    });
});

describe('phone input (rule 30)', () => {
    it.each([
        ['01712345678', '01712345678'],
        ['+880 1712-345678', '01712345678'],
        ['8801912345678', '01912345678'],
        ['০১৭১২৩৪৫৬৭৮', '01712345678'],
    ])('normalises %s', (input, national) => {
        expect(nationalPhone(input)).toBe(national);
    });

    it.each(['01112345678', '01212345678', '01012345678', '0171234567', '06012345678', ''])('rejects %s', (input) => {
        expect(nationalPhone(input)).toBeNull();
    });

    it('formats for display with Western digits', () => {
        expect(displayPhone('01985164739')).toBe('01985 164 739');
    });
});

describe('redirect target after sign-in', () => {
    it('keeps local account / checkout paths', () => {
        expect(safeNext('/account/orders/TMB-26-00042')).toBe('/account/orders/TMB-26-00042');
        expect(safeNext('/checkout')).toBe('/checkout');
    });

    it.each(['https://evil.example', '//evil.example', '/account/../x', '/admin', null, ''])('sends %s to the account home', (value) => {
        expect(safeNext(value)).toBe('/account');
    });
});

describe('flash and OTP cookies', () => {
    it('round-trips Bangla through the flash cookie', () => {
        const flash = { kind: 'error' as const, message: null, errors: { area: 'এলাকা দিন' }, draft: { area: 'আগ্রাবাদ' } };
        expect(decodeFlash(encodeFlash(flash))).toEqual(flash);
    });

    it('ignores tampered or oversized values', () => {
        expect(decodeFlash('not-base64-json')).toBeNull();
        expect(decodeFlash('x'.repeat(5000))).toBeNull();
        expect(decodeOtp(encodeOtp({ phone: '01112345678', sentAt: 1, resendIn: 60, next: '/account' }))).toBeNull();
    });

    it('keeps the OTP step state and sanitises its redirect', () => {
        const state = decodeOtp(encodeOtp({ phone: '01712345678', sentAt: 5, resendIn: 60, next: 'https://evil.example' }));
        expect(state).toEqual({ phone: '01712345678', sentAt: 5, resendIn: 60, next: '/account' });
    });
});

describe('display helpers', () => {
    it('translates notice keys and passes API sentences through', () => {
        const m = accountMessages('bn');
        expect(noticeText('signedIn', m)).toBe(m.notices.signedIn);
        expect(noticeText('কোডটি সঠিক নয়', m)).toBe('কোডটি সঠিক নয়');
        expect(noticeText(null, m)).toBeNull();
    });

    it('builds initials and first names, Bangla included', () => {
        expect(initials('Sumaiya Islam')).toBe('SI');
        expect(initials('')).toBeNull();
        expect(firstName('সাদিয়া ইসলাম')).toBe('সাদিয়া');
    });

    it('formats the resend countdown', () => {
        expect(clock(42)).toBe('0:42');
        expect(clock(61)).toBe('1:01');
        expect(clock(-3)).toBe('0:00');
    });
});
