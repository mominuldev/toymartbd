import type { IconName } from '@/components/icons/paths';

// Mobile bottom tab bar (storefront spec §A5, Figma MobileNav 111:784): five fixed, locale-authored items.
// Cart and account pages land with P4-STO-08 / P4-STO-11; until then those tabs reach the 404.

export type TabKey = 'home' | 'categories' | 'search' | 'cart' | 'account';

export const tabs: ReadonlyArray<{ key: TabKey; path: string; icon: IconName }> = [
    { key: 'home', path: '/', icon: 'house' },
    { key: 'categories', path: '/categories', icon: 'list' },
    { key: 'search', path: '/search', icon: 'magnifyingGlass' },
    { key: 'cart', path: '/cart', icon: 'shoppingBagOpenFill' },
    { key: 'account', path: '/account', icon: 'user' },
];

/** Which tab a locale-independent path belongs to (null: none highlighted, e.g. a brand page). */
export function activeTab(path: string): TabKey | null {
    const bare = path.split(/[?#]/)[0] ?? '/';
    if (bare === '/' || bare === '') return 'home';
    for (const tab of tabs) {
        if (tab.path !== '/' && (bare === tab.path || bare.startsWith(`${tab.path}/`))) return tab.key;
    }
    return null;
}

/** Cart badge text: hidden at 0, capped at 99+ (§A5). */
export function cartBadge(count: number): string | null {
    if (!Number.isFinite(count) || count <= 0) return null;
    return count > 99 ? '99+' : String(Math.floor(count));
}
