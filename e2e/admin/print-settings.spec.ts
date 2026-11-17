import { expect, test, type Page } from '@playwright/test';

/**
 * Settings › Invoices, receipts & labels (Figma 528:81351, 529:81638, 529:82666): each tab
 * renders its options and live preview. SHOTS_DIR=… saves screenshots for the Figma check.
 */

const shots = process.env.SHOTS_DIR;

async function signIn(page: Page) {
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill('mominulfed@gmail.com');
    await page.locator('input[type="password"]').fill('password');
    await page.locator('button[type="submit"]').click();
    await expect(page).not.toHaveURL(/\/login/);
}

test.use({ viewport: { width: 1440, height: 1100 } });

test('the three print tabs show their options and previews', async ({ page }) => {
    await signIn(page);

    await page.goto('/admin/settings/invoice');
    await expect(page.getByRole('heading', { name: 'Paper and language' })).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/print-invoice.png`, fullPage: true });

    await page.goto('/admin/settings/invoice/receipt');
    await expect(page.getByRole('heading', { name: 'Printer and paper' })).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/print-receipt.png`, fullPage: true });

    await page.goto('/admin/settings/invoice/labels');
    const label = page.getByRole('img', { name: 'Sample label' });
    await expect(label).toBeVisible();
    if (shots) {
        await page.screenshot({ path: `${shots}/print-labels.png`, fullPage: true });
        await label.screenshot({ path: `${shots}/print-label-only.png` });
    }
    // The bars reach the bottom padding: no blank band under the barcode.
    const box = (await label.boundingBox())!;
    const bars = (await label.locator('[data-part="bars"]').boundingBox())!;
    expect(box.y + box.height - (bars.y + bars.height)).toBeLessThan(12);
});
