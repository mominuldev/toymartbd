import { expect, test, type Page } from '@playwright/test';

import { MOCK_API_URL, STOREFRONT_URL } from './constants';

// P4-STO-01 layout + P4-STO-12 status pages. Asserted with JavaScript DISABLED (the HTML a
// crawler or a visitor on a weak connection gets), except the keyboard block at the end.
test.use({ javaScriptEnabled: false });

test.beforeAll(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

const megaMenu = (page: Page, name: string) => page.getByRole('navigation', { name });

test.describe('header, mega menu, footer and tab bar (server-rendered)', () => {
    test('English: header controls, mega-menu links from the API, footer, tab bar', async ({ page }) => {
        await page.goto('/');
        const header = page.locator('header').first();
        await expect(header.getByRole('link', { name: 'Toy Mart BD — home' })).toHaveAttribute('href', '/');
        await expect(header.locator('form[role="search"]').first()).toHaveAttribute('action', '/search');
        await expect(header.locator('a[hreflang="bn"]')).toHaveAttribute('href', '/bn');
        await expect(header.getByRole('link', { name: 'Wishlist' })).toHaveAttribute('href', '/wishlist');
        await expect(header.getByRole('link', { name: 'Cart, empty' })).toHaveAttribute('href', '/cart');

        // All three levels are real links in the HTML, from the mock API's tree.
        const menu = megaMenu(page, 'Shop by category');
        await expect(menu.locator('a[href="/categories/building-blocks"]').first()).toContainText('Building Blocks');
        await expect(menu.locator('a[href="/categories/lego-sets"]')).toHaveText('LEGO');
        await expect(menu.locator('a[href="/categories/lego-classic"]')).toHaveText('LEGO Classic');
        await expect(menu.locator('a[href="/categories/building-blocks"]', { hasText: 'All in Building Blocks' })).toHaveCount(1);
        // Depth is capped at 3 even though the fixture has a 4th level.
        expect(await page.content()).not.toContain('too-deep-level-4');
        // No ARIA menu roles without the keyboard layer that backs them.
        await expect(menu.locator('[role="menubar"]')).toHaveCount(0);

        const footer = page.locator('footer');
        // Figma Footer 124:3310: help tile + Shop / Help / About link tiles + "Say hi!".
        for (const heading of ['Stuck choosing a toy? We love helping.', 'Shop', 'Help', 'About', 'Say hi!'])
            await expect(footer.getByRole('heading', { name: heading, exact: true })).toBeVisible();
        await expect(footer.getByRole('link', { name: 'Our story' })).toHaveAttribute('href', '/pages/about');
        // Placeholder contact details are tagged, and never become a dialable link.
        await expect(footer.locator('[data-placeholder="owner-to-confirm"]').first()).toBeVisible();
        await expect(footer.locator('a[href^="tel:"]')).toHaveCount(0);

        // The tab bar is display:none at this desktop width; it is still in the HTML.
        const tabs = page.locator('nav[aria-label="Shortcuts"]');
        await expect(tabs.locator('a')).toHaveCount(5);
        await expect(tabs.locator('a[aria-current="page"]')).toHaveAttribute('href', '/');
    });

    test('Bangla: same chrome, Bangla names, English fallback marked, /bn links', async ({ page }) => {
        await page.goto('/bn/brands');
        await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
        await expect(page.locator('body')).toHaveClass(/lang-bn/);
        const header = page.locator('header').first();
        await expect(header.locator('form[role="search"]').first()).toHaveAttribute('action', '/bn/search');
        await expect(header.locator('a[hreflang="en"]')).toHaveAttribute('href', '/brands');

        const menu = megaMenu(page, 'ক্যাটাগরি অনুযায়ী কিনুন');
        await expect(menu.locator('a[href="/bn/categories/lego-classic"]')).toHaveText('লেগো ক্লাসিক');
        await expect(menu.locator('a[href="/bn/categories/lego-technic"]')).toHaveAttribute('lang', 'en');
        await expect(menu.locator('a[href="/bn/categories/science-learning"]').first()).toHaveAttribute('lang', 'en');

        const footer = page.locator('footer');
        await expect(footer.getByRole('heading', { name: 'কিনুন', exact: true })).toBeVisible();
        await expect(footer.getByRole('link', { name: 'আমাদের সম্পর্কে' })).toHaveAttribute('href', '/bn/pages/about');
        await expect(page.locator('nav[aria-label="শর্টকাট"] a', { hasText: 'ক্যাটাগরি' })).toHaveAttribute('href', '/bn/categories');
    });

    test('a skip link is the first focusable element and targets <main>', async ({ page }) => {
        await page.goto('/brands');
        const skip = page.locator('body a').first();
        await expect(skip).toHaveText('Skip to content');
        await expect(skip).toHaveAttribute('href', '#main');
        await expect(page.locator('main#main')).toHaveCount(1);
    });

    test('the search bar submits GET /search?q= without JavaScript, in both locales', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/');
        await page.locator('#search-desktop').fill('lego');
        await page.locator('#search-desktop').press('Enter');
        await expect(page).toHaveURL(/\/search\?q=lego$/);
        await expect(page.locator('h1')).toHaveText('Results for “lego”');
        await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
        await expect(page.locator('header a[hreflang="bn"]')).toHaveAttribute('href', '/bn/search?q=lego');

        await page.goto('/bn/search?q=লেগো');
        await expect(page.locator('h1')).toHaveText('“লেগো” — যা পাওয়া গেল');
        await expect(page.locator('#search-desktop')).toHaveValue('লেগো');
    });

    test('category links land on the category listing', async ({ page }) => {
        const res = await page.goto('/bn/categories/lego-sets');
        expect(res?.status()).toBe(200);
        await expect(page.locator('h1')).toHaveText('লেগো');
        await expect(page.locator('main nav[aria-label="উপ-ক্যাটাগরি"] a[href="/bn/categories/lego-city"]')).toHaveText('লেগো সিটি');
        await page.goto('/categories');
        await expect(page.locator('h1')).toHaveText('All categories');
        await expect(page.locator('main a[href="/categories/cars-rc"]')).toHaveText('Cars & Remote Control');
    });
});

