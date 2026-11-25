<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Recommendations;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Collection;

/**
 * Everything the product page recommends (PRD §3.1a), already through every guardrail:
 * live, listed online, in stock, never the product itself, capped, and each product in at
 * most one block (upsell > fbt > cross_sell > age_progression).
 */
final readonly class ProductRecommendations
{
    /**
     * @param  list<UpsellRecommendation>  $upsell
     * @param  Collection<int, Product>  $crossSell
     * @param  Collection<int, Product>  $ageProgression
     */
    public function __construct(
        public array $upsell,
        public Collection $crossSell,
        public Collection $ageProgression,
        public ?FrequentlyBoughtTogether $fbt,
    ) {}

    public static function none(): self
    {
        return new self([], new Collection, new Collection, null);
    }

    /**
     * Every recommended product id (not the source product itself), so other rows on the
     * page — "You may also like" — can leave them out.
     *
     * @return list<int>
     */
    public function productIds(): array
    {
        $ids = array_map(static fn (UpsellRecommendation $u): int => $u->product->id, $this->upsell);
        foreach ($this->fbt->items ?? [] as $i => $item) {
            if ($i > 0) {
                $ids[] = $item->product->id;
            }
        }

        return array_values(array_unique([...$ids, ...$this->crossSell->modelKeys(), ...$this->ageProgression->modelKeys()]));
    }
}
