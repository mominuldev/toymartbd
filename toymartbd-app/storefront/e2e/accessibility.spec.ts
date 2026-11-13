import { expect, test, type Page } from '@playwright/test';

import { bn } from '../lib/i18n/bn';
import { en } from '../lib/i18n/en';
import { expectAccessible } from './axe';
import { MOCK_API_URL } from './constants';

// Phase 9 accessibility audit (docs/audits/ACCESSIBILITY-AND-BILINGUAL.md): axe-core over every MVP
// storefront screen, in English and Bangla, at the two Figma frame widths, with JavaScript ON (the
// hydrated page a shopper actually gets). Any WCAG 2.2 A/AA violation fails (e2e/axe.ts). The
// layout half of the bilingual sweep is bangla-layout.spec.ts; keyboard paths are keyboard.spec.ts.
test.use({ reducedMotion: 'reduce' });
// Each test walks many screens, running axe twice on each.
test.describe.configure({ timeout: 240_000 });

test.beforeAll(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

const STATIC_SCREENS: Array<[string, string]> = [
    ['home', '/'],
    ['categories', '/categories'],
    ['category', '/categories/building-blocks'],
    ['brands', '/brands'],
    ['brand', '/brands/lego'],
    ['all-toys', '/toys'],
    ['flash-sale', '/flash-sale'],
    ['search', '/search?q=lego'],
    ['search-empty', '/search?q=zzzznothing'],
    ['product', '/products/lego-classic-creative-bricks'],
    ['cart-empty', '/cart'],
    ['track', '/track'],
    ['delivery-charge', '/delivery-charge'],
    ['about', '/pages/about'],
    ['cms-page', '/pages/privacy-policy'],
    ['not-found', '/products/no-such-toy'],
];

const ACCOUNT_SCREENS: Array<[string, string]> = [
    ['home', '/account'],
    ['orders', '/account/orders'],
    ['order-detail', '/account/orders/TMB-26-00031'],
    ['addresses', '/account/addresses'],
    ['wishlist', '/account/wishlist'],
    ['profile', '/account/profile'],
];

const WIDTHS = [
    ['390', { width: 390, height: 844 }],
    ['1440', { width: 1440, height: 1000 }],
] as const;

const LOCALES = [
    ['en', en, ''],
    ['bn', bn as unknown as typeof en, '/bn'],
] as const;

const at = (prefix: string, path: string) => (path === '/' ? prefix || '/' : `${prefix}${path}`);

for (const [width, viewport] of WIDTHS) {
    test.describe(`axe ${width}px`, () => {
        test.use({ viewport });

        for (const [locale, t, prefix] of LOCALES) {
            test(`${locale}: catalogue, content and status pages`, async ({ page }, testInfo) => {
                for (const [label, path] of STATIC_SCREENS) {
                    await page.goto(at(prefix, path));
                    await expectAccessible(page, testInfo, `${locale}-${width}-${label}`);
                }
            });

            test(`${locale}: account sign-in and every account page`, async ({ page }, testInfo) => {
                await page.goto(at(prefix, '/account/login'));
                await expectAccessible(page, testInfo, `${locale}-${width}-account-login`);
                await page.locator('#phone').fill('01712-345678');
                await page.locator('form[action="/api/account/otp"] button[type="submit"]').click();
                await expect(page).toHaveURL(new RegExp(`${prefix}/account/verify$`));
                await expectAccessible(page, testInfo, `${locale}-${width}-account-verify`);
                for (const [i, digit] of [...'123456'].entries()) await page.locator(`input[name="d${i + 1}"]`).fill(digit);
                await page.locator('button[form="verify-form"]').click();
                await expect(page).toHaveURL(new RegExp(`${prefix}/account$`));
                for (const [label, path] of ACCOUNT_SCREENS) {
                    await page.goto(at(prefix, path));
                    await expectAccessible(page, testInfo, `${locale}-${width}-account-${label}`);
                }
                await page.context().clearCookies();
            });

            test(`${locale}: cart, checkout steps, confirmation and tracking`, async ({ page }, testInfo) => {
                await page.goto(at(prefix, '/products/lego-classic-creative-bricks?variant=501'));
                await page.getByRole('button', { name: t.pdp.addToCart, exact: true }).first().click();
                await page.goto(at(prefix, '/cart'));
                await expectAccessible(page, testInfo, `${locale}-${width}-cart`);

                await page.goto(at(prefix, '/checkout'));
                await expectAccessible(page, testInfo, `${locale}-${width}-checkout-blank`);
                // Submitting empty shows the server's field errors: they must stay associated.
                await page.locator('button[value="place"]:visible').first().click();
                await expectAccessible(page, testInfo, `${locale}-${width}-checkout-errors`);
                await fillCheckout(page, locale);
                await expectAccessible(page, testInfo, `${locale}-${width}-checkout-filled`);

                await page.locator('button[value="place"]:visible').first().click();
                await expect(page).toHaveURL(new RegExp(`${prefix}/orders/TMB-26-\\d{5}$`));
                await expectAccessible(page, testInfo, `${locale}-${width}-confirmation`);
                const number = page.url().split('/').pop() ?? '';

                await page.goto(at(prefix, '/track'));
                await page.locator('#track-number').fill(number);
                await page.locator('#track-phone').fill('5678');
                await page.getByRole('button', { name: t.track.submit }).click();
                await expect(page.getByText(t.order.number.replace('{number}', number))).toBeVisible();
                await expectAccessible(page, testInfo, `${locale}-${width}-track-result`);
            });
        }
    });
}

async function fillCheckout(page: Page, locale: 'en' | 'bn') {
    const bangla = locale === 'bn';
    await page.locator('#name').fill(bangla ? 'সাবরিনা আক্তার' : 'Sabrina Akter');
    await page.locator('#phone').fill('01712-345678');
    await page.locator('#district_id').selectOption('13');
    await expect(page.locator('#upazila_id option[value="101"]')).toBeAttached();
    await page.locator('#upazila_id').selectOption('101');
    await page.locator('#area').fill(bangla ? 'ধানমন্ডি' : 'Dhanmondi');
    await page.locator('#address_line').fill(bangla ? 'বাসা ১২, রোড ৫, ব্লক সি' : 'House 12, Road 5, Block C');
    await page.locator('#landmark').fill(bangla ? 'আগোরার পাশে, ৩য় তলা' : 'beside Agora, 3rd floor');
}
