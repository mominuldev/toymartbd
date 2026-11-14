// @ts-check
/**
 * Sentry PII scrubbing for the browser and Next.js SDKs (P10, CLAUDE.md rule 20) — the same rules
 * as the API's App\Support\Logging\RedactSensitiveData, so all three apps redact alike:
 *
 *  - Bangladeshi mobile numbers anywhere in text → `017****5678`;
 *  - e-mail addresses anywhere in text → `r***@example.com`;
 *  - values under a sensitive key (password, token, secret, api key, authorization, cookie,
 *    credentials, address, otp…) at any depth → `[redacted]`;
 *  - secrets inside free text: `"password":"…"`, `token=…`, `Bearer …`;
 *  - the event's user keeps only its id; request cookies are dropped.
 *
 * Plain JavaScript with a hand-written .d.ts beside it, so Vite, Vitest and Next.js consume it
 * without transpiling a workspace package.
 */

export const REDACTED = '[redacted]';

const SENSITIVE_KEYS = new Set([
    'password', 'password_confirmation', 'current_password', 'new_password', 'token', 'secret', 'api_key',
    'apikey', 'authorization', 'proxy_authorization', 'cookie', 'set_cookie', 'credentials', 'address',
    'address_line', 'address_line_1', 'address_line_2', 'php_auth_pw', 'otp', 'otp_code', 'one_time_password',
    'verification_code', 'x_cart_token', 'cart_token',
]);

const SENSITIVE_FRAGMENTS = [
    'password', 'token', 'secret', 'api_key', 'credential', 'address_line', '_address', 'authorization', 'cookie',
    '_otp', 'otp_',
];

const SECRET_WORDS = '[A-Za-z0-9_\\-]*(?:password|token|secret|api[_\\-]?key|authorization|cookie|credential|address_line|otp)[A-Za-z0-9_\\-]*';

