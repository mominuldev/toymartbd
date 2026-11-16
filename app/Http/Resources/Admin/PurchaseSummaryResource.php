<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Purchasing\Models\Supplier;
use App\Http\Resources\ApiResource;
use App\Support\Money;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The Purchase orders KPI row (PurchasingSummary). Dates are Dhaka calendar dates (rule 11).
 * `open_value` needs catalog.view_cost (PO money is cost); `owed` needs finance.view (the
 * supplier payables, like the supplier balance).
 *
 * @property array{today: string, week_ends: string, open_count: int, open_value: Money, arriving_count: int, late_count: int, owed: Money, owed_suppliers: int} $resource
 */
#[SchemaName('PurchaseSummary')]
final class PurchaseSummaryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $summary = $this->resource;
        $viewCost = InventoryVariantPart::canViewCost($request);
        $finance = $request->user()?->can('viewBalance', Supplier::class) ?? false;

        return [
            /**
             * Today in Dhaka, Y-m-d.
             *
             * @var string
             */
            'today' => $summary['today'],
            /**
             * The Friday ending this Saturday–Friday week, Y-m-d.
             *
             * @var string
             */
            'week_ends' => $summary['week_ends'],
            /**
             * POs waiting for stock (ordered or partly received).
             *
             * @var int
             */
            'open_count' => $summary['open_count'],
            /**
             * Their order value. catalog.view_cost only.
             *
             * @var string|null
             */
            'open_value' => $viewCost ? $summary['open_value']->toDecimal() : null,
            /**
             * Open POs expected from today to `week_ends`.
             *
             * @var int
             */
            'arriving_count' => $summary['arriving_count'],
            /**
             * Open POs past their expected date.
             *
             * @var int
             */
            'late_count' => $summary['late_count'],
            /**
             * What we owe suppliers for stock received: the sum of positive supplier balances. finance.view only.
             *
             * @var string|null
             */
            'owed' => $finance ? $summary['owed']->toDecimal() : null,
            /**
             * How many suppliers we owe. finance.view only.
             *
             * @var int|null
             */
            'owed_suppliers' => $finance ? $summary['owed_suppliers'] : null,
        ];
    }
}
