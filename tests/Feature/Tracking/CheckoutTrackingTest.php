<?php

declare(strict_types=1);

use App\Domain\Ordering\Models\Order;
use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Models\OrderTrackingContext;
use App\Domain\Tracking\Models\TrackingEvent;
use App\Domain\Tracking\Services\TrackingSettings;
use App\Domain\Tracking\Support\TrackingEventIds;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Finance\FinanceKit;
use Tests\Feature\Orders\Support;
use Tests\Feature\Tracking\Support\FakeTrackingDestination;

/*
 * Checkout + tracking (docs/api/tracking.md §2.3): the optional `tracking` object, the
 * context written after commit, and the confirmation's purchase ids.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $this->token = CartTestKit::storefrontToken();
    config(['risk.first_order_hold_threshold' => '100000.00']);
});

/** @param  array<string, mixed>  $body */
function trackedCheckout(string $cartToken, array $body): Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->postJson('/api/v1/storefront/checkout', $body, [
        CartTestKit::HEADER => $cartToken,
        'Idempotency-Key' => (string) Str::uuid(),
    ]);
}

/** @return array<string, mixed> */
function trackingObject(): array
{
    return [
        'event_source_url' => 'https://toymartbd.test/checkout',
        'client' => [
            'ip' => '103.4.145.27',
            'user_agent' => 'Mozilla/5.0 CheckoutUA',
            'ga_client_id' => '1234567890.1731456000',
            'fbp' => 'fb.1.1731456000000.987654321',
        ],
        'consent' => ['analytics' => true, 'ads' => true, 'mode' => 'opt_in', 'policy_version' => '2026-11'],
    ];
}

it('stores the browser context after the order commits and returns the purchase ids', function (): void {
    FakeTrackingDestination::install();
    $variant = CartTestKit::variant('1000.00', 5);
    $cart = CheckoutKit::cart([$variant->id => 2]);

    $response = trackedCheckout($cart, CheckoutKit::payload($cart, ['tracking' => trackingObject()]))->assertCreated();

    $order = Order::query()->sole();
    expect($response->json('tracking'))->toBe([
        'purchase_event_id' => TrackingEventIds::purchase($order->number),
        'fire_browser_purchase' => true,
    ]);
    $context = OrderTrackingContext::query()->sole();
    expect($context->order_id)->toBe($order->id)
        ->and($context->client_ip)->toBe('103.4.145.27')
        ->and($context->user_agent)->toBe('Mozilla/5.0 CheckoutUA')
        ->and($context->fbp)->toBe('fb.1.1731456000000.987654321')
        ->and($context->consent)->toMatchArray(['analytics' => true, 'ads' => true, 'mode' => 'opt_in']);
    // Never echoed back to the storefront.
    expect($response->getContent())->not->toContain('103.4.145.27')->not->toContain('CheckoutUA');

    Support::assertStockInvariants();
    FinanceKit::assertFinanceInvariant();
});

it('still checks out without a tracking object, and writes no context', function (): void {
    $variant = CartTestKit::variant('1000.00', 5);
    $cart = CheckoutKit::cart([$variant->id => 1]);

    $response = trackedCheckout($cart, CheckoutKit::payload($cart))->assertCreated();

    expect(OrderTrackingContext::query()->count())->toBe(0)
        ->and($response->json('tracking.purchase_event_id'))->toBe(TrackingEventIds::purchase(Order::query()->sole()->number));
    Support::assertStockInvariants();
    FinanceKit::assertFinanceInvariant();
});

it('records the purchase once, with the deterministic id, after a checkout', function (): void {
    FakeTrackingDestination::install();
    Setting::put(TrackingSettings::CONSENT_MODE, 'notice', SettingGroup::Tracking);
    $variant = CartTestKit::variant('1000.00', 5);
    $cart = CheckoutKit::cart([$variant->id => 1]);

    $response = trackedCheckout($cart, CheckoutKit::payload($cart, ['tracking' => trackingObject()]))->assertCreated();

    $row = TrackingEvent::query()->where('event_name', 'purchase')->sole();
    expect($row->event_id)->toBe($response->json('tracking.purchase_event_id'))
        ->and($row->ga4_status)->toBe(DispatchStatus::Sent);
    Support::assertStockInvariants();
    FinanceKit::assertFinanceInvariant();
});

it('says not to fire a browser purchase when the trigger is delivery', function (): void {
    Setting::put(TrackingSettings::PURCHASE_TRIGGER, 'order_delivered', SettingGroup::Tracking);
    $variant = CartTestKit::variant('1000.00', 5);
    $cart = CheckoutKit::cart([$variant->id => 1]);

    trackedCheckout($cart, CheckoutKit::payload($cart, ['tracking' => trackingObject()]))->assertCreated()
        ->assertJsonPath('tracking.fire_browser_purchase', false);
    expect(TrackingEvent::query()->count())->toBe(0);
});

it('rejects a malformed tracking object with 422 and places no order', function (array $tracking, string $field): void {
    $variant = CartTestKit::variant('1000.00', 5);
    $cart = CheckoutKit::cart([$variant->id => 1]);

    trackedCheckout($cart, CheckoutKit::payload($cart, ['tracking' => $tracking]))->assertStatus(422)
        ->assertJsonPath('errors', fn (array $errors): bool => in_array($field, array_column($errors, 'field'), true));
    expect(Order::query()->count())->toBe(0);
})->with([
    'no consent' => [['client' => ['ip' => '103.4.145.27']], 'tracking.consent'],
    'bad ip' => [['client' => ['ip' => 'nope'], 'consent' => ['analytics' => true, 'ads' => true, 'mode' => 'opt_in']], 'tracking.client.ip'],
    'bad mode' => [['client' => [], 'consent' => ['analytics' => true, 'ads' => true, 'mode' => 'maybe']], 'tracking.consent.mode'],
]);
