import { expect, test, type BrowserContext, type Page } from '@playwright/test';

import { bn } from '../lib/i18n/bn';
import { en } from '../lib/i18n/en';
import { CONSENT_POLICY_VERSION, MOCK_API_URL, STOREFRONT_URL } from './constants';

// K-01/K-02 in the browser: the consent banner (opt-in), consent-gated tag loading, one event_id
// shared by gtag / fbq / the server twin, the footer's "Cookie settings", and the browser Purchase
// on the confirmation. The mock API turns GA4 + Meta on (e2e/mock-api.mjs TRACKING_CONFIG).
// Third-party script URLs are answered locally with an empty script — nothing leaves the machine.
// Set TRACKING_SHOTS=<dir> to also save the banner at 390 / 1440 in both locales (Figma check).

test.use({ storageState: { cookies: [], origins: [] }, javaScriptEnabled: true });

const PDP = '/products/lego-classic-creative-bricks?variant=501';
const THIRD_PARTY = /googletagmanager\.com|connect\.facebook\.net/;

async function stubThirdParty(context: BrowserContext): Promise<string[]> {
    const loaded: string[] = [];
    await context.route(THIRD_PARTY, (route) => {
        loaded.push(route.request().url());
        return route.fulfill({ status: 200, contentType: 'application/javascript', body: '' });
    });
    return loaded;
}

function eventPosts(page: Page): string[] {
    const posts: string[] = [];
    page.on('request', (req) => {
        if (req.method() === 'POST' && new URL(req.url()).pathname === '/api/events') posts.push(req.postData() ?? '');
    });
    return posts;
}

const banner = (page: Page) => page.getByRole('dialog', { name: en.consent.title });
const consentCookie = async (context: BrowserContext) => (await context.cookies()).find((c) => c.name === 'tm_consent')?.value ?? null;

