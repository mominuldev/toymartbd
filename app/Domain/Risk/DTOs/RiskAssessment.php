<?php

declare(strict_types=1);

namespace App\Domain\Risk\DTOs;

/**
 * Our advisory risk answer for one order. Never a cancellation (rules 23, 24b): the
 * strongest outcome is `holdReasons` non-empty → the order goes on hold for a call.
 *
 * `level` is `low` | `medium` | `high` (§5.2 thresholds, from the Risk rules settings).
 * `signals` is the transparent breakdown staff see — every factor that contributed, each
 * `{code, weight, value, contribution}`; Σ contribution = score. `holdReasons` are stable
 * codes: `high_risk_score`, `first_order_above_threshold`, `cod_advance_required`,
 * `blacklisted`, `watchlist`.
 */
final readonly class RiskAssessment
{
    /**
     * @param  list<array{code: string, weight: int, value: string, contribution: int}>  $signals
     * @param  list<string>  $holdReasons
     */
    public function __construct(
        public int $score,
        public string $level,
        public array $signals,
        public array $holdReasons,
        public ?string $profileStatus = null,
    ) {}

    public function shouldHold(): bool
    {
        return $this->holdReasons !== [];
    }

    /**
     * The `orders.risk_flags` document.
     *
     * @return array{signals: list<array{code: string, weight: int, value: string, contribution: int}>, hold_reasons: list<string>, profile_status: string|null, computed_at: string}
     */
    public function toFlags(): array
    {
        return [
            'signals' => $this->signals,
            'hold_reasons' => $this->holdReasons,
            'profile_status' => $this->profileStatus,
            'computed_at' => now()->toIso8601ZuluString(),
        ];
    }
}
