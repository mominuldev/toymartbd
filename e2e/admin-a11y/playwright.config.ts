import { defineConfig, devices } from '@playwright/test';

// Admin accessibility audit (Phase 9, docs/audits/ACCESSIBILITY-AND-BILINGUAL.md): axe-core over the
// main admin screens in English and Bangla, light and dark, on the real Laravel app with the demo
// data. Separate from the admin E2E suite (port 8124) so the two never collide. Run `npm run build`
// first, then `npm run test:a11y:admin`. A11Y_ADMIN_PORT overrides 8344; A11Y_ADMIN_REUSE=1 reuses a
// server you started yourself (sh e2e/admin-a11y/serve.sh).
const port = Number(process.env.A11Y_ADMIN_PORT ?? 8344);

export default defineConfig({
    testDir: '.',
    outputDir: './test-results',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 300_000,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never', outputFolder: './playwright-report' }]] : 'list',
    use: { baseURL: `http://localhost:${port}`, trace: 'retain-on-failure', reducedMotion: 'reduce' },
    // Locally: the Google Chrome installed on this machine (owner rule 2026-11-14), never a
    // separately downloaded browser. CI keeps the Chromium its workflow installs.
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], channel: process.env.CI ? undefined : 'chrome' } }],
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
