import { expect, test, type Page } from '@playwright/test';

/*
 * Settings → Staff & roles → Activity log (Figma B · 39) against the real app: the owner
 * changes what a role can do, then finds that change in the log — their name, the sentence,
 * the `permissions` chip, the masked IP and the Audited badge — and can filter it by area.
 * SHOTS_DIR=… also saves screenshots for the Figma check.
 */

const shots = process.env.SHOTS_DIR;

async function signIn(page: Page) {
    await page.goto('/admin/staff/roles');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
}

test('a role permission change shows up in the activity log', async ({ browser }) => {
    const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
    await signIn(page);

    const matrix = page.getByRole('table', { name: 'Permissions by role' });
    const refundsForOperator = matrix.getByRole('checkbox', { name: 'Approve refunds for Order operator' });
    await expect(refundsForOperator).not.toBeChecked();
    await refundsForOperator.check();
    await page.getByRole('button', { name: 'Save permissions' }).click();
    await page.getByRole('dialog', { name: 'Change permissions for 1 role(s)?' }).getByRole('button', { name: 'Save permissions' }).click();
    await expect(page.getByText('Permissions saved for 1 role(s)')).toBeVisible();

    // From the Staff & roles header.
    await page.getByRole('link', { name: 'People' }).click();
    await page.getByRole('link', { name: 'Audit log' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Activity log' })).toBeVisible();

    const today = page.getByRole('region', { name: /^Today · / });
    const row = today.getByRole('listitem').filter({ hasText: 'changed what the Order operator role can do' }).first();
    await expect(row).toBeVisible();
    await expect(row.getByText('permissions: +refunds.approve')).toBeVisible();
    await expect(row.getByText(/Staff & roles · .*xx/)).toBeVisible();
    await expect(row.getByText('Audited')).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/activity-log.png`, fullPage: true });

    // Area filter: the change is a staff matter, not a catalog one.
    await page.getByLabel('Area').selectOption('catalog');
    await expect(page.getByText('changed what the Order operator role can do')).toHaveCount(0);
    await page.getByLabel('Area').selectOption('staff');
    await expect(page.getByText('changed what the Order operator role can do').first()).toBeVisible();

    // Put the role back so the run leaves the seeded permissions as they were.
    await page.goto('/admin/staff/roles');
    await page.getByRole('button', { name: 'Restore the default permissions for Order operator' }).click();
    await page.getByRole('button', { name: 'Save permissions' }).click();
    await page.getByRole('dialog', { name: 'Change permissions for 1 role(s)?' }).getByRole('button', { name: 'Save permissions' }).click();
    await expect(page.getByRole('checkbox', { name: 'Approve refunds for Order operator' })).not.toBeChecked();
});
