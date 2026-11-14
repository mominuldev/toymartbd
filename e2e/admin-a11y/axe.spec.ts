import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type TestInfo } from '@playwright/test';

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
];

async function signIn(page: Page, locale: 'en' | 'bn', theme: 'admin-light' | 'admin-dark') {
    await page.addInitScript(([l, th]) => {
        localStorage.setItem('tm-admin-locale', l);
        localStorage.setItem('tm-admin-theme', th);
    }, [locale, theme] as const);
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill('owner@toymart.test');
    await page.locator('input[type="password"]').fill('password');
    await page.locator('button[type="submit"]').click();
    await expect(page).not.toHaveURL(/\/login/);
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
});
