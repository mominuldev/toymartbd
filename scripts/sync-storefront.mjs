#!/usr/bin/env node
// Copies the shared packages the storefront uses into the storefront repository (owner decision
// 2026-11-17: the storefront lives in its own repo, github.com/mominuldev/toymartbd-storefront,
// and receives copies — no registry, no workspace link).
//
//   npm run sync:storefront                 → ../toymartbd-storefront
//   npm run sync:storefront -- <dir>        → another checkout
//   npm run sync:storefront -- --check      → exit 1 if the copies are stale (nothing written)
//
// Run it after `npm run tokens:build` or `npm run api-types:generate`, then commit the result in
// the storefront repo. What is copied (and only this — the storefront never sees admin code):
//   packages/tokens         dist/ (the CSS the storefront imports) + src/ + a trimmed package.json
//   packages/api-types      src/index.ts + src/schema.ts (types only; the zod entry is admin-only)
//   packages/observability  src/ (Sentry PII scrubbing, rule 20)
//   contract/               config/payments.php + database/seeders/StaticPagesSeeder.php, verbatim —
//                           read by the storefront's tests (CSP payment hosts, seeded CMS slugs)
//                           so its links and policy stay in step with the API.
import { execSync } from 'node:child_process';
import { cpSync, existsSync, mkdtempSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const check = args.includes('--check');
const target = resolve(root, args.find((a) => !a.startsWith('--')) ?? process.env.STOREFRONT_DIR ?? '../toymartbd-storefront');

if (!existsSync(join(target, 'package.json'))) {
    console.error(`sync:storefront — no storefront checkout at ${target}. Clone github.com/mominuldev/toymartbd-storefront there, or pass its path.`);
    process.exit(1);
}

const pkg = (name) => JSON.parse(readFileSync(join(root, 'packages', name, 'package.json'), 'utf8'));
const trimmed = (name, exports) => {
    const p = pkg(name);
    return { name: p.name, version: p.version, private: true, type: p.type ?? 'module', description: `${p.description ?? ''} Copied from toymartbd by \`npm run sync:storefront\` — do not edit here.`.trim(), exports };
};

/** The shared/ tree as it should be: relative path → file contents (Buffer or string). */
function build() {
    const out = new Map();
    const copyDir = (from, to, skip = () => false) => {
        for (const entry of readdirSync(from)) {
            const src = join(from, entry);
            if (skip(entry)) continue;
            if (statSync(src).isDirectory()) copyDir(src, join(to, entry), skip);
            else out.set(join(to, entry), readFileSync(src));
        }
    };

    const tokens = pkg('tokens');
    out.set('tokens/package.json', JSON.stringify(trimmed('tokens', tokens.exports), null, 4) + '\n');
    copyDir(join(root, 'packages/tokens/dist'), 'tokens/dist');
    copyDir(join(root, 'packages/tokens/src'), 'tokens/src', (e) => e === 'figma-variables.json');

    out.set('api-types/package.json', JSON.stringify(trimmed('api-types', { '.': './src/index.ts' }), null, 4) + '\n');
    out.set('api-types/src/index.ts', readFileSync(join(root, 'packages/api-types/src/index.ts')));
    out.set('api-types/src/schema.ts', readFileSync(join(root, 'packages/api-types/src/schema.ts')));

    const obs = pkg('observability');
    out.set('observability/package.json', JSON.stringify(trimmed('observability', obs.exports), null, 4) + '\n');
    copyDir(join(root, 'packages/observability/src'), 'observability/src');

    out.set('contract/payments.php', readFileSync(join(root, 'config/payments.php')));
    out.set('contract/StaticPagesSeeder.php', readFileSync(join(root, 'database/seeders/StaticPagesSeeder.php')));

    out.set(
        'README.md',
        [
            '# shared/ — copied, never edited here',
            '',
            'These packages are built in the main repository (github.com/mominuldev/toymartbd) and copied',
            'here by `npm run sync:storefront`. Edit them there, run the sync, commit the result here.',
            '',
            '- `tokens/` — design tokens (`@toymart/tokens/css`, `@toymart/tokens/tailwind.css`).',
            '- `api-types/` — types generated from the API’s OpenAPI document (`Schema<…>`).',
            '- `observability/` — Sentry PII scrubbing (CLAUDE.md rule 20).',
            '- `contract/` — `config/payments.php` and `StaticPagesSeeder.php` from the API, read by the tests',
            '  (CSP payment hosts, seeded CMS slugs) so the storefront stays in step with the API.',
            '',
        ].join('\n'),
    );
    return out;
}

const wanted = build();
const shared = join(target, 'shared');

/** Every file currently under shared/, relative. */
function existing(dir = shared, base = shared, acc = new Map()) {
    if (!existsSync(dir)) return acc;
    for (const entry of readdirSync(dir)) {
        const p = join(dir, entry);
        if (statSync(p).isDirectory()) existing(p, base, acc);
        else acc.set(relative(base, p), readFileSync(p));
    }
    return acc;
}

const have = existing();
const stale = [...new Set([...wanted.keys(), ...have.keys()])].filter((f) => {
    const a = wanted.get(f);
    const b = have.get(f);
    return a === undefined || b === undefined || !Buffer.from(a).equals(Buffer.from(b));
});

if (check) {
    if (stale.length > 0) {
        console.error(`sync:storefront — ${stale.length} file(s) differ in ${shared}:\n  ${stale.slice(0, 20).join('\n  ')}\nRun \`npm run sync:storefront\`.`);
        process.exit(1);
    }
    console.log('sync:storefront — shared/ is up to date.');
    process.exit(0);
}

// Write into a temp dir first, then replace shared/ whole, so a removed file disappears too.
const tmp = mkdtempSync(join(tmpdir(), 'toymart-shared-'));
for (const [file, body] of wanted) {
    mkdirSync(dirname(join(tmp, file)), { recursive: true });
    writeFileSync(join(tmp, file), body);
}
rmSync(shared, { recursive: true, force: true });
cpSync(tmp, shared, { recursive: true });
rmSync(tmp, { recursive: true, force: true });

let sha = 'unknown';
try {
    sha = execSync('git rev-parse --short HEAD', { cwd: root }).toString().trim();
} catch {
    /* not a git checkout */
}
console.log(`sync:storefront — ${stale.length === 0 ? 'already up to date' : `${stale.length} file(s) updated`} in ${shared} (from toymartbd ${sha}). Commit the change in the storefront repo.`);
