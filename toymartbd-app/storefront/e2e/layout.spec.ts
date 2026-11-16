import { expect, test, type Page } from '@playwright/test';

import { CRAWLER_UA, MOCK_API_URL, STOREFRONT_URL } from './constants';

// P4-STO-01 layout + P4-STO-12 status pages. Asserted with JavaScript DISABLED (the HTML a
// crawler or a visitor on a weak connection gets), except the keyboard block at the end.
// A crawler UA: an HTML-limited bot gets every metadata tag in <head> (next.config.ts htmlLimitedBots).
test.use({ javaScriptEnabled: false, userAgent: CRAWLER_UA });

test.beforeAll(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

const megaMenu = (page: Page, name: string) => page.getByRole('navigation', { name });

test.describe('site & branding in <head> (Store settings → Site & branding)', () => {
    test('favicons and the share-image fallback come from store-info, in both locales', async ({ page }) => {
        for (const [path, siteName] of [['/', 'Toy Mart BD'], ['/bn', 'টয় মার্ট বিডি']] as const) {
            await page.goto(path);
            await expect(page.locator('link[rel="icon"][sizes="32x32"]')).toHaveAttribute('href', `${MOCK_API_URL}/site-assets/9/icon-32.png`);
            await expect(page.locator('link[rel="apple-touch-icon"]')).toHaveAttribute('href', `${MOCK_API_URL}/site-assets/9/icon-180.png`);
            await expect(page.locator('meta[property="og:site_name"]')).toHaveAttribute('content', siteName);
            await expect(page.locator('meta[property="og:image"]').first()).toHaveAttribute('content', `${MOCK_API_URL}/site-assets/10/share.jpg`);
        }
        // No logo set: the header keeps the built-in Wordmark.
        await expect(page.getByTestId('site-logo')).toHaveCount(0);
    });
});

test.describe('header, mega menu, footer and tab bar (server-rendered)', () => {
    test('English: header controls, mega-menu links from the API, footer, tab bar', async ({ page }) => {
        await page.goto('/');
        const header = page.locator('header').first();
        await expect(header.getByRole('link', { name: 'ToyMart BD — home' })).toHaveAttribute('href', '/');
        await expect(header.locator('form[role="search"]').first()).toHaveAttribute('action', '/search');
        await expect(header.locator('a[hreflang="bn"]')).toHaveAttribute('href', '/bn');
        await expect(header.getByRole('link', { name: 'Wishlist' })).toHaveAttribute('href', '/account/wishlist');
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
        await expect(footer.getByRole('link', { name: 'Our story' })).toHaveAttribute('href', '/about');
        // Contact details come from GET /storefront/store-info (the mock sets hotline, WhatsApp,
        // trade licence and BIN; email is unset). A set value is a link, an unset one is the
        // tagged sample and never a link.
        await expect(footer.locator('a[href="tel:+8809666700800"]')).toHaveText('09666-700800');
        await expect(footer.getByRole('link', { name: 'Chat on WhatsApp' })).toHaveAttribute('href', 'https://wa.me/8801812345678');
        await expect(footer.locator('span[data-placeholder="owner-to-confirm"]', { hasText: 'hello@toymartbd.com' })).toBeVisible();
        await expect(footer.locator('a[href^="mailto:"]')).toHaveCount(0);
        await expect(footer).not.toContainText('09610-123456');
        await expect(footer).toContainText('Trade licence TRAD/DNCC/012345/2026 · BIN 004567891-0101');
        // The header shows the same hotline.
        await expect(page.locator('header a[href="tel:+8809666700800"]')).toHaveText('09666-700800');

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
        await expect(footer.getByRole('link', { name: 'আমাদের সম্পর্কে' })).toHaveAttribute('href', '/bn/about');
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

test('the header language menu opens above the mega menu bar', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/');
    await page.locator('details.locale-menu > summary').click();
    const panel = page.locator('details.locale-menu > div');
    // The open menu overlaps the mega menu bar, whose row was drawn at z-20 over the menu's z-10:
    // its middle showed a category link instead. Top, middle and bottom must all be the menu.
    const covered = await panel.evaluate((menu) => {
        const b = menu.getBoundingClientRect();
        return [b.top + 8, b.top + b.height / 2, b.bottom - 8].filter((y) => !menu.contains(document.elementFromPoint(b.left + b.width / 2, y)));
    });
    expect(covered).toEqual([]);
    await panel.locator('a[hreflang="bn"]').click();
    await expect(page).toHaveURL(/\/bn$/);
});

test.describe('404 for unmatched URLs is styled and server-rendered (no JS)', () => {
    for (const [path, lang, title, home, toggle] of [
        // Two segments: a one-segment URL matches the CMS page route (/{slug}) — see 'static pages'.
        ['/no-such/page', 'en', "This page isn't here", '/', { lang: 'bn', href: '/bn/no-such/page' }],
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
        // `/{slug}` is a real route (CMS pages at the site root), so an unmatched URL has to be
        // deeper than one segment.
        for (const path of ['/privacy-policy/print', '/bn/no-such/wishlist']) {
            const res = await page.goto(path);
            expect(res?.status(), path).toBe(404);
            await expect(page.locator('h1'), path).toHaveText(path.startsWith('/bn') ? 'এই পাতাটি নেই' : "This page isn't here");
        }
    });
});

test.describe('static pages', () => {
    test('a CMS page renders server-side in both locales with canonical and hreflang', async ({ page }) => {
        const res = await page.goto('/privacy-policy');
        expect(res?.status()).toBe(200);
        await expect(page.locator('h1')).toHaveText('Privacy policy');
        await expect(page.locator('main h2').first()).toHaveText('What we collect');
        await expect(page.locator('head link[rel="canonical"]')).toHaveAttribute('href', `${STOREFRONT_URL}/privacy-policy`);
        await expect(page.locator('head link[rel="alternate"][hreflang="bn"]')).toHaveAttribute('href', `${STOREFRONT_URL}/bn/privacy-policy`);
        await expect(page.locator('head link[rel="alternate"][hreflang="x-default"]')).toHaveAttribute('href', `${STOREFRONT_URL}/privacy-policy`);
        await page.goto('/bn/privacy-policy');
        await expect(page.locator('h1')).toHaveText('গোপনীয়তা নীতি');
        await expect(page.locator('head link[rel="canonical"]')).toHaveAttribute('href', `${STOREFRONT_URL}/bn/privacy-policy`);
    });

    test('a page published from the builder renders its sections with the home blocks, one H1 and one FAQPage', async ({ page }) => {
        for (const [path, question] of [
            ['/privacy-policy', 'Do you sell my phone number?'],
            ['/bn/privacy-policy', 'আপনারা কি আমার ফোন নম্বর বিক্রি করেন?'],
        ] as const) {
            await page.goto(path);
            await expect(page.locator('h1'), path).toHaveCount(1);
            await expect(page.locator('main [data-block="rich_text"]'), path).toHaveCount(1);
            await expect(page.locator('main [data-block="faq"] summary'), path).toHaveText(question);
            // Sections are the live layout: no builder ids on the live page.
            await expect(page.locator('main [data-block-id]'), path).toHaveCount(0);
            const ld = await page.locator('script[type="application/ld+json"]').allTextContents();
            expect(ld.filter((block) => block.includes('"FAQPage"')), path).toHaveLength(1);
        }
    });

    test('a page never published from the builder keeps its legacy body and template', async ({ page }) => {
        expect((await page.goto('/delivery-policy'))?.status()).toBe(200);
        await expect(page.locator('h1')).toHaveText('Delivery policy');
        await expect(page.locator('main [data-block]')).toHaveCount(0);
        await expect(page.getByRole('navigation', { name: 'On this page' })).toBeVisible();
        await expect(page.locator('main h2').first()).toContainText('Inside Dhaka');
    });

    test('a page preview link with a bad token is refused before draft mode turns on', async ({ request }) => {
        for (const token of ['nope', `page_draft.1.9999999999.${'a'.repeat(64)}`, `home_draft.1.9999999999.${'a'.repeat(64)}`]) {
            const res = await request.get(`/api/preview/page?token=${token}&locale=en`, { maxRedirects: 0 });
            expect(res.status(), token).toBe(403);
            expect(res.headers()['set-cookie'] ?? '', token).not.toContain('tm_page_preview');
        }
    });

    test('the old /pages/{slug} URLs redirect permanently to the page at the site root', async ({ request }) => {
        for (const [from, to] of [
            ['/pages/privacy-policy', '/privacy-policy'],
            ['/bn/pages/about', '/bn/about'],
        ] as const) {
            const res = await request.get(from, { maxRedirects: 0 });
            expect(res.status(), from).toBe(308);
            expect(new URL(res.headers()['location']!, STOREFRONT_URL).pathname, from).toBe(to);
        }
    });

    test('a CMS page that is not live is a 404, noindex', async ({ page }) => {
        // notFound() from a matched page: right status and robots, but Next's bare shell without
        // JavaScript (README "Known limitations") — the styled check lives with unmatched URLs.
        const res = await page.goto('/terms');
        expect(res?.status()).toBe(404);
        await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
        expect((await page.goto('/bn/terms'))?.status()).toBe(404);
    });

    test('About renders on the template, marked as draft, with hreflang and a way back', async ({ page }) => {
        await page.goto('/about');
        await expect(page.locator('h1')).toHaveText('About Toy Mart BD');
        await expect(page.locator('[data-placeholder="draft-copy"]')).toBeVisible();
        await expect(page.locator('head link[rel="alternate"][hreflang="bn"]')).toHaveAttribute('href', `${STOREFRONT_URL}/bn/about`);
        await expect(page.locator('main a[href="/contact"]').first()).toBeVisible();
        await page.goto('/bn/about');
        await expect(page.locator('h1')).toHaveText('টয় মার্ট বিডি সম্পর্কে');
    });

    test('maintenance has no site chrome, is noindex, and offers the store hotline', async ({ page }) => {
        await page.goto('/bn/maintenance');
        await expect(page.locator('h1')).toHaveText('কিছুক্ষণের মধ্যেই ফিরছি');
        await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
        await expect(page.locator('header')).toHaveCount(0);
        await expect(page.locator('footer')).toHaveCount(0);
        await expect(page.getByText('09666-700800').first()).toBeVisible();
        await expect(page.locator('a[href="tel:+8809666700800"]').first()).toBeVisible();
    });
});

test.describe('320px reflow: no horizontal scroll', () => {
    test.use({ viewport: { width: 320, height: 640 } });

    for (const path of ['/', '/bn', '/brands', '/bn/brands', '/categories', '/bn/categories/lego-sets', '/no-such/page', '/bn/no-such/page', '/bn/about', '/bn/search?q=লেগো', '/search?q=zzqx', '/toys', '/bn/toys?age=24-48', '/maintenance']) {
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
