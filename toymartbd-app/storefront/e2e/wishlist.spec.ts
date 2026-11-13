import { expect, test, type Page } from '@playwright/test';

import { en } from '../lib/i18n/en';
import { MOCK_API_URL } from './constants';

// Wishlist hearts (storefront spec §A22) on product cards and the product page. The pages stay the
// same cached HTML for everyone: without JavaScript a heart is a form post (a guest goes to sign-in
// and back); with it, the saved state is painted from /api/account/wishlist and toggled in place.
// The mock customer starts with products 101 and 102 saved.

test.beforeEach(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

const heart = (page: Page, id: number) => page.locator(`button[data-wishlist-heart="${id}"]:visible`).first();

async function signIn(page: Page) {
    await page.goto('/account/login');
    await page.locator('#phone').fill('01712345678');
    await page.locator('form[action="/api/account/otp"] button[type="submit"]').click();
    await expect(page).toHaveURL(/\/account\/verify$/);
    for (const [i, digit] of [...'123456'].entries()) await page.locator(`input[name="d${i + 1}"]`).fill(digit);
    await page.locator('button[form="verify-form"]').click();
    await expect(page).toHaveURL(/\/account$/);
}

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('a guest’s heart goes to sign-in and comes back to the product', async ({ page }) => {
        await page.goto('/products/lego-city-fire-station');
        await heart(page, 102).click();
        await expect(page).toHaveURL(/\/account\/login\?next=%2Fproducts%2Flego-city-fire-station$/);
        await expect(page.locator('input[name="next"]')).toHaveValue('/products/lego-city-fire-station');
    });

    test('a signed-in heart saves the toy and shows the wishlist', async ({ page }) => {
        await signIn(page);
        await page.goto('/search?q=lego');
        await heart(page, 103).click();
        await expect(page).toHaveURL(/\/account\/wishlist$/);
        await expect(page.locator('button[data-wishlist-heart="103"]').first()).toBeAttached();
    });
});

test.describe('with JavaScript', () => {
    test('a guest’s heart sends them to sign-in with this page as next', async ({ page }) => {
        await page.goto('/search?q=lego');
        await expect(heart(page, 103)).toHaveAttribute('aria-pressed', 'false');
        await heart(page, 103).click();
        await expect(page).toHaveURL(/\/account\/login\?next=%2Fsearch$/);
    });

    test('saved hearts are filled, and toggle in place on cards and the product page', async ({ page }) => {
        await signIn(page);
        await page.goto('/search?q=lego');
        await expect(heart(page, 101)).toHaveAttribute('aria-pressed', 'true');
        await expect(heart(page, 103)).toHaveAttribute('aria-pressed', 'false');

        await heart(page, 103).click();
        await expect(heart(page, 103)).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByRole('status').filter({ hasText: en.wishlist.saved })).toBeAttached();
        await expect(page).toHaveURL(/\/search\?q=lego$/);

        await heart(page, 101).click();
        await expect(heart(page, 101)).toHaveAttribute('aria-pressed', 'false');

        // The product page is the cached page; its hearts pick the state up client-side.
        await page.goto('/products/lego-technic-race-car');
        await expect(heart(page, 103)).toHaveAttribute('aria-pressed', 'true');
        await page.goto('/products/lego-classic-creative-bricks');
        await expect(heart(page, 101)).toHaveAttribute('aria-pressed', 'false');
    });
});
