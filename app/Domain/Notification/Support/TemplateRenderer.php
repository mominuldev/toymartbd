<?php

declare(strict_types=1);

namespace App\Domain\Notification\Support;

/**
 * `{variable}` substitution. Only names on the template's allowlist are substituted;
 * the editor refuses to save a body naming anything else ({@see unknownPlaceholders()}),
 * so an unlisted `{x}` can only reach here from a hand-edited row and is left as is.
 * Values are plain text — no HTML, no Blade — and email escapes them on output.
 */
final class TemplateRenderer
{
    private const PATTERN = '/\{([a-z][a-z0-9_]*)\}/';

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $allowed
     */
    public static function render(string $text, array $values, array $allowed): string
    {
        $rendered = preg_replace_callback(self::PATTERN, static function (array $match) use ($values, $allowed): string {
            $name = $match[1];

            return in_array($name, $allowed, true) && array_key_exists($name, $values) ? $values[$name] : $match[0];
        }, $text) ?? $text;

        // An empty optional variable (refund_sentence) must not leave a double space.
        return trim((string) preg_replace('/ {2,}/', ' ', $rendered));
    }

    /** @return list<string> */
    public static function placeholders(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public static function unknownPlaceholders(string $text, array $allowed): array
    {
        return array_values(array_diff(self::placeholders($text), $allowed));
    }
}
