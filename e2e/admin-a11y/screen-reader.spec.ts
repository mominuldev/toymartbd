import { createHmac } from 'node:crypto';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { bn, en } from '../../resources/js/admin/i18n/messages';

// Phase 10 batch 4: screen-reader readiness (docs/audits/SCREEN-READER-CHECKLIST.md). axe proves
// the markup is valid; these assert what a screen reader would actually announce on the key
// flows — the accessibility tree Chrome hands to VoiceOver / TalkBack / NVDA: landmarks, the
// heading outline, the name, role and state of each control, which live regions exist and what
// they say, and how dialogs are named and where focus lands. Partial aria snapshots: a listed
// node must be there, in that order; unlisted nodes are allowed. Data that moves (counts, dates,
// order numbers) is matched by pattern, and nothing here writes to the demo data except the
// two-factor enrolment of its own account (order_operator@), which it tolerates already being on.

type Locale = 'en' | 'bn';
type Key = keyof typeof en;
const m = (locale: Locale, key: Key, vars: Record<string, string> = {}): string =>
    Object.entries(vars).reduce((text, [k, v]) => text.replaceAll(`{${k}}`, v), (locale === 'bn' ? bn : en)[key]);

test.use({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });

async function signIn(page: Page, { locale = 'en', email = 'owner@toymart.test' }: { locale?: Locale; email?: string } = {}) {
    await page.addInitScript((l) => localStorage.setItem('tm-admin-locale', l), locale);
    await page.goto('/admin/login');
    await page.getByRole('textbox', { name: m(locale, 'auth.email') }).fill(email);
    await page.getByRole('textbox', { name: m(locale, 'auth.password') }).fill('password');
    await page.getByRole('button', { name: m(locale, 'auth.submit') }).click();
}

async function signInOwner(page: Page) {
    await signIn(page);
    await expect(page).not.toHaveURL(/\/login/);
}

async function settle(page: Page) {
    await page.waitForLoadState('networkidle');
    await page.waitForFunction(() => document.querySelectorAll('[aria-busy="true"]').length === 0, null, { timeout: 15_000 }).catch(() => undefined);
}

/** The element that has focus, as a screen reader names it: role + accessible name. */
async function focused(page: Page): Promise<string> {
    return page.evaluate(() => {
        const el = document.activeElement as HTMLElement | null;
        if (!el || el === document.body) return 'body';
        const labelled = el.getAttribute('aria-labelledby');
        const name =
            el.getAttribute('aria-label') ??
            (labelled ? labelled.split(' ').map((id) => document.getElementById(id)?.textContent ?? '').join(' ') : null) ??
            (el.id ? document.querySelector(`label[for="${el.id}"]`)?.textContent : null) ??
            el.textContent ??
            '';
        return `${el.getAttribute('role') ?? el.tagName.toLowerCase()} ${name.trim()}`;
    });
}

/** The shell every signed-in screen shares: skip link first, then rail, top bar and one main. */
async function expectShell(page: Page, h1: string | RegExp) {
    await expect(page.locator('body')).toMatchAriaSnapshot(`
      - link "Skip to content"
      - complementary:
        - navigation "Main navigation"
      - banner:
        - searchbox
        - group "Language":
          - button "EN" [pressed]
          - button "বাং"
      - main
    `);
    await expect(page.getByRole('main')).toHaveCount(1);
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(h1);
    // The rail announces the shop's name once, not twice (sr-only copy for the collapsed rail only).
    const rail = page.getByRole('complementary').filter({ has: page.getByRole('navigation', { name: 'Main navigation' }) });
    const brand = await rail.evaluate((el) => (el as HTMLElement).innerText);
    expect(brand.split('Toy Mart BD').length - 1, 'shop name announced once in the expanded rail').toBe(1);
}

