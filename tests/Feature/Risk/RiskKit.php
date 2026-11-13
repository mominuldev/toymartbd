<?php

declare(strict_types=1);

namespace Tests\Feature\Risk;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\RiskLevel;
use App\Domain\Ordering\Enums\VerificationStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Risk\Services\RiskSettings;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;

/** Shared helpers for the Risk tests. Bangla names on every fixture (rule 34). */
final class RiskKit
{
    public const string PHONE = '+8801712345678';

    /** An order the risk check held for verification. */
    public static function heldOrder(string $phone = self::PHONE, array $holds = ['first_order_above_threshold'], int $score = 10): Order
    {
        return Order::factory()->status(OrderStatus::OnHold)->create([
            'shipping_phone' => $phone,
            'shipping_name' => 'রাহেলা খাতুন',
            'grand_total' => '3500.00',
            'subtotal' => '3500.00',
            'due_total' => '3500.00',
            'placed_at' => now(),
            'risk_score' => $score,
            'risk_level' => RiskLevel::Low,
            'risk_flags' => [
                'signals' => [['code' => 'first_order', 'weight' => 10, 'value' => '0', 'contribution' => 10]],
                'hold_reasons' => $holds,
                'profile_status' => null,
                'computed_at' => now()->toIso8601ZuluString(),
            ],
            'verification_status' => VerificationStatus::Pending,
        ]);
    }

    /**
     * Save risk rules: only the listed weights are non-zero.
     *
     * @param  array<string, int>  $weights
     */
    public static function rules(array $weights, int $medium = 40, int $high = 70, string $threshold = '3000.00'): void
    {
        $all = array_fill_keys(\App\Domain\Risk\Enums\RiskFactor::values(), 0);
        Setting::put(RiskSettings::KEY, [
            'weights' => array_merge($all, $weights),
            'medium_threshold' => $medium,
            'high_threshold' => $high,
            'first_order_hold_threshold' => $threshold,
            'value_norm_multiplier' => '2',
            'rapid_repeat_minutes' => 10,
        ], SettingGroup::Risk);
    }
}
