<?php

declare(strict_types=1);

namespace App\Domain\Risk\Actions;

use App\Domain\Risk\Services\RiskSettings;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;

/**
 * Saves the Risk rules form as the `risk.rules` settings document. Validation (weights total
 * ≤ 100, medium < high) is the FormRequest's; only future scores change — an order already
 * scored keeps the breakdown it was held on.
 */
final readonly class SaveRiskRules
{
    public function __construct(private RiskSettings $settings) {}

    /**
     * @param  array{weights: array<string, int>, medium_threshold: int, high_threshold: int, first_order_hold_threshold: string, value_norm_multiplier: string, rapid_repeat_minutes: int}  $rules
     */
    public function handle(array $rules, ?int $userId): RiskSettings
    {
        Setting::put(RiskSettings::KEY, $rules, SettingGroup::Risk, updatedBy: $userId);
        $this->settings->flush();

        return $this->settings;
    }
}
