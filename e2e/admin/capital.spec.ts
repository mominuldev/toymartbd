import { expect, test, type Page } from '@playwright/test';

/*
 * Owner capital end to end against the real app (Phase 10, Figma B · 32 Finance › Capital):
 * add a partner, record an investment and a drawing through the dialogs, reverse the drawing,
 * and watch the server's totals follow. Money in is cash, never profit — every taka figure on
 * screen comes from the API.
 */

async function signIn(page: Page) {
    await page.goto('/admin/finance/capital');
    await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
    await page.getByLabel('Email').fill('mominulfed@gmail.com');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Owner capital' })).toBeVisible();
}

test('add a partner → invest → draw → reverse the drawing → totals follow', async ({ page }) => {
    await signIn(page);
    const partner = `Tanvir Hasan E2E ${Date.now()}`;

    // A separate dialog adds the person (lead decision: not inline).
    await page.getByRole('button', { name: 'Add owner or partner', exact: true }).first().click();
    const add = page.getByRole('dialog', { name: 'Add an owner or partner' });
    await add.getByLabel(/^Name(\s\*)?$/).fill(partner);
    await add.getByLabel(/^Name in Bangla/).fill('তানভীর হাসান');
    await add.getByRole('button', { name: 'Partner' }).click();
    await add.getByRole('button', { name: 'Add person' }).click();
    await expect(add).toBeHidden();
    await expect(page.getByRole('main').getByRole('listitem').filter({ hasText: partner })).toContainText('Partner');

    // New investment — money in.
    await page.getByRole('button', { name: 'New investment' }).click();
    const invest = page.getByRole('dialog', { name: 'New investment — money in' });
    await invest.getByRole('combobox', { name: /Who put the money in/ }).selectOption({ label: `${partner} · তানভীর হাসান` });
    await invest.getByLabel(/^Amount/).fill('50000');
    await invest.getByRole('button', { name: 'bKash' }).click();
    await invest.getByLabel(/^Reference/).fill('9KQ7H2LM4P');
    await expect(invest.getByText('Profit unchanged')).toBeVisible();
    await invest.getByRole('button', { name: 'Record ৳50,000.00 in' }).click();
    await expect(invest).toBeHidden();

    const card = page.getByRole('main').getByRole('listitem').filter({ hasText: partner });
    await expect(card).toContainText('৳50,000.00');

    // Owner drawing — money out, from the same person.
    await page.getByRole('button', { name: 'Owner drawing' }).click();
    const draw = page.getByRole('dialog', { name: 'Owner drawing — money out' });
    await draw.getByRole('combobox', { name: /Who took the money/ }).selectOption({ label: `${partner} · তানভীর হাসান` });
    await expect(draw.getByText('Put in ৳50,000.00 · taken out ৳0.00 so far')).toBeVisible();
    await draw.getByLabel(/^Amount/).fill('12000');
    await draw.getByRole('button', { name: 'Record ৳12,000.00 out' }).click();
    await expect(draw).toBeHidden();
    await expect(card.getByText('৳38,000.00')).toBeVisible();

    // Reverse the drawing: the person's "taken out" drops back, "still in" returns to 50,000.
    await page.getByRole('combobox', { name: 'Person' }).selectOption({ label: partner });
    const table = page.getByRole('table', { name: 'Every entry' });
    const drawingRow = table.getByRole('row').filter({ hasText: '−৳12,000.00' });
    await drawingRow.locator('summary').click();
    await drawingRow.getByRole('button', { name: 'Reverse entry' }).click();
    const confirm = page.getByRole('dialog', { name: 'Reverse this entry?' });
    await expect(confirm.getByText('Reversal · money in')).toBeVisible();
    await confirm.getByLabel(/Why are you reversing it/).fill('Entered against the wrong person');
    await confirm.getByRole('button', { name: 'Reverse entry' }).click();
    await expect(confirm).toBeHidden();

    await expect(table.getByText('Reversed')).toBeVisible();
    await expect(table.getByText(/^Cancels entry #\d+$/)).toBeVisible();
    await expect(card.getByText('৳50,000.00').first()).toBeVisible();
    await expect(card).toContainText('৳0.00');
    // The page totals are the server's: 50,000 put in, nothing taken out once the drawing is reversed.
    const kpi = (label: string) => page.getByText(label, { exact: true }).locator('visible=true').locator('xpath=ancestor::div[contains(@class,"rounded-md")][1]');
    await expect(kpi('Still in the business')).toContainText('৳50,000.00');
    await expect(kpi('Taken out')).toContainText('৳0.00');
});
