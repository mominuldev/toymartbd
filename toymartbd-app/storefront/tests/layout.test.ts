import { readFileSync } from 'node:fs';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    buildMenu,
    categoryName,
    findCategory,
    normalizeTree,
    panelColumns,
    splitColumns,
    treeDepth,
    type CategoryNode,
} from '@/lib/category-tree';
import { getCategoryTree } from '@/lib/categories';
import { splitLocalePath, switchLocalePath } from '@/lib/i18n';
import { requestContext } from '@/lib/locale-route';
import { activeTab, cartBadge } from '@/lib/navigation';
import { contact } from '@/lib/site-config';

const fixture: unknown = JSON.parse(readFileSync(new URL('../e2e/fixtures/categories.json', import.meta.url), 'utf8'));

const labels = { viewAll: (c: string) => `All in ${c}`, more: 'More', allCategories: 'All categories' };

function node(id: number, slug: string, children: CategoryNode[] = [], name_bn: string | null = `বাং-${slug}`): CategoryNode {
    return { id, name: slug.toUpperCase(), name_bn, slug, icon: null, image_url: null, children };
}

describe('category tree: depth and shape', () => {
    it('cuts anything deeper than the 3-level contract', () => {
        expect(treeDepth(normalizeTree(fixture))).toBe(3);
        expect(JSON.stringify(normalizeTree(fixture))).not.toContain('too-deep-level-4');
        expect(treeDepth(normalizeTree(fixture, 2))).toBe(2);
    });

    it('drops malformed nodes (with their subtree) instead of failing the header', () => {
        const tree = normalizeTree({
            data: [
                { id: 1, name: 'Good', name_bn: '', slug: 'good', children: [{ id: 2, name: 'No slug' }] },
                { id: 3, name: 'Bangla slug', slug: 'খেলনা' },
                { id: '4', name: 'String id', slug: 'string-id' },
                null,
                { id: 5, name: '  ', slug: 'blank-name' },
            ],
        });
        expect(tree).toEqual([{ id: 1, name: 'Good', name_bn: null, slug: 'good', icon: null, image_url: null, children: [] }]);
    });

    it.each([null, 'oops', {}, { data: 'x' }, []])('an unusable body (%j) is an empty tree', (body) => {
        expect(normalizeTree(body)).toEqual([]);
    });

    it('localises names with an English fallback', () => {
        const tree = normalizeTree(fixture);
        expect(categoryName(tree[0]!, 'bn')).toBe('বিল্ডিং ব্লক');
        const science = tree.find((n) => n.slug === 'science-learning')!;
        expect(categoryName(science, 'bn')).toBe('Science & Learning');
    });

    it('finds a node with its ancestors', () => {
        const found = findCategory(normalizeTree(fixture), 'lego-city');
        expect(found?.node.name).toBe('LEGO City');
        expect(found?.trail.map((n) => n.slug)).toEqual(['building-blocks', 'lego-sets']);
        expect(findCategory(normalizeTree(fixture), 'nope')).toBeNull();
    });
});

describe('mega menu view model', () => {
    it('one item per L1, L2 groups in ≤3 columns, L3 links under each group', () => {
        const menu = buildMenu(normalizeTree(fixture), 'en', labels);
        const blocks = menu[0]!;
        expect(blocks.label).toEqual({ label: 'Building Blocks', path: '/categories/building-blocks' });
        expect(blocks.columns.length).toBeLessThanOrEqual(3);
        expect(blocks.columns.flat().map((g) => g.heading.label)).toEqual(['LEGO', 'DUPLO', 'Wooden Blocks', 'Magnetic Tiles']);
        expect(blocks.columns.flat()[0]!.links.map((l) => l.path)).toEqual([
            '/categories/lego-classic',
            '/categories/lego-city',
            '/categories/lego-technic',
        ]);
        expect(blocks.viewAll).toEqual({ label: 'All in Building Blocks', path: '/categories/building-blocks' });
    });

    it('a childless L1 is a plain link: no columns, no view-all', () => {
        const art = buildMenu(normalizeTree(fixture).filter((n) => n.slug === 'art-craft'), 'en', labels).find((i) => i.label.label === 'Art & Craft');
        expect(art?.columns).toEqual([]);
        expect(art?.viewAll).toBeNull();
    });

    it('marks English fallbacks on a Bangla page', () => {
        const menu = buildMenu(normalizeTree(fixture), 'bn', labels);
        const links = menu.flatMap((i) => [i.label, ...panelColumns(i).flat()]);
        expect(links.find((l) => l.label === 'LEGO Technic')?.lang).toBe('en');
        expect(links.find((l) => l.label === 'লেগো ক্লাসিক')?.lang).toBeUndefined();
    });

    it('collapses overflow into a final "More" item instead of wrapping the bar', () => {
        const tree = Array.from({ length: 10 }, (_, i) => node(i + 1, `cat-${i + 1}`));
        const menu = buildMenu(tree, 'en', labels);
        expect(menu).toHaveLength(7);
        const more = menu[6]!;
        expect(more.label.label).toBe('More');
        expect(panelColumns(more)[0]!.map((l) => l.path)).toEqual([
            '/categories',
            '/categories/cat-7',
            '/categories/cat-8',
            '/categories/cat-9',
            '/categories/cat-10',
        ]);
        expect(buildMenu(tree.slice(0, 7), 'en', labels)).toHaveLength(7);
    });

    it('panel columns are in DOM (= Tab) order: view-all, then heading + links per group', () => {
        const item = buildMenu([node(1, 'a', [node(2, 'b', [node(3, 'c')]), node(4, 'd')])], 'en', labels)[0]!;
        expect(panelColumns(item).map((c) => c.map((l) => l.path))).toEqual([
            ['/categories/a', '/categories/b', '/categories/c'],
            ['/categories/d'],
        ]);
    });

    it('splits groups top-to-bottom into at most three columns', () => {
        expect(splitColumns([1, 2, 3, 4, 5])).toEqual([[1, 2], [3, 4], [5]]);
        expect(splitColumns([1])).toEqual([[1]]);
        expect(splitColumns([])).toEqual([]);
    });
});

