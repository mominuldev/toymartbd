import { expect, test, type Page } from '@playwright/test';

/*
 * Shipments & tracking (Figma B · 08) against the real app. serve.sh seeds no demo order
 * history, so the board is empty: every tile must say why it has no figure ("—" plus a
 * reason, never a 0 %), the tabs carry server counts, filters round-trip through the URL as
 * allowlisted `filter[…]` parameters, and the issues panel shows its all-clear state rather
 * than placeholder bars. SHOTS_DIR=… also saves screenshots at 1440 and 768.
 */

const shots = process.env.SHOTS_DIR;

async function signIn(page: Page) {
    await page.goto('/admin/shipments');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
}

test('owner opens the shipments board: honest empty figures, tabs and filters', async ({ browser }) => {
    const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
    await signIn(page);

    await expect(page.getByRole('heading', { name: 'Shipments', level: 1 })).toBeVisible();
    await expect(page.getByText('No parcel was delivered or returned in this range')).toBeVisible();
    await expect(page.getByText('No parcel was delivered in this range')).toBeVisible();
    await expect(page.getByText('Every booking has a consignment ID')).toBeVisible();
    await expect(page.getByText('0%')).toHaveCount(0);

    const tabs = page.getByRole('navigation', { name: 'Shipment status' });
    for (const name of ['All', 'Submitted', 'In transit', 'Delivered', 'Returning / returned', 'Failed attempts']) {
        await expect(tabs.getByRole('button', { name: new RegExp(`^${name}\\s*0$`) })).toBeVisible();
    }
    await expect(page.getByText('No shipments here')).toBeVisible();
    await expect(page.getByText('No delivery issues in the last 30 days')).toBeVisible();
    await expect(page.getByText('No parcel was delivered or returned in the last 30 days.')).toBeVisible();
    if (shots)
        await page.screenshot({
            path: `${shots}/shipments-1440.png`,
            fullPage: true,
        });

    // Filters go to the server as allowlisted filter[…] parameters.
    const board = page.waitForRequest((r) => r.url().includes('/api/v1/admin/shipments/board?') && r.url().includes('filter%5Btab%5D=in_transit'));
    await tabs.getByRole('button', { name: /^In transit/ }).click();
    await board;
    await expect(page).toHaveURL(/tab=in_transit/);

    const ranged = page.waitForRequest((r) => r.url().includes('/api/v1/admin/shipments/board/stats?') && r.url().includes('filter%5Bfrom%5D='));
    await page.getByLabel('Date range').selectOption('30d');
    await ranged;
    await expect(page).toHaveURL(/range=30d/);

    const searched = page.waitForRequest((r) => r.url().includes('filter%5Bsearch%5D=SHP-26'));
    await page.getByLabel('Search shipments').fill('SHP-26');
    await searched;

    // An unlisted filter is the server's 400 (rule 16).
    const status = await page.evaluate(async () => (await fetch('/api/v1/admin/shipments/board?filter[status]=booked', { headers: { Accept: 'application/json' } })).status);
    expect(status).toBe(400);
});

test('the board reads in Bangla with Western digits', async ({ browser }) => {
    const page = await (await browser.newContext({ viewport: { width: 768, height: 1000 } })).newPage();
    await signIn(page);
    await page.getByRole('button', { name: 'বাং' }).first().click();

    await expect(page.getByRole('heading', { name: 'শিপমেন্ট', level: 1 })).toBeVisible();
    await expect(page.getByText('গত 30 দিন', { exact: true })).toBeVisible();
    if (shots)
        await page.screenshot({
            path: `${shots}/shipments-768-bn.png`,
            fullPage: true,
        });
});
