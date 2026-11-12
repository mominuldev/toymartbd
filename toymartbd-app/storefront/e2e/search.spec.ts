import { expect, test, type Page } from '@playwright/test';

import { MOCK_API_URL } from './constants';

// P4-STO-04 search results and zero results, asserted with JavaScript DISABLED: the header form,
// related-search chips, filters, sort and numbered pages are GET forms and links. The mock API
// pages search 4 results at a time and matches "lego" in 5 fixtures. The dropdown is checked
// separately with JavaScript on.
test.use({ javaScriptEnabled: false });

test.beforeAll(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

const cards = (page: Page) => page.locator('main article h3');

test.describe('desktop', () => {
    test.use({ viewport: { width: 1440, height: 1000 } });

    test('results reuse the listing: head, related searches, filters, sort and numbered pages', async ({ page }) => {
        const res = await page.goto('/search?q=lego');
        expect(res?.status()).toBe(200);
        await expect(page.locator('h1')).toHaveText('Results for “lego”');
        await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
        await expect(page.locator('nav[aria-label="Breadcrumb"] a')).toHaveText(['Home', 'Search results']);
        await expect(page.getByText('5 toys match your search', { exact: false })).toBeVisible();
        await expect(page.getByText('4 of 5 results', { exact: true })).toBeVisible();
        await expect(cards(page)).toHaveCount(4);
        await expect(page.locator('#search-desktop')).toHaveValue('lego');

        // Related searches: "All results" first and selected, then the matching categories.
        const related = page.locator('nav[aria-label="Related searches"] a');
        await expect(related.first()).toHaveText('All results');
        await expect(related.first()).toHaveAttribute('aria-current', 'page');
        await expect(related.nth(1)).toHaveText('Building Blocks');
        await related.nth(1).click();
        await expect(page).toHaveURL(/\/search\?q=lego&category=building-blocks$/);
        await expect(page.locator('nav[aria-label="Related searches"] a[aria-current="page"]')).toHaveText('Building Blocks');
        await expect(page.locator('nav[aria-label="Active filters"]')).toContainText('Category: Building Blocks');

        // The filter form keeps the query.
        await page.goto('/search?q=lego');
        await expect(page.locator('#listing-filters-desktop input[name="q"]')).toHaveValue('lego');
        await page.locator('#listing-filters-desktop input[name="age"][value="12-24"]').check({ force: true });
        await page.locator('#listing-filters-desktop button[type="submit"]').click();
        await expect(page).toHaveURL(/\/search\?q=lego&age=12-24$/);

        // Sort: relevance is the default and stays out of the URL; "featured" is not offered.
        await page.goto('/search?q=lego');
        const sorts = page.locator('main details:has(summary:has-text("Sort:"))').last();
        await expect(sorts.locator('summary')).toContainText('Sort: Relevance');
        await expect(sorts.locator('a')).toHaveText(['Relevance', 'Newest first', 'Price: low to high', 'Price: high to low', 'Top rated', 'Name: A to Z']);
        await expect(sorts.locator('a', { hasText: 'Newest first' })).toHaveAttribute('href', '/search?q=lego&sort=newest');

        // Numbered pages: real links, the current one marked, rel=next.
        const pager = page.locator('nav[aria-label="Pages"]');
        await expect(page.getByText('Showing 1–4 of 5 results')).toBeVisible();
        await expect(pager.locator('a[aria-current="page"]').last()).toHaveText('1');
        await pager.locator('a[rel="next"]').last().click();
        await expect(page).toHaveURL(/\/search\?q=lego&page=2$/);
        await expect(cards(page)).toHaveCount(1);
        await expect(page.getByText('Showing 5–5 of 5 results')).toBeVisible();
        await expect(pager.locator('a[rel="prev"]').last()).toHaveAttribute('href', '/search?q=lego');
    });

    test('zero results: suggestions from the index, the empty state and "Popular right now"', async ({ page }) => {
        await page.goto('/search?q=lgo');
        await expect(page.locator('h1')).toHaveText('Results for “lgo”');
        await expect(page.locator('main h2').first()).toHaveText('No toys found for “lgo”');
        await expect(page.getByText('No results', { exact: true })).toHaveAttribute('aria-live', 'polite');
        await expect(page.getByText('Did you mean')).toBeVisible();
        await expect(page.getByRole('link', { name: 'lego', exact: true })).toHaveAttribute('href', '/search?q=lego');
        await expect(page.getByRole('link', { name: 'Clear search' })).toHaveAttribute('href', '/search');
        await expect(page.getByRole('link', { name: 'Browse all toys' })).toHaveAttribute('href', '/toys');
        // No toolbar, filters or grid — the empty state replaces them.
        await expect(page.locator('#listing-filters-desktop')).toHaveCount(0);
        const popular = page.locator('section[aria-labelledby="search-popular"]');
        await expect(popular.locator('h2')).toHaveText('Popular right now');
        await expect(popular.locator('article')).toHaveCount(4);
    });

    test('zero results without a correction offer popular searches', async ({ page }) => {
        await page.goto('/search?q=zzqx');
        await expect(page.getByText('Popular searches')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Puzzles', exact: true })).toHaveAttribute('href', '/search?q=Puzzles');
    });

    test('a filter that empties the results keeps the filters and offers to clear them', async ({ page }) => {
        await page.goto('/search?q=lego&price=0-500');
        await expect(page.locator('main h2', { hasText: 'No toys match those filters' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Clear all filters' })).toHaveAttribute('href', '/search?q=lego');
        await expect(page.locator('#listing-filters-desktop')).toHaveCount(1);
    });

    test('Bangla: Western numerals, Bangla copy, the query kept in every link', async ({ page }) => {
        await page.goto('/bn/search?q=লেগো');
        await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
        await expect(page.locator('h1')).toHaveText('“লেগো” — যা পাওয়া গেল');
        await expect(page.locator('#search-desktop')).toHaveValue('লেগো');
        await expect(page.getByText('5টির মধ্যে 4টি ফলাফল')).toBeVisible();
        await expect(page.locator('nav[aria-label="পাতা"] a[rel="next"]').last()).toHaveAttribute('href', `/bn/search?q=${encodeURIComponent('লেগো').replace(/%20/g, '+')}&page=2`);
    });

    test('an empty query shows the search form', async ({ page }) => {
        await page.goto('/search');
        await expect(page.locator('h1')).toHaveText('Search');
        await expect(page.locator('#search-page')).toBeVisible();
    });
});

test.describe('390', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('mobile opens on the filter bar with a one-line results head', async ({ page }) => {
        await page.goto('/search?q=lego');
        await expect(page.locator('h1')).toHaveClass(/max-lg:sr-only/);
        await expect(page.locator('nav[aria-label="Breadcrumb"]')).toBeHidden();
        await expect(page.getByText('5 results', { exact: true })).toBeVisible();
        await expect(page.getByText('for “lego”', { exact: true })).toBeVisible();
        await expect(page.locator('#search-mobile')).toHaveValue('lego');
        // Compact pagination below sm.
        await expect(page.getByText('Page 1 of 2', { exact: true })).toBeVisible();
        const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
        expect(scroll).toBeLessThanOrEqual(client);
    });
});

test.describe('suggestions dropdown (JavaScript)', () => {
    test.use({ javaScriptEnabled: true, viewport: { width: 1440, height: 900 } });

    test('typing shows products, categories, brands and "See all"; arrows walk the rows', async ({ page }) => {
        await page.goto('/');
        const input = page.locator('#search-desktop');
        await input.fill('le');
        const list = page.locator('[role="listbox"]:visible');
        await expect(list.getByText('Products')).toBeVisible();
        await expect(list.locator('[role="option"]').first()).toContainText('LEGO Classic Creative Bricks 484 pcs');
        await expect(list.locator('[role="option"]').first()).toContainText('৳4,250');
        await expect(list.getByText('Brands')).toBeVisible();
        await expect(list.getByRole('link', { name: /See all \d+ results for “le”/ })).toHaveAttribute('href', '/search?q=le');
        await expect(input).toHaveAttribute('aria-expanded', 'true');

        await input.press('ArrowDown');
        const first = list.locator('[role="option"]').first();
        await expect(input).toHaveAttribute('aria-activedescendant', (await first.getAttribute('id')) ?? '');
        await expect(first).toHaveAttribute('aria-selected', 'true');
        await input.press('Escape');
        await expect(input).toHaveAttribute('aria-expanded', 'false');
        await expect(input).toHaveValue('le');
        await input.press('Escape');
        await expect(input).toHaveValue('');

        await input.fill('qqzz');
        await expect(page.getByText('No quick matches — press Enter for full search')).toBeVisible();
        await input.press('Enter');
        await expect(page).toHaveURL(/\/search\?q=qqzz$/);
    });
});
