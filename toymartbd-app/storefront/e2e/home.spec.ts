import { expect, test } from '@playwright/test';

import { MOCK_API_URL } from './constants';

// P4-STO-02 Home (static fallback until the Phase 7 CMS) and the all-toys listing it links to,
// asserted with JavaScript DISABLED. Rails are real API data from the mock catalogue.
test.use({ javaScriptEnabled: false });

test.beforeAll(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

test.describe('desktop', () => {
    test.use({ viewport: { width: 1440, height: 1000 } });

    test('hero, categories, shop by age, rails and the promise band, in Figma order', async ({ page }) => {
        const res = await page.goto('/');
        expect(res?.status()).toBe(200);
        await expect(page.locator('h1')).toHaveText('Tell us the age. See the right toys.');
        await expect(page.locator('main h2')).toHaveText(['Shop by category', 'Shop by age', 'Best sellers', 'New arrivals', 'Why parents shop with us']);

        // Age pills and the CTA land on the URL-addressable all-toys listing.
        const pills = page.locator('nav[aria-label="Choose an age"] a');
        await expect(pills).toHaveCount(7);
        await expect(pills.first()).toHaveText('0–1 yr');
        await expect(pills.nth(2)).toHaveAttribute('href', '/toys?age=24-48');
        await expect(page.getByRole('link', { name: 'Browse all toys' })).toHaveAttribute('href', '/toys');

        // Category tiles from the menu tree; age tiles with counts from the catalogue facet.
        const categories = page.locator('section[aria-labelledby="home-categories"] li a');
        await expect(categories.first()).toHaveAttribute('href', '/categories/building-blocks');
        const ages = page.locator('#shop-by-age li a');
        await expect(ages).toHaveCount(7);
        await expect(ages.nth(1)).toHaveAttribute('href', '/toys?age=12-24');
        await expect(ages.nth(1)).toContainText(/1–2 years\s*\d+ toys/);

        // Best sellers = featured first; new arrivals = newest first. Four cards each on desktop.
        const best = page.locator('section[aria-labelledby="home-best"]');
        await expect(best.locator('article:visible')).toHaveCount(4);
        // The mock's featured order: featured first, then newest.
        await expect(best.locator('article h3').first()).toHaveText('Hape Wooden Rainbow Stacker');
        await expect(best.locator('article h3').nth(1)).toHaveText('LEGO Classic Creative Bricks 484 pcs');
        await expect(best.getByRole('link', { name: 'See all: Best sellers' })).toHaveAttribute('href', '/toys');
        const fresh = page.locator('section[aria-labelledby="home-new"]');
        await expect(fresh.getByRole('link', { name: 'See all: New arrivals' })).toHaveAttribute('href', '/toys?sort=newest');

        // The promise band uses the hotline from site config.
        await expect(page.locator('section[aria-labelledby="home-usp"] li')).toHaveCount(4);
        await expect(page.locator('section[aria-labelledby="home-usp"]')).toContainText('Call 09610-123456 or WhatsApp us');
    });

    test('the hero photo is the LCP element: preloaded, eager, explicitly sized', async ({ page }) => {
        await page.goto('/');
        const img = page.locator('section[aria-labelledby="home-hero-title"] img');
        await expect(img).toHaveAttribute('alt', /pound-and-tap bench/);
        await expect(img).not.toHaveAttribute('loading', 'lazy');
        await expect(img).toHaveAttribute('width', /\d+/);
        await expect(img).toHaveAttribute('height', /\d+/);
        await expect(page.locator('head link[rel="preload"][as="image"]')).toHaveCount(1);
        // Every other image on Home is lazy.
        const eager = await page.locator('main img:not([loading="lazy"])').count();
        expect(eager).toBe(1);
    });

    test('Bangla home', async ({ page }) => {
        await page.goto('/bn');
        await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
        await expect(page.locator('h1')).toHaveText('বয়স বলুন, সঠিক খেলনা দেখুন');
        await expect(page.locator('nav[aria-label="বয়স বেছে নিন"] a').first()).toHaveAttribute('href', '/bn/toys?age=0-12');
        await expect(page.locator('main h2').first()).toHaveText('ক্যাটাগরি');
        await expect(page.locator('section[aria-labelledby="home-usp"]')).toContainText('অভিভাবকেরা কেন আমাদের থেকে কেনেন');
    });
});

test('all toys: the whole catalogue as a listing, with category and brand filters', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    const res = await page.goto('/toys?age=24-48');
    expect(res?.status()).toBe(200);
    await expect(page.locator('h1')).toHaveText('All toys');
    await expect(page.locator('nav[aria-label="Active filters"]')).toContainText('Age: 2–4 years');
    await expect(page.locator('#listing-filters-desktop input[name="category"]').first()).toHaveAttribute('type', 'radio');
    await expect(page.locator('#listing-filters-desktop input[name="brand"]').first()).toBeAttached();
    await page.goto('/bn/toys');
    await expect(page.locator('h1')).toHaveText('সব খেলনা');
});
