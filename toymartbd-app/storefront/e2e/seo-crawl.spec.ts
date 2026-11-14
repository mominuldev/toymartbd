import { readFileSync } from 'node:fs';

import { expect, test, type APIRequestContext } from '@playwright/test';

import { CRAWLER_UA, MOCK_API_URL, STOREFRONT_URL } from './constants';

// Phase 9 SEO audit (docs/audits/SEO-AND-COVERAGE.md): a crawler's view of the site, as a gate.
// Starting from both home pages, follow every internal link two levels deep (the chrome links
// every page, so this reaches every template) and assert per page what Search Console reports
// per URL: a status that is not an error, one locale-correct absolute canonical, reciprocal
// hreflang en/bn + x-default, a title that carries the site name once, and <html lang>. Then
// every sitemap URL must be a 200, indexable, self-canonical page.
//
// A link to `/pages/{slug}` may 404 only when the slug is one database/seeders/StaticPagesSeeder.php
// creates — that page exists at launch once the owner publishes it; the mock API serves two.

test.use({ userAgent: CRAWLER_UA, javaScriptEnabled: false });

const SEEDED_CMS = [...readFileSync(new URL('../../../database/seeders/StaticPagesSeeder.php', import.meta.url), 'utf8').matchAll(/'([a-z0-9-]+)' => \['/g)].map((m) => m[1]!);
const MAX_PAGES = 90;

interface Page {
    path: string;
    status: number;
    html: string;
}

// Attribute names are case-insensitive in HTML (React renders `hrefLang`).
const attr = (tag: string, name: string): string | null => new RegExp(`\\s${name}="([^"]*)"`, 'i').exec(tag)?.[1]?.replace(/&amp;/g, '&') ?? null;
const headOf = (html: string): string => html.slice(0, Math.max(0, html.indexOf('</head>')));
const linkTags = (head: string, rel: string): string[] => [...head.matchAll(/<link [^>]*>/g)].map((m) => m[0]).filter((t) => attr(t, 'rel') === rel);
const localeOf = (path: string): 'en' | 'bn' => (path === '/bn' || path.startsWith('/bn/') || path.startsWith('/bn?') ? 'bn' : 'en');

function internalLinks(html: string): string[] {
    const out: string[] = [];
    for (const m of html.matchAll(/<a [^>]*href="([^"#]+)"/g)) {
        const href = m[1]!.replace(/&amp;/g, '&');
        if (!href.startsWith('/') || href.startsWith('//')) continue;
        const url = new URL(href, STOREFRONT_URL);
        if (/^\/(api|_next)\//.test(url.pathname) || /\.[a-z0-9]+$/i.test(url.pathname)) continue;
        out.push(url.pathname + url.search);
    }
    return out;
}

async function crawl(request: APIRequestContext): Promise<Page[]> {
    const seen = new Set<string>(['/', '/bn']);
    let frontier = ['/', '/bn'];
    const pages: Page[] = [];
    for (let depth = 0; depth <= 2 && frontier.length > 0 && pages.length < MAX_PAGES; depth++) {
        const next: string[] = [];
        for (const path of frontier) {
            if (pages.length >= MAX_PAGES) break;
            const res = await request.get(path, { maxRedirects: 0 });
            const html = res.headers()['content-type']?.includes('text/html') ? await res.text() : '';
            pages.push({ path, status: res.status(), html });
            if (res.status() !== 200) continue;
            // One query variant per path is enough: facets share the template.
            for (const link of internalLinks(html)) {
                const key = link.includes('?') && [...seen].some((s) => s.split('?')[0] === link.split('?')[0]) ? null : link;
                if (key && !seen.has(key)) {
                    seen.add(key);
                    next.push(key);
                }
            }
        }
        frontier = next;
    }
    return pages;
}

test.beforeAll(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

test('every crawlable page answers without an error and carries canonical, hreflang and one site-name title', async ({ request }) => {
    test.setTimeout(240_000);
    const pages = await crawl(request);
    expect(pages.length).toBeGreaterThan(30);
    const problems: string[] = [];

    for (const { path, status, html } of pages) {
        const pathname = path.split('?')[0]!;
        if (status === 404) {
            const cms = /^(?:\/bn)?\/pages\/([a-z0-9-]+)$/.exec(pathname);
            if (!cms || !SEEDED_CMS.includes(cms[1]!)) problems.push(`${path}: 404 — a link to a page that does not exist`);
            continue;
        }
        if (status >= 300 && status < 400) continue; // account pages redirect to sign-in
        if (status !== 200) {
            problems.push(`${path}: ${status}`);
            continue;
        }

        const head = headOf(html);
        const locale = localeOf(path);
        const canonicals = linkTags(head, 'canonical').map((t) => attr(t, 'href'));
        const noindex = /<meta name="robots" content="noindex/.test(head);
        if (!noindex || canonicals.length > 0) {
            if (canonicals.length !== 1) problems.push(`${path}: ${canonicals.length} canonicals`);
            const canonical = canonicals[0] ?? '';
            if (!canonical.startsWith(STOREFRONT_URL)) problems.push(`${path}: canonical not absolute on this site (${canonical})`);
            else if (localeOf(new URL(canonical).pathname) !== locale) problems.push(`${path}: canonical in the other locale (${canonical})`);
        }
        const alternates = Object.fromEntries(linkTags(head, 'alternate').filter((t) => attr(t, 'hreflang')).map((t) => [attr(t, 'hreflang'), attr(t, 'href')]));
        if (!noindex) {
            for (const lang of ['en', 'bn', 'x-default']) if (!alternates[lang]) problems.push(`${path}: no hreflang ${lang}`);
            if (alternates.en && alternates['x-default'] !== alternates.en) problems.push(`${path}: x-default is not the English URL`);
            if (alternates[locale] && canonicals[0] && alternates[locale] !== canonicals[0] && !path.includes('?')) problems.push(`${path}: hreflang ${locale} (${alternates[locale]}) is not the canonical`);
        }
        const title = /<title>([^<]*)<\/title>/.exec(head)?.[1] ?? '';
        const site = locale === 'bn' ? 'টয় মার্ট বিডি' : 'Toy Mart BD';
        if (title === '') problems.push(`${path}: no <title>`);
        // The layout template appends " | {site}"; a title that already ended with it gets it twice.
        if (title.split(` | ${site}`).length > 2 || title.split(' | Toy Mart BD').length > 2) problems.push(`${path}: site name appended twice in "${title}"`);
        if (attr(/<html[^>]*>/.exec(html)?.[0] ?? '', 'lang') !== locale) problems.push(`${path}: <html lang> is not ${locale}`);
    }

    expect(problems, problems.join('\n')).toEqual([]);
});

test('every sitemap URL is a 200, indexable, self-canonical page', async ({ request }) => {
    const index = await (await request.get('/sitemap.xml')).text();
    const files = [...index.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => new URL(m[1]!).pathname);
    expect(files.length).toBeGreaterThan(0);
    const problems: string[] = [];
    for (const file of files) {
        const xml = await (await request.get(file)).text();
        for (const loc of [...xml.matchAll(/<url><loc>([^<]+)<\/loc>/g)].map((m) => m[1]!)) {
            const res = await request.get(new URL(loc).pathname, { maxRedirects: 0 });
            if (res.status() !== 200) {
                problems.push(`${loc}: ${res.status()}`);
                continue;
            }
            const head = headOf(await res.text());
            if (/<meta name="robots" content="noindex/.test(head)) problems.push(`${loc}: noindex`);
            const canonical = linkTags(head, 'canonical').map((t) => attr(t, 'href'))[0];
            if (canonical !== loc) problems.push(`${loc}: canonical ${canonical}`);
        }
    }
    expect(problems, problems.join('\n')).toEqual([]);
});
