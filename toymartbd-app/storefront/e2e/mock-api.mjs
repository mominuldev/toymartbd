// Tiny stand-in for the Laravel storefront API (node:http, no dependencies).
//   GET  /api/v1/storefront/brands          → {data: Brand[]}
//   GET  /api/v1/storefront/brands/{slug}   → {data: Brand} or 404 problem+json
//   GET  /api/v1/storefront/categories/tree → {data: CategoryNode[]} (fixtures/categories.json:
//        EN + BN names, a null name_bn, a childless L1 and one node deeper than the 3-level contract)
//   GET  /api/v1/storefront/categories/{slug} → StorefrontCategory (breadcrumbs + children from the tree)
//   GET  /api/v1/storefront/products        → P4-CAT-13 listing over fixtures/products.json: the same
//        filter allowlist (unlisted → 400), sorts, disjunctive facets and an opaque cursor — but
//        MOCK_PER_PAGE (4) per page so "load more" is reachable with a dozen fixtures
//   POST /__control/rename {slug, name}     → mutate a brand (for the revalidation test)
//   POST /__control/reset                   → restore fixtures
//   GET  /__control/hits                    → {path: count} of authorised API reads
// Every API read requires `Authorization: Bearer $EXPECTED_TOKEN`, so a page that renders proves
// the server sent the token.
import { readFileSync } from 'node:fs';
import { createServer } from 'node:http';

const port = Number(process.env.MOCK_API_PORT ?? 4010);
const expectedToken = process.env.EXPECTED_TOKEN;
if (!expectedToken) throw new Error('EXPECTED_TOKEN is required');

const fixtures = JSON.parse(readFileSync(new URL('./fixtures/brands.json', import.meta.url), 'utf8')).data;
const categoryTree = JSON.parse(readFileSync(new URL('./fixtures/categories.json', import.meta.url), 'utf8'));
const productFixtures = JSON.parse(readFileSync(new URL('./fixtures/products.json', import.meta.url), 'utf8')).data;
let brands = structuredClone(fixtures);
let hits = {};

const send = (res, status, body, type = 'application/json') => {
    res.writeHead(status, { 'Content-Type': type });
    res.end(JSON.stringify(body));
};
const problem = (res, status, title, detail) =>
    send(res, status, { type: `https://toymartbd.com/problems/${status === 404 ? 'not-found' : 'unauthenticated'}`, title, status, detail }, 'application/problem+json');

const readJson = (req) =>
    new Promise((resolve) => {
        let raw = '';
        req.on('data', (c) => (raw += c));
        req.on('end', () => resolve(raw ? JSON.parse(raw) : {}));
    });

// ---- Catalog listing (mirrors app/Domain/Catalog/Services/ProductListing.php) -------------------

const MOCK_PER_PAGE = 4;
const AGE_BANDS = [['0-12', 0, 12], ['12-24', 12, 24], ['24-48', 24, 48], ['48-72', 48, 72], ['72-96', 72, 96], ['96-144', 96, 144], ['144-', 144, null]];
const PRICE_BANDS = [['0-500', 0, 500], ['500-1000', 500, 1000], ['1000-2500', 1000, 2500], ['2500-5000', 2500, 5000], ['5000-', 5000, null]];
const FILTERS = ['category', 'brand', 'age', 'age_band', 'price_min', 'price_max', 'price_band', 'gender', 'rating', 'educational'];
const SORTS = ['featured', 'newest', 'price_asc', 'price_desc', 'rating', 'name'];
const badRequest = (res, detail) =>
    send(res, 400, { type: 'https://toymartbd.com/problems/bad-request', title: 'Bad Request', status: 400, detail }, 'application/problem+json');

function findCategory(nodes, slug, trail) {
    for (const node of nodes) {
        if (node.slug === slug) return { node, trail };
        const inner = findCategory(node.children, slug, [...trail, node]);
        if (inner) return inner;
    }
    return null;
}
const subtreeSlugs = (node) => [node.slug, ...node.children.flatMap(subtreeSlugs)];
const inBand = (p, [, lo, hi]) => (p.age_max_months === null || p.age_max_months >= lo) && (hi === null || p.age_min_months === null || p.age_min_months < hi);
const inPrice = (p, [, lo, hi]) => Number(p.price) >= lo && (hi === null || Number(p.price) < hi);

