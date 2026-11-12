<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Marketing\Contracts\CustomerOrderHistory;
use App\Support\PhoneNumber;

/** No orders exist yet (Ordering, P4 batch 5, binds the real answer). */
final class NoCustomerOrderHistory implements CustomerOrderHistory
{
    public function hasOrdered(PhoneNumber $phone): bool
    {
        return false;
    }
}
