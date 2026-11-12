<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Purchasing\Models\Supplier;
use App\Http\Resources\ApiResource;
use App\Support\Money;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A supplier. `balance` (the payable) is present only for staff with finance.view, and
 * only when the controller computed it (list and detail both do).
 *
 * @property Supplier $resource
 */
#[SchemaName('Supplier')]
final class SupplierResource extends ApiResource
{
    /** @var array{opening: Money, received: Money, paid: Money, balance: Money}|null */
    private ?array $balance = null;

    /** @param  array{opening: Money, received: Money, paid: Money, balance: Money}|null  $balance */
    public function withBalance(?array $balance): self
    {
        $this->balance = $balance;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $supplier = $this->resource;
        $balance = $this->balance;

        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            /** @var string|null */
            'contact_person' => $supplier->contact_person,
            /**
             * E.164 (+8801…).
             *
             * @var string|null
             */
            'phone' => $supplier->phone,
            /** @var string|null */
            'alt_phone' => $supplier->alt_phone,
            /** @var string|null */
            'email' => $supplier->email,
            /** @var string|null */
            'address' => $supplier->address,
            /** @var int|null */
            'district_id' => $supplier->district_id,
            /** @var string|null */
            'tin' => $supplier->tin,
            'payment_terms_days' => $supplier->payment_terms_days,
            /** @var 'active'|'archived' */
            'status' => $supplier->status->value,
            /** @var string|null */
            'note' => $supplier->note,
            /**
             * Payables, decimal strings: opening + received − paid = balance (negative = advance). finance.view only.
             *
             * @var array{opening: string, received: string, paid: string, balance: string}|null
             */
            'balance' => $balance === null ? null : [
                'opening' => $balance['opening']->toDecimal(),
                'received' => $balance['received']->toDecimal(),
                'paid' => $balance['paid']->toDecimal(),
                'balance' => $balance['balance']->toDecimal(),
            ],
            /**
             * ISO 8601, UTC.
             *
             * @var string
             *
             * @format date-time
             */
            'created_at' => ProductResource::utcIso($supplier->created_at),
        ];
    }
}