function listProducts(res, query) {
    const filter = {};
    for (const [key, value] of query) {
        const m = key.match(/^filter\[([a-z_]+)\]$/);
        if (m) {
            if (!FILTERS.includes(m[1])) return badRequest(res, `Unlisted filter(s): ${m[1]}.`);
            filter[m[1]] = value;
        } else if (!['sort', 'cursor'].includes(key)) return badRequest(res, `Unknown query parameter(s): ${key}.`);
    }
    const sort = query.get('sort') ?? 'featured';
    if (!SORTS.includes(sort)) return badRequest(res, 'sort must be one of: ' + SORTS.join(', '));
    const list = (k) => (filter[k] ? filter[k].split(',') : []);
    const category = filter.category ? findCategory(categoryTree.data, filter.category, []) : null;
    const catSlugs = category ? subtreeSlugs(category.node) : null;

    const tests = {
        category: (p) => !filter.category || (catSlugs !== null && p._categories.some((c) => catSlugs.includes(c))),
        brand: (p) => !filter.brand || (p.brand !== null && list('brand').includes(p.brand.slug)),
        age: (p) => !filter.age_band || AGE_BANDS.filter(([v]) => list('age_band').includes(v)).some((b) => inBand(p, b)),
        price: (p) => !filter.price_band || PRICE_BANDS.filter(([v]) => list('price_band').includes(v)).some((b) => inPrice(p, b)),
        gender: (p) => !filter.gender || list('gender').some((g) => p._gender === g || (g === 'unisex' && p._gender === null)),
    };
    const matching = (except) => productFixtures.filter((p) => Object.entries(tests).every(([k, t]) => k === except || t(p)));

    const results = matching(null);
    const by = {
        featured: (a, b) => Number(b.is_featured) - Number(a.is_featured) || b._published_at.localeCompare(a._published_at) || b.id - a.id,
        newest: (a, b) => b._published_at.localeCompare(a._published_at) || b.id - a.id,
        price_asc: (a, b) => Number(a.price) - Number(b.price) || a.id - b.id,
        price_desc: (a, b) => Number(b.price) - Number(a.price) || b.id - a.id,
        rating: (a, b) => b.id - a.id,
        name: (a, b) => a.name.localeCompare(b.name) || a.id - b.id,
    }[sort];
    results.sort(by);

    const cursor = query.get('cursor');
    let offset = 0;
    if (cursor) {
        try {
            offset = JSON.parse(Buffer.from(cursor, 'base64url').toString()).offset ?? 0;
        } catch {
            return badRequest(res, 'bad cursor');
        }
    }
    const page = results.slice(offset, offset + MOCK_PER_PAGE);
    const next = offset + MOCK_PER_PAGE < results.length ? Buffer.from(JSON.stringify({ offset: offset + MOCK_PER_PAGE })).toString('base64url') : null;

    const selectedBrands = list('brand');
    const brandCounts = new Map();
    for (const p of matching('brand')) if (p.brand) brandCounts.set(p.brand.slug, { brand: p.brand, count: (brandCounts.get(p.brand.slug)?.count ?? 0) + 1 });
    const children = category ? category.node.children : categoryTree.data;
    const forCategory = matching('category');
    const card = ({ _categories, _gender, _published_at, ...rest }) => rest;

    return send(res, 200, {
        data: page.map(card),
        links: { first: null, last: null, prev: null, next: null },
        meta: {
            path: '/api/v1/storefront/products',
            per_page: MOCK_PER_PAGE,
            next_cursor: next,
            prev_cursor: null,
            total: results.length,
            sort,
            facets: {
                category: children.map((c) => {
                    const slugs = subtreeSlugs(c);
                    return { value: c.slug, label: c.name, label_bn: c.name_bn, count: forCategory.filter((p) => p._categories.some((s) => slugs.includes(s))).length, selected: false };
                }),
                brand: [...brandCounts.values()]
                    .sort((a, b) => a.brand.name.localeCompare(b.brand.name))
                    .map(({ brand, count }) => ({ value: brand.slug, label: brand.name, label_bn: brand.name_bn, count, selected: selectedBrands.includes(brand.slug) })),
                age_band: AGE_BANDS.map((b) => ({ value: b[0], min_months: b[1], max_months: b[2], count: matching('age').filter((p) => inBand(p, b)).length, selected: list('age_band').includes(b[0]) })),
                price_band: PRICE_BANDS.map((b) => ({
                    value: b[0], min: `${b[1]}.00`, max: b[2] === null ? null : `${b[2]}.00`,
                    count: matching('price').filter((p) => inPrice(p, b)).length, selected: list('price_band').includes(b[0]),
                })),
                gender: ['unisex', 'boys', 'girls'].map((g) => ({
                    value: g, count: matching('gender').filter((p) => p._gender === g || (g === 'unisex' && p._gender === null)).length, selected: list('gender').includes(g),
                })),
                rating: [4, 3, 2, 1].map((r) => ({ value: r, count: 0, selected: false })),
                educational: { count: 0, selected: false },
            },
        },
    });
}

