<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Services;

use Carbon\CarbonImmutable;

/**
 * Schedule windows of snapshot blocks. A block is live at `t` when it is enabled and
 * `starts_at ≤ t < ends_at` (either bound open when null). Instants are stored UTC; the admin
 * enters and reads them in Asia/Dhaka, so "15 Jun 00:00" means Dhaka midnight (18:00 UTC the
 * day before).
 */
final class BlockSchedule
{
    /** @param  array<string, mixed>  $block */
    public static function isLive(array $block, CarbonImmutable $at): bool
    {
        if (! (bool) ($block['is_enabled'] ?? true)) {
            return false;
        }

        $starts = self::instant($block['starts_at'] ?? null);
        $ends = self::instant($block['ends_at'] ?? null);

        return ($starts === null || $starts->lessThanOrEqualTo($at)) && ($ends === null || $at->lessThan($ends));
    }

    /**
     * Every start or end instant of enabled blocks — the moments the live page changes by itself.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<CarbonImmutable>
     */
    public static function boundaries(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (! (bool) ($block['is_enabled'] ?? true)) {
                continue;
            }
            foreach (['starts_at', 'ends_at'] as $key) {
                $instant = self::instant($block[$key] ?? null);
                if ($instant !== null) {
                    $out[] = $instant;
                }
            }
        }

        return $out;
    }

    private static function instant(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }
}
