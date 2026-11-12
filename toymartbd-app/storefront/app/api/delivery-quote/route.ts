import { ApiError } from '@/lib/api';
import { getDeliveryQuote, parseQuoteRequest } from '@/lib/delivery/fetch';
import { isLocale } from '@/lib/i18n';

// GET /api/delivery-quote?district_id=&upazila_id=&variant_id=&quantity=&locale= — the browser's
// way to the delivery quote (P4-STO-06). The Laravel API needs the server-side storefront token,
// so the DeliveryEstimator's enhancement calls this Route Handler instead (rule 28); the figure is
// the API's DeliveryChargeResolver output, passed through untouched (rule 1).

export const dynamic = 'force-dynamic';

const HEADERS = { 'Cache-Control': 'no-store' };

function problem(status: number, title: string): Response {
    return Response.json(
        { type: status === 400 ? 'https://toymartbd.com/problems/bad-request' : 'about:blank', title, status },
        { status, headers: { ...HEADERS, 'Content-Type': 'application/problem+json' } },
    );
}

export async function GET(request: Request): Promise<Response> {
    const params = new URL(request.url).searchParams;
    const quote = parseQuoteRequest((key) => params.get(key));
    if (quote === null) return problem(400, 'district_id must be a district id.');

    const locale = params.get('locale') ?? '';
    try {
        const body = await getDeliveryQuote(quote, isLocale(locale) ? locale : 'en');
        return Response.json(body, { headers: HEADERS });
    } catch (error) {
        if (error instanceof ApiError && error.status < 500) return problem(error.status, error.problem.title);
        return problem(502, 'The delivery quote is unavailable.');
    }
}
