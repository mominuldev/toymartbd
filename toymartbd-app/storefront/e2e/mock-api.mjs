// Tiny stand-in for the Laravel storefront brands API (node:http, no dependencies).
//   GET  /api/v1/storefront/brands          → {data: Brand[]}
//   GET  /api/v1/storefront/brands/{slug}   → {data: Brand} or 404 problem+json
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
    const match = url.pathname.match(/^\/api\/v1\/storefront\/brands\/([^/]+)$/);
    if (match) {
        const brand = brands.find((b) => b.slug === decodeURIComponent(match[1]));
        return brand ? send(res, 200, { data: brand }) : problem(res, 404, 'Not Found', `No brand "${match[1]}".`);
    }
    return problem(res, 404, 'Not Found', url.pathname);
}).listen(port, '127.0.0.1', () => console.log(`mock API on http://127.0.0.1:${port}`));
