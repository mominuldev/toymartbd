import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { bn, en } from '../../resources/js/admin/i18n/messages';

// Phase 10: the POS register for offline (in-store) selling — accessibility on every device the
// shop may use (owner, 2026-11-15: "support all device"). Three parts, all on the real app with
// the demo data, signed in as the owner (super_admin: pos.use + pos.sell_on_due):
//
//   1. axe (WCAG 2.2 A/AA) over every register state — open a drawer, empty and filled cart,
//      Customer (F9), coupon, Collect payment (split cash + Due), Sale complete with the
//      receipt in English AND Bangla (owner: both, the cashier picks), Collect due, Held sales
//      (F8), Close drawer and its result — at 1440 (desktop till), 768 (tablet) and 390
//      (phone), in English and Bangla, Admin Light and Admin Dark. Below 1024 the same states
//      are reached through the Products / Cart switch, and every control must be a 44px target.
//   2. Screen reader: what the accessibility tree announces on the register, the payment
//      dialog and Sale complete — names, states, live regions, focus.
//   3. Keyboard only: a wedge scanner and the keyboard, no pointer — scan, Enter, F12, amount,
//      Enter, Enter, Enter — and the function keys return focus where the cashier was.
//
// Each test leaves the owner's drawer closed (or opens one itself), so tests are independent of
// order and of a retry. The 3x3 cube carries barcode 2000000000015 (e2e/admin-a11y/serve.sh).

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'];
const BARCODE = '2000000000015';
const SIZES = [
    { name: '1440', width: 1440, height: 900 },
    { name: '768', width: 768, height: 1024 },
    { name: '390', width: 390, height: 844 },
] as const;
/** Tailwind `lg`: below it the register shows Products or Cart, one at a time. */
const LG = 1024;
/** The POS touch-target floor below `lg` (Apple HIG / Material 44–48; WCAG 2.5.8 asks 24). */
const TARGET = 44;

type Locale = 'en' | 'bn';
type Theme = 'admin-light' | 'admin-dark';
type Key = keyof typeof en;

const m = (locale: Locale, key: Key, vars: Record<string, string | number> = {}): string =>
    Object.entries(vars).reduce((text, [k, v]) => text.replaceAll(`{${k}}`, String(v)), (locale === 'bn' ? bn : en)[key]);
const escape = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
/** The text of `key` before its first placeholder, as a "starts with" pattern. */
const startsWith = (locale: Locale, key: Key) => new RegExp(`^${escape(m(locale, key).split('{')[0]!)}`);
/** A fresh 017… number (rule 30) so each run's Due customer starts with no history. */
const freshPhone = () => `017${String(Date.now()).slice(-8)}`;
const CUSTOMER = { en: 'Rafi Ahmed', bn: 'রাফি আহমেদ' } as const;

async function signIn(page: Page, locale: Locale, theme: Theme = 'admin-light') {
    await page.addInitScript(
        ([l, th]) => {
            localStorage.setItem('tm-admin-locale', l);
            localStorage.setItem('tm-admin-theme', th);
        },
        [locale, theme] as const,
    );
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill('owner@toymart.test');
    await page.locator('input[type="password"]').fill('password');
    await page.locator('button[type="submit"]').click();
    await expect(page).not.toHaveURL(/\/login/);
}

async function settle(page: Page) {
    await page.waitForLoadState('networkidle');
    await page.waitForFunction(() => document.querySelectorAll('[aria-busy="true"]').length === 0, null, { timeout: 15_000 }).catch(() => undefined);
    await page.evaluate(() => document.fonts.ready);
}

async function audit(page: Page, testInfo: TestInfo, label: string) {
    await settle(page);
    const results = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
    const wcag = results.violations.map((v) => ({ rule: v.id, impact: v.impact, help: v.help, targets: v.nodes.slice(0, 5).map((n) => n.target.join(' ')) }));
    if (wcag.length) await testInfo.attach(`axe-${label}.json`, { body: JSON.stringify(wcag, null, 2), contentType: 'application/json' });
    const lines = wcag.map((f) => `\n  [${f.impact}] ${f.rule} — ${f.help}\n      ${f.targets.join('\n      ')}`).join('');
    expect.soft(wcag, `${label}: ${wcag.length} WCAG violation(s)${lines}`).toEqual([]);
    if ((page.viewportSize()?.width ?? LG) < LG) await expectTargets(page, label);
}

