import { expect, test, type Page } from '@playwright/test';

import { accountBn, accountEn } from '../lib/account/messages';
import { MOCK_API_URL } from './constants';

// P8 batch 3 — "My kids" and the "Birthday reminders and offers" switch on the account profile
// (Figma 239:13856), without JavaScript: plain form posts through /api/account/children and
// /api/account/profile. Never pre-ticked; switching off withdraws; no date of birth for the customer.

test.use({ javaScriptEnabled: false });

test.beforeEach(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

async function signIn(page: Page) {
    await page.goto('/account/login');
    await page.locator('#phone').fill('01712345678');
    await page.locator('form[action="/api/account/otp"] button[type="submit"]').click();
    await expect(page).toHaveURL(/\/account\/verify$/);
    for (const [i, digit] of [...'123456'].entries()) await page.locator(`input[name="d${i + 1}"]`).fill(digit);
    await page.locator('button[form="verify-form"]').click();
    await expect(page).toHaveURL(/\/account$/);
}

const k = accountEn.profile.kids;
const n = accountEn.profile.notifications;

test('adds, edits and removes a child without JavaScript', async ({ page }) => {
    await signIn(page);
    await page.goto('/account/profile');

    const kids = page.locator('#kids');
    await expect(kids.getByRole('heading', { name: k.title })).toBeVisible();
    await expect(kids.getByText(k.empty)).toBeVisible();
    await expect(page.locator('#date_of_birth')).toHaveCount(0); // the customer's own birthday is gone

    await kids.getByText(k.add, { exact: true }).click();
    const again = kids.getByRole('form', { name: k.addTitle });
    await again.getByLabel(k.name).fill('Arian');
    await again.getByLabel(k.month).selectOption('3');
    await again.getByLabel(k.day).selectOption('12');
    await again.getByLabel(k.year).selectOption('2020');
    await again.getByRole('button', { name: k.save }).click();
    await expect(page.getByText(accountEn.notices.childSaved)).toBeVisible();
    await expect(kids.getByText('Arian · 6 years')).toBeVisible();
    await expect(kids.getByText('Birthday 12 Mar')).toBeVisible();

    // 31 February is refused by the API and shown next to the form.
    await kids.getByText(k.add, { exact: true }).click();
    const bad = kids.getByRole('form', { name: k.addTitle });
    await bad.getByLabel(k.day).selectOption('31');
    await bad.getByLabel(k.month).selectOption('2');
    await bad.getByRole('button', { name: k.save }).click();
    await expect(kids.getByText('That birthday is not a real date. Check the day, month and year.')).toBeVisible();
    // The form re-opens with what was typed.
    await expect(kids.getByRole('form', { name: k.addTitle }).getByLabel(k.day)).toHaveValue('31');

    // Edit: drop the year.
    await kids.locator('summary[aria-label="Edit Arian"]').click();
    const edit = kids.getByRole('form', { name: 'Edit Arian' });
    await edit.getByLabel(k.year).selectOption('');
    await edit.getByRole('button', { name: k.save }).click();
    await expect(kids.getByText('Arian', { exact: true })).toBeVisible();

    await kids.getByRole('button', { name: 'Remove Arian' }).click();
    await expect(page.getByText(accountEn.notices.childRemoved)).toBeVisible();
    await expect(kids.getByText(k.empty)).toBeVisible();
});

test('switches birthday reminders on and off — never pre-ticked', async ({ page }) => {
    await signIn(page);
    await page.goto('/account/profile');

    const card = page.locator('#notifications');
    const toggle = card.getByRole('switch', { name: new RegExp(n.reminders) });
    await expect(card.getByText(n.what)).toBeVisible();
    await expect(card.getByText(n.stop)).toBeVisible();
    await expect(toggle).not.toBeChecked();

    await card.locator('label:has(input[name="birthday_reminders"])').click();
    await card.getByRole('button', { name: n.save }).click();
    await expect(page.getByText(accountEn.notices.saved)).toBeVisible();
    await expect(toggle).toBeChecked();
    await expect(card.getByText(n.on)).toBeVisible();
    await expect(card.getByText(n.needsKids)).toBeVisible(); // nothing to remind about yet

    await card.locator('label:has(input[name="birthday_reminders"])').click();
    await card.getByRole('button', { name: n.save }).click();
    await expect(page.getByText(accountEn.notices.saved)).toBeVisible();
    await expect(toggle).not.toBeChecked();
    await expect(card.getByText(/You stopped birthday reminders on /)).toBeVisible();
});

test('shows My kids and the switch in Bangla with Western digits', async ({ page }) => {
    await signIn(page);
    await page.goto('/bn/account/profile');
    await expect(page.getByRole('heading', { name: accountBn.profile.kids.title })).toBeVisible();
    await expect(page.getByRole('switch', { name: new RegExp(accountBn.profile.notifications.reminders) })).not.toBeChecked();
    await expect(page.getByText(accountBn.profile.notifications.what)).toBeVisible();
    expect(await page.locator('main').innerText()).not.toMatch(/[০-৯]/); // rule 36
});
