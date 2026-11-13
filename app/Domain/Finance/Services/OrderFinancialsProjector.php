<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType as T;
use App\Support\DhakaDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * `order_financials` as a projection of the ledger (DATABASE-DESIGN §3.4, §5.4, §7.2).
 * Rebuildable at any time: {@see compute()} derives every column from the order's
 * recognised `financial_transactions`; nothing else writes the table.
 *
 *   revenue        = sale_revenue − discount_given          (= Σ line_total, excl. shipping)
 *   gross_profit   = revenue − cogs
 *   net_profit     = Σ recognised in − Σ recognised out     (invariant §7.2)
 *                  = gross_profit + shipping_revenue − courier_* − gateway_fee − packaging
 *                    − refund − return_loss − allocated_marketing + other income/expense
 *   margin_percent = net_profit / revenue × 100 (null while revenue is zero)
 *
 * Each per-type column is in the type's natural direction, so a reversing entry reduces it.
 * Cash-only entries (cod_received, supplier_payment) never touch an order's P&L.
 */
final readonly class OrderFinancialsProjector
{
    /** order_financials column ← ledger type. */
    public const array TYPE_COLUMNS = [
        'cogs' => T::Cogs,
        'discount_cost' => T::DiscountGiven,
        'courier_charge' => T::CourierCharge,
        'courier_cod_fee' => T::CourierCodFee,
        'courier_return_charge' => T::CourierReturnCharge,
        'gateway_fee' => T::GatewayFee,
        'packaging_cost' => T::Packaging,
        'allocated_marketing_cost' => T::Marketing,
        'refund_amount' => T::Refund,
        'return_loss' => T::InventoryWriteOff,
    ];

    public function __construct(private OrderLedgerSources $sources) {}

    /**
     * Recompute and store the rows for these orders.
     *
     * @param  list<int>  $orderIds
     */
    public function refresh(array $orderIds): void
    {
        if ($orderIds === []) {
            return;
        }
        $this->store($this->computeMany($orderIds), $orderIds);
    }

    /** Rebuild every order's row from the ledger. Returns how many rows were written. */
    public function rebuildAll(): int
    {
        $computed = $this->computeMany(null);
        $stale = DB::table('order_financials')->pluck('order_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $this->store($computed, array_values(array_unique([...array_keys($computed), ...$stale])));

        return count($computed) + count(array_diff($stale, array_keys($computed)));
    }

    /**
     * The projection row an order's ledger implies (Money columns as decimal strings).
     *
     * @return array<string, string|null>
     */
    public function compute(int $orderId): array
    {
        return $this->computeMany([$orderId])[$orderId] ?? self::row([]);
    }

    /**
     * Computed rows keyed by order id, for the given orders (null = every order with entries).
     *
     * @param  list<int>|null  $orderIds
     * @return array<int, array<string, string|null>>
     */
    public function computeMany(?array $orderIds): array
    {
        /** @var array<int, array<string, Money>> $nets order → type → signed (in − out) */
        $nets = [];

        foreach ($this->sources->mappings() as $sourceType => $target) {
            $query = DB::table('financial_transactions as ft')
                ->where('ft.source_type', $sourceType)
                ->where('ft.is_recognised_revenue', true);

            if ($target === null) {
                $query->selectRaw('ft.source_id as order_id');
                if ($orderIds !== null) {
                    $query->whereIn('ft.source_id', $orderIds);
                }
                $query->groupBy('ft.source_id', 'ft.type', 'ft.direction');
            } else {
                [$table, $column] = $target;
                $query->join("{$table} as src", 'src.id', '=', 'ft.source_id')
                    ->selectRaw("src.{$column} as order_id");
                if ($orderIds !== null) {
                    $query->whereIn("src.{$column}", $orderIds);
                }
                $query->whereNotNull("src.{$column}")->groupBy("src.{$column}", 'ft.type', 'ft.direction');
            }

            $rows = $query->addSelect('ft.type', 'ft.direction')->selectRaw('SUM(ft.amount) as total')->get();

            foreach ($rows as $row) {
                $orderId = (int) $row->order_id;
                $amount = self::money($row->total);
                $signed = $row->direction === Direction::In->value ? $amount : $amount->negate();
                $nets[$orderId][$row->type] = ($nets[$orderId][$row->type] ?? Money::zero())->plus($signed);
            }
        }

        $out = [];
        foreach ($nets as $orderId => $byType) {
            $out[$orderId] = self::row($byType);
        }

        return $out;
    }

    /**
     * @param  array<string, Money>  $byType  type → signed net (in − out)
     * @return array<string, string|null>
     */
    private static function row(array $byType): array
    {
        $natural = static function (T $type) use ($byType): Money {
            $net = $byType[$type->value] ?? Money::zero();

            return $type->naturalDirection() === Direction::In ? $net : $net->negate();
        };

        $revenue = $natural(T::SaleRevenue)->minus($natural(T::DiscountGiven));
        $gross = $revenue->minus($natural(T::Cogs));
        $net = array_reduce($byType, static fn (Money $c, Money $m): Money => $c->plus($m), Money::zero());

        $row = ['revenue' => $revenue->toDecimal(), 'gross_profit' => $gross->toDecimal(), 'net_profit' => $net->toDecimal()];
        foreach (self::TYPE_COLUMNS as $column => $type) {
            $row[$column] = $natural($type)->toDecimal();
        }

        $margin = null;
        if (! $revenue->isZero()) {
            $margin = bcdiv(bcmul($net->toDecimal(), '100', 6), $revenue->toDecimal(), 4);
            $margin = Money::of($margin)->toDecimal(); // half-away rounding to 2 places
            $margin = bccomp(ltrim($margin, '-'), '99999.99', 2) > 0 ? (str_starts_with($margin, '-') ? '-99999.99' : '99999.99') : $margin;
        }
        $row['margin_percent'] = $margin;

        return $row;
    }

    /**
     * @param  array<int, array<string, string|null>>  $computed
     * @param  list<int>  $orderIds  every order to write; one without entries is zeroed
     */
    private function store(array $computed, array $orderIds): void
    {
        $existingOrders = DB::table('orders')->whereIn('id', $orderIds)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if ($existingOrders === []) {
            return;
        }

        $now = DhakaDate::instant();
        $rows = array_map(static fn (int $id): array => ['order_id' => $id, ...($computed[$id] ?? self::row([])), 'computed_at' => $now], $existingOrders);

        $columns = array_keys($rows[0]);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('order_financials')->upsert($chunk, ['order_id'], array_values(array_diff($columns, ['order_id'])));
        }
    }

    private static function money(mixed $value): Money
    {
        return match (true) {
            is_float($value) => Money::of(number_format($value, 2, '.', '')),
            is_int($value), is_string($value) => Money::of($value),
            default => Money::zero(),
        };
    }
}