/**
 * Below `lg` every visible control in the top layer (an open dialog, else the page) is at least
 * 44 × 44 — a thumb on a phone, a finger on a counter tablet. Text fields are checked for height.
 */
async function expectTargets(page: Page, label: string) {
    const small = await page.evaluate((min) => {
        const open = [...document.querySelectorAll('dialog')].find((d) => d.open);
        const scope: ParentNode = open ?? document;
        return [...scope.querySelectorAll<HTMLElement>('button, a[href], input:not([type="hidden"]), select, textarea, [role="button"], [role="switch"], [tabindex="0"]')]
            .filter((el) => {
                const style = getComputedStyle(el);
                if (style.visibility === 'hidden' || el.closest('[aria-hidden="true"], .sr-only, [hidden]')) return false;
                const r = el.getBoundingClientRect();
                if (r.width === 0 || r.height === 0) return false;
                // A field's box is its wrapper (the Input / SearchField frame) when it has one.
                const box = el.matches('input, select, textarea') ? (el.closest('[data-field], label, .relative, div') as HTMLElement | null)?.getBoundingClientRect() ?? r : r;
                const text = el.matches('input, select, textarea');
                return box.height < min - 0.5 || (!text && box.width < min - 0.5);
            })
            .map((el) => {
                const r = el.getBoundingClientRect();
                const name = el.getAttribute('aria-label') ?? el.textContent?.trim() ?? el.tagName;
                return `${el.tagName.toLowerCase()} "${name.slice(0, 40)}" ${Math.round(r.width)}×${Math.round(r.height)}`;
            });
    }, TARGET);
    expect.soft(small, `${label}: controls under ${TARGET}px\n  ${small.join('\n  ')}`).toEqual([]);
}

const searchBox = (page: Page, locale: Locale) => page.getByRole('searchbox', { name: startsWith(locale, 'pos.search.label') });
const products = (page: Page, locale: Locale) => page.getByRole('region', { name: m(locale, 'pos.products'), exact: true });
// includeHidden: below `lg` the cart column is display:none while Products shows.
const cartPanel = (page: Page, locale: Locale) => page.getByRole('complementary', { name: m(locale, 'pos.cart.title'), includeHidden: true });
const chargeButton = (page: Page, locale: Locale) => page.getByRole('button', { name: startsWith(locale, 'pos.charge'), includeHidden: true });
/** The register's one polite status: the item count and the server's total. */
const cartStatus = (page: Page) => page.getByTestId('pos-cart-status');
const narrow = (page: Page) => (page.viewportSize()?.width ?? LG) < LG;

/** Below `lg`, flip the Products / Cart switch; at `lg` and up both columns are on screen. */
async function show(page: Page, locale: Locale, view: 'products' | 'cart') {
    if (!narrow(page)) return;
    const button = page.getByRole('group', { name: m(locale, 'pos.view.label') }).getByRole('button').nth(view === 'products' ? 0 : 1);
    await button.click();
    await expect(button).toHaveAttribute('aria-pressed', 'true');
}

/** On /admin/pos: open the drawer at Chattogram · Agrabad if none is open; `onOpener` audits first. */
async function toRegister(page: Page, locale: Locale, onOpener?: () => Promise<void>) {
    await page.goto('/admin/pos');
    const opener = page.getByRole('heading', { name: m(locale, 'pos.open.title'), level: 1 });
    await expect(opener.or(searchBox(page, locale))).toBeVisible();
    if (await opener.isVisible()) {
        if (onOpener) await onOpener();
        await page.getByLabel(m(locale, 'pos.open.branch')).selectOption({ label: locale === 'bn' ? 'চট্টগ্রাম · আগ্রাবাদ' : 'Chattogram · Agrabad' });
        await page.getByLabel(m(locale, 'pos.open.float')).fill('5000');
        await page.getByRole('button', { name: m(locale, 'pos.open.submit') }).click();
    }
    await expect(searchBox(page, locale)).toBeVisible();
    await expect(products(page, locale).getByRole('listitem').first()).toBeVisible();
}

