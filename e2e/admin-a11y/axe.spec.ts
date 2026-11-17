import { createHmac } from 'node:crypto';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { bn, en } from '../../resources/js/admin/i18n/messages';

// Phase 9 admin accessibility audit (docs/audits/ACCESSIBILITY-AND-BILINGUAL.md). axe-core over the
// main admin screens, signed in as the owner on the demo data, in English and Bangla (the per-staff
// EN | বাং switch, `tm-admin-locale`) and in Admin Light and Admin Dark (`tm-admin-theme`). Any WCAG
// 2.2 A/AA violation fails; best-practice findings are attached to the report only.
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'];

const SCREENS: Array<[string, string]> = [
    ['dashboard', '/admin'],
    ['orders', '/admin/orders'],
    ['order-detail', '/admin/orders/1'],
    ['order-create', '/admin/orders/new'],
    ['products', '/admin/products'],
    ['product-form', '/admin/products/1'],
    ['product-new', '/admin/products/new'],
    ['categories', '/admin/products/categories'],
    ['brands', '/admin/products/brands'],
    ['inventory', '/admin/inventory'],
    ['inventory-movements', '/admin/inventory/movements'],
    ['customers', '/admin/customers'],
    ['customer-profile', '/admin/customers/1'],
    ['analytics-sales', '/admin/analytics/sales'],
    ['analytics-customers', '/admin/analytics/customers'],
    // Analytics premium pass (Figma 518:78370): the four tabs added 2026-11-17.
    ['analytics-products', '/admin/analytics/products'],
    ['analytics-visitors', '/admin/analytics/visitors'],
    ['analytics-marketing', '/admin/analytics/marketing'],
    ['analytics-live', '/admin/analytics/live'],
    ['analytics-couriers', '/admin/analytics/couriers'],
    ['analytics-schedules', '/admin/analytics/schedules'],
    ['finance-pnl', '/admin/finance/pnl'],
    ['invoices', '/admin/invoices'],
    ['risk-queue', '/admin/risk/queue'],
    ['settings-invoice', '/admin/settings/invoice'],
    ['couriers', '/admin/integrations/couriers'],
    ['cms-pages', '/admin/store/pages'],
    ['cms-banners', '/admin/store/banners'],
    ['cms-blog', '/admin/store/blog'],
    ['cms-home', '/admin/store/home'],
    ['templates', '/admin/automations/templates'],
    // Phase 10 (batch 3 audit): staff & roles, two-factor, activity log, integrations hub, store settings.
    ...PHASE10_SCREENS(),
];

/** The Phase 10 screens; also audited at 768 wide. Settings → Invoice is in the list above. */
function PHASE10_SCREENS(): Array<[string, string]> {
    return [
        ['staff-people', '/admin/staff'],
        ['staff-roles', '/admin/staff/roles'],
        ['staff-activity', '/admin/staff/activity'],
        ['two-factor', '/admin/account/two-factor'],
        ['integrations-hub', '/admin/integrations'],
        ['ai-settings', '/admin/integrations/ai'],
        ['ai-advisor', '/admin/analytics/advisor'],
        ['ai-restock', '/admin/inventory/restock'],
        ['settings-business', '/admin/settings/business'],
        ['settings-branding', '/admin/settings/branding'],
        ['settings-branches', '/admin/settings/branches'],
        ['settings-checkout', '/admin/settings/checkout'],
        // Purchasing premium pass: the list and a partly received PO (demo PO-2026-0008).
        ['purchasing', '/admin/purchasing'],
        ['purchase-order', '/admin/purchasing/8'],
        // Finance › Owner capital (Figma B · 32), on DemoCapitalSeeder's holders and entries.
        ['finance-capital', '/admin/finance/capital'],
        // Finance premium pass (Figma B · 32): expenses, categories, ledger, COD settlements, reports.
        ['finance-expenses', '/admin/finance/expenses'],
        ['finance-expense-categories', '/admin/finance/expenses/categories'],
        ['finance-ledger', '/admin/finance/ledger'],
        ['finance-settlements', '/admin/finance/settlements'],
        ['finance-daily', '/admin/finance/reports'],
        ['finance-profitability', '/admin/finance/profitability'],
    ];
}

type Locale = 'en' | 'bn';
type Key = keyof typeof en;

