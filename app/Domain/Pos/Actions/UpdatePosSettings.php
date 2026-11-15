<?php

declare(strict_types=1);

namespace App\Domain\Pos\Actions;

use App\Domain\Pos\Services\PosDiscountSettings;
use App\Domain\Pos\Services\PosDueSettings;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Store settings › Point of sale: the most a cashier may take off a sale (rule 33d) and the due
 * (baki) limit per customer (rule 33c). Both apply from the next quote; a sale already charged
 * keeps its discount and its due. `updated_by` records who saved each.
 */
final readonly class UpdatePosSettings
{
    public function __construct(private PosDiscountSettings $discounts, private PosDueSettings $dues) {}

    /**
     * @param  string  $maxPercent  a decimal percent, 0–100 (0 turns cashier discounts off)
     * @param  Money  $dueLimit  per customer (0 stops new sales on due; collecting old due still works)
     */
    public function handle(string $maxPercent, Money $dueLimit, ?int $userId): void
    {
        DB::transaction(function () use ($maxPercent, $dueLimit, $userId): void {
            Setting::put(PosDiscountSettings::KEY, self::normalise($maxPercent), SettingGroup::Checkout, false, $userId);
            Setting::put(PosDueSettings::KEY, $dueLimit->toDecimal(), SettingGroup::Checkout, false, $userId);
        });
        $this->discounts->refresh();
        $this->dues->refresh();
    }

    /** `10.00` → `10`, `12.50` → `12.5` — the cap as the quote and the cart hint show it. */
    private static function normalise(string $percent): string
    {
        $trimmed = str_contains($percent, '.') ? rtrim(rtrim($percent, '0'), '.') : $percent;
        $trimmed = ltrim($trimmed, '0');

        return $trimmed === '' || str_starts_with($trimmed, '.') ? '0'.$trimmed : $trimmed;
    }
}
