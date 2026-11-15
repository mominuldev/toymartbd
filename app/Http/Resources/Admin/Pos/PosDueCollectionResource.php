<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Pos;

use App\Domain\Pos\Models\PosDueCollection;
use App\Domain\Pos\Models\PosDueCollectionAllocation;
use App\Http\Resources\ApiResource;
use App\Support\Money;
use App\Support\PhoneNumber;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A taken due collection: how much was applied, what was handed over, the change to give
 * (server-computed — the register never subtracts), what the customer still owes, and the
 * orders it paid on (FIFO).
 *
 * @property PosDueCollection $resource
 */
#[SchemaName('PosDueCollection')]
final class PosDueCollectionResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $collection = $this->resource;
        $collection->loadMissing(['customer', 'allocations.order']);
        $orders = [];
        foreach ($collection->allocations->sortBy('id') as $allocation) {
            /** @var PosDueCollectionAllocation $allocation */
            $number = $allocation->order->number;
            $orders[$number] = ($orders[$number] ?? Money::zero())->plus($allocation->amount);
        }

        return [
            /** @var int */
            'id' => $collection->id,
            'customer' => [
                'name' => $collection->customer->name,
                /** 01XXXXXXXXX */
                'phone' => PhoneNumber::tryParse($collection->customer->phone)?->national() ?? '',
            ],
            'collected' => $collection->collected->toDecimal(),
            'tendered_total' => $collection->tendered_total->toDecimal(),
            'cash_tendered' => $collection->cash_tendered->toDecimal(),
            'change_due' => $collection->change_due->toDecimal(),
            'remaining_due' => $collection->remaining_due->toDecimal(),
            /** @var list<array{order_number: string, applied: string}> */
            'orders' => array_map(
                static fn (string $number, Money $applied): array => ['order_number' => $number, 'applied' => $applied->toDecimal()],
                array_map(strval(...), array_keys($orders)),
                array_values($orders),
            ),
            /** @var string|null */
            'created_at' => $collection->created_at?->toIso8601String(),
        ];
    }
}
