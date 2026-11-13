<?php

declare(strict_types=1);

namespace App\Domain\Risk\Services;

use App\Domain\Risk\DTOs\OrderContext;
use App\Domain\Risk\DTOs\PhoneHistory;
use App\Domain\Risk\DTOs\RiskBreakdown;
use App\Domain\Risk\Enums\RiskFactor;
use App\Support\Money;

/**
 * The weighted score (DATABASE-DESIGN §5.2), 0–100, higher = riskier. Pure: history and
 * settings in, breakdown out. Each factor contributes `round(weight × strength)` with
 * strength in [0, 1], so a contribution never exceeds its weight and the score is the sum.
 *
 *  - cancellation_rate   customer-caused cancellations ÷ prior orders, × confidence
 *                        (full weight from 3 prior orders, §5.2);
 *  - courier_return_rate refused ÷ (delivered + refused), × confidence over those parcels;
 *  - failed_delivery     failed attempts, full weight at 3;
 *  - first_order         the phone has never ordered (mild, §5.2);
 *  - value_above_norm    order value > multiplier × the phone's average delivered order;
 *  - address_quality     address line under 15 characters, or no upazila/area;
 *  - rapid_repeat        another order from the phone within the window (duplicate submit?).
 *
 * The score is ADVISORY. Nothing here, or anywhere downstream, cancels an order (rule 23).
 */
final readonly class RiskScorer
{
    public const int FULL_CONFIDENCE_ORDERS = 3;

    public const int SHORT_ADDRESS = 15;

    public function __construct(private RiskSettings $settings) {}

    public function score(PhoneHistory $history, ?OrderContext $order = null): RiskBreakdown
    {
        $w = $this->settings->weights();
        $factors = [];

        $n = $history->totalOrders;
        $rate = $n > 0 ? $history->customerCancelCount / $n : 0.0;
        $factors[] = self::factor(RiskFactor::CancellationRate, $w, $rate * self::confidence($n), sprintf('%d/%d', $history->customerCancelCount, $n));

        $parcels = $history->deliveredCount + $history->refusedCount;
        $returnRate = $parcels > 0 ? $history->refusedCount / $parcels : 0.0;
        $factors[] = self::factor(RiskFactor::CourierReturnRate, $w, $returnRate * self::confidence($parcels), sprintf('%d/%d', $history->refusedCount, $parcels));

        $factors[] = self::factor(RiskFactor::FailedDelivery, $w, min(1.0, $history->failedDeliveryCount / self::FULL_CONFIDENCE_ORDERS), (string) $history->failedDeliveryCount);

        $factors[] = self::factor(RiskFactor::FirstOrder, $w, $n === 0 ? 1.0 : 0.0, (string) $n);

        if ($order !== null) {
            $average = $history->averageDeliveredValue;
            $norm = $average !== null ? Money::of($average)->times($this->settings->valueNormMultiplier()) : null;
            $above = $norm !== null && $order->orderValue->greaterThan($norm);
            $factors[] = self::factor(RiskFactor::ValueAboveNorm, $w, $above ? 1.0 : 0.0, $average === null ? '—' : $order->orderValue->toDecimal().'/'.$average);

            $line = trim((string) $order->addressLine);
            $poor = $order->addressLine !== null && (mb_strlen($line) < self::SHORT_ADDRESS || ! $order->hasLocality);
            $factors[] = self::factor(RiskFactor::AddressQuality, $w, $poor ? 1.0 : 0.0, (string) mb_strlen($line));

            $factors[] = self::factor(RiskFactor::RapidRepeat, $w, $history->recentOrders > 0 ? 1.0 : 0.0, (string) $history->recentOrders);
        }

        $score = min(100, array_sum(array_column($factors, 'contribution')));

        return new RiskBreakdown($score, $this->settings->level($score), $factors);
    }

    private static function confidence(int $count): float
    {
        return min(1.0, $count / self::FULL_CONFIDENCE_ORDERS);
    }

    /**
     * @param  array<string, int>  $weights
     * @return array{code: string, weight: int, value: string, contribution: int}
     */
    private static function factor(RiskFactor $factor, array $weights, float $strength, string $value): array
    {
        $weight = $weights[$factor->value];
        $strength = max(0.0, min(1.0, $strength));

        return [
            'code' => $factor->value,
            'weight' => $weight,
            'value' => $value,
            'contribution' => (int) min($weight, round($weight * $strength)),
        ];
    }
}
