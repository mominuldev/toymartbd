import { expect, test, type Page } from '@playwright/test';

/*
 * Settings › Store (Figma B · 38) against the real app: the section nav, Business saving into
 * the same rows Invoices, receipts & labels prints, and a branch added, edited and switched off — never
 * deleted. The default location cannot be switched off. SHOTS_DIR=… also saves screenshots for
 * the Figma check.
 */

const shots = process.env.SHOTS_DIR;

async function signIn(page: Page) {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto('/admin/settings');
    await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
    await page.getByLabel('Email').fill('mominulfed@gmail.com');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
}

test('owner edits business details once, then the invoice details on Invoices, receipts & labels', async ({ page }) => {
    await signIn(page);
    await expect(page.getByRole('heading', { level: 1, name: 'Store settings' })).toBeVisible();
    const nav = page.getByRole('navigation', { name: 'Store settings sections' });
    await expect(nav.getByRole('link', { name: 'Business' })).toHaveAttribute('aria-current', 'page');

    await page.getByLabel('Store name', { exact: true }).fill('Toy Mart BD');
    await page.getByLabel('দোকানের নাম').fill('টয় মার্ট বিডি');
    await page.getByLabel('Trade licence no.').fill('TRAD/DNCC/045512/2025');
    await page.getByLabel('BIN (VAT registration)').fill('004567891-0101');
    await page.getByLabel('Support hotline').fill('09610-123456');

    // Rule 30: 011 is a defunct prefix.
    await page.getByLabel('WhatsApp').fill('01111-000222');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByLabel('WhatsApp')).toHaveAttribute('aria-invalid', 'true');

    await page.getByLabel('WhatsApp').fill('01711-000222');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('Business details saved. New invoices will use them.')).toBeVisible();
    await expect(page.getByLabel('WhatsApp')).toHaveValue('+880 1711-000222');
    if (shots) await page.screenshot({ path: `${shots}/business.png`, fullPage: true });

    // Invoices, receipts & labels › A4 invoice edits the rest of what invoices print.
    await nav.getByRole('link', { name: 'Invoices, receipts & labels' }).click();
    await page.getByLabel(/^Address(?! \()/).fill('House 7, Road 2, Section 10, Mirpur, Dhaka 1216');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('Saved. Invoices print this way from now on.')).toBeVisible();

    // …and the invoice save left the Business values alone.
    await nav.getByRole('link', { name: 'Business' }).click();
    await expect(page.getByLabel('Trade licence no.')).toHaveValue('TRAD/DNCC/045512/2025');
    await expect(page.getByLabel('WhatsApp')).toHaveValue('+880 1711-000222');
});