/** Heading levels never skip downward (h1 → h3 without an h2), in document order. */
async function expectHeadingOutline(page: Page) {
    const levels = await page.locator('main :is(h1,h2,h3,h4,h5,h6)').evaluateAll((hs) =>
        hs.filter((h) => (h as HTMLElement).offsetParent !== null).map((h) => Number(h.tagName[1])),
    );
    expect(levels[0], 'main starts with the h1').toBe(1);
    for (let i = 1; i < levels.length; i++) expect(levels[i]! - levels[i - 1]!, `heading ${i}: h${levels[i - 1]} → h${levels[i]}`).toBeLessThanOrEqual(1);
}

// ── Sign in and the two-factor code step ────────────────────────────────────────────────────

test('sign in: form names, the error alert tied to both fields, in English and Bangla', async ({ page }) => {
    await page.goto('/admin/login');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.locator('body')).toMatchAriaSnapshot(`
      - main:
        - group "Language":
          - button "EN" [pressed]
          - button "বাং"
        - heading "Sign in to Admin" [level=1]
        - textbox "Email"
        - textbox "Password"
        - checkbox "Keep me signed in on this device" [checked=false]
        - button "Sign in"
    `);
    // Each language's label is spoken in its own voice.
    await expect(page.getByRole('button', { name: 'বাং' })).toHaveAttribute('lang', 'bn');

    await page.getByRole('textbox', { name: 'Email' }).fill('owner@toymart.test');
    await page.getByRole('textbox', { name: 'Password' }).fill('not-the-password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    const alert = page.getByRole('alert');
    await expect(alert).toHaveText(en['auth.failed']);
    for (const name of ['Email', 'Password']) {
        const field = page.getByRole('textbox', { name });
        await expect(field).toHaveAttribute('aria-invalid', 'true');
        await expect(field).toHaveAccessibleDescription(en['auth.failed']);
    }

    // Bangla: the switch flips the document language, so VoiceOver / TalkBack change voice.
    await page.getByRole('button', { name: 'বাং' }).click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(bn['auth.title']);
    await expect(page.getByRole('button', { name: 'বাং' })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('textbox', { name: bn['auth.email'] })).toBeVisible();
    await expect(page.getByRole('textbox', { name: bn['auth.password'] })).toBeVisible();
    await expect(page.getByRole('button', { name: bn['auth.submit'] })).toBeVisible();
});

/** Sign `email` in and turn two-factor on through the real setup screen, unless it already is. */
async function ensureTwoFactor(browser: Browser, email: string) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    const page = await context.newPage();
    await signIn(page, { email });
    const challenge = page.getByRole('heading', { name: en['twoFactor.challenge.title'] });
    const home = page.waitForURL((url) => !url.pathname.endsWith('/login'));
    if ((await Promise.race([challenge.waitFor().then(() => 'challenge'), home.then(() => 'in')])) === 'in') {
        await page.goto('/admin/account/two-factor');
        await page.getByRole('button', { name: en['twoFactor.start'] }).click();
        await expect(page.locator('code').first()).toBeVisible();
        const secret = (await page.locator('code').first().innerText()).replace(/\s+/g, '');
        const left = 30_000 - (Date.now() % 30_000);
        if (left < 4_000) await page.waitForTimeout(left + 250);
        await page.getByLabel(en['twoFactor.code'], { exact: false }).fill(totp(secret));
        await page.getByRole('button', { name: en['twoFactor.confirm'] }).click();
        await expect(page.getByRole('list', { name: en['twoFactor.codesTitle'] })).toBeVisible();
    }
    await context.close();
}

