#!/usr/bin/env node
// i18n lint (CLAUDE.md rule 35, P4-I18N-01): fails on user-facing text that bypasses the
// i18n layer in the admin SPA and the storefront.
//
//   1. JSX text containing a letter (Latin or Bangla): `<button>Save</button>`.
//   2. A string literal (or a template literal / conditional with literal text) passed to a
//      user-facing prop: aria-label, aria-description, aria-placeholder, aria-roledescription,
//      aria-valuetext, placeholder, title, alt, label.
//
// Strings inside a call (`t('orders.title')`, `format(m.x, {...})`) are keys or code, not copy,
// so call arguments are never inspected. `alt=""` (decorative image) is allowed.
//
// Escape hatch, for text that is deliberately not translated (a language's own name, a
// Latin wordmark): put `i18n-ignore` in a comment on the same line or the line above, with a
// reason. Use it rarely; reviewers should challenge every one.
//
// Why not ESLint: typescript-eslint's support for the TypeScript 7 native compiler is
// unverified (see .github/workflows/ci.yml). This walks the oxc AST that rolldown (Vite 8's
// bundler, already installed) exposes, so it parses exactly what Vite builds.
//
// Usage: node scripts/i18n/lint.mjs [admin|storefront]   (no argument = both)
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseAst } from 'rolldown/parseAst';

const repo = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

export const targets = {
    admin: { roots: ['resources/js/admin'] },
    storefront: { roots: ['toymartbd-app/storefront/app', 'toymartbd-app/storefront/components'] },
};

const TEXT_PROPS = new Set([
    'aria-label',
    'aria-description',
    'aria-placeholder',
    'aria-roledescription',
    'aria-valuetext',
    'placeholder',
    'title',
    'alt',
    'label',
]);

const LETTER = /[A-Za-zঀ-৿]/;

// Not copy: a language's name written in that language (the switcher shows "EN" / "বাং" in
// both locales on purpose), an i18n key passed down as a prop, a URL or path.
const LANGUAGE_SELF_NAMES = new Set(['EN', 'বাং', 'English', 'বাংলা']);
const I18N_KEY = /^[a-z][A-Za-z0-9_]*(\.[A-Za-z0-9_-]+)+$/;
const URL_OR_PATH = /^(https?:\/\/\S*|\/[\w\-./:[\]{}]*)$/;

export function isCopy(value) {
    const s = value.trim();
    return LETTER.test(s) && !LANGUAGE_SELF_NAMES.has(s) && !I18N_KEY.test(s) && !URL_OR_PATH.test(s);
}

function sourceFiles(dir) {
    let names;
    try {
        names = readdirSync(dir);
    } catch {
        return [];
    }
    return names.flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) {
            return ['node_modules', 'test', 'tests', '__tests__'].includes(name) ? [] : sourceFiles(path);
        }
        return name.endsWith('.tsx') && !/\.(test|spec|stories)\.tsx$/.test(name) ? [path] : [];
    });
}

function lineStarts(text) {
    const starts = [0];
    for (let i = 0; i < text.length; i++) if (text[i] === '\n') starts.push(i + 1);
    return starts;
}

function position(starts, offset) {
    let lo = 0;
    let hi = starts.length - 1;
    while (lo < hi) {
        const mid = (lo + hi + 1) >> 1;
        if (starts[mid] <= offset) lo = mid;
        else hi = mid - 1;
    }
    return { line: lo + 1, column: offset - starts[lo] + 1 };
}

/** Literal copy reachable in a prop expression, skipping calls (keys and helpers). */
function literalText(node) {
    if (!node || typeof node !== 'object') return [];
    switch (node.type) {
        case 'Literal':
            return typeof node.value === 'string' && isCopy(node.value) ? [node.value] : [];
        case 'TemplateLiteral':
            return [
                ...node.quasis.map((q) => q.value.cooked ?? q.value.raw).filter((s) => isCopy(s)),
                ...node.expressions.flatMap(literalText),
            ];
        case 'ConditionalExpression':
            return [...literalText(node.consequent), ...literalText(node.alternate)];
        case 'LogicalExpression':
            return [...literalText(node.left), ...literalText(node.right)];
        case 'BinaryExpression':
            return node.operator === '+' ? [...literalText(node.left), ...literalText(node.right)] : [];
        case 'JSXExpressionContainer':
        case 'TSAsExpression':
        case 'TSSatisfiesExpression':
        case 'ParenthesizedExpression':
            return literalText(node.expression);
        default:
            return [];
    }
}

function attrName(node) {
    const n = node.name;
    if (!n) return '';
    if (n.type === 'JSXIdentifier') return n.name;
    if (n.type === 'JSXNamespacedName') return `${n.namespace.name}:${n.name.name}`;
    return '';
}

export function lintSource(text, path) {
    const ast = parseAst(text, { lang: 'tsx', sourceType: 'module' }, path);
    const starts = lineStarts(text);
    const lines = text.split('\n');
    const ignored = (line) => /i18n-ignore/.test(lines[line - 1] ?? '') || /i18n-ignore/.test(lines[line - 2] ?? '');
    const found = [];
    const report = (offset, message) => {
        const { line, column } = position(starts, offset);
        if (!ignored(line)) found.push({ path, line, column, message });
    };

    const visit = (node) => {
        if (!node || typeof node !== 'object') return;
        if (Array.isArray(node)) {
            for (const child of node) visit(child);
            return;
        }
        if (typeof node.type !== 'string') return;

        if (node.type === 'JSXText') {
            const value = node.value.replace(/\s+/g, ' ').trim();
            if (isCopy(value)) report(node.start + node.value.search(/\S/), `hardcoded JSX text "${value}"`);
        } else if (node.type === 'JSXAttribute' && TEXT_PROPS.has(attrName(node))) {
            for (const s of literalText(node.value)) report(node.start, `hardcoded ${attrName(node)}="${s}"`);
        } else if (node.type === 'JSXExpressionContainer' && node.expression?.type !== 'JSXEmptyExpression') {
            // `{'Save'}` or `{cond ? 'Yes' : 'No'}` as a child is JSX text too.
            const parentIsChild = node.__child === true;
            if (parentIsChild) for (const s of literalText(node.expression)) report(node.start, `hardcoded JSX text "${s}"`);
        }

        if (node.type === 'JSXElement' || node.type === 'JSXFragment') {
            for (const child of node.children) if (child.type === 'JSXExpressionContainer') child.__child = true;
        }
        for (const key of Object.keys(node)) {
            if (key === 'start' || key === 'end' || key === 'type' || key === '__child') continue;
            visit(node[key]);
        }
    };
    visit(ast);
    return found;
}

export function lintTarget(name) {
    return targets[name].roots
        .flatMap((root) => sourceFiles(join(repo, root)))
        .flatMap((file) => lintSource(readFileSync(file, 'utf8'), relative(repo, file)));
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
    const requested = process.argv.slice(2);
    const names = requested.length ? requested : Object.keys(targets);
    let total = 0;
    for (const name of names) {
        if (!targets[name]) {
            console.error(`unknown target "${name}" (expected: ${Object.keys(targets).join(', ')})`);
            process.exit(2);
        }
        const found = lintTarget(name);
        total += found.length;
        for (const f of found) console.log(`${f.path}:${f.line}:${f.column}  ${f.message}`);
        console.log(`i18n lint (${name}): ${found.length} violation${found.length === 1 ? '' : 's'}`);
    }
    if (total > 0) {
        console.error('\nEvery user-facing string goes through the i18n layer (CLAUDE.md rule 35).');
        process.exit(1);
    }
}
