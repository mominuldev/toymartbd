import { NextResponse } from 'next/server';

import { send } from '@/lib/api';
import { ORDER_NUMBER } from '@/lib/checkout/form';
import { isLocale, localizedPath, type Locale } from '@/lib/i18n';

// POST /api/payments/sslcommerz/{number}/{success|fail|cancel}?locale= — where SSLCommerz sends the
// customer's browser back (P4-PAY-03, docs/api/checkout.md "Online payment"). Its POST carries the
// same signed fields as the IPN. We trust NOTHING in it, nor the outcome in the path: the fields go
// to the API untouched (POST /storefront/payments/sslcommerz/return), which verifies the signature,
// re-queries SSLCommerz and applies the authoritative answer. Then the browser goes (303, a GET, so
// the Lax order cookie travels) to the payment page, which re-reads the order from the API.
//
// The order number in the path only routes the customer back; the page proves ownership with the
// placing cart's cookie, as the confirmation page does.

export const dynamic = 'force-dynamic';

interface Params {
    params: Promise<{ number: string; outcome: string }>;
}

function target(request: Request, number: string): string {
    const raw = new URL(request.url).searchParams.get('locale') ?? '';
    const locale: Locale = isLocale(raw) ? raw : 'en';
    return ORDER_NUMBER.test(number) ? localizedPath(locale, `/orders/${number}/payment`) : localizedPath(locale, '/track');
}

function seeOther(location: string): NextResponse {
    return new NextResponse(null, { status: 303, headers: { Location: location, 'Cache-Control': 'no-store' } });
}

export async function POST(request: Request, { params }: Params): Promise<Response> {
    const { number: raw } = await params;
    const number = decodeURIComponent(raw).toUpperCase();
    const form = await request.formData().catch(() => new FormData());

    const fields: Record<string, string> = {};
    let count = 0;
    for (const [key, value] of form.entries()) {
        if (typeof value !== 'string' || count++ >= 80) continue;
        fields[key.slice(0, 64)] = value.slice(0, 500);
    }

    // Whatever the API answers (applied, unchanged, a bad signature → 401), the page re-reads the
    // order: a forged or failed return can only ever show the customer the true state.
    await send('POST', 'storefront/payments/sslcommerz/return', { body: fields }).catch(() => null);

    return seeOther(target(request, number));
}

/** A GET (refresh, bookmark) carries nothing to verify: straight to the page. */
export async function GET(request: Request, { params }: Params): Promise<Response> {
    const { number: raw } = await params;
    return seeOther(target(request, decodeURIComponent(raw).toUpperCase()));
}