test.describe('404 for unmatched URLs is styled and server-rendered (no JS)', () => {
    for (const [path, lang, title, home, toggle] of [
        ['/no-such-page', 'en', "This page isn't here", '/', { lang: 'bn', href: '/bn/no-such-page' }],
        ['/bn/no/such/page', 'bn', 'এই পাতাটি নেই', '/bn', { lang: 'en', href: '/no/such/page' }],
    ] as const) {
        test(`${path}`, async ({ page }) => {
            const res = await page.goto(path);
            expect(res?.status()).toBe(404);
            expect(await res?.text()).not.toContain('__next_error__');
            await expect(page.locator('html')).toHaveAttribute('lang', lang);
            await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
            await expect(page.locator('h1')).toHaveText(title);
            // Styled: the token stylesheet and the site chrome are there.
            await expect(page.locator('link[rel="stylesheet"]').first()).toBeAttached();
            await expect(page.locator('footer')).toBeVisible();
            // Routes back into the catalog.
            await expect(page.locator('main').getByRole('link').first()).toHaveAttribute('href', home);
            await expect(page.locator(`main a[href="${home === '/' ? '' : home}/categories"]`).first()).toBeVisible();
            await expect(page.locator(`main a[href="${home === '/' ? '' : home}/categories/building-blocks"]`)).toBeVisible();
            // The language switch keeps its hreflang and points at the same URL in the other locale.
            await expect(page.locator(`header a[hreflang="${toggle.lang}"]`)).toHaveAttribute('href', toggle.href);
        });
    }

    test('links to pages not built yet get the same styled 404', async ({ page }) => {
        // `/pages/{slug}` is a real route now (Phase 7 CMS pages), so an unmatched URL under it
        // has to be deeper than one segment.
        for (const path of ['/pages/privacy/print', '/bn/wishlist']) {
            const res = await page.goto(path);
            expect(res?.status(), path).toBe(404);
            await expect(page.locator('h1'), path).toHaveText(path.startsWith('/bn') ? 'এই পাতাটি নেই' : "This page isn't here");
        }
    });
});

test.describe('static pages', () => {
    test('a CMS page renders server-side in both locales with canonical and hreflang', async ({ page }) => {
        const res = await page.goto('/pages/privacy');
        expect(res?.status()).toBe(200);
        await expect(page.locator('h1')).toHaveText('Privacy policy');
        await expect(page.locator('main h2').first()).toHaveText('What we collect');
        await expect(page.locator('head link[rel="canonical"]')).toHaveAttribute('href', `${STOREFRONT_URL}/pages/privacy`);
        await expect(page.locator('head link[rel="alternate"][hreflang="bn"]')).toHaveAttribute('href', `${STOREFRONT_URL}/bn/pages/privacy`);
        await expect(page.locator('head link[rel="alternate"][hreflang="x-default"]')).toHaveAttribute('href', `${STOREFRONT_URL}/pages/privacy`);
        await page.goto('/bn/pages/privacy');
        await expect(page.locator('h1')).toHaveText('গোপনীয়তা নীতি');
        await expect(page.locator('head link[rel="canonical"]')).toHaveAttribute('href', `${STOREFRONT_URL}/bn/pages/privacy`);
    });

    test('a CMS page that is not live is a 404, noindex', async ({ page }) => {
        // notFound() from a matched page: right status and robots, but Next's bare shell without
        // JavaScript (README "Known limitations") — the styled check lives with unmatched URLs.
        const res = await page.goto('/pages/terms');
        expect(res?.status()).toBe(404);
        await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
        expect((await page.goto('/bn/pages/terms'))?.status()).toBe(404);
    });

    test('About renders on the template, marked as draft, with hreflang and a way back', async ({ page }) => {
        await page.goto('/pages/about');
        await expect(page.locator('h1')).toHaveText('About Toy Mart BD');
        await expect(page.locator('[data-placeholder="draft-copy"]')).toBeVisible();
        await expect(page.locator('head link[rel="alternate"][hreflang="bn"]')).toHaveAttribute('href', `${STOREFRONT_URL}/bn/pages/about`);
        await expect(page.locator('main a[href="/pages/contact"]').first()).toBeVisible();
        await page.goto('/bn/pages/about');
        await expect(page.locator('h1')).toHaveText('টয় মার্ট বিডি সম্পর্কে');
    });

    test('maintenance has no site chrome, is noindex, and offers the (sample) hotline', async ({ page }) => {
        await page.goto('/bn/maintenance');
        await expect(page.locator('h1')).toHaveText('কিছুক্ষণের মধ্যেই ফিরছি');
        await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
        await expect(page.locator('header')).toHaveCount(0);
        await expect(page.locator('footer')).toHaveCount(0);
        await expect(page.getByText('09610-123456').first()).toBeVisible();
    });
});