/** Tap the first tile with stock; returns once the server's quote has priced the cart. */
async function addTile(page: Page, locale: Locale) {
    await show(page, locale, 'products');
    await products(page, locale).locator('li button:enabled').first().click();
    await show(page, locale, 'cart');
    await expect(chargeButton(page, locale)).toBeEnabled();
}

async function closeDrawer(page: Page, locale: Locale, testInfo?: TestInfo, label = (s: string) => s) {
    await page.getByRole('button', { name: m(locale, 'pos.closeDrawer') }).click();
    const dialog = page.getByRole('dialog', { name: m(locale, 'pos.close.title') });
    await expect(dialog).toBeVisible();
    if (testInfo) {
        await audit(page, testInfo, label('close'));
        await dialog.getByRole('button', { name: m(locale, 'pos.close.submit') }).click();
        await expect(dialog.getByText(m(locale, 'pos.close.invalid'))).toBeVisible();
        await audit(page, testInfo, label('close-error'));
    }
    await dialog.getByLabel(m(locale, 'pos.close.counted')).fill('5000');
    await dialog.getByRole('button', { name: m(locale, 'pos.close.submit') }).click();
    const done = dialog.getByRole('button', { name: m(locale, 'pos.close.done') });
    await expect(done).toBeFocused();
    if (testInfo) await audit(page, testInfo, label('close-result'));
    await done.click();
    await expect(page.getByRole('heading', { name: m(locale, 'pos.open.title') })).toBeVisible();
}

// ── 1 · axe on every register state: 3 sizes × 2 languages × 2 themes ─────────────────────

