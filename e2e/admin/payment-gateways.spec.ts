import { expect, test, type Page } from '@playwright/test';

/*
 * Integrations › Payments (Figma B · 37a) against the real app on the seeded database: the
 * owner saves SSLCommerz sandbox keys (the footer then says who saved), the IPN URL comes from
 * the API, a live switch without live keys is refused on Environment, and PayStation opens from
 * its tile and the URL. SHOTS_DIR=… also saves screenshots at 1440, 1920 and 390 for the Figma check.
 */

const shots = process.env.SHOTS_DIR;

async function signIn(page: Page, path: string) {
    await page.goto(path);
    await page.getByLabel('Email').fill('mominulfed@gmail.com');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
}

test('owner sets up SSLCommerz sandbox keys and reads PayStation', async ({ browser }) => {
    const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
    await signIn(page, '/admin/integrations/payments');

    await expect(page.getByRole('heading', { name: 'Payments', level: 1 })).toBeVisible();
    const ssl = page.getByRole('form', { name: 'SSLCommerz' });
    await expect(ssl).toBeVisible();
    await expect(ssl.locator('[data-ipn-url="sslcommerz"]')).toHaveText('http://localhost:8124/api/v1/webhooks/payments/sslcommerz/ipn');
    await expect(ssl.getByText('sandbox.sslcommerz.com')).toBeVisible();

    // Live without live keys: the server refuses, the message sits on Environment.
    await ssl.getByRole('radio', { name: /Live/ }).check({ force: true });
    await ssl.getByRole('button', { name: 'Save changes' }).click();
    await expect(ssl.getByText('Enter every live key before switching to live mode.')).toBeVisible();
    if (shots) await ssl.screenshot({ path: `${shots}/payments-422.png` });
    await ssl.getByRole('button', { name: 'Discard' }).click();

    // Sandbox keys, saved: the footer now names who saved.
    await ssl.getByLabel('Store ID').fill('testbox');
    await ssl.getByLabel('Store Password').fill('qwerty');
    await expect(ssl.getByText('2 unsaved changes')).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/payments-dirty.png`, fullPage: true });
    await ssl.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('SSLCommerz settings saved')).toBeVisible();
    await expect(ssl.getByText(/Last saved .* by /)).toBeVisible();
    await expect(ssl.getByText('Saved', { exact: true })).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/payments-sslcommerz-1440.png`, fullPage: true });

    await page.getByRole('button', { name: 'PayStation settings' }).click();
    await expect(page).toHaveURL(/\?gateway=paystation$/);
    await expect(page.getByRole('form', { name: 'PayStation' })).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/payments-paystation-1440.png`, fullPage: true });

    if (shots) {
        await page.setViewportSize({ width: 1920, height: 1080 });
        await page.getByRole('button', { name: 'SSLCommerz settings' }).click();
        await page.screenshot({ path: `${shots}/payments-sslcommerz-1920.png`, fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({ path: `${shots}/payments-sslcommerz-390.png`, fullPage: true });
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto('/admin/integrations');
        await expect(page.getByRole('listitem', { name: 'SSLCommerz' })).toBeVisible();
        await page.screenshot({ path: `${shots}/integrations-hub.png`, fullPage: true });
    }
});
