<?php

declare(strict_types=1);

namespace Tests\Feature\Returns;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Inventory\Services\StockReservations;
use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Services\OrderTransitions;
use App\Domain\Payment\Actions\CollectCodPayment;
use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Services\PaymentTransitions;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Ordering\Support\OrderFixtures;

/** Delivered, paid orders for the returns & refunds tests. */
final class ReturnsKit
{
    public static function actingAs(Role $role): User
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->withRole($role)->create()->refresh();
        auth()->forgetGuards();
        test()->actingAs($user, 'web');

        return $user;
    }

    /**
     * Place → dispatch (reservation consumed as `sale_out`) → delivered and fulfilled →
     * payment captured (COD collected, or an online payment verified paid). Revenue and
     * COGS are recognised by Finance's OrderDelivered listener, as in production.
     *
     * @param  array<int, int>  $lines  variant id → quantity
     */
    public static function deliveredOrder(array $lines, PaymentMethod $method = PaymentMethod::Cod, string $shipping = '70.00'): Order
    {
        $order = OrderFixtures::place($lines, $method, shippingCharge: $shipping);

        DB::transaction(function () use ($order, $lines): void {
            ksort($lines);
            app(StockReservations::class)->release($lines);
            app(StockLedger::class)->record(array_map(
                static fn (int $variantId, int $qty): StockMovementLine => new StockMovementLine($variantId, StockMovementType::SaleOut, $qty, referenceType: 'order', referenceId: $order->id),
                array_keys($lines), $lines,
            ));
            OrderItem::query()->where('order_id', $order->id)->update(['quantity_shipped' => DB::raw('quantity')]);

            $t = app(OrderTransitions::class);
            $t->transition($order, OrderStatus::Confirmed);
            $t->transition($order, OrderStatus::Shipped);
            $t->transitionFulfillment($order, FulfillmentStatus::Fulfilled);
            $t->transition($order, OrderStatus::Delivered);
        });

        /** @var Payment $payment */
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        if ($method === PaymentMethod::Cod) {
            app(CollectCodPayment::class)->handle($payment, $payment->amount);
        } else {
            DB::transaction(fn () => app(PaymentTransitions::class)->apply($payment, new GatewayResult(PaymentStatus::Paid, amount: $payment->amount, transactionId: 'TRX-1')));
        }

        return $order->refresh()->load('items');
    }

    public static function variant(int $onHand = 10, string $price = '1250.00', string $cost = '800.00'): ProductVariant
    {
        return OrderFixtures::stockedVariant($onHand, $price, $cost);
    }

    public static function line(Order $order, int $variantId): OrderItem
    {
        return $order->items->firstOrFail(static fn (OrderItem $i): bool => $i->variant_id === $variantId);
    }
}
