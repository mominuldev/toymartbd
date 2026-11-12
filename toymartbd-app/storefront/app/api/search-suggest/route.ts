import { ApiError, cached } from '@/lib/api';
import { isLocale } from '@/lib/i18n';
import { MIN_SUGGEST_QUERY, parseQuery } from '@/lib/search/query';
import { normalizeSuggestions } from '@/lib/search/response';

// GET /api/search-suggest?q=&locale= — the header search dropdown's way to
// GET /storefront/search/suggest (P4-STO-04, Figma SearchSuggestions 115:887). The Laravel API
// needs the server-side storefront token, so the browser calls this Route Handler (rule 28). Card
// prices pass through untouched (rule 1).
//
// Answers are cached for a minute per (query, locale) and dropped on any product write (tag
// `products`): every shopper reaches Laravel through this one server, and its `search-suggest`
// limit is per IP, so repeated prefixes must not each cost a request.

export const dynamic = 'force-dynamic';

const SUGGEST_TTL_SECONDS = 60;
const HEADERS = { 'Cache-Control': 'private, max-age=60' };

function problem(status: number, title: string): Response {
    return Response.json(
        { type: status === 400 ? 'https://toymartbd.com/problems/bad-request' : 'about:blank', title, status },
        { status, headers: { 'Cache-Control': 'no-store', 'Content-Type': 'application/problem+json' } },
    );
}

export async function GET(request: Request): Promise<Response> {
    const params = new URL(request.url).searchParams;
    const q = parseQuery(params.get('q') ?? undefined);
    if ([...q].length < MIN_SUGGEST_QUERY) return problem(400, `q must be at least ${MIN_SUGGEST_QUERY} characters.`);
    const raw = params.get('locale') ?? '';
    const locale = isLocale(raw) ? raw : 'en';
    try {
        const body = await cached<unknown>(`storefront/search/suggest?${new URLSearchParams({ q }).toString()}`, ['products'], { locale, revalidate: SUGGEST_TTL_SECONDS });
        return Response.json(normalizeSuggestions(body), { headers: HEADERS });
    } catch (error) {
        if (error instanceof ApiError && error.status === 429) return problem(429, 'Too many suggestion requests.');
        if (error instanceof ApiError && error.status < 500) return problem(error.status, error.problem.title);
        return problem(502, 'Search suggestions are unavailable.');
    }
}
