// Tiny stand-in for the Laravel storefront API (node:http, no dependencies).
//   GET  /api/v1/storefront/brands          → {data: Brand[]}
//   GET  /api/v1/storefront/brands/{slug}   → {data: Brand} or 404 problem+json
//   GET  /api/v1/storefront/categories/tree → {data: CategoryNode[]} (fixtures/categories.json:
//        EN + BN names, a null name_bn, a childless L1 and one node deeper than the 3-level contract)
//   GET  /api/v1/storefront/categories/{slug} → StorefrontCategory (breadcrumbs + children from the tree)
//   GET  /api/v1/storefront/products        → P4-CAT-13 listing over fixtures/products.json: the same
//        filter allowlist (unlisted → 400), sorts, disjunctive facets and an opaque cursor — but
//        MOCK_PER_PAGE (4) per page so "load more" is reachable with a dozen fixtures
//   GET  /api/v1/storefront/products/{slug} → StorefrontProduct (fixtures/product-detail.json, P4-STO-05;
//        `related` are the listing cards named in its `_related`) or 404 problem+json
//   GET  /api/v1/storefront/search          → P4-CAT-14 search over the same fixtures: `q` matched as a
//        case-insensitive substring of the EN/BN name or brand (plus a tiny synonym table), the
//        listing's filters and facets, `page` paging (MOCK_PER_PAGE), `related_searches` from the
//        top-level categories of the hits; zero hits → `suggestions` + `best_sellers`
//   GET  /api/v1/storefront/search/suggest  → header autocomplete (≤5 products, ≤3 categories, ≤3 brands)
//   GET  /api/v1/storefront/districts, /districts/{id}/upazilas, /delivery-rates, /delivery-quote
//   /api/v1/storefront/cart…, POST /checkout, GET /orders/{number}, POST /orders/track
//        → an in-memory cart, checkout and order store (docs/api/cart.md, docs/api/checkout.md)
//   POST /__control/price {variant_id, price} → change a price (drives the price-changed path)
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
const productDetail = JSON.parse(readFileSync(new URL('./fixtures/product-detail.json', import.meta.url), 'utf8'));
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

function listProducts(res, query, search = null) {
    const filter = {};
    const params = search ? ['q', 'sort', 'page'] : ['sort', 'cursor'];
    for (const [key, value] of query) {
        const m = key.match(/^filter\[([a-z_]+)\]$/);
        if (m) {
            if (!FILTERS.includes(m[1])) return badRequest(res, `Unlisted filter(s): ${m[1]}.`);
            filter[m[1]] = value;
        } else if (!params.includes(key)) return badRequest(res, `Unknown query parameter(s): ${key}.`);
    }
    const sort = query.get('sort') ?? (search ? 'relevance' : 'featured');
    const sorts = search ? ['relevance', ...SORTS] : SORTS;
    if (!sorts.includes(sort)) return badRequest(res, 'sort must be one of: ' + sorts.join(', '));
    const pool = search ? productFixtures.filter((p) => matchesQuery(p, search)) : productFixtures;
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
    const matching = (except) => pool.filter((p) => Object.entries(tests).every(([k, t]) => k === except || t(p)));

    const results = matching(null);
    const by = {
        relevance: (a, b) => a.id - b.id,
        featured: (a, b) => Number(b.is_featured) - Number(a.is_featured) || b._published_at.localeCompare(a._published_at) || b.id - a.id,
        newest: (a, b) => b._published_at.localeCompare(a._published_at) || b.id - a.id,
        price_asc: (a, b) => Number(a.price) - Number(b.price) || a.id - b.id,
        price_desc: (a, b) => Number(b.price) - Number(a.price) || b.id - a.id,
        rating: (a, b) => b.id - a.id,
        name: (a, b) => a.name.localeCompare(b.name) || a.id - b.id,
    }[sort];
    results.sort(by);

    const pageNo = Number(query.get('page') ?? '1');
    const cursor = query.get('cursor');
    let offset = search ? (pageNo - 1) * MOCK_PER_PAGE : 0;
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
    const facets = {
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
            };

    if (search) {
        const related = categoryTree.data
            .map((c) => ({ category: c.slug, label: c.name, label_bn: c.name_bn, count: results.filter((p) => p._categories.some((s) => subtreeSlugs(c).includes(s))).length, selected: filter.category === c.slug }))
            .filter((r) => r.count > 0);
        const zero = results.length === 0;
        const suggestions = zero ? Object.entries(CORRECTIONS).filter(([typo]) => search.toLowerCase().includes(typo)).map(([, fixed]) => ({ query: fixed, count: productFixtures.filter((p) => matchesQuery(p, fixed)).length })) : [];
        const bestSellers = zero ? [...productFixtures].sort((a, b) => Number(b.is_featured) - Number(a.is_featured) || a.id - b.id).slice(0, 4).map(card) : [];
        const last = Math.max(1, Math.ceil(results.length / MOCK_PER_PAGE));
        return send(res, 200, {
            data: page.map(card),
            links: { first: null, last: null, prev: null, next: null },
            meta: {
                current_page: pageNo, from: page.length ? offset + 1 : null, last_page: last, path: '/api/v1/storefront/search', per_page: MOCK_PER_PAGE, to: page.length ? offset + page.length : null, total: results.length,
                query: search, sort, degraded: false, facets, related_searches: related, suggestions, best_sellers: bestSellers,
            },
        });
    }

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
            facets,
        },
    });
}

