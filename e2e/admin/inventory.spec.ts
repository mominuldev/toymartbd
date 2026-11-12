import { expect, test, type Page } from '@playwright/test';

async function signIn(page: Page) {
    await page.goto('/admin/inventory');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Inventory', exact: true })).toBeVisible();
}

test('owner records damage on a variant; the ledger shows it and still matches the stock levels', async ({ page }) => {
    await signIn(page);

    // The demo catalogue carries opening stock (DemoCatalogSeeder), so the ledger is balanced from the start.
    await page.getByRole('searchbox', { name: 'Search stock' }).fill('LEGO Classic');
    const first = page.getByRole('row', { name: /LEGO Classic Creative Bricks/ }).first();
    await expect(first).toBeVisible();
    // Pin the row by its SKU (the product has several variants).
    const sku = (await first.locator('.t-admin-mono-sm').innerText()).trim();
    const row = page.getByRole('row').filter({ hasText: sku });
    await expect(row).toHaveCount(1);
    const onHandBefore = Number((await row.getByRole('cell').nth(1).innerText()).replace(/\D/g, ''));
    expect(onHandBefore).toBeGreaterThan(1);

    await row.getByRole('button', { name: 'Adjust' }).click();
    const dialog = page.getByRole('dialog', { name: 'Stock adjustment' });
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('Reason').selectOption('damage');
    await dialog.getByLabel(/Change in units/).fill('1');
    await dialog.getByLabel(/Note/).fill('Crushed box — বাক্স ভাঙা');
    await dialog.getByRole('button', { name: 'Record adjustment' }).click();
    await expect(page.getByText(/^Adjustment ADJ-\d{4}-\d{4} recorded$/)).toBeVisible();
    await expect(row.getByRole('cell').nth(1)).toHaveText(new RegExp(`${onHandBefore - 1}$`));

    // A reason that only removes stock cannot add it: the server refuses the line.
    await row.getByRole('button', { name: 'Adjust' }).click();
    await dialog.getByLabel('Reason').selectOption('correction');
    await dialog.getByLabel(/Change in units/).fill(`-${onHandBefore * 10}`);
    await dialog.getByRole('button', { name: 'Record adjustment' }).click();
    await expect(dialog.getByText(/reserved for open orders|unchanged|can only/)).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel' }).click();

    await row.getByRole('link', { name: 'Ledger' }).click();
    await expect(page.getByRole('heading', { name: 'Stock movements' })).toBeVisible();
    const ledger = page.getByRole('table');
    await expect(ledger.getByRole('row').nth(1)).toContainText('Damage out');
    await expect(ledger.getByRole('row').nth(1)).toContainText('−1');
    await expect(ledger.getByRole('row').nth(1)).toContainText(/ADJ-\d{4}-\d{4}/);
    await expect(page.getByText(/^Ledger matches stock levels for all \d+ variants$/)).toBeVisible();

    // Bangla: same screen, Western digits.
    await page.getByRole('button', { name: 'বাং' }).click();
    await expect(page.getByRole('heading', { name: 'স্টক মুভমেন্ট' })).toBeVisible();
    await expect(page.getByText(/^লেজার ও স্টক লেভেল সব \d+টি ভ্যারিয়েন্টে মিলেছে$/)).toBeVisible();
});
