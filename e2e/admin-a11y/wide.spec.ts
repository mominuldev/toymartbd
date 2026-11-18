import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

// Wide-screen regression guard (owner decision 2026-11-16, docs/_design/04-admin-spec.md rule 4b:
// "remove the right white space on screens bigger than 1440px, don't make this mistake again").
// At 1920×1080, every screen the admin registers must reach the right edge of <main>'s content
// box: a page column, card stack or form capped with max-w-* leaves an empty band on the right and
// fails here. The route list is read from resources/js/admin/app/routes.tsx, so a new screen is
// covered the day it is registered — a new `:param` needs a representative demo id below.

const ROUTES_FILE = fileURLToPath(new URL('../../resources/js/admin/app/routes.tsx', import.meta.url));

/** Allowed shortfall between the content's right edge and main's content box, in CSS px. */
const TOLERANCE = 8;

/**
 * Screens that legitimately centre one narrow card instead of filling the width. Each needs a reason.
 * Paths are as registered (with `:params`), without the /admin prefix.
 */
const ALLOWLIST: Record<string, string> = {
    // Two-factor enrolment/challenge: outside the Shell, a centred sign-in card (Figma sign-in frame B · 00).
    '/account/two-factor': 'centred sign-in card, outside the shell',
    // POS register: the demo data has no open drawer, so the register shows its single centred
    // "Open a drawer" card (Figma B · 33). The open register itself is full-bleed (product grid + cart rail).
    '/pos': 'centred open-a-drawer card while no drawer is open',
};

/** A demo-data id (DemoDataSeeder) for every route parameter; one representative detail screen per resource. */
const PARAM_IDS: Record<string, string> = {
    productId: '1',
    categoryId: '1',
    brandId: '1',
    attributeId: '1',
    supplierId: '1',
    // PO-2026-0008: partly received, so the detail shows lines, receipts and payments.
    purchaseId: '8',
    customerId: '1',
    orderId: '1',
    returnId: '1',
    id: '1', // finance/settlements/:id
    couponId: '1',
    promotionId: '1',
    pageId: '1',
    bannerId: '1',
    testimonialId: '1',
    postId: '1',
    templateId: '1',
    conversationId: '1',
    location: 'header', // store/navigation/:location
};

/** Per-route overrides where the generic id would land on the wrong kind of record. */
const ROUTE_IDS: Record<string, Record<string, string>> = {
    // Promotion 7 is the demo flash sale; 1 is an ordinary discount.
    '/marketing/flash-sales/:promotionId': { promotionId: '7' },
};

type Route = { pattern: string; url: string };

/**
 * Every concrete screen routes.tsx registers, by its line layout: `{ path: 'x', element: … }` and
 * `{ index: true, … }` lines are screens; a bare `path: 'x',` line opens a parent whose children
 * are indented deeper. Redirects (`<Navigate`), splats (`*`) and the generated ComingSoon
 * sections are skipped.
 */
