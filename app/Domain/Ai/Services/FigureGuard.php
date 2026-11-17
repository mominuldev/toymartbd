<?php

declare(strict_types=1);

namespace App\Domain\Ai\Services;

/**
 * Rule 1 for AI text: every figure in an answer must come from the facts the server gave the
 * model. {@see self::unknown()} lists the numbers in `$text` that match no fact (exactly, or a
 * fact rounded to 0 or 1 decimal); feature code rejects or flags such an answer instead of
 * showing an invented number. Small counts (0–12, "3 things", "top 5") are always allowed.
 */
final class FigureGuard
{
    /**
     * @param  array<array-key, mixed>  $facts  any nested structure of the server's figures
     * @return list<string> the unknown figures as written
     */
    public static function unknown(string $text, array $facts): array
    {
        $allowed = [];
        array_walk_recursive($facts, static function (mixed $value) use (&$allowed): void {
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (! is_string($value)) {
                return;
            }
            foreach (self::numbers($value) as $n) {
                foreach (self::forms($n) as $form) {
                    $allowed[$form] = true;
                }
            }
        });

        $unknown = [];
        foreach (self::numbers($text, true) as $raw => $n) {
            if ((float) $n <= 12 && floor((float) $n) === (float) $n) {
                continue;
            }
            if (! isset($allowed[self::norm($n)])) {
                $unknown[] = (string) $raw;
            }
        }

        return array_values(array_unique($unknown));
    }

    /** @return array<string, string> raw => normalised digits ("2,48,600" => "248600") */
    private static function numbers(string $text, bool $keyByRaw = false): array
    {
        preg_match_all('/\d[\d,]*(?:\.\d+)?/u', $text, $m);
        $out = [];
        foreach ($m[0] as $i => $raw) {
            $n = str_replace(',', '', rtrim($raw, ','));
            $out[$keyByRaw ? $raw : (string) $i] = $n;
        }

        return $out;
    }

    /** @return list<string> */
    private static function forms(string $n): array
    {
        $f = (float) $n;

        return array_values(array_unique([self::norm($n), self::norm((string) round($f)), self::norm((string) round($f, 1)), self::norm((string) abs($f))]));
    }

    private static function norm(string $n): string
    {
        $n = ltrim($n, '+-');

        return str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : (ltrim($n, '0') ?: '0');
    }
}
