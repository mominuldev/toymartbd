<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * The storefront age facet (03-storefront-spec §A7.2), in months. A band covers
 * ages `min ≤ x < max` (`max` null = open-ended). A product is in a band when its
 * own inclusive range `[age_min_months, age_max_months]` (null bounds open) shares
 * at least one age with it — so bands overlap, and a 0–12 month toy is in both
 * `0-12` and `12-24` (it fits a 12-month-old), consistent with `filter[age]=12`.
 */
enum AgeBand: string
{
    case Infant = '0-12';
    case Toddler = '12-24';
    case Preschool = '24-48';
    case Early = '48-72';
    case Middle = '72-96';
    case Tween = '96-144';
    case Teen = '144-';

    public function min(): int
    {
        return match ($this) {
            self::Infant => 0,
            self::Toddler => 12,
            self::Preschool => 24,
            self::Early => 48,
            self::Middle => 72,
            self::Tween => 96,
            self::Teen => 144,
        };
    }

    /** Exclusive upper bound in months; null for the open-ended last band. */
    public function max(): ?int
    {
        return match ($this) {
            self::Infant => 12,
            self::Toddler => 24,
            self::Preschool => 48,
            self::Early => 72,
            self::Middle => 96,
            self::Tween => 144,
            self::Teen => null,
        };
    }
}
