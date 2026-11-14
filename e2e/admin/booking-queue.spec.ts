import { expect, test, type Page } from '@playwright/test';

/*
 * Courier booking queue (Figma B · 07) against the real app on the E2E seed: no demo order
 * history (DEMO_SEED_TRANSACTIONS=false) and no courier merchant account (rule 33a), so the
 * queue must say so honestly — Steadfast without credentials, Pathao switched off, the seeded
 * 17:00 cut-off, zero counts, every tab's empty state, and the rule-24d notice. Booking itself
 * is covered by Pest (BookingQueueApiTest) and Vitest (booking.test.tsx) against fixtures.
 * SHOTS_DIR=… also saves screenshots at 1440 and 768.
 */

const shots = process.env.SHOTS_DIR;

async function signIn(page: Page) {
    await page.goto('/admin/shipments/booking');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
}

test('the booking queue states the live facts and never invents a figure', async ({ browser }) => {
    const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
    await signIn(page);

    await expect(page.getByRole('heading', { name: 'Courier booking' })).toBeVisible();
    await expect(page.getByText(/Ready-to-ship orders · Steadfast not connected · Pathao off · today’s pickup cut-off 5:00 pm/)).toBeVisible();

    const tabs = page.getByRole('navigation', { name: 'Booking queue' });
    for (const label of ['Ready to ship', 'Submitted — awaiting ID', 'Booked today', 'Failed', 'Unmapped areas']) {
        await expect(tabs.getByRole('button', { name: new RegExp(`^${label}\\s*0$`) })).toBeVisible();
    }

    const cards = page.getByRole('list', { name: 'Couriers' });
    await expect(cards.getByText('Steadfast', { exact: true })).toBeVisible();
    await expect(cards.getByText(/Not connected — no credentials · 0 bookings/)).toBeVisible();
    await expect(cards.getByText(/Switched off · 0 bookings/)).toBeVisible();
    // No balance has ever been stored: none is shown, never ৳0.
    await expect(cards.getByText('৳0')).toHaveCount(0);

    await expect(page.getByText('Nothing is waiting to ship')).toBeVisible();
    await expect(page.getByRole('note')).toContainText('Bulk booking returns no consignment IDs');
    if (shots) await page.screenshot({ path: `${shots}/booking-queue-1440.png`, fullPage: true });

    await tabs.getByRole('button', { name: /^Submitted — awaiting ID/ }).click();
    await expect(page).toHaveURL(/tab=submitted/);
    await expect(page.getByText('Nothing is awaiting a consignment ID')).toBeVisible();

    await tabs.getByRole('button', { name: /^Unmapped areas/ }).click();
    await expect(page.getByText('Every ready order’s area is mapped')).toBeVisible();

    await page.setViewportSize({ width: 768, height: 1000 });
    await tabs.getByRole('button', { name: /^Ready to ship/ }).click();
    await expect(page.getByRole('heading', { name: 'Courier booking' })).toBeVisible();
    // No horizontal page scroll at tablet width.
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    if (shots) await page.screenshot({ path: `${shots}/booking-queue-768.png`, fullPage: true });
});
