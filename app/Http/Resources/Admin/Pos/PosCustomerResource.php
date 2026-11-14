<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Pos;

use App\Domain\Customer\Models\Customer;
use App\Http\Resources\ApiResource;
use App\Support\PhoneNumber;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The phone the cashier typed and whose it is. `customer` is null for a new number (the sale
 * creates them as a walk-in customer). No loyalty tier or points: no loyalty system exists.
 *
 * @property array{phone: PhoneNumber, customer: Customer|null} $resource
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
            /** @var array{id: int, name: string, orders_count: int}|null */
            'customer' => $customer === null ? null : [
                'id' => $customer->id,
                'name' => $customer->name,
                'orders_count' => $customer->orders_count,
            ],
        ];
    }
}
