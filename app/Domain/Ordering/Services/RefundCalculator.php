<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Services;

use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Support\Money;

/**
 * How much a return gives back (DATABASE-DESIGN §4.7) — computed here, never in a client
 * (rule 1).
 *
 *   goods paid   = subtotal − discount_total   (what the customer paid for the lines)
 *   line net     = goods paid split across lines in proportion to line_total. At checkout
 *                  Σ line_total already equals goods paid (discounts are allocated into the
 *                  lines), so this is the line_total itself; an order whose discount sits
 *                  only at order level gets it shared out proportionally here.
 *   units' share = cumulative: net × (refunded_before + n) / qty − net × refunded_before / qty,
 *                  each rounded with Money::allocate, so returning every unit of a line —
 *                  in one go or several — refunds exactly its net, never a poisha more.
 *   delivery     = shipping_charge (what was paid for delivery), at most once per order.
 */
final class RefundCalculator
{
    /** @var array<int, array<int, Money>> order id → order item id → line net */
    private array $nets = [];

    /** The refund for `$units` more units of `$item`, after `$alreadyRefunded` units. */
    public function forUnits(Order $order, OrderItem $item, int $alreadyRefunded, int $units): Money
    {
        $net = $this->lineNets($order)[$item->id] ?? Money::zero();

        return $this->share($net, $item->quantity, $alreadyRefunded + $units)
            ->minus($this->share($net, $item->quantity, $alreadyRefunded));
    }

    /** The delivery charge the customer paid. */
    public function shipping(Order $order): Money
    {
        return $order->shipping_charge->isNegative() ? Money::zero() : $order->shipping_charge;
    }

    /** @return array<int, Money> order item id → its share of the goods paid */
    public function lineNets(Order $order): array
    {
        if (isset($this->nets[$order->id])) {
            return $this->nets[$order->id];
        }

        $items = $order->items;
        $goods = $order->subtotal->minus($order->discount_total);
        $lineSum = $items->reduce(static fn (Money $c, OrderItem $i): Money => $c->plus($i->line_total), Money::zero());

        $nets = [];
        if ($lineSum->equals($goods) || $lineSum->isZero() || $goods->isNegative()) {
            foreach ($items as $item) {
                $nets[$item->id] = $item->line_total;
            }
        } else {
            $ratios = $items->map(static fn (OrderItem $i): int => max(0, $i->line_total->toPoisha()))->values()->all();
            $shares = $goods->allocate(...$ratios);
            foreach ($items->values() as $i => $item) {
                $nets[$item->id] = $shares[$i];
            }
        }

        return $this->nets[$order->id] = $nets;
    }

    private function share(Money $net, int $quantity, int $units): Money
    {
        if ($units <= 0 || $quantity <= 0) {
            return Money::zero();
        }
        if ($units >= $quantity) {
            return $net;
        }

        return $net->allocate($units, $quantity - $units)[0];
    }
}