test.describe('POS axe', () => {
    for (const size of SIZES) {
        for (const locale of ['en', 'bn'] as const) {
            for (const theme of ['admin-light', 'admin-dark'] as const) {
                test(`${size.name} · ${locale} · ${theme}: every register state`, async ({ page }, testInfo) => {
                    await page.setViewportSize({ width: size.width, height: size.height });
                    const label = (s: string) => `pos-${size.name}-${locale}-${theme}-${s}`;
                    await signIn(page, locale, theme);

                    await toRegister(page, locale, async () => {
                        await audit(page, testInfo, label('open'));
                        await page.getByRole('button', { name: m(locale, 'pos.open.submit') }).click();
                        await expect(page.getByLabel(m(locale, 'pos.open.float'))).toHaveAttribute('aria-invalid', 'true');
                        await audit(page, testInfo, label('open-error'));
                    });
                    await audit(page, testInfo, label('register'));
                    if (narrow(page)) {
                        await show(page, locale, 'cart');
                        await audit(page, testInfo, label('cart-empty'));
                    }

                    await addTile(page, locale);
                    await audit(page, testInfo, label('cart'));

                    // Customer (F9): the dialog, a refused add, then a new named customer for Due.
                    const phone = freshPhone();
                    await page.keyboard.press('F9');
                    const customer = page.getByRole('dialog', { name: m(locale, 'pos.customerDialog.title') });
                    await expect(customer).toBeVisible();
                    await audit(page, testInfo, label('customer'));
                    await customer.getByRole('button', { name: m(locale, 'pos.customerDialog.add') }).click();
                    await expect(customer.getByRole('alert')).toBeVisible();
                    await audit(page, testInfo, label('customer-error'));
                    await customer.getByLabel(m(locale, 'pos.customerDialog.name')).fill(CUSTOMER[locale]);
                    await customer.getByLabel(m(locale, 'pos.customerDialog.phone')).fill(phone);
                    await customer.getByRole('button', { name: m(locale, 'pos.customerDialog.add') }).click();
                    await expect(customer).toBeHidden();
                    await expect(cartPanel(page, locale).getByText(CUSTOMER[locale])).toBeVisible();

                    await cartPanel(page, locale).getByRole('button', { name: m(locale, 'pos.coupon.toggle') }).click();
                    await expect(cartPanel(page, locale).getByRole('textbox', { name: m(locale, 'pos.coupon.label') })).toBeVisible();
                    await audit(page, testInfo, label('cart-customer-coupon'));
                    await cartPanel(page, locale).getByRole('button', { name: m(locale, 'pos.coupon.toggle') }).click();

                    // Collect payment: the dialog, then ৳500 cash + the rest on Due (split tender).
                    await chargeButton(page, locale).click();
                    const pay = page.getByRole('dialog', { name: m(locale, 'pos.pay.dialogTitle') });
                    await expect(pay).toBeVisible();
                    await audit(page, testInfo, label('pay'));
                    await pay.getByRole('button', { name: '৳500', exact: true }).click();
                    await expect(pay.getByRole('button', { name: m(locale, 'pos.pay.removeTender', { method: m(locale, 'pos.tender.cash') }) })).toBeVisible();
                    await pay.getByRole('button', { name: m(locale, 'pos.tender.due'), exact: true }).click();
                    await pay.getByRole('button', { name: m(locale, 'pos.pay.fill') }).click();
                    await pay.getByRole('button', { name: m(locale, 'pos.pay.add') }).click();
                    await expect(pay.getByRole('status')).toHaveText(m(locale, 'pos.pay.fullyPaid'));
                    await audit(page, testInfo, label('pay-split'));
                    await pay.getByRole('button', { name: m(locale, 'pos.pay.complete') }).click();

                    // Sale complete, with the receipt in each language.
                    await expect(page.getByRole('heading', { name: m(locale, 'pos.done.title') })).toBeVisible();
                    const languages = page.getByRole('group', { name: m(locale, 'pos.receipt.language') });
                    const receipt = page.getByTestId('pos-receipt');
                    for (const [lang, name] of [['en', 'English'], ['bn', 'বাংলা']] as const) {
                        await languages.getByRole('button', { name }).click();
                        await expect(receipt).toHaveAttribute('lang', lang);
                        await audit(page, testInfo, label(`done-receipt-${lang}`));
                    }
                    await page.getByRole('button', { name: m(locale, 'pos.done.next') }).click();

                    // Collect due: find the customer again by phone; the Due sale is their open due.
                    await page.keyboard.press('F9');
                    await expect(customer).toBeVisible();
                    await customer.getByRole('searchbox', { name: m(locale, 'pos.customerDialog.search') }).fill(phone);
                    await customer.getByRole('button', { name: new RegExp(escape(CUSTOMER[locale])) }).click();
                    await show(page, locale, 'cart');
                    await cartPanel(page, locale).getByRole('button', { name: m(locale, 'pos.customer.collect') }).click();
                    const due = page.getByRole('dialog', { name: m(locale, 'pos.dueCollect.title') });
                    await expect(due).toBeVisible();
                    await audit(page, testInfo, label('collect-due'));
                    await page.keyboard.press('Escape');
                    await expect(due).toBeHidden();

                    // Held sales (F8): with a cart to hold, then the list, then resume it and clear.
                    await addTile(page, locale);
                    await page.keyboard.press('F8');
                    const holds = page.getByRole('dialog', { name: m(locale, 'pos.holds.title') });
                    await expect(holds).toBeVisible();
                    await audit(page, testInfo, label('holds'));
                    await holds.getByLabel(m(locale, 'pos.holds.label')).fill(CUSTOMER[locale]);
                    await holds.getByRole('button', { name: m(locale, 'pos.holds.holdCurrent') }).click();
                    const resume = holds.getByRole('button', { name: m(locale, 'pos.holds.resume') }).first();
                    await expect(resume).toBeEnabled();
                    await audit(page, testInfo, label('holds-list'));
                    await resume.click();
                    await expect(holds).toBeHidden();
                    await show(page, locale, 'cart');
                    await cartPanel(page, locale).getByRole('button', { name: m(locale, 'pos.cart.clear') }).click();

                    await closeDrawer(page, locale, testInfo, label);
                });
            }
        }
    }
});

