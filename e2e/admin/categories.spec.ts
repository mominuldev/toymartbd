import { expect, test, type Page } from '@playwright/test';

async function signIn(page: Page) {
    await page.goto('/admin/products/categories');
    await expect(page.getByRole('heading', { name: 'Sign in to the admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Categories' })).toBeVisible();
}

test('owner creates a subcategory, then reorders and outdents it with the keyboard', async ({ page }) => {
    await signIn(page);

    // Seeded demo tree (CategoryDemoSeeder), Bangla names included.
    const tree = page.getByRole('region', { name: 'Category tree' });
    await expect(tree.getByRole('link', { name: 'Educational Toys', exact: true })).toBeVisible();
    await expect(tree.getByText('শিক্ষামূলক খেলনা')).toBeVisible();

    // Create under "Educational Toys" — appended after Puzzles and STEM Kits.
    await page.getByRole('link', { name: 'New category' }).click();
    await page.getByLabel('Name (English)').fill('Wooden Toys');
    await page.getByLabel('Name (Bangla)').fill('কাঠের খেলনা');
    await page.getByLabel('Parent category').selectOption({ label: 'Educational Toys' });
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Category created.')).toBeVisible();
    await expect(page.getByLabel('URL slug')).toHaveValue('wooden-toys');

    await page.getByRole('link', { name: 'All categories' }).click();
    await expect(tree.getByRole('link', { name: 'Wooden Toys', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Move Wooden Toys down' })).toBeDisabled();

    // Move up with the keyboard: focus stays on the button after the tree reloads.
    const up = page.getByRole('button', { name: 'Move Wooden Toys up' });
    await up.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByText('Wooden Toys moved.')).toBeAttached();
    await expect(up).toBeFocused();

    const children = tree.getByRole('link').filter({ hasText: /^(Puzzles|STEM Kits|Wooden Toys)$/ });
    await expect(children).toHaveText(['Puzzles', 'Wooden Toys', 'STEM Kits']);

    // Outdent: it becomes a top-level category right after its old parent.
    await page.getByRole('button', { name: 'Outdent Wooden Toys one level' }).click();
    await expect(page.getByRole('button', { name: 'Outdent Wooden Toys one level' })).toBeDisabled();
    const roots = tree.getByRole('link').filter({ hasText: /^(Educational Toys|Wooden Toys|Baby & Toddler)$/ });
    await expect(roots).toHaveText(['Educational Toys', 'Wooden Toys', 'Baby & Toddler']);
});