describe('menu data: API failure never breaks the layout', () => {
    const fetchMock = vi.fn<typeof fetch>();

    beforeEach(() => {
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
        vi.stubEnv('API_URL', 'https://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'tok_123');
        vi.spyOn(console, 'error').mockImplementation(() => undefined);
    });

    it('fetches the tree tagged `categories`', async () => {
        fetchMock.mockResolvedValue(new Response(JSON.stringify(fixture), { headers: { 'Content-Type': 'application/json' } }));
        const tree = await getCategoryTree();
        expect(tree).toHaveLength(8);
        const [url, init] = fetchMock.mock.calls[0]!;
        expect(url).toBe('https://api.test/api/v1/storefront/categories/tree');
        expect((init as { next: { tags: string[] } }).next.tags).toEqual(['categories']);
    });

    it.each([
        ['a 500', () => fetchMock.mockResolvedValue(new Response('{}', { status: 500, headers: { 'Content-Type': 'application/json' } }))],
        ['an unreachable API', () => fetchMock.mockRejectedValue(new TypeError('fetch failed'))],
        ['a non-JSON body', () => fetchMock.mockResolvedValue(new Response('<html>', { status: 200 }))],
        ['a missing token', () => vi.stubEnv('STOREFRONT_API_TOKEN', '')],
    ])('%s yields an empty menu, not an exception', async (_label, arrange) => {
        arrange();
        await expect(getCategoryTree()).resolves.toEqual([]);
        expect(console.error).toHaveBeenCalled();
    });
});

describe('locale path switching', () => {
    it.each([
        ['/', 'en', '/'],
        ['/brands/lego', 'en', '/brands/lego'],
        ['/bn', 'bn', '/'],
        ['/bn/', 'bn', '/'],
        ['/bn/brands/lego', 'bn', '/brands/lego'],
        ['/bnx', 'en', '/bnx'],
        ['/bn?q=x', 'bn', '/?q=x'],
    ])('%s → locale %s, path %s', (pathname, locale, path) => {
        expect(splitLocalePath(pathname)).toEqual({ locale, path });
    });

    it('switches a page to the same path in the other locale', () => {
        expect(switchLocalePath('/brands/lego', 'bn')).toBe('/bn/brands/lego');
        expect(switchLocalePath('/bn/brands/lego', 'en')).toBe('/brands/lego');
        expect(switchLocalePath('/bn', 'en')).toBe('/');
        expect(switchLocalePath('/', 'bn')).toBe('/bn');
        expect(switchLocalePath('/search?q=lego', 'bn')).toBe('/bn/search?q=lego');
    });

    it('treats the proxy headers as untrusted', () => {
        expect(requestContext('bn', '/bn/no-such-page')).toEqual({ locale: 'bn', path: '/no-such-page' });
        expect(requestContext('en', '/x/y')).toEqual({ locale: 'en', path: '/x/y' });
        expect(requestContext('fr', '/x')).toEqual({ locale: 'en', path: '/x' });
        expect(requestContext(null, null)).toEqual({ locale: 'en', path: '/' });
        expect(requestContext('en', '//evil.example/')).toEqual({ locale: 'en', path: '/' });
        expect(requestContext('en', 'https://evil.example/')).toEqual({ locale: 'en', path: '/' });
        expect(requestContext('en', '/a\\b')).toEqual({ locale: 'en', path: '/' });
    });
});

describe('mobile tab bar', () => {
    it.each([
        ['/', 'home'],
        ['/categories', 'categories'],
        ['/categories/lego-city', 'categories'],
        ['/search?q=lego', 'search'],
        ['/cart', 'cart'],
        ['/account/orders', 'account'],
        ['/brands', null],
        ['/cartoons', null],
    ])('%s highlights %s', (path, tab) => {
        expect(activeTab(path)).toBe(tab);
    });

    it('hides the cart badge at 0 and caps it at 99+', () => {
        expect(cartBadge(0)).toBeNull();
        expect(cartBadge(-1)).toBeNull();
        expect(cartBadge(3)).toBe('3');
        expect(cartBadge(120)).toBe('99+');
    });
});

describe('contact placeholders', () => {
    it('every contact value is either confirmed or flagged as a placeholder, in Western digits', () => {
        for (const [name, value] of Object.entries(contact)) {
            expect(typeof value.placeholder, name).toBe('boolean');
            expect(/[০-৯]/.test(value.display), name).toBe(false);
        }
    });
});