test('two-factor code step: heading, focus in the field, mode switch, error tied to the field', async ({ browser, page }) => {
    const email = 'order_operator@toymart.test';
    await ensureTwoFactor(browser, email);

    await signIn(page, { email });
    await expect(page.getByRole('heading', { name: 'Enter your sign-in code', level: 1 })).toBeVisible();
    await expect(page.locator('body')).toMatchAriaSnapshot(`
      - main:
        - heading "Enter your sign-in code" [level=1]
        - textbox "Code from the app"
        - button "Sign in"
        - button "Use a recovery code"
        - button "Start again"
    `);
    // The step moves focus to its one field, so the next thing heard is "Code from the app, edit text".
    await expect(page.getByRole('textbox', { name: 'Code from the app' })).toBeFocused();

    await page.getByRole('button', { name: 'Use a recovery code' }).click();
    const recovery = page.getByRole('textbox', { name: 'Recovery code' });
    await expect(recovery).toBeFocused();
    await expect(page.getByRole('button', { name: 'Use the app code' })).toBeVisible();

    await recovery.fill('wrong-code-1234');
    await page.getByRole('button', { name: 'Sign in' }).click();
    const alert = page.getByRole('alert');
    await expect(alert).toBeVisible();
    await expect(recovery).toHaveAttribute('aria-invalid', 'true');
    await expect(recovery).toHaveAccessibleDescription((await alert.innerText()).trim());

    await page.getByRole('button', { name: 'Start again' }).click();
    await expect(page.getByRole('textbox', { name: 'Email' })).toBeFocused();
});

// ── Staff & roles ───────────────────────────────────────────────────────────────────────────

test('staff & roles: people table, manage dialog, and the permission matrix', async ({ page }) => {
    await signInOwner(page);
    await page.goto('/admin/staff');
    await settle(page);
    await expectShell(page, 'Staff & roles');
    await expectHeadingOutline(page);
    await expect(page.getByRole('main')).toMatchAriaSnapshot(`
      - navigation "Breadcrumb"
      - heading "Staff & roles" [level=1]
      - button "Add staff"
      - navigation "Staff & roles sections":
        - link /People \\d+/
        - link /Roles & permissions \\d+/
      - table:
        - rowgroup:
          - row:
            - columnheader "Person"
            - columnheader "Role"
            - columnheader "Status"
            - columnheader "Two-factor"
            - columnheader "Last sign-in"
            - columnheader "Actions"
    `);
    await expect(page.getByRole('link', { name: /^People/ })).toHaveAttribute('aria-current', 'page');
    // Every row's action names its person — "Manage" alone would be ambiguous in a list of seven.
    const manage = page.getByRole('button', { name: /^Manage / });
    expect(await manage.count()).toBeGreaterThanOrEqual(7);
    // Off + "their role can see money" is spoken in the cell, not shown by colour alone.
    await expect(page.getByRole('row', { name: /Farhana/ }).getByRole('cell', { name: /must set up two-factor/ })).toBeVisible();

    const trigger = page.getByRole('button', { name: /^Manage Farhana/ });
    await trigger.click();
    const dialog = page.getByRole('dialog', { name: /^Manage Farhana/ });
    await expect(dialog).toBeVisible();
    await expect(dialog).toMatchAriaSnapshot(`
      - dialog:
        - heading /Manage Farhana/ [level=2]
        - group:
          - textbox "Full name"
          - combobox "Role":
            - option "Manager" [selected]
        - heading "Set a new temporary password" [level=3]
        - textbox "Temporary password"
        - button "Set password"
        - heading "Two-factor sign-in" [level=3]
        - heading "Account status" [level=3]
        - button "Deactivate"
        - button "Cancel"
        - button "Save changes"
    `);
    expect(await dialog.evaluate((d) => d.contains(document.activeElement)), `focus inside the dialog, not ${await focused(page)}`).toBe(true);
    await expect(page.getByRole('textbox', { name: 'Temporary password' })).toHaveAccessibleDescription(/At least 12 characters/);
    // The "must set up two-factor" sentence is printed under the chip: spoken once, not twice.
    const said = await dialog.evaluate((d) => (d as HTMLElement).innerText + [...d.querySelectorAll('.sr-only')].map((e) => e.textContent).join(' '));
    expect(said.split('they must set up two-factor').length - 1, 'two-factor reason announced once in the dialog').toBe(1);
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.getByRole('link', { name: /^Roles & permissions/ }).click();
    await settle(page);
    await expect(page.getByRole('link', { name: /^Roles & permissions/ })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('main')).toMatchAriaSnapshot(`
      - table "Permissions by role":
        - caption: Permissions by role
        - rowgroup:
          - row:
            - columnheader "Permission"
            - columnheader /Super admin/
            - columnheader /Manager/
        - rowgroup:
          - row "General":
            - columnheader "General"
          - row:
            - rowheader /View dashboard/
            - 'cell "Owner role: every permission, always"':
              - 'img "Owner role: every permission, always"'
            - cell "View dashboard for Manager":
              - checkbox "View dashboard for Manager"
    `);
    // A persistent, empty polite status is waiting before anything changes…
    const status = page.getByRole('main').getByRole('status');
    await expect(status).toHaveCount(1);
    await expect(status).toHaveText('');
    // …so ticking a box is announced, and the changed column says so in words, not only by tint.
    const box = page.getByRole('checkbox', { name: 'View dashboard for Viewer' });
    const was = await box.isChecked();
    await box.press('Space');
    await expect(box).toBeChecked({ checked: !was });
    await expect(status).toHaveText(en['roles.unsaved'].replace('{count}', '1'));
    await expect(page.getByRole('columnheader', { name: new RegExp(`^Viewer.*${escape(en['roles.changed'])}`) })).toBeVisible();
    const bar = page.getByRole('region', { name: en['roles.unsaved'].replace('{count}', '1') });
    await bar.getByRole('button', { name: en['roles.discard'] }).click();
    await expect(box).toBeChecked({ checked: was });
    await expect(status).toHaveText('');
});