function registeredRoutes(): Route[] {
    const stack: Array<{ indent: number; prefix: string }> = [];
    const join = (prefix: string, path: string) => (path.startsWith('/') ? path : `${prefix.replace(/\/$/, '')}/${path}`);
    const patterns = new Set<string>();

    for (const line of readFileSync(ROUTES_FILE, 'utf8').split('\n')) {
        if (/^\s*\/\//.test(line)) continue;
        const indent = line.search(/\S/);
        const object = line.match(/^\s*\{\s*path:\s*'([^']+)'/);
        const parent = line.match(/^\s*path:\s*'([^']+)',\s*$/);
        const index = /^\s*\{\s*index:\s*true\b/.test(line);
        if (!object && !parent && !index) continue;

        while (stack.length > 0 && stack[stack.length - 1]!.indent >= indent) stack.pop();
        const prefix = stack[stack.length - 1]?.prefix ?? '/';

        if (parent) {
            stack.push({ indent, prefix: join(prefix, parent[1]!) });
            continue;
        }
        if (/children\s*:/.test(line)) throw new Error(`wide.spec: cannot read a one-line route with children: ${line.trim()}`);
        if (/<Navigate\b/.test(line)) continue;
        const path = index ? prefix : join(prefix, object![1]!);
        if (path.includes('*') || path === '/login') continue;
        patterns.add(path);
    }

    return [...patterns].map((pattern) => {
        const url = pattern.replace(/:(\w+)/g, (_, name: string) => {
            const value = ROUTE_IDS[pattern]?.[name] ?? PARAM_IDS[name];
            if (value === undefined) throw new Error(`wide.spec: no demo id for :${name} in ${pattern} — add one to PARAM_IDS`);
            return value;
        });
        return { pattern, url: `/admin${url === '/' ? '' : url}` };
    });
}

async function signIn(page: Page) {
    await page.addInitScript(() => {
        localStorage.setItem('tm-admin-locale', 'en');
        localStorage.setItem('tm-admin-theme', 'admin-light');
    });
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill('mominulfed@gmail.com');
    await page.locator('input[type="password"]').fill('password');
    await page.locator('button[type="submit"]').click();
    await expect(page).not.toHaveURL(/\/login/);
}

/** Requests in flight per page: client-side navigation never fires `networkidle` again, so count them. */
const inFlight = new WeakMap<Page, Set<unknown>>();

function trackRequests(page: Page) {
    const pending = new Set<unknown>();
    inFlight.set(page, pending);
    page.on('request', (request) => pending.add(request));
    page.on('requestfinished', (request) => pending.delete(request));
    page.on('requestfailed', (request) => pending.delete(request));
}

async function settle(page: Page) {
    await page.waitForLoadState('load');
    // Quiet for 300ms in a row (a query that resolves can start the next one), at most 15s.
    const pending = inFlight.get(page)!;
    const deadline = Date.now() + 15_000;
    let quietSince = Date.now();
    while (Date.now() < deadline) {
        if (pending.size > 0) quietSince = Date.now();
        else if (Date.now() - quietSince >= 300) break;
        await page.waitForTimeout(50);
    }
    await page.waitForFunction(() => document.querySelectorAll('[aria-busy="true"]').length === 0, null, { timeout: 15_000 }).catch(() => undefined);
    await page.evaluate(() => document.fonts.ready);
}

/**
 * Client-side navigation: one page load for the whole sweep (a full reload per screen made the
 * ~100-screen sweep take minutes). Screens outside the Shell and the first visit load normally.
 */
async function visit(page: Page, url: string) {
    const inApp = await page.evaluate(() => Boolean(document.querySelector('main#content')));
    if (inApp) {
        await page.evaluate((to) => {
            window.history.pushState({ usr: null, key: Math.random().toString(36).slice(2), idx: window.history.length }, '', to);
            window.dispatchEvent(new PopStateEvent('popstate', { state: window.history.state }));
        }, url);
        await page.waitForURL((u) => u.pathname === url);
    } else {
        await page.goto(url);
    }
    await settle(page);
}

type Measure = { ok: true; gap: number; edge: number; contentRight: number; widest: string } | { ok: false; reason: string };

/**
 * How far the page's content stops short of main's content box. "Content" is every visible box
 * inside <main> that draws an edge — a background or a right border (cards, panels, tables, inputs,
 * images, charts) — skipping the page header row (its actions always sit at the right), sticky
 * save bars and fixed/absolute layers (dialogs, popovers, toasts), which span the width regardless.
 */
function measure(page: Page): Promise<Measure> {
    return page.evaluate(() => {
        const main = document.querySelector<HTMLElement>('main#content') ?? document.querySelector<HTMLElement>('main');
        if (!main) return { ok: false as const, reason: 'no <main> element' };
        const mainStyle = getComputedStyle(main);
        const edge = main.getBoundingClientRect().right - parseFloat(mainStyle.borderRightWidth) - parseFloat(mainStyle.paddingRight);
        const skip = 'dialog, [role="dialog"], [role="alertdialog"], [role="tooltip"], [role="menu"], [role="listbox"], [popover], [data-page-header]';
        const transparent = (colour: string) => colour === 'transparent' || /rgba\([^)]*,\s*0\)$/.test(colour);
        let contentRight = Number.NEGATIVE_INFINITY;
        let widest = '';

        const describe = (el: Element) => {
            const cls = typeof el.className === 'string' ? el.className.trim().split(/\s+/).slice(0, 6).join('.') : '';
            return `${el.tagName.toLowerCase()}${cls ? `.${cls}` : ''}`;
        };

        const walk = (el: Element) => {
            for (const child of Array.from(el.children)) {
                if (child.matches(skip)) continue;
                const style = getComputedStyle(child);
                if (style.display === 'none' || style.visibility === 'hidden') continue;
                // Fixed/absolute layers and bottom-pinned sticky save bars span the width regardless; a
                // top-pinned sticky side rail (a summary column) is page content and is measured.
                if (style.position === 'fixed' || style.position === 'absolute' || (style.position === 'sticky' && style.bottom !== 'auto')) continue;
                const rect = child.getBoundingClientRect();
                const drawn =
                    !transparent(style.backgroundColor) ||
                    style.backgroundImage !== 'none' ||
                    (parseFloat(style.borderRightWidth) > 0 && style.borderRightStyle !== 'none' && !transparent(style.borderRightColor)) ||
                    ['TABLE', 'IMG', 'CANVAS', 'VIDEO', 'IFRAME'].includes(child.tagName) ||
                    (child.tagName.toLowerCase() === 'svg' && rect.width > 64);
                if (drawn && rect.width > 1 && rect.height > 1 && rect.right > contentRight) {
                    contentRight = rect.right;
                    widest = describe(child);
                }
                walk(child);
            }
        };
        walk(main);

        if (!Number.isFinite(contentRight)) return { ok: false as const, reason: 'no content boxes inside <main>' };
        return { ok: true as const, gap: Math.round(edge - contentRight), edge: Math.round(edge), contentRight: Math.round(contentRight), widest };
    });
}

