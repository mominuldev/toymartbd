import type { Schema } from '@toymart/api-types';

import { ApiError, send } from '@/lib/api';
import { consentPayload } from '@/lib/tracking/consent';
import { MAX_BODY_BYTES, requestContext, validateBrowserEvent, type ApiEventBody } from '@/lib/tracking/events';
import { getTrackingConfig } from '@/lib/tracking/fetch';

// POST /api/events — the server-side twin of a browser tracking event (K-01/K-02).
// (Not /api/track: that is public order tracking.)
//
// The browser sends {event_id, event_name, event_source_url, value?, currency?, items?} with the
// SAME event_id it gave gtag / fbq. This handler, server-side only (rule 28 — the storefront bearer
// token never reaches the browser):
//   1. refuses cross-site posts (Origin / Sec-Fetch-Site) and bodies over 16 KB;
//   2. validates the body; an unknown event name (incl. `purchase`, which is server-originated) is 422;
//   3. reads consent from the `tm_consent` cookie — not from the body — and does nothing (204) when
//      neither analytics nor ads is granted, or tracking is off;
//   4. adds the client block (IP, user agent, GA client/session id, _fbp, _fbc — fbc built from an
//      fbclid in the page URL when there is no _fbc cookie) and forwards to
//      POST /api/v1/storefront/tracking/events, answering 202 {event_id}.
// The API is idempotent on (event_id, event_name) and rate-limits per shopper IP (X-Forwarded-For).

export const dynamic = 'force-dynamic';

const PROBLEM = 'https://toymartbd.com/problems/';

function problem(status: number, type: string, title: string, extra: Record<string, unknown> = {}): Response {
    return Response.json({ type: `${PROBLEM}${type}`, title, status, ...extra }, { status, headers: { 'Content-Type': 'application/problem+json', 'Cache-Control': 'no-store' } });
}

/** The origin the request reached, plus SITE_URL's (behind a proxy the two can differ in scheme/host). */
function siteOrigins(request: Request): string[] {
    const origins = [new URL(request.url).origin];
    try {
        if (process.env.SITE_URL) origins.push(new URL(process.env.SITE_URL).origin);
    } catch {
        // A malformed SITE_URL adds nothing.
    }
    return origins;
}

function sameSite(request: Request, origins: readonly string[]): boolean {
    const site = request.headers.get('sec-fetch-site');
    if (site && site !== 'same-origin') return false;
    const sent = request.headers.get('origin');
    return sent === null || origins.includes(sent);
}

export async function POST(request: Request): Promise<Response> {
    const origins = siteOrigins(request);
    if (!sameSite(request, origins)) return problem(403, 'forbidden', 'Forbidden');
    if (!(request.headers.get('content-type') ?? '').includes('application/json')) return problem(415, 'unsupported-media-type', 'Unsupported Media Type');

    const raw = await request.text();
    if (Buffer.byteLength(raw, 'utf8') > MAX_BODY_BYTES) return problem(413, 'payload-too-large', 'Payload Too Large');
    let json: unknown;
    try {
        json = JSON.parse(raw);
    } catch {
        return problem(400, 'bad-request', 'Bad Request', { detail: 'Body is not valid JSON.' });
    }

    const result = validateBrowserEvent(json, origins);
    if (!result.ok) return problem(422, 'validation-error', 'Unprocessable Content', { errors: result.errors });
    const event = result.value;

    const config = await getTrackingConfig();
    if (!config.enabled.ga4 && !config.enabled.meta) return new Response(null, { status: 204, headers: { 'Cache-Control': 'no-store' } });

    const nowMs = Date.now();
    const { consent, client } = requestContext({ headers: request.headers, config, eventSourceUrl: event.event_source_url, nowMs });
    // Opt-in and not (yet) granted: nothing leaves this server.
    if (!consent.analytics && !consent.ads) return new Response(null, { status: 204, headers: { 'Cache-Control': 'no-store' } });

    const body = { ...event, occurred_at: new Date(nowMs).toISOString(), client, consent: consentPayload(consent) } satisfies ApiEventBody & Schema<'StoreTrackingEventRequest'>;
    try {
        await send('POST', 'storefront/tracking/events', { body });
        return Response.json({ event_id: event.event_id }, { status: 202, headers: { 'Cache-Control': 'no-store' } });
    } catch (error) {
        if (error instanceof ApiError && error.status === 429) return problem(429, 'too-many-requests', 'Too Many Requests');
        if (error instanceof ApiError && error.status === 422) return problem(422, 'validation-error', 'Unprocessable Content', { errors: error.problem.errors ?? [] });
        return problem(502, 'bad-gateway', 'Bad Gateway');
    }
}
