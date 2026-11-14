<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Pos;

use App\Domain\Pos\Models\PosSale;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A charged sale: the order it created, the total, what was handed over and the change to
 * give back — the register shows "Change ৳545" from this, never from its own arithmetic.
 *
 * @property PosSale $resource
 */
#[SchemaName('PosSale')]
final class PosSaleResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $sale = $this->resource;

        return [
            /** @var int */
            'id' => $sale->id,
            /** @var int */
            'order_id' => $sale->order_id,
            'order_number' => $sale->order->number,
            'grand_total' => $sale->grand_total->toDecimal(),
            'tendered_total' => $sale->tendered_total->toDecimal(),
            'cash_tendered' => $sale->cash_tendered->toDecimal(),
            'change_due' => $sale->change_due->toDecimal(),
            /** @var string|null */
            'created_at' => $sale->created_at?->toIso8601String(),
        ];
    }
}
