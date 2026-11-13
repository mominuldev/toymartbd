import { expect, test, type Page } from '@playwright/test';

import { MOCK_API_URL, STOREFRONT_URL } from './constants';

// P4-STO-03: category and brand listings, asserted with JavaScript DISABLED — the filter form,
// chips, sort and "load more" are plain GET forms and links, so this is what every visitor gets
// before hydration (and what a crawler sees). The mock API pages 4 products at a time.
test.use({ javaScriptEnabled: false });

test.beforeAll(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

const abs = (path: string) => `${STOREFRONT_URL}${path}`;
const cards = (page: Page) => page.locator('main article h3');
const sidebar = (page: Page) => page.locator('#listing-filters-desktop');

test.describe('desktop', () => {
    test.use({ viewport: { width: 1440, height: 1000 } });

    test('a category lists its products and those of its sub-categories, server-rendered', async ({ page }) => {
        const res = await page.goto('/categories/building-blocks');
        expect(res?.status()).toBe(200);
        await expect(page.locator('h1')).toHaveText('Building Blocks');
        await expect(page.locator('nav[aria-label="Breadcrumb"] a')).toHaveText(['Home', 'Building Blocks']);
        await expect(page.getByText('10 products', { exact: true })).toBeVisible();
        await expect(cards(page)).toHaveText([
            'Hape Wooden Rainbow Stacker',
            'LEGO Classic Creative Bricks 484 pcs',
            'Wooden Alphabet Blocks',
            'Big Building Bag 80 pcs',
        ]);
        // Prices exactly as the API sends them, formatted only; "from" when variants differ.
        const classic = page.locator('main article', { hasText: 'LEGO Classic' });
        await expect(classic).toContainText('৳4,250');
        await expect(classic).toContainText('৳4,990');
        await expect(classic).toContainText('−14%');
        await expect(classic).toContainText('4–12 years');
        await expect(page.locator('main article', { hasText: 'Big Building Bag' })).toContainText('৳990.50');
        await expect(classic.getByRole('link', { name: 'Buy now' })).toHaveAttribute('href', '/products/lego-classic-creative-bricks');

        // Sub-category chips.
        await expect(page.locator('nav[aria-label="Sub-categories"] a')).toHaveText(['All', 'LEGO', 'DUPLO', 'Wooden Blocks', 'Magnetic Tiles']);

        // Bare listing: indexable, self-canonical, reciprocal hreflang.
        await expect(page.locator('head link[rel="canonical"]')).toHaveAttribute('href', abs('/categories/building-blocks'));
        await expect(page.locator('head link[rel="alternate"][hreflang="bn"]')).toHaveAttribute('href', abs('/bn/categories/building-blocks'));
        await expect(page.locator('meta[name="robots"]')).toHaveCount(0);
    });

    test('filter by brand and age with the form, remove a chip, and go back', async ({ page }) => {
        await page.goto('/categories/building-blocks');
        await sidebar(page).locator('input[name="age"][value="12-24"]').check({ force: true });
        await sidebar(page).locator('details', { hasText: 'Brand' }).locator('summary').click();
        await sidebar(page).locator('input[name="brand"][value="hape"]').check();
        await sidebar(page).getByRole('button', { name: 'Show results' }).click();

        await expect(page).toHaveURL(/\/categories\/building-blocks\?age=12-24&brand=hape$/);
        await expect(cards(page)).toHaveText(['Hape Wooden Rainbow Stacker', 'Hape Pound & Tap Bench with Xylophone']);
        const chips = page.locator('nav[aria-label="Active filters"]');
        await expect(chips.locator('li')).toHaveText(['Age: 1–2 years', 'Brand: Hape', 'Clear all']);
        // The form reflects the URL.
        await expect(sidebar(page).locator('input[name="brand"][value="hape"]')).toBeChecked();
        // Two facets: noindex, canonical on the bare listing.
        await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', 'noindex, follow');
        await expect(page.locator('head link[rel="canonical"]')).toHaveAttribute('href', abs('/categories/building-blocks'));
        // Brand counts ignore the brand filter (disjunctive): LEGO has 2 products for 1–2 years here.
        await expect(sidebar(page).locator('label', { hasText: 'LEGO' })).toContainText('2');

        // Remove the brand chip.
        await chips.getByRole('link', { name: 'Remove filter: Brand: Hape' }).click();
        await expect(page).toHaveURL(/\/categories\/building-blocks\?age=12-24$/);
        await expect(chips.locator('li')).toHaveText(['Age: 1–2 years', 'Clear all']);
        await expect(cards(page)).toHaveCount(4);

        // Back restores the previous filter state.
        await page.goBack();
        await expect(page).toHaveURL(/\?age=12-24&brand=hape$/);
        await expect(cards(page)).toHaveCount(2);
        await expect(chips.locator('li')).toHaveText(['Age: 1–2 years', 'Brand: Hape', 'Clear all']);

        await chips.getByRole('link', { name: 'Clear all' }).click();
        await expect(page).toHaveURL(/\/categories\/building-blocks$/);
        await expect(chips).toHaveCount(0);
    });

    test('sort is a link per option and is reflected in the URL', async ({ page }) => {
        await page.goto('/categories/building-blocks?brand=hape');
        await page.locator('main details', { hasText: 'Sort: Featured' }).last().locator('summary').click();
        await page.locator('main').getByRole('link', { name: 'Price: low to high' }).last().click();
        await expect(page).toHaveURL(/\?brand=hape&sort=price_asc$/);
        await expect(cards(page).first()).toHaveText('Wooden Alphabet Blocks');
        await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', 'noindex, follow');
    });

    test('load more follows the cursor, and back returns to the first page', async ({ page }) => {
        await page.goto('/categories/building-blocks');
        await expect(page.getByText('Showing 4 of 10 products')).toBeVisible();
        await page.getByRole('link', { name: 'Show more products' }).click();
        await expect(page).toHaveURL(/\?cursor=/);
        await expect(cards(page)).toHaveText(['Magnetic Tiles Starter Set 60 pcs', 'Hape Pound & Tap Bench with Xylophone', 'DUPLO Animal Train', 'DUPLO My First Town']);
        // Pagination SEO (Phase 7): a later page is indexable and self-canonical, with rel=next.
        await expect(page.locator('meta[name="robots"]')).toHaveCount(0);
        await expect(page.locator('head link[rel="canonical"]')).toHaveAttribute('href', new RegExp(`/categories/building-blocks\\?cursor=`));
        await expect(page.locator('head link[rel="next"]')).toHaveAttribute('href', /\?cursor=/);
        await page.getByRole('link', { name: 'Show more products' }).click();
        await expect(cards(page)).toHaveText(['LEGO Technic Race Car', 'LEGO City Fire Station']);
        await expect(page.getByRole('link', { name: 'Show more products' })).toHaveCount(0);
        await page.goBack();
        await page.goBack();
        await expect(cards(page).first()).toHaveText('Hape Wooden Rainbow Stacker');
    });

    test('an empty result offers a way back', async ({ page }) => {
        await page.goto('/categories/building-blocks?gender=girls');
        await expect(page.getByRole('heading', { name: 'No toys match these filters' })).toBeVisible();
        await page.getByRole('link', { name: 'Clear all filters' }).click();
        await expect(page).toHaveURL(/\/categories\/building-blocks$/);
        await expect(cards(page)).toHaveCount(4);
    });

    test('a brand page lists only that brand, with category chips', async ({ page }) => {
        const res = await page.goto('/brands/lego');
        expect(res?.status()).toBe(200);
        await expect(page.locator('h1')).toHaveText('LEGO');
        await expect(page.getByText('5 products', { exact: true })).toBeVisible();
        await expect(page.locator('main')).not.toContainText('Hape');
        await expect(page.locator('main')).not.toContainText('Barbie');
        await page.locator('nav[aria-label="Sub-categories"]').getByRole('link', { name: 'Building Blocks' }).click();
        await expect(page).toHaveURL(/\/brands\/lego\?category=building-blocks$/);
        await expect(page.locator('nav[aria-label="Active filters"] li').first()).toHaveText('Category: Building Blocks');
    });

    test('an unknown category is a 404', async ({ page }) => {
        const res = await page.goto('/categories/no-such-category');
        expect(res?.status()).toBe(404);
        await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
        expect((await page.goto('/bn/categories/no-such-category'))?.status()).toBe(404);
    });
});

test.describe('Bangla', () => {
    test.use({ viewport: { width: 1440, height: 1000 } });

    test('Bangla names with an English fallback, Western digits, Bangla UI', async ({ page }) => {
        await page.goto('/bn/categories/building-blocks?age=48-72');
        await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
        await expect(page.locator('h1')).toHaveText('বিল্ডিং ব্লক');
        await expect(page.getByText('১ থেকে ১২ বছর বয়সের জন্য লেগো, ডুপ্লো আর কাঠের ব্লক।')).toBeVisible();
        await expect(page.locator('nav[aria-label="চালু ফিল্টার"] li').first()).toHaveText('বয়স: 4–6 বছর');
        const classic = page.locator('main article', { hasText: 'লেগো ক্লাসিক' });
        await expect(classic).toContainText('৳4,250');
        await expect(classic).toContainText('4–12 বছর');
        await expect(classic.getByRole('link', { name: 'এখনই কিনুন' })).toHaveAttribute('href', '/bn/products/lego-classic-creative-bricks');
        // Single facet, single value: indexable and self-canonical in both languages.
        await expect(page.locator('head link[rel="canonical"]')).toHaveAttribute('href', abs('/bn/categories/building-blocks?age=48-72'));
        await expect(page.locator('head link[rel="alternate"][hreflang="en"]')).toHaveAttribute('href', abs('/categories/building-blocks?age=48-72'));
        // The language toggle keeps the filters.
        await expect(page.locator('header a[hreflang="en"]')).toHaveAttribute('href', '/categories/building-blocks?age=48-72');
    });

    test('a product without a Bangla name is marked lang="en"', async ({ page }) => {
        await page.goto('/bn/categories/duplo');
        await expect(page.locator('main article h3[lang="en"]')).toHaveText('DUPLO Animal Train');
    });
});

test.describe('mobile', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('the filter sheet opens and applies without JavaScript', async ({ page }) => {
        await page.goto('/categories/building-blocks');
        const sheet = page.locator('details.filter-sheet');
        await sheet.locator(':scope > summary').click();
        const form = page.locator('#listing-filters-mobile');
        await expect(form).toBeVisible();
        await form.locator('input[name="price"][value="0-500"]').check();
        await form.getByRole('button', { name: 'Show results' }).click();
        await expect(page).toHaveURL(/\?price=0-500$/);
        await expect(cards(page)).toHaveText(['Wooden Alphabet Blocks']);
        await expect(page.locator('details.filter-sheet > summary')).toContainText('1');
    });

    for (const path of ['/categories/building-blocks?age=12-24&brand=hape,lego', '/bn/categories/building-blocks', '/bn/brands/lego']) {
        test(`320px: no horizontal scroll on ${path}`, async ({ page }) => {
            await page.setViewportSize({ width: 320, height: 640 });
            await page.goto(path);
            const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
            expect(scroll).toBeLessThanOrEqual(client);
        });
    }
});
