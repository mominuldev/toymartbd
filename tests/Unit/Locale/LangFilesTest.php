<?php

declare(strict_types=1);

/**
 * Every key that exists in Bangla must exist in English, and every Bangla
 * string must keep the same :placeholders as its English counterpart.
 */
function flattenLang(array $lines, string $prefix = ''): array
{
    $flat = [];

    foreach ($lines as $key => $value) {
        if (is_array($value)) {
            $flat += flattenLang($value, $prefix.$key.'.');
        } else {
            $flat[$prefix.$key] = (string) $value;
        }
    }

    return $flat;
}

function placeholders(string $line): array
{
    preg_match_all('/:([a-z_]+)/', $line, $m);
    $names = array_unique($m[1]);
    sort($names);

    return $names;
}

it('keeps bn keys and placeholders in step with en', function (string $file): void {
    $en = flattenLang(require lang_path("en/{$file}.php"));
    $bn = flattenLang(require lang_path("bn/{$file}.php"));

    foreach ($bn as $key => $line) {
        if (str_starts_with($key, 'attributes.') || str_starts_with($key, 'custom.')) {
            continue;
        }

        expect($en)->toHaveKey($key);
        expect(placeholders($line))->toBe(placeholders($en[$key]), "placeholder mismatch in {$file}.{$key}");
    }
})->with(['validation', 'auth', 'pagination', 'passwords', 'app']);

it('translates the commonly used validation rules into Bangla', function (): void {
    $bn = require lang_path('bn/validation.php');

    foreach (['required', 'string', 'email', 'numeric', 'integer', 'in', 'unique', 'exists', 'confirmed',
        'date', 'boolean', 'array', 'regex', 'image', 'mimes', 'url', 'max.string', 'min.string',
        'size.string', 'between.numeric'] as $rule) {
        expect(data_get($bn, $rule))->toBeString()->toMatch('/\p{Bengali}/u');
    }
});

it('uses no Bengali digits in any system string', function (string $file): void {
    foreach (flattenLang(require lang_path("bn/{$file}.php")) as $line) {
        expect($line)->not->toMatch('/[০-৯]/u');
    }
})->with(['validation', 'auth', 'pagination', 'passwords', 'app']);
