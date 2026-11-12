#!/usr/bin/env node
// i18n key check (CLAUDE.md rule 35, P4-I18N-01). Fails when a key would not resolve in BOTH
// locales, so CI breaks instead of a screen rendering a raw key, an empty string or `undefined`.
//
// Per app (admin SPA, storefront):
//   1. en and bn have exactly the same keys, every value a non-empty string;
//   2. every {placeholder} in a string exists in the other locale's string too;
//   3. no Bengali digit in any string (rule 36: numerals stay Western);
//   4. admin only: every `t('literal')` key used in the source exists, and every dynamic key
//      (`t(`orders.status.${s}` as MessageKey)`) has at least one catalog key under its static
//      prefix. Dynamic keys are why the admin's translate() also throws on a missing key in
//      dev and tests; the storefront reads its catalog by typed property access, which the
//      TypeScript build already checks.
//
// The catalogs are TypeScript; rolldown (Vite 8's bundler, already installed) bundles each one
// in memory so this runs on plain Node with no build step.
//
// Usage: node scripts/i18n/check-keys.mjs [admin|storefront]   (no argument = both)
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { rolldown } from 'rolldown';
import { parseAst } from 'rolldown/parseAst';

const repo = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

const apps = {
    admin: {
        catalog: 'resources/js/admin/i18n/messages.ts',
        sources: ['resources/js/admin'],
    },
    storefront: {
        catalog: 'toymartbd-app/storefront/lib/i18n/index.ts',
        exports: (m) => ({ en: m.getMessages('en'), bn: m.getMessages('bn') }),
        sources: [],
    },
};

async function loadCatalog(entry) {
    const bundle = await rolldown({ input: join(repo, entry), logLevel: 'silent' });
    const { output } = await bundle.generate({ format: 'esm' });
    await bundle.close();
    const code = output[0].code;
    return import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);
}

/** Flatten a nested catalog to dotted keys; flat catalogs pass through. */
function leaves(value, prefix = '') {
    if (typeof value !== 'object' || value === null) return [[prefix, value]];
    return Object.entries(value).flatMap(([k, v]) => leaves(v, prefix ? `${prefix}.${k}` : k));
}

const placeholders = (s) => [...String(s).matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();

export function compareCatalogs(en, bn) {
    const problems = [];
    const enMap = new Map(leaves(en));
    const bnMap = new Map(leaves(bn));
    for (const [locale, own, other] of [
        ['en', enMap, bnMap],
        ['bn', bnMap, enMap],
    ]) {
        for (const [key, value] of own) {
            if (typeof value !== 'string' || value.trim() === '') problems.push(`${locale}: "${key}" is empty or not a string`);
            if (/[০-৯]/.test(String(value))) problems.push(`${locale}: "${key}" contains a Bengali digit (rule 36)`);
            if (!other.has(key)) problems.push(`${locale === 'en' ? 'bn' : 'en'}: missing key "${key}"`);
        }
    }
    for (const [key, value] of enMap) {
        if (!bnMap.has(key)) continue;
        const a = placeholders(value).join(',');
        const b = placeholders(bnMap.get(key)).join(',');
        if (a !== b) problems.push(`"${key}": placeholders differ — en {${a}} vs bn {${b}}`);
    }
    return problems;
}

function sourceFiles(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) return ['node_modules', 'test', 'i18n'].includes(name) ? [] : sourceFiles(path);
        return /\.tsx?$/.test(name) && !/\.(test|spec)\.tsx?$/.test(name) && !name.endsWith('.d.ts') ? [path] : [];
    });
}

const unwrap = (node) =>
    node && ['TSAsExpression', 'TSSatisfiesExpression', 'ParenthesizedExpression', 'TSNonNullExpression'].includes(node.type)
        ? unwrap(node.expression)
        : node;

const isMessageKeyCast = (node) =>
    node?.type === 'TSAsExpression' && node.typeAnnotation?.type === 'TSTypeReference' && node.typeAnnotation.typeName?.name === 'MessageKey';

/** Keys the admin source asks for: literal ones exactly, template ones by static prefix. */
export function usedKeys(text, path) {
    const ast = parseAst(text, { lang: path.endsWith('.tsx') ? 'tsx' : 'ts', sourceType: 'module' }, path);
    const literal = [];
    const prefixes = [];
    const take = (raw) => {
        const node = unwrap(raw);
        if (node?.type === 'Literal' && typeof node.value === 'string') literal.push(node.value);
        else if (node?.type === 'TemplateLiteral' && node.expressions.length > 0) prefixes.push(node.quasis[0].value.cooked ?? '');
        else if (node?.type === 'ConditionalExpression') {
            take(node.consequent);
            take(node.alternate);
        }
    };
    const visit = (node) => {
        if (!node || typeof node !== 'object') return;
        if (Array.isArray(node)) return node.forEach(visit);
        if (node.type === 'CallExpression') {
            const c = node.callee;
            const name = c?.type === 'Identifier' ? c.name : c?.type === 'MemberExpression' && !c.computed ? c.property.name : '';
            if (name === 't' && node.arguments[0]) take(node.arguments[0]);
            if (name === 'translate' && node.arguments[1]) take(node.arguments[1]);
        }
        if (isMessageKeyCast(node)) take(node.expression);
        for (const key of Object.keys(node)) if (key !== 'type' && key !== 'start' && key !== 'end') visit(node[key]);
    };
    visit(ast);
    return { literal, prefixes };
}

export async function checkApp(name) {
    const app = apps[name];
    const mod = await loadCatalog(app.catalog);
    const { en, bn } = app.exports ? app.exports(mod) : { en: mod.en, bn: mod.bn };
    const problems = compareCatalogs(en, bn);
    if (app.sources.length) {
        const keys = new Set(leaves(en).map(([k]) => k));
        const all = [...keys];
        for (const file of app.sources.flatMap((d) => sourceFiles(join(repo, d)))) {
            const rel = relative(repo, file);
            const { literal, prefixes } = usedKeys(readFileSync(file, 'utf8'), rel);
            // A literal that is not key-shaped (`t(someVar)` never reaches here) is not a key.
            for (const k of literal) if (/^[a-z][\w]*(\.[\w-]+)+$/.test(k) && !keys.has(k)) problems.push(`${rel}: unknown key "${k}"`);
            for (const p of prefixes) if (p !== '' && !all.some((k) => k.startsWith(p))) problems.push(`${rel}: no key starts with "${p}"`);
        }
    }
    return problems;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
    const requested = process.argv.slice(2);
    const names = requested.length ? requested : Object.keys(apps);
    let failed = false;
    for (const name of names) {
        if (!apps[name]) {
            console.error(`unknown app "${name}" (expected: ${Object.keys(apps).join(', ')})`);
            process.exit(2);
        }
        const problems = await checkApp(name);
        for (const p of problems) console.log(`${name}: ${p}`);
        console.log(`i18n keys (${name}): ${problems.length === 0 ? 'every key resolves in en and bn' : `${problems.length} problem(s)`}`);
        failed ||= problems.length > 0;
    }
    if (failed) process.exit(1);
}