const card = ({ _categories, _gender, _published_at, ...rest }) => rest;

// Banglish/Bangla → English, and the typo corrections the spelling suggester would offer.
const SYNONYMS = { gari: 'car', 'গাড়ি': 'car', 'লেগো': 'lego' };
const CORRECTIONS = { lgo: 'lego', puzle: 'puzzle', helicoptar: 'car' };
function matchesQuery(p, q) {
    const text = q.trim().toLowerCase();
    const terms = [text, SYNONYMS[text]].filter(Boolean);
    const hay = [p.name, p.name_bn ?? '', p.brand?.name ?? '', p.brand?.name_bn ?? '', ...p._categories].join(' ').toLowerCase();
    return terms.some((t) => hay.includes(t));
}

/** Any other listing fixture as a one-variant product page (recently viewed reads these). */
function simpleDetail(p) {
    const { _related, ...base } = productDetail;
    const variantId = 9000 + p.id;
    return {
        ...base,
        id: p.id, slug: p.slug, name: p.name, name_bn: p.name_bn, brand: p.brand, type: 'simple',
        age_min_months: p.age_min_months, age_max_months: p.age_max_months,
        rating_avg: null, rating_count: 0, in_stock: p.in_stock ?? true, default_variant_id: variantId, images: [], attributes: [],
        variants: [{ id: variantId, sku: `TMB-${p.id}`, name: null, name_bn: null, attribute_value_ids: [], price: p.price, compare_at_price: p.compare_at_price, discount_percent: p.discount_percent, saving: null, weight_grams: 500, is_default: true, status: 'active', available: 5, stock_state: 'in_stock' }],
        related: [],
    };
}

function searchProducts(res, query) {
    const q = (query.get('q') ?? '').trim();
    if (q.length < 1 || q.length > 100) return badRequest(res, 'q must be 1 to 100 characters.');
    const page = query.get('page');
    if (page !== null && !/^[1-9]\d*$/.test(page)) return badRequest(res, 'page must be a whole number.');
    return listProducts(res, query, q);
}

