<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Feature\Ordering\Support\OrderFixtures;
use Tests\Support\RoleMatrix;

/*
 * Admin order board + detail (P4-ORD-04/05 read side): status-tab counts, allowlisted
 * filters, cursor pagination, role-based PII masking, and the 403 role matrix (rule 21).
 */

function ordActingAs(Role $role): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole($role)->create()->refresh();
    test()->actingAs($user, 'web');

    return $user;
}

it('guards GET /orders with orders.view', function (): void {
    RoleMatrix::assert($this, Permission::OrdersView, fn () => $this->getJson('/api/v1/admin/orders'));
});

it('guards GET /orders/{order} with orders.view', function (): void {
    $order = Order::factory()->create();

    RoleMatrix::assert($this, Permission::OrdersView, fn () => $this->getJson("/api/v1/admin/orders/{$order->id}"));
});

it('refuses a guest with 401', function (string $uri): void {
    $order = Order::factory()->create();

    $this->getJson(str_replace('{id}', (string) $order->id, $uri))->assertUnauthorized();
})->with(['/api/v1/admin/orders', '/api/v1/admin/orders/{id}']);

it('lists orders newest first with a masked phone and status-tab counts', function (): void {
    ordActingAs(Role::OrderOperator);
    $customer = Customer::factory()->create(['phone' => '+8801812345678', 'name' => 'নুসরাত জাহান']);
    Order::factory()->count(2)->for($customer)->create(['created_at' => now()->subHour()]);
    $latest = Order::factory()->for($customer)->status(OrderStatus::Confirmed)->create();
    Order::factory()->status(OrderStatus::Cancelled)->create(['created_at' => now()->subDay()]);

    $response = $this->getJson('/api/v1/admin/orders')->assertOk();

    expect($response->json('data.0.number'))->toBe($latest->number)
        ->and($response->json('data.0.customer_phone_masked'))->toBe('018****5678')
        ->and($response->json('data.0.customer_name'))->toBe('নুসরাত জাহান')
        ->and($response->json('data.0.grand_total'))->toBe('1320.00')
        ->and($response->json('meta.counts'))->toMatchArray(['all' => 4, 'pending' => 2, 'confirmed' => 1, 'cancelled' => 1, 'shipped' => 0])
        ->and($response->getContent())->not->toContain('+8801812345678');
});

it('filters by status while the tab counts ignore the status filter', function (): void {
    ordActingAs(Role::Viewer);
    Order::factory()->count(3)->create();
    Order::factory()->status(OrderStatus::OnHold)->create(['payment_status' => OrderPaymentStatus::Pending]);

    $response = $this->getJson('/api/v1/admin/orders?filter[status]=on_hold')->assertOk();
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('meta.counts.all'))->toBe(4)
        ->and($response->json('meta.counts.on_hold'))->toBe(1);

    $both = $this->getJson('/api/v1/admin/orders?filter[payment_status]=pending')->assertOk();
    expect($both->json('data'))->toHaveCount(1)->and($both->json('meta.counts.all'))->toBe(1);
});

it('searches by order number, phone digits and name', function (): void {
    ordActingAs(Role::Viewer);
    $match = Order::factory()->create(['shipping_phone' => '+8801912340000', 'shipping_name' => 'আরিফ হোসেন']);
    Order::factory()->count(2)->create(['shipping_name' => 'Tanvir Ahmed']);

    foreach ([$match->number, '0000', '01912340000', 'আরিফ'] as $term) {
        expect($this->getJson('/api/v1/admin/orders?filter[search]='.urlencode($term))->assertOk()->json('data.*.id'))
            ->toBe([$match->id], $term);
    }
});

it('filters by Dhaka date, inclusive', function (): void {
    ordActingAs(Role::Viewer);
    // 2026-11-14 18:30 UTC is 15 Nov 00:30 in Dhaka.
    $inside = Order::factory()->create(['created_at' => '2026-11-14 18:30:00']);
    Order::factory()->create(['created_at' => '2026-11-14 17:59:00']);

    $ids = $this->getJson('/api/v1/admin/orders?filter[placed_from]=2026-11-15&filter[placed_to]=2026-11-15')->assertOk()->json('data.*.id');

    expect($ids)->toBe([$inside->id]);
});

it('returns 400 for an unlisted filter or sort or a bad value', function (string $query): void {
    ordActingAs(Role::Manager);

    $this->getJson('/api/v1/admin/orders?'.$query)->assertStatus(400);
})->with([
    'filter[shipping_phone]=01712345678',
    'sort=shipping_phone',
    'filter[status]=lost',
    'filter[placed_from]=15-11-2026',
    'filter[customer_id]=abc',
]);

it('paginates with a cursor', function (): void {
    ordActingAs(Role::Viewer);
    Order::factory()->count(30)->create();

    $first = $this->getJson('/api/v1/admin/orders')->assertOk();
    $next = $first->json('meta.next_cursor');
    $second = $this->getJson('/api/v1/admin/orders?cursor='.$next)->assertOk();

    expect($first->json('data'))->toHaveCount(25)
        ->and($second->json('data'))->toHaveCount(5)
        ->and(array_intersect($first->json('data.*.id'), $second->json('data.*.id')))->toBe([]);
});

it('shows full phone and address to a role with customers.view_pii', function (): void {
    ordActingAs(Role::OrderOperator);
    $variant = OrderFixtures::stockedVariant();
    $order = OrderFixtures::place([$variant->id => 2]);

    $json = $this->getJson("/api/v1/admin/orders/{$order->id}")->assertOk()->json();

    expect($json['pii_masked'])->toBeFalse()
        ->and($json['shipping']['phone'])->toBe($order->shipping_phone)
        ->and($json['shipping']['address'])->toBe('বাড়ি 12, রোড 5')
        ->and($json['shipping']['landmark'])->toBe('জামে মসজিদের পাশে')
        ->and($json['items'][0]['quantity'])->toBe(2)
        ->and($json['items'][0]['cost_price_snapshot'])->toBeNull() // no catalog.view_cost
        ->and($json['payments'][0]['method'])->toBe('cod')
        ->and($json['timeline'][0]['type'])->toBe('placed');
});

it('masks phone and street address for a role without customers.view_pii', function (Role $role): void {
    ordActingAs($role);
    $variant = OrderFixtures::stockedVariant();
    $order = OrderFixtures::place([$variant->id => 1]);

    $response = $this->getJson("/api/v1/admin/orders/{$order->id}")->assertOk();
    $json = $response->json();

    expect($json['pii_masked'])->toBeTrue()
        ->and($json['shipping']['phone'])->toMatch('/^01[3-9]\*{4}\d{4}$/')
        ->and($json['customer']['phone'])->toMatch('/^01[3-9]\*{4}\d{4}$/')
        ->and($json['shipping']['address'])->toBeNull()
        ->and($json['shipping']['landmark'])->toBeNull()
        ->and($json['shipping']['district'])->toBe('ঢাকা')
        ->and($response->getContent())->not->toContain(substr($order->shipping_phone, 3))
        ->not->toContain('বাড়ি 12');
})->with([Role::Viewer, Role::Accountant, Role::InventoryStaff]);

it('shows the cost snapshot only with catalog.view_cost', function (): void {
    ordActingAs(Role::Accountant);
    $variant = OrderFixtures::stockedVariant(cost: '812.50');
    $order = OrderFixtures::place([$variant->id => 1]);

    expect($this->getJson("/api/v1/admin/orders/{$order->id}")->assertOk()->json('items.0.cost_price_snapshot'))->toBe('812.5000');
});
