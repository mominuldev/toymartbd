<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Services\OrderTransitions;
use App\Domain\Risk\Listeners\RefreshProfileOnOrderChange;
use App\Domain\Risk\Models\CustomerRiskProfile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Finance\FinanceKit;
use Tests\Feature\Risk\RiskKit;

/*
 * customer_risk_profiles (§3.3): keyed on the phone, rebuilt from order events, replay-safe.
 */

it('keys the profile on the phone and maintains it from order events', function (): void {
    // Placed → delivered.
    $delivered = FinanceKit::shippedOrder();
    $delivered->forceFill(['shipping_phone' => RiskKit::PHONE])->saveQuietly();
    FinanceKit::deliver($delivered->refresh());

    // Placed → cancelled by the customer.
    $cancelled = Order::factory()->create(['shipping_phone' => RiskKit::PHONE]);
    app(CancelOrder::class)->handle($cancelled, CancellationReason::FakeOrder);

    // Shipped → came back undelivered (refused at the door).
    $refused = FinanceKit::shippedOrder();
    $refused->forceFill(['shipping_phone' => RiskKit::PHONE])->saveQuietly();
    DB::transaction(fn () => app(OrderTransitions::class)->transition($refused->refresh(), OrderStatus::Returned));

    $profile = CustomerRiskProfile::query()->where('phone', RiskKit::PHONE)->sole();
    expect($profile->total_orders)->toBe(3)
        ->and($profile->delivered_count)->toBe(1)
        ->and($profile->cancelled_count)->toBe(1)
        ->and($profile->refused_count)->toBe(1)
        ->and($profile->returned_count)->toBe(0)
        ->and($profile->score_computed_at)->not->toBeNull()
        ->and($profile->internal_score)->toBe(array_sum(array_column($profile->score_breakdown ?? [], 'contribution')))
        ->and($profile->internal_score)->toBeGreaterThan(0)
        ->and($profile->status->value)->toBe('normal');
});

it('recomputes rather than increments, so a replayed event cannot double count', function (): void {
    $customer = Customer::factory()->create(['phone' => RiskKit::PHONE]);
    $order = Order::factory()->create(['customer_id' => $customer->id, 'shipping_phone' => RiskKit::PHONE]);
    $listener = app(RefreshProfileOnOrderChange::class);

    $listener->handle(new OrderPlaced($order->id, $order->customer_id));
    $listener->handle(new OrderPlaced($order->id, $order->customer_id));
    $listener->handle(new OrderPlaced($order->id, $order->customer_id));

    expect(CustomerRiskProfile::query()->count())->toBe(1)
        ->and(CustomerRiskProfile::query()->sole()->total_orders)->toBe(1);
});

it('never touches the staff decision when it rebuilds', function (): void {
    $customer = Customer::factory()->create(['phone' => RiskKit::PHONE]);
    $order = Order::factory()->create(['customer_id' => $customer->id, 'shipping_phone' => RiskKit::PHONE]);
    CustomerRiskProfile::query()->create(['phone' => RiskKit::PHONE, 'status' => 'blacklist', 'status_reason' => 'ভুয়া অর্ডার', 'status_set_at' => now()]);

    app(RefreshProfileOnOrderChange::class)->handle(new OrderPlaced($order->id, $order->customer_id));

    $profile = CustomerRiskProfile::query()->where('phone', RiskKit::PHONE)->sole();
    expect($profile->status->value)->toBe('blacklist')
        ->and($profile->status_reason)->toBe('ভুয়া অর্ডার')
        ->and($profile->total_orders)->toBe(1);
});

it('keeps the finance invariant intact while profiles rebuild', function (): void {
    $order = FinanceKit::shippedOrder();
    FinanceKit::deliver($order);

    expect(CustomerRiskProfile::query()->count())->toBeGreaterThan(0);
    FinanceKit::assertFinanceInvariant();
});
