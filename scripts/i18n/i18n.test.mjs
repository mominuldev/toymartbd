// Self-tests for the i18n gates. Run: node --test scripts/i18n/
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { compareCatalogs, usedKeys } from './check-keys.mjs';
import { lintSource } from './lint.mjs';

const messages = (src) => lintSource(src, 'fixture.tsx').map((f) => f.message);

test('flags hardcoded JSX text in either script', () => {
    assert.deepEqual(messages('const A = () => <button>Save</button>;'), ['hardcoded JSX text "Save"']);
    assert.deepEqual(messages('const A = () => <p>সংরক্ষণ</p>;'), ['hardcoded JSX text "সংরক্ষণ"']);
    assert.deepEqual(messages("const A = () => <p>{ok ? 'Yes' : 'No'}</p>;"), ['hardcoded JSX text "Yes"', 'hardcoded JSX text "No"']);
});

test('flags literal copy in aria-label, placeholder, title and alt', () => {
    assert.deepEqual(messages('const A = () => <input aria-label="Search" placeholder="Type here" />;'), [
        'hardcoded aria-label="Search"',
        'hardcoded placeholder="Type here"',
    ]);
    assert.deepEqual(messages('const A = () => <img alt={`Photo of ${name}`} title={"Hi"} />;'), [
        'hardcoded alt="Photo of "',
        'hardcoded title="Hi"',
    ]);
});

test('allows translated text, keys, decorative alt, symbols, URLs and language self-names', () => {
    assert.deepEqual(
        messages(`const A = () => (
            <div aria-label={t('nav.primary')} title="nav.store">
                {t('x.y')} · {count} — <img alt="" /> <input placeholder="https://" />
                <span>EN</span><span>বাং</span>
            </div>
        );`),
        [],
    );
});

test('honours an i18n-ignore comment on the same or previous line', () => {
    assert.deepEqual(messages('const A = () => (\n<p>\n{/* i18n-ignore: format name */}\nAVIF</p>);'), []);
});

test('catalog comparison catches a missing key, an empty value, a placeholder drift and Bengali digits', () => {
    assert.deepEqual(compareCatalogs({ a: 'Hi {name}', b: 'x', c: 'ok' }, { a: 'হাই {nam}', c: '', d: '১' }), [
        'bn: missing key "b"',
        'bn: "c" is empty or not a string',
        'bn: "d" contains a Bengali digit (rule 36)',
        'en: missing key "d"',
        '"a": placeholders differ — en {name} vs bn {nam}',
    ]);
});

test('collects literal and dynamic admin keys', () => {
    const { literal, prefixes } = usedKeys("t('orders.title'); t(`orders.status.${s}` as MessageKey); translate(l, 'a.b');", 'x.ts');
    assert.deepEqual(literal, ['orders.title', 'a.b']);
    assert.ok(prefixes.includes('orders.status.'));
});
