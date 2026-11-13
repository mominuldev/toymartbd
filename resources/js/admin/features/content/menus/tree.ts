/**
 * The menu editor's tree, as pure functions: every operation returns a new tree. Depth limits
 * come from the API (`max_depth`: header 3, footer and mobile 2); an operation that would break
 * one is refused (returns the same tree), so the UI can disable the control up front.
 */

export type LinkType = 'category' | 'brand' | 'page' | 'post' | 'product' | 'custom' | 'category_tree' | 'none';
export const linkTypes: readonly LinkType[] = ['category', 'brand', 'page', 'post', 'product', 'custom', 'category_tree', 'none'];
export const targetTypes: readonly LinkType[] = ['category', 'brand', 'page', 'post', 'product'];

export type MenuTarget = { label: string; label_bn: string | null; path: string; live: boolean };

export type MenuNode = {
    /** Client-only identity; the API replaces ids on every save. */
    key: string;
    label: string;
    label_bn: string | null;
    link_type: LinkType;
    link_id: number | null;
    url: string | null;
    is_visible: boolean;
    open_in_new_tab: boolean;
    target: MenuTarget | null;
    children: MenuNode[];
};

/** The API's admin item shape. */
export type ApiMenuItem = Omit<MenuNode, 'key' | 'children' | 'link_type'> & { id: number; link_type: string; children: unknown[] };

/** The PUT body's item shape. */
export type MenuItemPayload = {
    label: string;
    label_bn: string | null;
    link_type: LinkType;
    link_id: number | null;
    url: string | null;
    is_visible: boolean;
    open_in_new_tab: boolean;
    children: MenuItemPayload[];
};

let counter = 0;
export function newKey(): string {
    counter += 1;
    return `n${counter}`;
}

export function isTargetType(type: LinkType): boolean {
    return targetTypes.includes(type);
}

export function fromApi(items: readonly ApiMenuItem[]): MenuNode[] {
    return items.map((item) => ({
        key: `i${item.id}`,
        label: item.label,
        label_bn: item.label_bn,
        link_type: (linkTypes as readonly string[]).includes(item.link_type) ? (item.link_type as LinkType) : 'none',
        link_id: item.link_id,
        url: item.url,
        is_visible: item.is_visible,
        open_in_new_tab: item.open_in_new_tab,
        target: item.target,
        children: fromApi(item.children as ApiMenuItem[]),
    }));
}

export function toPayload(nodes: readonly MenuNode[]): MenuItemPayload[] {
    return nodes.map((node) => ({
        label: node.label.trim(),
        label_bn: node.label_bn && node.label_bn.trim() !== '' ? node.label_bn.trim() : null,
        link_type: node.link_type,
        link_id: isTargetType(node.link_type) ? node.link_id : null,
        url: node.link_type === 'custom' ? (node.url?.trim() ?? null) : null,
        is_visible: node.is_visible,
        open_in_new_tab: node.open_in_new_tab,
        children: toPayload(node.children),
    }));
}

export function emptyNode(): MenuNode {
    return { key: newKey(), label: '', label_bn: null, link_type: 'custom', link_id: null, url: '', is_visible: true, open_in_new_tab: false, target: null, children: [] };
}

/** Every node with its depth (1 = top level), in display order. */
export function flatten(nodes: readonly MenuNode[], depth = 1): { node: MenuNode; depth: number }[] {
    return nodes.flatMap((node) => [{ node, depth }, ...flatten(node.children, depth + 1)]);
}

export function countItems(nodes: readonly MenuNode[]): number {
    return flatten(nodes).length;
}

/** Levels in a subtree: a leaf is 1. */
export function heightOf(node: MenuNode): number {
    return 1 + Math.max(0, ...node.children.map(heightOf));
}

/** Index path to a node, or null. */
export function pathOf(nodes: readonly MenuNode[], key: string, prefix: number[] = []): number[] | null {
    for (let i = 0; i < nodes.length; i += 1) {
        const node = nodes[i]!;
        if (node.key === key) return [...prefix, i];
        const found = pathOf(node.children, key, [...prefix, i]);
        if (found) return found;
    }
    return null;
}

export function findNode(nodes: readonly MenuNode[], key: string): MenuNode | null {
    return flatten(nodes).find((entry) => entry.node.key === key)?.node ?? null;
}

function siblingsAt(nodes: MenuNode[], parentPath: number[]): MenuNode[] {
    let list = nodes;
    for (const index of parentPath) list = list[index]!.children;
    return list;
}

/** Deep-copies the spine so a mutation on the copy never touches the original. */
function clone(nodes: readonly MenuNode[]): MenuNode[] {
    return nodes.map((node) => ({ ...node, children: clone(node.children) }));
}

export function updateNode(nodes: readonly MenuNode[], key: string, patch: Partial<Omit<MenuNode, 'key' | 'children'>>): MenuNode[] {
    return nodes.map((node) => (node.key === key ? { ...node, ...patch } : { ...node, children: updateNode(node.children, key, patch) }));
}

export function removeNode(nodes: readonly MenuNode[], key: string): MenuNode[] {
    return nodes.filter((node) => node.key !== key).map((node) => ({ ...node, children: removeNode(node.children, key) }));
}

