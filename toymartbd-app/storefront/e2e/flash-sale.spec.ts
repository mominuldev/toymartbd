import { expect, test } from '@playwright/test';

import { MOCK_API_URL } from './constants';

// P7 flash sales: the /flash-sale page, the flash badge on cards and the CountdownTimer. Without
// JavaScript the timer shows the sale's end time (Dhaka, Western digits); with JavaScript it ticks
// inside the same reserved box — no layout shift.

test.beforeAll(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('/flash-sale renders the sale, its badges and the end time server-side', async ({ page }) => {
        const response = await page.goto('/flash-sale');
        expect(response?.status()).toBe(200);
        await expect(page.locator('h1')).toHaveText('Flash sale');
        await expect(page.getByRole('heading', { level: 2, name: 'Weekend flash sale' })).toBeVisible();

        const cards = page.locator('article');
        await expect(cards).toHaveCount(2);
        await expect(cards.first()).toContainText('Flash sale');
        await expect(cards.first()).toContainText('−10%');
        await expect(cards.first().locator('s')).toHaveText('৳4,250');
        await expect(cards.first()).toContainText('৳3,790');

        // The countdown is the end time itself, as a <time> with the server's ISO instant.
        const timer = page.locator('[role="timer"][data-size="sm"]').first();
        await expect(timer).toHaveAttribute('data-state', 'static');
        const iso = await timer.locator('time').getAttribute('datetime');
        expect(iso).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
        await expect(timer).toContainText(/\d{2}\/\d{2} \d{2}:\d{2}/);
    });

    test('/bn/flash-sale is Bangla with Western digits in the timer', async ({ page }) => {
        await page.goto('/bn/flash-sale');
        await expect(page.locator('h1')).toHaveText('ফ্ল্যাশ সেল');
        await expect(page.locator('article').first()).toContainText('ফ্ল্যাশ সেল');
        const text = await page.locator('[role="timer"]').first().innerText();
        expect(text).not.toMatch(/[০-৯]/);
        expect(text).toMatch(/\d/);
    });
});

test.describe('with JavaScript', () => {
    test('the countdown ticks without moving the layout', async ({ page, browser }) => {
        // The reserved box with JS off …
        const noJs = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 844 } });
        const staticPage = await noJs.newPage();
        await staticPage.goto('/flash-sale');
        const staticBox = await staticPage.locator('[role="timer"][data-size="sm"]').first().boundingBox();
        const staticCard = await staticPage.locator('article').first().boundingBox();
        await noJs.close();

        // … equals the ticking box with JS on, and the page records no layout shift.
        await page.setViewportSize({ width: 390, height: 844 });
        await page.addInitScript(() => {
            (window as unknown as { __cls: number }).__cls = 0;
            new PerformanceObserver((list) => {
                for (const entry of list.getEntries() as Array<PerformanceEntry & { value: number; hadRecentInput: boolean }>) {
                    if (!entry.hadRecentInput) (window as unknown as { __cls: number }).__cls += entry.value;
                }
            }).observe({ type: 'layout-shift', buffered: true });
        });
        await page.goto('/flash-sale');
        const timer = page.locator('[role="timer"][data-size="sm"]').first();
        await expect(timer).toHaveAttribute('data-state', 'normal');
        await expect(timer).toContainText(/\d{2}:\d{2}:\d{2}/);
        const first = await timer.innerText();
        await expect.poll(async () => timer.innerText(), { timeout: 3000 }).not.toBe(first);

        const liveBox = await timer.boundingBox();
        const liveCard = await page.locator('article').first().boundingBox();
        expect(liveBox?.width).toBe(staticBox?.width);
        expect(liveBox?.height).toBe(staticBox?.height);
        expect(liveCard?.height).toBe(staticCard?.height);
        const cls = await page.evaluate(() => (window as unknown as { __cls: number }).__cls);
        expect(cls).toBeLessThan(0.01);
    });
});
