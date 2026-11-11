import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';

// Stand-in for lint rules until ESLint is wired (needs typescript-eslint support for TS 7).
const root = join(__dirname, '..');

function files(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) return name === 'test' ? [] : files(path);
        return /\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name) ? [path] : [];
    });
}

const sources = files(root).map((path) => ({ path: relative(root, path), text: readFileSync(path, 'utf8') }));

// Language names are shown in their own language on purpose (LanguageSwitch).
const allowedText = new Set(['EN', 'বাং', 'TM']);

describe('admin source conventions', () => {
    it('has no hardcoded user-facing text in JSX (rule 35, P3-I18N-03)', () => {
        // JSX text only: .tsx files, and segments free of code punctuation (generics,
        // object literals and arrow bodies all contain at least one of these).
        const offenders = sources.filter(({ path }) => path.endsWith('.tsx')).flatMap(({ path, text }) =>
            [...text.matchAll(/>\s*([^<>{}\n]*[A-Za-zঀ-৿][^<>{}\n]*?)\s*</g)]
                .map((m) => m[1]!.trim())
                .filter((s) => s !== '' && !allowedText.has(s) && !/[,:;=()'"`]/.test(s))
                .map((s) => `${path}: "${s}"`),
        );
        expect(offenders).toEqual([]);
    });

    it('has no hardcoded hex colours or px values in class names (rule 26)', () => {
        const offenders = sources.flatMap(({ path, text }) =>
            [...text.matchAll(/(#[0-9a-fA-F]{3,8}\b|\[\d+px\])/g)]
                .filter((m) => !text.slice(Math.max(0, m.index! - 2), m.index!).includes('&'))
                .map((m) => `${path}: ${m[0]}`),
        );
        expect(offenders).toEqual([]);
    });
});
