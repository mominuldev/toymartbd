import { expect, test } from '@playwright/test';

import { MOCK_API_URL, STOREFRONT_URL } from './constants';

// P4-STO-05 product page, asserted with JavaScript DISABLED: the selected variant is the URL
// (`?variant=`), options are links, tabs are fragment links and the mobile accordion is <details>,
// so this is what every visitor and crawler gets before hydration. Fixture: e2e/fixtures/product-detail.json
// (Set 90/484 × Packaging Box/Tub; 484 + Tub is sold out; the default 484 + Box has 3 left).
test.use({ javaScriptEnabled: false });

test.beforeAll(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

const PATH = '/products/lego-classic-creative-bricks';
const abs = (path: string) => `${STOREFRONT_URL}${path}`;

test.describe('desktop', () => {
    test.use({ viewport: { width: 1440, height: 1000 } });

    test('renders the product server-side with the default variant, price and stock', async ({ page }) => {
        const res = await page.goto(PATH);
        expect(res?.status()).toBe(200);
        await expect(page.locator('h1')).toHaveText('LEGO Classic Creative Bricks 484 pcs');
        await expect(page.locator('nav[aria-label="Breadcrumb"] a')).toHaveText(['Home', 'Building Blocks', 'LEGO', 'LEGO Classic', 'LEGO Classic Creative Bricks 484 pcs']);
        // Money exactly as the API sends it, formatted only (rule 1).
        await expect(page.getByText('You save ৳740 · Cash on delivery available')).toBeVisible();
        await expect(page.locator('main s').first()).toHaveText('৳4,990');
        await expect(page.getByText('−14%').first()).toBeVisible();
        await expect(page.getByText('Only 3 left', { exact: true })).toBeVisible();
        await expect(page.getByText('For ages 4–12', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Add to cart' }).first()).toBeVisible();
        // The cart is not built yet (P4-CRT-01): the form is a marked no-op, never a fake success.
        await expect(page.locator('form#pdp-cart-form')).toHaveAttribute('data-todo', 'P4-CRT-01');

        // SEO: self-canonical without ?variant=, reciprocal hreflang, Product JSON-LD with offers.
        await expect(page.locator('head link[rel="canonical"]')).toHaveAttribute('href', abs(PATH));
        await expect(page.locator('head link[rel="alternate"][hreflang="bn"]')).toHaveAttribute('href', abs(`/bn${PATH}`));
        const ld = await page.locator('script[type="application/ld+json"]').allTextContents();
        const product = ld.map((s) => JSON.parse(s)).find((d) => d['@type'] === 'Product');
        expect(product.offers).toHaveLength(4);
        expect(product.offers).toContainEqual(expect.objectContaining({ sku: 'TMB-LEG-CL484', price: '4250.00', priceCurrency: 'BDT', availability: 'https://schema.org/LimitedAvailability' }));
        expect(product.offers).toContainEqual(expect.objectContaining({ sku: 'TMB-LEG-CL484-T', availability: 'https://schema.org/OutOfStock' }));

        // Related row reuses the listing card.
        await expect(page.locator('#pdp-related ~ ul article h3')).toHaveText(['LEGO City Fire Station', 'DUPLO Animal Train', 'Hape Wooden Rainbow Stacker', 'Big Building Bag 80 pcs']);
    });

    test('switches variant through the URL', async ({ page }) => {
        await page.goto(PATH);
        await page.getByRole('link', { name: '90 pcs' }).click();
        await expect(page).toHaveURL(abs(`${PATH}?variant=501`));
        await expect(page.locator('#pdp-variants')).toContainText('90 pcs · ৳1,450');
        await expect(page.getByText('In stock', { exact: true })).toBeVisible();
        await expect(page.getByText('Cash on delivery available', { exact: true })).toBeVisible();
        // Shareable: the same URL opened fresh renders the same variant.
        await page.goto(`${PATH}?variant=501`);
        await expect(page.locator('#pdp-variants [aria-current="true"]')).toHaveText(['90 pcs', 'Box']);
        // An unknown variant id falls back to the default rather than erroring.
        await page.goto(`${PATH}?variant=999`);
        await expect(page.locator('#pdp-variants [aria-current="true"]')).toHaveText(['484 pcs', 'Box']);
    });

    test('an out-of-stock combination is disabled with a visible reason, never hidden', async ({ page }) => {
        await page.goto(PATH);
        const tub = page.locator('#pdp-variants [aria-disabled="true"]', { hasText: 'Tub' });
        await expect(tub).toBeVisible();
        await expect(tub).not.toHaveAttribute('href', /.*/);
        await expect(page.locator('#pdp-variants')).toContainText('Tub with 484 pcs is out of stock right now');
        // Selecting the sold-out variant by URL shows it as out of stock with Notify me instead of Add to cart.
        await page.goto(`${PATH}?variant=504`);
        await expect(page.getByText('Out of stock', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Notify me' }).first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Add to cart', exact: true })).toHaveCount(0);
    });

    test('tabs work as fragment links without JavaScript', async ({ page }) => {
        await page.goto(PATH);
        await expect(page.locator('#pdp-tab-description')).toBeVisible();
        await expect(page.locator('#pdp-tab-specifications')).toBeHidden();
        await page.getByRole('tab', { name: 'Specifications' }).click();
        await expect(page.locator('#pdp-tab-specifications')).toBeVisible();
        await expect(page.locator('#pdp-tab-specifications')).toContainText('TMB-LEG-CL484');
        await expect(page.locator('#pdp-tab-description')).toBeHidden();
    });

    test('an unknown product is a 404', async ({ page }) => {
        const res = await page.goto('/products/no-such-toy');
        expect(res?.status()).toBe(404);
    });
});

test.describe('mobile', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('shows the sticky order bar and the accordion, first section open', async ({ page }) => {
        await page.goto(PATH);
        const bar = page.getByRole('region', { name: 'Order bar' });
        await expect(bar).toBeVisible();
        await expect(bar).toContainText('৳4,250');
        await expect(bar).toContainText('484 pcs · Box');
        const sections = page.locator('details.pdp-accordion');
        await expect(sections).toHaveCount(4);
        await expect(sections.first()).toHaveAttribute('open', '');
        await expect(sections.nth(1)).not.toHaveAttribute('open', '');
        await expect(page.getByRole('heading', { name: 'At a glance' })).toBeVisible();
    });

    test('Bangla: same slug under /bn, Bangla copy, Western numerals', async ({ page }) => {
        const res = await page.goto(`/bn${PATH}`);
        expect(res?.status()).toBe(200);
        await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
        await expect(page.locator('h1')).toHaveText('লেগো ক্লাসিক ক্রিয়েটিভ ব্রিকস 484 পিস');
        await expect(page.getByText('মাত্র 3টি বাকি', { exact: true })).toBeVisible();
        await expect(page.getByText('আপনার ৳740 সাশ্রয় · ক্যাশ অন ডেলিভারি আছে')).toBeVisible();
        await expect(page.locator('#pdp-variants')).toContainText('484 পিস-এ টাব এখন স্টকে নেই');
        await expect(page.getByRole('link', { name: '90 পিস' })).toHaveAttribute('href', `/bn${PATH}?variant=501`);
        await expect(page.locator('head link[rel="canonical"]')).toHaveAttribute('href', abs(`/bn${PATH}`));
    });
});