createServer(async (req, res) => {
    const url = new URL(req.url ?? '/', `http://127.0.0.1:${port}`);

    if (url.pathname.startsWith('/__control/')) {
        if (url.pathname === '/__control/hits') return send(res, 200, hits);
        if (url.pathname === '/__control/reset') {
            brands = structuredClone(fixtures);
            hits = {};
            return send(res, 200, { ok: true });
        }
        if (url.pathname === '/__control/rename' && req.method === 'POST') {
            const { slug, name } = await readJson(req);
            const brand = brands.find((b) => b.slug === slug);
            if (!brand) return problem(res, 404, 'Not Found', slug);
            brand.name = name;
            return send(res, 200, brand);
        }
        return problem(res, 404, 'Not Found', url.pathname);
    }

    if (req.headers.authorization !== `Bearer ${expectedToken}`) {
        return problem(res, 401, 'Unauthenticated', 'Missing or wrong storefront token.');
    }
    hits[url.pathname] = (hits[url.pathname] ?? 0) + 1;

    if (url.pathname === '/api/v1/storefront/brands') return send(res, 200, { data: brands });
    if (url.pathname === '/api/v1/storefront/categories/tree') return send(res, 200, categoryTree);
    if (url.pathname === '/api/v1/storefront/products') return listProducts(res, url.searchParams);
    const category = url.pathname.match(/^\/api\/v1\/storefront\/categories\/([^/]+)$/);
    if (category) {
        const found = findCategory(categoryTree.data, decodeURIComponent(category[1]), []);
        if (!found) return problem(res, 404, 'Not Found', `No category "${category[1]}".`);
        const { node, trail } = found;
        const crumb = (n) => ({ name: n.name, name_bn: n.name_bn, slug: n.slug });
        return send(res, 200, {
            id: node.id, name: node.name, name_bn: node.name_bn, slug: node.slug,
            description: node.slug === 'building-blocks' ? 'LEGO, DUPLO and wooden blocks for ages 1 to 12.' : null,
            description_bn: node.slug === 'building-blocks' ? '১ থেকে ১২ বছর বয়সের জন্য লেগো, ডুপ্লো আর কাঠের ব্লক।' : null,
            icon: null, image_url: null, banner_url: null,
            breadcrumbs: [...trail, node].map(crumb),
            children: node.children,
        });
    }
    const match = url.pathname.match(/^\/api\/v1\/storefront\/brands\/([^/]+)$/);
    if (match) {
        const brand = brands.find((b) => b.slug === decodeURIComponent(match[1]));
        return brand ? send(res, 200, { data: brand }) : problem(res, 404, 'Not Found', `No brand "${match[1]}".`);
    }
    return problem(res, 404, 'Not Found', url.pathname);
}).listen(port, '127.0.0.1', () => console.log(`mock API on http://127.0.0.1:${port}`));
