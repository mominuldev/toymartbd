import { hrefFor, toApiQuery, type ListingState } from '../listing/state';

// Search URL helpers (P4-STO-04). Pure and client-safe.
//
//   /search?q=puzzle&age=24-48&category=puzzles&sort=newest&page=2
//
// `q` comes first, then the listing's canonical filter query (lib/listing/state.ts, the same
// keys and semantics as a category page), then `page`. Any filter or sort change drops `page`:
// the filter links are built from the state alone, which has no page.

/** The API's limit (SearchQuery::MAX_QUERY). */
export const MAX_QUERY = 100;
/** The suggestion endpoint's minimum (SearchQuery::MIN_SUGGEST_QUERY). */
export const MIN_SUGGEST_QUERY = 2;
/** Meilisearch serves the first 1000 hits, 24 per page (SearchQuery::fromRequest). */
export const MAX_PAGE = 41;

/** The search text from a URL value: first value, trimmed, capped, control characters dropped. */
export function parseQuery(value: string | string[] | undefined): string {
    const raw = Array.isArray(value) ? value[0] : value;
    // eslint-disable-next-line no-control-regex
    return (raw ?? '').replace(/[\u0000-\u001f\u007f]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, MAX_QUERY).trim();
}

/** `page` from a URL value: a whole number 1–41, anything else is page 1. */
export function parsePage(value: string | string[] | undefined): number {
    const raw = Array.isArray(value) ? value[0] : value;
    if (!raw || !/^\d{1,3}$/.test(raw)) return 1;
    const n = Number(raw);
    return n >= 1 && n <= MAX_PAGE ? n : 1;
}

/** `/search?q=…` (locale-independent). */
export function searchPath(q: string): string {
    return q ? `/search?${new URLSearchParams({ q }).toString()}` : '/search';
}

/** A results page: `base` is the localised `/search?q=…`. Page 1 has no `page` key. */
export function pageHref(base: string, state: ListingState, page: number): string {
    const href = hrefFor(base, { ...state, cursor: null });
    return page > 1 ? `${href}&page=${page}` : href;
}

/** The API query: `q`, the listing filters, a non-default sort and `page`. */
export function toSearchApiQuery(q: string, state: ListingState, page: number): URLSearchParams {
    const query = new URLSearchParams({ q });
    for (const [key, value] of toApiQuery(state, { kind: 'search' })) query.set(key, value);
    if (page > 1) query.set('page', String(page));
    return query;
}

/**
 * The numbered page window (Figma Pagination 76:820): first, last and current ±1 always; "…"
 * only replaces two or more pages; near either end 1 2 3 4 5 … n, so the row never changes width.
 */
export function pageWindow(current: number, last: number): Array<number | 'gap'> {
    if (last <= 1) return [];
    if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
    if (current <= 4) return [1, 2, 3, 4, 5, 'gap', last];
    if (current >= last - 3) return [1, 'gap', last - 4, last - 3, last - 2, last - 1, last];
    return [1, 'gap', current - 1, current, current + 1, 'gap', last];
}