const PHONE = /(?<!\d)(?:\+?880|0)(1[3-9]\d{8})(?!\d)/g;
const EMAIL = /(?<![A-Za-z0-9._%+-])([A-Za-z0-9])[A-Za-z0-9._%+-]*@([A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*\.[A-Za-z]{2,})/g;
const JSON_SECRET = new RegExp(`("${SECRET_WORDS}"\\s*:\\s*)"(?:[^"\\\\]|\\\\.)*"`, 'gi');
const QUERY_SECRET = new RegExp(`(?<![A-Za-z0-9_\\-])(${SECRET_WORDS}=)[^&\\s"']+`, 'gi');
const AUTH_HEADER = /\b(Bearer|Basic)\s+[A-Za-z0-9._~+/=-]+/g;

const MAX_DEPTH = 10;

/** @param {string} value */
export function maskPhones(value) {
    return value.replace(PHONE, (_m, ten) => `0${ten.slice(0, 2)}****${ten.slice(-4)}`);
}

/** @param {string} value */
export function maskEmails(value) {
    return value.replace(EMAIL, '$1***@$2');
}

/** @param {string} value */
export function scrubText(value) {
    return maskEmails(maskPhones(value))
        .replace(JSON_SECRET, `$1"${REDACTED}"`)
        .replace(QUERY_SECRET, `$1${REDACTED}`)
        .replace(AUTH_HEADER, `$1 ${REDACTED}`);
}

/** @param {string | number} key */
export function isSensitiveKey(key) {
    if (typeof key !== 'string') {
        return false;
    }
    const k = key.toLowerCase().replaceAll('-', '_');

    return SENSITIVE_KEYS.has(k) || SENSITIVE_FRAGMENTS.some((f) => k.includes(f));
}

/**
 * @param {unknown} value
 * @param {number} [depth]
 * @returns {unknown}
 */
export function scrubValue(value, depth = 0) {
    if (depth > MAX_DEPTH) {
        return value;
    }
    if (typeof value === 'string') {
        return scrubText(value);
    }
    if (Array.isArray(value)) {
        return value.map((v) => scrubValue(v, depth + 1));
    }
    if (value !== null && typeof value === 'object') {
        /** @type {Record<string, unknown>} */
        const out = {};
        for (const [k, v] of Object.entries(value)) {
            out[k] = isSensitiveKey(k) ? REDACTED : scrubValue(v, depth + 1);
        }

        return out;
    }

    return value;
}

/**
 * Sentry `beforeSend` / `beforeSendTransaction`: scrubs an event in place-safe fashion and
 * returns it. Typed loosely so it fits every Sentry SDK's Event type.
 *
 * @template {Record<string, any>} E
 * @param {E} event
 * @returns {E}
 */
export function scrubEvent(event) {
    /** @type {Record<string, any>} */
    const e = { ...event };
    if (typeof e.message === 'string') {
        e.message = scrubText(e.message);
    }
    if (e.logentry && typeof e.logentry === 'object') {
        e.logentry = scrubValue(e.logentry);
    }
    if (e.exception?.values) {
        e.exception = {
            ...e.exception,
            values: e.exception.values.map((/** @type {Record<string, any>} */ ex) => ({
                ...ex,
                value: typeof ex.value === 'string' ? scrubText(ex.value) : ex.value,
                stacktrace: ex.stacktrace?.frames
                    ? {
                          ...ex.stacktrace,
                          frames: ex.stacktrace.frames.map((/** @type {Record<string, any>} */ f) => ({
                              ...f,
                              vars: f.vars ? scrubValue(f.vars) : f.vars,
                              context_line: typeof f.context_line === 'string' ? scrubText(f.context_line) : f.context_line,
                              pre_context: Array.isArray(f.pre_context) ? scrubValue(f.pre_context) : f.pre_context,
                              post_context: Array.isArray(f.post_context) ? scrubValue(f.post_context) : f.post_context,
                          })),
                      }
                    : ex.stacktrace,
            })),
        };
    }
    if (e.request && typeof e.request === 'object') {
        const { cookies: _dropped, ...request } = e.request;
        e.request = scrubValue(request);
    }
    for (const key of ['extra', 'contexts', 'tags']) {
        if (e[key]) {
            e[key] = scrubValue(e[key]);
        }
    }
    if (Array.isArray(e.breadcrumbs)) {
        e.breadcrumbs = e.breadcrumbs.map(scrubBreadcrumb);
    }
    if (e.user && typeof e.user === 'object') {
        e.user = e.user.id === undefined ? undefined : { id: e.user.id };
    }

    return /** @type {E} */ (e);
}

/**
 * Sentry `beforeBreadcrumb`.
 *
 * @template {Record<string, any>} B
 * @param {B} breadcrumb
 * @returns {B}
 */
export function scrubBreadcrumb(breadcrumb) {
    /** @type {Record<string, any>} */
    const b = { ...breadcrumb };
    if (typeof b.message === 'string') {
        b.message = scrubText(b.message);
    }
    if (b.data) {
        b.data = scrubValue(b.data);
    }

    return /** @type {B} */ (b);
}

/**
 * Parses a sample rate from an env string; out of range or missing → fallback.
 *
 * @param {string | undefined} raw
 * @param {number} fallback
 */
export function sampleRate(raw, fallback) {
    if (raw === undefined || raw.trim() === '') {
        return fallback;
    }
    const n = Number(raw);

    return Number.isFinite(n) && n >= 0 && n <= 1 ? n : fallback;
}

/**
 * The options every Toy Mart BD Sentry.init() shares. `enabled` is false without a DSN, so the
 * SDK is a no-op (no network, no global handlers' reports) until the owner adds one.
 *
 * @param {{ dsn?: string, release?: string, environment?: string, sampleRate?: string, tracesSampleRate?: string }} env
 */
export function baseSentryOptions(env) {
    const dsn = env.dsn && env.dsn.trim() !== '' ? env.dsn.trim() : undefined;

    return {
        dsn,
        enabled: dsn !== undefined,
        release: env.release && env.release.trim() !== '' ? env.release.trim() : undefined,
        environment: env.environment && env.environment.trim() !== '' ? env.environment.trim() : 'production',
        sendDefaultPii: false,
        sampleRate: sampleRate(env.sampleRate, 1),
        tracesSampleRate: sampleRate(env.tracesSampleRate, 0),
        beforeSend: scrubEvent,
        beforeSendTransaction: scrubEvent,
        beforeBreadcrumb: scrubBreadcrumb,
    };
}
