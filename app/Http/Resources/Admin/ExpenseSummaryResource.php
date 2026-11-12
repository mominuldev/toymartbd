<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use App\Support\Money;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The Expenses screen's stat cards, computed by the server. Money is a decimal string;
 * `ad_spend_ratio` is a percentage string (2 dp) or null when nothing was delivered.
 *
 * @property array{from: string, to: string, period_total: Money, period_top: list<array{category_id: int, name: string, name_bn: string|null, amount: Money}>, month_to_date: Money, recurring_monthly: Money, ad_spend_ratio: string|null, pending_count: int} $resource
 */
#[SchemaName('ExpenseSummary')]
final class ExpenseSummaryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            /** @var string */
            'from' => $s['from'],
            /** @var string */
            'to' => $s['to'],
            /** @var string */
            'period_total' => $s['period_total']->toDecimal(),
            /** @var list<array{category_id: int, name: string, name_bn: string|null, amount: string}> */
            'period_top' => array_map(static fn (array $c): array => [...$c, 'amount' => $c['amount']->toDecimal()], $s['period_top']),
            /** @var string */
            'month_to_date' => $s['month_to_date']->toDecimal(),
            /** @var string */
            'recurring_monthly' => $s['recurring_monthly']->toDecimal(),
            /** @var string|null */
            'ad_spend_ratio' => $s['ad_spend_ratio'],
            /** @var int */
            'pending_count' => $s['pending_count'],
        ];
    }
}
