<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Pos;

use App\Domain\Pos\Models\PosHeldSale;
use App\Http\Resources\ApiResource;
use App\Support\PhoneNumber;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A parked cart: what to put back on the register (lines, coupon, the phone in the national
 * form the cashier typed). No prices — resuming re-quotes.
 *
 * @property PosHeldSale $resource
 */
#[SchemaName('PosHeldSale')]
final class PosHeldSaleResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $hold = $this->resource;
        $lines = [];
        foreach ($hold->lineMap() as $variantId => $quantity) {
            $lines[] = ['variant_id' => $variantId, 'quantity' => $quantity];
        }
        $phone = $hold->customer_phone === null ? null : PhoneNumber::tryParse($hold->customer_phone);

        return [
            /** @var int */
            'id' => $hold->id,
            /** @var string|null */
            'label' => $hold->label,
            /** @var list<array{variant_id: int, quantity: int}> */
            'lines' => $lines,
            /** @var int */
            'item_count' => array_sum($hold->lineMap()),
            /** @var string|null */
            'coupon_code' => $hold->coupon_code,
            /**
             * 01XXXXXXXXX.
             *
             * @var string|null
             */
            'customer_phone' => $phone?->national(),
            /** @var string|null */
            'held_at' => $hold->created_at?->toIso8601String(),
            /** @var string|null */
            'resumed_at' => $hold->resumed_at?->toIso8601String(),
        ];
    }
}
