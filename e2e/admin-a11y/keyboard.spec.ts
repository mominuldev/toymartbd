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

/** Tab a dozen times inside an open modal <dialog>: focus never reaches the page behind it. */
async function assertTrapped(page: Page, dialog: Locator): Promise<void> {
    for (let i = 0; i < 12; i++) {
        await page.keyboard.press('Tab');
        expect(await dialog.evaluate((d) => d.contains(document.activeElement) || document.activeElement === document.body), `Tab ${i + 1} left the dialog`).toBe(true);
    }
}

async function signInOwner(page: Page): Promise<void> {
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill('owner@toymart.test');
    await page.locator('input[type="password"]').fill('password');
    await page.locator('button[type="submit"]').click();
    await expect(page).not.toHaveURL(/\/login/);
}

// Phase 10 batch 3: Staff & roles and Store settings dialogs, and the editable permission matrix,
// by keyboard alone — open from the trigger, focus inside, trapped, Escape, focus back on the trigger.
test('Phase 10: staff and branch dialogs, and the permission matrix, by keyboard', async ({ page }) => {
    await signInOwner(page);

    await page.goto('/admin/staff');
    const add = page.getByRole('button', { name: 'Add staff', exact: true });
    await tabTo(page, add);
    await page.keyboard.press('Enter');
    const addDialog = page.getByRole('dialog', { name: 'Add staff' });
    await expect(addDialog).toBeVisible();
    await expect(addDialog.getByLabel('Full name')).toBeFocused();
    await assertTrapped(page, addDialog);
    await page.keyboard.press('Escape');
    await expect(addDialog).toBeHidden();
    await expect(add).toBeFocused();

    const manage = page.getByRole('button', { name: /^Manage Farhana/ });
    await tabTo(page, manage);
    await page.keyboard.press('Enter');
    const manageDialog = page.getByRole('dialog', { name: /Farhana/ });
    await expect(manageDialog).toBeVisible();
    await assertTrapped(page, manageDialog);
    await page.keyboard.press('Escape');
    await expect(manageDialog).toBeHidden();
    await expect(manage).toBeFocused();

    // The matrix: every editable cell is a named checkbox; Space ticks it; the save bar is reachable.
    await page.goto('/admin/staff/roles');
    const boxes = page.locator('table input[type="checkbox"]');
    await expect(boxes.first()).toBeVisible();
    const unnamed = await boxes.evaluateAll((els) => els.filter((el) => !(el.getAttribute('aria-label') ?? '').trim()).length);
    expect(unnamed, 'permission checkboxes without a name').toBe(0);
    const first = boxes.first();
    await tabTo(page, first, 200);
    const before = await first.isChecked();
    await page.keyboard.press('Space');
    expect(await first.isChecked()).toBe(!before);
    await expect(page.getByRole('status').filter({ hasText: 'Unsaved changes to 1 role(s)' })).toHaveCount(1);
    const discard = page.getByRole('region', { name: 'Unsaved changes to 1 role(s)' }).getByRole('button', { name: 'Discard' });
    // The save bar follows the whole matrix in tab order: ~70 permissions × 6 editable roles
    // (≈420 checkboxes since POS). Reachable, but long — see ACCESSIBILITY-AND-BILINGUAL.md P10-10.
    await tabTo(page, discard, 900);
    await page.keyboard.press('Tab');
    const save = page.getByRole('button', { name: 'Save permissions' });
    await expect(save).toBeFocused();
    await page.keyboard.press('Enter');
    const confirm = page.getByRole('dialog');
    await expect(confirm).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(confirm).toBeHidden();
    await expect(save).toBeFocused();
    await discard.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('region', { name: /Unsaved changes/ })).toHaveCount(0);

    // Store settings › Branches: Add branch and a row's Edit.
    await page.goto('/admin/settings/branches');
    const addBranch = page.getByRole('button', { name: 'Add branch' });
    await tabTo(page, addBranch);
    await page.keyboard.press('Enter');
    const branch = page.getByRole('dialog', { name: 'Add branch' });
    await expect(branch).toBeVisible();
    await assertTrapped(page, branch);
    await page.keyboard.press('Escape');
    await expect(branch).toBeHidden();
    await expect(addBranch).toBeFocused();
    const edit = page.getByRole('button', { name: /^Edit / }).first();
    await tabTo(page, edit);
    await expect(edit).toBeFocused();
    await page.keyboard.press('Enter');
    // Seen twice in ~8 local runs under heavy load: Enter on a focused Edit opened nothing (not reproduced
    // in isolation). The message names what had focus, so a CI failure is diagnosable.
    await expect(page.getByRole('dialog'), `Edit branch dialog did not open; focus on ${await page.evaluate(() => document.activeElement?.outerHTML.slice(0, 120))}`).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(edit).toBeFocused();
});
