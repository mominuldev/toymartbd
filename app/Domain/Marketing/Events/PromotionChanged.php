<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A promotion was created, edited, deactivated — or crossed its start or end time
 * (`toymart:promotions:boundaries`). Storefront pages that show promotion prices (cards,
 * product pages, the flash-sale page) are stale. `productSlugs` are the flash-sale
 * products whose pages carry the sale price and countdown.
 */
final readonly class PromotionChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param  list<string>  $productSlugs */
    public function __construct(
        public int $promotionId,
        public bool $isFlashSale,
        public array $productSlugs = [],
    ) {}
}
