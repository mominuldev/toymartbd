import { defineConfig, devices } from '@playwright/test';

// Admin E2E against the real Laravel app (P3-ADM-04): run `npm run build` first,
// then `npm run test:e2e:admin`. PHP_BIN overrides the php binary.
export default defineConfig({
    testDir: '.',
    outputDir: './test-results',
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never', outputFolder: './playwright-report' }]] : 'list',
    use: {
        baseURL: 'http://localhost:8124',
        trace: 'retain-on-failure',
    },
    // Locally: the Google Chrome installed on this machine (owner rule 2026-11-14), never a
    // separately downloaded browser. CI keeps the Chromium its workflow installs.
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], channel: process.env.CI ? undefined : 'chrome' } }],
    webServer: {
        command: 'sh ./serve.sh',
        url: 'http://localhost:8124/up',
        reuseExistingServer: false,
        timeout: 120_000,
        stdout: 'ignore',
        stderr: 'pipe',
    },
});
