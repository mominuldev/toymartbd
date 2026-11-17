import { expect, test } from '@playwright/test';

/*
 * Settings → Integrations hub (Figma B · 37) against the real app on the seeded database:
 * statuses come from stored state (no courier credentials yet, COD always on, nothing built
 * for RedX), the category tabs filter, a courier switches through the existing courier
 * endpoint, and each card links to the screen that configures it.
 * SHOTS_DIR=… also saves screenshots for the Figma check.
 */

const shots = process.env.SHOTS_DIR;

test('owner reads every integration, filters, switches a courier and opens its settings', async ({ browser }) => {
    const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
    await page.goto('/admin/integrations');
    await page.getByLabel('Email').fill('mominulfed@gmail.com');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page.getByRole('heading', { name: 'Integrations', level: 1 })).toBeVisible();
    const steadfast = page.getByRole('listitem', { name: 'Steadfast' });
    await expect(steadfast).toBeVisible();
    // Seeded: no merchant credentials (rule 33a) → not connected, live bookings refused.
    await expect(steadfast.getByText('Not connected')).toBeVisible();
    await expect(steadfast).toContainText('No sandbox · tested on fixtures only');
    await expect(page.getByRole('listitem', { name: 'RedX' }).getByText('Not available yet')).toBeVisible();
    await expect(page.getByRole('listitem', { name: 'Cash on delivery' }).getByText('Connected · live')).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/integrations.png`, fullPage: true });

    // The tab filters in place.
    await page.getByRole('button', { name: /^Payments/ }).click();
    await expect(page.getByRole('listitem', { name: 'Cash on delivery' })).toBeVisible();
    await expect(page.getByRole('listitem', { name: 'Steadfast' })).toHaveCount(0);
    await page.getByRole('button', { name: /^All/ }).click();

    // Pathao is seeded off; switching it on and off goes through PATCH /settings/couriers/{id}.
    const pathao = page.getByRole('listitem', { name: 'Pathao' });
    await pathao.getByRole('switch', { name: 'Turn Pathao on' }).click();
    await expect(page.getByText('Pathao switched on')).toBeVisible();
    await expect(pathao.getByRole('switch', { name: 'Turn Pathao off' })).toHaveAttribute('aria-checked', 'true');
    await pathao.getByRole('switch', { name: 'Turn Pathao off' }).click();
    await expect(pathao.getByRole('switch', { name: 'Turn Pathao on' })).toHaveAttribute('aria-checked', 'false');

    // Connect opens the courier settings screen.
    await steadfast.getByRole('link', { name: 'Connect: Steadfast' }).click();
    await expect(page).toHaveURL(/\/admin\/integrations\/couriers$/);
});

test('Bangla hub keeps Western digits in the tab counts', async ({ browser }) => {
    const page = await (await browser.newContext({ viewport: { width: 768, height: 1000 } })).newPage();
    await page.goto('/admin/integrations');
    await page.getByLabel('Email').fill('mominulfed@gmail.com');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Integrations', level: 1 })).toBeVisible();

    await page.getByRole('button', { name: 'বাং' }).first().click();
    await expect(page.getByRole('heading', { name: 'ইন্টিগ্রেশন', level: 1 })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'ইন্টিগ্রেশনের ধরন' })).toContainText(/সব\s*\d+/);
    if (shots) await page.screenshot({ path: `${shots}/integrations-bn-768.png`, fullPage: true });
});
