// Browser identifiers the server-side twin needs for matching (K-02). Pure and client-safe; the
// /api/events and /api/checkout Route Handlers read them from the request's cookies, so the browser
// never has to copy them into a body.

// --- Meta: _fbp / _fbc -------------------------------------------------------------------------
// https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/fbp-and-fbc
//   fbc = `fb.<subdomainIndex>.<creationTime>.<fbclid>`: "fb" always; subdomainIndex 1 when the
//   value is generated on a server without an _fbc cookie; creationTime in MILLISECONDS (when the
//   cookie was saved, else when the fbclid was first observed); the fbclid is case-sensitive and
//   must not be modified. The _fbc cookie is recommended with a 90-day expiry.
//   fbp = `fb.<subdomainIndex>.<creationTime>.<random>`, written by the Pixel SDK — we only forward it.

const FBP = /^fb\.\d\.\d{10,16}\.\d{1,30}$/;
const FBC = /^fb\.\d\.\d{10,16}\.[A-Za-z0-9_-]{1,500}$/;
const FBCLID = /^[A-Za-z0-9_-]{1,500}$/;
export const FBC_COOKIE = '_fbc';
export const FBP_COOKIE = '_fbp';
export const FBC_MAX_AGE = 90 * 24 * 60 * 60;

export function validFbp(value: string | null | undefined): string | null {
    return value && FBP.test(value) ? value : null;
}

export function validFbc(value: string | null | undefined): string | null {
    return value && FBC.test(value) ? value : null;
}

/** The `fbclid` query parameter of a URL, untouched (case-sensitive), or null. */
export function fbclidFrom(url: string | null | undefined): string | null {
    if (!url) return null;
    try {
        const value = new URL(url).searchParams.get('fbclid');
        return value && FBCLID.test(value) ? value : null;
    } catch {
        return null;
    }
}

/** `fb.1.<ms>.<fbclid>` — subdomainIndex 1 as Meta specifies for a server-generated value. */
export function buildFbc(fbclid: string, observedAtMs: number): string | null {
    if (!FBCLID.test(fbclid)) return null;
    return `fb.1.${Math.floor(observedAtMs)}.${fbclid}`;
}

/** Prefer the _fbc cookie (the Pixel's own, or ours); else build one from an fbclid in the URL. */
export function resolveFbc(cookie: string | null | undefined, eventSourceUrl: string | null | undefined, nowMs: number): string | null {
    const existing = validFbc(cookie);
    if (existing) return existing;
    const fbclid = fbclidFrom(eventSourceUrl);
    return fbclid ? buildFbc(fbclid, nowMs) : null;
}

// --- Google Analytics: _ga / _ga_<container> ----------------------------------------------------
// UNVERIFIED: Google documents getting these through `gtag('get', '<G-ID>', 'client_id' | 'session_id', cb)`
// (https://developers.google.com/tag-platform/gtagjs/reference#get), not the cookie layout. The
// formats below are the ones gtag.js writes today:
//   _ga               = GA1.1.<random>.<first visit seconds>              → client_id "<random>.<seconds>"
//   _ga_<id minus G-> = GS1.1.<session start>.<n>.<…>                     → session_id "<session start>"
//                     | GS2.1.s<session start>$o<n>$g<…>$t<…>…            (newer layout)
// A value in any other shape is dropped (null), never guessed.

export function gaClientId(cookie: string | null | undefined): string | null {
    const match = /^GA\d\.\d+\.(\d{1,20}\.\d{1,20})$/.exec(cookie ?? '');
    return match ? match[1]! : null;
}

export function gaSessionCookieName(measurementId: string): string {
    return `_ga_${measurementId.replace(/^G-/, '')}`;
}

export function gaSessionId(cookie: string | null | undefined): string | null {
    const value = cookie ?? '';
    const v2 = /^GS2\.\d+\.s(\d{1,20})(?:\$|$)/.exec(value);
    if (v2) return v2[1]!;
    const v1 = /^GS1\.\d+\.(\d{1,20})\./.exec(value);
    return v1 ? v1[1]! : null;
}

/** One cookie from a `Cookie` header, URL-decoded, or null. */
export function cookieValue(header: string | null | undefined, name: string): string | null {
    for (const part of (header ?? '').split(';')) {
        const eq = part.indexOf('=');
        if (eq === -1) continue;
        if (part.slice(0, eq).trim() !== name) continue;
        const raw = part.slice(eq + 1).trim();
        try {
            return decodeURIComponent(raw);
        } catch {
            return raw;
        }
    }
    return null;
}
