import { expect, test, type Page } from '@playwright/test';

import { accountBn, accountEn } from '../lib/account/messages';
import { MOCK_API_URL } from './constants';

// "Order updates on WhatsApp" and "SMS updates" on the account profile (Figma 239:13856), without
// JavaScript: plain checkboxes in the "Notifications & language" form, posted through
// /api/account/profile to PUT /storefront/account/notification-preferences. On by default; unticking
// turns a channel off; sign-in codes are never offered as a switch.

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

const n = accountEn.profile.notifications;

test('turns WhatsApp order updates off and back on — on by default, SMS untouched', async ({ page }) => {
    await signIn(page);
    await page.goto('/account/profile');

    const card = page.locator('#notifications');
    const whatsapp = card.getByRole('switch', { name: new RegExp(n.orderWhatsapp) });
    const sms = card.getByRole('switch', { name: new RegExp(n.orderSms) });
    await expect(whatsapp).toBeChecked();
    await expect(sms).toBeChecked();
    await expect(card.getByText(n.orderHow)).toBeVisible();
    await expect(card.getByRole('switch')).toHaveCount(3); // WhatsApp, SMS, birthday — never sign-in codes
    await expect(card.getByText(n.orderNone)).toHaveCount(0);

    // The sr-only checkbox is toggled through its visible label (a real no-JS form post).
    await card.locator('label:has(input[name="order_updates_whatsapp"])').click();
    await card.getByRole('button', { name: n.save }).click();
    await expect(page.getByText(accountEn.notices.saved)).toBeVisible();
    await expect(whatsapp).not.toBeChecked();
    await expect(sms).toBeChecked();

    // Both off: the page says no order messages will come.
    await card.locator('label:has(input[name="order_updates_sms"])').click();
    await card.getByRole('button', { name: n.save }).click();
    await expect(sms).not.toBeChecked();
    await expect(card.getByText(n.orderNone)).toBeVisible();

    await card.locator('label:has(input[name="order_updates_whatsapp"])').click();
    await card.locator('label:has(input[name="order_updates_sms"])').click();
    await card.getByRole('button', { name: n.save }).click();
    await expect(whatsapp).toBeChecked();
    await expect(sms).toBeChecked();

    // The birthday consent is still never pre-ticked by saving the order switches.
    await expect(card.getByRole('switch', { name: new RegExp(n.reminders) })).not.toBeChecked();
});

test('Bangla labels for the order-update switches', async ({ page }) => {
    await signIn(page);
    await page.goto('/bn/account/profile');
    const b = accountBn.profile.notifications;
    const card = page.locator('#notifications');
    await expect(card.getByRole('switch', { name: new RegExp(b.orderWhatsapp) })).toBeChecked();
    await expect(card.getByRole('switch', { name: new RegExp(b.orderSms) })).toBeChecked();
});
