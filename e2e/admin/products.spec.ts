import { expect, test, type Page } from '@playwright/test';

async function signIn(page: Page) {
    await page.goto('/admin/products');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Products', exact: true })).toBeVisible();
}

test('owner creates a Color × Size product, edits one variant price, and it survives a reload', async ({ page }) => {
    await signIn(page);

    await page.getByRole('link', { name: 'Add product' }).click();
    await page.getByLabel('Name (English)').fill('Rainbow Stacking Rings');
    await page.getByLabel(/Name \(Bangla\)/).fill('রংধনু স্ট্যাকিং রিং');
    await page.getByRole('checkbox', { name: 'Puzzles & Games', exact: true }).check();
    await page.getByLabel('Product type').selectOption('variable');

    // Seeded variant-defining attributes (CatalogReferenceSeeder): Color and Size.
    await page.getByRole('tab', { name: /Variants/ }).click();
    await page.getByLabel('Add attribute').selectOption({ label: 'Color' });
    await page.getByLabel('Add a Color value').selectOption({ label: 'Red' });
    await page.getByLabel('Add a Color value').selectOption({ label: 'Blue' });
    await page.getByLabel('Add attribute').selectOption({ label: 'Size' });
    await page.getByLabel('Add a Size value').selectOption({ label: 'Small' });
    await page.getByLabel('Add a Size value').selectOption({ label: 'Medium' });
    await page.getByLabel('Add a Size value').selectOption({ label: 'Large' });
    await expect(page.getByRole('switch')).toHaveCount(6);

    // One price for every combination, then save: the server generates the SKUs.
    await page.getByLabel('Value to apply').fill('999');
    await page.getByRole('button', { name: 'Apply' }).click();
    await page.getByRole('button', { name: 'Create product' }).click();
    await expect(page.getByText('Product created.')).toBeVisible();
    await expect(page).toHaveURL(/\/admin\/products\/\d+\?tab=variants$/);
    await expect(page.getByLabel('SKU — Blue / Medium')).toHaveValue(/^TM-\d{5}-BLUE-MEDIUM$/);
    await expect(page.getByLabel('Price — Blue / Medium', { exact: true })).toHaveValue('999.00');

    await page.getByLabel('Price — Blue / Medium', { exact: true }).fill('1299');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('Product saved.')).toBeVisible();

    await page.reload();
    await expect(page.getByLabel('Price — Blue / Medium', { exact: true })).toHaveValue('1299.00');
    await expect(page.getByLabel('Price — Red / Small', { exact: true })).toHaveValue('999.00');

    // The list shows the server's price range as given.
    await page.goto('/admin/products?q=Rainbow');
    await expect(page.getByRole('link', { name: 'Rainbow Stacking Rings' })).toBeVisible();
    await expect(page.getByText('৳999 – ৳1,299')).toBeVisible();
});
