// Fake credentials for the E2E build. They are deliberately distinctive so the bundle test can
// prove they never reach .next-e2e/static (CLAUDE.md rule 28).
// Ports and the build directory can be overridden (E2E_MOCK_API_PORT, E2E_STOREFRONT_PORT,
// E2E_DIST_DIR) so two checkouts can run the suite side by side; CI uses the defaults.
export const MOCK_API_PORT = Number(process.env.E2E_MOCK_API_PORT ?? 4010);
export const STOREFRONT_PORT = Number(process.env.E2E_STOREFRONT_PORT ?? 3100);
export const MOCK_API_URL = `http://127.0.0.1:${MOCK_API_PORT}`;
export const STOREFRONT_URL = `http://127.0.0.1:${STOREFRONT_PORT}`;
export const FAKE_TOKEN = 'e2e-fake-storefront-token-7Qx9Lm2Vp4';
export const FAKE_REVALIDATE_SECRET = 'e2e-fake-revalidate-secret-3Hk8Zr6Wn1';
export const E2E_DIST_DIR = process.env.E2E_DIST_DIR ?? '.next-e2e';

/** A consent answer of "Reject" under the mock API's policy version (e2e/mock-api.mjs TRACKING_CONFIG). */
export const CONSENT_POLICY_VERSION = '2026-11';
export const CONSENT_REJECTED_COOKIE = {
    name: 'tm_consent',
    value: encodeURIComponent(`1|${CONSENT_POLICY_VERSION}|0|0|1763000000`),
    domain: '127.0.0.1',
    path: '/',
    expires: -1,
    httpOnly: false,
    secure: false,
    sameSite: 'Lax' as const,
};
