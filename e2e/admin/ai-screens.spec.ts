import { expect, test, type Page } from '@playwright/test';

/**
 * AI for growth (Figma 536:82212): each screen renders without an AI provider connected and
 * says how to set one up — never a raw error. SHOTS_DIR=… saves screenshots for the Figma check.
 * No provider is ever called from the e2e app (no key is configured).
 */

const shots = process.env.SHOTS_DIR;

async function signIn(page: Page) {
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill('owner@toymart.test');
    await page.locator('input[type="password"]').fill('password');
    await page.locator('button[type="submit"]').click();
    await expect(page).not.toHaveURL(/\/login/);
}

test.use({ viewport: { width: 1440, height: 1100 } });

test('AI settings, Growth advisor and Restock forecast render without a provider', async ({ page }) => {
    await signIn(page);

    await page.goto('/admin/integrations/ai');
    await expect(page.getByRole('heading', { level: 1, name: 'AI assistant' })).toBeVisible();
    await expect(page.getByRole('link', { name: /Get an API key from OpenAI/ })).toHaveAttribute('href', 'https://platform.openai.com/api-keys');
    if (shots) await page.screenshot({ path: `${shots}/ai-settings.png`, fullPage: true });

    await page.goto('/admin/analytics/advisor');
    await expect(page.getByRole('heading', { level: 1, name: 'Growth advisor' })).toBeVisible();
    await expect(page.getByText('Loading the advisor…')).toHaveCount(0, { timeout: 15_000 });
    if (shots) await page.screenshot({ path: `${shots}/ai-advisor.png`, fullPage: true });

    await page.goto('/admin/inventory/restock');
    await expect(page.getByRole('heading', { level: 1, name: 'Restock forecast' })).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/ai-restock.png`, fullPage: true });
});