// ── Orders board → order detail ─────────────────────────────────────────────────────────────

test('orders board → order detail: filters, table, the three tracks, and the hold dialog', async ({ page }) => {
    await signInOwner(page);
    await page.goto('/admin/orders');
    await settle(page);
    await expectShell(page, 'Orders');
    await expect(page.getByRole('main')).toMatchAriaSnapshot(`
      - heading "Orders" [level=1]
      - combobox "Date range"
      - link "New order"
      - group "Order counts":
        - button /^Pending \\d+ Waiting for confirmation/ [pressed=false]
        - button /^Verify \\d+/ [pressed=false]
        - button /^Ready to ship \\d+/ [pressed=false]
      - navigation "Order status":
        - button /All \\d+/ [pressed]
        - button /Pending \\d+/ [pressed=false]
        - button /Confirmed \\d+/ [pressed=false]
      - button "More statuses"
      - button /Anomalies \\d+/ [pressed=false]
      - searchbox "Search orders"
      - combobox "Payment status"
      - combobox "Source"
      - combobox "Risk"
      - switch "Only anomalies" [checked=false]
      - button "Columns"
      - table:
        - rowgroup:
          - row:
            - columnheader "Select every order on this page":
              - checkbox "Select every order on this page"
            - columnheader "Order"
            - columnheader "Placed"
            - columnheader "Customer"
            - columnheader "Total"
            - columnheader "Payment"
            - columnheader "Status"
            - columnheader "Risk"
            - columnheader "Courier"
            - columnheader "Actions"
        - rowgroup:
          - row:
            - cell /Select TMB-\\d+-\\d+/:
              - checkbox /Select TMB-\\d+-\\d+/
            - cell:
              - link /TMB-\\d+-\\d+/
    `);
    // A status filter is a toggle: pressing it is announced as "pressed".
    await page.getByRole('button', { name: /^Delivered \d+/ }).click();
    await expect(page.getByRole('button', { name: /^Delivered \d+/ })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('button', { name: /^All \d+/ })).toHaveAttribute('aria-pressed', 'false');
    // A web order: a POS sale (another spec may have just rung one up) is delivered too, but its
    // detail page has a different shape (no courier track, the Sell breadcrumb).
    await page.getByRole('combobox', { name: 'Source' }).selectOption('web');
    await expect(page).toHaveURL(/source=web/);
    // The board keeps the last page on screen while the filtered one loads: wait for a web row.
    await expect(page.getByRole('table').getByRole('row').nth(1)).toContainText('web');
    await settle(page);

    // Read the number and its target in one step: the list may still re-render after the
    // filter, and a separate read-then-click can land on a different row.
    const link = page.getByRole('table').getByRole('link', { name: /^TMB-/ }).first();
    const [number, href] = await link.evaluate((a) => [(a.textContent ?? '').trim(), a.getAttribute('href') ?? '']);
    await page.goto(href.startsWith('/admin') ? href : `/admin${href}`);
    await settle(page);
    await expectShell(page, number);
    await expectHeadingOutline(page);
    await expect(page.getByRole('main')).toMatchAriaSnapshot(`
      - navigation "Breadcrumb":
        - list:
          - listitem:
            - link "Orders"
      - heading "${number}" [level=1]
      - button "Print invoice"
      - region "Where this order is":
        - heading "Where this order is" [level=2]
        - list:
          - listitem /Pending — (done|current)/
        - list:
          - listitem /Unpaid — (done|current)/
        - list:
          - listitem /Not sent — (done|current)/
      - region /Items \\(\\d+\\)/:
        - heading /Items \\(\\d+\\)/ [level=2]
      - region "Payments"
      - region "Activity":
        - combobox "Show"
        - textbox "Internal note"
        - switch "Visible to customer"
        - button "Add note"
      - region "Customer":
        - button "Copy phone number"
        - link "Call customer"
      - region "Delivery address"
      - region "Toy Mart risk score"
      - region "Courier-verified delivery record"
    `);
    // Rule 24: our score and the courier's record are two separately named regions, never one.
    // The raw column names under each track are hidden from speech (they would be spelled out).
    const tree = await page.getByRole('main').ariaSnapshot();
    expect(tree).not.toMatch(/orders\.status|payment_status|fulfillment_status/);

    // Hold: a named modal dialog, focus inside, Escape returns to the trigger. (Not submitted.)
    await page.goto('/admin/orders');
    await settle(page);
    await page.getByRole('button', { name: /^Confirmed \d+/ }).click();
    // The board keeps the last page on screen while the filtered one loads: wait for a confirmed
    // row, then read the number and its target in one step (as above).
    await expect(page.getByRole('table').getByRole('row').nth(1)).toContainText('Confirmed');
    await settle(page);
    const confirmed = page.getByRole('table').getByRole('link', { name: /^TMB-/ }).first();
    const [held, heldHref] = await confirmed.evaluate((a) => [(a.textContent ?? '').trim(), a.getAttribute('href') ?? '']);
    await page.goto(heldHref.startsWith('/admin') ? heldHref : `/admin${heldHref}`);
    await settle(page);
    const hold = page.getByRole('button', { name: 'Hold', exact: true });
    await hold.click();
    const dialog = page.getByRole('dialog', { name: `Hold ${held}?` });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('heading', { level: 2 })).toHaveText(`Hold ${held}?`);
    expect(await dialog.evaluate((d) => d.contains(document.activeElement))).toBe(true);
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(hold).toBeFocused();
});

