<?php

declare(strict_types=1);

namespace App\Domain\Notification\Mail;

/**
 * Email clients ignore CSS custom properties, so the mail views inline colour values —
 * read here from the built design tokens (packages/tokens/dist/tokens.json, storefront
 * Light mode) rather than hard-coded (rule 26). Keyed by semantic token name.
 */
final class MailTheme
{
    /** @var array<string, string>|null */
    private static ?array $tokens = null;

    public static function color(string $token): string
    {
        return self::tokens()[$token] ?? 'inherit';
    }

    /** Bangla needs a Bengali face before the Latin fallback (rule 38: and ~1.15× line-height). */
    public static function fontStack(string $locale): string
    {
        return $locale === 'bn'
            ? "'Anek Bangla', 'Noto Sans Bengali', 'Hind Siliguri', 'Nirmala UI', 'Vrinda', 'Hanken Grotesk', Arial, sans-serif"
            : "'Hanken Grotesk', 'Segoe UI', Arial, 'Noto Sans Bengali', sans-serif";
    }

    public static function lineHeight(string $locale): string
    {
        return $locale === 'bn' ? '1.73' : '1.5';
    }

    /** @return array<string, string> */
    private static function tokens(): array
    {
        if (self::$tokens !== null) {
            return self::$tokens;
        }

        $path = base_path('packages/tokens/dist/tokens.json');
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $light = is_array($decoded) && is_array($decoded['light'] ?? null) ? $decoded['light'] : [];

        return self::$tokens = array_filter($light, static fn (mixed $v): bool => is_string($v));
    }
}
