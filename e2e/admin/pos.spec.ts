import { expect, test, type Page } from '@playwright/test';

/*
 * POS register end to end (Figma B · 33) against the real app and the seeded demo branch
 * "Chattogram · Agrabad": open a drawer, ring up a product from the grid, pay cash with
 * the server's "Exact" amount, see the change, hold and resume a cart, close the drawer.
 */

async function signIn(page: Page) {
    await page.goto('/admin/pos');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
}

async function openDrawer(page: Page) {
    const opener = page.getByRole('heading', { name: 'Open a drawer' });
    const register = page.getByRole('heading', { name: /^POS · / });
    await expect(opener.or(register)).toBeVisible();
    if (await opener.isVisible()) {
        await page.getByLabel('Branch').selectOption({ label: 'Chattogram · Agrabad' });
        await page.getByLabel('Opening float').fill('5000');
        await page.getByRole('button', { name: 'Open drawer' }).click();
    }
    await expect(page.getByRole('heading', { name: 'POS · Chattogram · Agrabad' })).toBeVisible();
}

test('cashier opens a drawer, sells for cash, holds and resumes a cart, and closes the drawer', async ({ page }) => {
    await signIn(page);
    await openDrawer(page);
    await expect(page.getByText(/Register 1 · cashier .* · drawer opened .* with ৳5,000/)).toBeVisible();

    // A tile with stock here: "N here".
    const tile = page.getByRole('button', { name: /^Add .*, [1-9]\d* here$/ }).first();
    await tile.click();
    await expect(page.getByRole('list', { name: 'Cart' }).getByRole('listitem')).toHaveCount(1);

    // The total and the change are the server's; "Exact" takes what is still due.
    await page.getByRole('button', { name: 'Exact' }).click();
    const charge = page.getByRole('button', { name: /^Charge ৳/ });
    await expect(charge).toBeEnabled();
    await charge.click();
    await expect(page.getByText(/Sale complete · TMB-/)).toBeVisible();
    await expect(page.getByText('Change to give').first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Print receipt' })).toBeEnabled();
    await expect(page.getByRole('button', { name: 'WhatsApp receipt' })).toBeDisabled();
    await expect(page.getByText('WhatsApp receipts need a WhatsApp provider — none is connected yet.')).toBeVisible();

    // Hold a cart, then resume it.
    await page.getByRole('button', { name: 'New sale' }).click();
    await tile.click();
    await page.getByRole('button', { name: 'Hold sale' }).click();
    await expect(page.getByText('Scan or tap a product to start a sale.')).toBeVisible();
    await page.getByRole('button', { name: 'Held (1)' }).click();
    await page.getByRole('dialog', { name: 'Held sales' }).getByRole('button', { name: 'Resume' }).click();
    await expect(page.getByRole('list', { name: 'Cart' }).getByRole('listitem')).toHaveCount(1);

    // Close the drawer: expected = float + cash kept; counted 5000 → short by the sale.
    await page.getByRole('button', { name: 'Close drawer' }).click();
    const dialog = page.getByRole('dialog', { name: 'Close drawer' });
    await expect(dialog.getByText('Expected cash in drawer')).toBeVisible();
    await dialog.getByLabel('Counted cash').fill('5000');
    await dialog.getByRole('button', { name: 'Close drawer' }).click();
    await expect(dialog.getByText(/Drawer closed\. Counted ৳5,000 — short by ৳/)).toBeVisible();
    await dialog.getByRole('button', { name: 'Done' }).click();
    await expect(page.getByRole('heading', { name: 'Open a drawer' })).toBeVisible();
});
