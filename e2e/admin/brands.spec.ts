import { expect, test, type Page } from '@playwright/test';

async function signIn(page: Page) {
    await page.goto('/admin/products/brands');
    await expect(page.getByRole('heading', { name: 'Sign in to the admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Brands' })).toBeVisible();
}

test('owner signs in, then creates, edits and archives a brand', async ({ page }) => {
    await signIn(page);

    // Seeded demo data is listed, with its Bangla name.
    const table = page.getByRole('table');
    await expect(table.getByRole('link', { name: 'LEGO', exact: true })).toBeVisible();

    // Create — empty name is caught by the generated schema before any request.
    await page.getByRole('link', { name: 'New brand' }).click();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('This field is required.')).toBeVisible();

    await page.getByLabel('Name (English)').fill('Rainbow Blocks');
    await page.getByLabel('Name (Bangla)').fill('রংধনু ব্লক');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('heading', { name: 'Edit brand' })).toBeVisible();
    await expect(page.getByText('Brand created.')).toBeVisible();
    await expect(page.getByLabel('URL slug')).toHaveValue('rainbow-blocks');

    // Edit — a duplicate slug comes back from the server as a field error.
    await page.getByLabel('URL slug').fill('lego');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByLabel('URL slug')).toHaveAttribute('aria-invalid', 'true');

    await page.getByLabel('URL slug').fill('rainbow-blocks');
    await page.getByLabel('Name (English)').fill('Rainbow Blocks Junior');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Changes saved.')).toBeVisible();

    // Archive — the confirm button stays disabled until the name is typed.
    await page.getByRole('button', { name: 'Archive brand' }).click();
    const dialog = page.getByRole('dialog');
    const confirm = dialog.getByRole('button', { name: 'Archive brand' });
    await expect(confirm).toBeDisabled();
    await dialog.getByLabel('Type Rainbow Blocks Junior to confirm').fill('Rainbow Blocks Junior');
    await confirm.click();

    await expect(page.getByText('Brand archived.')).toBeVisible();
    await expect(page.getByRole('table').getByRole('link', { name: 'Rainbow Blocks Junior', exact: true })).toHaveCount(0);

    await page.getByRole('button', { name: 'Archived' }).click();
    await expect(page.getByRole('table').getByRole('link', { name: 'Rainbow Blocks Junior', exact: true })).toBeVisible();
});

test('the same screens work in Bangla', async ({ page }) => {
    await signIn(page);
    await page.getByRole('button', { name: 'বাং' }).first().click();

    await expect(page.getByRole('heading', { name: 'ব্র্যান্ড' })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'bn');

    await page.getByRole('link', { name: 'নতুন ব্র্যান্ড' }).click();
    await page.getByRole('button', { name: 'সংরক্ষণ' }).click();
    await expect(page.getByText('এই ঘরটি পূরণ করতে হবে।')).toBeVisible();
});
