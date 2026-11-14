<?php

declare(strict_types=1);

namespace App\Domain\Pos\DTOs;

use App\Domain\Cart\DTOs\PriceBreakdown;

/**
 * A priced register cart: the checkout pricing pipeline's breakdown (no delivery line at
 * the counter), the stock available for each line at the session's branch, what stops it
 * being charged (`blockers`, field-level), and the tender plan when tenders were given.
 */
final readonly class PosQuote
{
    /**
     * @param  array<int, int>  $available  variant id → available at the branch now
     * @param  list<array{field: string, message: string}>  $blockers
     */
    public function __construct(
        public PriceBreakdown $breakdown,
        public array $available,
        public array $blockers,
        public ?TenderPlan $tenders,
    ) {}

    public function chargeable(): bool
    {
        return $this->blockers === [] && ($this->tenders === null || $this->tenders->settles());
    }
}
