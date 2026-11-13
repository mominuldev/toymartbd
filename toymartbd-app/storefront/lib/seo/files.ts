import 'server-only';

import { apiUrl, DEFAULT_REVALIDATE_SECONDS } from '../api';

// Text bodies (sitemap XML, robots.txt) from the API, tag-cached like every catalog read.
// Returns null on 404 or any failure; the route handlers decide the fallback.

export async function fetchText(path: string, tags: string[]): Promise<string | null> {
    const token = process.env.STOREFRONT_API_TOKEN;
    if (!process.env.API_URL || !token) return null;
    try {
        const response = await fetch(apiUrl(path), {
            headers: { Accept: 'application/xml, text/plain, application/json', Authorization: `Bearer ${token}` },
            next: { tags, revalidate: DEFAULT_REVALIDATE_SECONDS },
        });
        if (!response.ok) return null;
        const text = await response.text();
        // A JSON envelope ({"content": "..."}) is accepted too.
        if ((response.headers.get('content-type') ?? '').includes('json')) {
            const body = JSON.parse(text) as { content?: unknown; data?: { content?: unknown } };
            const content = body.content ?? body.data?.content;
            return typeof content === 'string' ? content : null;
        }
        return text;
    } catch {
        return null;
    }
}
