import { beforeEach, describe, expect, it, vi } from 'vitest';

const revalidateTag = vi.fn();
vi.mock('next/cache', () => ({ revalidateTag: (...args: unknown[]) => revalidateTag(...args) }));

const { POST } = await import('@/app/api/revalidate/route');
const { sign } = await import('@/lib/revalidate');

const SECRET = 'test-revalidate-secret';
const now = () => Math.floor(Date.now() / 1000);

function request(body: string, opts: { ts?: number | string | null; signature?: string | null; secret?: string } = {}) {
    const ts = opts.ts === undefined ? String(now()) : opts.ts === null ? null : String(opts.ts);
    const headers: Record<string, string> = { 'Content-Type': 'application/json' };
    if (ts !== null) headers['X-Revalidate-Timestamp'] = ts;
    const signature = opts.signature === undefined ? (ts === null ? null : sign(opts.secret ?? SECRET, ts, body)) : opts.signature;
    if (signature !== null) headers['X-Revalidate-Signature'] = signature;
    return new Request('http://storefront.test/api/revalidate', { method: 'POST', headers, body });
}

async function expectProblem(response: Response, status: number) {
    expect(response.status).toBe(status);
    expect(response.headers.get('content-type')).toContain('application/problem+json');
    const body = (await response.json()) as { status: number; type: string; title: string };
    expect(body.status).toBe(status);
    expect(body.type).toMatch(/^https:\/\/toymartbd\.com\/problems\//);
    expect(revalidateTag).not.toHaveBeenCalled();
}

describe('POST /api/revalidate', () => {
    beforeEach(() => {
        revalidateTag.mockReset();
        vi.stubEnv('STOREFRONT_REVALIDATE_SECRET', SECRET);
    });

    it('revalidates every tag of a correctly signed, fresh request', async () => {
        const res = await POST(request('{"tags": ["brands", "brand:lego"]}'));
        expect(res.status).toBe(200);
        expect(await res.json()).toEqual({ revalidated: ['brands', 'brand:lego'] });
        expect(revalidateTag.mock.calls).toEqual([
            ['brands', { expire: 0 }],
            ['brand:lego', { expire: 0 }],
        ]);
    });

    it('accepts a timestamp at the edge of the ±300 s window', async () => {
        expect((await POST(request('{"tags":["brands"]}', { ts: now() - 299 }))).status).toBe(200);
        expect((await POST(request('{"tags":["brands"]}', { ts: now() + 299 }))).status).toBe(200);
    });

    it('rejects a signature made with another secret (401)', async () => {
        await expectProblem(await POST(request('{"tags":["brands"]}', { secret: 'wrong' })), 401);
    });

    it('rejects a body changed after signing (401)', async () => {
        const ts = String(now());
        const sig = sign(SECRET, ts, '{"tags":["brands"]}');
        await expectProblem(await POST(request('{"tags":["brand:lego"]}', { ts, signature: sig })), 401);
    });

    it('rejects a garbage or truncated signature (401)', async () => {
        await expectProblem(await POST(request('{"tags":["brands"]}', { signature: 'sha256=abc' })), 401);
        await expectProblem(await POST(request('{"tags":["brands"]}', { signature: 'not-a-signature' })), 401);
    });

    it('rejects missing signature headers (401)', async () => {
        await expectProblem(await POST(request('{"tags":["brands"]}', { signature: null })), 401);
        await expectProblem(await POST(request('{"tags":["brands"]}', { ts: null })), 401);
    });

    it('rejects a stale timestamp even when correctly signed (401)', async () => {
        await expectProblem(await POST(request('{"tags":["brands"]}', { ts: now() - 301 })), 401);
    });

    it('rejects a timestamp too far in the future (401)', async () => {
        await expectProblem(await POST(request('{"tags":["brands"]}', { ts: now() + 301 })), 401);
    });

    it('rejects a non-numeric timestamp (401)', async () => {
        await expectProblem(await POST(request('{"tags":["brands"]}', { ts: 'yesterday' })), 401);
    });

    it.each([
        ['invalid JSON', '{"tags": ['],
        ['an array body', '["brands"]'],
        ['missing tags', '{"tag":"brands"}'],
        ['tags not an array', '{"tags":"brands"}'],
        ['zero tags', '{"tags":[]}'],
        ['51 tags', JSON.stringify({ tags: Array.from({ length: 51 }, (_, i) => `brand:b${i}`) })],
        ['a non-string tag', '{"tags":[42]}'],
        ['an uppercase tag', '{"tags":["Brands"]}'],
        ['a tag with a space', '{"tags":["brand lego"]}'],
        ['a 101-character tag', JSON.stringify({ tags: ['a'.repeat(101)] })],
        ['one bad tag among good ones', '{"tags":["brands","brand:LEGO"]}'],
    ])('rejects a signed malformed body: %s (400)', async (_label, body) => {
        await expectProblem(await POST(request(body)), 400);
    });

    it('accepts exactly 50 tags of 100 characters', async () => {
        const tags = Array.from({ length: 50 }, (_, i) => `${String(i).padStart(2, '0')}${'x'.repeat(98)}`);
        const res = await POST(request(JSON.stringify({ tags })));
        expect(res.status).toBe(200);
        expect(revalidateTag).toHaveBeenCalledTimes(50);
    });

    it('refuses everything when the secret is not configured (503)', async () => {
        vi.stubEnv('STOREFRONT_REVALIDATE_SECRET', '');
        await expectProblem(await POST(request('{"tags":["brands"]}', { secret: '' })), 503);
    });
});
