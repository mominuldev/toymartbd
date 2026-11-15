<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Pos;

use App\Domain\Pos\Models\PosSession;
use App\Http\Resources\ApiResource;
use App\Support\Money;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A drawer session (Figma B · 33 header: branch · register · cashier · opened at · float).
 * `totals` are DrawerTotals — Σ paid tenders by method and the cash the drawer should hold;
 * the close figures are null while open.
 *
 * `by_method` and `expected_cash` include due collected on this drawer; a sale's due part is
 * never cash (`due_sold`).
 *
 * @property array{session: PosSession, totals: array{sales_count: int, sales_total: Money, by_method: array<string, Money>, expected_cash: Money, due_sold: Money, due_collected: Money, due_collections_count: int}} $resource
 */
#[SchemaName('PosSession')]
final class PosSessionResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $session = $this->resource['session'];
        $totals = $this->resource['totals'];
        $location = $session->location;

        return [
            /** @var int */
            'id' => $session->id,
            /** @var 'open'|'closed' */
            'status' => $session->status->value,
            'location' => [
                /** @var int */
                'id' => $location->id,
                'code' => $location->code,
                'name' => $location->name,
                /** @var string|null */
                'name_bn' => $location->name_bn,
            ],
            'register' => $session->register,
            'cashier' => [
                /** @var int */
                'id' => $session->opener->id,
                'name' => $session->opener->name,
            ],
            /** ISO 8601 instant. */
            'opened_at' => $session->opened_at->toIso8601String(),
            'opening_float' => $session->opening_float->toDecimal(),
            /** @var string|null */
            'closed_at' => $session->closed_at?->toIso8601String(),
            /** @var string|null */
            'expected_cash' => $session->expected_cash?->toDecimal(),
            /** @var string|null */
            'counted_cash' => $session->counted_cash?->toDecimal(),
            /**
             * counted − expected; negative = short.
             *
             * @var string|null
             */
            'over_short' => $session->over_short?->toDecimal(),
            /** @var string|null */
            'close_note' => $session->close_note,
            'totals' => [
                /** @var int */
                'sales_count' => $totals['sales_count'],
                'sales_total' => $totals['sales_total']->toDecimal(),
                /** Cash the drawer should hold now: float + cash kept (sales and due collected). */
                'expected_cash' => $totals['expected_cash']->toDecimal(),
                /** Put on customers' due by this drawer's sales — no money, never in expected cash. */
                'due_sold' => $totals['due_sold']->toDecimal(),
                /** Due collected on this drawer (all tenders). */
                'due_collected' => $totals['due_collected']->toDecimal(),
                /** @var int */
                'due_collections_count' => $totals['due_collections_count'],
                /** @var list<array{method: string, amount: string}> */
                'by_method' => array_map(
                    static fn (string $method, Money $amount): array => ['method' => $method, 'amount' => $amount->toDecimal()],
                    array_keys($totals['by_method']),
                    $totals['by_method'],
                ),
            ],
        ];
    }
}
