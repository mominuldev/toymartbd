<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which ledger sources belong to an order, for the `order_financials` projection (§3.4,
 * §7.2). The ledger's source is polymorphic and the Ledger contract carries no order id,
 * so every order-scoped source type is listed here with how to reach its order:
 *
 *   - `null`          the source id IS the order id (`order`, `order_reversal`);
 *   - [table, column] the source row carries the order id (a shipment, a refund…).
 *
 * A domain that writes order-scoped entries under a new source type adds one line here;
 * an unlisted source type is not part of any order's financials. A listed table that does
 * not exist yet (a later phase) is skipped.
 */
final class OrderLedgerSources
{
    /** The order itself — delivery revenue, COGS, discount, shipping revenue. */
    public const string ORDER = 'order';

    /** Reversals of the order's own entries (courier return, §4.4) — a distinct key from `order`. */
    public const string ORDER_REVERSAL = 'order_reversal';

    /** @var array<string, array{0: string, 1: string}|null> */
    public const array MAP = [
        self::ORDER => null,
        self::ORDER_REVERSAL => null,
        'shipment' => ['shipments', 'order_id'],
        'refund' => ['refunds', 'order_id'],
        // Returns & refunds (§4.7): COGS reversal / write-off per return, and the cash payout
        // of a refund (a separate source from `refund`, which carries the recognised refund).
        'order_return' => ['order_returns', 'order_id'],
        'refund_payout' => ['refunds', 'order_id'],
        'payment' => ['payments', 'order_id'],
        'manual_cod_receipt' => ['manual_cod_receipts', 'order_id'],
        // Courier COD settlement (§4.5): one `cod_received` per booked settlement line.
        'courier_settlement_item' => ['courier_settlement_items', 'order_id'],
        // An expense tied to one order (e.g. its packaging) is part of that order's P&L.
        'expense' => ['expenses', 'order_id'],
        'expense_void' => ['expenses', 'order_id'],
        'expense_payment' => ['expenses', 'order_id'],
    ];

    /** @var array<string, bool> */
    private array $tables = [];

    /**
     * The available mappings (tables that exist).
     *
     * @return array<string, array{0: string, 1: string}|null>
     */
    public function mappings(): array
    {
        return array_filter(
            self::MAP,
            fn (?array $target): bool => $target === null || ($this->tables[$target[0]] ??= Schema::hasTable($target[0])),
        );
    }

    /**
     * The order ids that these (source type, source id) pairs belong to.
     *
     * @param  list<array{0: string, 1: int}>  $sources
     * @return list<int>
     */
    public function orderIdsFor(array $sources): array
    {
        $mappings = $this->mappings();
        $byType = [];
        foreach ($sources as [$type, $id]) {
            if (array_key_exists($type, $mappings)) {
                $byType[$type][] = $id;
            }
        }

        $orderIds = [];
        foreach ($byType as $type => $ids) {
            $target = $mappings[$type];
            if ($target === null) {
                array_push($orderIds, ...$ids);

                continue;
            }
            foreach (DB::table($target[0])->whereIn('id', array_unique($ids))->pluck($target[1]) as $orderId) {
                if (is_numeric($orderId)) {
                    $orderIds[] = (int) $orderId;
                }
            }
        }

        return array_values(array_unique($orderIds));
    }
}
