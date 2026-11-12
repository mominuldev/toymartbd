<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Services\CustomerAggregates;
use Illuminate\Support\Facades\DB;

/** Recompute one customer's aggregates from their orders (after any order change). */
final readonly class RefreshCustomerAggregates
{
    public function __construct(private CustomerAggregates $aggregates) {}

    public function handle(int $customerId): void
    {
        DB::transaction(fn (): int => $this->aggregates->refresh([$customerId]));
    }
}
