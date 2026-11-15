<?php

declare(strict_types=1);

namespace App\Domain\Pos\Services;

use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Support\Money;

/**
 * The store-wide due (baki) limit per customer — owner decision 2026-11-15. Stored with the
 * other checkout rules in `settings` (group `checkout`, key `checkout.pos_due_limit`); a key
 * nobody has saved falls back to {@see self::DEFAULT_LIMIT}.
 *
 * PLACEHOLDER business value (UI-UX-DESIGN-SPEC §10): ৳5,000 until the owner sets it. There
 * is no per-customer override yet — every customer gets the store default.
 */
final class PosDueSettings
{
    public const string KEY = 'checkout.pos_due_limit';

    public const string DEFAULT_LIMIT = '5000.00';

    private ?Money $limit = null;

    public function limit(): Money
    {
        if ($this->limit === null) {
            $value = Setting::query()->where('group', SettingGroup::Checkout->value)->find(self::KEY)?->value;
            $this->limit = is_string($value) || is_int($value) ? Money::of((string) $value) : Money::of(self::DEFAULT_LIMIT);
        }

        return $this->limit;
    }

    /** Forget the value read in this instance (after an edit, in tests). */
    public function refresh(): void
    {
        $this->limit = null;
    }
}
