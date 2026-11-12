import { expect, type Page, type TestInfo } from '@playwright/test';

// P4-I18N-02: the Bangla layout audit. Run in the page, it reports every rendered element that
// carries its own text and
//   • overflows its own box (scrollWidth > clientWidth, or scrollHeight > clientHeight when the
//     box has a fixed height — a Bangla label wrapping inside a fixed-height button), or
//   • is clipped by an `overflow: hidden | clip` ancestor (it sticks out past that ancestor's edge),
// plus a horizontal page scroll. Deliberate truncation is not a defect and is skipped: an element
// that sets `text-overflow: ellipsis` or `line-clamp` (product names clamp at 2 lines, Figma
// ProductCard), and anything inside a horizontal scroller (chip rows, the gallery track), whose
// content is reachable by scrolling. Visually-hidden text (sr-only, 1px boxes) is skipped too.

export interface LayoutProblem {
    kind: 'page-scroll' | 'overflow-x' | 'overflow-y' | 'clipped';
    element: string;
    detail: string;
}

export async function layoutProblems(page: Page): Promise<LayoutProblem[]> {
    return page.evaluate(() => {
        const problems: Array<{ kind: 'page-scroll' | 'overflow-x' | 'overflow-y' | 'clipped'; element: string; detail: string }> = [];
        const root = document.documentElement;
        if (root.scrollWidth > root.clientWidth + 1) {
            problems.push({ kind: 'page-scroll', element: 'html', detail: `scrollWidth ${root.scrollWidth} > ${root.clientWidth}` });
        }
        const name = (el: Element): string => {
            const id = el.id ? `#${el.id}` : '';
            const cls = typeof el.className === 'string' && el.className ? `.${el.className.trim().split(/\s+/).slice(0, 3).join('.')}` : '';
            const text = (el.textContent ?? '').replace(/\s+/g, ' ').trim().slice(0, 60);
            return `<${el.tagName.toLowerCase()}${id}${cls}> "${text}"`;
        };
        const truncates = (cs: CSSStyleDeclaration): boolean =>
            cs.textOverflow === 'ellipsis' || (cs.getPropertyValue('-webkit-line-clamp') || 'none') !== 'none' || (cs.getPropertyValue('line-clamp') || 'none') !== 'none';
        const scrolls = (v: string): boolean => v === 'auto' || v === 'scroll';

        for (const el of Array.from(document.body.querySelectorAll('*'))) {
            if (el.closest('script, style, template, noscript, svg, select, option')) continue;
            const own = Array.from(el.childNodes)
                .filter((n) => n.nodeType === Node.TEXT_NODE)
                .map((n) => n.textContent ?? '')
                .join('')
                .trim();
            if (own === '') continue;
            const cs = getComputedStyle(el);
            if (cs.visibility !== 'visible' || cs.display === 'contents') continue;
            const box = el.getBoundingClientRect();
            if (box.width <= 1 || box.height <= 1) continue; // not rendered, or visually hidden
            if (cs.clipPath !== 'none' || (cs.position === 'absolute' && cs.clip !== 'auto')) continue;

            // Inside a scroller or a deliberately truncating ancestor? Then the overflow is designed.
            let designed = truncates(cs);
            for (let a = el.parentElement; a && !designed; a = a.parentElement) {
                const acs = getComputedStyle(a);
                if (scrolls(acs.overflowX) || truncates(acs)) designed = true;
            }
            if (designed) continue;

            if (cs.display !== 'inline') {
                if (!scrolls(cs.overflowX) && el.scrollWidth > el.clientWidth + 1) {
                    problems.push({ kind: 'overflow-x', element: name(el), detail: `scrollWidth ${el.scrollWidth} > clientWidth ${el.clientWidth}` });
                }
                // Vertically, a glyph's ink may legitimately rise past a tight line box by a few
                // pixels (scrollHeight counts it; nothing is hidden while overflow is visible). A real
                // defect is a wrapped extra line in a fixed-height box, or any overflow that is hidden.
                const lineHeight = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.2;
                const hidesY = cs.overflowY === 'hidden' || cs.overflowY === 'clip';
                const allowance = hidesY ? 1 : Math.max(2, lineHeight * 0.4);
                if (!scrolls(cs.overflowY) && el.scrollHeight > el.clientHeight + allowance) {
                    problems.push({ kind: 'overflow-y', element: name(el), detail: `scrollHeight ${el.scrollHeight} > clientHeight ${el.clientHeight}` });
                }
            }

            // Clipped by an overflow:hidden/clip ancestor (an inline element is measured by its line box).
            for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
                const acs = getComputedStyle(a);
                const hidesX = acs.overflowX === 'hidden' || acs.overflowX === 'clip';
                const hidesY = acs.overflowY === 'hidden' || acs.overflowY === 'clip';
                if (!hidesX && !hidesY) continue;
                const r = a.getBoundingClientRect();
                if (r.width <= 1 || r.height <= 1) break; // a visually-hidden wrapper
                const outX = hidesX && (box.left < r.left - 1 || box.right > r.right + 1);
                const outY = hidesY && (box.top < r.top - 1 || box.bottom > r.bottom + 1);
                if (outX || outY) {
                    problems.push({
                        kind: 'clipped',
                        element: name(el),
                        detail: `box [${Math.round(box.left)},${Math.round(box.top)} ${Math.round(box.width)}×${Math.round(box.height)}] outside ${name(a).slice(0, 80)} [${Math.round(r.left)},${Math.round(r.top)} ${Math.round(r.width)}×${Math.round(r.height)}]`,
                    });
                }
                break;
            }
        }
        return problems;
    });
}

/** Screenshot the page into the test's output (attached to the report) and assert a clean layout. */
export async function auditScreen(page: Page, testInfo: TestInfo, label: string): Promise<void> {
    await page.evaluate(() => document.fonts.ready);
    const body = await page.screenshot({ fullPage: true, type: 'jpeg', quality: 60 });
    await testInfo.attach(label, { body, contentType: 'image/jpeg' });
    const problems = await layoutProblems(page);
    expect(problems, `${label}: ${problems.map((p) => `\n  ${p.kind} ${p.element} — ${p.detail}`).join('')}`).toEqual([]);
}
