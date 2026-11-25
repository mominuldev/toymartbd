<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Recommendations;

/**
 * The frequently-bought-together block (PRD X-03): `items[0]` is the product being viewed,
 * then 1–2 products bought with it in at least `catalog.fbt_min_orders` orders. Every item
 * has exactly one purchasable variant in stock; when that cannot be met the resolver
 * returns no block at all (it hides, never degrades — rule 25c).
 */
final readonly class FrequentlyBoughtTogether
{
    /** @param  non-empty-list<FrequentlyBoughtTogetherItem>  $items */
    public function __construct(public array $items) {}
}