test.beforeEach(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

test('opt-in: nothing loads or is sent until the visitor answers; Reject keeps it that way', async ({ page, context }) => {
    const loaded = await stubThirdParty(context);
    const posts = eventPosts(page);
    await page.goto(PDP);
    await expect(banner(page)).toBeVisible();
    await expect(banner(page)).toHaveAttribute('aria-modal', 'false');
    await expect(banner(page).getByRole('link', { name: en.consent.privacy })).toHaveAttribute('href', '/pages/privacy');
    await page.waitForLoadState('load');
    await page.waitForTimeout(500); // lazyOnload runs in idle time after load
    expect(await page.locator('script[src*="googletagmanager"], script[src*="fbevents"]').count()).toBe(0);
    expect(await page.evaluate(() => typeof (window as { fbq?: unknown }).fbq)).toBe('undefined');
    expect(posts).toEqual([]);

    await banner(page).getByRole('button', { name: en.consent.reject }).click();
    await expect(banner(page)).toHaveCount(0);
    expect(decodeURIComponent((await consentCookie(context)) ?? '')).toMatch(new RegExp(`^1\\|${CONSENT_POLICY_VERSION}\\|0\\|0\\|\\d+$`));
    await expect(page.getByRole('status').filter({ hasText: en.consent.saved })).toBeAttached();

    await page.reload();
    await page.waitForLoadState('load');
    await expect(banner(page)).toHaveCount(0);
    await page.waitForTimeout(500);
    expect(loaded).toEqual([]);
    expect(posts).toEqual([]);
    const recorded = (await (await page.request.get(`${MOCK_API_URL}/__control/tracking`)).json()) as { events: unknown[] };
    expect(recorded.events).toEqual([]);
});

test('Accept all: tags load after the page, and view_item / add_to_cart share one event_id with the server twin', async ({ page, context }) => {
    const loaded = await stubThirdParty(context);
    const posts = eventPosts(page);
    await page.goto(PDP);
    await banner(page).getByRole('button', { name: en.consent.acceptAll }).click();
    await expect(banner(page)).toHaveCount(0);
    await expect.poll(() => loaded.length).toBeGreaterThanOrEqual(2);
    expect(loaded.some((u) => u.includes('gtag/js?id=G-E2ETEST01'))).toBe(true);
    expect(loaded.some((u) => u.includes('fbevents.js'))).toBe(true);

    // A fresh PDP view with consent on record: view_item fires once.
    await page.goto(PDP);
    await expect.poll(() => posts.length).toBe(1);
    const sent = JSON.parse(posts[0]!) as { event_id: string; event_name: string; items: Array<{ item_id: string }> };
    expect(sent.event_name).toBe('view_item');
    const ids = await page.evaluate(() => {
        const w = window as unknown as { dataLayer: ArrayLike<unknown>[]; fbq: { queue: unknown[][] } };
        const ga = Array.from(w.dataLayer).map((e) => Array.from(e as ArrayLike<unknown>)).find((e) => e[0] === 'event' && e[1] === 'view_item');
        const meta = w.fbq.queue.find((q) => q[0] === 'track' && q[1] === 'ViewContent');
        return { ga: (ga?.[2] as { event_id?: string } | undefined)?.event_id, meta: (meta?.[3] as { eventID?: string } | undefined)?.eventID };
    });
    expect(ids).toEqual({ ga: sent.event_id, meta: sent.event_id });

    await expect.poll(async () => ((await (await page.request.get(`${MOCK_API_URL}/__control/tracking`)).json()) as { events: unknown[] }).events.length).toBe(1);
    const recorded = ((await (await page.request.get(`${MOCK_API_URL}/__control/tracking`)).json()) as { events: Array<Record<string, unknown>> }).events[0]!;
    expect(recorded).toMatchObject({ event_id: sent.event_id, event_name: 'view_item', consent: { analytics: true, ads: true, mode: 'opt_in', policy_version: CONSENT_POLICY_VERSION } });
    expect(recorded.client).toMatchObject({ user_agent: expect.any(String) });

    // add_to_cart: only after the API confirmed the add.
    await page.getByRole('button', { name: en.pdp.addToCart, exact: true }).first().click();
    await expect(page.getByText(en.cart.added)).toBeVisible();
    await expect.poll(() => posts.length).toBe(2);
    expect(JSON.parse(posts[1]!)).toMatchObject({ event_name: 'add_to_cart', items: [{ quantity: 1 }] });
});

test('Cookie settings reopens the banner with focus inside; Esc closes it and returns focus', async ({ page, context }) => {
    await stubThirdParty(context);
    await page.goto('/');
    await banner(page).getByRole('button', { name: en.consent.choose }).click();
    await expect(banner(page).getByRole('checkbox', { name: en.consent.analytics })).toBeFocused();
    await banner(page).getByRole('checkbox', { name: en.consent.analytics }).check();
    await banner(page).getByRole('button', { name: en.consent.save }).click();
    expect(decodeURIComponent((await consentCookie(context)) ?? '')).toMatch(/^1\|2026-11\|1\|0\|/);

    const settings = page.getByRole('link', { name: en.consent.settings });
    await settings.focus();
    await page.keyboard.press('Enter');
    await expect(banner(page)).toBeVisible();
    await expect(banner(page).getByRole('heading', { name: en.consent.title })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(banner(page)).toHaveCount(0);
    await expect(settings).toBeFocused();
    expect(page.url()).toBe(`${STOREFRONT_URL}/`);
});

test('Bangla: the banner speaks Bangla and links to the Bangla privacy policy', async ({ page, context }) => {
    await stubThirdParty(context);
    await page.goto('/bn');
    const dialog = page.getByRole('dialog', { name: bn.consent.title });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('link', { name: bn.consent.privacy })).toHaveAttribute('href', '/bn/pages/privacy');
    await expect(dialog.getByRole('button', { name: bn.consent.acceptAll })).toBeVisible();
    // The banner is first in the tab order.
    await page.keyboard.press('Tab');
    await expect(dialog.getByRole('link', { name: bn.consent.privacy })).toBeFocused();
});

test('confirmation: the browser Purchase fires once with the API event id; checkout carried consent', async ({ browser }) => {
    // Place the order without JavaScript (the shortest path), as a visitor who accepted.
    const accepted = { name: 'tm_consent', value: encodeURIComponent(`1|${CONSENT_POLICY_VERSION}|1|1|1763000000`), domain: '127.0.0.1', path: '/', expires: -1, httpOnly: false, secure: false, sameSite: 'Lax' as const };
    const noJs = await browser.newContext({ baseURL: STOREFRONT_URL, javaScriptEnabled: false, storageState: { cookies: [accepted], origins: [] } });
    const page = await noJs.newPage();
    await page.goto(PDP);
    await page.getByRole('button', { name: en.pdp.addToCart, exact: true }).first().click();
    await page.goto('/checkout');
    await page.locator('#name').fill('Sabrina Akter');
    await page.locator('#phone').fill('01712-345678');
    await page.locator('#district_id').selectOption('13');
    await page.locator('button[value="refresh"]').click();
    await page.locator('#upazila_id').selectOption('101');
    await page.locator('#area').fill('Dhanmondi');
    await page.locator('#address_line').fill('House 12, Road 5');
    await page.locator('button[value="refresh"]').click();
    await page.locator('button[name="intent"][value="place"]').first().click();
    await expect(page).toHaveURL(/\/orders\/TMB-26-\d{5}$/);
    const confirmation = page.url();
    const state = await noJs.storageState();
    await noJs.close();

    const recorded = (await (await fetch(`${MOCK_API_URL}/__control/tracking`)).json()) as { checkout: Array<Record<string, unknown>> };
    expect(recorded.checkout[0]).toMatchObject({ consent: { analytics: true, ads: true, mode: 'opt_in', policy_version: CONSENT_POLICY_VERSION }, client: { user_agent: expect.any(String) } });

    const js = await browser.newContext({ baseURL: STOREFRONT_URL, storageState: state });
    await stubThirdParty(js);
    const view = await js.newPage();
    await view.goto(confirmation);
    await expect.poll(() => view.evaluate(() => ((window as unknown as { fbq?: { queue: unknown[][] } }).fbq?.queue ?? []).filter((q) => q[1] === 'Purchase').length)).toBe(1);
    const purchase = await view.evaluate(() => (window as unknown as { fbq: { queue: unknown[][] } }).fbq.queue.find((q) => q[1] === 'Purchase'));
    expect(purchase?.[3]).toEqual({ eventID: expect.stringMatching(/^00000000-0000-5000-8000-\d{12}$/) });
    // Never to GA4 from the browser (the server sends GA4's purchase).
    expect(await view.evaluate(() => Array.from((window as unknown as { dataLayer: ArrayLike<unknown>[] }).dataLayer).some((e) => Array.from(e as ArrayLike<unknown>)[1] === 'purchase'))).toBe(false);
    // A reload does not fire it again.
    await view.reload();
    await view.waitForLoadState('load');
    await view.waitForTimeout(300);
    expect(await view.evaluate(() => ((window as unknown as { fbq?: { queue: unknown[][] } }).fbq?.queue ?? []).filter((q) => q[1] === 'Purchase').length)).toBe(0);
    await js.close();
});

test('without JavaScript there is no banner, and Cookie settings is a plain link to the privacy policy', async ({ browser }) => {
    const noJs = await browser.newContext({ baseURL: STOREFRONT_URL, javaScriptEnabled: false });
    const page = await noJs.newPage();
    await page.goto('/');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.getByRole('link', { name: en.consent.settings })).toHaveAttribute('href', '/pages/privacy');
    await noJs.close();
});

const shots = process.env.TRACKING_SHOTS;
for (const [locale, prefix] of [
    ['en', ''],
    ['bn', '/bn'],
] as const) {
    for (const width of [390, 1440]) {
        test(`screenshot: banner ${locale} @${width}`, async ({ page, context }) => {
            test.skip(!shots, 'TRACKING_SHOTS not set');
            await stubThirdParty(context);
            await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
            await page.emulateMedia({ reducedMotion: 'reduce' });
            await page.goto(`${prefix}/`);
            const dialog = page.getByRole('dialog');
            await expect(dialog).toBeVisible();
            await page.waitForTimeout(400);
            await page.screenshot({ path: `${shots}/banner-${locale}-${width}.png` });
            await dialog.getByRole('button', { name: (locale === 'bn' ? bn : en).consent.choose }).click();
            await page.waitForTimeout(200);
            await page.screenshot({ path: `${shots}/banner-${locale}-${width}-choose.png` });
            const box = await dialog.boundingBox();
            expect(box).not.toBeNull();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        });
    }
}
