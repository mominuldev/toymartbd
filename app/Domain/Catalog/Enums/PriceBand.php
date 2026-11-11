<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * The storefront price facet (03-storefront-spec §A7.2), in taka, against a
 * product's lowest active variant price. A band is `min ≤ price < max` (`max` null
 * = open-ended), so the bands partition every price.
 */
enum PriceBand: string
{
    case Under500 = '0-500';
    case To1000 = '500-1000';
    case To2500 = '1000-2500';
    case To5000 = '2500-5000';
    case Over5000 = '5000-';

    /** Inclusive lower bound, a decimal string. */
    public function min(): string
    {
        return match ($this) {
            self::Under500 => '0.00',
            self::To1000 => '500.00',
            self::To2500 => '1000.00',
            self::To5000 => '2500.00',
            self::Over5000 => '5000.00',
        };
    }

    /** Exclusive upper bound, a decimal string; null for the open-ended last band. */
    public function max(): ?string
    {
        return match ($this) {
            self::Under500 => '500.00',
            self::To1000 => '1000.00',
            self::To2500 => '2500.00',
            self::To5000 => '5000.00',
            self::Over5000 => null,
        };
    }
}
