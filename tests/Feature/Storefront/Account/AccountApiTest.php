<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Address;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Customer\Models\WishlistItem;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Storefront\Account\AccountTestKit;

/*
 * P4-STO-11 — the signed-in customer's account: profile, orders, addresses, wishlist.
 * A customer can never read or change another customer's rows.
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create(['name' => 'সাদিয়া ইসলাম', 'phone' => '+8801712345678']);
    $this->other = Customer::factory()->create(['phone' => '+8801812345678']);
    $this->bearer = AccountTestKit::tokenFor($this->customer);
});

function account(): Illuminate\Foundation\Testing\TestCase
{
    return test()->withToken(test()->bearer);
}

/** A district with a plain (non-city) upazila: rule-31 address fixtures. */
function addressPlace(): array
{
    $upazila = Upazila::query()->whereHas('district', fn ($q) => $q->where('name', '!=', 'Dhaka'))->firstOrFail();

    return ['district_id' => $upazila->district_id, 'upazila_id' => $upazila->id];
}

function addressBody(array $overrides = []): array
{
    return [
        'type' => 'home',
        'recipient_name' => 'সাদিয়া ইসলাম',
        'phone' => '01712345678',
        ...addressPlace(),
        'area' => 'আগ্রাবাদ',
        'address_line' => 'বাড়ি 9, রোড 4, আগ্রাবাদ সি/এ',
        'landmark' => 'আগ্রাবাদ হোটেলের পেছনে',
        ...$overrides,
    ];
}

it('refuses requests without a customer token', function (): void {
    $this->getJson('/api/v1/storefront/account')->assertUnauthorized();

    // The Next.js server's own token is not a customer.
    $this->withToken(CartTestKit::storefrontToken())->getJson('/api/v1/storefront/account')->assertUnauthorized();
});

it('keeps a customer token off the catalog and admin routes', function (): void {
    account()->getJson('/api/v1/storefront/brands')->assertForbidden();
    app('auth')->forgetGuards();
    // EnsureActiveStaffSession: only a staff User passes.
    account()->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
});

it('refuses and revokes a blocked customer\'s token', function (): void {
    $this->customer->update(['status' => CustomerStatus::Blocked]);

    account()->getJson('/api/v1/storefront/account')->assertUnauthorized();
    expect($this->customer->tokens()->count())->toBe(0);
});

it('shows and updates the profile', function (): void {
    account()->getJson('/api/v1/storefront/account')->assertOk()
        ->assertJsonPath('name', 'সাদিয়া ইসলাম')
        ->assertJsonPath('phone', '01712345678')
        ->assertJsonPath('counts', ['orders' => 0, 'wishlist' => 0, 'addresses' => 0]);

    account()->patchJson('/api/v1/storefront/account', ['name' => 'Sadia Islam', 'email' => 'Sadia@Example.com', 'preferred_language' => 'en'])
        ->assertOk()->assertJsonPath('name', 'Sadia Islam')->assertJsonPath('email', 'sadia@example.com')->assertJsonPath('preferred_language', 'en');

    account()->patchJson('/api/v1/storefront/account', ['phone' => '01999999999', 'preferred_language' => 'fr'])->assertUnprocessable();
    expect($this->customer->refresh()->phone)->toBe('+8801712345678');
});

it('lists only the customer\'s own orders', function (): void {
    $mine = Order::factory()->create(['customer_id' => $this->customer->id]);
    Order::factory()->status(OrderStatus::Delivered)->create(['customer_id' => $this->customer->id]);
    $theirs = Order::factory()->create(['customer_id' => $this->other->id]);

    $response = account()->getJson('/api/v1/storefront/account/orders')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('summary', ['active' => 1, 'delivered' => 1, 'cancelled' => 0, 'returned' => 0]);

    expect(collect($response->json('data'))->pluck('number')->all())->toContain($mine->number)->not->toContain($theirs->number)
        ->and($response->json('data.0.grand_total'))->toBe('1320.00');
});

it('filters orders by an allowlisted group only (rule 16)', function (): void {
    Order::factory()->create(['customer_id' => $this->customer->id]);
    $delivered = Order::factory()->status(OrderStatus::Delivered)->create(['customer_id' => $this->customer->id]);

    account()->getJson('/api/v1/storefront/account/orders?group=delivered')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $delivered->number)->assertJsonPath('data.0.stage', 'delivered');
    // Rule 16: an unknown group or an unlisted parameter is a 400 `bad-request`, never a 422.
    account()->getJson('/api/v1/storefront/account/orders?group=everything')->assertStatus(400)->assertJsonPath('status', 400);
    account()->getJson('/api/v1/storefront/account/orders?group[]=active')->assertStatus(400);
    account()->getJson('/api/v1/storefront/account/orders?customer_id='.$this->other->id)->assertStatus(400)
        ->assertHeader('Content-Type', 'application/problem+json');
    account()->getJson('/api/v1/storefront/account/orders?filter[status]=delivered')->assertStatus(400);
    account()->getJson('/api/v1/storefront/account/orders?group=&locale=bn')->assertOk();
});

