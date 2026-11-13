<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Marketing\Enums\PromotionType;
use App\Domain\Marketing\Models\FlashSaleItem;
use App\Domain\Marketing\Models\Promotion;
use App\Support\Money;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the storefront shows for flash sales (P7): which variants are on a running
 * flash sale right now, at what price and until when. Display only — the cart and
 * checkout price through PromotionEngine (rule 1), which also enforces the allotment, so
 * a card cached by ISR can at worst show a badge a few seconds after a sale sold out.
 *
 * A variant in two running flash sales shows the one the engine would apply first
 * (priority descending, then oldest).
 */
final class FlashSaleLookup
{
    /** @var array<int, array{promotion_id: int, name: string, name_bn: string|null, ends_at: string, sale_price: Money, remaining: int, allotment: int}>|null */
    private ?array $byVariant = null;

    private ?string $at = null;

    /**
     * Running flash sales with their items, highest priority first.
     *
     * @return Collection<int, Promotion>
     */
    public function running(?DateTimeInterface $now = null): Collection
    {
        return Promotion::query()->runningAt($now ?? now())
            ->where('type', PromotionType::FlashSale->value)
            ->whereNotNull('ends_at')
            ->with('flashItems')
            ->get()
            ->filter(static fn (Promotion $p): bool => $p->flashItems->isNotEmpty())
            ->values();
    }

    /** The next flash sale to start (for "See the next sale"), if any. */
    public function next(?DateTimeInterface $now = null): ?Promotion
    {
        return Promotion::query()
            ->where('is_active', true)
            ->where('type', PromotionType::FlashSale->value)
            ->where('starts_at', '>', $now ?? now())
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * Variant id → its flash offer, for every variant on a running flash sale with units left.
     *
     * @return array<int, array{promotion_id: int, name: string, name_bn: string|null, ends_at: string, sale_price: Money, remaining: int, allotment: int}>
     */
    public function byVariant(?DateTimeInterface $now = null): array
    {
        $key = ($now ?? now())->format('Y-m-d H:i');
        if ($this->byVariant !== null && $this->at === $key) {
            return $this->byVariant;
        }

        $out = [];
        foreach ($this->running($now) as $promotion) {
            /** @var FlashSaleItem $item */
            foreach ($promotion->flashItems as $item) {
                if (isset($out[$item->variant_id]) || $item->remaining() < 1) {
                    continue;
                }
                $out[$item->variant_id] = [
                    'promotion_id' => $promotion->id,
                    'name' => $promotion->name,
                    'name_bn' => $promotion->name_bn,
                    'ends_at' => $promotion->ends_at?->utc()->format('Y-m-d\TH:i:s\Z') ?? '',
                    'sale_price' => $item->sale_price,
                    'remaining' => $item->remaining(),
                    'allotment' => $item->allotment,
                ];
            }
        }

        $this->at = $key;

        return $this->byVariant = $out;
    }
}