test.describe('admin wide screens', () => {
    test.use({ viewport: { width: 1920, height: 1080 } });

    test('every registered screen fills the content width at 1920', async ({ page }, testInfo) => {
        const routes = registeredRoutes();
        test.setTimeout(routes.length * 20_000);
        // routes.tsx registers ~100 screens; a tiny list means the parser broke, not that the admin shrank.
        expect(routes.length, 'routes parsed from routes.tsx').toBeGreaterThan(60);

        trackRequests(page);
        await signIn(page);
        const offenders: string[] = [];
        const report: string[] = [];

        for (const route of routes) {
            if (ALLOWLIST[route.pattern]) {
                report.push(`skip  ${route.url} — ${ALLOWLIST[route.pattern]}`);
                continue;
            }
            await visit(page, route.url);
            const result = await measure(page);
            if (!result.ok) {
                offenders.push(`${route.url} (${route.pattern}): ${result.reason}`);
                report.push(`FAIL  ${route.url} — ${result.reason}`);
            } else if (result.gap > TOLERANCE) {
                offenders.push(`${route.url} (${route.pattern}): content ends at ${result.contentRight}px, main's content box at ${result.edge}px — ${result.gap}px empty band (widest: ${result.widest})`);
                report.push(`FAIL  ${route.url} gap ${result.gap}px`);
            } else {
                report.push(`ok    ${route.url} gap ${result.gap}px`);
            }
        }

        await testInfo.attach('wide-routes.txt', { body: report.join('\n'), contentType: 'text/plain' });
        expect(offenders, `${offenders.length} screen(s) leave an empty band on the right at 1920 (spec rule 4b):\n  ${offenders.join('\n  ')}\n`).toEqual([]);
    });
});
