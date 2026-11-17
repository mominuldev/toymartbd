import { expect, test, type Page } from '@playwright/test';

/*
 * POS register end to end (Figma B · 33 POS v2) against the real app and the seeded demo
 * branch "Chattogram · Agrabad": open a drawer, ring up a product from the grid, Charge →
 * Collect payment with the server's "Exact" cash, Sale complete, hold and resume a cart,
 * close the drawer. SHOTS_DIR=… also saves screenshots for the Figma check.
 */

const shots = process.env.SHOTS_DIR;

// The Figma frames are 1440 × 900.
test.use({ viewport: { width: 1440, height: 900 } });
async function shot(page: Page, name: string) {
    if (shots) await page.screenshot({ path: `${shots}/pos-${name}.png` });
}

async function signIn(page: Page) {
    await page.goto('/admin/pos');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
}

async function openDrawer(page: Page) {
    const opener = page.getByRole('heading', { name: 'Open a drawer' });
    const register = page.getByRole('heading', { name: / · Register \d+$/ });
    await expect(opener.or(register)).toBeVisible();
    if (await opener.isVisible()) {
        await page.getByLabel('Branch').selectOption({ label: 'Chattogram · Agrabad' });
        await page.getByLabel('Opening float').fill('5000');
        await page.getByRole('button', { name: 'Open drawer' }).click();
    }
    await expect(page.getByRole('heading', { name: /^Chattogram · Agrabad · / })).toBeVisible();
}

test('cashier opens a drawer, sells for cash, holds and resumes a cart, and closes the drawer', async ({ page }) => {
    // Sale complete prints the receipt automatically (print settings default): no dialog in a test run, but fire afterprint like a browser does.
    await page.addInitScript(() => {
        window.print = () => window.dispatchEvent(new Event('afterprint'));
    });
    await signIn(page);
    await openDrawer(page);
    await expect(page.getByText(/^Drawer since .* · float ৳5,000$/)).toBeVisible();

    // A tile with stock here: "N in stock" / "N left".
    const tile = page.getByRole('button', { name: /^Add .*, \d+ (in stock|left)$/ }).first();
    await tile.click();
    await expect(page.getByRole('list', { name: 'Cart' }).getByRole('listitem')).toHaveCount(1);

    await shot(page, 'checkout');
    // Charge opens Collect payment; the total and the change are the server's; "Exact" takes what is still due.
    await page.getByRole('button', { name: /^Charge ৳/ }).click();
    const pay = page.getByRole('dialog', { name: 'Collect payment' });
    await expect(pay).toBeVisible();
    await shot(page, 'payment');
    await pay.getByRole('button', { name: 'Exact' }).click();
    const complete = pay.getByRole('button', { name: 'Complete sale' });
    await expect(complete).toBeEnabled();
    await complete.click();

    await expect(page.getByRole('heading', { name: 'Sale complete' })).toBeVisible();
    await shot(page, 'complete');
    await expect(page.getByRole('button', { name: 'Print receipt' })).toBeEnabled();
    await expect(page.getByRole('button', { name: 'WhatsApp receipt' })).toBeDisabled();
    await expect(page.getByText('WhatsApp receipts need a WhatsApp provider — none is connected yet.')).toBeVisible();

    // Hold a cart, then resume it.
    await page.getByRole('button', { name: 'Start new sale' }).click();
    await tile.click();
    // A cashier's discount: the server prices it and the totals show it with its reason.
    await page.getByRole('button', { name: 'Discount', exact: true }).click();
    const discount = page.getByRole('dialog', { name: 'Discount' });
    await discount.getByLabel('Percent off').fill('5');
    await discount.getByLabel('Reason').selectOption('regular_customer');
    if (shots) await discount.screenshot({ path: `${shots}/pos-discount.png` });
    await discount.getByRole('button', { name: 'Apply discount' }).click();
    await expect(page.getByText('Discount · Regular customer')).toBeVisible();
    await shot(page, 'discounted');

    // Hold opens Held sales: type the title, hold, and the sale is listed under it.
    await page.getByRole('button', { name: 'Hold', exact: true }).click();
    const held = page.getByRole('dialog', { name: 'Held sales' });
    await held.getByLabel('Label').fill('Blue bag — back soon');
    if (shots) await held.screenshot({ path: `${shots}/pos-hold-label.png` });
    await held.getByRole('button', { name: 'Hold sale' }).click();
    await expect(held.getByText('Blue bag — back soon')).toBeVisible();
    await expect(page.getByText('Scan or tap a product to start a sale.')).toBeVisible();
    if (shots) await held.screenshot({ path: `${shots}/pos-held.png` });
    await held.getByRole('button', { name: 'Resume' }).click();
    await expect(page.getByRole('list', { name: 'Cart' }).getByRole('listitem')).toHaveCount(1);

    // Close the drawer: expected = float + cash kept; counted 5000 → short by the sale.
    await page.getByRole('button', { name: 'Close drawer' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'Close drawer' });
    await shot(page, 'close');
    await expect(dialog.getByText('Expected cash in drawer')).toBeVisible();
    await dialog.getByLabel('Counted cash').fill('5000');
    await dialog.getByRole('button', { name: 'Close drawer' }).click();
    await expect(dialog.getByText(/Drawer closed\. Counted ৳5,000 — short by ৳/)).toBeVisible();
    await dialog.getByRole('button', { name: 'Done' }).click();
    await expect(page.getByRole('heading', { name: 'Open a drawer' })).toBeVisible();
});
