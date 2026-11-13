import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, type TestInfo } from '@playwright/test';

// Phase 9 accessibility gate (UI-UX spec §7: WCAG 2.2 AA, axe-core via Playwright, "a new
// violation fails the build"). Every rule tagged WCAG 2.0/2.1/2.2 A or AA runs; a violation of any
// impact fails. Best-practice rules (region, landmark-unique, heading-order, …) run too and are
// attached to the report, but only the WCAG ones gate — they are advice, not conformance.

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'];

export interface AxeFinding {
    rule: string;
    impact: string;
    help: string;
    targets: string[];
}

async function run(page: Page, tags: string[]): Promise<AxeFinding[]> {
    const results = await new AxeBuilder({ page }).withTags(tags).analyze();
    return results.violations.map((v) => ({
        rule: v.id,
        impact: v.impact ?? 'unknown',
        help: v.help,
        targets: v.nodes.slice(0, 5).map((n) => n.target.join(' ')),
    }));
}

/** Run axe on the current page; attach every finding, fail on any WCAG A/AA violation. */
export async function expectAccessible(page: Page, testInfo: TestInfo, label: string): Promise<void> {
    await page.evaluate(() => document.fonts.ready);
    // Next streams metadata to a browser after the body; give the <title> a moment to land. A page
    // that never gets one still fails axe's document-title rule below.
    await page.waitForFunction(() => document.title.trim() !== '', null, { timeout: 5_000 }).catch(() => undefined);
    const wcag = await run(page, WCAG_TAGS);
    const advice = await run(page, ['best-practice']);
    if (wcag.length || advice.length) {
        await testInfo.attach(`axe-${label}.json`, { body: JSON.stringify({ wcag, advice }, null, 2), contentType: 'application/json' });
    }
    const lines = wcag.map((f) => `\n  [${f.impact}] ${f.rule} — ${f.help}\n      ${f.targets.join('\n      ')}`).join('');
    expect.soft(wcag, `${label}: ${wcag.length} WCAG violation(s)${lines}`).toEqual([]);
}
