// Homepage draft preview (Phase 7) — client-safe helpers for the preview route handler.
//
// The admin mints a token with POST /admin/content/home/preview-token: `home_draft.{user}.{expires}.{hmac}`
// (App\Support\PreviewToken, HMAC under the Laravel app key, 10-minute life). The storefront
// cannot check the HMAC itself — the key never leaves Laravel — so the route handler rejects
// obviously bad tokens here (shape, expiry) and then asks the API, server-side, before it turns
// draft mode on. The API checks the signature again on every draft read.

export const HOME_PREVIEW_COOKIE = 'tm_home_preview';

const TOKEN = /^home_draft\.(\d{1,12})\.(\d{1,12})\.([a-f0-9]{64})$/;

/** Seconds until the token expires, or null when it is malformed or already expired. */
export function previewTokenLifetime(token: string | null | undefined, nowSeconds: number): number | null {
    if (!token) return null;
    const match = TOKEN.exec(token);
    if (!match) return null;
    const left = Number(match[2]) - nowSeconds;
    return left > 0 ? left : null;
}
