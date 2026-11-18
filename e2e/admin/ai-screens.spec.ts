import { expect, test, type Page } from '@playwright/test';

/**
 * AI for growth (Figma 536:82212): each screen renders without an AI provider connected and
 * says how to set one up — never a raw error. SHOTS_DIR=… saves screenshots for the Figma check.
 * No provider is ever called from the e2e app (no key is configured).
 */

const shots = process.env.SHOTS_DIR;

async function signIn(page: Page) {
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill('mominulfed@gmail.com');
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

test('Ask AI pushes the page on a wide screen and docks over it on a narrower one', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1000 });
    await signIn(page);
    await page.goto('/admin/returns');
    const main = page.locator('#content');
    const before = (await main.boundingBox())!.width;

    const ask = page.getByRole('button', { name: 'Ask', exact: true });
    await ask.click();
    await expect(ask).toHaveAttribute('aria-pressed', 'true');
    const panel = page.getByRole('region', { name: 'Ask Toy Mart AI' });
    await expect(panel.getByText('AI is not set up for this')).toBeVisible();
    // From 1720 it pushes: the page is 440 narrower once the slide ends.
    await expect.poll(async () => Math.round(before - (await main.boundingBox())!.width)).toBe(440);
    if (shots) await page.screenshot({ path: `${shots}/ask-ai-panel-1920.png` });

    await page.keyboard.press('Escape');
    await expect(ask).toHaveAttribute('aria-pressed', 'false');
    await expect(ask).toBeFocused();
    await expect.poll(async () => Math.round((await main.boundingBox())!.width)).toBe(Math.round(before));

    // At 1440 the page would be squeezed to 752 under its xl layout, so the panel docks over it.
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/admin');
    const wide = (await main.boundingBox())!.width;
    await page.getByRole('button', { name: 'Ask', exact: true }).click();
    await expect(panel).toBeVisible();
    expect(Math.round((await main.boundingBox())!.width)).toBe(Math.round(wide));
    expect((await panel.boundingBox())!.y).toBe(60);
    if (shots) await page.screenshot({ path: `${shots}/ask-ai-panel-1440.png` });
});