function suggest(res, query) {
    for (const key of query.keys()) if (key !== 'q') return badRequest(res, `Unknown query parameter(s): ${key}.`);
    const q = (query.get('q') ?? '').trim();
    if (q.length < 2 || q.length > 100) return badRequest(res, 'q must be 2 to 100 characters.');
    const hits = productFixtures.filter((p) => matchesQuery(p, q));
    const flat = (nodes) => nodes.flatMap((n) => [n, ...flat(n.children)]);
    const categories = flat(categoryTree.data)
        .map((c) => ({ slug: c.slug, name: c.name, name_bn: c.name_bn, icon: c.icon ?? null, count: hits.filter((p) => p._categories.some((s) => subtreeSlugs(c).includes(s))).length }))
        .filter((c) => c.count > 0)
        .slice(0, 3);
    const brandMap = new Map();
    for (const p of hits) if (p.brand) brandMap.set(p.brand.slug, { ...p.brand, count: (brandMap.get(p.brand.slug)?.count ?? 0) + 1 });
    return send(res, 200, { query: q, products: hits.slice(0, 5).map(card), categories, brands: [...brandMap.values()].slice(0, 3), total: hits.length, degraded: false });
}

// ---- Cart, checkout and orders (docs/api/cart.md, docs/api/checkout.md) ---------------------------
// A small in-memory stand-in: enough pricing to exercise the storefront (it renders these strings
// and never recomputes them). Dhaka (city option, upazilas) ৳70, elsewhere ৳130, free from ৳3000;
// coupon SAVE10 = 10% off, BIG5000 needs ৳5000 of goods. POST /__control/price changes a price so
// the price-changed path can be driven; /__control/reset clears carts and orders.

const DIVISION = { id: 6, name: 'Dhaka', name_bn: 'ঢাকা' };
const DISTRICTS = [
    { id: 13, name: 'Dhaka', name_bn: 'ঢাকা', division: DIVISION, upazila_required: true, city_option: true },
    { id: 20, name: 'Chattogram', name_bn: 'চট্টগ্রাম', division: { id: 2, name: 'Chattogram', name_bn: 'চট্টগ্রাম' }, upazila_required: false, city_option: false },
];
const UPAZILAS = {
    13: [{ id: 101, name: 'Dhanmondi', name_bn: 'ধানমন্ডি', serviceable: true }, { id: 102, name: 'Gulshan', name_bn: 'গুলশান', serviceable: true }],
    20: [{ id: 201, name: 'Kotwali', name_bn: 'কোতোয়ালী', serviceable: true }, { id: 202, name: 'Sandwip', name_bn: 'সন্দ্বীপ', serviceable: false }],
};
const RATES = {
    zones: [
        { zone: 'inside_dhaka_metro', charge: '70.00', min_days: 1, max_days: 2 },
        { zone: 'dhaka_suburb', charge: '100.00', min_days: 2, max_days: 3 },
        { zone: 'outside_dhaka', charge: '130.00', min_days: 2, max_days: 4 },
    ],
    free_shipping_threshold: '3000.00', included_weight_grams: 1000, per_kg_charge: '20.00', cutoff_time: '17:00', timezone: 'Asia/Dhaka',
};

let priceOverrides = {};
let carts = new Map();
let orders = new Map();
let idempotency = new Map();
let orderSeq = 41;

const money = (n) => (Math.round(n * 100) / 100).toFixed(2);
const uuid = () => crypto.randomUUID();
const bnDigits = (s) => String(s).replace(/[০-৯]/g, (d) => String('০১২৩৪৫৬৭৮৯'.indexOf(d)));

function variantInfo(variantId) {
    const v = productDetail.variants.find((x) => x.id === variantId);
    if (v) return { product: productDetail, variant: v, price: priceOverrides[variantId] ?? v.price, available: v.available };
    const p = productFixtures.find((x) => 9000 + x.id === variantId);
    if (p) return { product: p, variant: { sku: `TMB-${p.id}`, name: null, name_bn: null }, price: priceOverrides[variantId] ?? p.price, available: p.in_stock === false ? 0 : 5 };
    return problemOf(res, 404, 'not-found', 'Not Found', p);
}

