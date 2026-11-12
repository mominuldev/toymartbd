<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Services\CustomerAggregates;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild the whole projection from orders (`toymart:customers:rebuild-aggregates`). One
 * transaction per chunk, so a long rebuild never holds locks on every customer at once;
 * each chunk is a full recomputation, so an interrupted run can simply be re-run.
 */
final readonly class RebuildCustomerAggregates
{
    public const int CHUNK = 500;

    public function __construct(private CustomerAggregates $aggregates) {}

    /** @return int customers rebuilt */
    public function handle(int $chunk = self::CHUNK): int
    {
        $total = 0;

        Customer::withTrashed()->select('id')->chunkById($chunk, function ($customers) use (&$total): void {
            /** @var list<int> $ids */
            $ids = $customers->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
            $total += DB::transaction(fn (): int => $this->aggregates->refresh($ids));
        });

        return $total;
    }
}
