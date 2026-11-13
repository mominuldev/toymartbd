import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { birthdayLabel, childTitle } from '@/components/account/KidsCard';
import { accountBn, accountEn } from '@/lib/account/messages';
import { readChildForm, readDetailsForm, readPreferencesForm } from '@/lib/account/profile-form';
import { noticeText } from '@/lib/account/format';

// P8 batch 3 — "My kids" and the "Birthday reminders and offers" switch on the account profile: the
// form parsers behind the no-JS Route Handlers and the copy. Unticked means "no"; the profile never
// assumes consent; nothing of it is on checkout; the customer's own date of birth is gone.

function form(fields: Record<string, string>): FormData {
    const f = new FormData();
    for (const [k, v] of Object.entries(fields)) f.set(k, v);
    return f;
}

describe('details and preferences forms', () => {
    it('reads name and email only — no date of birth any more', () => {
        const s = readDetailsForm(form({ name: ' Sabrina ', email: '', date_of_birth: '1992-02-29' }));
        expect(s.body).toEqual({ name: 'Sabrina', email: null });
    });

    it('reads the reminders switch: only our own value counts as yes; absent is no', () => {
        expect(readPreferencesForm(form({ preferred_language: 'en', birthday_reminders: '1' }))).toMatchObject({ birthday: true, body: { preferred_language: 'en' } });
        const off = readPreferencesForm(form({ preferred_language: 'bn' }));
        expect(off.birthday).toBe(false);
        expect(off.draft.birthday_reminders).toBe('0');
        expect(readPreferencesForm(form({ birthday_reminders: 'on' })).birthday).toBe(false);
        expect(readPreferencesForm(form({ preferred_language: 'xx' })).body.preferred_language).toBe('bn');
    });
});

describe('child form', () => {
    it('reads an add with an optional name and no year', () => {
        const s = readChildForm(form({ intent: 'save', child_name: ' আয়ান ', birth_day: '29', birth_month: '2', birth_year: '' }));
        expect(s.errors).toEqual({});
        expect(s.id).toBeNull();
        expect(s.body).toEqual({ name: 'আয়ান', birth_month: 2, birth_day: 29, birth_year: null });
        expect(s.draft.child_id).toBe('new');
    });

    it('reads an edit and a delete by id; an empty name is null', () => {
        expect(readChildForm(form({ intent: 'save', id: '42', child_name: '', birth_day: '12', birth_month: '3', birth_year: '2020' }))).toMatchObject({
            id: 42,
            body: { name: null, birth_month: 3, birth_day: 12, birth_year: 2020 },
            draft: { child_id: '42' },
        });
        expect(readChildForm(form({ intent: 'delete', id: '42' }))).toMatchObject({ intent: 'delete', id: 42, errors: {} });
    });

    it('asks for day and month before calling the API, keeping what was typed', () => {
        const s = readChildForm(form({ intent: 'save', child_name: 'Mehrin', birth_day: '', birth_month: '8' }));
        expect(s.errors).toEqual({ birth_day: 'childDateMissing' });
        expect(s.draft).toMatchObject({ child_name: 'Mehrin', birth_month: '8' });
        expect(noticeText(s.errors.birth_day, accountEn)).toBe(accountEn.notices.childDateMissing);
        expect(readChildForm(form({ intent: 'save', birth_day: '32', birth_month: '1' })).errors).toEqual({ birth_day: 'childDateMissing' });
    });
});

describe('kids card labels', () => {
    it('shows "Arian · 6 years" and "12 Mar" with Western digits in both locales', () => {
        expect(childTitle({ name: 'Arian', age: 6 }, accountEn.profile.kids)).toBe('Arian · 6 years');
        expect(childTitle({ name: null, age: null }, accountEn.profile.kids)).toBe('Your child');
        expect(birthdayLabel({ birth_month: 8, birth_day: 4 }, accountEn.profile.kids)).toBe('04 Aug');
        expect(childTitle({ name: 'আয়ান', age: 3 }, accountBn.profile.kids)).toBe('আয়ান · 3 বছর');
        expect(birthdayLabel({ birth_month: 3, birth_day: 12 }, accountBn.profile.kids)).toBe('12 মার্চ');
        expect(accountBn.profile.kids.months).toHaveLength(12);
        expect(accountBn.profile.kids.monthsShort).toHaveLength(12);
    });
});

describe('reminder copy', () => {
    it('says it is about the children, that offers are included, how often and how to stop, in both locales', () => {
        const en = accountEn.profile.notifications;
        const bn = accountBn.profile.notifications;
        expect(en.what).toMatch(/each child/);
        expect(en.what).toMatch(/gift code/);
        expect(en.what).toMatch(/Once a year/);
        expect(en.what).toMatch(/SMS/);
        expect(en.stop).toMatch(/switch it off/i);
        expect(bn.what).toMatch(/সন্তানের/);
        expect(bn.what).toMatch(/উপহার কোড/);
        expect(bn.what).toMatch(/এসএমএস/);
        expect(accountEn.profile.kids.intro).toMatch(/first name/);
        for (const text of [...Object.values(bn), accountBn.profile.kids.intro]) expect(text).not.toMatch(/[০-৯]/);
    });
});

describe('never on checkout', () => {
    const files = (dir: string): string[] =>
        readdirSync(dir).flatMap((name) => {
            const path = join(dir, name);
            return statSync(path).isDirectory() ? files(path) : [path];
        });

    it('no checkout page, component or handler asks for the reminder opt-in or a child', () => {
        const checkout = [...files('app/[locale]/checkout'), ...files('components/checkout'), ...files('app/api/checkout')].filter((f) => /\.(tsx?|mjs)$/.test(f));
        expect(checkout.length).toBeGreaterThan(0);
        for (const file of checkout) expect(readFileSync(file, 'utf8'), file).not.toMatch(/birthday_reminders|birthday_messages|date_of_birth|profile\.notifications|KidsCard|account\/children/);
    });
});
