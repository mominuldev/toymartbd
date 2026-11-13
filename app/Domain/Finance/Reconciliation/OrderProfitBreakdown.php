<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reconciliation;

use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType as T;
use App\Domain\Finance\Reports\ProfitAndLoss;
use App\Domain\Finance\Reports\ReportPeriod;
use App\Domain\Finance\Services\ExpenseEntries;
use App\Domain\Finance\Services\OrderLedgerSources;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The Phase 5 gate's hand check — "net profit correct to the taka, verified by hand against
 * a spreadsheet" (DEVELOPMENT-ROADMAP §Phase 5, docs/ops/PROFIT-RECONCILIATION.md).
 *
 * Each order's net profit is computed TWICE, by two code paths that share nothing but the
 * database:
 *
 *   order side   straight from the source tables — orders, order_items (cost_price_snapshot,
 *                rule 7), order_returns + order_return_items, refunds, payments, expenses.
 *                It deliberately does NOT call the Finance listeners, the projector or the
 *                P&L report: it is an independent recomputation, so a listener that books
 *                the wrong amount (or nothing) shows up as a difference here.
 *   ledger side  from `financial_transactions` alone: every recognised entry
 *                (`is_recognised_revenue`, rule 10) whose source belongs to the order
 *                ({@see OrderLedgerSources}, the one shared definition of "order-scoped").
 *
 * plus the stored `order_financials.net_profit` projection as a third opinion.
 *
 * Read-only. Lifetime figures per order: a refund approved a month after delivery is in the
 * order's row, whatever the period. For the period view (what the P&L says), see
 * {@see period()}.
 */
