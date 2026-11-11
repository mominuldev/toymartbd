import { expect, test, type Page } from '@playwright/test';

async function signIn(page: Page) {
    await page.goto('/admin/products/categories');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Categories' })).toBeVisible();
}

test('owner creates a subcategory, then reorders and outdents it with the keyboard', async ({ page }) => {
    await signIn(page);

    // Seeded demo tree (CategoryDemoSeeder), Bangla names included.
    const tree = page.getByRole('region', { name: 'Category tree' });
    await expect(tree.getByRole('link', { name: 'Puzzles & Games', exact: true })).toBeVisible();
    await expect(tree.getByText('পাজল ও গেম')).toBeVisible();

    // Create under "Puzzles & Games" — appended after its four children, the last two being Board Games and Cubes & Brain Teasers.
    await page.getByRole('link', { name: 'New category' }).click();
    await page.getByLabel('Name (English)').fill('Wooden Toys');
    await page.getByLabel('Name (Bangla)').fill('কাঠের খেলনা');
    await page.getByLabel('Parent category').selectOption({ label: 'Puzzles & Games' });
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Category created.')).toBeVisible();

    // Split view: the saved category stays open in the panel beside the tree, and the URL carries it.
    await expect(page).toHaveURL(/\/admin\/products\/categories\/\d+$/);
    await expect(page.getByRole('heading', { name: 'Wooden Toys', level: 2 })).toBeFocused();
    await expect(page.getByLabel('URL slug')).toHaveValue('wooden-toys');
    await expect(tree.getByRole('link', { name: 'Wooden Toys', exact: true })).toHaveAttribute('aria-current', 'page');

    // Another row loads into the same panel; the back button returns to the previous one.
    await tree.getByRole('link', { name: 'Puzzles & Games', exact: true }).click();
    await expect(page.getByLabel('Name (Bangla)')).toHaveValue('পাজল ও গেম');
    await page.goBack();
    await expect(page.getByLabel('Name (English)')).toHaveValue('Wooden Toys');
    await expect(page.getByRole('button', { name: 'Move Wooden Toys down' })).toBeDisabled();

    // Move up with the keyboard: focus stays on the button after the tree reloads.
    const up = page.getByRole('button', { name: 'Move Wooden Toys up' });
    await up.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByText('Wooden Toys moved.')).toBeAttached();
    await expect(up).toBeFocused();

    const children = tree.getByRole('link').filter({ hasText: /^(Board Games|Cubes & Brain Teasers|Wooden Toys)$/ });
    await expect(children).toHaveText(['Board Games', 'Wooden Toys', 'Cubes & Brain Teasers']);

    // Outdent: it becomes a top-level category right after its old parent.
    await page.getByRole('button', { name: 'Outdent Wooden Toys one level' }).click();
    await expect(page.getByRole('button', { name: 'Outdent Wooden Toys one level' })).toBeDisabled();
    const roots = tree.getByRole('link').filter({ hasText: /^(Puzzles & Games|Wooden Toys|Dolls & Soft Toys)$/ });
    await expect(roots).toHaveText(['Puzzles & Games', 'Wooden Toys', 'Dolls & Soft Toys']);
});