// ── Create order ────────────────────────────────────────────────────────────────────────────

test('create order: numbered sections, landmark hint, product search announced, payment radios', async ({ page }) => {
    await signInOwner(page);
    await page.goto('/admin/orders/new');
    await settle(page);
    await expectShell(page, 'New order');
    await expectHeadingOutline(page);
    await expect(page.getByRole('main')).toMatchAriaSnapshot(`
      - heading "New order" [level=1]
      - heading "1 · Customer" [level=2]
      - textbox "Phone"
      - combobox "Order source"
      - textbox "Customer name"
      - heading "2 · Delivery address" [level=2]
      - combobox "Division"
      - combobox "District"
      - combobox "Upazila / thana" [disabled]
      - textbox "Area"
      - textbox "Address line"
      - textbox "Landmark"
      - heading "3 · Products" [level=2]
      - searchbox "Search name, SKU or scan barcode"
      - heading "4 · Notes" [level=2]
      - textbox "Note for the packer"
      - textbox "Internal note"
      - region "Summary"
      - region "Payment":
        - radiogroup "Payment":
          - radio /Cash on delivery/ [checked]
      - button /Create order/ [disabled]
    `);
    // Rule 31: the landmark field's reason is its description, heard with the field.
    await expect(page.getByRole('textbox', { name: 'Landmark' })).toHaveAccessibleDescription(/landmarks/);

    // Product search: a plain search box (not a broken combobox); the result count is spoken
    // through a status that was already on the page; each result is an "Add …" button.
    const search = page.getByRole('searchbox', { name: 'Search name, SKU or scan barcode' });
    const status = page.getByRole('main').getByRole('status').filter({ hasText: /matching products|No sellable product/ });
    await search.fill('lego');
    await expect(status).toHaveText(/^\d+ matching products — press Tab to choose one$/);
    const results = page.getByRole('list', { name: 'Search name, SKU or scan barcode' });
    const add = results.getByRole('button', { name: /^Add / }).first();
    await expect(add).toBeVisible();
    const name = (await add.getAttribute('aria-label'))!.replace(/^Add /, '');
    await search.press('Tab');
    await expect(add).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('button', { name: `One more ${name}` })).toBeVisible();
    await expect(page.getByRole('button', { name: `One fewer ${name}` })).toBeDisabled();
    await expect(page.getByRole('button', { name: `Remove ${name}` })).toBeVisible();

    await search.fill('zzzz-no-such-toy');
    await expect(status).toHaveText(en['orders.compose.searchEmpty'].replace('{term}', 'zzzz-no-such-toy'));
});

