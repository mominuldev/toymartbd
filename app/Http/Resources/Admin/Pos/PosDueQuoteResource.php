<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Pos;

use App\Domain\Pos\DTOs\DueCollectionPlan;
use App\Http\Resources\ApiResource;
use App\Support\PhoneNumber;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A due collection before it is taken (rule 1): the customer's open due, their open POS orders
 * oldest first with what this money pays on each (FIFO), and how the tenders settle —
 * `remaining` is the due left after it (a partial collection is allowed), `change` comes from
 * cash only. `chargeable` = no errors and something is applied.
 *
 * @property DueCollectionPlan $resource
 */
#[SchemaName('PosDueQuote')]
final class PosDueQuoteResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $plan = $this->resource;
        $customer = $plan->customer;

        return [
            /** @var array{name: string, phone: string}|null */
            'customer' => $customer === null ? null : [
                'name' => $customer->name,
                'phone' => PhoneNumber::tryParse($customer->phone)?->national() ?? '',
            ],
            'open_due' => $plan->openDue->toDecimal(),
            /** @var list<array{order_id: int, order_number: string, due: string, applied: string}> */
            'orders' => array_map(static fn (array $row): array => [
                'order_id' => $row['order']->id,
                'order_number' => $row['order']->number,
                'due' => $row['due']->toDecimal(),
                'applied' => $row['applied']->toDecimal(),
            ], $plan->orders),
            /** @var array{tendered: string, cash_tendered: string, remaining: string, change: string, errors: list<array{field: string, message: string}>} */
            'tenders' => [
                'tendered' => $plan->tendered->toDecimal(),
                'cash_tendered' => $plan->cashTendered->toDecimal(),
                'remaining' => $plan->remaining->toDecimal(),
                'change' => $plan->change->toDecimal(),
                'errors' => $plan->errors,
            ],
            'chargeable' => $plan->chargeable(),
        ];
    }
}
