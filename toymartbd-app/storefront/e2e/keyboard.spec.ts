import { expect, test, type Locator, type Page } from '@playwright/test';

import { bn } from '../lib/i18n/bn';
import { en } from '../lib/i18n/en';
import { MOCK_API_URL } from './constants';

// Phase 9 keyboard paths (UI-UX spec §7; docs/audits/ACCESSIBILITY-AND-BILINGUAL.md), JavaScript ON:
// the skip link, a visible focus ring on every tab stop, checkout end to end without a pointer, and
// the <details> pop-overs (filter sheet: focus moves in, is trapped, Escape closes and returns it;
// sort menu: Escape). The mega menu's arrow-key contract is layout.spec.ts.
test.use({ reducedMotion: 'reduce' });

test.beforeEach(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

const LOCALES = [
    ['en', en, ''],
    ['bn', bn as unknown as typeof en, '/bn'],
] as const;

/** Press Tab until `target` has focus — proves it is reachable, in order, by keyboard alone. */
async function tabTo(page: Page, target: Locator, max = 80): Promise<void> {
    for (let i = 0; i < max; i++) {
        if (await target.evaluate((el) => el === document.activeElement).catch(() => false)) return;
        await page.keyboard.press('Tab');
    }
    throw new Error(`not reachable with ${max} Tab presses: ${target.toString()}`);
}

/** Describe the focused element and whether it shows a ring (box-shadow, or a non-transparent outline). */
const focusState = (page: Page) =>
    page.evaluate(() => {
        const el = document.activeElement as HTMLElement | null;
        if (!el || el === document.body) return null;
        const cs = getComputedStyle(el);
        const outline = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0 && cs.outlineColor !== 'rgba(0, 0, 0, 0)' && cs.outlineColor !== 'transparent';
        const box = el.getBoundingClientRect();
        return {
            name: `<${el.tagName.toLowerCase()}${el.id ? `#${el.id}` : ''}> ${(el.getAttribute('aria-label') ?? el.textContent ?? '').replace(/\s+/g, ' ').trim().slice(0, 40)}`,
            ring: cs.boxShadow !== 'none' || outline,
            visible: box.width > 0 && box.height > 0,
        };
    });

for (const [locale, t, prefix] of LOCALES) {
    test.describe(`${locale}`, () => {
        test.use({ viewport: { width: 1440, height: 1000 } });

        test('the first Tab reaches the skip link, which moves focus to main', async ({ page }) => {
            await page.goto(prefix || '/');
            await page.keyboard.press('Tab');
            const skip = page.getByRole('link', { name: t.nav.skipToContent });
            await expect(skip).toBeFocused();
            const box = await skip.boundingBox();
            expect(box && box.width > 1 && box.height > 1, 'the skip link becomes visible on focus').toBe(true);
            await page.keyboard.press('Enter');
            await expect(page.locator('#main')).toBeFocused();
        });

        for (const [label, path] of [
            ['home', '/'],
            ['category', '/categories/building-blocks'],
            ['product', '/products/lego-classic-creative-bricks'],
            ['checkout', '/checkout'],
        ] as const) {
            test(`every tab stop on ${label} shows the focus ring`, async ({ page }) => {
                if (label === 'checkout') {
                    await page.goto(`${prefix}/products/lego-classic-creative-bricks?variant=501`);
                    await page.getByRole('button', { name: t.pdp.addToCart, exact: true }).first().click();
                    await expect(page.getByRole('status').filter({ hasText: t.cart.added })).toBeVisible();
                }
                await page.goto(path === '/' ? prefix || '/' : `${prefix}${path}`);
                const missing: string[] = [];
                for (let i = 0; i < 40; i++) {
                    await page.keyboard.press('Tab');
                    const state = await focusState(page);
                    if (state && state.visible && !state.ring) missing.push(state.name);
                }
                expect(missing, `tab stops without a visible focus ring:\n  ${missing.join('\n  ')}`).toEqual([]);
            });
        }

        test('checkout end to end with the keyboard only (COD)', async ({ page }) => {
            const bangla = locale === 'bn';
            await page.goto(`${prefix}/products/lego-classic-creative-bricks?variant=501`);
            const add = page.getByRole('button', { name: t.pdp.addToCart, exact: true }).first();
            await tabTo(page, add);
            await page.keyboard.press('Enter');
            const added = page.getByRole('status').filter({ hasText: t.cart.added });
            await expect(added).toBeVisible();
            await tabTo(page, added.getByRole('link', { name: t.cart.viewCart }));
            await page.keyboard.press('Enter');

            await expect(page).toHaveURL(new RegExp(`${prefix}/cart$`));
            await tabTo(page, page.getByRole('link', { name: t.cart.checkout, exact: true }).first());
            await page.keyboard.press('Enter');
            await expect(page).toHaveURL(new RegExp(`${prefix}/checkout$`));

            await tabTo(page, page.locator('#name'));
            await page.keyboard.type(bangla ? 'সাবরিনা আক্তার' : 'Sabrina Akter');
            await tabTo(page, page.locator('#phone'));
            await page.keyboard.type('01712345678');
            await tabTo(page, page.locator('#district_id'));
            if (bangla) {
                // Type-ahead needs key events, which Bangla text input does not send; the select is
                // reached by keyboard above and set here.
                await page.locator('#district_id').selectOption('13');
            } else {
                await page.keyboard.type('Dhaka');
            }
            await expect(page.locator('#district_id')).toHaveValue('13');
            await expect(page.locator('#upazila_id option[value="101"]')).toBeAttached();
            await tabTo(page, page.locator('#upazila_id'));
            if (bangla) await page.locator('#upazila_id').selectOption('101');
            else await page.keyboard.type('Dhanmondi');
            await expect(page.locator('#upazila_id')).toHaveValue('101');
            await tabTo(page, page.locator('#area'));
            await page.keyboard.type(bangla ? 'ধানমন্ডি' : 'Dhanmondi');
            await tabTo(page, page.locator('#address_line'));
            await page.keyboard.type(bangla ? 'বাসা ১২, রোড ৫' : 'House 12, Road 5');
            await tabTo(page, page.locator('#landmark'));
            await page.keyboard.type(bangla ? 'আগোরার পাশে' : 'beside Agora');
            await expect(page.locator('#checkout-total')).toHaveText('৳1,520');

            await tabTo(page, page.locator('aside button[value="place"]'));
            await page.keyboard.press('Enter');
            await expect(page).toHaveURL(new RegExp(`${prefix}/orders/TMB-26-\\d{5}$`));
            await expect(page.locator('h1')).toHaveText(t.order.thanksTitle);
        });
    });

    test.describe(`${locale} 390px`, () => {
        test.use({ viewport: { width: 390, height: 844 } });

        test('the filter sheet takes focus, traps Tab, closes on Escape and returns focus', async ({ page }) => {
            await page.goto(`${prefix}/categories/building-blocks`);
            const summary = page.locator('details.filter-sheet > summary');
            await tabTo(page, summary);
            await page.keyboard.press('Enter');
            const dialog = page.getByRole('dialog', { name: t.listing.filters });
            await expect(dialog).toBeVisible();
            await expect.poll(() => dialog.evaluate((d) => d.contains(document.activeElement))).toBe(true);
            for (let i = 0; i < 60; i++) {
                await page.keyboard.press('Tab');
                expect(await dialog.evaluate((d) => d.contains(document.activeElement)), `Tab ${i + 1} left the sheet`).toBe(true);
            }
            await page.keyboard.press('Shift+Tab');
            expect(await dialog.evaluate((d) => d.contains(document.activeElement))).toBe(true);
            await page.keyboard.press('Escape');
            await expect(dialog).toBeHidden();
            await expect(summary).toBeFocused();
        });

        test('the sort menu closes on Escape and returns focus', async ({ page }) => {
            await page.goto(`${prefix}/categories/building-blocks`);
            const sort = page.locator('details.filter-sheet + details[data-dismissible]'); // the mobile sort, beside Filters
            const summary = sort.locator('> summary');
            await tabTo(page, summary);
            await page.keyboard.press('Enter');
            await expect(sort).toHaveAttribute('open', '');
            await page.keyboard.press('Tab');
            await page.keyboard.press('Escape');
            await expect(sort).not.toHaveAttribute('open', '');
            await expect(summary).toBeFocused();
        });
    });
}
