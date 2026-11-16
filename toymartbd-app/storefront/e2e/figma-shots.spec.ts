import { expect, test, type Page } from '@playwright/test';

// Figma comparison screenshots (not a test of behaviour). Run with SHOTS_DIR=<dir>; skipped otherwise.
// Captures every storefront screen that has a Figma frame (page 05 mobile 390, page 06 desktop 1440).
const shots = process.env.SHOTS_DIR;
test.skip(!shots, 'SHOTS_DIR not set');

const PUBLIC: [string, string][] = [
    ['home', '/'],
    ['product', '/products/lego-classic-creative-bricks'],
    ['category', '/categories/building-blocks'],
    ['search', '/search?q=lego'],
    ['track', '/track'],
    ['order', '/orders/TMB-26-00042'],
    ['404', '/products/no-such-toy'],
    ['contact', '/contact'],
    ['privacy', '/privacy-policy'],
    ['terms', '/terms'],
    ['about', '/about'],
    ['maintenance', '/maintenance'],
    ['delivery-policy', '/delivery-policy'],
    ['login', '/account/login'],
];
const ACCOUNT: [string, string][] = [
    ['account-home', '/account'],
    ['account-orders', '/account/orders'],
    ['account-order', '/account/orders/TMB-26-00031'],
    ['account-addresses', '/account/addresses'],
    ['account-wishlist', '/account/wishlist'],
    ['account-profile', '/account/profile'],
];

async function signIn(page: Page) {
    await page.goto('/account/login');
    await page.locator('#phone').fill('01712345678');
    await page.locator('form[action="/api/account/otp"] button[type="submit"]').click();
    await expect(page).toHaveURL(/\/account\/verify$/);
}

for (const [w, h, tag] of [[1440, 900, 'd'], [390, 844, 'm']] as const) {
    test.describe(`${tag}`, () => {
        test.use({ viewport: { width: w, height: h } });

        test(`public pages ${w}`, async ({ page }) => {
            test.setTimeout(240_000);
            for (const [id, path] of PUBLIC) {
                await page.goto(path);
                await page.waitForLoadState('networkidle');
                await page.screenshot({ path: `${shots}/${id}-${w}.png`, fullPage: true });
            }
        });

        test(`cart, checkout ${w}`, async ({ page }) => {
            test.setTimeout(120_000);
            await page.goto('/products/lego-classic-creative-bricks');
            await page.getByRole('button', { name: 'Add to cart', exact: true }).first().click();
            await page.waitForTimeout(1500);
            await page.goto('/cart');
            await page.waitForLoadState('networkidle');
            await page.screenshot({ path: `${shots}/cart-${w}.png`, fullPage: true });
            await page.goto('/checkout');
            await page.waitForLoadState('networkidle');
            await page.screenshot({ path: `${shots}/checkout-${w}.png`, fullPage: true });
        });

        test(`account ${w}`, async ({ page }) => {
            test.setTimeout(180_000);
            await signIn(page);
            await page.screenshot({ path: `${shots}/verify-${w}.png`, fullPage: true });
            for (const [i, digit] of [...'123456'].entries()) await page.locator(`input[name="d${i + 1}"]`).fill(digit);
            await page.locator('button[form="verify-form"]').click();
            await expect(page).toHaveURL(/\/account$/);
            for (const [id, path] of ACCOUNT) {
                await page.goto(path);
                await page.waitForLoadState('networkidle');
                await page.screenshot({ path: `${shots}/${id}-${w}.png`, fullPage: true });
            }
        });
    });
}
