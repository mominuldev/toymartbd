import { defineConfig, devices } from '@playwright/test';

import {
    E2E_DIST_DIR,
    FAKE_REVALIDATE_SECRET,
    FAKE_TOKEN,
    MOCK_API_PORT,
    MOCK_API_URL,
    STOREFRONT_PORT,
    STOREFRONT_URL,
} from './e2e/constants';

// `npm run test:e2e` (from the repo root: `npm run test:e2e -w storefront`):
//  1. starts e2e/mock-api.mjs serving fixture brands;
//  2. runs `next build` into .next-e2e with FAKE credentials, then `next start` against the mock.
// The bundle spec then greps .next-e2e/static for those credentials.

const storefrontEnv = {
    API_URL: MOCK_API_URL,
    STOREFRONT_API_TOKEN: FAKE_TOKEN,
    STOREFRONT_REVALIDATE_SECRET: FAKE_REVALIDATE_SECRET,
    SITE_URL: STOREFRONT_URL,
    NEXT_DIST_DIR: E2E_DIST_DIR,
    NEXT_TELEMETRY_DISABLED: '1',
};

export default defineConfig({
    testDir: './e2e',
    fullyParallel: false,
    workers: 1, // the revalidation spec mutates the mock API
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: STOREFRONT_URL,
        trace: 'retain-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: [
        {
            command: 'node e2e/mock-api.mjs',
            url: `${MOCK_API_URL}/__control/hits`,
            env: { MOCK_API_PORT: String(MOCK_API_PORT), EXPECTED_TOKEN: FAKE_TOKEN },
            reuseExistingServer: false,
            timeout: 30_000,
        },
        {
            command: `npx next build && npx next start --hostname 127.0.0.1 --port ${STOREFRONT_PORT}`,
            url: `${STOREFRONT_URL}/bn`,
            env: storefrontEnv,
            reuseExistingServer: false,
            timeout: 300_000,
            stdout: 'pipe',
        },
    ],
});