// ── 2 · Screen reader: names, states, live regions and focus ──────────────────────────────

test.describe('POS screen reader', () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    test('register: one main, the h1, named regions, tiles that say price and stock, the cart status', async ({ page }) => {
        await signIn(page, 'en');
        await toRegister(page, 'en');
        await settle(page);
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        await expect(page.getByRole('main')).toHaveCount(1);
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(/^Chattogram · Agrabad · /);
        await expect(page.getByRole('main')).toMatchAriaSnapshot(`
          - heading /Chattogram · Agrabad · / [level=1]
          - link "Dashboard"
          - status
          - button "Close drawer"
          - img /^Cashier /
          - region "Products":
            - searchbox /^Scan barcode or search/
            - button "Scan"
            - group "Categories":
              - button "All" [pressed]
            - list:
              - listitem:
                - button /^Add .+, ৳[\\d,]+, (\\d+ in stock|\\d+ left)$/
          - complementary "Current sale":
            - heading "Current sale" [level=2]
            - button "Add customer"
        `);
        // The function keys are exposed, not only printed.
        await expect(searchBox(page, 'en')).toHaveAttribute('aria-keyshortcuts', 'F1');
        await expect(chargeButton(page, 'en')).toHaveAttribute('aria-keyshortcuts', 'F12');
        // A sold-out tile is a disabled button that says so in words.
        await expect(products(page, 'en').getByRole('button', { name: /, Out of stock$/ }).first()).toBeDisabled();

        // One polite status for the sale, mounted empty before the first line…
        const status = cartStatus(page);
        await expect(status).toHaveRole('status');
        await expect(status).toHaveText('');
        // …speaks the server's count and total after a tap, and again after the stepper.
        const tile = products(page, 'en').locator('li button:enabled').first();
        await tile.click();
        await expect(status).toHaveText(/^Items: 1 · total payable ৳[\d,]+$/);
        await expect(tile).toHaveAccessibleDescription('1 in cart');
        const name = (await cartPanel(page, 'en').getByRole('button', { name: /^One more / }).getAttribute('aria-label'))!.replace(/^One more /, '');
        await cartPanel(page, 'en').getByRole('button', { name: `One more ${name}` }).click();
        await expect(status).toHaveText(/^Items: 2 · total payable ৳[\d,]+$/);
        await expect(cartPanel(page, 'en').getByRole('button', { name: `One fewer ${name}` })).toBeEnabled();
        await expect(cartPanel(page, 'en').getByRole('button', { name: `Remove ${name}` })).toBeVisible();
        // No other live region in the cart: no per-line or per-total chatter on top of the status.
        await expect(cartPanel(page, 'en').locator('[aria-live], [role="status"], [role="alert"]')).toHaveCount(0);

        await cartPanel(page, 'en').getByRole('button', { name: 'Clear' }).click();
        await expect(status).toHaveText('');
        await closeDrawer(page, 'en');
    });

    test('Collect payment: a named modal, focus in the amount, tender states, fully paid announced', async ({ page }) => {
        await signIn(page, 'en');
        await toRegister(page, 'en');
        await addTile(page, 'en');
        const charge = chargeButton(page, 'en');
        await charge.click();
        const pay = page.getByRole('dialog', { name: 'Collect payment' });
        await expect(pay).toBeVisible();
        await expect(pay).toMatchAriaSnapshot(`
          - dialog "Collect payment":
            - heading "Collect payment" [level=2]
            - button "Back to cart"
            - group "Payment method":
              - button "Cash" [pressed]
              - button "bKash" [pressed=false]
              - button "Nagad" [pressed=false]
              - button "Card" [pressed=false]
              - button "Due" [disabled]
            - textbox "Cash received"
            - button "Add payment"
            - region "Payments":
              - heading "Payments" [level=3]
              - status
            - button "Complete sale" [disabled]
        `);
        const amount = pay.getByRole('textbox', { name: 'Cash received' });
        await expect(amount).toBeFocused();
        // Due is disabled with the reason as its description, not a silent grey button.
        await expect(pay.getByRole('button', { name: 'Due', exact: true })).toHaveAccessibleDescription(en['pos.pay.dueNeedsCustomer']);
        for (const [i, method] of ['Cash', 'bKash', 'Nagad', 'Card'].entries()) await expect(pay.getByRole('button', { name: method, exact: true })).toHaveAttribute('aria-keyshortcuts', `F${i + 1}`);

        // F2: bKash is pressed, the amount is relabelled, and a transaction-ID field appears.
        await page.keyboard.press('F2');
        await expect(pay.getByRole('button', { name: 'bKash', exact: true })).toHaveAttribute('aria-pressed', 'true');
        await expect(pay.getByRole('textbox', { name: 'Amount for bKash' })).toBeFocused();
        await expect(pay.getByRole('textbox', { name: /bKash/ }).nth(1)).toBeVisible();
        await page.keyboard.press('F1');
        await expect(pay.getByRole('textbox', { name: 'Cash received' })).toBeFocused();

        // The status is mounted empty, so "fully paid" is spoken when it appears.
        const status = pay.getByRole('status');
        await expect(status).toHaveText('');
        await pay.getByRole('button', { name: '৳5,000', exact: true }).click();
        await expect(status).toHaveText(en['pos.pay.fullyPaid']);
        await expect(pay.getByRole('button', { name: 'Remove Cash payment' })).toBeVisible();

        await page.keyboard.press('Escape');
        await expect(pay).toBeHidden();
        await expect(charge).toBeFocused();
        await cartPanel(page, 'en').getByRole('button', { name: 'Clear' }).click();
        await closeDrawer(page, 'en');
    });

    test('Sale complete: focus on Start new sale that describes the sale; receipt language in its own voice', async ({ page }) => {
        await signIn(page, 'en');
        await toRegister(page, 'en');
        await addTile(page, 'en');
        await chargeButton(page, 'en').click();
        const pay = page.getByRole('dialog', { name: 'Collect payment' });
        await pay.getByRole('button', { name: 'Exact' }).click();
        await pay.getByRole('button', { name: 'Complete sale' }).click();

        const next = page.getByRole('button', { name: 'Start new sale' });
        await expect(next).toBeFocused();
        // "Start new sale, button — Sale complete. TMB-… · Walk-in customer · 2:41 pm. Total ৳… Paid ৳… Change ৳0".
        await expect(next).toHaveAccessibleDescription(/^Sale complete TMB-\d+-\d+ · Walk-in customer · .+ Total ৳[\d,]+ Paid ৳[\d,]+ Change ৳0$/);
        await expect(page.getByRole('region', { name: 'Sale complete' })).toBeVisible();

        const languages = page.getByRole('group', { name: 'Receipt language' });
        await expect(languages).toMatchAriaSnapshot(`
          - group "Receipt language":
            - button "English" [pressed]
            - button "বাংলা" [pressed=false]
        `);
        await expect(languages.getByRole('button', { name: 'বাংলা' })).toHaveAttribute('lang', 'bn');
        const receipt = page.getByTestId('pos-receipt');
        await expect(receipt).toHaveAttribute('lang', 'en');
        await expect(page.getByRole('region', { name: 'Receipt', exact: true })).toBeVisible();

        await languages.getByRole('button', { name: 'বাংলা' }).click();
        await expect(languages.getByRole('button', { name: 'বাংলা' })).toHaveAttribute('aria-pressed', 'true');
        // The receipt switches language and voice; the admin around it stays English.
        await expect(receipt).toHaveAttribute('lang', 'bn');
        await expect(receipt).toHaveClass(/lang-bn/);
        await expect(page.getByRole('region', { name: bn['pos.receipt.title'], exact: true })).toBeVisible();
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        await expect(receipt).toContainText(bn['pos.totals.total']);
        // Rule 36: Western digits on a Bangla receipt, never ০–৯.
        expect(await receipt.innerText()).not.toMatch(/[০-৯]/);

        await next.click();
        await expect(searchBox(page, 'en')).toBeFocused();
        await closeDrawer(page, 'en');
    });

    test('Bangla register: the document speaks Bangla, the receipt defaults to it and can switch to English', async ({ page }) => {
        await signIn(page, 'bn');
        await toRegister(page, 'bn');
        await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
        await expect(cartPanel(page, 'bn')).toBeVisible();
        await addTile(page, 'bn');
        await expect(cartStatus(page)).toHaveText(new RegExp(`^${escape(bn['pos.cart.items'].split('{')[0]!)}1 · `));
        await chargeButton(page, 'bn').click();
        const pay = page.getByRole('dialog', { name: bn['pos.pay.dialogTitle'] });
        await pay.getByRole('button', { name: bn['pos.pay.exact'] }).click();
        await pay.getByRole('button', { name: bn['pos.pay.complete'] }).click();
        const languages = page.getByRole('group', { name: bn['pos.receipt.language'] });
        await expect(languages.getByRole('button', { name: 'বাংলা' })).toHaveAttribute('aria-pressed', 'true');
        const receipt = page.getByTestId('pos-receipt');
        await expect(receipt).toHaveAttribute('lang', 'bn');
        expect(await receipt.innerText()).not.toMatch(/[০-৯]/);
        await languages.getByRole('button', { name: 'English' }).click();
        await expect(receipt).toHaveAttribute('lang', 'en');
        await expect(receipt).not.toHaveClass(/lang-bn/);
        await expect(receipt).toContainText(en['pos.totals.total']);
        await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
        await page.getByRole('button', { name: bn['pos.done.next'] }).click();
        await closeDrawer(page, 'bn');
    });
});

