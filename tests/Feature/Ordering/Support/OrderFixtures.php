<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering\Support;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Inventory\Services\StockReservations;
use App\Domain\Ordering\Enums\OrderSource;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Services\OrderNumberGenerator;
use App\Domain\Ordering\Services\OrderTransitions;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Services\PaymentTransitions;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Test-only stand-in for PlaceOrder (batch 5) — creates an order exactly the shape the
 * real checkout will, through the same services, minus cart pricing/coupons/risk:
 *
 *   number from OrderNumberGenerator · reserve stock (ascending variant id) · order +
 *   items with cost_price_snapshot from the variant · payment opened `pending` ·
 *   `placed` order_event + OrderPlaced.
 *
 * Usage:
 *   $variant = OrderFixtures::stockedVariant(10);
 *   $order = OrderFixtures::place([$variant->id => 2]);
 */
final class OrderFixtures
{
    /** A variant with opening stock on hand (an `opening_balance` movement). */
    public static function stockedVariant(int $onHand = 10, string $price = '1250.00', string $cost = '800.00'): ProductVariant
    {
        $variant = ProductVariant::factory()->create(['price' => $price, 'cost_price' => $cost]);
        if ($onHand > 0) {
            DB::transaction(fn () => app(StockLedger::class)->recordOne($variant->id, StockMovementType::OpeningBalance, $onHand));
        }

        return $variant;
    }

    /**
     * @param  array<int, int>  $lines  variant id → quantity
     * @param  array<string, mixed>  $overrides  order columns
     */
    public static function place(
        array $lines,
        PaymentMethod $method = PaymentMethod::Cod,
        ?Customer $customer = null,
        string $shippingCharge = '70.00',
        array $overrides = [],
    ): Order {
        $customer ??= Customer::factory()->create();
        ksort($lines);

        return DB::transaction(function () use ($lines, $method, $customer, $shippingCharge, $overrides): Order {
            app(StockReservations::class)->reserve($lines);

            $variants = ProductVariant::query()->with('product')->whereKey(array_keys($lines))->orderBy('id')->get();
            $subtotal = Money::zero();
            $items = [];
            foreach ($variants as $variant) {
                $quantity = $lines[$variant->id];
                $lineTotal = $variant->price->times($quantity);
                $subtotal = $subtotal->plus($lineTotal);
                $items[] = [
                    'variant_id' => $variant->id,
                    'product_id' => $variant->product_id,
                    'product_name' => $variant->product->name,
                    'product_name_bn' => $variant->product->name_bn,
                    'variant_name' => $variant->name,
                    'sku' => $variant->sku,
                    'unit_price' => $variant->price,
                    'quantity' => $quantity,
                    'line_total' => $lineTotal,
                    'cost_price_snapshot' => $variant->cost_price->toDecimal(),
                ];
            }
            $shipping = Money::of($shippingCharge);
            $grand = $subtotal->plus($shipping);

            $order = Order::query()->create($overrides + [
                'number' => app(OrderNumberGenerator::class)->next(),
                'customer_id' => $customer->id,
                'source' => OrderSource::Web,
                'payment_method' => $method->value,
                'subtotal' => $subtotal,
                'shipping_charge' => $shipping,
                'grand_total' => $grand,
                'due_total' => $grand,
                'shipping_name' => $customer->name,
                'shipping_phone' => $customer->phone,
                'shipping_division' => 'ঢাকা',
                'shipping_district' => 'ঢাকা',
                'shipping_area' => 'ধানমন্ডি',
                'shipping_address' => 'বাড়ি 12, রোড 5',
                'shipping_landmark' => 'জামে মসজিদের পাশে',
                'placed_at' => now(),
            ]);
            foreach ($items as $item) {
                OrderItem::query()->create(['order_id' => $order->id] + $item);
            }

            app(PaymentTransitions::class)->open($order->id, $method, $grand);
            app(OrderTransitions::class)->placed($order);

            return $order->refresh();
        });
    }
}
