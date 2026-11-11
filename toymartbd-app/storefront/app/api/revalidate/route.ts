import { revalidateTag } from 'next/cache';

import { verifyRevalidateRequest } from '@/lib/revalidate';

// POST /api/revalidate — receiver for docs/api/revalidation.md. Laravel signs
// "<X-Revalidate-Timestamp>.<raw body>" with STOREFRONT_REVALIDATE_SECRET (HMAC-SHA256).
// Nothing is parsed or revalidated until the signature and timestamp pass.

export const dynamic = 'force-dynamic';

// Same stable problem types as the Laravel API (app/Http/Problems/ProblemType.php).
const PROBLEM_TYPE: Record<400 | 401 | 503, string> = {
    400: 'https://toymartbd.com/problems/bad-request',
    401: 'https://toymartbd.com/problems/unauthenticated',
    503: 'https://toymartbd.com/problems/service-unavailable',
};

function problem(status: 400 | 401 | 503, title: string, detail: string): Response {
    return Response.json(
        { type: PROBLEM_TYPE[status], title, status, detail },
        { status, headers: { 'Content-Type': 'application/problem+json', 'Cache-Control': 'no-store' } },
    );
}

export async function POST(request: Request): Promise<Response> {
    // Raw bytes, exactly as sent — the signature covers them, not a re-serialisation.
    const rawBody = Buffer.from(await request.arrayBuffer());

    const result = verifyRevalidateRequest({
        rawBody,
        timestamp: request.headers.get('x-revalidate-timestamp'),
        signature: request.headers.get('x-revalidate-signature'),
        secret: process.env.STOREFRONT_REVALIDATE_SECRET,
        nowSeconds: Math.floor(Date.now() / 1000),
    });

    if (!result.ok) return problem(result.status, result.title, result.detail);

    for (const tag of result.tags) {
        // Webhook from outside a Server Action: expire immediately, so the next visit renders
        // fresh data instead of one more stale page (a changed price must not linger).
        revalidateTag(tag, { expire: 0 });
    }

    return Response.json({ revalidated: result.tags }, { headers: { 'Cache-Control': 'no-store' } });
}