/** The UI string the SPA shows for `key` in `locale`, so the same steps drive both languages. */
function m(locale: Locale, key: Key, vars: Record<string, string> = {}): string {
    return Object.entries(vars).reduce((text, [k, v]) => text.replaceAll(`{${k}}`, v), (locale === 'bn' ? bn : en)[key]);
}

async function signIn(page: Page, locale: 'en' | 'bn', theme: 'admin-light' | 'admin-dark', email = 'owner@toymart.test', expectSignedIn = true) {
    await page.addInitScript(([l, th]) => {
        localStorage.setItem('tm-admin-locale', l);
        localStorage.setItem('tm-admin-theme', th);
    }, [locale, theme] as const);
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill('password');
    await page.locator('button[type="submit"]').click();
    if (expectSignedIn) await expect(page).not.toHaveURL(/\/login/);
}

async function settle(page: Page) {
    await page.waitForLoadState('networkidle');
    // TanStack Query skeletons: wait until nothing is busy.
    await page.waitForFunction(() => document.querySelectorAll('[aria-busy="true"]').length === 0, null, { timeout: 15_000 }).catch(() => undefined);
    await page.evaluate(() => document.fonts.ready);
}

async function audit(page: Page, testInfo: TestInfo, label: string) {
    await settle(page);
    const results = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
    const advice = await new AxeBuilder({ page }).withTags(['best-practice']).analyze();
    const shape = (vs: typeof results.violations) =>
        vs.map((v) => ({ rule: v.id, impact: v.impact, help: v.help, targets: v.nodes.slice(0, 5).map((n) => n.target.join(' ')) }));
    const wcag = shape(results.violations);
    if (wcag.length || advice.violations.length) {
        await testInfo.attach(`axe-${label}.json`, { body: JSON.stringify({ wcag, advice: shape(advice.violations) }, null, 2), contentType: 'application/json' });
    }
    const lines = wcag.map((f) => `\n  [${f.impact}] ${f.rule} — ${f.help}\n      ${f.targets.join('\n      ')}`).join('');
    expect.soft(wcag, `${label}: ${wcag.length} WCAG violation(s)${lines}`).toEqual([]);
}

