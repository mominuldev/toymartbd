// Production environment validation for the storefront (Phase 10 hardening).
//
// next.config.ts runs this for `next build` and `next start` only (PHASE_PRODUCTION_BUILD /
// PHASE_PRODUCTION_SERVER), so a production build or server with a missing or malformed variable
// stops with one clear message instead of serving a shop that cannot reach its API, cannot be
// revalidated, emits wrong canonical URLs or cannot be framed by the admin's homepage builder.
// `next dev`, `next typegen` and the unit tests never run it. The E2E suite is a production build
// and passes fake values for every variable (playwright.config.ts).
//
// Only variable NAMES appear in messages, never a value: a build log is not a secret store.

/** Every variable a production storefront needs. All server-only (CLAUDE.md rule 28). */
export const REQUIRED_PRODUCTION_ENV = {
    API_URL: 'url',
    STOREFRONT_API_TOKEN: 'secret',
    STOREFRONT_REVALIDATE_SECRET: 'secret',
    SITE_URL: 'url',
    ADMIN_URL: 'url',
} as const;

/** Shorter than this and a "secret" is a placeholder or a typo. Sanctum tokens are ~42+ chars. */
export const MIN_SECRET_LENGTH = 16;

const PLACEHOLDER = /^(?:changeme|change-me|secret|token|xxx+|todo|placeholder|null|undefined)$/i;

function isHttpUrl(value: string): boolean {
    try {
        const url = new URL(value);
        return (url.protocol === 'https:' || url.protocol === 'http:') && url.hostname !== '';
    } catch {
        return false;
    }
}

/** Problems with a production environment; empty when it is complete. Pure. */
export function productionEnvProblems(env: Record<string, string | undefined>): string[] {
    const problems: string[] = [];
    for (const [name, kind] of Object.entries(REQUIRED_PRODUCTION_ENV)) {
        const value = env[name]?.trim() ?? '';
        if (value === '') {
            problems.push(`${name} is not set`);
        } else if (kind === 'url' && !isHttpUrl(value)) {
            problems.push(`${name} is not an absolute http(s) URL`);
        } else if (kind === 'secret' && (value.length < MIN_SECRET_LENGTH || PLACEHOLDER.test(value))) {
            problems.push(`${name} looks like a placeholder (shorter than ${MIN_SECRET_LENGTH} characters)`);
        }
    }
    // A NEXT_PUBLIC_ variable is inlined into the browser bundle. None may exist for a server secret,
    // and none may carry a server secret's value under another name.
    const secrets = new Set(
        Object.entries(REQUIRED_PRODUCTION_ENV)
            .filter(([, kind]) => kind === 'secret')
            .map(([name]) => env[name]?.trim())
            .filter((v): v is string => !!v && v.length >= MIN_SECRET_LENGTH),
    );
    for (const [name, value] of Object.entries(env)) {
        if (!name.startsWith('NEXT_PUBLIC_')) continue;
        const bare = name.slice('NEXT_PUBLIC_'.length);
        if (bare in REQUIRED_PRODUCTION_ENV && REQUIRED_PRODUCTION_ENV[bare as keyof typeof REQUIRED_PRODUCTION_ENV] === 'secret') {
            problems.push(`${name} must not exist: ${bare} is server-only and NEXT_PUBLIC_ variables reach the browser`);
        } else if (value && secrets.has(value.trim())) {
            problems.push(`${name} holds a server secret's value and would ship it to the browser`);
        }
    }
    return problems;
}

/** Throws one readable error listing every problem. */
export function assertProductionEnv(env: Record<string, string | undefined>): void {
    const problems = productionEnvProblems(env);
    if (problems.length === 0) return;
    throw new Error(
        [
            'Storefront production environment is incomplete:',
            ...problems.map((p) => `  - ${p}`),
            'Set them in the deployment environment (see toymartbd-app/storefront/README.md "Environment").',
            'For local development use `npm run dev:storefront`, which does not require them.',
        ].join('\n'),
    );
}