// ── 3 · Keyboard only: a wedge scanner and the keys, no pointer ───────────────────────────

/** F9, type the phone, Tab to the match, Enter: a known customer on the sale without a pointer. */
async function keyboardCustomer(page: Page, phone: string, name: RegExp) {
    await page.keyboard.press('F9');
    const dialog = page.getByRole('dialog', { name: 'Customer' });
    await expect(dialog.getByRole('searchbox', { name: en['pos.customerDialog.search'] })).toBeFocused();
    await page.keyboard.type(phone);
    const match = dialog.getByRole('button', { name });
    await expect(match).toBeVisible();
    // Search → Walk-in → the match.
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    await expect(match).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(dialog).toBeHidden();
}

/**
 * Every key press here is a real keyboard event; nothing is clicked or tapped. The cube is on the
 * demo flash sale (749, allotment 12 — DemoMarketingSeeder::LIVE_FLASH), and a promotion needs
 * the customer's phone (offer limits are per phone), so F12 first refuses and says why.
 */
async function keyboardSale(page: Page, customer: { phone: string; name: RegExp }) {
    // F1 from anywhere puts the cursor in the scan / search box.
    await page.keyboard.press('F1');
    const search = searchBox(page, 'en');
    await expect(search).toBeFocused();

    // A keyboard-wedge scanner types the code and Enter: the line goes in, the box clears, focus stays.
    for (const quantity of [1, 2]) {
        await page.keyboard.type(BARCODE);
        await page.keyboard.press('Enter');
        await expect(cartStatus(page)).toHaveText(new RegExp(`^Items: ${quantity} · total payable ৳[\\d,]+$`));
        await expect(search).toHaveValue('');
        await expect(search).toBeFocused();
    }

    // F12 obeys the Charge button: no dialog while the quote is blocked, and the reason is spoken.
    await page.keyboard.press('F12');
    await expect(page.getByRole('status').filter({ hasText: /phone to give a discount/ })).toBeVisible();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(chargeButton(page, 'en')).toHaveAccessibleDescription(/phone to give a discount/);

    await keyboardCustomer(page, customer.phone, customer.name);
    await expect(chargeButton(page, 'en')).toBeEnabled();

    // F12 → Collect payment, cursor in Cash received; type the cash, Enter adds it, Enter completes.
    await page.keyboard.press('F12');
    const pay = page.getByRole('dialog', { name: 'Collect payment' });
    await expect(pay).toBeVisible();
    await expect(pay.getByRole('textbox', { name: 'Cash received' })).toBeFocused();
    await page.keyboard.type('2000');
    await page.keyboard.press('Enter');
    await expect(pay.getByRole('status')).toHaveText(en['pos.pay.fullyPaid']);
    const change = (await pay.getByRole('definition').filter({ hasText: '৳' }).last().innerText()).trim();
    await expect(pay.getByRole('textbox').first()).toBeFocused();
    await page.keyboard.press('Enter');

    // Sale complete: focus on Start new sale, which says the change the server worked out.
    const next = page.getByRole('button', { name: 'Start new sale' });
    await expect(next).toBeFocused();
    await expect(next).toHaveAccessibleDescription(new RegExp(`Change ${escape(change)}$`));
    await page.keyboard.press('Enter');
    await expect(search).toBeFocused();
    await expect(cartPanel(page, 'en').getByText(en['pos.cart.empty'])).toBeAttached();
}

