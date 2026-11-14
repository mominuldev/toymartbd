import { expect, test, type Page } from '@playwright/test';

/*
 * Settings › Store (Figma B · 38) against the real app: the section nav, Business saving into
 * the same rows Taxes & invoices prints, and a branch added, edited and switched off — never
 * deleted. The default location cannot be switched off. SHOTS_DIR=… also saves screenshots for
 * the Figma check.
 */

const shots = process.env.SHOTS_DIR;

async function signIn(page: Page) {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto('/admin/settings');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
}

test('owner edits business details once and sees them under Taxes & invoices', async ({ page }) => {
    await signIn(page);
    await expect(page.getByRole('heading', { level: 1, name: 'Store settings' })).toBeVisible();
    const nav = page.getByRole('navigation', { name: 'Store settings sections' });
    await expect(nav.getByRole('link', { name: 'Business' })).toHaveAttribute('aria-current', 'page');

    await page.getByLabel('Store name', { exact: true }).fill('Toy Mart BD');
    await page.getByLabel('দোকানের নাম').fill('টয় মার্ট বিডি');
    await page.getByLabel('Trade licence no.').fill('TRAD/DNCC/045512/2025');
    await page.getByLabel('BIN (VAT registration)').fill('004567891-0101');
    await page.getByLabel('Support hotline').fill('09610-123456');

    // Rule 30: 011 is a defunct prefix.
    await page.getByLabel('WhatsApp').fill('01111-000222');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByLabel('WhatsApp')).toHaveAttribute('aria-invalid', 'true');

    await page.getByLabel('WhatsApp').fill('01711-000222');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('Business details saved. New invoices will use them.')).toBeVisible();
    await expect(page.getByLabel('WhatsApp')).toHaveValue('+880 1711-000222');
    if (shots) await page.screenshot({ path: `${shots}/business.png`, fullPage: true });

    // One value, two screens: Taxes & invoices shows what Business saved.
    await nav.getByRole('link', { name: 'Taxes & invoices' }).click();
    await expect(page.getByText('TRAD/DNCC/045512/2025')).toBeVisible();
    await expect(page.getByText('004567891-0101')).toBeVisible();
    await page.getByLabel(/^Address(?! \()/).fill('House 7, Road 2, Section 10, Mirpur, Dhaka 1216');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('Invoice details saved. New invoices will use them.')).toBeVisible();

    // …and the invoice save left the Business values alone.
    await nav.getByRole('link', { name: 'Business' }).click();
    await expect(page.getByLabel('Trade licence no.')).toHaveValue('TRAD/DNCC/045512/2025');
    await expect(page.getByLabel('WhatsApp')).toHaveValue('+880 1711-000222');
});

test('owner adds, edits and switches off a branch; the default stays on', async ({ page }) => {
    await signIn(page);
    await page.getByRole('navigation', { name: 'Store settings sections' }).getByRole('link', { name: 'Branches & warehouses' }).click();
    await expect(page.getByRole('heading', { name: 'Branches & warehouses' })).toBeVisible();

    await page.getByRole('button', { name: 'Add branch' }).click();
    const add = page.getByRole('dialog', { name: 'Add branch' });
    await add.getByLabel(/^Name(?! \()/).fill('Khulna · Sonadanga');
    await add.getByLabel(/^Short code/).fill('KHL');
    await add.getByLabel(/^Type/).selectOption('retail');
    await add.getByLabel(/^District/).selectOption({ label: 'Khulna' });
    await add.getByLabel(/^Area/).fill('Sonadanga');
    await add.getByLabel(/^Address/).fill('Shop 4, Sonadanga Bus Stand Market');
    await add.getByLabel(/^Landmark/).fill('Opposite the bus stand');
    await add.getByLabel(/^Facts line/).fill('1 register · open 10 am–9 pm');
    if (shots) await page.screenshot({ path: `${shots}/branch-dialog.png` });
    await add.getByRole('button', { name: 'Add branch' }).click();
    await expect(page.getByText('Khulna · Sonadanga added')).toBeVisible();

    const row = page.getByRole('listitem').filter({ hasText: 'Khulna · Sonadanga' });
    await expect(row).toContainText('Retail shop · POS · click & collect');
    await expect(row).toContainText('Shop 4, Sonadanga Bus Stand Market, Sonadanga, Khulna');
    await expect(row).toContainText('Near Opposite the bus stand');
    if (shots) await page.screenshot({ path: `${shots}/branches.png`, fullPage: true });

    // Switched off — still listed, never deleted.
    await page.getByRole('button', { name: 'Edit Khulna · Sonadanga' }).click();
    const edit = page.getByRole('dialog', { name: 'Edit Khulna · Sonadanga' });
    await expect(edit.getByLabel(/^Short code/)).toHaveCount(0);
    await edit.getByLabel('Active').uncheck();
    await edit.getByRole('button', { name: 'Save branch' }).click();
    await expect(page.getByText('Khulna · Sonadanga saved')).toBeVisible();
    await expect(row).toContainText('Switched off');

    // The default location cannot be switched off.
    const first = page.getByRole('listitem').filter({ hasText: 'Default' }).first();
    await first.getByRole('button', { name: /^Edit / }).click();
    await expect(page.getByRole('dialog').getByLabel('Active')).toBeDisabled();
});