function quote(districtId, upazilaId, subtotal) {
    const district = DISTRICTS.find((d) => d.id === districtId);
    if (!district) return null;
    const upazila = (UPAZILAS[districtId] ?? []).find((u) => u.id === upazilaId) ?? null;
    const serviceable = upazila ? upazila.serviceable : true;
    const zone = districtId === 13 ? 'inside_dhaka_metro' : 'outside_dhaka';
    const standard = zone === 'inside_dhaka_metro' ? 70 : 130;
    const free = subtotal >= 3000;
    const charge = serviceable ? (free ? 0 : standard) : null;
    return {
        serviceable, zone: serviceable ? zone : null,
        district: { id: district.id, name: district.name, name_bn: district.name_bn },
        upazila: upazila ? { id: upazila.id, name: upazila.name, name_bn: upazila.name_bn } : null,
        upazila_required: false,
        courier: serviceable ? { code: 'steadfast', name: 'Steadfast', name_bn: 'স্টেডফাস্ট' } : null,
        subtotal: money(subtotal), weight_grams: 1000,
        base_charge: serviceable ? money(standard) : null, weight_surcharge: serviceable ? '0.00' : null,
        charge: charge === null ? null : money(charge), standard_charge: serviceable ? money(standard) : null,
        free_shipping: { applied: serviceable && free, reason: serviceable && free ? 'threshold' : null, threshold: '3000.00', remaining: free ? '0.00' : money(3000 - subtotal), progress_percent: Math.min(100, Math.floor((subtotal / 3000) * 100)) },
        eta: serviceable ? { dispatch_date: '2026-11-12', earliest: '2026-11-13', latest: zone === 'inside_dhaka_metro' ? '2026-11-14' : '2026-11-16', min_days: 1, max_days: zone === 'inside_dhaka_metro' ? 2 : 4, cutoff_time: '17:00', cutoff_passed: false, timezone: 'Asia/Dhaka' } : null,
        cod_advance: null,
    };
}

function priceCart(cart, districtId, upazilaId, bn) {
    const items = cart.items.map((line) => {
        const info = variantInfo(line.variant_id);
        const unit = Number(info.price);
        return {
            id: line.id, variant_id: line.variant_id,
            product: { id: info.product.id, name: info.product.name, name_bn: info.product.name_bn, slug: info.product.slug },
            variant: { sku: info.variant.sku, name: info.variant.name ?? null, name_bn: info.variant.name_bn ?? null },
            quantity: line.quantity, available_quantity: info.available, in_stock: line.quantity <= info.available, sellable: true,
            unit_price: money(unit), added_unit_price: line.added_unit_price, price_changed: money(unit) !== line.added_unit_price,
            subtotal: money(unit * line.quantity), discount: '0.00', total: money(unit * line.quantity), _sub: unit * line.quantity,
        };
    });
    const subtotal = items.reduce((n, i) => n + i._sub, 0);
    let coupon = null;
    let couponDiscount = 0;
    if (cart.coupon) {
        const code = cart.coupon;
        if (code === 'SAVE10') {
            couponDiscount = Math.round(subtotal * 10) / 100;
            coupon = { code, applied: true, reason: null, message: null, discount: money(couponDiscount), free_shipping: false };
        } else if (code === 'BIG5000' && subtotal < 5000) {
            coupon = { code, applied: false, reason: 'minimum_not_met', message: bn ? '৳5000.00 বা তার বেশি কিনলে এই কুপন চলবে।' : 'Spend at least ৳5000.00 to use this coupon.', discount: '0.00', free_shipping: false };
        } else if (code === 'BIG5000') {
            couponDiscount = 500;
            coupon = { code, applied: true, reason: null, message: null, discount: '500.00', free_shipping: false };
        }
    }
    // Spread the coupon over lines (last line takes the remainder) so Σ line.total = goods total.
    let left = couponDiscount;
    items.forEach((item, i) => {
        const share = i === items.length - 1 ? left : Math.round((couponDiscount * item._sub) / (subtotal || 1) * 100) / 100;
        left = Math.round((left - share) * 100) / 100;
        item.discount = money(share);
        item.total = money(item._sub - share);
        delete item._sub;
    });
    const goods = subtotal - couponDiscount;
    const delivery = districtId ? quote(districtId, upazilaId, goods) : null;
    const shipping = delivery && delivery.charge !== null ? Number(delivery.charge) : null;
    const adjustments = [{ type: 'subtotal', id: null, label: null, label_bn: null, amount: money(subtotal) }];
    if (couponDiscount) adjustments.push({ type: 'coupon', id: 1, label: cart.coupon, label_bn: cart.coupon, amount: money(-couponDiscount) });
    if (shipping !== null) adjustments.push({ type: 'shipping', id: null, label: null, label_bn: null, amount: money(shipping) });
    return {
        token: cart.token, expires_at: '2026-12-12T04:00:00Z', item_count: cart.items.reduce((n, l) => n + l.quantity, 0), items, coupon, adjustments,
        totals: { subtotal: money(subtotal), promotion_discount: '0.00', coupon_discount: money(couponDiscount), discount_total: money(couponDiscount), shipping_charge: shipping === null ? null : money(shipping), grand_total: money(goods + (shipping ?? 0)) },
        delivery,
        checkout_ready: items.length > 0 && items.every((i) => i.in_stock) && (!coupon || coupon.applied) && shipping !== null,
    };
}

