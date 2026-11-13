<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * One block type's JSON schema, enforced server-side. `rules()` are Laravel validation rules
 * for the content object (keys relative to it; the FormRequest prefixes them with `content.`).
 * `normalize()` runs on validated input before it is stored: it keeps only known keys, trims
 * text, turns empty strings into null and sanitises rich text, so every stored row has the
 * same shape. `defaults()` is the content of a freshly added block and must itself validate.
 *
 * Bilingual copy is a pair of keys, `x` (English, required where the block needs copy) and
 * `x_bn` (Bangla, optional: the storefront falls back to English, never to an empty string).
 */
abstract class BlockSchema
{
    /** Relative storefront path or an https URL; never `//host`, `javascript:` or whitespace. */
    public const string HREF_PATTERN = '#^(?:/(?!/)[^\s]*|https://[^\s/]+[^\s]*)$#';

    /** @return array<string, mixed> */
    abstract public function rules(): array;

    /** @return array<string, mixed> */
    abstract public function defaults(): array;

    /**
     * @param  array<string, mixed>  $content  validated input
     * @return array<string, mixed>
     */
    abstract public function normalize(array $content): array;

    /** @return array<string, list<mixed>> `key` and `key_bn` */
    protected static function text(string $key, int $max, bool $required = true): array
    {
        return [
            $key => [$required ? 'required' : 'nullable', 'string', 'max:'.$max],
            $key.'_bn' => ['nullable', 'string', 'max:'.$max],
        ];
    }

    /** @return list<mixed> */
    protected static function href(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:2048', 'regex:'.self::HREF_PATTERN];
    }

    protected static function imageMedia(): Exists
    {
        return Rule::exists('media', 'id')->where('kind', 'image');
    }

    /**
     * Copy both halves of a bilingual pair, trimmed; an empty value becomes null.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    protected static function copyText(array $from, array &$to, string ...$keys): void
    {
        foreach ($keys as $key) {
            $to[$key] = self::str($from[$key] ?? null);
            $to[$key.'_bn'] = self::str($from[$key.'_bn'] ?? null);
        }
    }

    protected static function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    protected static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    protected static function bool(mixed $value, bool $default): bool
    {
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return list<int> */
    protected static function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_map(intval(...), array_filter($value, is_numeric(...)))));
    }
}
