import { expect, test, type Page } from '@playwright/test';

// Phase 10 batch 3 (docs/audits/ACCESSIBILITY-AND-BILINGUAL.md, "Phase 10 screens"): the Phase 10
// screens in Bangla at 768 and 1440 — no horizontal page scroll, no clipped text (an element that
// cuts its own text off without an ellipsis), the Bangla line-height twin in force (rule 38), and
// Western digits only (rule 36). SHOTS_DIR=<dir> also saves a screenshot of each for review.
const SCREENS: Array<[string, string]> = [
    ['staff-people', '/admin/staff'],
    ['staff-roles', '/admin/staff/roles'],
    ['staff-activity', '/admin/staff/activity'],
    ['two-factor', '/admin/account/two-factor'],
    ['integrations-hub', '/admin/integrations'],
    ['settings-business', '/admin/settings/business'],
    ['settings-branding', '/admin/settings/branding'],
    ['settings-branches', '/admin/settings/branches'],
    ['settings-checkout', '/admin/settings/checkout'],
    ['settings-invoice', '/admin/settings/invoice'],
];

const shots = process.env.SHOTS_DIR;

async function signIn(page: Page) {
    await page.addInitScript(() => localStorage.setItem('tm-admin-locale', 'bn'));
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill('owner@toymart.test');
    await page.locator('input[type="password"]').fill('password');
    await page.locator('button[type="submit"]').click();
    await expect(page).not.toHaveURL(/\/login/);
}

type Finding = { kind: string; where: string; text: string };

/** Clipped text, page overflow, a Latin line-height on Bangla text, and Bengali digits. */
async function inspect(page: Page): Promise<Finding[]> {
    return page.evaluate(() => {
        const out: { kind: string; where: string; text: string }[] = [];
        const describe = (el: Element) => `${el.tagName.toLowerCase()}${el.id ? `#${el.id}` : ''}.${[...el.classList].slice(0, 4).join('.')}`;
        if (document.documentElement.scrollWidth > window.innerWidth + 1) {
            out.push({ kind: 'page-overflow', where: 'html', text: `${document.documentElement.scrollWidth} > ${window.innerWidth}` });
        }
        const main = document.querySelector('main') ?? document.body;
        for (const el of main.querySelectorAll<HTMLElement>('*')) {
            if (!el.offsetParent && getComputedStyle(el).position !== 'fixed') continue;
            const style = getComputedStyle(el);
            const own = [...el.childNodes].some((n) => n.nodeType === Node.TEXT_NODE && (n.textContent ?? '').trim() !== '');
            if (!own) continue;
            const text = (el.textContent ?? '').trim().slice(0, 60);
            const clipsX = (style.overflowX === 'hidden' || style.overflowX === 'clip') && el.scrollWidth > el.clientWidth + 1 && style.textOverflow !== 'ellipsis';
            const clipsY = (style.overflowY === 'hidden' || style.overflowY === 'clip') && el.scrollHeight > el.clientHeight + 1;
            if ((clipsX || clipsY) && !el.closest('.sr-only')) out.push({ kind: 'clipped', where: describe(el), text });
            // Decorative single-line marks (Avatar initials, aria-hidden) are set solid on purpose.
            if (/[\u0980-\u09FF]/.test(text) && !el.closest('.sr-only, [aria-hidden="true"]')) {
                const lh = parseFloat(style.lineHeight);
                const fs = parseFloat(style.fontSize);
                if (Number.isFinite(lh) && lh / fs < 1.3) out.push({ kind: 'tight-bangla-line-height', where: describe(el), text: `${(lh / fs).toFixed(2)} · ${text}` });
            }
            if (/[০-৯]/.test(text)) out.push({ kind: 'bengali-digit', where: describe(el), text });
        }
        return out;
    });
}

for (const width of [768, 1440]) {
    test(`bn · ${width}px: Phase 10 screens do not clip or overflow`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await signIn(page);
        const all: Record<string, Finding[]> = {};
        for (const [label, path] of SCREENS) {
            await page.goto(path);
            await page.waitForLoadState('networkidle');
            await page.waitForFunction(() => document.querySelectorAll('[aria-busy="true"]').length === 0, null, { timeout: 15_000 }).catch(() => undefined);
            await page.evaluate(() => document.fonts.ready);
            if (shots) await page.screenshot({ path: `${shots}/bn-${width}-${label}.png`, fullPage: true });
            const found = await inspect(page);
            if (found.length) all[label] = found;
        }
        expect.soft(all, JSON.stringify(all, null, 2)).toEqual({});
    });
}
