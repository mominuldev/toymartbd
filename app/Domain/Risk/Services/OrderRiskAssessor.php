<?php

declare(strict_types=1);

namespace App\Domain\Risk\Services;

use App\Domain\Risk\DTOs\RiskAssessment;
use App\Domain\Risk\DTOs\RiskInput;
use App\Support\Money;

/**
 * The launch risk check (DATABASE-DESIGN §5.2, rule 33b) — deliberately minimal: our
 * own order history only, no courier data (no merchant account yet).
 *
 *  - signal `first_order` (weight 10): the phone has never ordered;
 *  - hold `first_order_above_threshold`: first order at or above
 *    config('risk.first_order_hold_threshold') — held regardless of score (rule 33b);
 *  - hold `cod_advance_required`: the COD advance (delivery.cod_advance) must be
 *    arranged by phone, since no online gateway exists to take it;
 *  - hold `high_risk_score`: score ≥ the `high` level.
 *
 * Pure: no reads, no writes. It never cancels anything (rules 23, 24b).
 */
final readonly class OrderRiskAssessor
{
    public function assess(RiskInput $input): RiskAssessment
    {
        $signals = [];
        if ($input->priorOrders === 0) {
            $signals[] = ['code' => 'first_order', 'weight' => self::weight('first_order')];
        }

        $score = min(100, array_sum(array_column($signals, 'weight')));
        $level = self::level($score);

        $hold = [];
        if ($level === 'high') {
            $hold[] = 'high_risk_score';
        }
        if ($input->priorOrders === 0 && ! $input->orderValue->lessThan(self::threshold())) {
            $hold[] = 'first_order_above_threshold';
        }
        if ($input->codAdvanceRequired) {
            $hold[] = 'cod_advance_required';
        }

        return new RiskAssessment($score, $level, $signals, $hold);
    }

    public static function threshold(): Money
    {
        $value = config('risk.first_order_hold_threshold', '3000.00');

        return Money::of(is_string($value) || is_int($value) ? (string) $value : '3000.00');
    }

    private static function weight(string $signal): int
    {
        $value = config('risk.weights.'.$signal, 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function level(int $score): string
    {
        $high = config('risk.levels.high', 70);
        $medium = config('risk.levels.medium', 40);

        return match (true) {
            $score >= (is_numeric($high) ? (int) $high : 70) => 'high',
            $score >= (is_numeric($medium) ? (int) $medium : 40) => 'medium',
            default => 'low',
        };
    }
}
