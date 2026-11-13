import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { accountBn, accountEn } from '@/lib/account/messages';
import { readProfileForm } from '@/lib/account/profile-form';
import { noticeText } from '@/lib/account/format';

// P8 — birthday messages on the account profile: the form parser behind the no-JS Route Handler and
// the copy. Unticked means "no"; a "yes" needs a date; the profile never assumes consent.

function form(fields: Record<string, string>): FormData {
    const f = new FormData();
    for (const [k, v] of Object.entries(fields)) f.set(k, v);
    return f;
}

const base = { locale: 'en', name: 'Sabrina', email: '', preferred_language: 'bn' };

describe('profile form with the birthday opt-in', () => {
    it('reads an opt-in with a date into one PATCH body and a consent of yes', () => {
        const s = readProfileForm(form({ ...base, date_of_birth: '1992-02-29', birthday_messages: '1' }));
        expect(s.errors).toEqual({});
        expect(s.body).toEqual({ name: 'Sabrina', email: null, preferred_language: 'bn', date_of_birth: '1992-02-29' });
        expect(s.birthday).toBe(true);
    });

    it('treats an unticked (absent) box as no — the withdrawal path', () => {
        const s = readProfileForm(form({ ...base, date_of_birth: '1992-02-29' }));
        expect(s.birthday).toBe(false);
        expect(s.draft.birthday_messages).toBe('0');
        expect(readProfileForm(form({ ...base, birthday_messages: 'on' })).birthday).toBe(false); // only our own value counts
    });

    it('asks for a date before opting in, and keeps what was typed', () => {
        const s = readProfileForm(form({ ...base, name: 'সাবরিনা', birthday_messages: '1' }));
        expect(s.errors).toEqual({ date_of_birth: 'birthdayNeedsDate' });
        expect(s.draft).toMatchObject({ name: 'সাবরিনা', birthday_messages: '1', date_of_birth: '' });
        expect(noticeText(s.errors.date_of_birth, accountEn)).toBe(accountEn.notices.birthdayNeedsDate);
    });

    it('rejects a date that is not Y-m-d before calling the API; an empty date clears it', () => {
        expect(readProfileForm(form({ ...base, date_of_birth: '29/02/1992' })).errors).toEqual({ date_of_birth: 'dateInvalid' });
        expect(readProfileForm(form({ ...base, date_of_birth: '' })).body.date_of_birth).toBeNull();
    });
});

describe('birthday copy', () => {
    it('says what is sent, on which channel, how often and how to stop, in both locales', () => {
        expect(accountEn.profile.birthday.what).toMatch(/SMS/);
        expect(accountEn.profile.birthday.what).toMatch(/Once a year/);
        expect(accountEn.profile.birthday.stop).toMatch(/untick/i);
        expect(accountBn.profile.birthday.what).toMatch(/এসএমএস/);
        expect(accountBn.profile.birthday.stop).toMatch(/টিক/);
    });
});

describe('never on checkout', () => {
    const files = (dir: string): string[] =>
        readdirSync(dir).flatMap((name) => {
            const path = join(dir, name);
            return statSync(path).isDirectory() ? files(path) : [path];
        });

    it('no checkout page, component or handler asks for the birthday opt-in or a date of birth', () => {
        const checkout = [...files('app/[locale]/checkout'), ...files('components/checkout'), ...files('app/api/checkout')].filter((f) => /\.(tsx?|mjs)$/.test(f));
        expect(checkout.length).toBeGreaterThan(0);
        for (const file of checkout) expect(readFileSync(file, 'utf8'), file).not.toMatch(/birthday_messages|date_of_birth|profile\.birthday/);
    });
});
