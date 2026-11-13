<?php

declare(strict_types=1);

use App\Domain\Ordering\Actions\PlaceOrder;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\VerificationStatus;
use App\Domain\Ordering\Events\OrderCancelled;
use App\Domain\Ordering\Models\Order;
use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Domain\Risk\Models\RiskVerificationCall;
use App\Models\Enums\Role;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Finance\FinanceKit;
use Tests\Feature\Risk\RiskKit;

/*
 * The verification queue and phone lookup (checklist §2.4; rules 23, 24, 24b, 33b).
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
});

function placeOrder(string $price = '1000.00', int $qty = 1, string $phone = CheckoutKit::PHONE): Order
{
    $variant = CartTestKit::variant($price, 20);
    $cart = CheckoutKit::cart([$variant->id => $qty]);

    return app(PlaceOrder::class)->handle(CheckoutKit::data($cart, $phone));
}

it('lists held orders with the score they were held on, its breakdown, and our profile', function (): void {
    FinanceKit::actingAs(Role::OrderOperator);
    RiskKit::rules(['first_order' => 10, 'rapid_repeat' => 5], threshold: '3000.00');
    $held = placeOrder('3500.00');
    placeOrder('500.00', 1, '01812345678'); // not held: below the threshold

    $response = $this->getJson('/api/v1/admin/risk/queue')->assertOk();

    expect($response->json('meta.count'))->toBe(1)
        ->and($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.number'))->toBe($held->number)
        ->and($response->json('data.0.order_risk.hold_reasons'))->toBe(['first_order_above_threshold'])
        ->and($response->json('data.0.order_risk.score'))->toBe(10)
        ->and(array_sum(array_column($response->json('data.0.order_risk.signals'), 'contribution')))->toBe(10)
        ->and($response->json('data.0.ours.stored'))->toBeTrue()
        ->and($response->json('data.0.ours.counts.total_orders'))->toBe(1)
        ->and($response->json('data.0.status.value'))->toBe('normal')
        ->and($response->json('data.0.customer_name'))->toBe('রাহেলা খাতুন');
});

it('shows the courier panel as not connected — never a zero — beside our score (rules 24, 33b)', function (): void {
    FinanceKit::actingAs(Role::OrderOperator);
    $order = RiskKit::heldOrder();

    $response = $this->getJson('/api/v1/admin/risk/orders/'.$order->id)->assertOk();

    $providers = $response->json('phone.courier.providers');
    expect($providers)->not->toBeEmpty()
        ->and($response->json('phone.courier.checked_at'))->not->toBeNull()
        ->and($response->json('phone.ours'))->toHaveKeys(['internal_score', 'breakdown', 'score_computed_at', 'counts']);
    foreach ($providers as $p) {
        expect($p['connected'])->toBeFalse()
            ->and($p['reason'])->toBeString()
            ->and($p['total_parcels'])->toBeNull()
            ->and($p['total_delivered'])->toBeNull()
            ->and($p['delivery_ratio'])->toBeNull();
    }
    // Our block never carries courier figures, and the courier block never carries our score.
    expect($response->json('phone.ours'))->not->toHaveKey('courier_delivered_count')
        ->and($response->json('phone.courier'))->not->toHaveKey('internal_score');
});

it('looks up a phone with our score, timestamps and the courier panel side by side', function (): void {
    FinanceKit::actingAs(Role::Manager);
    Order::factory()->create(['shipping_phone' => RiskKit::PHONE]);

    $response = $this->getJson('/api/v1/admin/risk/lookup?phone=01712345678')->assertOk();

    expect($response->json('phone'))->toBe(RiskKit::PHONE)
        ->and($response->json('ours.counts.total_orders'))->toBe(1)
        ->and($response->json('ours.score_computed_at'))->not->toBeNull()
        ->and($response->json('courier.checked_at'))->not->toBeNull()
        ->and($response->json('courier.providers.0.connected'))->toBeFalse()
        ->and($response->json('courier.providers.0.total_parcels'))->toBeNull()
        ->and($response->json('recent_orders'))->toHaveCount(1);

    $this->getJson('/api/v1/admin/risk/lookup?phone=01112345678')->assertStatus(422); // 011: rule 30
});

it('masks the phone for a role without customers.view_pii', function (): void {
    FinanceKit::actingAs(Role::Viewer);

    $response = $this->getJson('/api/v1/admin/risk/lookup?phone=01712345678')->assertOk();

    expect($response->json('phone'))->not->toBe(RiskKit::PHONE)->and($response->json('pii_masked'))->toBeTrue();
});

it('verifies a held order: hold released, who and when recorded', function (): void {
    $user = FinanceKit::actingAs(Role::OrderOperator);
    $order = RiskKit::heldOrder();

    $this->postJson("/api/v1/admin/risk/orders/{$order->id}/verify", ['note' => 'গ্রাহক নিশ্চিত করেছেন'])
        ->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('verification_status', 'verified');

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->verification_status)->toBe(VerificationStatus::Verified)
        ->and($order->verified_by)->toBe($user->id)
        ->and($order->verified_at)->not->toBeNull();

    // A second click (or a colleague) is a conflict, not a second move.
    $this->postJson("/api/v1/admin/risk/orders/{$order->id}/verify")->assertStatus(409);
});

it('logs a call without moving the order', function (): void {
    FinanceKit::actingAs(Role::OrderOperator);
    $order = RiskKit::heldOrder();

    $this->postJson("/api/v1/admin/risk/orders/{$order->id}/calls", ['outcome' => 'no_answer', 'note' => 'দুবার কল, ধরেননি'])
        ->assertCreated()->assertJsonPath('outcome', 'no_answer');

    expect($order->refresh()->status)->toBe(OrderStatus::OnHold)
        ->and(RiskVerificationCall::query()->count())->toBe(1);
    $this->getJson('/api/v1/admin/risk/queue')->assertJsonPath('data.0.calls.count', 1)->assertJsonPath('data.0.calls.last_outcome', 'no_answer');
});

it('cancels only when a person chooses to, with a reason', function (): void {
    FinanceKit::actingAs(Role::OrderOperator);
    $order = RiskKit::heldOrder();

    $this->postJson("/api/v1/admin/risk/orders/{$order->id}/reject", [])->assertStatus(422);
    $this->postJson("/api/v1/admin/risk/orders/{$order->id}/reject", ['reason' => 'other'])->assertStatus(422);
    $this->postJson("/api/v1/admin/risk/orders/{$order->id}/reject", ['reason' => 'fake_order', 'note' => 'নম্বরটি ভুল'])
        ->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('verification_status', 'failed');
});

it('routes every order from a blacklisted phone to the queue — held, never cancelled', function (): void {
    FinanceKit::actingAs(Role::Manager);
    Event::fake([OrderCancelled::class]);
    Order::factory()->create(['shipping_phone' => RiskKit::PHONE]); // known phone, small order: nothing else would hold it

    $this->putJson('/api/v1/admin/risk/phone-status', ['phone' => '01712345678', 'status' => 'blacklist', 'reason' => 'ভুয়া অর্ডারের ইতিহাস'])
        ->assertStatus(422); // typed confirmation missing
    $this->putJson('/api/v1/admin/risk/phone-status', ['phone' => '01712345678', 'status' => 'blacklist', 'reason' => 'ভুয়া অর্ডারের ইতিহাস', 'confirm' => '5678'])
        ->assertOk()->assertJsonPath('status.value', 'blacklist')->assertJsonPath('status.reason', 'ভুয়া অর্ডারের ইতিহাস');

    $order = placeOrder('300.00');

    expect($order->status)->toBe(OrderStatus::OnHold)
        ->and($order->verification_status)->toBe(VerificationStatus::Pending)
        ->and($order->risk_flags['hold_reasons'])->toContain('blacklisted');
    $this->getJson('/api/v1/admin/risk/queue')->assertJsonPath('data.0.number', $order->number);
    Event::assertNotDispatched(OrderCancelled::class);

    $profile = CustomerRiskProfile::query()->where('phone', RiskKit::PHONE)->sole();
    expect($profile->status_set_by)->not->toBeNull()->and($profile->status_set_at)->not->toBeNull();
});

it('routes a watchlisted phone to the queue too', function (): void {
    FinanceKit::actingAs(Role::Manager);
    Order::factory()->create(['shipping_phone' => RiskKit::PHONE]);
    $this->putJson('/api/v1/admin/risk/phone-status', ['phone' => '01712345678', 'status' => 'watch', 'reason' => 'নজরে রাখুন'])->assertOk();

    expect(placeOrder('300.00')->risk_flags['hold_reasons'])->toBe(['watchlist']);
});

it('lets a whitelisted phone through a high score, but not past the first-order rule', function (): void {
    FinanceKit::actingAs(Role::Manager);
    RiskKit::rules(['first_order' => 80], threshold: '3000.00');
    $this->putJson('/api/v1/admin/risk/phone-status', ['phone' => '01712345678', 'status' => 'whitelist', 'reason' => 'পরিচিত গ্রাহক'])->assertOk();

    $small = placeOrder('300.00');
    expect($small->risk_score)->toBe(80)->and($small->status)->toBe(OrderStatus::Pending);

    $this->putJson('/api/v1/admin/risk/phone-status', ['phone' => '01912345678', 'status' => 'whitelist', 'reason' => 'পরিচিত গ্রাহক'])->assertOk();
    expect(placeOrder('3500.00', 1, '01912345678')->risk_flags['hold_reasons'])->toBe(['first_order_above_threshold']);
});

it('never auto-cancels: the riskiest possible order is held, and Risk has no path to a cancel', function (): void {
    Event::fake([OrderCancelled::class]);
    RiskKit::rules(['first_order' => 100], threshold: '1.00');
    CustomerRiskProfile::query()->create(['phone' => RiskKit::PHONE, 'status' => 'blacklist', 'status_reason' => 'x', 'status_set_at' => now()]);

    $order = placeOrder('5000.00');

    expect($order->status)->toBe(OrderStatus::OnHold)
        ->and($order->risk_score)->toBe(100)
        ->and($order->risk_flags['hold_reasons'])->toContain('high_risk_score', 'blacklisted', 'first_order_above_threshold');
    Event::assertNotDispatched(OrderCancelled::class);

    // Static guarantee: nothing in the Risk domain can reach a cancellation.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Domain/Risk')));
    foreach ($files as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $code = (string) file_get_contents($file->getPathname());
            expect($code)->not->toContain('CancelOrder')
                ->not->toContain('OrderStatus::Cancelled')
                ->not->toContain("'cancelled'".' =>');
        }
    }
});

it('saves the Risk rules and refuses weights totalling over 100', function (): void {
    FinanceKit::actingAs(Role::Manager);
    $rules = $this->getJson('/api/v1/admin/risk/rules')->assertOk()->json();
    expect($rules['weights'])->toHaveKeys(['cancellation_rate', 'courier_return_rate', 'first_order'])
        ->and($rules['high_threshold'])->toBe(70);

    $rules['weights']['first_order'] = 5; // total 90
    $rules['first_order_hold_threshold'] = '2500.00';
    $this->putJson('/api/v1/admin/risk/rules', $rules)->assertOk()->assertJsonPath('weights.first_order', 5)
        ->assertJsonPath('first_order_hold_threshold', '2500.00');
    expect(placeOrder('2500.00')->risk_flags['hold_reasons'])->toContain('first_order_above_threshold');

    $rules['weights']['first_order'] = 50; // 25+25+15+50+10+5+5 = 135
    $this->putJson('/api/v1/admin/risk/rules', $rules)->assertStatus(422);
    $rules['weights']['first_order'] = 10;
    $rules['medium_threshold'] = 80;
    $this->putJson('/api/v1/admin/risk/rules', $rules)->assertStatus(422);
});
