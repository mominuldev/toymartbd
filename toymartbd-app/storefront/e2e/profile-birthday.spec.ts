import { expect, test, type Page } from '@playwright/test';

import { accountBn, accountEn } from '../lib/account/messages';
import { MOCK_API_URL } from './constants';

// P8 — the birthday-message opt-in on the account profile, without JavaScript (a plain form post
// through /api/account/profile). Never pre-ticked; a "yes" needs a date; unticking withdraws.

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

const b = accountEn.profile.birthday;

test('opts in with a date, then withdraws — never pre-ticked', async ({ page }) => {
    await signIn(page);
    await page.goto('/account/profile');

    const box = page.getByLabel(b.optIn);
    await expect(page.getByText(b.what)).toBeVisible();
    await expect(page.getByText(b.stop)).toBeVisible();
    await expect(box).not.toBeChecked();
    await expect(page.locator('#date_of_birth')).toHaveValue('');

    // Ticked without a date: sent back with the box still ticked and the reason under the field.
    await box.check();
    await page.getByRole('button', { name: accountEn.profile.save }).click();
    await expect(page).toHaveURL(/\/account\/profile$/);
    await expect(page.getByText(accountEn.notices.birthdayNeedsDate)).toBeVisible();
    await expect(box).toBeChecked();

    await page.locator('#date_of_birth').fill('1992-02-29');
    await page.getByRole('button', { name: accountEn.profile.save }).click();
    await expect(page.getByText(accountEn.notices.saved)).toBeVisible();
    await expect(box).toBeChecked();
    await expect(page.locator('#date_of_birth')).toHaveValue('1992-02-29');
    await expect(page.getByText(b.on)).toBeVisible();

    await box.uncheck();
    await page.getByRole('button', { name: accountEn.profile.save }).click();
    await expect(page.getByText(accountEn.notices.saved)).toBeVisible();
    await expect(box).not.toBeChecked();
    await expect(page.getByText(/You stopped birthday messages on /)).toBeVisible();
    await expect(page.locator('#date_of_birth')).toHaveValue('1992-02-29');
});

test('shows the birthday card in Bangla with Western digits', async ({ page }) => {
    await signIn(page);
    await page.goto('/bn/account/profile');
    await expect(page.getByText(accountBn.profile.birthday.title, { exact: true })).toBeVisible();
    await expect(page.getByLabel(accountBn.profile.birthday.optIn)).not.toBeChecked();
    await expect(page.getByText(accountBn.profile.birthday.what)).toBeVisible();
    expect(await page.locator('main').innerText()).not.toMatch(/[০-৯]/); // rule 36
});
