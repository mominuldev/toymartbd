import { fetchText } from '@/lib/seo/files';
import { defaultRobots, withSitemapLine } from '@/lib/seo/robots';
import { siteUrl } from '@/lib/seo/site';

// robots.txt from the admin editor (GET /storefront/robots, tag `robots`), always ending with our
// sitemap line. If the API cannot answer, a safe default is served — never a 5xx, which Google
// reads as "crawl nothing" for a while.

// Rendered per request (the API answer is tag-cached in the data cache), never baked at build time.
export const dynamic = 'force-dynamic';

export async function GET(): Promise<Response> {
    const site = siteUrl();
    const content = await fetchText('storefront/robots', ['robots']);
    const body = content === null || content.trim() === '' ? defaultRobots(site) : withSitemapLine(content, site);
    return new Response(body, { headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'public, max-age=3600, s-maxage=3600' } });
}