const problemOf = (res, status, type, title, detail, errors) =>
    send(res, status, { type: `https://toymartbd.com/problems/${type}`, title, status, ...(detail ? { detail } : {}), ...(errors ? { errors } : {}) }, 'application/problem+json');

function stockError(info, quantity, bn) {
    if (quantity <= info.available) return null;
    if (info.available <= 0) return bn ? 'দুঃখিত, পণ্যটি স্টকে নেই।' : 'Sorry, this item is out of stock.';
    return bn ? `স্টকে মাত্র ${info.available}টি আছে।` : `Only ${info.available} left in stock.`;
}

function storefrontOrder(order, tracking) {
    const s = order.shipping;
    return {
        ...order,
        shipping: tracking ? { ...s, name: s.name.split(/\s+/)[0], address_line: null, landmark: null, postcode: null } : s,
    };
}

async function cartRoutes(req, res, url) {
    const bn = (req.headers['accept-language'] ?? '').startsWith('bn') || url.searchParams.get('locale') === 'bn';
    const token = req.headers['x-cart-token'];
    const cart = token ? carts.get(token) : null;
    const p = url.pathname.replace('/api/v1/storefront', '');
    const did = Number(url.searchParams.get('district_id')) || null;
    const uid = Number(url.searchParams.get('upazila_id')) || null;

    if (p === '/districts') return send(res, 200, { data: DISTRICTS });
    const up = p.match(/^\/districts\/(\d+)\/upazilas$/);
    if (up) return UPAZILAS[up[1]] ? send(res, 200, { data: UPAZILAS[up[1]] }) : problemOf(res, 404, 'not-found', 'Not Found');
    if (p === '/delivery-rates') return send(res, 200, RATES);
    if (p === '/delivery-quote') {
        const info = variantInfo(Number(url.searchParams.get('variant_id')));
        const q = quote(did, uid, info ? Number(info.price) * (Number(url.searchParams.get('quantity')) || 1) : 0);
        return q ? send(res, 200, q) : problemOf(res, 422, 'validation-error', 'Unprocessable', null, [{ field: 'district_id', message: 'Unknown district.' }]);
    }

    if (p === '/cart' && req.method === 'GET') return cart ? send(res, 200, priceCart(cart, did, uid, bn)) : problemOf(res, 404, 'not-found', 'Not Found');
    if (p === '/cart/items' && req.method === 'POST') {
        const body = await readJson(req);
        let target = cart;
        if (token && !cart) return problemOf(res, 404, 'not-found', 'Not Found');
        const info = variantInfo(Number(body.variant_id));
        if (!info) return problemOf(res, 422, 'validation-error', 'Unprocessable', null, [{ field: 'variant_id', message: 'Unknown variant.' }]);
        const existing = target?.items.find((l) => l.variant_id === info.variant.id || l.variant_id === Number(body.variant_id));
        const quantity = (existing?.quantity ?? 0) + Number(body.quantity ?? 1);
        const stock = stockError(info, quantity, bn);
        if (stock) return problemOf(res, 422, 'validation-error', 'Unprocessable', null, [{ field: 'quantity', message: stock }]);
        if (!target) {
            target = { token: uuid(), items: [], coupon: null, nextId: 1 };
            carts.set(target.token, target);
        }
        if (existing) existing.quantity = quantity;
        else target.items.push({ id: target.nextId++, variant_id: Number(body.variant_id), quantity, added_unit_price: money(Number(info.price)) });
        return send(res, cart ? 200 : 201, priceCart(target, null, null, bn));
    }
    const item = p.match(/^\/cart\/items\/(\d+)$/);
    if (item) {
        if (!cart) return problemOf(res, 404, 'not-found', 'Not Found');
        const line = cart.items.find((l) => l.id === Number(item[1]));
        if (!line) return problemOf(res, 404, 'not-found', 'Not Found');
        if (req.method === 'DELETE') cart.items = cart.items.filter((l) => l !== line);
        else {
            const { quantity } = await readJson(req);
            const stock = quantity > line.quantity ? stockError(variantInfo(line.variant_id), quantity, bn) : null;
            if (stock) return problemOf(res, 422, 'validation-error', 'Unprocessable', null, [{ field: 'quantity', message: stock }]);
            line.quantity = quantity;
        }
        return send(res, 200, priceCart(cart, null, null, bn));
    }
    if (p === '/cart/coupon') {
        if (!cart) return problemOf(res, 404, 'not-found', 'Not Found');
        if (req.method === 'DELETE') cart.coupon = null;
        else {
            const { code } = await readJson(req);
            const upper = String(code).toUpperCase();
            if (!['SAVE10', 'BIG5000'].includes(upper)) return problemOf(res, 422, 'validation-error', 'Unprocessable', null, [{ field: 'code', message: bn ? 'এই কুপন কোডটি পাওয়া যায়নি।' : 'We couldn’t find that coupon code.' }]);
            cart.coupon = upper;
            const priced = priceCart(cart, null, null, bn);
            if (!priced.coupon.applied) return problemOf(res, 422, 'validation-error', 'Unprocessable', null, [{ field: 'code', message: priced.coupon.message }]);
        }
        return send(res, 200, priceCart(cart, null, null, bn));
    }

    if (p === '/checkout' && req.method === 'POST') {
        const key = req.headers['idempotency-key'];
        if (!key) return problemOf(res, 400, 'idempotency-key-missing', 'Bad Request');
        const body = await readJson(req);
        const fingerprint = JSON.stringify(body) + token;
        const replay = idempotency.get(key);
        if (replay) {
            if (replay.fingerprint !== fingerprint) return problemOf(res, 422, 'idempotency-key-reused', 'Unprocessable');
            res.writeHead(201, { 'Content-Type': 'application/json', 'Idempotent-Replayed': 'true' });
            return res.end(JSON.stringify(storefrontOrder(orders.get(replay.number), false)));
        }
        if (!cart) return problemOf(res, 404, 'not-found', 'Not Found');
        const errors = [];
        const phone = bnDigits(body.phone ?? '').replace(/[\s-]/g, '');
        if (!body.name || String(body.name).trim().length < 2) errors.push({ field: 'name', message: bn ? 'আপনার নাম লিখুন।' : 'Enter your name.' });
        if (!/^(?:\+?880|0)1[3-9]\d{8}$/.test(phone)) errors.push({ field: 'phone', message: bn ? '01 দিয়ে শুরু 11 অঙ্কের মোবাইল নম্বর দিন (013–019)।' : 'Enter an 11-digit mobile number starting with 01 (013–019).' });
        const district = DISTRICTS.find((d) => d.id === body.district_id);
        if (!district) errors.push({ field: 'district_id', message: bn ? 'জেলা বেছে নিন।' : 'Choose a district.' });
        else if (body.upazila_id === null ? !district.city_option : !(UPAZILAS[district.id] ?? []).some((u) => u.id === body.upazila_id)) errors.push({ field: 'upazila_id', message: bn ? 'উপজেলা বেছে নিন।' : 'Choose an upazila of that district.' });
        if (!body.area || String(body.area).trim().length < 2) errors.push({ field: 'area', message: bn ? 'এলাকা লিখুন।' : 'Enter the area.' });
        if (!body.address_line || String(body.address_line).trim().length < 5) errors.push({ field: 'address_line', message: bn ? 'পুরো ঠিকানা লিখুন।' : 'Enter the full address (at least 5 characters).' });
        if (errors.length) return problemOf(res, 422, 'validation-error', 'Unprocessable', 'The given data was invalid.', errors);
        const priced = priceCart(cart, body.district_id, body.upazila_id, bn);
        if (priced.items.length === 0) return problemOf(res, 422, 'validation-error', 'Unprocessable', null, [{ field: 'cart', message: 'Your cart is empty.' }]);
        if (priced.totals.grand_total !== body.expected_total) {
            const msg = bn ? `মোট বদলে ৳${priced.totals.grand_total} হয়েছে। অর্ডারটি দেখে আবার দিন।` : `The total has changed to ৳${priced.totals.grand_total}. Please review your order and place it again.`;
            return problemOf(res, 409, 'price-changed', 'Price changed', msg, [{ field: 'expected_total', message: msg }]);
        }
        const short = priced.items.filter((i) => !i.in_stock).map((i) => ({ field: `items.${i.variant_id}`, message: stockError(variantInfo(i.variant_id), i.quantity, bn) }));
        if (short.length) return problemOf(res, 409, 'insufficient-stock', 'Insufficient stock', short[0].message, short);
        const number = `TMB-26-${String(++orderSeq).padStart(5, '0')}`;
        const upazila = (UPAZILAS[district.id] ?? []).find((u) => u.id === body.upazila_id) ?? null;
        const masked = `${phone.slice(-11, -8)}****${phone.slice(-4)}`;
        const placedAt = '2026-11-12T05:02:00Z';
        const order = {
            number, stage: 'placed', placed_at: placedAt,
            payment: { method: 'cod', status: 'unpaid', next_action: null },
            contact: { name: body.name, phone_masked: masked, email: body.email ?? null },
            shipping: {
                name: body.name, phone_masked: masked, division: district.division,
                district: { id: district.id, name: district.name, name_bn: district.name_bn },
                upazila: upazila ? { id: upazila.id, name: upazila.name, name_bn: upazila.name_bn } : null,
                area: body.area, address_line: body.address_line, landmark: body.landmark ?? null, postcode: null,
            },
            items: priced.items.map((i) => ({ variant_id: i.variant_id, product_id: i.product.id, slug: i.product.slug, name: i.product.name, name_bn: i.product.name_bn, variant_name: i.variant.name, sku: i.variant.sku, image: null, quantity: i.quantity, unit_price: i.unit_price, discount: i.discount, total: i.total })),
            coupon_code: priced.coupon?.applied ? priced.coupon.code : null,
            totals: { ...priced.totals, paid_total: '0.00', due_total: priced.totals.grand_total },
            timeline: [{ stage: 'placed', at: placedAt }],
            _token: token, _phone: phone,
        };
        orders.set(number, order);
        carts.delete(token);
        idempotency.set(key, { fingerprint, number });
        res.writeHead(201, { 'Content-Type': 'application/json', Location: `/api/v1/storefront/orders/${number}` });
        const { _token, _phone, ...out } = order;
        return res.end(JSON.stringify(out));
    }
    if (p === '/orders/track' && req.method === 'POST') {
        const { number, phone } = await readJson(req);
        const digits = bnDigits(phone ?? '').replace(/\D/g, '');
        if (!number || !(digits.length === 4 || digits.length >= 11)) return problemOf(res, 422, 'validation-error', 'Unprocessable', null, [{ field: 'phone', message: 'Enter 4 digits.' }]);
        const norm = bnDigits(number).toUpperCase().replace(/\s+/g, '-').replace(/^(?!TMB-)/, 'TMB-');
        const order = orders.get(norm);
        if (!order || !order._phone.endsWith(digits.slice(-4))) return problemOf(res, 404, 'not-found', 'Not Found', bn ? 'অর্ডার নম্বর আর সংখ্যাগুলো মেলেনি।' : 'That order number and those digits don’t match. Please check the number in your SMS.');
        const { _token, _phone, ...out } = order;
        return send(res, 200, storefrontOrder(out, true));
    }
    const show = p.match(/^\/orders\/([^/]+)$/);
    if (show && req.method === 'GET') {
        const order = orders.get(decodeURIComponent(show[1]).toUpperCase());
        if (!order || order._token !== token) return problemOf(res, 404, 'not-found', 'Not Found');
        const { _token, _phone, ...out } = order;
        return send(res, 200, storefrontOrder(out, false));
    }
    return problemOf(res, 404, 'not-found', 'Not Found', p);
}

