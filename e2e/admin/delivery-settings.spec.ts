import { expect, test, type Page } from '@playwright/test';

/*
 * Store settings › Delivery areas & timing against the real app: the seeded `delivery.*`
 * values load (cut-off, ETA days, metro/suburb districts, not-serviced upazilas, COD advance),
 * a change saves through PUT /settings/delivery-areas and survives a reload, and Checkout &
 * delivery shows the COD advance from the same setting. The run puts the seed values back.
 * SHOTS_DIR=… also saves a screenshot.
 */

const shots = process.env.SHOTS_DIR;

async function signIn(page: Page) {
    await page.goto('/admin/settings/delivery');
    await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
    await page.getByLabel('Email').fill('mominulfed@gmail.com');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
}

test('owner edits delivery areas & timing and checkout follows', async ({ browser }) => {
    const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
    await signIn(page);

    const form = page.getByRole('form', { name: 'Delivery areas & timing' });
    await expect(form.getByRole('heading', { name: 'Order cut-off & delivery time' })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Store settings sections' }).getByRole('link', { name: 'Delivery areas & timing' })).toHaveAttribute('aria-current', 'page');

    // Seed values (DeliverySettings::DEFAULTS).
    await expect(form.getByLabel('Order cut-off')).toHaveValue('17:00');
    await expect(form.getByLabel('Outside Dhaka: slowest, in working days')).toHaveValue('4');
    await expect(form.getByRole('list', { name: 'Chosen: Inside Dhaka (city districts)' }).getByText('Dhaka')).toBeVisible();
    await expect(form.getByRole('list', { name: 'Chosen: Dhaka suburbs (whole districts)' }).getByRole('listitem')).toHaveCount(5);
    await expect(form.getByRole('list', { name: 'Chosen: Not-serviced upazilas' }).getByText('Barkal')).toBeVisible();
    await expect(form.getByRole('switch', { name: 'Require a COD advance' })).toHaveAttribute('aria-checked', 'true');
    if (shots) await form.screenshot({ path: `${shots}/delivery-areas.png` });

    // The sticky save bar sits outside the form and appears only once something changed.
    const save = page.getByRole('button', { name: 'Save changes' });
    await expect(save).toHaveCount(0);

    await form.getByLabel('Order cut-off').fill('16:00');
    await form.getByLabel('Outside Dhaka: slowest, in working days').fill('5');
    await form.getByLabel('Advance amount').fill('250');
    await save.click();
    await expect(page.getByText('Delivery areas & timing saved')).toBeVisible();

    await page.reload();
    await expect(form.getByLabel('Order cut-off')).toHaveValue('16:00');
    await expect(form.getByLabel('Outside Dhaka: slowest, in working days')).toHaveValue('5');
    await expect(form.getByLabel('Advance amount')).toHaveValue('250');

    // One source of truth: Checkout & delivery states the advance from the same setting.
    await page.goto('/admin/settings/checkout');
    await expect(page.getByText(/held until a ৳250 advance/)).toBeVisible();
    await page.getByRole('link', { name: 'Zones, delivery days and the COD advance: Delivery areas & timing' }).click();

    // Put the seed values back.
    await form.getByLabel('Order cut-off').fill('17:00');
    await form.getByLabel('Outside Dhaka: slowest, in working days').fill('4');
    await form.getByLabel('Advance amount').fill('200');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('Delivery areas & timing saved')).toBeVisible();
});

test('a server refusal is shown on its field', async ({ browser }) => {
    const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
    await signIn(page);

    const form = page.getByRole('form', { name: 'Delivery areas & timing' });
    await form.getByLabel('Advance amount').fill('9000');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(form.getByText('The advance must be less than the threshold.')).toBeVisible();
    await expect(form.getByLabel('Advance amount')).toHaveAttribute('aria-invalid', 'true');
});
