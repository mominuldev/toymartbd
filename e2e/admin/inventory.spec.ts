import { expect, test, type Page } from '@playwright/test';

async function signIn(page: Page) {
    await page.goto('/admin/inventory');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('mominulfed@gmail.com');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Inventory', exact: true })).toBeVisible();
}

/** The stock-state tabs (a KPI card is also labelled "Low stock"). */
const tabs = (page: Page) => page.getByRole('group', { name: 'Stock state' });

test('three branches (DemoInventorySeeder): low rows offer Create PO and Transfer', async ({ page }) => {
    await signIn(page);

    await expect(page.getByText(/^Across 3 branches · /)).toBeVisible();
    await expect(page.getByRole('main').getByRole('button', { name: /Dhaka HQ · Mirpur/ })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('button', { name: /Transfer stock/ })).toBeEnabled();
    // The nav badge is the server's low-stock count at Dhaka HQ, the same as the Low stock tab.
    await expect(page.getByRole('link', { name: /Inventory.*3 low on stock/ }).first()).toBeAttached();
    await tabs(page).getByRole('button', { name: /^Low stock\s*3$/ }).click();
    await expect(page).toHaveURL(/state=low/);

    // The Barbie doll: 3 at Dhaka, 4 available in Chattogram (seeded opening balances).
    const barbie = page.getByRole('row', { name: /Barbie/ });
    await expect(barbie).toContainText('Chattogram · Agrabad: 4 available');
    await expect(barbie).toContainText('Sylhet pickup point: 0 available');
    // A low row's ⋯ menu offers Create PO and a transfer from the branch that has stock.
    await barbie.getByRole('button', { name: /^Actions for Barbie/ }).click();
    await expect(page.getByRole('menuitem', { name: 'Create PO' })).toHaveAttribute('href', /\/purchasing\/new\?sku=/);
    await expect(page.getByRole('menuitem', { name: /^Transfer 4 from Chattogram/ })).toBeEnabled();
    await page.keyboard.press('Escape');

    // Selecting Chattogram scopes the page; the URL carries it.
    await page.getByRole('main').getByRole('button', { name: /Chattogram · Agrabad/ }).click();
    await expect(page).toHaveURL(/location=\d+/);
    await expect(page.getByRole('main').getByRole('button', { name: /Chattogram · Agrabad/ })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('columnheader', { name: 'Other branches' })).toBeVisible();
});

test('owner records damage on a variant; the ledger shows it and still matches the stock levels', async ({ page }) => {
    await signIn(page);

    // The LEGO 484 box variant is low at Dhaka HQ (9 on hand, reorder at 12).
    // Name the seeded SKU instead of taking "the first LEGO row": right after the tab
    // click the table can still show All variants, which picked a healthy variant that
    // the Low stock list then no longer contained (flaky on CI).
    const sku = 'TMB-LEG-CL484';
    await tabs(page).getByRole('button', { name: /^Low stock/ }).click();
    await expect(page.getByRole('row').filter({ hasText: 'TM-00001-90PCS-BOX' })).toHaveCount(0);
    const row = page.getByRole('row').filter({ hasText: sku });
    await expect(row).toHaveCount(1);
    // Cells: product · status · on hand …
    const onHandBefore = Number((await row.getByRole('cell').nth(2).innerText()).replace(/\D/g, ''));
    expect(onHandBefore).toBeGreaterThan(1);

    // Adjusting lives in the header (Figma's rows carry no Adjust): search, pick, record.
    const dialog = page.getByRole('dialog', { name: 'Stock adjustment' });
    const pick = async () => {
        await page.getByRole('button', { name: 'New adjustment' }).click();
        await expect(dialog).toBeVisible();
        await dialog.getByRole('searchbox', { name: /Search by name or SKU/ }).fill(sku);
        await dialog.getByRole('button', { name: new RegExp(sku) }).click();
    };
    await pick();
    await dialog.getByLabel('Reason').selectOption('damage');
    await dialog.getByLabel(/Change in units/).fill('1');
    await dialog.getByLabel(/Note/).fill('Crushed box — বাক্স ভাঙা');
    await dialog.getByRole('button', { name: 'Record adjustment' }).click();
    await expect(page.getByText(/^Adjustment ADJ-\d{4}-\d{4} recorded$/)).toBeVisible();
    await expect(row.getByRole('cell').nth(2)).toHaveText(new RegExp(`${onHandBefore - 1}$`));

    // A reason that only removes stock cannot add it: the server refuses the line.
    await pick();
    await dialog.getByLabel('Reason').selectOption('correction');
    await dialog.getByLabel(/Change in units/).fill(`-${onHandBefore * 10}`);
    await dialog.getByRole('button', { name: 'Record adjustment' }).click();
    await expect(dialog.getByText(/reserved for open orders|unchanged|can only/)).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel' }).click();

    // A row's ⋯ → View ledger opens the movements ledger; clearing its variant shows every movement.
    await tabs(page).getByRole('button', { name: /^All variants/ }).click();
    await page.getByRole('button', { name: /^Actions for / }).first().click();
    await page.getByRole('menuitem', { name: 'View ledger' }).click();
    await expect(page.getByRole('heading', { name: 'Stock movements' })).toBeVisible();
    await page.getByRole('button', { name: 'Show every variant' }).click();
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
