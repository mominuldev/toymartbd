<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Finance\Models\FinancialTransaction;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One ledger entry (read-only, rule 8). `amount` is a positive decimal string; `direction`
 * gives the sign. `occurred_on` is a Dhaka date. `is_recognised_revenue` = in the P&L;
 * `is_cash_movement` = real money moved (rule 10).
 *
 * @property FinancialTransaction $resource
 */
#[SchemaName('FinanceLedgerEntry')]
final class FinanceLedgerEntryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $e = $this->resource;

        return [
            'id' => $e->id,
            /** @var 'sale_revenue'|'cogs'|'discount_given'|'shipping_revenue'|'courier_charge'|'courier_cod_fee'|'courier_return_charge'|'gateway_fee'|'packaging'|'marketing'|'salary'|'rent'|'utility'|'refund'|'inventory_write_off'|'supplier_payment'|'cod_received'|'other_income'|'other_expense' */
            'type' => $e->type->value,
            /** @var 'in'|'out' */
            'direction' => $e->direction->value,
            /** @var string */
            'amount' => $e->amount->toDecimal(),
            /** @var string */
            'occurred_on' => $e->occurred_on->toDateString(),
            'source_type' => $e->source_type,
            'source_id' => $e->source_id,
            'category_id' => $e->category_id,
            /** @var string|null */
            'category_name' => $e->category?->name,
            /** @var string|null */
            'category_name_bn' => $e->category?->name_bn,
            'description' => $e->description,
            'is_cash_movement' => $e->is_cash_movement,
            'is_recognised_revenue' => $e->is_recognised_revenue,
            /** @var string|null */
            'created_at' => $e->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
