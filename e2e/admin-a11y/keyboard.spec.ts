import { expect, test, type Locator, type Page } from '@playwright/test';

// Phase 9 admin keyboard paths (docs/audits/ACCESSIBILITY-AND-BILINGUAL.md), on the demo data:
// sign in, the skip link, and processing an order from the detail page without a pointer — a
// <dialog> opened from the keyboard traps focus, Escape closes it and focus returns to its trigger.
// The mobile drawer and the account menu's Escape are covered in Vitest (components/shell).

/** Press Tab until `target` has focus — it is reachable, in order, by keyboard alone. */
async function tabTo(page: Page, target: Locator, max = 120): Promise<void> {
    for (let i = 0; i < max; i++) {
        if (await target.evaluate((el) => el === document.activeElement).catch(() => false)) return;
        await page.keyboard.press('Tab');
    }
    throw new Error(`not reachable with ${max} Tab presses: ${target.toString()}`);
}

test.use({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });

test('sign in, skip to content, and process an order with the keyboard only', async ({ page }) => {
    await page.goto('/admin/login');
    await tabTo(page, page.locator('input[type="email"]'));
    await page.keyboard.type('owner@toymart.test');
    await tabTo(page, page.locator('input[type="password"]'));
    await page.keyboard.type('password');
    await page.keyboard.press('Enter');
    await expect(page).not.toHaveURL(/\/login/);

    // A confirmed demo order (DemoOrdersSeeder): Hold opens a dialog; Start processing moves it on.
    const order = await page.evaluate(async () => {
        const res = await fetch('/api/v1/admin/orders?filter[status]=confirmed', { headers: { Accept: 'application/json' }, credentials: 'include' });
        const body = (await res.json()) as { data: Array<{ id: number; number: string }> };
        return body.data[0]!;
    });
    await page.goto(`/admin/orders/${order.id}`);
    await expect(page.getByRole('button', { name: 'Start processing' })).toBeVisible(); // the SPA has rendered
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.locator('#content')).toBeFocused();

    const hold = page.getByRole('button', { name: 'Hold', exact: true });
    await tabTo(page, hold);
    await page.keyboard.press('Enter');
    const dialog = page.getByRole('dialog', { name: `Hold ${order.number}?` });
    await expect(dialog).toBeVisible();
    for (let i = 0; i < 12; i++) {
        await page.keyboard.press('Tab');
        // A native modal <dialog> makes the page inert; Tab may pass through the browser's own UI
        // (activeElement = body) on its way round, but never to a page element outside the dialog.
        expect(await dialog.evaluate((d) => d.contains(document.activeElement) || document.activeElement === document.body), `Tab ${i + 1} left the dialog`).toBe(true);
    }
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(hold).toBeFocused();

    await tabTo(page, page.getByRole('button', { name: 'Start processing' }));
    await page.keyboard.press('Enter');
    await expect(page.getByText(`${order.number} is processing`)).toBeVisible();
});
