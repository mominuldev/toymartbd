import type { Locale } from './i18n/config';

// Client-safe shapes and pure helpers for the category tree behind the mega menu, the mobile
// categories page and the footer. The fetch itself is server-only (lib/categories.ts).
//
// API contract — GET /api/v1/storefront/categories/tree (active + show_in_menu only, depth ≤ 3):
//   {"data":[{"id":1,"name":"…","name_bn":"…"|null,"slug":"…","icon":null,"image_url":null,"children":[…]}]}
// TODO(api-types): switch to the generated `@toymart/api-types` schema once the endpoint is in
// docs/api/openapi.json (another agent is adding it).

export const MAX_DEPTH = 3;

export interface CategoryNode {
    id: number;
    name: string;
    name_bn: string | null;
    slug: string;
    icon: string | null;
    image_url: string | null;
    children: CategoryNode[];
}

const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

function str(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function normalizeNode(value: unknown, depth: number, maxDepth: number): CategoryNode | null {
    if (typeof value !== 'object' || value === null) return null;
    const raw = value as Record<string, unknown>;
    const name = str(raw.name);
    const slug = str(raw.slug);
    if (typeof raw.id !== 'number' || !name || !slug || !SLUG.test(slug)) return null;
    const children =
        depth < maxDepth && Array.isArray(raw.children)
            ? raw.children.map((c) => normalizeNode(c, depth + 1, maxDepth)).filter((c): c is CategoryNode => c !== null)
            : [];
    return { id: raw.id, name, name_bn: str(raw.name_bn), slug, icon: str(raw.icon), image_url: str(raw.image_url), children };
}

/**
 * Validates an untrusted API body into a tree at most `maxDepth` levels deep. Malformed nodes
 * (missing name, non-ASCII slug…) are dropped with their subtree rather than failing the
 * whole header; anything deeper than the limit is cut off.
 */
export function normalizeTree(body: unknown, maxDepth: number = MAX_DEPTH): CategoryNode[] {
    if (typeof body !== 'object' || body === null) return [];
    const data = (body as { data?: unknown }).data;
    if (!Array.isArray(data)) return [];
    return data.map((n) => normalizeNode(n, 1, maxDepth)).filter((n): n is CategoryNode => n !== null);
}

/** Bangla name with an English fallback when the translation is missing. */
export function categoryName(node: Pick<CategoryNode, 'name' | 'name_bn'>, locale: Locale): string {
    return locale === 'bn' && node.name_bn ? node.name_bn : node.name;
}

/** True when the name shown is not in the page language (mark it `lang="en"`). */
export function categoryNameIsFallback(node: Pick<CategoryNode, 'name_bn'>, locale: Locale): boolean {
    return locale === 'bn' && !node.name_bn;
}

export function categoryPath(slug: string): string {
    return `/categories/${slug}`;
}

/** Deepest level present in a tree (0 for an empty tree). */
export function treeDepth(nodes: CategoryNode[]): number {
    return nodes.reduce((max, n) => Math.max(max, 1 + treeDepth(n.children)), 0);
}

/** Find a node anywhere in the tree, with its ancestors (root first). */
export function findCategory(tree: CategoryNode[], slug: string): { node: CategoryNode; trail: CategoryNode[] } | null {
    for (const node of tree) {
        if (node.slug === slug) return { node, trail: [] };
        const inner = findCategory(node.children, slug);
        if (inner) return { node: inner.node, trail: [node, ...inner.trail] };
    }
    return null;
}

// ---- Mega menu view model --------------------------------------------------------------------

/** A link as the menu renders it: already localised, path locale-independent. */
export interface MenuLink {
    label: string;
    /** `en` when a Bangla page shows the English fallback. */
    lang?: 'en';
    path: string;
    /** An absolute https URL from the CMS menu: rendered as is, not locale-prefixed. */
    external?: boolean;
    /** Open in a new tab (CMS `open_in_new_tab`). */
    newTab?: boolean;
}

export interface MenuGroup {
    heading: MenuLink;
    links: MenuLink[];
}

export interface MenuItem {
    id: string;
    label: MenuLink;
    /** Up to 3 columns of L2 groups. Empty when the L1 category has no children (plain link). */
    columns: MenuGroup[][];
    /** "All in {category}" — first entry of the panel. */
    viewAll: MenuLink | null;
}

/** L1 items shown in the bar before the rest collapse into "More" (A4.5: never wrap, never shrink). */
export const MAX_BAR_ITEMS = 7;
export const PANEL_COLUMNS = 3;

function toLink(node: CategoryNode, locale: Locale): MenuLink {
    const link: MenuLink = { label: categoryName(node, locale), path: categoryPath(node.slug) };
    if (categoryNameIsFallback(node, locale)) link.lang = 'en';
    return link;
}

/** Deal groups into `count` columns, top-to-bottom then left-to-right (column 1 first). */
export function splitColumns<T>(items: T[], count: number = PANEL_COLUMNS): T[][] {
    if (items.length === 0) return [];
    const perColumn = Math.ceil(items.length / count);
    const columns: T[][] = [];
    for (let i = 0; i < items.length; i += perColumn) columns.push(items.slice(i, i + perColumn));
    return columns;
}

export interface MenuLabels {
    viewAll: (category: string) => string;
    more: string;
    allCategories: string;
}

/**
 * Builds the bar: one item per L1 category (L2 groups with their L3 links in up to three
 * columns), and — when there are more than MAX_BAR_ITEMS — a final "More" item whose single
 * column lists the overflow L1 categories.
 */
export function buildMenu(tree: CategoryNode[], locale: Locale, labels: MenuLabels): MenuItem[] {
    const toItem = (node: CategoryNode): MenuItem => {
        const label = toLink(node, locale);
        const groups = node.children.map((l2) => ({
            heading: toLink(l2, locale),
            links: l2.children.map((l3) => toLink(l3, locale)),
        }));
        return {
            id: `cat-${node.id}`,
            label,
            columns: splitColumns(groups),
            viewAll: groups.length > 0 ? { label: labels.viewAll(label.label), path: label.path } : null,
        };
    };

    if (tree.length <= MAX_BAR_ITEMS) return tree.map(toItem);

    const visible = tree.slice(0, MAX_BAR_ITEMS - 1).map(toItem);
    const overflow = tree.slice(MAX_BAR_ITEMS - 1).map((n) => toLink(n, locale));
    visible.push({
        id: 'cat-more',
        label: { label: labels.more, path: '/categories' },
        columns: [[{ heading: { label: labels.allCategories, path: '/categories' }, links: overflow }]],
        viewAll: null,
    });
    return visible;
}

/** Focusable links of a panel per column, in DOM order (view-all, then each group heading + links). */
export function panelColumns(item: MenuItem): MenuLink[][] {
    return item.columns.map((groups, index) => {
        const links = groups.flatMap((g) => [g.heading, ...g.links]);
        return index === 0 && item.viewAll ? [item.viewAll, ...links] : links;
    });
}
