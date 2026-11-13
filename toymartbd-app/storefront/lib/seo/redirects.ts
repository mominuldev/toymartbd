import { localizedPath, splitLocalePath } from '../i18n/config';
import type { RedirectEntry } from './types';

// Redirect resolution for proxy.ts (docs/api/seo.md §2–3). Pure, so it is unit-tested.
// The map is locale-independent (`/products/old` → `/products/new`); a request under /bn keeps its
// locale. Laravel already collapses chains (A→B→C is served as A→C) and drops loops; this side is
// defensive anyway: it follows at most MAX_HOPS internal hops and gives up on a loop, so a bad map
// can never redirect a visitor in circles.

export const MAX_HOPS = 5;

export type RedirectIndex = ReadonlyMap<string, RedirectEntry>;

export interface RedirectDecision {
    /** Public URL path + query (or an absolute https URL) to send the visitor to. */
    location: string;
    status: 301 | 302;
    /** Manual redirect ids whose hit counter to bump (the first hop's, when it has one). */
    id: number | null;
}

/** Trailing slash dropped (except `/`); lookups are exact otherwise (slugs are lower-case ASCII). */
export function normalisePath(path: string): string {
    if (path.length > 1 && path.endsWith('/')) return path.replace(/\/+$/, '') || '/';
    return path;
}

export function indexRedirects(entries: readonly RedirectEntry[]): RedirectIndex {
    const map = new Map<string, RedirectEntry>();
    for (const entry of entries) {
        if (typeof entry?.from !== 'string' || typeof entry.to !== 'string' || !entry.from.startsWith('/')) continue;
        if (entry.status !== 301 && entry.status !== 302) continue;
        if (!entry.to.startsWith('/') && !/^https:\/\//.test(entry.to)) continue;
        map.set(normalisePath(entry.from), entry);
    }
    return map;
}

/**
 * Where `pathname` (+ `search`) should go, or null to serve it. A chain of internal hops collapses
 * to its final target with the first hop's status (301 only when every hop is permanent).
 */
export function resolveRedirect(index: RedirectIndex, pathname: string, search = ''): RedirectDecision | null {
    if (index.size === 0) return null;
    const { locale, path } = splitLocalePath(pathname);
    let current = normalisePath(path);
    let entry = index.get(current);
    if (!entry) return null;

    const first = entry;
    let permanent = entry.status === 301;
    const seen = new Set<string>([current]);

    for (let hop = 0; hop < MAX_HOPS; hop++) {
        const target = entry.to;
        if (!target.startsWith('/')) {
            // External https target: as given, no locale prefix, no query carried over.
            return { location: target, status: permanent ? 301 : 302, id: first.id };
        }
        const [targetPath, targetQuery = ''] = target.split('?', 2) as [string, string?];
        const next = normalisePath(targetPath);
        if (seen.has(next)) return null; // loop: serve the page (404) rather than bounce forever
        const nextEntry = index.get(next);
        if (!nextEntry) {
            const query = targetQuery !== '' ? `?${targetQuery}` : search;
            return { location: `${localizedPath(locale, next)}${query}`, status: permanent ? 301 : 302, id: first.id };
        }
        seen.add(next);
        permanent = permanent && nextEntry.status === 301;
        current = next;
        entry = nextEntry;
    }
    return null; // longer than MAX_HOPS: treat as broken
}