test.describe('320px reflow: no horizontal scroll', () => {
    test.use({ viewport: { width: 320, height: 640 } });

    for (const path of ['/', '/bn', '/brands', '/bn/brands', '/categories', '/bn/categories/lego-sets', '/no-such-page', '/bn/no-such-page', '/bn/pages/about', '/bn/search?q=লেগো', '/search?q=zzqx', '/toys', '/bn/toys?age=24-48', '/maintenance']) {
        test(path, async ({ page }) => {
            await page.goto(path);
            const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
            expect(scroll, `${path} scrollWidth`).toBeLessThanOrEqual(client);
            await expect(page.getByRole('navigation', { name: path.startsWith('/bn') ? 'শর্টকাট' : 'Shortcuts' })).toBeVisible({
                visible: path !== '/maintenance' && path !== '/bn/maintenance',
            });
        });
    }
});

test.describe('mega menu keyboard contract (JavaScript)', () => {
    test.use({ javaScriptEnabled: true, viewport: { width: 1440, height: 900 } });

    test('open, move, wrap and close with the keyboard', async ({ page }) => {
        await page.goto('/');
        const menu = page.locator('nav.mega-menu[data-enhanced]');
        await expect(menu.locator('[role="menubar"]')).toHaveCount(1);
        const first = menu.getByRole('menuitem', { name: 'Building Blocks', exact: true });
        await first.focus();

        await page.keyboard.press('ArrowRight');
        await expect(menu.getByRole('menuitem', { name: 'Cars & Remote Control', exact: true })).toBeFocused();
        await page.keyboard.press('ArrowLeft');
        await expect(first).toBeFocused();
        await expect(first).toHaveAttribute('aria-expanded', 'false');

        await page.keyboard.press('ArrowDown');
        await expect(first).toHaveAttribute('aria-expanded', 'true');
        const panel = page.locator(`#${await first.getAttribute('aria-controls')}`);
        await expect(panel).toBeVisible();
        await expect(panel.getByRole('menuitem', { name: 'All in Building Blocks' })).toBeFocused();
        await page.keyboard.press('ArrowDown');
        await expect(panel.getByRole('menuitem', { name: 'LEGO', exact: true })).toBeFocused();
        await page.keyboard.press('ArrowUp');
        await page.keyboard.press('ArrowUp'); // wraps to the bottom of column 1
        await expect(panel.getByRole('menuitem', { name: 'DUPLO Animals' })).toBeFocused();
        await page.keyboard.press('Home');
        await page.keyboard.press('ArrowRight'); // same row, next column
        await expect(panel.getByRole('menuitem', { name: 'Wooden Blocks' })).toBeFocused();

        await page.keyboard.press('Escape');
        await expect(panel).toBeHidden();
        await expect(first).toBeFocused();
        await expect(first).toHaveAttribute('aria-expanded', 'false');
    });

    test('an open panel stays open and swaps content when moving along the bar', async ({ page }) => {
        await page.goto('/bn');
        const menu = page.locator('nav.mega-menu[data-enhanced]');
        const first = menu.getByRole('menuitem', { name: 'বিল্ডিং ব্লক', exact: true });
        await first.focus();
        await page.keyboard.press('Enter');
        await page.keyboard.press('Escape');
        await page.keyboard.press('Enter');
        await expect(first).toHaveAttribute('aria-expanded', 'true');
        await first.focus();
        await page.keyboard.press('ArrowRight');
        const second = menu.getByRole('menuitem', { name: 'গাড়ি ও রিমোট', exact: true });
        await expect(second).toBeFocused();
        await expect(second).toHaveAttribute('aria-expanded', 'true');
        await expect(first).toHaveAttribute('aria-expanded', 'false');
    });
});
