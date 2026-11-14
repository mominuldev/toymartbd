import { expect, test, type Browser, type Page } from '@playwright/test';

/*
 * Settings → Staff & roles (Figma B · 35) against the real app: the owner adds a person
 * with a temporary password, that person can sign in with exactly their role's access,
 * and once deactivated they cannot. SHOTS_DIR=… also saves screenshots for the Figma check.
 */

const shots = process.env.SHOTS_DIR;

async function signIn(page: Page, email: string, password: string) {
    await page.goto('/admin/staff');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
}

async function signInAs(browser: Browser, email: string, password: string): Promise<Page> {
    const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
    await signIn(page, email, password);
    return page;
}

test('owner adds, manages and deactivates a staff member', async ({ browser }) => {
    const owner = await signInAs(browser, 'owner@toymart.test', 'password');
    await expect(owner.getByRole('heading', { name: 'Staff & roles' })).toBeVisible();
    const table = owner.getByRole('table');
    await expect(table.getByText('owner@toymart.test')).toBeVisible();
    if (shots) await owner.screenshot({ path: `${shots}/people.png`, fullPage: true });

    // Add — a short temporary password is caught by the generated schema first.
    await owner.getByRole('button', { name: 'Add staff' }).click();
    const add = owner.getByRole('dialog', { name: 'Add staff' });
    await add.getByLabel('Full name').fill('Sadia Haque');
    await add.getByLabel('Email').fill('Sadia@ToyMart.test');
    await add.getByLabel('Role').selectOption('order_operator');
    await add.getByLabel('Temporary password').fill('short');
    await add.getByRole('button', { name: 'Add staff' }).click();
    await expect(add.getByLabel('Temporary password')).toHaveAttribute('aria-invalid', 'true');
    if (shots) await owner.screenshot({ path: `${shots}/add-dialog.png` });

    await add.getByLabel('Temporary password').fill('temporary-pass-2026');
    await add.getByRole('button', { name: 'Add staff' }).click();
    await expect(owner.getByText('Sadia Haque added')).toBeVisible();
    const row = table.getByRole('row', { name: /Sadia Haque/ });
    await expect(row).toContainText('sadia@toymart.test');
    await expect(row).toContainText('Order operator');
    await expect(row).toContainText('Never signed in');

    // The new person signs in with the temporary password and has the order operator's access only.
    const sadia = await signInAs(browser, 'sadia@toymart.test', 'temporary-pass-2026');
    await expect(sadia.getByText('You do not have access to this page.')).toBeVisible();
    await sadia.goto('/admin/orders');
    await expect(sadia.getByRole('heading', { name: 'Orders', exact: true })).toBeVisible();

    // Manage: role change, then deactivate after confirming.
    await owner.reload();
    await owner.getByRole('button', { name: 'Manage Sadia Haque' }).click();
    const manage = owner.getByRole('dialog', { name: 'Manage Sadia Haque' });
    await manage.getByLabel('Role').selectOption('accountant');
    if (shots) await owner.screenshot({ path: `${shots}/manage-dialog.png` });
    await manage.getByRole('button', { name: 'Save changes' }).click();
    await expect(owner.getByText('Changes saved')).toBeVisible();
    await expect(table.getByRole('row', { name: /Sadia Haque/ })).toContainText('Accountant');

    await owner.getByRole('button', { name: 'Manage Sadia Haque' }).click();
    await owner.getByRole('dialog', { name: 'Manage Sadia Haque' }).getByRole('button', { name: 'Deactivate' }).click();
    await owner.getByRole('dialog', { name: 'Deactivate Sadia Haque?' }).getByRole('button', { name: 'Deactivate' }).click();
    await expect(owner.getByText('Sadia Haque deactivated')).toBeVisible();
    await expect(table.getByRole('row', { name: /Sadia Haque/ })).toContainText('Deactivated');

    // Her open session is refused on the next request; a fresh sign-in fails.
    await sadia.reload();
    await expect(sadia.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await sadia.getByLabel('Email').fill('sadia@toymart.test');
    await sadia.getByLabel('Password').fill('temporary-pass-2026');
    await sadia.getByRole('button', { name: 'Sign in' }).click();
    await expect(sadia.getByText('Those details did not match an active staff account.')).toBeVisible();

    // Your own account cannot be demoted or deactivated from here.
    await owner.getByRole('button', { name: /^Manage Local Owner$/ }).click();
    const self = owner.getByRole('dialog', { name: /Manage Local Owner/ });
    await expect(self.getByText('This is your account. Another owner must change your role or status.')).toBeVisible();
    await expect(self.getByRole('button', { name: 'Deactivate' })).toHaveCount(0);
    await self.getByRole('button', { name: 'Cancel' }).click();

    // The matrix: owner-only rows locked; an edit is confirmed, saved and survives a reload.
    await owner.getByRole('link', { name: /Roles & permissions/ }).click();
    const matrix = owner.getByRole('table', { name: 'Permissions by role' });
    await expect(matrix.getByRole('rowheader', { name: /Approve refunds/ })).toBeVisible();
    await expect(matrix.getByRole('checkbox', { name: 'Manage staff for Manager' })).toHaveCount(0);
    const financeForViewer = matrix.getByRole('checkbox', { name: 'View finance for Viewer' });
    await expect(financeForViewer).not.toBeChecked();
    await financeForViewer.check();
    if (shots) await owner.screenshot({ path: `${shots}/roles.png`, fullPage: true });
    await owner.getByRole('button', { name: 'Save permissions' }).click();
    await owner.getByRole('dialog', { name: 'Change permissions for 1 role(s)?' }).getByRole('button', { name: 'Save permissions' }).click();
    await expect(owner.getByText('Permissions saved for 1 role(s)')).toBeVisible();
    await owner.reload();
    await expect(owner.getByRole('checkbox', { name: 'View finance for Viewer' })).toBeChecked();
    await owner.getByRole('button', { name: 'Restore the default permissions for Viewer' }).click();
    await owner.getByRole('button', { name: 'Save permissions' }).click();
    await owner.getByRole('dialog', { name: 'Change permissions for 1 role(s)?' }).getByRole('button', { name: 'Save permissions' }).click();
    await expect(owner.getByRole('checkbox', { name: 'View finance for Viewer' })).not.toBeChecked();

    // Bangla.
    await owner.getByRole('group', { name: 'Language' }).getByRole('button', { name: 'বাং' }).click();
    await expect(owner.getByRole('heading', { name: 'স্টাফ ও ভূমিকা' })).toBeVisible();
    if (shots) await owner.screenshot({ path: `${shots}/roles-bn.png`, fullPage: true });
});