final readonly class OrderProfitBreakdown
{
    /** Components, in their natural direction (a cost is positive). Order matters: it is the export's. */
    public const array COMPONENTS = [
        'item_revenue', 'discount', 'delivery_charge', 'cogs', 'cogs_reversed', 'write_off',
        'refunds', 'courier_charges', 'gateway_fees', 'order_expenses', 'other',
    ];

    /** +1 adds to net profit, −1 subtracts. */
    private const array SIGN = [
        'item_revenue' => 1, 'discount' => -1, 'delivery_charge' => 1, 'cogs' => -1, 'cogs_reversed' => 1,
        'write_off' => -1, 'refunds' => -1, 'courier_charges' => -1, 'gateway_fees' => -1, 'order_expenses' => -1,
        'other' => 1,
    ];

    /** Statuses at which an order's sale is recognised (revenue on delivery, rule 10). */
    private const array RECOGNISED_STATUSES = ['delivered', 'completed', 'returned', 'partially_returned'];

    /** Return statuses past inspection — goods are back and counted. */
    private const array INSPECTED_RETURNS = ['inspected', 'closed'];

    /** Expense sources whose entries belong to an order through `expenses.order_id`. */
    private const array EXPENSE_SOURCES = [ExpenseEntries::SOURCE, ExpenseEntries::SOURCE_VOID, ExpenseEntries::SOURCE_PAYMENT];

    private const int CHUNK = 500;

    public function __construct(private OrderLedgerSources $sources, private ProfitAndLoss $pnl) {}

    /**
     * Which orders to break down: explicit references (order numbers or ids), else the
     * orders PLACED on the Dhaka dates [from, to].
     *
     * @param  list<string>  $references
     * @return list<int>
     */
    public function selectOrders(?string $from, ?string $to, array $references = []): array
    {
        if ($references !== []) {
            $ids = [];
            $numbers = [];
            foreach ($references as $ref) {
                $ref = trim($ref);
                if ($ref === '') {
                    continue;
                }
                ctype_digit($ref) ? $ids[] = (int) $ref : $numbers[] = $ref;
            }

            $found = DB::table('orders')
                ->where(fn ($q) => $q->whereIn('id', $ids === [] ? [0] : $ids)->orWhereIn('number', $numbers === [] ? [''] : $numbers))
                ->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

            return array_values($found);
        }

        if ($from === null) {
            throw new InvalidArgumentException('Give --orders, or --from (and optionally --to).');
        }
        $period = ReportPeriod::of($from, $to ?? DhakaDate::today());
        [$start, $end] = $period->utcBounds();

        return DB::table('orders')
            ->where('placed_at', '>=', $start)->where('placed_at', '<', $end)
            ->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }

    /**
     * @param  list<int>  $orderIds
     * @return list<OrderBreakdownRow>
     */
    public function rows(array $orderIds): array
    {
        $rows = [];
        foreach (array_chunk($orderIds, self::CHUNK) as $chunk) {
            $orderSide = $this->orderSide($chunk);
            $ledgerSide = $this->ledgerSide($chunk);
            $projection = DB::table('order_financials')->whereIn('order_id', $chunk)->pluck('net_profit', 'order_id');

            $orders = DB::table('orders')->whereIn('id', $chunk)->orderBy('id')
                ->get(['id', 'number', 'status', 'payment_method', 'placed_at', 'delivered_at']);

            foreach ($orders as $o) {
                $id = (int) $o->id;
                $order = $orderSide[$id]['components'];
                $ledger = $ledgerSide[$id]['components'] ?? self::zeros();
                $mismatched = array_values(array_filter(
                    self::COMPONENTS,
                    static fn (string $c): bool => ! $order[$c]->equals($ledger[$c]),
                ));
                $stored = $projection->get($id);

                $rows[] = new OrderBreakdownRow(
                    orderId: $id,
                    number: (string) $o->number,
                    status: (string) $o->status,
                    paymentMethod: $o->payment_method === null ? null : (string) $o->payment_method,
                    placedOn: self::dhakaDay($o->placed_at),
                    deliveredOn: self::dhakaDay($o->delivered_at),
                    recognised: $orderSide[$id]['recognised'],
                    order: $order,
                    ledger: $ledger,
                    orderNet: self::net($order),
                    ledgerNet: $ledgerSide[$id]['net'] ?? Money::zero(),
                    projectionNet: $stored === null ? null : self::money($stored),
                    mismatched: $mismatched,
                );
            }
        }

        return $rows;
    }

    /**
     * The period view, ledger only, for entries DATED in [from, to] (Dhaka days, rule 11):
     * the P&L report's net profit, the same total summed straight from the ledger, and its
     * split into order-scoped entries and everything else (expenses not tied to an order,
     * stock write-offs…), by type.
     *
     * @return array{from: string, to: string, pnl_net_profit: Money, ledger_net_profit: Money, order_scoped: Money, other: Money, other_by_type: array<string, Money>, agrees: bool}
     */
    public function period(ReportPeriod $period): array
    {
        $statement = $this->pnl->statement($period, 'none');
        /** @var Money $pnlNet */
        $pnlNet = $statement['totals']['net_profit'];

        $orderScoped = Money::zero();
        $other = Money::zero();
        $otherByType = [];
        $scopedIds = $this->orderScopedEntryIds($period);

        DB::table('financial_transactions')
            ->where('is_recognised_revenue', true)
            ->where('occurred_on', '>=', $period->fromDate())
            ->where('occurred_on', '<=', $period->toDate())
            ->orderBy('id')
            ->select(['id', 'type', 'direction', 'amount'])
            ->chunk(1000, function ($entries) use (&$orderScoped, &$other, &$otherByType, $scopedIds): void {
                foreach ($entries as $e) {
                    $signed = self::signed($e->direction, $e->amount);
                    if (isset($scopedIds[(int) $e->id])) {
                        $orderScoped = $orderScoped->plus($signed);

                        continue;
                    }
                    $other = $other->plus($signed);
                    $otherByType[(string) $e->type] = ($otherByType[(string) $e->type] ?? Money::zero())->plus($signed);
                }
            });

        ksort($otherByType);
        $ledgerNet = $orderScoped->plus($other);

        return [
            'from' => $period->fromDate(),
            'to' => $period->toDate(),
            'pnl_net_profit' => $pnlNet,
            'ledger_net_profit' => $ledgerNet,
            'order_scoped' => $orderScoped,
            'other' => $other,
            'other_by_type' => $otherByType,
            'agrees' => $pnlNet->equals($ledgerNet),
        ];
    }

    /**
     * Order side: straight from the source tables.
     *
     * @param  list<int>  $orderIds
     * @return array<int, array{recognised: bool, components: array<string, Money>}>
     */
    private function orderSide(array $orderIds): array
    {
        $out = [];
        $orders = DB::table('orders')->whereIn('id', $orderIds)->get(['id', 'status', 'delivered_at', 'shipping_charge']);
        $recognised = [];
        foreach ($orders as $o) {
            $id = (int) $o->id;
            $isRecognised = $o->delivered_at !== null && in_array((string) $o->status, self::RECOGNISED_STATUSES, true);
            $recognised[$id] = $isRecognised;
            $c = self::zeros();
            if ($isRecognised) {
                $c['delivery_charge'] = self::money($o->shipping_charge);
            }
            $out[$id] = ['recognised' => $isRecognised, 'components' => $c];
        }

        // Goods sold: Σ unit_price × quantity (+ tax), Σ line discounts, Σ snapshot cost.
        $items = DB::table('order_items')->whereIn('order_id', $orderIds)->orderBy('id')
            ->get(['id', 'order_id', 'unit_price', 'quantity', 'discount', 'tax', 'cost_price_snapshot']);
        $snapshots = [];
        foreach ($items as $item) {
            $id = (int) $item->order_id;
            $snapshots[(int) $item->id] = self::decimal($item->cost_price_snapshot, 4);
            if (! ($recognised[$id] ?? false)) {
                continue;
            }
            $c = &$out[$id]['components'];
            $c['item_revenue'] = $c['item_revenue']->plus(self::money($item->unit_price)->times((int) $item->quantity))->plus(self::money($item->tax));
            $c['discount'] = $c['discount']->plus(self::money($item->discount));
            $c['cogs'] = $c['cogs']->plus(self::cost($snapshots[(int) $item->id], (int) $item->quantity));
            unset($c);
        }

        // Returns, once inspected: every unit back reverses its snapshot COGS; damaged units
        // are written off at the same snapshot cost.
        $returnLines = DB::table('order_return_items as ri')
            ->join('order_returns as r', 'r.id', '=', 'ri.order_return_id')
            ->whereIn('r.order_id', $orderIds)
            ->whereIn('r.status', self::INSPECTED_RETURNS)
            ->whereNotNull('r.inspected_at')
            ->orderBy('ri.id')
            ->get(['r.order_id', 'ri.order_item_id', 'ri.quantity', 'ri.quantity_damaged']);
        foreach ($returnLines as $line) {
            $id = (int) $line->order_id;
            if (! ($recognised[$id] ?? false)) {
                continue;
            }
            $snapshot = $snapshots[(int) $line->order_item_id] ?? '0.0000';
            $c = &$out[$id]['components'];
            $c['cogs_reversed'] = $c['cogs_reversed']->plus(self::cost($snapshot, (int) $line->quantity));
            $c['write_off'] = $c['write_off']->plus(self::cost($snapshot, (int) ($line->quantity_damaged ?? 0)));
            unset($c);
        }

        // Refunds: recognised when approved (an approved refund is never rejected later).
        $refunds = DB::table('refunds')->whereIn('order_id', $orderIds)
            ->whereNotNull('approved_at')->where('status', '!=', 'rejected')
            ->orderBy('id')->get(['order_id', 'amount']);
        foreach ($refunds as $refund) {
            $id = (int) $refund->order_id;
            if ($recognised[$id] ?? false) {
                $out[$id]['components']['refunds'] = $out[$id]['components']['refunds']->plus(self::money($refund->amount));
            }
        }

        // Gateway fees: what the gateway kept on a captured payment (payments.fee_amount).
        $payments = DB::table('payments')->whereIn('order_id', $orderIds)->whereNotNull('paid_at')
            ->orderBy('id')->get(['order_id', 'fee_amount']);
        foreach ($payments as $payment) {
            $id = (int) $payment->order_id;
            $out[$id]['components']['gateway_fees'] = $out[$id]['components']['gateway_fees']->plus(self::money($payment->fee_amount));
        }

        // Courier charges: no source exists until Phase 6 courier booking (manual fulfilment
        // records no charge, DATABASE-DESIGN §4.3 "as built") — 0 on this side by definition.

        // Expenses tied to the order (packaging…): approved and still standing, in a P&L category.
        $expenses = DB::table('expenses as e')
            ->join('expense_categories as c', 'c.id', '=', 'e.category_id')
            ->whereIn('e.order_id', $orderIds)
            ->where('e.status', 'approved')
            ->where('c.capitalised', false)
            ->orderBy('e.id')->get(['e.order_id', 'e.amount']);
        foreach ($expenses as $expense) {
            $id = (int) $expense->order_id;
            $out[$id]['components']['order_expenses'] = $out[$id]['components']['order_expenses']->plus(self::money($expense->amount));
        }

        return $out;
    }

    /**
     * Ledger side: every recognised entry belonging to these orders, per component.
     *
     * @param  list<int>  $orderIds
     * @return array<int, array{net: Money, components: array<string, Money>}>
     */
    private function ledgerSide(array $orderIds): array
    {
        $out = [];
        foreach ($this->sources->mappings() as $sourceType => $target) {
            $query = DB::table('financial_transactions as ft')
                ->where('ft.source_type', $sourceType)
                ->where('ft.is_recognised_revenue', true)
                ->orderBy('ft.id');
            if ($target === null) {
                $query->whereIn('ft.source_id', $orderIds)->select(['ft.source_id as order_id']);
            } else {
                [$table, $column] = $target;
                $query->join("{$table} as src", 'src.id', '=', 'ft.source_id')
                    ->whereIn("src.{$column}", $orderIds)
                    ->select(["src.{$column} as order_id"]);
            }

            foreach ($query->addSelect(['ft.type', 'ft.direction', 'ft.amount'])->get() as $e) {
                $id = (int) $e->order_id;
                $out[$id] ??= ['net' => Money::zero(), 'components' => self::zeros()];
                $signed = self::signed($e->direction, $e->amount);
                $out[$id]['net'] = $out[$id]['net']->plus($signed);

                $component = self::componentOf($sourceType, (string) $e->type, $signed);
                // In the component's natural direction: a cost component holds out − in.
                $natural = self::SIGN[$component] === 1 ? $signed : $signed->negate();
                $out[$id]['components'][$component] = $out[$id]['components'][$component]->plus($natural);
            }
        }

        return $out;
    }

    /** Which breakdown component a ledger entry belongs to. */
    private static function componentOf(string $sourceType, string $type, Money $signed): string
    {
        if (in_array($sourceType, self::EXPENSE_SOURCES, true)) {
            return 'order_expenses';
        }

        return match ($type) {
            T::SaleRevenue->value => 'item_revenue',
            T::DiscountGiven->value => 'discount',
            T::ShippingRevenue->value => 'delivery_charge',
            // A COGS entry out is the sale; in is goods coming back (return, courier return).
            T::Cogs->value => $signed->isNegative() ? 'cogs' : 'cogs_reversed',
            T::InventoryWriteOff->value => 'write_off',
            T::Refund->value => 'refunds',
            T::CourierCharge->value, T::CourierCodFee->value, T::CourierReturnCharge->value => 'courier_charges',
            T::GatewayFee->value => 'gateway_fees',
            default => 'other',
        };
    }

    /**
     * Ids of the in-period recognised entries that belong to some order.
     *
     * @return array<int, true>
     */
    private function orderScopedEntryIds(ReportPeriod $period): array
    {
        $ids = [];
        foreach ($this->sources->mappings() as $sourceType => $target) {
            $query = DB::table('financial_transactions as ft')
                ->where('ft.source_type', $sourceType)
                ->where('ft.is_recognised_revenue', true)
                ->where('ft.occurred_on', '>=', $period->fromDate())
                ->where('ft.occurred_on', '<=', $period->toDate());
            if ($target !== null) {
                [$table, $column] = $target;
                $query->join("{$table} as src", 'src.id', '=', 'ft.source_id')->whereNotNull("src.{$column}");
            }
            foreach ($query->pluck('ft.id') as $id) {
                $ids[(int) $id] = true;
            }
        }

        return $ids;
    }

    /** @param  array<string, Money>  $c */
    private static function net(array $c): Money
    {
        $net = Money::zero();
        foreach (self::SIGN as $component => $sign) {
            $net = $sign === 1 ? $net->plus($c[$component]) : $net->minus($c[$component]);
        }

        return $net;
    }

    /** @return array<string, Money> */
    private static function zeros(): array
    {
        return array_fill_keys(self::COMPONENTS, Money::zero());
    }

    /** Snapshot cost × quantity, rounded to the poisha once per line. */
    private static function cost(string $snapshot, int $quantity): Money
    {
        return Money::of(bcmul($snapshot, (string) $quantity, 4));
    }

    private static function signed(mixed $direction, mixed $amount): Money
    {
        $money = self::money($amount);

        return $direction === Direction::In->value ? $money : $money->negate();
    }

    private static function dhakaDay(mixed $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        return DhakaDate::dayOf(CarbonImmutable::parse((string) $timestamp, 'UTC'));
    }

    private static function money(mixed $value): Money
    {
        return Money::of(self::decimal($value, 2));
    }

    /** A DB decimal (string, int, or a float from SQLite) as a decimal string. */
    private static function decimal(mixed $value, int $scale): string
    {
        return match (true) {
            is_float($value) => number_format($value, $scale, '.', ''),
            is_int($value), is_string($value) => (string) $value,
            default => '0',
        };
    }
}
