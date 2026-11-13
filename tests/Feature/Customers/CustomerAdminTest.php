<?php

declare(strict_types=1);

use App\Domain\Customer\Actions\RebuildCustomerAggregates;
use App\Domain\Customer\Models\Address;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerNote;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Division;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Models\Enums\Permission as P;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\RoleMatrix;

/*
 * Admin Customers (P8; Figma B · 17 / B · 18): list search + allowlisted filters and sorts,
 * PII masking per role (rule 20), append-only notes, and the 403 matrix (rule 21).
 */

function cxActingAs(Role $role): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole($role)->create()->refresh();
    app('auth')->forgetGuards(); // a second login in one test must not reuse the resolved guard
    test()->actingAs($user, 'web');

    return $user;
}

function cxRebuild(): void
{
    app(RebuildCustomerAggregates::class)->handle();
}

/** @return list<string> names in response order */
function cxNames(Illuminate\Testing\TestResponse $response): array
{
    return array_column($response->json('data'), 'name');
}

it('guards the customer endpoints for every role', function (): void {
    $customer = Customer::factory()->create();

    RoleMatrix::assert($this, P::CustomersView, fn () => $this->getJson('/api/v1/admin/customers'));
    RoleMatrix::assert($this, P::CustomersView, fn () => $this->getJson("/api/v1/admin/customers/{$customer->id}"));
    RoleMatrix::assert($this, P::CustomersView, fn () => $this->getJson("/api/v1/admin/customers/{$customer->id}/notes"));
    RoleMatrix::assert($this, P::CustomersUpdate, fn () => $this->postJson("/api/v1/admin/customers/{$customer->id}/notes", ['body' => 'Prefers evening calls']), 201);
});

it('lists customers with a masked phone for every role, even super admin', function (): void {
    cxActingAs(Role::SuperAdmin);
    Customer::factory()->create(['name' => 'Jahangir Alam', 'phone' => '+8801711258046']);

    $row = $this->getJson('/api/v1/admin/customers')->assertOk()->json('data.0');

    expect($row['phone_masked'])->toBe('017****8046')
        ->and(json_encode($row))->not->toContain('1711258046');
});

it('searches by name, phone digits and order number', function (): void {
    cxActingAs(Role::Manager);
    $a = Customer::factory()->create(['name' => 'Farhana Yasmin', 'phone' => '+8801777123615']);
    $b = Customer::factory()->create(['name' => 'রাকিব হাসান', 'phone' => '+8801919000052']);
    $c = Customer::factory()->create(['name' => 'Tanzila Akter', 'phone' => '+8801533000480']);
    Order::factory()->for($c)->create(['number' => 'TMB-26-00118']);

    $search = fn (string $q): array => cxNames($this->getJson('/api/v1/admin/customers?filter[search]='.urlencode($q))->assertOk());

    expect($search('farhana'))->toBe(['Farhana Yasmin'])
        ->and($search('রাকিব'))->toBe(['রাকিব হাসান'])
        ->and($search('01919000'))->toBe(['রাকিব হাসান'])
        ->and($search('7123615'))->toBe(['Farhana Yasmin'])
        ->and($search('tmb-26-0011'))->toBe(['Tanzila Akter'])
        ->and($search('zzz'))->toBe([]);
});

it('filters by segment, account, risk status, district and last-order range', function (): void {
    cxActingAs(Role::SuperAdmin);
    $division = Division::query()->firstOrCreate(['name' => 'Khulna'], ['name_bn' => 'খুলনা']);
    $khulna = District::query()->create(['division_id' => $division->id, 'name' => 'Khulna', 'name_bn' => 'খুলনা']);

    $fresh = Customer::factory()->create(['name' => 'Fresh One', 'phone_verified_at' => now()]);
    Order::factory()->for($fresh)->create(['created_at' => now()->subDays(3), 'placed_at' => now()->subDays(3), 'shipping_district_id' => $khulna->id]);
    $sleepy = Customer::factory()->create(['name' => 'Sleepy Two']);
    Order::factory()->for($sleepy)->count(2)->create(['created_at' => now()->subDays(120), 'placed_at' => now()->subDays(120)]);
    $gone = Customer::factory()->create(['name' => 'Gone Three']);
    Order::factory()->for($gone)->create(['created_at' => now()->subDays(400), 'placed_at' => now()->subDays(400)]);
    $none = Customer::factory()->create(['name' => 'Never Four']);
    Address::factory()->for($none)->create(['district_id' => $khulna->id, 'division_id' => $division->id]);
    CustomerRiskProfile::query()->create(['phone' => $sleepy->phone, 'customer_id' => $sleepy->id, 'internal_score' => 80, 'status' => 'blacklist']);
    cxRebuild();

    $list = fn (string $query): array => cxNames($this->getJson('/api/v1/admin/customers?'.$query)->assertOk());

    expect($list('filter[segment]=new'))->toBe(['Fresh One'])
        ->and($list('filter[segment]=at_risk'))->toBe(['Sleepy Two'])
        ->and($list('filter[segment]=lapsed'))->toBe(['Gone Three'])
        ->and($list('filter[segment]=no_orders'))->toBe(['Never Four'])
        ->and($list('filter[has_account]=1'))->toBe(['Fresh One'])
        ->and($list('filter[has_account]=0&sort=created'))->toBe(['Sleepy Two', 'Gone Three', 'Never Four'])
        ->and($list('filter[risk]=blacklist'))->toBe(['Sleepy Two'])
        ->and($list('filter[district_id]='.$khulna->id.'&sort=created'))->toBe(['Fresh One', 'Never Four'])
        ->and($list('filter[last_order_from]='.now('Asia/Dhaka')->subDays(10)->toDateString()))->toBe(['Fresh One'])
        ->and($list('filter[last_order_to]='.now('Asia/Dhaka')->subDays(10)->toDateString()))->toBe(['Sleepy Two', 'Gone Three']);

    $meta = $this->getJson('/api/v1/admin/customers?filter[segment]=new')->json('meta');
    expect($meta['counts'])->toMatchArray(['all' => 4, 'new' => 1, 'at_risk' => 1, 'lapsed' => 1, 'no_orders' => 1, 'vip' => 0, 'active' => 0, 'blacklisted' => 1])
        ->and($meta['total'])->toBe(1)
        ->and($meta['thresholds'])->toBe(['vip_min_lifetime_value' => '15000.00', 'at_risk_after_days' => 90, 'lapsed_after_days' => 180]);
});

