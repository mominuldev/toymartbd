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
 * A customer search hit at the counter (POS F9): who, their number in the national form the
 * cashier types, how many orders, and their due (open due, the store limit, what is left).
 *
 * @property array{customer: Customer, open_due: Money, due_limit: Money, due_available: Money} $resource
 */
#[SchemaName('PosCustomerMatch')]
final class PosCustomerMatchResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $customer = $this->resource['customer'];

        return [
            /** @var int */
            'id' => $customer->id,
            'name' => $customer->name,
            /** 01XXXXXXXXX */
            'phone' => PhoneNumber::tryParse($customer->phone)?->national() ?? '',
            /** @var int */
            'orders_count' => $customer->orders_count,
            'open_due' => $this->resource['open_due']->toDecimal(),
            'due_limit' => $this->resource['due_limit']->toDecimal(),
            'due_available' => $this->resource['due_available']->toDecimal(),
        ];
    }
}
