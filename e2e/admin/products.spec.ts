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
    await page.getByLabel('নাম (বাংলা)', { exact: true }).fill('রংধনু স্ট্যাকিং রিং');
    await page.getByLabel('Category', { exact: true }).selectOption({ label: 'Puzzles & Games' });
    // Seeded variant-defining attributes (CatalogReferenceSeeder): Color and Size. The
    // Variants section is always on the page; the first option makes the product variable.
    await page.getByLabel('Add option').selectOption({ label: 'Color' });
    await page.getByLabel('Add a Color value').selectOption({ label: 'Red' });
    await page.getByLabel('Add a Color value').selectOption({ label: 'Blue' });
    await page.getByLabel('Add option').selectOption({ label: 'Size' });
    await page.getByLabel('Add a Size value').selectOption({ label: 'Small' });
    await page.getByLabel('Add a Size value').selectOption({ label: 'Medium' });
    await page.getByLabel('Add a Size value').selectOption({ label: 'Large' });
    await expect(page.getByRole('switch', { name: /^Sell / })).toHaveCount(6);

    // The rich-text description: bold text in English, then Bangla through the EN | বাং switch.
    const editor = page.getByRole('textbox', { name: 'Description' }).first();
    await editor.click();
    await page.keyboard.type('Stacks to ');
    await page.getByRole('button', { name: 'Bold' }).click();
    await page.keyboard.type('six rings');
    await page.getByRole('button', { name: /Switch the description language/ }).click();
    await page.locator('[role="textbox"][lang="bn"]').click();
    await page.keyboard.type('ছয়টি রিং');

    // One price for every combination, then save: the server generates the SKUs.
    await page.getByRole('checkbox', { name: 'Select all variants' }).check();
    await page.getByLabel('Value to apply').fill('999');
    await page.getByRole('button', { name: 'Apply' }).click();
    await page.getByRole('button', { name: 'Create product' }).click();
    await expect(page.getByText('Product created.')).toBeVisible();
    await expect(page).toHaveURL(/\/admin\/products\/\d+(\?tab=\w+)?$/);
    await expect(page.getByLabel('SKU — Blue / Medium')).toHaveValue(/^TM-\d{5}-BLUE-MEDIUM$/);
    await expect(page.getByLabel('Price — Blue / Medium', { exact: true })).toHaveValue('999.00');

    await page.getByLabel('Price — Blue / Medium', { exact: true }).fill('1299');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('Product saved.')).toBeVisible();

    await page.reload();
    await expect(page.getByLabel('Price — Blue / Medium', { exact: true })).toHaveValue('1299.00');
    // Saved as sanitised HTML and loaded back into the editor.
    await expect(page.locator('[role="textbox"][lang="en"] strong')).toHaveText('six rings');
    await expect(page.getByLabel('Price — Red / Small', { exact: true })).toHaveValue('999.00');

    // The list shows the server's price range as given.
    await page.goto('/admin/products?q=Rainbow');
    await expect(page.getByRole('link', { name: 'Rainbow Stacking Rings' })).toBeVisible();
    await expect(page.getByText('৳999 – ৳1,299')).toBeVisible();
});

test('the Inventory tab brings "Stock by branch" into view below the sticky topbar and keeps the tab active', async ({ page }) => {
    await signIn(page);
    await page.setViewportSize({ width: 1440, height: 900 });

    // A seeded demo product with stock (DemoCatalogSeeder).
    await page.getByRole('table').getByRole('link').first().click();
    await expect(page).toHaveURL(/\/admin\/products\/\d+$/);

    const tabs = page.getByRole('navigation', { name: 'Product sections' });
    const inventory = tabs.getByRole('link', { name: /^Inventory/ });
    await inventory.click();
    await expect(page).toHaveURL(/\?tab=inventory$/);

    const heading = page.getByRole('heading', { name: 'Stock by branch' });
    await expect(heading).toBeInViewport();
    // Let the smooth scroll settle, then check the geometry against the sticky chrome.
    await page.waitForTimeout(1200);
    const topbar = await page.locator('header').first().boundingBox();
    const strip = await tabs.boundingBox();
    const box = await heading.boundingBox();
    expect(topbar && strip && box).toBeTruthy();
    expect(strip!.y).toBeGreaterThanOrEqual(topbar!.y + topbar!.height - 1);
    expect(box!.y).toBeGreaterThan(strip!.y + strip!.height);
    await expect(inventory).toHaveAttribute('aria-current', 'location');

    // Every tab jumps to its section and stays the active one.
    for (const name of ['General', 'Media', 'Pricing', 'Variants', 'Inventory', 'SEO', 'Related']) {
        const tab = tabs.getByRole('link', { name: new RegExp(`^${name}`) });
        await tab.click();
        await page.waitForTimeout(900);
        await expect(tab).toHaveAttribute('aria-current', 'location');
    }
});
