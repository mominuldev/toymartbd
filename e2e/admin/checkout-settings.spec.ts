import { expect, test, type Page } from '@playwright/test';

/*
 * Store settings › Checkout & delivery rules (Figma B · 38) against the real app: the card
 * shows what checkout really does (fixed rows, the bKash advance not available yet, the
 * default courier's zone charges) and the free-delivery threshold saves through
 * PUT /settings/delivery — the same value the courier settings screen shows.
 * SHOTS_DIR=… also saves a screenshot for the Figma check.
 */

const shots = process.env.SHOTS_DIR;

async function signIn(page: Page) {
    await page.goto('/admin/settings/checkout');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
}

test('owner sees the checkout rules and changes the free-delivery threshold', async ({ browser }) => {
    const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
    await signIn(page);

    const card = page.getByRole('form', { name: 'Checkout & delivery rules' });
    await expect(card.getByRole('heading', { name: 'Checkout & delivery rules' })).toBeVisible();
    await expect(card.getByText('Always on')).toHaveCount(3);
    await expect(card.getByText('Not available yet')).toBeVisible();
    await expect(card.getByRole('switch')).toHaveCount(1);
    await expect(card.getByLabel('Inside Dhaka')).toHaveValue('70');
    await expect(card.getByLabel('Dhaka suburbs')).toHaveValue('100');
    await expect(card.getByLabel('Outside Dhaka')).toHaveValue('130');
    if (shots) await card.screenshot({ path: `${shots}/checkout-card.png` });

    const amount = card.getByLabel('Free delivery from (৳)');
    await expect(amount).toHaveValue('3000');
    await amount.fill('3500');
    await card.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Checkout & delivery rules saved')).toBeVisible();
    await expect(card.getByText('Free delivery on orders of ৳3,500 or more')).toBeVisible();

    // One source of truth: the courier settings screen reads the same threshold.
    await page.goto('/admin/integrations/couriers');
    await expect(page.locator('input[value="3500"]').first()).toBeVisible();

    // Put it back so the run leaves the seed value.
    await page.goto('/admin/settings/checkout');
    await page.getByLabel('Free delivery from (৳)').fill('3000');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Checkout & delivery rules saved')).toBeVisible();
});
