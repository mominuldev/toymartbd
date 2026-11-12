<?php

declare(strict_types=1);

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;

/*
 * GET /storefront/orders/{number} (confirmation) and POST /storefront/orders/track
 * (rule 22: number plus phone digits) — docs/api/checkout.md.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $this->token = CartTestKit::storefrontToken();
    config(['risk.first_order_hold_threshold' => '100000.00']);

    $variant = CartTestKit::variant('1000.00', 5);
    $this->cart = CheckoutKit::cart([$variant->id => 1]);
    $this->number = $this->withToken($this->token)
        ->postJson('/api/v1/storefront/checkout', CheckoutKit::payload($this->cart), [
            CartTestKit::HEADER => $this->cart, 'Idempotency-Key' => (string) Str::uuid(),
        ])->assertCreated()->json('number');
});

function lookup(string $number, ?string $cartToken): Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->getJson('/api/v1/storefront/orders/'.$number, $cartToken === null ? [] : [CartTestKit::HEADER => $cartToken]);
}

/** @param  array<string, mixed>  $body */
function track(array $body): Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->postJson('/api/v1/storefront/orders/track', $body);
}

it('shows the confirmation with the full address to the cart that placed it', function (): void {
    lookup($this->number, $this->cart)
        ->assertOk()
        ->assertJsonPath('number', $this->number)
        ->assertJsonPath('stage', 'placed')
        ->assertJsonPath('shipping.address_line', 'বাড়ি ১২, রোড ৫, ব্লক সি')
        ->assertJsonPath('shipping.name', 'রাহেলা খাতুন');
});

it('returns the same 404 for a wrong token, no token, an unknown number or an old order', function (): void {
    $notFound = fn ($r) => $r->assertNotFound()->assertJsonPath('type', 'https://toymartbd.com/problems/not-found');

    $notFound(lookup($this->number, (string) Str::uuid()));
    $notFound(lookup($this->number, null));
    $notFound(lookup('TMB-26-99999', $this->cart));

    $other = CheckoutKit::cart([CartTestKit::variant()->id => 1]);
    $notFound(lookup($this->number, $other));

    Order::query()->where('number', $this->number)->update(['placed_at' => now()->subDays(31)]);
    $notFound(lookup($this->number, $this->cart));
});

it('tracks by number and the last 4 phone digits without the street address', function (): void {
    $response = track(['number' => $this->number, 'phone' => '5678'])->assertOk();

    expect($response->json('number'))->toBe($this->number)
        ->and($response->json('shipping.address_line'))->toBeNull()
        ->and($response->json('shipping.landmark'))->toBeNull()
        ->and($response->json('shipping.postcode'))->toBeNull()
        ->and($response->json('shipping.name'))->toBe('রাহেলা')
        ->and($response->json('contact.email'))->toBeNull()
        ->and($response->json('shipping.district.id'))->toBe(CheckoutKit::DISTRICT)
        ->and($response->json('items'))->toHaveCount(1);
});

it('tracks with the full number, Bengali digits and a forgiving order number', function (string $form, string $phone): void {
    $number = match ($form) {
        'as issued' => $this->number,
        'no prefix, lower case' => strtolower(substr($this->number, 4)),
        'spaces' => str_replace('-', ' ', $this->number),
        'bengali digits' => strtr($this->number, ['0' => '০', '1' => '১', '2' => '২', '6' => '৬']),
    };

    track(['number' => $number, 'phone' => $phone])->assertOk()->assertJsonPath('number', $this->number);
})->with([
    'full phone' => ['as issued', '01712-345678'],
    'bengali phone digits' => ['as issued', '৫৬৭৮'],
    'no prefix, lower case' => ['no prefix, lower case', '5678'],
    'spaces' => ['spaces', '5678'],
    'bengali order number' => ['bengali digits', '5678'],
]);

it('returns the same 404 for a wrong phone as for an unknown number (rule 22)', function (): void {
    $wrongPhone = track(['number' => $this->number, 'phone' => '1111'])->assertNotFound();
    $unknown = track(['number' => 'TMB-26-99999', 'phone' => '5678'])->assertNotFound();

    expect(Arr::except($wrongPhone->json(), 'request_id'))->toBe(Arr::except($unknown->json(), 'request_id'))
        ->and($wrongPhone->json('type'))->toBe('https://toymartbd.com/problems/not-found');
});

it('needs both fields', function (array $body, string $field): void {
    track($body)->assertStatus(422)->assertJsonFragment(['field' => $field]);
})->with([
    'no phone' => [['number' => 'TMB-26-00001'], 'phone'],
    'no number' => [['phone' => '5678'], 'number'],
    'three digits' => [['number' => 'TMB-26-00001', 'phone' => '567'], 'phone'],
]);

it('shows the customer stage, not the internal status', function (OrderStatus $status, string $stage): void {
    Order::query()->where('number', $this->number)->update(['status' => $status->value]);

    track(['number' => $this->number, 'phone' => '5678'])->assertOk()->assertJsonPath('stage', $stage);
})->with([
    'on hold reads placed' => [OrderStatus::OnHold, 'placed'],
    'ready to ship reads processing' => [OrderStatus::ReadyToShip, 'processing'],
    'completed reads delivered' => [OrderStatus::Completed, 'delivered'],
    'partially returned reads returned' => [OrderStatus::PartiallyReturned, 'returned'],
]);

it('rate-limits tracking attempts per order number', function (): void {
    RateLimiter::clear('order-track');
    for ($i = 0; $i < 5; $i++) {
        track(['number' => $this->number, 'phone' => sprintf('%04d', $i)])->assertNotFound();
    }

    track(['number' => $this->number, 'phone' => '5678'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});
