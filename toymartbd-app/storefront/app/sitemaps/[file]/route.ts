import { fetchText } from '@/lib/seo/files';
import { SITEMAP_FILE } from '@/lib/seo/robots';

// One locale-partitioned sitemap file (`sitemap-products-bn-1.xml`). Only allowlisted names reach the API.

// Rendered per request (the API answer is tag-cached in the data cache), never baked at build time.
export const dynamic = 'force-dynamic';

export async function GET(_request: Request, { params }: { params: Promise<{ file: string }> }): Promise<Response> {
    const { file } = await params;
    if (!SITEMAP_FILE.test(file)) return new Response('Not found.', { status: 404, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
    const xml = await fetchText(`storefront/sitemaps/${file}`, ['sitemaps']);
    if (xml === null) return new Response('Not found.', { status: 404, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
    return new Response(xml, { headers: { 'Content-Type': 'application/xml; charset=utf-8', 'Cache-Control': 'public, max-age=3600, s-maxage=3600' } });
}
