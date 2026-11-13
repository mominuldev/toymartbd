<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Finance\Services\OrderLedgerSources;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Revenue recognition on delivery (DATABASE-DESIGN §4.3, rule 10). Writes, dated the Dhaka
 * day of `delivered_at` (rule 11), all recognised and **none** of them cash — for COD the
 * courier is holding the money until remittance (`cod_received`, §4.5):
 *
 *   sale_revenue      in   subtotal + tax_total (gross of order-level discounts)
 *   discount_given    out  discount_total
 *   shipping_revenue  in   shipping_charge
 *   cogs              out  Σ cost_price_snapshot × quantity (rule 7 — the snapshot, never today's cost)
 *
 * so revenue − discount = Σ line_total (§5.4). Idempotent: every entry is keyed on
 * (`order`, order id, type), so a replayed OrderDelivered writes nothing. An order that is
 * not delivered (or later) — e.g. cancelled before shipping — is skipped.
 */
final readonly class RecognizeOrderRevenue
{
    private const array RECOGNISABLE = [
        OrderStatus::Delivered, OrderStatus::Completed, OrderStatus::Returned, OrderStatus::PartiallyReturned,
    ];

    public function __construct(private Ledger $ledger) {}

    public function handle(int $orderId): void
    {
        DB::transaction(function () use ($orderId): void {
            $order = Order::query()->with('items')->find($orderId);
            if ($order === null || $order->delivered_at === null || ! in_array($order->status, self::RECOGNISABLE, true)) {
                return;
            }

            $on = LedgerEntry::dhakaDateOf($order->delivered_at);
            $cogs = $order->items->reduce(static fn (Money $c, $item): Money => $c->plus($item->cogs()), Money::zero());
            $entry = static fn (LedgerEntryType $type, Direction $direction, Money $amount): LedgerEntry => new LedgerEntry(
                type: $type,
                direction: $direction,
                amount: $amount,
                occurredOn: $on,
                sourceType: OrderLedgerSources::ORDER,
                sourceId: $order->id,
                isCashMovement: false,
                isRecognisedRevenue: true,
                description: "Order {$order->number} delivered",
            );

            $entries = [
                $entry(LedgerEntryType::SaleRevenue, Direction::In, $order->subtotal->plus($order->tax_total)),
                $entry(LedgerEntryType::Cogs, Direction::Out, $cogs),
            ];
            if (! $order->discount_total->isZero()) {
                $entries[] = $entry(LedgerEntryType::DiscountGiven, Direction::Out, $order->discount_total);
            }
            if (! $order->shipping_charge->isZero()) {
                $entries[] = $entry(LedgerEntryType::ShippingRevenue, Direction::In, $order->shipping_charge);
            }

            $this->ledger->record(...$entries);
        });
    }
}
