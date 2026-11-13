// Homepage builder ↔ storefront preview: the postMessage protocol (Phase 8, live editor).
//
// The admin's home builder shows the draft homepage in an iframe. Inside that frame — and only
// there — components/content/PreviewBridge.tsx turns the page into an editing canvas: a hover
// outline with the section's name, click-to-select, and an in-place refresh after each autosave.
// The two sides talk over window.postMessage. Every message carries `source: 'tm-home-builder'`
// and is accepted only from the expected origin and window, in both directions. Nothing in the
// protocol carries data beyond block ids (the CMS block's uuid, which is the id the storefront
// payload uses) and the section names the admin already shows; the draft itself is still read
// server-side with the signed preview token.

export const BRIDGE_SOURCE = 'tm-home-builder';

/** Admin → storefront. */
export type ToCanvas =
    | { source: typeof BRIDGE_SOURCE; type: 'refresh' }
    | { source: typeof BRIDGE_SOURCE; type: 'select'; id: string | null; scroll: boolean }
    | { source: typeof BRIDGE_SOURCE; type: 'labels'; labels: Record<string, string> };

/** Storefront → admin. */
export type FromCanvas = { source: typeof BRIDGE_SOURCE; type: 'ready' } | { source: typeof BRIDGE_SOURCE; type: 'select'; id: string };

function record(value: unknown): Record<string, unknown> | null {
    return typeof value === 'object' && value !== null && !Array.isArray(value) ? (value as Record<string, unknown>) : null;
}

const BLOCK_ID = /^[A-Za-z0-9-]{1,64}$/;

/** A block id as the storefront payload carries it (the CMS block's uuid). */
export function blockId(value: unknown): string | null {
    return typeof value === 'string' && BLOCK_ID.test(value) ? value : null;
}

/** A message from the builder, or null when it is not one (wrong shape or source). */
export function parseToCanvas(data: unknown): ToCanvas | null {
    const m = record(data);
    if (!m || m.source !== BRIDGE_SOURCE) return null;
    switch (m.type) {
        case 'refresh':
            return { source: BRIDGE_SOURCE, type: 'refresh' };
        case 'select':
            if (m.id !== null && blockId(m.id) === null) return null;
            return { source: BRIDGE_SOURCE, type: 'select', id: m.id === null ? null : blockId(m.id), scroll: m.scroll === true };
        case 'labels': {
            const raw = record(m.labels);
            if (!raw) return null;
            const labels: Record<string, string> = {};
            for (const [key, value] of Object.entries(raw)) {
                if (blockId(key) !== null && typeof value === 'string') labels[key] = value.slice(0, 120);
            }
            return { source: BRIDGE_SOURCE, type: 'labels', labels };
        }
        default:
            return null;
    }
}

/**
 * The origin the builder runs on. The admin is served by Laravel, so it shares the API's origin
 * unless ADMIN_URL says otherwise (an API reached over an internal hostname). Null when neither
 * parses — the bridge then stays off and the preview is a plain page.
 */
export function adminOrigin(env: { ADMIN_URL?: string; API_URL?: string }): string | null {
    for (const value of [env.ADMIN_URL, env.API_URL]) {
        if (!value) continue;
        try {
            return new URL(value).origin;
        } catch {
            // try the next one
        }
    }
    return null;
}