test('owner adds, edits and switches off a branch; the default stays on', async ({ page }) => {
    await signIn(page);
    await page.getByRole('navigation', { name: 'Store settings sections' }).getByRole('link', { name: 'Branches & warehouses' }).click();
    await expect(page.getByRole('heading', { name: 'Branches & warehouses' })).toBeVisible();

    await page.getByRole('button', { name: 'Add branch' }).click();
    const add = page.getByRole('dialog', { name: 'Add branch' });
    await add.getByLabel(/^Name(?! \()/).fill('Khulna · Sonadanga');
    await add.getByLabel(/^Short code/).fill('KHL');
    await add.getByLabel(/^Type/).selectOption('retail');
    await add.getByLabel(/^District/).selectOption({ label: 'Khulna' });
    await add.getByLabel(/^Area/).fill('Sonadanga');
    await add.getByLabel(/^Address/).fill('Shop 4, Sonadanga Bus Stand Market');
    await add.getByLabel(/^Landmark/).fill('Opposite the bus stand');
    await add.getByLabel(/^Facts line/).fill('1 register · open 10 am–9 pm');
    if (shots) await page.screenshot({ path: `${shots}/branch-dialog.png` });
    await add.getByRole('button', { name: 'Add branch' }).click();
    await expect(page.getByText('Khulna · Sonadanga added')).toBeVisible();

    const row = page.getByRole('main').getByRole('listitem').filter({ hasText: 'Khulna · Sonadanga' });
    await expect(row).toContainText('Retail shop · POS · click & collect');
    await expect(row).toContainText('Shop 4, Sonadanga Bus Stand Market, Sonadanga, Khulna');
    await expect(row).toContainText('Near Opposite the bus stand');
    if (shots) await page.screenshot({ path: `${shots}/branches.png`, fullPage: true });

    // Switched off — still listed, never deleted.
    await page.getByRole('button', { name: 'Edit Khulna · Sonadanga' }).click();
    const edit = page.getByRole('dialog', { name: 'Edit Khulna · Sonadanga' });
    await expect(edit.getByLabel(/^Short code/)).toHaveCount(0);
    await edit.getByLabel('Active').uncheck();
    await edit.getByRole('button', { name: 'Save branch' }).click();
    await expect(page.getByText('Khulna · Sonadanga saved')).toBeVisible();
    await expect(row).toContainText('Switched off');

    // The default location cannot be switched off.
    const first = page.getByRole('main').getByRole('listitem').filter({ hasText: 'Default' }).first();
    await first.getByRole('button', { name: /^Edit / }).click();
    await expect(page.getByRole('dialog').getByLabel('Active')).toBeDisabled();
});

test('owner sets the discount cap and the due limit under Point of sale', async ({ page }) => {
    await signIn(page);
    await page.getByRole('link', { name: 'Point of sale' }).click();
    const cap = page.getByLabel('Maximum cashier discount');
    await expect(cap).toHaveValue('10');
    await expect(page.getByText('Not saved yet — the store default of 10% applies. 0 turns cashier discounts off.')).toBeVisible();
    await cap.fill('15');
    const due = page.getByLabel('Due limit per customer');
    await expect(due).toHaveValue('5000');
    await due.fill('8000');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('Point of sale settings saved')).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/pos-settings.png` });

    await page.reload();
    await expect(page.getByLabel('Maximum cashier discount')).toHaveValue('15');
    await expect(page.getByLabel('Due limit per customer')).toHaveValue('8000');
});

test('owner edits the tagline under Site & branding through the sticky save bar', async ({ page }) => {
    await signIn(page);
    const nav = page.getByRole('navigation', { name: 'Store settings sections' });
    await nav.getByRole('link', { name: /Site & branding/ }).click();
    await expect(page.getByRole('heading', { name: 'Site & branding' })).toBeVisible();

    // Nothing changed yet: no save bar.
    await expect(page.getByRole('button', { name: 'Save changes' })).toHaveCount(0);

    const tagline = page.getByLabel('Tagline', { exact: true });
    await tagline.fill('Toys & learning for every age');
    await page.getByLabel('Tagline · বাংলা').fill('সব বয়সের খেলনা ও শেখা');
    await expect(page.getByText(/Unsaved changes in Site & branding/)).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/site-branding.png`, fullPage: true });
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('button', { name: 'Save changes' })).toHaveCount(0);

    await page.reload();
    await expect(page.getByLabel('Tagline', { exact: true })).toHaveValue('Toys & learning for every age');
    await expect(page.getByLabel('Tagline · বাংলা')).toHaveValue('সব বয়সের খেলনা ও শেখা');

    // Discard puts the saved value back.
    await page.getByLabel('Tagline', { exact: true }).fill('Something else');
    await page.getByRole('button', { name: 'Discard' }).click();
    await expect(page.getByLabel('Tagline', { exact: true })).toHaveValue('Toys & learning for every age');
});

