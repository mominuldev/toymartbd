// robots.txt and sitemap file names (docs/api/seo.md §5). Pure; the route handlers use these.

/** `sitemap-{type}-{en|bn}-{n}.xml` — the only files /sitemaps/[file] will proxy. */
export const SITEMAP_FILE = /^sitemap-[a-z]+-(en|bn)-\d{1,4}\.xml$/;

export function sitemapUrl(site: URL): string {
    return new URL('/sitemap.xml', site).toString();
}

/**
 * Served when the API cannot answer: crawl everything public, keep crawlers out of the private
 * and infinite URL spaces (cart, checkout, account, order pages, route handlers, search).
 */
export function defaultRobots(site: URL): string {
    return [
        'User-agent: *',
        'Allow: /',
        'Disallow: /cart',
        'Disallow: /bn/cart',
        'Disallow: /checkout',
        'Disallow: /bn/checkout',
        'Disallow: /account',
        'Disallow: /bn/account',
        'Disallow: /orders/',
        'Disallow: /bn/orders/',
        'Disallow: /api/',
        '',
        `Sitemap: ${sitemapUrl(site)}`,
        '',
    ].join('\n');
}

/** The editor's text, with our sitemap line guaranteed (added once if the owner removed it). */
export function withSitemapLine(content: string, site: URL): string {
    const line = `Sitemap: ${sitemapUrl(site)}`;
    const body = content.replace(/\r\n/g, '\n').trimEnd();
    const has = body.split('\n').some((l) => l.trim().toLowerCase() === line.toLowerCase());
    return `${has ? body : `${body}\n\n${line}`}\n`;
}
