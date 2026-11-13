import { describe, expect, it } from 'vitest';
import { isValidLink } from '../shared/links';
import {
    addNode,
    canIndent,
    canMoveDown,
    canMoveUp,
    canOutdent,
    countItems,
    flatten,
    indent,
    moveBeside,
    moveSibling,
    outdent,
    removeNode,
    toPayload,
    validate,
    type MenuNode,
} from './tree';

function n(key: string, children: MenuNode[] = [], patch: Partial<MenuNode> = {}): MenuNode {
    return { key, label: key, label_bn: null, link_type: 'custom', link_id: null, url: `/${key}`, is_visible: true, open_in_new_tab: false, target: null, children, ...patch };
}

const labels = (tree: MenuNode[]) => flatten(tree).map(({ node, depth }) => `${'-'.repeat(depth - 1)}${node.key}`);

describe('menu tree', () => {
    const tree = [n('a', [n('a1'), n('a2')]), n('b'), n('c')];

    it('moves siblings up and down and knows the ends', () => {
        expect(labels(moveSibling(tree, 'b', -1))).toEqual(['b', 'a', '-a1', '-a2', 'c']);
        expect(labels(moveSibling(tree, 'a2', -1))).toEqual(['a', '-a2', '-a1', 'b', 'c']);
        expect(canMoveUp(tree, 'a')).toBe(false);
        expect(canMoveDown(tree, 'c')).toBe(false);
        expect(canMoveDown(tree, 'a1')).toBe(true);
        // The original is never mutated.
        expect(labels(tree)).toEqual(['a', '-a1', '-a2', 'b', 'c']);
    });

    it('indents under the previous sibling and outdents after the parent', () => {
        expect(labels(indent(tree, 'b', 3))).toEqual(['a', '-a1', '-a2', '-b', 'c']);
        expect(labels(outdent(tree, 'a1'))).toEqual(['a', '-a2', 'a1', 'b', 'c']);
        expect(canIndent(tree, 'a', 3)).toBe(false);
        expect(canOutdent(tree, 'b')).toBe(false);
    });

    it('refuses to pass the location depth limit', () => {
        // Footer/mobile: 2 levels. a2 under a1 would be level 3.
        expect(canIndent(tree, 'a2', 2)).toBe(false);
        expect(indent(tree, 'a2', 2)).toEqual(tree);
        expect(canIndent(tree, 'a2', 3)).toBe(true);
        // Moving a parent with children under b would make 3 levels.
        const deep = [n('x'), n('y', [n('y1')])];
        expect(canIndent(deep, 'y', 2)).toBe(false);
        expect(addNode(tree, n('new'), 'a1', 2)).toEqual(tree);
        expect(labels(addNode(tree, n('new'), 'a1', 3))).toContain('--new');
    });

    it('drags beside a target at its level, never into itself or past the limit', () => {
        expect(labels(moveBeside(tree, 'c', 'a1', 'before', 3))).toEqual(['a', '-c', '-a1', '-a2', 'b']);
        expect(labels(moveBeside(tree, 'b', 'a', 'before', 3))).toEqual(['b', 'a', '-a1', '-a2', 'c']);
        expect(moveBeside(tree, 'a', 'a1', 'after', 3)).toEqual(tree);
        expect(moveBeside([n('p', [n('q')]), n('r', [n('s')])], 'r', 'q', 'after', 2)).toEqual([n('p', [n('q')]), n('r', [n('s')])]);
        expect(moveBeside(tree, 'b', 'a1', 'after', 3).length).toBe(2);
    });

    it('keeps the category mega menu at the top level', () => {
        const withTree = [n('shop'), n('all', [], { link_type: 'category_tree', url: null })];
        expect(canIndent(withTree, 'all', 3)).toBe(false);
        expect(validate([n('h', [n('all', [], { link_type: 'category_tree', url: null })])], isValidLink).get('all')).toBe('treeTop');
    });

    it('validates labels, targets and URLs like the API', () => {
        const errors = validate([n('ok'), n('nolabel', [], { label: ' ' }), n('page', [], { link_type: 'page', url: null }), n('bad', [], { url: 'javascript:alert(1)' }), n('proto', [], { url: '//evil.test' })], isValidLink);
        expect(Object.fromEntries(errors)).toEqual({ nolabel: 'label', page: 'target', bad: 'url', proto: 'url' });
    });

    it('builds the PUT payload: links only where the type takes them', () => {
        const payload = toPayload([n('h', [n('p', [], { link_type: 'page', link_id: 7, url: '/stale', label_bn: ' পাতা ' })], { link_type: 'none', url: '/x' })]);
        expect(payload).toEqual([
            {
                label: 'h',
                label_bn: null,
                link_type: 'none',
                link_id: null,
                url: null,
                is_visible: true,
                open_in_new_tab: false,
                children: [{ label: 'p', label_bn: 'পাতা', link_type: 'page', link_id: 7, url: null, is_visible: true, open_in_new_tab: false, children: [] }],
            },
        ]);
        expect(countItems(removeNode(tree, 'a'))).toBe(2);
    });
});