// ── Product edit ────────────────────────────────────────────────────────────────────────────

test('product edit: section nav, bilingual fields in their own language, editor, tabs, variant grid, save state', async ({ page }) => {
    await signInOwner(page);
    await page.goto('/admin/products/1');
    await settle(page);
    const title = (await page.getByRole('heading', { level: 1 }).innerText()).trim();
    await expectShell(page, title);
    await expectHeadingOutline(page);
    await expect(page.getByRole('main')).toMatchAriaSnapshot(`
      - navigation "Product sections":
        - link "General"
        - link "Media"
        - link "Pricing"
        - link "Variants"
      - group:
        - region "General":
          - heading "General" [level=2]
          - textbox "Name (English)"
          - textbox "নাম (বাংলা)"
          - toolbar "Description formatting":
            - combobox "Text style"
            - button "Bold"
            - button "Italic"
          - textbox "Description"
          - combobox "Category"
          - combobox "Brand"
        - region "Pricing":
          - textbox "VAT"
        - region "Variants":
          - table:
            - rowgroup:
              - row:
                - columnheader "Select all variants"
        - region "Search & social":
          - tablist "Search & social":
            - tab "Basics" [selected]
            - tab "Google preview" [selected=false]
          - tabpanel "Basics":
            - textbox "Meta title"
            - textbox "Meta description"
        - complementary:
          - region "Status & visibility":
            - group "Sales channels":
              - switch "Online store"
          - region "Product URL":
            - textbox "Slug"
    `);
    // Bangla fields are tagged lang="bn", so the reader switches to a Bangla voice for them.
    await expect(page.getByRole('textbox', { name: 'নাম (বাংলা)' })).toHaveAttribute('lang', 'bn');
    await expect(page.getByRole('textbox', { name: 'Description', exact: true })).toHaveAttribute('aria-multiline', 'true');
    // Every cell in the variant grid names its variant: "Price — 90 pcs / Box", not just "Price".
    const prices = page.getByRole('textbox', { name: /^Price — / });
    expect(await prices.count()).toBeGreaterThan(0);

    // The save-state line is a polite live region: an edit is announced as an unsaved change.
    const name = page.getByRole('textbox', { name: 'Name (English)' });
    await expect(page.getByText(en['products.noChanges'])).toBeVisible();
    await name.fill('');
    await expect(page.locator('[aria-live="polite"]').filter({ hasText: en['products.unsavedOne'] })).toHaveCount(1);
    await page.getByRole('button', { name: en['products.saveChanges'] }).click();
    await expect(name).toHaveAttribute('aria-invalid', 'true');
    await expect(name).not.toHaveAccessibleDescription('');
    await page.getByRole('button', { name: en['products.discard'] }).click();
    await expect(name).toHaveValue(title);
});