test('a single shop is refused while the demo branches are active, and names them', async ({ page }) => {
    await signIn(page);
    const nav = page.getByRole('navigation', { name: 'Store settings sections' });
    await nav.getByRole('link', { name: 'Branches & warehouses' }).click();

    const setup = page.getByRole('group', { name: 'Branch setup' });
    await expect(setup.getByRole('radio', { name: /Multiple branches/ })).toBeChecked();
    if (shots) await page.screenshot({ path: `${shots}/branch-setup-multi.png`, fullPage: true });

    // The radio is visually replaced (Figma Radio · on/off): choose it through its card, as a pointer user does.
    await setup.getByText('Single shop', { exact: true }).click();
    await expect(setup.getByRole('radio', { name: /Single shop/ })).toBeChecked();
    await expect(page.getByText('Unsaved changes in Branches & warehouses')).toBeVisible();
    await page.getByRole('button', { name: 'Save changes' }).click();
    const alert = page.getByRole('alert').filter({ hasText: 'Switch off the other branches first' });
    await expect(alert).toBeVisible();
    await expect(alert.getByText(/^Chattogram · Agrabad — \d+ units$/)).toBeVisible();
    await expect(alert.getByRole('link', { name: 'Transfer stock' })).toHaveAttribute('href', /\/admin\/inventory(\?transfer=\d+)?$/);
    if (shots) await page.screenshot({ path: `${shots}/branch-setup-blocked.png`, fullPage: true });

    // Nothing was saved: "Keep multiple branches" puts the choice back, and the store still runs its branches.
    await alert.getByRole('button', { name: 'Keep multiple branches' }).click();
    await expect(page.getByRole('button', { name: 'Save changes' })).toHaveCount(0);
    await page.reload();
    await expect(page.getByRole('group', { name: 'Branch setup' }).getByRole('radio', { name: /Multiple branches/ })).toBeChecked();
    await expect(page.getByRole('button', { name: 'Add branch' })).toBeVisible();
});

test('sidebar branch switcher: the working branch is chosen from the menu and kept on this device', async ({ page }) => {
    await signIn(page);
    const button = page.getByRole('button', { name: /^Working branch:/ }).first();
    await button.click();
    const menu = page.getByRole('menu', { name: 'Working branch' });
    await expect(menu.getByRole('menuitemradio')).toHaveCount(3);
    await expect(menu.getByRole('menuitem', { name: 'Manage branches' })).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/branch-switcher-open.png`, clip: { x: 0, y: 0, width: 260, height: 640 } });
    await menu.getByRole('menuitemradio', { name: /Chattogram/ }).click();
    await expect(button).toHaveAccessibleName(/^Working branch:\s*Chattogram/);
    await page.reload();
    await expect(page.getByRole('button', { name: /^Working branch:\s*Chattogram/ }).first()).toBeVisible();

    // Collapsed rail: an icon button; its menu opens beside the rail.
    await page.getByRole('button', { name: 'Collapse sidebar' }).click();
    const rail = page.getByRole('button', { name: /^Working branch:/ }).first();
    await rail.click();
    await expect(menu).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/branch-switcher-rail.png`, clip: { x: 0, y: 0, width: 360, height: 560 } });
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Expand sidebar' }).click();

    // A single shop (the API answer rewritten): no branch block at all — the nav follows the brand row.
    await page.route('**/api/v1/admin/auth/me', async (route) => {
        const response = await route.fetch();
        const body = (await response.json()) as { branches: { multi_branch: boolean; locations: { is_default: boolean }[] } };
        body.branches = { multi_branch: false, locations: body.branches.locations.filter((l) => l.is_default) };
        await route.fulfill({ response, json: body });
    });
    await page.reload();
    await expect(page.getByRole('navigation', { name: 'Main navigation' }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: /^Working branch:/ })).toHaveCount(0);
    await expect(page.getByRole('complementary').first().getByText('Dhaka HQ · Mirpur')).toHaveCount(0);
    if (shots) await page.screenshot({ path: `${shots}/branch-switcher-single.png`, clip: { x: 0, y: 0, width: 260, height: 240 } });

    if (shots) {
        // The single-shop Branches section, for the Figma check (410:69706).
        await page.route('**/api/v1/admin/settings/branch-mode', async (route) => {
            const response = await route.fetch();
            const body = (await response.json()) as Record<string, unknown>;
            await route.fulfill({ response, json: { ...body, multi_branch: false, saved: true } });
        });
        await page.goto('/admin/settings/branches');
        await expect(page.getByRole('heading', { name: 'Shop & warehouse' })).toBeVisible();
        await page.screenshot({ path: `${shots}/branch-setup-single.png`, fullPage: true });
    }
});
