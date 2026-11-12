import type { AccountFlash } from './types';

// Encode / decode the one-shot account flash (base64url JSON, so Bangla survives the cookie header).

const MAX = 3500;

export function encodeFlash(flash: AccountFlash): string {
    return Buffer.from(JSON.stringify(flash), 'utf8').toString('base64url');
}

function strings(value: unknown, limit: number): Record<string, string> {
    if (typeof value !== 'object' || value === null) return {};
    const out: Record<string, string> = {};
    for (const [key, v] of Object.entries(value).slice(0, 20)) {
        if (typeof v === 'string' && /^[a-z_]{1,32}$/.test(key)) out[key] = v.slice(0, limit);
    }
    return out;
}

export function decodeFlash(value: string | undefined | null): AccountFlash | null {
    if (!value || value.length > MAX) return null;
    try {
        const raw = JSON.parse(Buffer.from(value, 'base64url').toString('utf8')) as Record<string, unknown>;
        if (raw.kind !== 'error' && raw.kind !== 'success') return null;
        return {
            kind: raw.kind,
            message: typeof raw.message === 'string' ? raw.message.slice(0, 300) : null,
            errors: strings(raw.errors, 300),
            draft: strings(raw.draft, 500),
        };
    } catch {
        return null;
    }
}

export interface OtpState {
    /** National form, 01XXXXXXXXX. */
    phone: string;
    /** When the last code was sent (ms since epoch). */
    sentAt: number;
    /** Seconds before another code may be asked for. */
    resendIn: number;
    /** Where to go after signing in (a local path). */
    next: string;
}

export function encodeOtp(state: OtpState): string {
    return Buffer.from(JSON.stringify(state), 'utf8').toString('base64url');
}

export function decodeOtp(value: string | undefined | null): OtpState | null {
    if (!value || value.length > 600) return null;
    try {
        const raw = JSON.parse(Buffer.from(value, 'base64url').toString('utf8')) as Record<string, unknown>;
        if (typeof raw.phone !== 'string' || !/^01[3-9]\d{8}$/.test(raw.phone)) return null;
        return {
            phone: raw.phone,
            sentAt: typeof raw.sentAt === 'number' ? raw.sentAt : 0,
            resendIn: typeof raw.resendIn === 'number' ? Math.min(Math.max(raw.resendIn, 0), 3600) : 60,
            next: safeNext(typeof raw.next === 'string' ? raw.next : null),
        };
    } catch {
        return null;
    }
}

/** Only a local account / checkout / cart path; anything else (or a protocol-relative URL) goes home. */
export function safeNext(value: string | null | undefined): string {
    if (!value || !/^\/(account|checkout|cart|products)(\/[A-Za-z0-9\-_/]*)?$/.test(value)) return '/account';
    return value;
}

/** 01712345678 → "01712 345 678" for display (Western digits, rule 36). */
export function displayPhone(national: string): string {
    return /^\d{11}$/.test(national) ? `${national.slice(0, 5)} ${national.slice(5, 8)} ${national.slice(8)}` : national;
}

/** Digits only, Bengali digits folded to Western; what the form sends to the API. */
export function normalisePhoneInput(value: string): string {
    return value.replace(/[০-৯]/g, (d) => String('০১২৩৪৫৬৭৮৯'.indexOf(d))).replace(/[^\d+]/g, '');
}

/** National 01XXXXXXXXX from any accepted spelling (+880 / 880 / 0), or null. Mirrors the API's rule 30 check. */
export function nationalPhone(value: string): string | null {
    const clean = normalisePhoneInput(value);
    const m = /^(?:\+?880|0)(1[3-9]\d{8})$/.exec(clean);
    return m ? `0${m[1]}` : null;
}