// ── Courier booking ─────────────────────────────────────────────────────────────────────────

test('courier booking: the drawer is a named dialog, the block reason is an alert, Escape returns focus', async ({ page }) => {
    await signInOwner(page);
    // A ready-to-ship demo order with no shipment yet (DemoOrdersSeeder); nothing is booked here.
    const ids = await page.evaluate(async () => {
        const res = await fetch('/api/v1/admin/orders?filter[status]=ready_to_ship', { headers: { Accept: 'application/json' }, credentials: 'include' });
        return ((await res.json()) as { data: Array<{ id: number }> }).data.map((o) => o.id);
    });
    let trigger = page.getByRole('button', { name: 'Book courier', exact: true });
    for (const id of ids) {
        await page.goto(`/admin/orders/${id}`);
        await settle(page);
        if (await trigger.count()) break;
    }
    await expect(trigger, 'a ready-to-ship demo order with no shipment').toBeVisible();
    const number = (await page.getByRole('heading', { level: 1 }).innerText()).trim();
    await expect(page.getByRole('region', { name: 'Shipment' })).toBeVisible();

    await trigger.click();
    const dialog = page.getByRole('dialog', { name: `Book courier — ${number}` });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('group', { name: 'Courier' })).toBeVisible();
    await settle(page);
    await expect(dialog).toMatchAriaSnapshot(`
      - dialog:
        - heading "Book courier — ${number}" [level=2]
        - button "Close"
        - note
        - group "Courier":
          - radio /^Steadfast/
          - radio /^Pathao/
        - textbox "Weight"
        - textbox /Note to courier/
        - button "Cancel"
        - alert
        - button /Book with/ [disabled]
    `);
    expect(await dialog.evaluate((d) => d.contains(document.activeElement)), `focus inside the drawer, not ${await focused(page)}`).toBe(true);
    await expect(dialog.getByRole('textbox', { name: 'Weight' })).toHaveAccessibleDescription(/variant weights/);
    // No merchant account (rule 33a): why booking is blocked is an alert naming the screen that fixes it.
    await expect(dialog.getByRole('alert')).toHaveText(/courier settings/);
    // The result/progress live region is mounted before any booking, empty, inside the dialog.
    await expect(dialog.getByRole('status')).toHaveText('');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    trigger = page.getByRole('button', { name: 'Book courier', exact: true });
    await expect(trigger).toBeFocused();
});

function escape(text: string): string {
    return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function base32(secret: string): Buffer {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret.replace(/\s+/g, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    const bytes: number[] = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
    return Buffer.from(bytes);
}

/** The 6-digit RFC 6238 code an authenticator app shows now (as in axe.spec.ts). */
function totp(secret: string): string {
    const message = Buffer.alloc(8);
    message.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / 30)));
    const hmac = createHmac('sha1', base32(secret)).update(message).digest();
    const offset = hmac[hmac.length - 1]! & 0xf;
    return String((hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}
