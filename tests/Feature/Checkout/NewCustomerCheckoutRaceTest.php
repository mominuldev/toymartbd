<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Actions\PlaceOrder;
use App\Domain\Ordering\Checkout\CheckoutData;
use App\Domain\Ordering\Models\Order;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Support\ForkRace;

/*
 * Launch day: every shopper is a new customer (P9, LOAD-AND-CONCURRENCY F-6). Checkout
 * used to look the phone up with FOR UPDATE before creating it; on a missing key that is
 * a GAP lock on customers_phone_unique, and two new phones in the same gap deadlocked on
 * their inserts — PlaceOrder's retry hid it, at the price of a whole rolled-back checkout
 * per collision (3 deadlocks in a 50-shopper load run). Each cart here has its own variant
 * so stock locks do not serialise the checkouts before the customer step.
 *
 * MySQL only (CI's mysql group): asserts every checkout placed and InnoDB counted no
 * deadlock. The SQLite suite covers find-or-create semantics in CheckoutApiTest.
 */

beforeEach(function (): void {
    if (onlyOnMysql()) {
        return;
    }
    ForkRace::open('new_customer_race');
});

afterEach(function (): void {
    if (onlyOnMysql()) {
        return;
    }
    ForkRace::close();
});

it('places parallel first orders from new phones without a deadlock', function (): void {
    expect(function_exists('pcntl_fork'))->toBeTrue('pcntl is required for the race test');
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    config(['risk.first_order_hold_threshold' => '100000.00']);

    $inputs = [];
    for ($i = 0; $i < 12; $i++) {
        $variant = CartTestKit::variant('1000.00', 5);
        $inputs[] = CheckoutKit::data(CheckoutKit::cart([$variant->id => 1]), sprintf('0181%07d', $i));
    }
    // One returning customer racing the new ones: found, locked, never duplicated.
    Customer::factory()->create(['phone' => '+8801810000003']);
    $before = ForkRace::deadlocks();

    $results = ForkRace::run(array_map(static fn (CheckoutData $input): Closure => static fn (): string => app(PlaceOrder::class)->handle($input)->number, $inputs));

    expect(array_values(array_filter($results, static fn (string $r): bool => ! str_starts_with($r, 'TMB-'))))->toBe([])
        ->and(Order::query()->count())->toBe(12)
        ->and(Customer::query()->count())->toBe(12)
        ->and(Customer::query()->distinct()->count('phone'))->toBe(12)
        ->and(ForkRace::deadlocks() - $before)->toBe(0);
})->group('mysql')->skip(onlyOnMysql(...), 'Parallel checkouts need MySQL row locking (make test-mysql).');
