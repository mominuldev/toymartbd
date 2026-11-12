<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Checkout\Support\CheckoutKit;

/**
 * Shared helpers for the admin create / edit / bulk order tests (P4-ORD-06/07/08).
 * Needs GeographySeeder + DeliverySeeder (CheckoutKit's Chattogram / Rangunia address).
 */
final class Support
{
    public static function actingAs(Role $role): User
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->withRole($role)->create()->refresh();
        test()->actingAs($user, 'web');

        return $user;
    }

    /**
     * The admin create-order body for these lines.
     *
     * @param  array<int, int>  $lines
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function body(array $lines, array $overrides = []): array
    {
        return [
            'lines' => array_map(static fn (int $id, int $qty): array => ['variant_id' => $id, 'quantity' => $qty], array_keys($lines), $lines),
            'name' => 'রাহেলা খাতুন',
            'phone' => CheckoutKit::PHONE,
            'district_id' => CheckoutKit::DISTRICT,
            'upazila_id' => CheckoutKit::UPAZILA,
            'area' => 'আগ্রাবাদ',
            'address_line' => 'বাড়ি ১২, রোড ৫, ব্লক সি',
            'landmark' => 'জামে মসজিদের পাশে, ৩য় তলা',
            'source' => 'phone',
            ...$overrides,
        ];
    }

    /**
     * §7.1: Σ stock_movements = on_hand for every level, and reserved = what open orders hold.
     */
    public static function assertStockInvariants(): void
    {
        $sums = StockMovement::query()->groupBy('variant_id', 'location_id')
            ->select('variant_id', 'location_id', DB::raw('SUM(quantity) as total'))->get()
            ->mapWithKeys(fn ($r) => ["{$r->variant_id}:{$r->location_id}" => (int) $r->total]);

        $held = [];
        foreach (Order::query()->with('items')->get() as $order) {
            if ($order->status->holdsReservation()) {
                foreach ($order->reservedLines() as $variantId => $qty) {
                    $held[$variantId] = ($held[$variantId] ?? 0) + $qty;
                }
            }
        }

        foreach (StockLevel::all() as $level) {
            expect($sums["{$level->variant_id}:{$level->location_id}"] ?? 0)->toBe($level->on_hand)
                ->and($level->reserved)->toBe($held[$level->variant_id] ?? 0);
        }
    }

    /** §7.3 plus lines and payment: the order's money agrees with itself and its pending payment. */
    public static function assertOrderFinancials(Order $order): void
    {
        $order->refresh()->load(['items', 'payments']);
        $lineSum = $order->items->reduce(static fn ($c, $i) => $c->plus($i->line_total), \App\Support\Money::zero());

        expect($order->grand_total->equals($order->subtotal->minus($order->discount_total)->plus($order->shipping_charge)->plus($order->tax_total)))->toBeTrue()
            ->and($lineSum->equals($order->subtotal->minus($order->discount_total)))->toBeTrue()
            ->and($order->due_total->equals($order->grand_total->minus($order->paid_total)))->toBeTrue();
        foreach ($order->payments as $payment) {
            expect($payment->amount->equals($order->grand_total))->toBeTrue();
        }
    }
}