it('never lets a customer read another customer\'s order', function (): void {
    $mine = Order::factory()->create(['customer_id' => $this->customer->id]);
    $theirs = Order::factory()->create(['customer_id' => $this->other->id]);

    account()->getJson('/api/v1/storefront/account/orders/'.$mine->number)->assertOk()
        ->assertJsonPath('number', $mine->number)
        ->assertJsonPath('shipping.address_line', $mine->shipping_address);

    $missing = account()->getJson('/api/v1/storefront/account/orders/'.$theirs->number)->assertNotFound();
    $unknown = account()->getJson('/api/v1/storefront/account/orders/TMB-26-99999')->assertNotFound();
    expect($missing->json('detail'))->toBe($unknown->json('detail'));
});

it('manages addresses: first is default, one default at a time', function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);

    $first = account()->postJson('/api/v1/storefront/account/addresses', addressBody())->assertCreated()
        ->assertJsonPath('is_default', true)
        ->assertJsonPath('landmark', 'আগ্রাবাদ হোটেলের পেছনে')
        ->json('id');

    $second = account()->postJson('/api/v1/storefront/account/addresses', addressBody(['type' => 'office', 'label' => 'Office']))
        ->assertCreated()->assertJsonPath('is_default', false)->json('id');

    account()->postJson("/api/v1/storefront/account/addresses/{$second}/default")->assertOk()->assertJsonPath('is_default', true);
    expect(Address::query()->where('customer_id', $this->customer->id)->where('is_default_shipping', true)->pluck('id')->all())->toBe([$second]);

    $district = District::query()->findOrFail(addressPlace()['district_id']);
    account()->getJson('/api/v1/storefront/account/addresses')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $second)
        ->assertJsonPath('data.0.division.id', $district->division_id);

    account()->patchJson("/api/v1/storefront/account/addresses/{$first}", addressBody(['area' => 'হালিশহর']))->assertOk()->assertJsonPath('area', 'হালিশহর');

    account()->deleteJson("/api/v1/storefront/account/addresses/{$second}")->assertNoContent();
    expect(Address::query()->findOrFail($first)->is_default_shipping)->toBeTrue();
});

it('validates an address like checkout does (rule 31)', function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $place = addressPlace();
    $elsewhere = Upazila::query()->where('district_id', '!=', $place['district_id'])->firstOrFail();

    account()->postJson('/api/v1/storefront/account/addresses', addressBody(['upazila_id' => $elsewhere->id]))
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'upazila_id');
    account()->postJson('/api/v1/storefront/account/addresses', addressBody(['phone' => '01112345678']))
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'phone');
});

it('never touches another customer\'s address', function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $theirs = Address::factory()->create(['customer_id' => $this->other->id]);

    account()->patchJson("/api/v1/storefront/account/addresses/{$theirs->id}", addressBody())->assertNotFound();
    account()->deleteJson("/api/v1/storefront/account/addresses/{$theirs->id}")->assertNotFound();
    account()->postJson("/api/v1/storefront/account/addresses/{$theirs->id}/default")->assertNotFound();
    expect(Address::query()->find($theirs->id))->not->toBeNull();
});

it('keeps a wishlist with live prices and stock', function (): void {
    $variant = CartTestKit::variant('1250.00', 0);
    $productId = $variant->product_id;

    account()->postJson('/api/v1/storefront/account/wishlist', ['product_id' => $productId])->assertCreated()
        ->assertJsonPath('available', true)->assertJsonPath('product.in_stock', false);
    account()->postJson('/api/v1/storefront/account/wishlist', ['product_id' => $productId])->assertOk();
    expect(WishlistItem::query()->count())->toBe(1);

    // Price and stock change after saving: the list shows today's values.
    ProductVariant::query()->whereKey($variant->id)->update(['price' => '990.00']);
    CartTestKit::stock($variant, 4);

    account()->getJson('/api/v1/storefront/account/wishlist')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product.price', '990.00')
        ->assertJsonPath('data.0.product.in_stock', true);

    account()->getJson('/api/v1/storefront/account')->assertJsonPath('counts.wishlist', 1);

    account()->deleteJson("/api/v1/storefront/account/wishlist/{$productId}")->assertNoContent();
    account()->deleteJson("/api/v1/storefront/account/wishlist/{$productId}")->assertNoContent();
    account()->getJson('/api/v1/storefront/account/wishlist')->assertJsonCount(0, 'data');
});

it('refuses to save an unpublished product and hides one archived later', function (): void {
    $variant = CartTestKit::variant();
    $product = $variant->product;

    account()->postJson('/api/v1/storefront/account/wishlist', ['product_id' => $product->id])->assertCreated();
    $product->forceFill(['published_at' => null])->save();

    account()->getJson('/api/v1/storefront/account/wishlist')->assertOk()
        ->assertJsonPath('data.0.available', false)->assertJsonPath('data.0.product', null);
    account()->postJson('/api/v1/storefront/account/wishlist', ['product_id' => $product->id])->assertUnprocessable();
    account()->postJson('/api/v1/storefront/account/wishlist', ['product_id' => 999999])->assertUnprocessable();
});

it('keeps each customer\'s wishlist to themselves', function (): void {
    $variant = CartTestKit::variant();
    WishlistItem::query()->create(['customer_id' => $this->other->id, 'product_id' => $variant->product_id]);

    account()->getJson('/api/v1/storefront/account/wishlist')->assertJsonCount(0, 'data');
    account()->deleteJson("/api/v1/storefront/account/wishlist/{$variant->product_id}")->assertNoContent();
    expect(WishlistItem::query()->where('customer_id', $this->other->id)->count())->toBe(1);
});
