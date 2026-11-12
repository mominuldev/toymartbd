import { expect, test } from '@playwright/test';

import { bn } from '../lib/i18n/bn';
import { en } from '../lib/i18n/en';
import { MOCK_API_URL } from './constants';

// Phase 4 exit golden path, as a shopper clicks it with JavaScript ON, in both locales, against the
// mock API: home → category → product → add to cart → cart → checkout (COD) → confirmation →
// track order. checkout.spec.ts walks the cart-onwards half with JavaScript off; this one proves
// the enhanced layer (in-place add to cart, re-pricing on district change, fetch-based Place
// order) reaches the same order. Money is the mock API's (Dhaka ৳70 delivery); the storefront
// only formats it (rule 1).
test.use({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });

test.beforeEach(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

for (const [locale, t, prefix] of [
    ['en', en, ''],
    ['bn', bn as unknown as typeof en, '/bn'],
] as const) {
    test(`${locale}: home → category → product → cart → checkout (COD) → confirmation → track`, async ({ page }) => {
        const bangla = locale === 'bn';

        // Home → the first category tile.
        await page.goto(prefix || '/');
        await expect(page.locator('html')).toHaveAttribute('lang', locale);
        await page.locator('section[aria-labelledby="home-categories"] li a').first().click();
        await expect(page).toHaveURL(new RegExp(`${prefix}/categories/building-blocks$`));
        await expect(page.locator('h1')).toHaveText(bangla ? /বিল্ডিং|Building/ : 'Building Blocks');

        // Category → product card.
        await page.locator(`main article h3 a[href="${prefix}/products/lego-classic-creative-bricks"]`).click();
        await expect(page).toHaveURL(new RegExp(`${prefix}/products/lego-classic-creative-bricks$`));
        await expect(page.locator('h1')).toContainText(/LEGO|লেগো/);

        // Product → choose the 90 pcs set (৳1,450), add in place: the page stays, the header counts it.
        await page.locator('#pdp-variants a', { hasText: bangla ? /90/ : '90 pcs' }).first().click();
        await expect(page).toHaveURL(/\?variant=501$/);
        await page.getByRole('button', { name: t.pdp.addToCart, exact: true }).first().click();
        const added = page.getByRole('status').filter({ hasText: t.cart.added });
        await expect(added).toBeVisible();
        await expect(page).toHaveURL(/\/products\/lego-classic-creative-bricks/);
        await added.getByRole('link', { name: t.cart.viewCart }).click();

        // Cart: the API's line and total.
        await expect(page).toHaveURL(new RegExp(`${prefix}/cart$`));
        await expect(page.locator('h1')).toHaveText(t.cart.title);
        await expect(page.locator('#cart-total')).toHaveText('৳1,450');
        await page.getByRole('link', { name: t.cart.checkout, exact: true }).click();

        // Checkout: choosing the district re-prices without a reload; COD is the payment.
        await expect(page).toHaveURL(new RegExp(`${prefix}/checkout$`));
        await page.locator('#name').fill(bangla ? 'সাবরিনা আক্তার' : 'Sabrina Akter');
        await page.locator('#phone').fill('01712-345678');
        await page.locator('#district_id').selectOption('13');
        await expect(page.locator('#upazila_id option[value="101"]')).toBeAttached();
        await page.locator('#upazila_id').selectOption('101');
        await page.locator('#area').fill(bangla ? 'ধানমন্ডি' : 'Dhanmondi');
        await page.locator('#address_line').fill(bangla ? 'বাসা ১২, রোড ৫, ব্লক সি' : 'House 12, Road 5, Block C');
        await page.locator('#landmark').fill(bangla ? 'আগোরার পাশে, ৩য় তলা' : 'beside Agora, 3rd floor');
        await expect(page.locator('#checkout-total')).toHaveText('৳1,520');
        await expect(page.locator('#name')).toHaveValue(bangla ? 'সাবরিনা আক্তার' : 'Sabrina Akter');
        const cod = page.locator('input[name="payment_method"][value="cod"]');
        if ((await cod.count()) > 0) await expect(cod).toBeChecked();
        // Rule 25b: nothing recommended on checkout.
        await expect(page.locator('main article')).toHaveCount(0);
        await page.locator('aside button[value="place"]').click();

        // Confirmation.
        await expect(page).toHaveURL(new RegExp(`${prefix}/orders/TMB-26-\\d{5}$`));
        await expect(page.locator('h1')).toHaveText(t.order.thanksTitle);
        const number = page.url().split('/').pop() ?? '';
        await expect(page.getByText(t.order.number.replace('{number}', number))).toBeVisible();
        await expect(page.getByText(/৳1,520/).first()).toBeVisible();

        // Track: order number plus phone digits (rule 22).
        await page.goto(`${prefix}/track`);
        await page.locator('#track-number').fill(number);
        await page.locator('#track-phone').fill('5678');
        await page.getByRole('button', { name: t.track.submit }).click();
        await expect(page.getByText(t.order.number.replace('{number}', number))).toBeVisible();
        await expect(page.getByText(t.order.stages.placed)).toBeVisible();
        await expect(page.getByText(/House 12|বাসা ১২/)).toHaveCount(0);
    });
}
