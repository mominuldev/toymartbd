<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Risk;

use App\Domain\Ordering\Models\Order;
use App\Domain\Risk\DTOs\RiskBreakdown;
use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Domain\Risk\Services\RiskSettings;
use App\Models\User;
use App\Support\PhoneNumber;
use Carbon\CarbonInterface;

/**
 * Shapes the three risk blocks the admin shows — always as SEPARATE blocks (rule 24):
 *
 *  - `ours`    OUR score of the phone, its breakdown and when we computed it;
 *  - `status`  the staff decision (list status, reason, who, when);
 *  - `courier` what each courier officially reports, each with its own timestamp, or
 *              `connected: false` with a reason — never a zero (rule 33b).
 *
 * plus `order_risk`, the score an order was held on (its own snapshot, from `risk_flags`).
 */
final class RiskProfilePresenter
{
    /**
     * @param  array{total_orders: int, delivered: int, cancelled: int, returned: int, failed_delivery: int, refused: int}|null  $previewCounts
     * @return array{stored: bool, internal_score: int, level: string, breakdown: list<array{code: string, weight: int, value: string, contribution: int}>, score_computed_at: string|null, counts: array{total_orders: int, delivered: int, cancelled: int, returned: int, failed_delivery: int, refused: int}, last_order_at: string|null}
     */
    public static function ours(?CustomerRiskProfile $profile, ?RiskBreakdown $preview = null, ?array $previewCounts = null): array
    {
        $settings = app(RiskSettings::class);
        if ($profile === null) {
            return [
                'stored' => false,
                'internal_score' => $preview->score ?? 0,
                'level' => $preview->level ?? 'low',
                'breakdown' => $preview->factors ?? [],
                'score_computed_at' => $preview !== null ? now()->toIso8601String() : null,
                'counts' => $previewCounts ?? ['total_orders' => 0, 'delivered' => 0, 'cancelled' => 0, 'returned' => 0, 'failed_delivery' => 0, 'refused' => 0],
                'last_order_at' => null,
            ];
        }

        /** @var list<array{code: string, weight: int, value: string, contribution: int}> $breakdown */
        $breakdown = $profile->score_breakdown ?? [];

        return [
            'stored' => true,
            'internal_score' => $profile->internal_score,
            'level' => $settings->level($profile->internal_score),
            'breakdown' => $breakdown,
            'score_computed_at' => $profile->score_computed_at?->toIso8601String(),
            'counts' => [
                'total_orders' => $profile->total_orders,
                'delivered' => $profile->delivered_count,
                'cancelled' => $profile->cancelled_count,
                'returned' => $profile->returned_count,
                'failed_delivery' => $profile->failed_delivery_count,
                'refused' => $profile->refused_count,
            ],
            'last_order_at' => $profile->last_order_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{value: string, reason: string|null, set_by: string|null, set_at: string|null}
     */
    public static function status(?CustomerRiskProfile $profile): array
    {
        if ($profile === null) {
            return ['value' => 'normal', 'reason' => null, 'set_by' => null, 'set_at' => null];
        }
        $name = $profile->status_set_by !== null ? User::query()->whereKey($profile->status_set_by)->value('name') : null;

        return [
            'value' => $profile->status->value,
            'reason' => $profile->status_reason,
            'set_by' => is_string($name) ? $name : null,
            'set_at' => $profile->status_set_at?->toIso8601String(),
        ];
    }

    /**
     * The order's own snapshot — what it was scored and held on at placement.
     *
     * @return array{score: int|null, level: string|null, verification_status: string, hold_reasons: list<string>, signals: list<array<string, mixed>>, computed_at: string|null}
     */
    public static function orderRisk(Order $order): array
    {
        $flags = $order->risk_flags ?? [];
        $holds = is_array($flags['hold_reasons'] ?? null) ? array_values(array_filter($flags['hold_reasons'], 'is_string')) : [];
        $signals = is_array($flags['signals'] ?? null) ? array_values(array_filter($flags['signals'], 'is_array')) : [];

        return [
            'score' => $order->risk_score,
            'level' => $order->risk_level?->value,
            'verification_status' => $order->verification_status->value,
            'hold_reasons' => $holds,
            /** @var list<array<string, mixed>> */
            'signals' => $signals,
            'computed_at' => is_string($flags['computed_at'] ?? null) ? $flags['computed_at'] : null,
        ];
    }

    public static function phone(string $e164, ?User $user): string
    {
        return $user?->can('viewPii', Order::class) === true ? $e164 : (PhoneNumber::tryParse($e164)?->masked() ?? '***');
    }

    public static function iso(?CarbonInterface $at): ?string
    {
        return $at?->toIso8601String();
    }
}
