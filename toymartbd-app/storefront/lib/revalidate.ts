import { createHmac, timingSafeEqual } from 'node:crypto';

// Receiver side of docs/api/revalidation.md. Pure so it can be unit-tested without Next.

export const MAX_SKEW_SECONDS = 300;
export const MAX_TAGS = 50;
export const TAG_PATTERN = /^[a-z0-9:_-]{1,100}$/;

export type VerifyResult =
    | { ok: true; tags: string[] }
    | { ok: false; status: 400 | 401 | 503; title: string; detail: string };

export interface VerifyInput {
    rawBody: Buffer;
    timestamp: string | null;
    signature: string | null;
    secret: string | undefined;
    nowSeconds: number;
}

export function sign(secret: string, timestamp: string, rawBody: Buffer | string): string {
    const mac = createHmac('sha256', secret);
    mac.update(`${timestamp}.`);
    mac.update(rawBody);
    return `sha256=${mac.digest('hex')}`;
}

function signatureMatches(expected: string, received: string): boolean {
    const a = Buffer.from(expected, 'utf8');
    const b = Buffer.from(received, 'utf8');
    // Lengths are not secret (always "sha256=" + 64 hex); timingSafeEqual needs equal lengths.
    if (a.length !== b.length) return false;
    return timingSafeEqual(a, b);
}

const unauthorized = (detail: string): VerifyResult => ({ ok: false, status: 401, title: 'Unauthorized', detail });
const badRequest = (detail: string): VerifyResult => ({ ok: false, status: 400, title: 'Bad Request', detail });

export function verifyRevalidateRequest(input: VerifyInput): VerifyResult {
    if (!input.secret) {
        return { ok: false, status: 503, title: 'Service Unavailable', detail: 'STOREFRONT_REVALIDATE_SECRET is not set.' };
    }

    // Rule 1: signature over "<timestamp>.<raw body>", before anything is parsed.
    if (!input.timestamp || !input.signature) return unauthorized('Missing signature headers.');
    const expected = sign(input.secret, input.timestamp, input.rawBody);
    if (!signatureMatches(expected, input.signature.trim().toLowerCase())) return unauthorized('Invalid signature.');

    // Rule 2: replay guard, ±300 s.
    if (!/^\d{1,12}$/.test(input.timestamp)) return unauthorized('Invalid timestamp.');
    if (Math.abs(input.nowSeconds - Number(input.timestamp)) > MAX_SKEW_SECONDS) {
        return unauthorized('Timestamp outside the allowed window.');
    }

    // Rule 3: {"tags": string[]}, 1–50 tags, each ^[a-z0-9:_-]{1,100}$.
    let body: unknown;
    try {
        body = JSON.parse(input.rawBody.toString('utf8'));
    } catch {
        return badRequest('Body is not valid JSON.');
    }
    if (typeof body !== 'object' || body === null || Array.isArray(body)) return badRequest('Body must be an object.');
    const tags = (body as { tags?: unknown }).tags;
    if (!Array.isArray(tags) || tags.length < 1 || tags.length > MAX_TAGS) {
        return badRequest(`"tags" must be an array of 1–${MAX_TAGS} strings.`);
    }
    for (const tag of tags) {
        if (typeof tag !== 'string' || !TAG_PATTERN.test(tag)) return badRequest('Every tag must match ^[a-z0-9:_-]{1,100}$.');
    }
    return { ok: true, tags: tags as string[] };
}
