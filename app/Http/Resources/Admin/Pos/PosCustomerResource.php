<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Pos;

use App\Domain\Customer\Models\Customer;
use App\Http\Resources\ApiResource;
use App\Support\Money;
use App\Support\PhoneNumber;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The phone the cashier typed and whose it is. `customer` is null for a new number (the sale
 * creates them as a walk-in customer). No loyalty tier or points: no loyalty system exists.
 *
 * `customer` (when known) carries their open due — Σ due_total of their delivered POS orders
 * still owing (PosDues) — the store's due limit and what is still available under it.
 * The anonymous walk-in row is never returned as a customer.
 *
 * @property array{phone: PhoneNumber, customer: Customer|null, open_due: Money, due_limit: Money, due_available: Money} $resource
 */
#[SchemaName('PosCustomer')]
final class PosCustomerResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $customer = $this->resource['customer'];

        return [
            /** 01XXXXXXXXX */
            'phone' => $this->resource['phone']->national(),
            /** @var array{id: int, name: string, orders_count: int, open_due: string, due_limit: string, due_available: string}|null */
            'customer' => $customer === null ? null : [
                'id' => $customer->id,
                'name' => $customer->name,
                'orders_count' => $customer->orders_count,
                'open_due' => $this->resource['open_due']->toDecimal(),
                'due_limit' => $this->resource['due_limit']->toDecimal(),
                'due_available' => $this->resource['due_available']->toDecimal(),
            ],
        ];
    }
}