createServer(async (req, res) => {
    const url = new URL(req.url ?? '/', `http://127.0.0.1:${port}`);

    if (url.pathname.startsWith('/__control/')) {
        if (url.pathname === '/__control/hits') return send(res, 200, hits);
        if (url.pathname === '/__control/reset') {
            brands = structuredClone(fixtures);
            hits = {};
            carts = new Map();
            orders = new Map();
            idempotency = new Map();
            priceOverrides = {};
            return send(res, 200, { ok: true });
        }
        if (url.pathname === '/__control/price' && req.method === 'POST') {
            const { variant_id, price } = await readJson(req);
            priceOverrides[variant_id] = price;
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

    if (/^\/api\/v1\/storefront\/(districts|delivery-|cart|checkout|orders)/.test(url.pathname)) return cartRoutes(req, res, url);
    if (url.pathname === '/api/v1/storefront/brands') return send(res, 200, { data: brands });
    if (url.pathname === '/api/v1/storefront/categories/tree') return send(res, 200, categoryTree);
    if (url.pathname === '/api/v1/storefront/products') return listProducts(res, url.searchParams);
    if (url.pathname === '/api/v1/storefront/search') return searchProducts(res, url.searchParams);
    if (url.pathname === '/api/v1/storefront/search/suggest') return suggest(res, url.searchParams);
    const product = url.pathname.match(/^\/api\/v1\/storefront\/products\/([^/]+)$/);
    if (product) {
        const slug = decodeURIComponent(product[1]);
        const simple = productFixtures.find((p) => p.slug === slug);
        if (slug !== productDetail.slug && simple) return send(res, 200, simpleDetail(simple));
        if (slug !== productDetail.slug) return problem(res, 404, 'Not Found', `No product "${product[1]}".`);
        const { _related, ...detail } = productDetail;
        const related = _related.map((slug) => productFixtures.find((p) => p.slug === slug)).filter(Boolean).map(card);
        return send(res, 200, { ...detail, related });
    }
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
