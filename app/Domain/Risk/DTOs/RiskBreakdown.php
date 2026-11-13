<?php

declare(strict_types=1);

namespace App\Domain\Risk\DTOs;

/**
 * A score with the factors that make it up. `score` is exactly Σ contribution (weights total
 * ≤ 100), so what staff see adds up to the number they are shown.
 */
final readonly class RiskBreakdown
{
    /**
     * @param  list<array{code: string, weight: int, value: string, contribution: int}>  $factors
     */
    public function __construct(
        public int $score,
        public string $level,
        public array $factors,
    ) {}

    /**
     * The factors that contributed (contribution > 0) — the "signals" stored on an order.
     *
     * @return list<array{code: string, weight: int, value: string, contribution: int}>
     */
    public function signals(): array
    {
        return array_values(array_filter($this->factors, static fn (array $f): bool => $f['contribution'] > 0));
    }
}
