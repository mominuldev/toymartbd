<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Contracts;

use Illuminate\Database\Query\Builder;

/**
 * Available stock per variant as a subquery the storefront listing can join — the
 * "in stock" filter and facet (P4-CAT-13). Declared in Catalog so ProductListing never
 * depends on the Inventory domain (deptrac); App\Domain\Inventory\Services\
 * StockAvailability implements it, and AppServiceProvider binds it.
 */
interface AvailableStockQuery
{
    /**
     * Rows of `(variant_id, available)` where available = SUM(on_hand - reserved) across
     * locations. A variant with no stock row has no row here (treat as 0).
     */
    public function availableByVariant(): Builder;
}
