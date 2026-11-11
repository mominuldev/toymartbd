import { expect, test } from '@playwright/test';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

import { E2E_DIST_DIR, FAKE_REVALIDATE_SECRET, FAKE_TOKEN, MOCK_API_URL } from './constants';

// P3-STO-02 acceptance: no API credential appears in any client bundle (CLAUDE.md rule 28).
// The webServer built .next-e2e with FAKE_TOKEN/FAKE_REVALIDATE_SECRET in the environment;
// everything under .next-e2e/static is what browsers download.

function files(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        return statSync(path).isDirectory() ? files(path) : [path];
    });
}

test('client bundles contain no credential, secret, env var name or API origin', () => {
    const staticDir = join(process.cwd(), E2E_DIST_DIR, 'static');
    const all = files(staticDir);
    const scripts = all.filter((f) => f.endsWith('.js'));
    expect(scripts.length, 'the build produced client scripts to scan').toBeGreaterThan(0);

    const needles = [
        FAKE_TOKEN,
        FAKE_REVALIDATE_SECRET,
        'STOREFRONT_API_TOKEN',
        'STOREFRONT_REVALIDATE_SECRET',
        MOCK_API_URL,
        'storefront/brands', // API paths are server-side only
    ];
    const leaks: string[] = [];
    for (const file of all) {
        const text = readFileSync(file, 'latin1');
        for (const needle of needles) if (text.includes(needle)) leaks.push(`${needle} in ${file}`);
    }
    expect(leaks).toEqual([]);
});

test('the fake token DID reach the server (control: the scan is not vacuous)', async ({ request }) => {
    // The mock API rejects any request without `Bearer FAKE_TOKEN`; a rendered brand proves the
    // server used it.
    expect(await (await request.get('/brands')).text()).toContain('Hot Wheels');
    const serverChunks = files(join(process.cwd(), E2E_DIST_DIR, 'server')).filter((f) => f.endsWith('.js'));
    expect(serverChunks.some((f) => readFileSync(f, 'latin1').includes('STOREFRONT_API_TOKEN'))).toBe(true);
});
