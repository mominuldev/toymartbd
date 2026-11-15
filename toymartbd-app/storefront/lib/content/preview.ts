// Homepage draft preview (Phase 7) — client-safe helpers for the preview route handler.
//
// The admin mints a token with POST /admin/content/home/preview-token: `home_draft.{user}.{expires}.{hmac}`
// (App\Support\PreviewToken, HMAC under the Laravel app key, 10-minute life). The storefront
// cannot check the HMAC itself — the key never leaves Laravel — so the route handler rejects
// obviously bad tokens here (shape, expiry) and then asks the API, server-side, before it turns
// draft mode on. The API checks the signature again on every draft read.

//
// Static pages built from sections (2026-11-16) use the same scheme with type `page_draft`
// (`page_draft.{page id}.{expires}.{hmac}`, from POST /admin/content/pages/{page}/layout/preview-token),
// opened through /api/preview/page and kept in their own cookie.

export const HOME_PREVIEW_COOKIE = 'tm_home_preview';
export const PAGE_PREVIEW_COOKIE = 'tm_page_preview';

export type PreviewTokenType = 'home_draft' | 'page_draft';

const TOKEN = /^([a-z_]{1,32})\.(\d{1,12})\.(\d{1,12})\.([a-f0-9]{64})$/;

/** Seconds until the token expires, or null when it is malformed, of another type or already expired. */
export function previewTokenLifetime(token: string | null | undefined, nowSeconds: number, type: PreviewTokenType = 'home_draft'): number | null {
    if (!token) return null;
    const match = TOKEN.exec(token);
    if (!match || match[1] !== type) return null;
    const left = Number(match[3]) - nowSeconds;
    return left > 0 ? left : null;
}
