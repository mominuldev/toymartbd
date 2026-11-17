import { expect, test, type Page } from '@playwright/test';

/*
 * Settings › Integrations › Couriers premium pass (Figma B · 41 513:77225) on the real app:
 * whose prices checkout uses, each courier as a checklist, Steadfast's and Pathao's connection
 * steps, and the charges as sentences saved round trip. "Check connection" is not pressed here:
 * it would call the real courier (tests/Feature/Fulfilment/CourierConnectionCheckTest covers it).
 * SHOTS_DIR=… also saves 1440 and 390 screenshots for the Figma check.
 */
const shots = process.env.SHOTS_DIR;

async function signIn(page: Page) {
    await page.goto('/admin/integrations/couriers');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Couriers', exact: true, level: 1 })).toBeVisible();
}

test('couriers: checklist cards, connection steps per courier, charges saved as sentences', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await signIn(page);

    const cards = page.getByRole('list', { name: 'Couriers' });
    const steadfast = cards.getByRole('article', { name: 'Steadfast' });
    await expect(steadfast.getByRole('list', { name: 'Steadfast setup' })).toContainText('Account connected');
    await expect(page.getByText(/Customers pay delivery by .*’s prices/)).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Get your keys from Steadfast' })).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/couriers-steadfast-connection-1440.png`, fullPage: true });

    await page.getByRole('button', { name: 'Delivery charges' }).click();
    const outside = page.getByLabel('Outside Dhaka');
    const before = await outside.inputValue();
    await outside.fill(String(Number(before) + 5));
    await page.getByRole('button', { name: 'Save charges' }).click();
    await expect(page.getByText('Steadfast rate card saved')).toBeVisible();
    await expect(steadfast.getByText(/Checked and saved on/)).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/couriers-steadfast-charges-1440.png`, fullPage: true });
    await outside.fill(before);
    await page.getByRole('button', { name: 'Save charges' }).click();
    await expect(page.getByText('Steadfast rate card saved').last()).toBeVisible();

    await cards.getByRole('article', { name: 'Pathao' }).getByRole('button', { name: /Set up|Manage/ }).click();
    await page.getByRole('button', { name: 'Connection' }).click();
    await expect(page.getByRole('heading', { name: 'Choose your pickup store' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Match your areas to Pathao zones' })).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/couriers-pathao-connection-1440.png`, fullPage: true });

    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByRole('heading', { name: 'Couriers', exact: true, level: 1 })).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/couriers-390.png`, fullPage: true });
});
