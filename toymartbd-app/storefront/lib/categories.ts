import 'server-only';

import { cache } from 'react';

import { cached } from './api';
import { normalizeTree, type CategoryNode } from './category-tree';

export const categoryTags = {
    tree: 'categories',
} as const;

/**
 * The menu tree, ISR-cached under tag `categories` (POST /api/revalidate {"tags":["categories"]}).
 * The body is not localised (both names come back), so one fetch serves both locales.
 * Throws on any failure — use it where the page is meaningless without the tree.
 */
export const fetchCategoryTree = cache(async (): Promise<CategoryNode[]> =>
    normalizeTree(await cached<unknown>('storefront/categories/tree', [categoryTags.tree])),
);

/**
 * Same tree for the chrome (mega menu, 404 tiles). Never throws: the header renders on every
 * page, so an unreachable API, a 5xx, a missing env var or a malformed body must cost the menu,
 * not the page. Caveat: a page rendered without its menu is ISR-cached like any other, until the
 * next `categories` revalidation or the 3600 s ceiling.
 */
export const getCategoryTree = cache(async (): Promise<CategoryNode[]> => {
    try {
        return await fetchCategoryTree();
    } catch (error) {
        console.error('[categories] menu tree unavailable; rendering without it:', error instanceof Error ? error.message : error);
        return [];
    }
});