it('sorts by an allowlist and answers 400 to anything unlisted', function (): void {
    cxActingAs(Role::Manager);
    $few = Customer::factory()->create(['name' => 'Few']);
    Order::factory()->for($few)->create();
    $many = Customer::factory()->create(['name' => 'Many']);
    Order::factory()->for($many)->count(3)->create();
    cxRebuild();

    expect(cxNames($this->getJson('/api/v1/admin/customers?sort=-orders_count')))->toBe(['Many', 'Few'])
        ->and(cxNames($this->getJson('/api/v1/admin/customers?sort=orders_count')))->toBe(['Few', 'Many']);

    foreach ([
        'filter[email]=x',
        'filter[segment]=gold',
        'filter[has_account]=maybe',
        'filter[risk]=high',
        'filter[last_order_from]=2026-13-01',
        'filter[district_id]=abc',
        'sort=phone',
        'sort=total_spent',
        'page=0',
        'per_page=500',
    ] as $query) {
        $this->getJson('/api/v1/admin/customers?'.$query)->assertStatus(400)->assertJsonPath('type', fn (string $t): bool => str_ends_with($t, 'bad-request'));
    }
});

it('shows the full phone, e-mail and street address only with customers.view_pii', function (): void {
    $customer = Customer::factory()->create(['phone' => '+8801711258046', 'email' => 'jahangir.alam@example.com']);
    Address::factory()->for($customer)->create(['address_line' => 'House 22, Road 9/A', 'landmark' => 'beside Abahani field', 'phone' => '+8801711258046']);

    cxActingAs(Role::OrderOperator);
    $full = $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk();
    expect($full->json('pii_masked'))->toBeFalse()
        ->and($full->json('phone'))->toBe('+8801711258046')
        ->and($full->json('email'))->toBe('jahangir.alam@example.com')
        ->and($full->json('addresses.0.address_line'))->toBe('House 22, Road 9/A')
        ->and($full->json('addresses.0.landmark'))->toBe('beside Abahani field');

    cxActingAs(Role::Viewer);
    $masked = $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk();
    expect($masked->json('pii_masked'))->toBeTrue()
        ->and($masked->json('phone'))->toBe('017****8046')
        ->and($masked->json('email'))->toBe('j***@example.com')
        ->and($masked->json('addresses.0.address_line'))->toBeNull()
        ->and($masked->json('addresses.0.landmark'))->toBeNull()
        ->and($masked->json('addresses.0.recipient_name'))->toBeNull()
        ->and($masked->json('addresses.0.district'))->toBe('Dhaka')
        ->and($masked->getContent())->not->toContain('1711258046')->not->toContain('Road 9/A');
});

it('summarises orders on the profile and hides the risk card without risk.view', function (): void {
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->status(OrderStatus::Delivered)->count(2)->create();
    Order::factory()->for($customer)->status(OrderStatus::Returned)->create();
    Order::factory()->for($customer)->status(OrderStatus::Cancelled)->create();
    cxRebuild();

    cxActingAs(Role::Manager);
    $profile = $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk();
    expect($profile->json('stats'))->toMatchArray([
        'orders_count' => 3,
        'delivered_orders_count' => 2,
        'returned_orders_count' => 1,
        'cancelled_orders_count' => 1,
        'delivery_success_rate' => '66.67',
    ])->and($profile->json('orders'))->toHaveCount(4)
        ->and($profile->json('risk.ours.stored'))->toBeFalse();

    cxActingAs(Role::ContentEditor);
    // content editors hold no customers.view at all
    $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertForbidden();
});

it('appends notes with their author and refuses edits and deletes', function (): void {
    $customer = Customer::factory()->create();
    $user = cxActingAs(Role::OrderOperator);

    $this->postJson("/api/v1/admin/customers/{$customer->id}/notes", ['body' => 'Buys birthday gifts in Feb and Aug', 'is_pinned' => true])
        ->assertCreated()
        ->assertJsonPath('user_name', $user->name)
        ->assertJsonPath('is_pinned', true);
    $this->postJson("/api/v1/admin/customers/{$customer->id}/notes", ['body' => ''])->assertUnprocessable();

    $notes = $this->getJson("/api/v1/admin/customers/{$customer->id}/notes")->assertOk()->json('data');
    expect($notes)->toHaveCount(1)
        ->and($this->getJson("/api/v1/admin/customers/{$customer->id}")->json('pinned_note.body'))->toBe('Buys birthday gifts in Feb and Aug');

    $note = CustomerNote::query()->firstOrFail();
    expect(fn () => $note->update(['body' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $note->delete())->toThrow(LogicException::class);
});

it('has no route that deletes a customer', function (): void {
    cxActingAs(Role::SuperAdmin);
    $customer = Customer::factory()->create();

    $this->deleteJson("/api/v1/admin/customers/{$customer->id}")->assertStatus(405);
    expect(Customer::query()->whereKey($customer->id)->exists())->toBeTrue();
});
