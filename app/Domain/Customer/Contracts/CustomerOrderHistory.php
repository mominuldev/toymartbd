<?php

declare(strict_types=1);

namespace App\Domain\Customer\Contracts;

use App\Domain\Customer\DTOs\CustomerOrderStats;

/**
 * What the Customer aggregate projection reads from orders. Customer declares it; Ordering
 * implements it (bound in AppServiceProvider) — Customer may not depend on Ordering
 * (deptrac), the same pattern as Catalog's AvailableStockQuery.
 */
interface CustomerOrderHistory
{
    /**
     * @param  list<int>  $customerIds
     * @return array<int, CustomerOrderStats> keyed by customer id; customers without orders are absent
     */
    public function statsFor(array $customerIds): array;
}
