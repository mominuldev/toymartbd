<?php

declare(strict_types=1);

use App\Domain\Marketing\Models\Coupon;
use App\Domain\Ordering\Actions\PlaceOrder;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Role;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Marketing\Promotions\PromoKit;
use Tests\Feature\Orders\Support;

/*
 * The global lock order (DATABASE-DESIGN §4.8): every order-writing transaction takes its
 * row locks in the same sequence — the order (or, at checkout, the cart) row → stock levels
 * in ascending variant id → coupons → promotions in ascending id, each followed by its
 * flash-sale items. Two actions that disagree (EditOrder once locked promotions before stock
 * while PlaceOrder locked stock first) deadlock each other under load.
 *
 * SQLite drops FOR UPDATE from the SQL, so the test swaps in a grammar that marks every
 * locking select with a comment; on MySQL the real `for update` is captured.
 */

const LOCK_TIERS = ['carts' => 0, 'orders' => 0, 'stock_levels' => 1, 'coupons' => 2, 'promotions' => 3, 'flash_sale_items' => 4];

/** @return object{tables: list<string>} */
function captureLockSequence(): object
{
    $connection = DB::connection();
    if ($connection->getDriverName() === 'sqlite') {
        $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, $value): string
            {
                return $value === true ? '/* for update */' : '';
            }
        });
    }
    $log = new class
    {
        /** @var list<string> */
        public array $tables = [];
    };
    DB::listen(function (QueryExecuted $q) use ($log): void {
        if (stripos($q->sql, 'for update') !== false && preg_match('/from [`"]?(\w+)[`"]?/i', $q->sql, $m) === 1) {
            $log->tables[] = $m[1];
        }
    });

    return $log;
}

/** @param  list<string>  $tables */
function assertGlobalLockOrder(array $tables): void
{
    $tiered = array_values(array_filter($tables, static fn (string $t): bool => array_key_exists($t, LOCK_TIERS)));

    // Every tier first appears in the global order…
    $firsts = array_values(array_unique($tiered));
    $ranks = array_map(static fn (string $t): int => LOCK_TIERS[$t], $firsts);
    $sorted = $ranks;
    sort($sorted);
    expect($ranks)->toBe($sorted, 'first locks out of order: '.implode(' → ', $tiered));

    // …and no stock row is locked after any Marketing row (coupon, promotion, flash item).
    $lastStock = max(array_keys($tiered, 'stock_levels', true) ?: [-1]);
    $firstMarketing = min(array_keys(array_filter($tiered, static fn (string $t): bool => LOCK_TIERS[$t] >= 2)) ?: [PHP_INT_MAX]);
    expect($lastStock)->toBeLessThan($firstMarketing, 'stock locked after marketing: '.implode(' → ', $tiered));
}

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    config(['risk.first_order_hold_threshold' => '100000.00']);
    $this->flashVariant = CartTestKit::variant('1000.00', 20);
    $this->plainVariant = CartTestKit::variant('500.00', 20);
    PromoKit::flash([$this->flashVariant->id => ['800.00', 10]]);
    Coupon::factory()->fixed('100.00')->create(['code' => 'LOCK100']);
});

it('PlaceOrder locks cart → stock → coupon → promotions → flash items', function (): void {
    $cart = CheckoutKit::cart([$this->flashVariant->id => 2, $this->plainVariant->id => 1], 'LOCK100');
    $data = CheckoutKit::data($cart);
    $log = captureLockSequence();

    $order = app(PlaceOrder::class)->handle($data);

    expect((string) $order->coupon_discount)->toBe('100.00')
        ->and($order->promotion_discount->toDecimal())->toBe('400.00');
    expect($log->tables)->toContain('carts', 'stock_levels', 'coupons', 'promotions', 'flash_sale_items');
    assertGlobalLockOrder($log->tables);
});

it('EditOrder locks order → stock → coupon → promotions → flash items, the same order', function (): void {
    $order = app(PlaceOrder::class)->handle(CheckoutKit::data(CheckoutKit::cart([$this->flashVariant->id => 2], 'LOCK100')));
    Support::actingAs(Role::OrderOperator);
    $body = Support::body([$this->flashVariant->id => 3, $this->plainVariant->id => 1]);
    unset($body['source']);
    $body['expected_total'] = $this->postJson("/api/v1/admin/orders/{$order->id}/edit-quote", $body)->assertOk()->json('grand_total');
    $log = captureLockSequence();

    $this->putJson("/api/v1/admin/orders/{$order->id}", $body)->assertOk()
        ->assertJsonPath('totals.coupon_discount', '100.00')
        ->assertJsonPath('totals.promotion_discount', '600.00');

    expect($log->tables)->toContain('orders', 'stock_levels', 'coupons', 'promotions', 'flash_sale_items');
    assertGlobalLockOrder($log->tables);
    PromoKit::assertInvariants();
    expect(Order::query()->sole()->id)->toBe($order->id);
});
