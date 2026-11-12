import { expect, test, type Page } from '@playwright/test';

import { bn } from '../lib/i18n/bn';
import { en } from '../lib/i18n/en';
import { MOCK_API_URL } from './constants';
import { auditScreen, layoutProblems } from './layout-audit';

// P4-I18N-02 Bangla layout pass. Every MVP storefront screen, in English and Bangla, at the two
// Figma frame widths (390 mobile, 1440 desktop): a full-page screenshot is attached to the report,
// and the page must have no text overflowing its box, nothing clipped and no horizontal scroll
// (e2e/layout-audit.ts). JavaScript is disabled so the measured HTML is the server's, the same
// for every visitor, and the screenshots are deterministic.
//
// Account pages (app/[locale]/account) are not in this pass yet: they are being built in parallel.
test.use({ javaScriptEnabled: false, reducedMotion: 'reduce' });

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
    ['search', '/search?q=lego'],
    ['search-empty', '/search?q=zzzznothing'],
    ['product', '/products/lego-classic-creative-bricks'],
    ['cart-empty', '/cart'],
    ['track', '/track'],
    ['delivery-charge', '/delivery-charge'],
    ['about', '/pages/about'],
    ['not-found', '/products/no-such-toy'],
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
    test.describe(`${width}px`, () => {
        test.use({ viewport });

        for (const [locale, , prefix] of LOCALES) {
            test(`${locale}: static screens fit their boxes`, async ({ page }, testInfo) => {
                for (const [label, path] of STATIC_SCREENS) {
                    await page.goto(at(prefix, path));
                    await auditScreen(page, testInfo, `${locale}-${width}-${label}`);
                }
            });

            test(`${locale}: cart, checkout, confirmation and tracking fit their boxes`, async ({ page }, testInfo) => {
                const t = LOCALES.find(([l]) => l === locale)![1];
                await page.goto(at(prefix, '/products/lego-classic-creative-bricks?variant=501'));
                await page.getByRole('button', { name: t.pdp.addToCart, exact: true }).first().click();
                await expect(page).toHaveURL(new RegExp(`${prefix}/cart$`));
                await auditScreen(page, testInfo, `${locale}-${width}-cart`);

                await page.getByRole('link', { name: t.cart.checkout, exact: true }).first().click();
                await expect(page).toHaveURL(new RegExp(`${prefix}/checkout$`));
                await auditScreen(page, testInfo, `${locale}-${width}-checkout-blank`);
                await fillCheckout(page, locale);
                await auditScreen(page, testInfo, `${locale}-${width}-checkout-filled`);

                await page.locator('button[value="place"]:visible').first().click();
                await expect(page).toHaveURL(new RegExp(`${prefix}/orders/TMB-26-\\d{5}$`));
                await auditScreen(page, testInfo, `${locale}-${width}-confirmation`);
                const number = page.url().split('/').pop() ?? '';

                await page.goto(at(prefix, '/track'));
                await page.locator('#track-number').fill(number);
                await page.locator('#track-phone').fill('5678');
                await page.getByRole('button', { name: t.track.submit }).click();
                await expect(page.getByText(t.order.number.replace('{number}', number))).toBeVisible();
                await auditScreen(page, testInfo, `${locale}-${width}-track-result`);
            });
        }
    });
}

for (const [width, viewport] of WIDTHS) {
    test(`${width}px: the Bangla header and nav do not wrap to an extra row`, async ({ page }) => {
        // Spec §A4.5: the category bar never wraps to two rows and never shrinks the font.
        await page.setViewportSize(viewport);
        const heights = async (path: string) => {
            await page.goto(path);
            return page.evaluate(() => Array.from(document.querySelectorAll('header, header nav')).map((el) => Math.round(el.getBoundingClientRect().height)));
        };
        const latin = await heights('/');
        const bangla = await heights('/bn');
        expect(bangla.length).toBe(latin.length);
        bangla.forEach((h, i) => expect(Math.abs(h - latin[i]!), `header block ${i}: bn ${h}px vs en ${latin[i]}px`).toBeLessThanOrEqual(2));
    });
}

test('the audit itself catches an overflowing label, a clipped one and a page scroll', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.setContent(`<body style="margin:0">
        <button style="width:80px;height:40px;white-space:nowrap">কার্টে যোগ করুন এখনই</button>
        <div style="width:100px;overflow:hidden"><span style="display:inline-block;white-space:nowrap">অর্ডার সারসংক্ষেপ দেখুন</span></div>
        <div style="width:120px;height:20px;overflow:hidden;line-height:20px">ডেলিভারি চার্জ এবং সময় দুই লাইনে</div>
        <p style="width:600px">wide</p>
        <p style="width:60px;white-space:nowrap;text-overflow:ellipsis;overflow:hidden">deliberately truncated text</p>
        <span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">screen reader only text</span>
    </body>`);
    const kinds = (await layoutProblems(page)).map((p) => `${p.kind}:${p.element.slice(0, 9)}`);
    expect(kinds).toEqual(['page-scroll:html', 'overflow-x:<button> ', 'clipped:<span> "অ', 'overflow-y:<div> "ডে']);
});

test.describe('rule 38: Bangla line-height is ~1.15× the Latin one', () => {
    test.use({ viewport: { width: 1440, height: 1000 } });

    test('the same text styles measure taller in Bangla', async ({ page }) => {
        const ratio = async (path: string) => {
            await page.goto(path);
            return page.evaluate(() => {
                const lh = (el: Element | null) => {
                    if (!el) return NaN;
                    const cs = getComputedStyle(el);
                    return parseFloat(cs.lineHeight) / parseFloat(cs.fontSize);
                };
                return { h1: lh(document.querySelector('main h1')), h2: lh(document.querySelector('main h2')) };
            });
        };
        const latin = await ratio('/');
        const bangla = await ratio('/bn');
        await expect(page.locator('body')).toHaveClass(/lang-bn/);
        for (const style of ['h1', 'h2'] as const) {
            const factor = bangla[style] / latin[style];
            expect(factor, `${style}: bn ${bangla[style]} vs en ${latin[style]}`).toBeGreaterThanOrEqual(1.1);
            expect(factor, `${style}: bn ${bangla[style]} vs en ${latin[style]}`).toBeLessThanOrEqual(1.25);
        }
    });
});

async function fillCheckout(page: Page, locale: 'en' | 'bn') {
    const bangla = locale === 'bn';
    await page.locator('#name').fill(bangla ? 'সাবরিনা আক্তার চৌধুরী' : 'Sabrina Akter Chowdhury');
    await page.locator('#phone').fill('01712-345678');
    await page.locator('#district_id').selectOption('13');
    await page.locator('button[value="refresh"]').click();
    await page.locator('#upazila_id').selectOption('101');
    await page.locator('#area').fill(bangla ? 'ধানমন্ডি' : 'Dhanmondi');
    await page.locator('#address_line').fill(bangla ? 'বাসা ১২, রোড ৫, ব্লক সি, দক্ষিণ পাশের গেট' : 'House 12, Road 5, Block C, south gate');
    await page.locator('#landmark').fill(bangla ? 'আগোরার পাশে, ৩য় তলা' : 'beside Agora, 3rd floor');
    await page.locator('button[value="refresh"]').click();
}
