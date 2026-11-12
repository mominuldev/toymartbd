<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Ordering\Models\OrderFinancial;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Invariant §7.2 — "order financials equal the ledger". For every order that has a
 * projection row or recognised ledger entries, the stored `order_financials` row must equal
 * what {@see OrderFinancialsProjector::compute()} derives from the ledger, column by column,
 * and `net_profit` must equal the signed sum of its recognised entries.
 *
 * Reports drift; never heals it. Drift means a code path bypassed the ledger, and silently
 * correcting it would hide the bug (§7).
 */
final readonly class FinanceInvariant
{
    public function __construct(private OrderFinancialsProjector $projector) {}

    /**
     * @return list<array{order_id: int, column: string, stored: string|null, ledger: string|null}>
     */
    public function drift(): array
    {
        $computed = $this->projector->computeMany(null);
        $stored = DB::table('order_financials')->get()->keyBy('order_id');

        $drift = [];
        $orderIds = array_values(array_unique([...array_keys($computed), ...$stored->keys()->map(static fn (mixed $id): int => (int) $id)->all()]));
        sort($orderIds);

        foreach ($orderIds as $orderId) {
            $expected = $computed[$orderId] ?? null;
            $row = $stored->get($orderId);

            if ($row === null) {
                $drift[] = ['order_id' => $orderId, 'column' => '*', 'stored' => null, 'ledger' => 'entries without a projection row'];

                continue;
            }

            foreach ([...OrderFinancial::MONEY_COLUMNS, 'margin_percent'] as $column) {
                $have = self::decimal($row->{$column} ?? null);
                $want = $expected === null ? ($column === 'margin_percent' ? null : '0.00') : $expected[$column];
                if ($have !== $want) {
                    $drift[] = ['order_id' => $orderId, 'column' => $column, 'stored' => $have, 'ledger' => $want];
                }
            }
        }

        return $drift;
    }

    private static function decimal(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_float($value) => Money::of(number_format($value, 2, '.', ''))->toDecimal(),
            is_int($value), is_string($value) => Money::of((string) $value)->toDecimal(),
            default => null,
        };
    }
}
