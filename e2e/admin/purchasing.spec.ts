import { expect, test, type Page } from '@playwright/test';

/*
 * Purchasing, end to end against the real app (Phase 10 premium pass, Figma 414:70076): raise a
 * PO from Inventory's ?sku= deep link, place it, receive part of it through the Receive stock
 * drawer with an extra cost, then see the progress and what is owed on the PO and the list.
 * Every taka figure on screen is the server's.
 */

async function signIn(page: Page) {
    await page.goto('/admin/purchasing');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('mominulfed@gmail.com');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Purchase orders', exact: true })).toBeVisible();
}

/** A JSON write from inside the page, so the cookie session and XSRF header are the admin's own. */
async function apiPost(page: Page, path: string, body: unknown): Promise<{ status: number; json: Record<string, unknown> }> {
    return page.evaluate(
        async ([url, payload]) => {
            const xsrf = decodeURIComponent(/(?:^|; )XSRF-TOKEN=([^;]+)/.exec(document.cookie)?.[1] ?? '');
            const response = await fetch(url, {
                method: 'POST',
                body: JSON.stringify(payload),
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf },
            });
            return { status: response.status, json: (await response.json()) as Record<string, unknown> };
        },
        [`/api/v1/admin${path}`, body] as const,
    );
}

test('create → place → receive part of it → see the progress and what is owed', async ({ page }) => {
    await signIn(page);
    const name = `Bangshal Kids Imports E2E ${Date.now()} · বংশাল কিডস`;
    const supplier = await apiPost(page, '/suppliers', { name, phone: '01819503746', payment_terms_days: 15 });
    expect(supplier.status).toBe(201);

    // Inventory's "Create PO" deep link starts the product search with the SKU.
    await page.goto('/admin/purchasing/new?sku=TMB-LEG-CL484');
    await expect(page.getByRole('heading', { name: 'New purchase order', exact: true })).toBeVisible();
    await page.getByRole('combobox', { name: /Supplier/ }).selectOption({ label: name });
    await expect(page.getByText('Credit 15 days · you owe them ৳0.00')).toBeVisible();
    await page.getByRole('list', { name: /Add a product/ }).getByRole('button').first().click();

    const quantity = page.getByRole('textbox', { name: /^Quantity of / });
    await quantity.fill('10');
    await page.getByRole('textbox', { name: /^Unit cost of / }).fill('500');
    const rail = page.getByRole('complementary', { name: 'Summary' });
    await expect(rail).toContainText('৳5,000.00');
    await rail.getByRole('button', { name: 'Place order' }).click();

    await expect(page).toHaveURL(/\/admin\/purchasing\/\d+$/);
    const title = page.getByRole('heading', { level: 1, name: /^PO-\d{4}-\d{4}$/ });
    await expect(title).toBeVisible();
    const number = (await title.innerText()).trim();
    await expect(page.getByText('Waiting for stock').first()).toBeVisible();

    // One primary action now: Receive stock, in the drawer.
    await page.getByRole('button', { name: 'Receive stock' }).click();
    const drawer = page.getByRole('dialog', { name: 'Receive stock' });
    await expect(drawer).toBeVisible();
    await drawer.getByRole('textbox', { name: /^Units received for / }).fill('4');
    await drawer.getByRole('textbox', { name: 'Freight (৳)' }).fill('100');
    await expect(drawer).toContainText('You will owe the supplier৳2,100.00');
    await drawer.getByRole('button', { name: 'Receive 4 units' }).click();
    await expect(drawer).toBeHidden();

    // The PO shows the progress and what is owed, from the server.
    await expect(page.getByText('Partly received').first()).toBeVisible();
    await expect(page.getByRole('region', { name: 'Progress' })).toContainText('4 of 10 units in');
    const owe = page.getByRole('region', { name: 'What you owe' });
    // The order total stays what was ordered; the freight is its own line and, being on the
    // supplier's bill, part of what is owed (owner decision 2026-11-16).
    await expect(owe).toContainText('Order total৳5,000.00');
    await expect(owe).toContainText('Freight৳100.00');
    await expect(owe).toContainText('Goods received so far৳2,000.00');
    await expect(owe).toContainText('Still to pay৳2,100.00');
    await expect(page.getByRole('region', { name: 'Deliveries received' })).toContainText('freight ৳100.00 (spread by product value) = ৳2,100.00 added to what you owe');

    // The list row: received progress in units and still to pay.
    await page.goto('/admin/purchasing');
    const row = page.getByRole('row').filter({ has: page.getByRole('link', { name: number }) });
    await expect(row).toContainText('4 of 10 units');
    await expect(row).toContainText('৳5,000.00');
    await expect(row).toContainText('৳2,100.00');
    await expect(row).toContainText('Partly received');
});
