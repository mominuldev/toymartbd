import { defineConfig, devices } from '@playwright/test';

// Admin accessibility audit (Phase 9, docs/audits/ACCESSIBILITY-AND-BILINGUAL.md): axe-core over the
// main admin screens in English and Bangla, light and dark, on the real Laravel app with the demo
// data. Separate from the admin E2E suite (port 8124) so the two never collide. Run `npm run build`
// first, then `npm run test:a11y:admin`. A11Y_ADMIN_PORT overrides 8344; A11Y_ADMIN_REUSE=1 reuses a
// server you started yourself (sh e2e/admin-a11y/serve.sh).
const port = Number(process.env.A11Y_ADMIN_PORT ?? 8344);
// CI (the "Admin a11y" job in .github/workflows/ci.yml) runs on a slower shared runner: double the
// per-test budget for the long multi-screen sweeps, give web-first assertions more room, and
// retry a failed test once. Every spec is independent of the order it runs in and of the others'
// writes (two-factor enrols a different account on a retry), so a retry starts clean.
const ci = Boolean(process.env.CI);

export default defineConfig({
    testDir: '.',
    outputDir: './test-results',
    fullyParallel: false,
    workers: 1,
    retries: ci ? 1 : 0,
    timeout: ci ? 600_000 : 300_000,
    expect: { timeout: ci ? 15_000 : 5_000 },
    reporter: ci ? [['list'], ['html', { open: 'never', outputFolder: './playwright-report' }]] : 'list',
    use: {
        baseURL: `http://localhost:${port}`,
        trace: 'retain-on-failure',
        reducedMotion: 'reduce',
        // Dates and "41m ago" render in Asia/Dhaka (rule 11) whatever the runner's clock zone is.
        timezoneId: 'Asia/Dhaka',
        actionTimeout: ci ? 30_000 : 15_000,
        navigationTimeout: ci ? 60_000 : 30_000,
    },
    // Locally: the Google Chrome installed on this machine (owner rule 2026-11-14), never a
    // separately downloaded browser. CI keeps the Chromium its workflow installs.
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], channel: ci ? undefined : 'chrome' } }],
    webServer: {
        command: 'sh ./serve.sh',
        url: `http://localhost:${port}/up`,
        env: { A11Y_ADMIN_PORT: String(port) },
        reuseExistingServer: process.env.A11Y_ADMIN_REUSE === '1',
        timeout: 600_000,
        stdout: 'ignore',
        stderr: 'pipe',
    },
});
