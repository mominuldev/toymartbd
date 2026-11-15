/** A site path (`/…`, never `//…`) or a full https:// address — the rule the API applies to custom links. */
export function isValidLink(url: string): boolean {
    const value = url.trim();
    if (value.startsWith('/')) return !value.startsWith('//') && /^\/[A-Za-z0-9\-._~/?=&%#]*$/.test(value);
    try {
        return new URL(value).protocol === 'https:';
    } catch {
        return false;
    }
}

/**
 * First path segments the storefront routes itself, so never a page slug. Mirrors the API's
 * CmsPage::RESERVED_SLUGS (pages live at the site root, `/{slug}`); the API is the authority.
 */
const reservedSlugs: ReadonlySet<string> = new Set([
    'account', 'admin', 'api', 'blog', 'bn', 'brands', 'cart', 'categories', 'checkout', 'delivery-charge',
    'en', 'flash-sale', 'guides', 'maintenance', 'orders', 'pages', 'preview', 'products', 'robots', 'search', 'sitemap',
    'sitemaps', 'toys', 'track',
]);

/** The info strip (shown on checkout) may link only to a store page, `/{slug}` (rule 25b). */
export function isPageLink(url: string): boolean {
    const match = /^\/([a-z0-9]+(?:-[a-z0-9]+)*)\/?$/.exec(url.trim());
    return match !== null && !reservedSlugs.has(match[1]!);
}

/** Move one element of a list from one index to another, returning a new list. */
export function moveIndex<T>(list: readonly T[], from: number, to: number): T[] {
    if (from === to || from < 0 || to < 0 || from >= list.length || to >= list.length) return [...list];
    const next = [...list];
    const [item] = next.splice(from, 1);
    next.splice(to, 0, item as T);
    return next;
}
