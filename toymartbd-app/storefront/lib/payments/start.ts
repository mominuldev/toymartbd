import 'server-only';

import { ApiError, send } from '../api';
import type { Locale } from '../i18n/config';

// Start, resume or retry an online order's payment (P4-PAY-02/04): POST /storefront/orders/{n}/payment
// with the placing cart's token and an Idempotency-Key (rule 17). The API decides everything —
// whether a new session is allowed, the amount, the gateway URL. We only follow its redirect.

export type StartResult = { ok: true; url: string } | { ok: false; reason: string };

interface StartAnswer {
    data: { next_action: { type: string; url: string } | null };
}

/** Only ever send a browser to an https URL the API handed us. */
function safeUrl(value: unknown): string | null {
    if (typeof value !== 'string') return null;
    try {
        const url = new URL(value);
        return url.protocol === 'https:' ? url.toString() : null;
    } catch {
        return null;
    }
}

export async function startPayment(number: string, token: string, key: string, locale: Locale): Promise<StartResult> {
    try {
        const { data } = await send<StartAnswer>('POST', `storefront/orders/${encodeURIComponent(number)}/payment`, {
            locale,
            headers: { 'X-Cart-Token': token, 'Idempotency-Key': key },
        });
        const url = data.data.next_action?.type === 'redirect' ? safeUrl(data.data.next_action.url) : null;
        return url ? { ok: true, url } : { ok: false, reason: 'no_session' };
    } catch (error) {
        if (!(error instanceof ApiError)) return { ok: false, reason: 'failed' };
        const reason = (error.problem as { reason?: unknown }).reason;
        return { ok: false, reason: typeof reason === 'string' ? reason : error.status === 404 ? 'not_found' : 'failed' };
    }
}
