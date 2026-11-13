<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Domain\Finance\Services\FinanceInvariant;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Services\OrderTransitions;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/** Shared helpers for the Finance tests. */
final class FinanceKit
{
    public static function actingAs(Role $role): User
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->withRole($role)->create()->refresh();
        test()->actingAs($user, 'web');

        return $user;
    }

    /**
     * A shipped COD order: 2 × ৳600 at a ৳350 snapshot cost, a ৳100 discount, ৳70 shipping.
     * grand_total = 1200 − 100 + 70 = 1170; Σ line_total = 1100.
     */
    public static function shippedOrder(string $paymentMethod = 'cod'): Order
    {
        $order = Order::factory()->status(OrderStatus::Shipped)->create([
            'payment_method' => $paymentMethod,
            'subtotal' => '1200.00',
            'discount_total' => '100.00',
            'coupon_discount' => '100.00',
            'shipping_charge' => '70.00',
            'grand_total' => '1170.00',
            'due_total' => '1170.00',
            'shipped_at' => now(),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'unit_price' => '600.00',
            'quantity' => 2,
            'discount' => '100.00',
            'line_total' => '1100.00',
            'cost_price_snapshot' => '350.0000',
        ]);

        return $order->refresh();
    }

    /** Move the order to delivered through the state machine (OrderDelivered fires after commit). */
    public static function deliver(Order $order): Order
    {
        return DB::transaction(static fn (): Order => app(OrderTransitions::class)->transition($order, OrderStatus::Delivered));
    }

    /** §7.2: order_financials equal the ledger, for every order. */
    public static function assertFinanceInvariant(): void
    {
        expect(app(FinanceInvariant::class)->drift())->toBe([]);
    }
}
