import { expect, test, type Page } from '@playwright/test';

/**
 * Pages built from sections (owner decision 2026-11-16) against the real Laravel app: Home is
 * pinned in the Pages list and opens the home builder; a new page lands in its own builder,
 * gets a section and is published on its own layout; its settings screen links back to it.
 */

async function signIn(page: Page) {
    await page.goto('/admin/store/pages');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('mominulfed@gmail.com');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Pages & navigation', exact: true })).toBeVisible();
}

test('home in the pages list, and a new page built from sections', async ({ page }) => {
    await signIn(page);
    const shots = process.env.SHOTS_DIR;

    const list = page.getByRole('list', { name: 'Pages' });
    const home = list.getByRole('link', { name: 'Home', exact: true });
    await expect(home).toHaveAttribute('href', /\/store\/home$/);
    await expect(list.getByText('toymartbd.com/', { exact: true })).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/pages-list.png`, fullPage: true });

    await page.getByRole('link', { name: 'New page' }).first().click();
    await page.getByLabel('Title (English)', { exact: true }).fill('Returns policy');
    await expect(page.getByText('Add question')).toHaveCount(0);
    await page.getByRole('button', { name: 'Save' }).click();

    // Created → straight into the page's builder.
    await expect(page.getByRole('link', { name: 'Page settings' })).toBeVisible();
    await expect(page).toHaveURL(/\/admin\/store\/pages\/\d+\/build$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Returns policy' })).toBeVisible();

    await page.getByRole('button', { name: 'Add FAQ' }).click();
    await expect(page.getByRole('list', { name: /^Sections · [1-9]/ })).toBeVisible();
    const publish = page.getByRole('button', { name: 'Publish', exact: true });
    await expect(publish).toBeEnabled();
    await publish.click();
    await expect(page.getByText(/^Published version \d+\. The page on the storefront is refreshing\.$/)).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/page-builder.png` });

    await page.getByRole('link', { name: 'Page settings' }).click();
    // Settings: details and the SEO panel share the left column; the save bar closes the page.
    await expect(page.getByTestId('seo-score')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save SEO' })).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/page-settings.png`, fullPage: true });
    await expect(page.getByRole('link', { name: 'Edit sections' })).toHaveAttribute('href', /\/store\/pages\/\d+\/build$/);
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Page saved')).toBeVisible();

    await page.goto('/admin/store/pages');
    await expect(list.getByRole('link', { name: 'Returns policy', exact: true })).toHaveAttribute('href', /\/store\/pages\/\d+\/build$/);
    await list.getByRole('button', { name: 'Actions for Returns policy' }).click();
    const menu = page.getByRole('menu', { name: 'Actions for Returns policy' });
    await expect(menu.getByRole('menuitem')).toHaveText(['Edit', 'Edit sections', 'Preview', 'Archive']);
    await expect(menu.getByRole('menuitem', { name: 'Edit', exact: true })).toHaveAttribute('href', /\/store\/pages\/\d+$/);
    await page.keyboard.press('Escape');

    // Home → Edit: the homepage's SEO settings.
    await list.getByRole('button', { name: 'Actions for Home' }).click();
    await page.getByRole('menu', { name: 'Actions for Home' }).getByRole('menuitem', { name: 'Edit', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Home settings' })).toBeVisible();
    await expect(page.getByTestId('seo-score')).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/home-settings.png`, fullPage: true });
});
