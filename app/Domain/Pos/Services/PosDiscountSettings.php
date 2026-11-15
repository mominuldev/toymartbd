<?php

declare(strict_types=1);

namespace App\Domain\Pos\Services;

use App\Models\Enums\SettingGroup;
use App\Models\Setting;

/**
 * The most a cashier may take off a POS sale, as a percent of the goods after promotions and
 * the coupon — owner decision 2026-11-15: any POS user, up to 10%. Stored with the other
 * checkout rules in `settings` (group `checkout`, key `checkout.pos_discount_max_percent`); a
 * key nobody has saved falls back to {@see self::DEFAULT_MAX_PERCENT}.
 */
final class PosDiscountSettings
{
    public const string KEY = 'checkout.pos_discount_max_percent';

    public const string DEFAULT_MAX_PERCENT = '10';

    private ?string $maxPercent = null;

    /** A decimal string, e.g. `10` or `12.5`. */
    public function maxPercent(): string
    {
        if ($this->maxPercent === null) {
            $value = Setting::query()->where('group', SettingGroup::Checkout->value)->find(self::KEY)?->value;
            $this->maxPercent = (is_string($value) || is_int($value) || is_float($value)) && is_numeric((string) $value)
                ? (string) $value
                : self::DEFAULT_MAX_PERCENT;
        }

        return $this->maxPercent;
    }

    /** Forget the value read in this instance (after an edit, in tests). */
    public function refresh(): void
    {
        $this->maxPercent = null;
    }
}
