<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\LedgerEntryType as T;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * COD receivables = recognised minus received (DATABASE-DESIGN §3.7, PRD N-03/N-09).
 *
 * "Recognised" is what a delivered COD order's own ledger entries say the customer owes:
 * sale_revenue + shipping_revenue − discount_given (net of any reversal). "Received" is
 * every `cod_received` entry. Both read only the ledger, so the figure is as correct as
 * the ledger and is never stored anywhere it could drift (the nightly summary snapshots
 * {@see total()} as `cod_receivable_closing`).
 */
final readonly class Receivables
{
    private const array COLLECTABLE = [T::SaleRevenue, T::ShippingRevenue, T::DiscountGiven];

    public function __construct(private OrderLedgerSources $sources) {}

    /** Outstanding COD receivable for one order (never below zero is NOT enforced: an overpayment shows negative). */
    public function forOrder(int $orderId): Money
    {
        $collectable = $this->collectableByOrder(null, [$orderId])[$orderId] ?? Money::zero();

        return $collectable->minus($this->receivedByOrder(null, [$orderId])[$orderId] ?? Money::zero());
    }

    /** Total recognised − total received, as at the end of a Dhaka date (default today). */
    public function total(?string $asOf = null): Money
    {
        $asOf ??= DhakaDate::today();
        $collectable = array_reduce($this->collectableByOrder($asOf, null), static fn (Money $c, Money $m): Money => $c->plus($m), Money::zero());

        return $collectable->minus($this->receivedTotal($asOf));
    }

    /** Σ cod_received (every source) as at the end of a Dhaka date. */
    private function receivedTotal(string $asOf): Money
    {
        return self::money(DB::table('financial_transactions')
            ->where('type', T::CodReceived->value)
            ->where('occurred_on', '<=', $asOf)
            ->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as total")
            ->value('total'));
    }

    /**
     * The summary card: total recognised, received, outstanding, open orders, and the
     * outstanding amount aged by the Dhaka delivery date (0–7, 8–15, 16–30, 31+ days).
     *
     * @return array{recognised: Money, received: Money, outstanding: Money, open_orders: int, aging: array<string, Money>}
     */
    public function summary(?string $asOf = null): array
    {
        $asOf ??= DhakaDate::today();
        // One pass over the ledger for the collectable amounts and the delivery dates (Phase 9,
        // docs/audits/PERFORMANCE.md P-06): the delivery date used to be a second query with every
        // open order id as a bound parameter — past 65,535 delivered COD orders MySQL refuses the
        // statement ("too many placeholders") — and total() re-ran the collectable aggregation.
        [$collectable, $deliveredOn] = $this->collectable($asOf, null);
        $received = $this->receivedByOrder($asOf, null);

        $aging = ['0_7' => Money::zero(), '8_15' => Money::zero(), '16_30' => Money::zero(), '31_plus' => Money::zero()];
        $open = 0;
        $today = CarbonImmutable::createFromFormat('!Y-m-d', $asOf) ?? DhakaDate::todayDate();
        foreach ($collectable as $orderId => $amount) {
            $outstanding = $amount->minus($received[$orderId] ?? Money::zero());
            if (! $outstanding->greaterThan(Money::zero())) {
                continue;
            }
            $open++;
            $on = $deliveredOn[$orderId] ?? $asOf;
            $days = (int) (CarbonImmutable::createFromFormat('!Y-m-d', substr((string) $on, 0, 10)) ?? $today)->diffInDays($today);
            $bucket = match (true) {
                $days <= 7 => '0_7',
                $days <= 15 => '8_15',
                $days <= 30 => '16_30',
                default => '31_plus',
            };
            $aging[$bucket] = $aging[$bucket]->plus($outstanding);
        }

        $recognised = array_reduce($collectable, static fn (Money $c, Money $m): Money => $c->plus($m), Money::zero());
        $total = $recognised->minus($this->receivedTotal($asOf));

        return [
            'recognised' => $recognised,
            'received' => $recognised->minus($total),
            'outstanding' => $total,
            'open_orders' => $open,
            'aging' => $aging,
        ];
    }

    /**
     * Σ collectable per delivered COD order.
     *
     * @param  list<int>|null  $orderIds
     * @return array<int, Money>
     */
    private function collectableByOrder(?string $asOf, ?array $orderIds): array
    {
        return $this->collectable($asOf, $orderIds)[0];
    }

    /**
     * Σ collectable per delivered COD order, and the Dhaka date its sale revenue was recognised
     * (the delivery date the aging buckets count from; null when only a reversal is in range).
     *
     * @param  list<int>|null  $orderIds
     * @return array{0: array<int, Money>, 1: array<int, string|null>}
     */
    private function collectable(?string $asOf, ?array $orderIds): array
    {
        $query = DB::table('financial_transactions as ft')
            ->join('orders as o', 'o.id', '=', 'ft.source_id')
            ->whereIn('ft.source_type', [OrderLedgerSources::ORDER, OrderLedgerSources::ORDER_REVERSAL])
            ->whereIn('ft.type', array_map(static fn (T $t): string => $t->value, self::COLLECTABLE))
            ->where('o.payment_method', 'cod')
            ->groupBy('ft.source_id')
            ->selectRaw('ft.source_id as order_id')
            ->selectRaw("SUM(CASE WHEN ft.direction = 'in' THEN ft.amount ELSE -ft.amount END) as total")
            ->selectRaw('MIN(CASE WHEN ft.source_type = ? AND ft.type = ? THEN ft.occurred_on END) as delivered_on', [OrderLedgerSources::ORDER, T::SaleRevenue->value]);
        if ($asOf !== null) {
            $query->where('ft.occurred_on', '<=', $asOf);
        }
        if ($orderIds !== null) {
            $query->whereIn('ft.source_id', $orderIds);
        }

        $out = [];
        $deliveredOn = [];
        foreach ($query->get() as $row) {
            $out[(int) $row->order_id] = self::money($row->total);
            $deliveredOn[(int) $row->order_id] = $row->delivered_on === null ? null : (string) $row->delivered_on;
        }

        return [$out, $deliveredOn];
    }

    /**
     * Σ cod_received per order, over every order-scoped source.
     *
     * @param  list<int>|null  $orderIds
     * @return array<int, Money>
     */
    private function receivedByOrder(?string $asOf, ?array $orderIds): array
    {
        $out = [];
        foreach ($this->sources->mappings() as $sourceType => $target) {
            $query = DB::table('financial_transactions as ft')
                ->where('ft.source_type', $sourceType)
                ->where('ft.type', T::CodReceived->value)
                ->selectRaw("SUM(CASE WHEN ft.direction = 'in' THEN ft.amount ELSE -ft.amount END) as total");
            if ($asOf !== null) {
                $query->where('ft.occurred_on', '<=', $asOf);
            }
            if ($target === null) {
                $query->selectRaw('ft.source_id as order_id')->groupBy('ft.source_id');
                if ($orderIds !== null) {
                    $query->whereIn('ft.source_id', $orderIds);
                }
            } else {
                [$table, $column] = $target;
                $query->join("{$table} as src", 'src.id', '=', 'ft.source_id')
                    ->selectRaw("src.{$column} as order_id")->groupBy("src.{$column}");
                if ($orderIds !== null) {
                    $query->whereIn("src.{$column}", $orderIds);
                }
            }
            foreach ($query->get() as $row) {
                if ($row->order_id === null) {
                    continue;
                }
                $id = (int) $row->order_id;
                $out[$id] = ($out[$id] ?? Money::zero())->plus(self::money($row->total));
            }
        }

        return $out;
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