test.describe('admin axe', () => {
    test.use({ viewport: { width: 1440, height: 1000 } });

    for (const locale of ['en', 'bn'] as const) {
        for (const theme of ['admin-light', 'admin-dark'] as const) {
            test(`${locale} · ${theme}: main screens`, async ({ page }, testInfo) => {
                await signIn(page, locale, theme);
                for (const [label, path] of SCREENS) {
                    await page.goto(path);
                    await audit(page, testInfo, `${locale}-${theme}-${label}`);
                }
            });
        }
    }

    test('en · 390px: shell drawer and order list', async ({ page }, testInfo) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await signIn(page, 'en', 'admin-light');
        for (const [label, path] of [['orders', '/admin/orders'], ['dashboard', '/admin']] as const) {
            await page.goto(path);
            await audit(page, testInfo, `390-${label}`);
        }
    });

    // Phase 10 screens at the tablet width the frames are checked at, both languages.
    for (const locale of ['en', 'bn'] as const) {
        test(`${locale} · 768px: Phase 10 screens`, async ({ page }, testInfo) => {
            await page.setViewportSize({ width: 768, height: 1024 });
            await signIn(page, locale, 'admin-light');
            for (const [label, path] of PHASE10_SCREENS()) {
                await page.goto(path);
                await audit(page, testInfo, `768-${locale}-${label}`);
            }
        });
    }

    // Phase 10 open dialogs and in-page states the screen list cannot reach by URL.
    for (const locale of ['en', 'bn'] as const) {
        test(`${locale} · admin-light: Phase 10 dialogs and states`, async ({ page }, testInfo) => {
            await signIn(page, locale, 'admin-light');
            const label = (name: string) => `${locale}-${name}`;

            await page.goto('/admin/staff');
            await page.getByRole('button', { name: m(locale, 'staff.add'), exact: true }).click();
            const add = page.getByRole('dialog', { name: m(locale, 'staff.form.addTitle') });
            await expect(add).toBeVisible();
            await audit(page, testInfo, label('staff-add'));
            await add.getByRole('button', { name: m(locale, 'staff.form.submitAdd') }).click();
            await audit(page, testInfo, label('staff-add-errors'));
            await page.keyboard.press('Escape');
            await expect(add).toBeHidden();

            await page.getByRole('button', { name: /Farhana/ }).click();
            const manage = page.getByRole('dialog', { name: /Farhana/ });
            await expect(manage).toBeVisible();
            await audit(page, testInfo, label('staff-manage'));
            await page.keyboard.press('Escape');
            await expect(manage).toBeHidden();

            await page.goto('/admin/staff/roles');
            await page.locator('table input[type="checkbox"]').first().click();
            const bar = page.getByRole('region', { name: m(locale, 'roles.unsaved', { count: '1' }) });
            await expect(bar).toBeVisible();
            await audit(page, testInfo, label('roles-edited'));
            await bar.getByRole('button', { name: m(locale, 'roles.save') }).click();
            await expect(page.getByRole('dialog')).toBeVisible();
            await audit(page, testInfo, label('roles-confirm'));
            await page.keyboard.press('Escape');

            await page.goto('/admin/settings/branches');
            await page.getByRole('button', { name: m(locale, 'storeSettings.branches.add') }).click();
            const branch = page.getByRole('dialog', { name: m(locale, 'storeSettings.branches.dialog.addTitle') });
            await expect(branch).toBeVisible();
            await audit(page, testInfo, label('branch-add'));
            await branch.getByRole('button', { name: m(locale, 'storeSettings.branches.submitAdd') }).click();
            await audit(page, testInfo, label('branch-add-errors'));
            await page.keyboard.press('Escape');
            await expect(branch).toBeHidden();
            await page.getByRole('button', { name: m(locale, 'storeSettings.branches.editLabel', { name: '' }).trim() }).first().click();
            await expect(page.getByRole('dialog')).toBeVisible();
            await audit(page, testInfo, label('branch-edit'));
            await page.keyboard.press('Escape');

            await page.goto('/admin/staff/activity');
            await page.getByLabel(m(locale, 'audit.range.label')).selectOption('custom');
            await audit(page, testInfo, label('activity-custom'));

            await page.goto('/admin/integrations');
            await page
                .getByRole('navigation', { name: m(locale, 'integrationsHub.tabs') })
                .getByRole('button', { name: new RegExp(m(locale, 'integrationsHub.category.couriers')) })
                .click();
            await audit(page, testInfo, label('integrations-couriers'));

            await page.goto('/admin/settings/checkout');
            await page.getByRole('switch').first().click();
            await audit(page, testInfo, label('checkout-toggled'));

            // Owner capital: New investment (with its errors), Owner drawing, Reverse entry, Add a person.
            await page.goto('/admin/finance/capital');
            await page.getByRole('button', { name: m(locale, 'capital.newInvestment') }).click();
            const invest = page.getByRole('dialog', { name: m(locale, 'capital.in.title') });
            await expect(invest).toBeVisible();
            await audit(page, testInfo, label('capital-invest'));
            await invest.getByRole('button', { name: m(locale, 'capital.in.submitEmpty') }).click();
            await audit(page, testInfo, label('capital-invest-errors'));
            await page.keyboard.press('Escape');
            await expect(invest).toBeHidden();
            await page.getByRole('button', { name: m(locale, 'capital.ownerDrawing') }).click();
            const draw = page.getByRole('dialog', { name: m(locale, 'capital.out.title') });
            await expect(draw).toBeVisible();
            await audit(page, testInfo, label('capital-drawing'));
            await page.keyboard.press('Escape');
            await expect(draw).toBeHidden();
            await page.getByRole('table').locator('summary').first().click();
            await page.getByRole('table').getByRole('button', { name: m(locale, 'capital.reverse') }).first().click();
            const reverse = page.getByRole('dialog', { name: m(locale, 'capital.reverse.title') });
            await expect(reverse).toBeVisible();
            await audit(page, testInfo, label('capital-reverse'));
            await page.keyboard.press('Escape');
            await expect(reverse).toBeHidden();
            await page.getByRole('button', { name: m(locale, 'capital.addHolder') }).click();
            const person = page.getByRole('dialog', { name: m(locale, 'capital.holderDialog.title') });
            await expect(person).toBeVisible();
            await audit(page, testInfo, label('capital-add-person'));
            await page.keyboard.press('Escape');
        });
    }

    // Two-factor: setup, the key and code, the recovery codes, manage, and the sign-in code step
    // (both modes, and a wrong code). Each locale enrols its own demo account (it is one-time), and
    // a CI retry enrols a second one: the first attempt may already have turned two-factor on.
    for (const [locale, accounts] of [
        ['en', ['viewer@toymart.test', 'inventory_staff@toymart.test']],
        ['bn', ['content_editor@toymart.test', 'accountant@toymart.test']],
    ] as const) {
        test(`${locale} · admin-light: two-factor states and the sign-in code step`, async ({ browser }, testInfo) => {
            const email = accounts[Math.min(testInfo.retry, accounts.length - 1)]!;
            const label = (name: string) => `${locale}-2fa-${name}`;
            const fresh = async () => {
                const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
                return context.newPage();
            };

            const page = await fresh();
            await page.addInitScript((l) => localStorage.setItem('tm-admin-locale', l), locale);
            await page.goto('/admin/login');
            await audit(page, testInfo, label('login'));
            await signIn(page, locale, 'admin-light', email);
            await page.goto('/admin/account/two-factor');
            await audit(page, testInfo, label('setup'));
            await page.getByRole('button', { name: m(locale, 'twoFactor.start') }).click();
            await expect(page.locator('code')).toBeVisible();
            await audit(page, testInfo, label('scan'));
            const secret = (await page.locator('code').first().innerText()).replace(/\s+/g, '');
            const code = page.getByLabel(m(locale, 'twoFactor.code'), { exact: false });
            await code.fill('000000');
            await page.getByRole('button', { name: m(locale, 'twoFactor.confirm') }).click();
            await expect(code).toHaveAttribute('aria-invalid', 'true');
            await audit(page, testInfo, label('scan-error'));
            await code.fill(await freshTotp(secret));
            await page.getByRole('button', { name: m(locale, 'twoFactor.confirm') }).click();
            await expect(page.getByRole('list', { name: m(locale, 'twoFactor.codesTitle') })).toBeVisible();
            await audit(page, testInfo, label('codes'));
            await page.getByRole('button', { name: m(locale, 'twoFactor.saved') }).click();
            await page.goto('/admin/account/two-factor');
            await page.getByRole('button', { name: m(locale, 'twoFactor.newCodes') }).click();
            await audit(page, testInfo, label('manage-form'));
            await page.getByRole('button', { name: m(locale, 'common.cancel') }).click();
            await audit(page, testInfo, label('manage'));

            const again = await fresh();
            await signIn(again, locale, 'admin-light', email, false);
            const heading = again.getByRole('heading', { name: m(locale, 'twoFactor.challenge.title') });
            await expect(heading).toBeVisible();
            await audit(again, testInfo, label('challenge'));
            await again.getByRole('button', { name: m(locale, 'twoFactor.challenge.useRecovery') }).click();
            await audit(again, testInfo, label('challenge-recovery'));
            await again.getByLabel(m(locale, 'twoFactor.recoveryCode')).fill('wrong-code-1234');
            await again.getByRole('button', { name: m(locale, 'twoFactor.challenge.submit') }).click();
            await expect(again.getByRole('alert')).toBeVisible();
            await audit(again, testInfo, label('challenge-error'));
            await again.context().close();
            await page.context().close();
        });
    }
});

function base32(secret: string): Buffer {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret.replace(/\s+/g, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    const bytes: number[] = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
    return Buffer.from(bytes);
}

/** A code with at least 4 s of its 30 s step left, so it cannot roll over between typing and submitting. */
async function freshTotp(secret: string): Promise<string> {
    const left = 30_000 - (Date.now() % 30_000);
    if (left < 4_000) await new Promise((resolve) => setTimeout(resolve, left + 250));
    return totp(secret);
}

/** The 6-digit RFC 6238 code an authenticator app shows now (as in e2e/admin/two-factor.spec.ts). */
function totp(secret: string): string {
    const message = Buffer.alloc(8);
    message.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / 30)));
    const hmac = createHmac('sha1', base32(secret)).update(message).digest();
    const offset = hmac[hmac.length - 1]! & 0xf;
    return String((hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}
