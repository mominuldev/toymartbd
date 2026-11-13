<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Marketing\Actions\SavePromotion;
use App\Domain\Marketing\Enums\PromotionType;
use App\Domain\Marketing\Events\PromotionChanged;
use App\Domain\Marketing\Models\Promotion;
use DateTimeInterface;

/**
 * ISR at promotion start and end (P7): every active promotion whose `starts_at` or
 * `ends_at` fell in (`$since`, `$until`] gets a PromotionChanged, so the storefront drops
 * the cached cards, product pages and the flash-sale page at the boundary rather than at
 * the next unrelated write. Read-only besides the events.
 */
final class PromotionBoundaries
{
    /** @return int promotions that crossed a boundary */
    public function announce(DateTimeInterface $since, DateTimeInterface $until): int
    {
        $crossed = Promotion::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereBetween('starts_at', [$since, $until])->orWhereBetween('ends_at', [$since, $until]))
            ->get()
            ->filter(static fn (Promotion $p): bool => ($p->starts_at !== null && $p->starts_at->greaterThan($since) && ! $p->starts_at->greaterThan($until))
                || ($p->ends_at !== null && $p->ends_at->greaterThan($since) && ! $p->ends_at->greaterThan($until)));

        foreach ($crossed as $promotion) {
            PromotionChanged::dispatch($promotion->id, $promotion->type === PromotionType::FlashSale, SavePromotion::slugs($promotion));
        }

        return $crossed->count();
    }
}