test.describe('POS keyboard only', () => {
    test('desktop till: scan, Enter, F12, cash, Enter, Enter, Enter — and F8 / F9 / Escape return focus', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await signIn(page, 'en');
        await toRegister(page, 'en');
        await keyboardSale(page, { phone: '01819233467', name: /^Tahmid Rahman/ });

        // F9 and F8 open their dialogs with focus inside; Escape closes and returns to the box.
        const search = searchBox(page, 'en');
        await page.keyboard.press('F9');
        const customer = page.getByRole('dialog', { name: 'Customer' });
        await expect(customer.getByRole('searchbox', { name: en['pos.customerDialog.search'] })).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(customer).toBeHidden();
        await expect(search).toBeFocused();

        await page.keyboard.press('F8');
        const holds = page.getByRole('dialog', { name: 'Held sales' });
        await expect(holds).toBeVisible();
        expect(await holds.evaluate((d) => d.contains(document.activeElement))).toBe(true);
        await page.keyboard.press('Escape');
        await expect(holds).toBeHidden();
        await expect(search).toBeFocused();

        // F12 on an empty cart does nothing; Escape in Collect payment returns to the box too.
        await page.keyboard.press('F12');
        await expect(page.getByRole('dialog')).toHaveCount(0);
        await page.keyboard.type(BARCODE);
        await page.keyboard.press('Enter');
        await keyboardCustomer(page, '01819233467', /^Tahmid Rahman/);
        await expect(search).toBeFocused();
        await expect(chargeButton(page, 'en')).toBeEnabled();
        await page.keyboard.press('F12');
        await expect(page.getByRole('dialog', { name: 'Collect payment' })).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(search).toBeFocused();

        // Tab from the box reaches the cart's controls without a trap: Scan, the category pills, the tiles…
        await page.keyboard.press('Tab');
        await expect(page.getByRole('button', { name: 'Scan' })).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(page.getByRole('button', { name: 'All', exact: true })).toBeFocused();

        // Clean-up, not part of the keyboard path.
        await cartPanel(page, 'en').getByRole('button', { name: 'Clear' }).click();
        await closeDrawer(page, 'en');
    });

    test('tablet with a keyboard: the same scan-to-sale path works with the Products / Cart switch', async ({ page }) => {
        await page.setViewportSize({ width: 768, height: 1024 });
        await signIn(page, 'en');
        await toRegister(page, 'en');
        // The cart column is hidden on a tablet until the switch; the scan path works from Products,
        // and the switch's count shows the scan landed.
        await expect(cartPanel(page, 'en')).toBeHidden();
        await keyboardSale(page, { phone: '01711458231', name: /নুসরাত জাহান/ });
        await expect(page.getByRole('group', { name: 'Show' }).getByRole('button', { name: 'Cart · 0' })).toBeVisible();
        await closeDrawer(page, 'en');
    });
});
