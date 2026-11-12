import { expect, test, type Page } from '@playwright/test';

import { bn } from '../lib/i18n/bn';
import { en } from '../lib/i18n/en';
import { MOCK_API_URL } from './constants';

// P4-STO-08/09/10 golden path with JavaScript DISABLED, in both locales, against the mock API
// (e2e/mock-api.mjs: SAVE10 = 10% off, Dhaka ৳70 below ৳3,000). Every step is a plain form POST
// to a Route Handler and a 303 — add to cart → cart (quantity, coupon) → checkout (cascading
// district → upazila, landmark) → order confirmation → public tracking. Figures are the mock API's
// strings; the storefront only formats them.
test.use({ javaScriptEnabled: false, viewport: { width: 1440, height: 1000 } });

test.beforeEach(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

const PRODUCT = '/products/lego-classic-creative-bricks?variant=501'; // ৳1,450, 12 in stock

async function fillCheckout(page: Page, t: typeof en) {
    await page.locator('#name').fill(t === en ? 'Sabrina Akter' : 'সাবরিনা আক্তার');
    await page.locator('#phone').fill('01712-345678');
    await page.locator('#district_id').selectOption('13');
    // Without JS the upazilas of the chosen district come from a round trip; typed fields survive it.
    await page.locator('button[value="refresh"]').click();
    await expect(page.locator('#name')).toHaveValue(t === en ? 'Sabrina Akter' : 'সাবরিনা আক্তার');
    await page.locator('#upazila_id').selectOption('101');
    await page.locator('#area').fill(t === en ? 'Dhanmondi' : 'ধানমন্ডি');
    await page.locator('#address_line').fill(t === en ? 'House 12, Road 5, Block C' : 'বাসা ১২, রোড ৫, ব্লক সি');
    await page.locator('#landmark').fill(t === en ? 'beside Agora, 3rd floor' : 'আগোরার পাশে, ৩য় তলা');
    await page.locator('button[value="refresh"]').click();
}

for (const [locale, t, prefix] of [
    ['en', en, ''],
    ['bn', bn as unknown as typeof en, '/bn'],
] as const) {
    test(`${locale}: add to cart → cart → checkout → confirmation → tracking, without JavaScript`, async ({ page }) => {
        await page.goto(`${prefix}${PRODUCT}`);
        await page.getByRole('button', { name: t.pdp.addToCart, exact: true }).first().click();

        // Cart: the API's line and totals; the header knows the count server-side.
        await expect(page).toHaveURL(new RegExp(`${prefix}/cart$`));
        await expect(page.locator('h1')).toHaveText(t.cart.title);
        await expect(page.locator('main li').filter({ hasText: /LEGO|লেগো/ }).first()).toBeVisible();
        await expect(page.locator('#cart-total')).toHaveText('৳1,450');

        // Quantity +1 (a real form button), then the coupon (native <details>).
        await page.locator('main button[value="inc"]').first().click();
        await expect(page.locator('main output').first()).toHaveText('2');
        await expect(page.locator('#cart-total')).toHaveText('৳2,900');
        await page.locator('summary', { hasText: t.cart.couponToggle }).click();
        await page.locator('input[name="code"]').fill('save10');
        await page.getByRole('button', { name: t.cart.couponApply, exact: true }).click();
        await expect(page.getByText(t.cart.couponApplied.replace('{code}', 'SAVE10')).first()).toBeVisible();
        await expect(page.locator('#cart-total')).toHaveText('৳2,610');

        // Checkout: nothing recommended (rule 25b), landmark kept, total from the API incl. delivery.
        await page.getByRole('link', { name: t.cart.checkout, exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${prefix}/checkout$`));
        await fillCheckout(page, t);
        await expect(page.locator('#checkout-total')).toHaveText('৳2,680');
        await expect(page.locator('aside button[value="place"]')).toContainText('৳2,680');
        await expect(page.locator('#landmark')).toHaveValue(/Agora|আগোরার/);
        await page.locator('aside button[value="place"]').click();

        // Confirmation: readable only with the cart token cookie this browser holds.
        await expect(page).toHaveURL(new RegExp(`${prefix}/orders/TMB-26-\\d{5}$`));
        await expect(page.locator('h1')).toHaveText(t.order.thanksTitle);
        const number = (page.url().split('/').pop() ?? '').trim();
        await expect(page.getByText(t.order.number.replace('{number}', number))).toBeVisible();
        await expect(page.getByText(t.order.stages.placed)).toBeVisible();
        await expect(page.getByText(/আগোরার|Agora/)).toBeVisible();
        await expect(page.getByText(/৳2,680/).first()).toBeVisible();

        // The cart is closed: the header no longer counts it.
        await page.goto(`${prefix}/cart`);
        await expect(page.getByText(t.cart.emptyTitle)).toBeVisible();

        // Tracking: number + last 4 digits; never the street address.
        await page.goto(`${prefix}/track`);
        await page.locator('#track-number').fill(number.replace('TMB-', '').toLowerCase());
        await page.locator('#track-phone').fill('5678');
        await page.getByRole('button', { name: t.track.submit }).click();
        await expect(page.getByText(t.order.number.replace('{number}', number))).toBeVisible();
        await expect(page.getByText(/আগোরার|Agora/)).toHaveCount(0);
        await expect(page.getByText(/House 12|বাসা ১২/)).toHaveCount(0);

        // A wrong phone: one generic answer, no hint which part was wrong.
        await page.locator('#track-number').fill(number);
        await page.locator('#track-phone').fill('1111');
        await page.getByRole('button', { name: t.track.submit }).click();
        await expect(page.getByRole('alert')).toHaveText(t.track.notFound);
    });
}

test('a price change between render and Place order is shown, then the order goes through', async ({ page, request }) => {
    await page.goto(PRODUCT);
    await page.getByRole('button', { name: en.pdp.addToCart, exact: true }).first().click();
    await page.getByRole('link', { name: en.cart.checkout, exact: true }).click();
    await fillCheckout(page, en);
    await expect(page.locator('#checkout-total')).toHaveText('৳1,520');

    await request.post(`${MOCK_API_URL}/__control/price`, { data: { variant_id: 501, price: '1500.00' } });
    await page.locator('aside button[value="place"]').click();
    await expect(page).toHaveURL(/\/checkout$/);
    await expect(page.getByRole('alert')).toContainText('৳1570.00');
    await expect(page.locator('#checkout-total')).toHaveText('৳1,570');
    await expect(page.locator('#address_line')).toHaveValue('House 12, Road 5, Block C');

    await page.locator('aside button[value="place"]').click();
    await expect(page).toHaveURL(/\/orders\/TMB-26-\d{5}$/);
});

for (const [locale, t, prefix] of [
    ['en', en, ''],
    ['bn', bn as unknown as typeof en, '/bn'],
] as const) {
    test(`${locale}: field errors from the API come back at their fields, typed values kept`, async ({ page }) => {
        await page.goto(`${prefix}${PRODUCT}`);
        await page.getByRole('button', { name: t.pdp.addToCart, exact: true }).first().click();
        await page.getByRole('link', { name: t.cart.checkout, exact: true }).click();
        await fillCheckout(page, t);
        await page.locator('#phone').fill('01112345678'); // 011 = defunct Citycell (rule 30): the API refuses it
        await page.locator('aside button[value="place"]').click();
        await expect(page).toHaveURL(new RegExp(`${prefix}/checkout$`));
        await expect(page.locator('#phone-help')).toContainText('013–019');
        await expect(page.locator('#phone')).toHaveAttribute('aria-invalid', 'true');
        await expect(page.locator('#name')).toHaveValue(t === en ? 'Sabrina Akter' : 'সাবরিনা আক্তার');
        // One-shot: a reload no longer shows the error.
        await page.reload();
        await expect(page.locator('#phone')).not.toHaveAttribute('aria-invalid', 'true');
    });
}

test('the confirmation is not shown to another browser', async ({ page }) => {
    await page.goto('/orders/TMB-26-00042');
    await expect(page.locator('h1')).toHaveText(en.order.notFoundTitle);
    await expect(page.getByRole('link', { name: en.order.track })).toHaveAttribute('href', '/track?number=TMB-26-00042');
});

test('checkout with an empty cart goes back to the cart', async ({ page }) => {
    await page.goto('/checkout');
    await expect(page).toHaveURL(/\/cart$/);
    await expect(page.getByText(en.cart.emptyTitle)).toBeVisible();
});