/** Append a node at the top level, or as the last child of `parentKey` when depth allows. */
export function addNode(nodes: readonly MenuNode[], node: MenuNode, parentKey: string | null, maxDepth: number): MenuNode[] {
    if (parentKey === null) return [...nodes, node];
    const path = pathOf(nodes, parentKey);
    if (!path || path.length + heightOf(node) > maxDepth) return [...nodes];
    const next = clone(nodes);
    siblingsAt(next, path).push(node);
    return next;
}

export function canMoveUp(nodes: readonly MenuNode[], key: string): boolean {
    const path = pathOf(nodes, key);
    return path !== null && path[path.length - 1]! > 0;
}

export function canMoveDown(nodes: readonly MenuNode[], key: string): boolean {
    const path = pathOf(nodes, key);
    if (!path) return false;
    const siblings = siblingsAt(nodes as MenuNode[], path.slice(0, -1));
    return path[path.length - 1]! < siblings.length - 1;
}

/** Swap with the previous (-1) or next (+1) sibling. */
export function moveSibling(nodes: readonly MenuNode[], key: string, delta: -1 | 1): MenuNode[] {
    const path = pathOf(nodes, key);
    if (!path) return [...nodes];
    const next = clone(nodes);
    const siblings = siblingsAt(next, path.slice(0, -1));
    const from = path[path.length - 1]!;
    const to = from + delta;
    if (to < 0 || to >= siblings.length) return [...nodes];
    const [moved] = siblings.splice(from, 1);
    siblings.splice(to, 0, moved!);
    return next;
}

/** Can the node become the last child of its previous sibling without passing maxDepth? */
export function canIndent(nodes: readonly MenuNode[], key: string, maxDepth: number): boolean {
    const path = pathOf(nodes, key);
    if (!path || path[path.length - 1] === 0) return false;
    const node = findNode(nodes, key)!;
    if (node.link_type === 'category_tree') return false;
    return path.length + heightOf(node) <= maxDepth;
}

export function indent(nodes: readonly MenuNode[], key: string, maxDepth: number): MenuNode[] {
    if (!canIndent(nodes, key, maxDepth)) return [...nodes];
    const path = pathOf(nodes, key)!;
    const next = clone(nodes);
    const siblings = siblingsAt(next, path.slice(0, -1));
    const index = path[path.length - 1]!;
    const [moved] = siblings.splice(index, 1);
    siblings[index - 1]!.children.push(moved!);
    return next;
}

export function canOutdent(nodes: readonly MenuNode[], key: string): boolean {
    const path = pathOf(nodes, key);
    return path !== null && path.length > 1;
}

/** Become the next sibling of the current parent. */
export function outdent(nodes: readonly MenuNode[], key: string): MenuNode[] {
    if (!canOutdent(nodes, key)) return [...nodes];
    const path = pathOf(nodes, key)!;
    const next = clone(nodes);
    const parentPath = path.slice(0, -1);
    const siblings = siblingsAt(next, parentPath);
    const [moved] = siblings.splice(path[path.length - 1]!, 1);
    const grand = siblingsAt(next, parentPath.slice(0, -1));
    grand.splice(parentPath[parentPath.length - 1]! + 1, 0, moved!);
    return next;
}

/**
 * Drag and drop: put `dragKey` right before or after `targetKey`, at the target's level. Refused
 * when the node would land inside itself or pass the depth limit, or a category menu would nest.
 */
export function moveBeside(nodes: readonly MenuNode[], dragKey: string, targetKey: string, where: 'before' | 'after', maxDepth: number): MenuNode[] {
    if (dragKey === targetKey) return [...nodes];
    const dragged = findNode(nodes, dragKey);
    if (!dragged || findNode(dragged.children, targetKey)) return [...nodes];
    const targetPath = pathOf(nodes, targetKey);
    if (!targetPath) return [...nodes];
    if (targetPath.length - 1 + heightOf(dragged) > maxDepth) return [...nodes];
    if (dragged.link_type === 'category_tree' && targetPath.length > 1) return [...nodes];

    const without = removeNode(nodes, dragKey);
    const path = pathOf(without, targetKey)!;
    const next = clone(without);
    const siblings = siblingsAt(next, path.slice(0, -1));
    const index = path[path.length - 1]! + (where === 'after' ? 1 : 0);
    siblings.splice(index, 0, { ...dragged, children: clone(dragged.children) });
    return next;
}

export type NodeError = 'label' | 'target' | 'url' | 'treeTop';

/** The same checks the API makes, so a bad tree is flagged before Save. */
export function validate(nodes: readonly MenuNode[], isValidUrl: (url: string) => boolean): Map<string, NodeError> {
    const errors = new Map<string, NodeError>();
    for (const { node, depth } of flatten(nodes)) {
        if (node.label.trim() === '') errors.set(node.key, 'label');
        else if (isTargetType(node.link_type) && node.link_id === null) errors.set(node.key, 'target');
        else if (node.link_type === 'custom' && !isValidUrl(node.url ?? '')) errors.set(node.key, 'url');
        else if (node.link_type === 'category_tree' && depth !== 1) errors.set(node.key, 'treeTop');
    }
    return errors;
}
