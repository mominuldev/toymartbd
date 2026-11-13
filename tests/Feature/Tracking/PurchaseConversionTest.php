<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Enums\OrderSource;
use App\Domain\Ordering\Events\OrderDelivered;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Events\RefundPaid;
use App\Domain\Ordering\Models\Order;
use App\Domain\Tracking\Actions\PruneOrderTrackingContexts;
use App\Domain\Tracking\Actions\RecordOrderTrackingContext;
use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\DTOs\TrackingClientContext;
use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Listeners\RecordPurchaseConversion;
use App\Domain\Tracking\Models\OrderTrackingContext;
use App\Domain\Tracking\Models\TrackingEvent;
use App\Domain\Tracking\Services\TrackingSettings;
use App\Domain\Tracking\Support\TrackingEventIds;
use App\Http\Resources\Storefront\StorefrontOrderResource;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Http\Request;
use Tests\Feature\Ordering\Support\OrderFixtures;
use Tests\Feature\Tracking\Support\FakeTrackingDestination;

/*
 * Server-originated conversions (PRD K-02): purchase on exactly one trigger, refund to GA4.
 */

function trackingTrigger(string $trigger): void
{
    Setting::put(TrackingSettings::PURCHASE_TRIGGER, $trigger, SettingGroup::Tracking);
}

function placeTrackedOrder(OrderSource $source = OrderSource::Web): Order
{
    $customer = Customer::query()->where('phone', '+8801712345678')->first()
        ?? Customer::factory()->create(['name' => 'রাহেলা খাতুন', 'phone' => '+8801712345678', 'email' => 'rahela.khatun@example.com']);

    return OrderFixtures::place([OrderFixtures::stockedVariant(10, '1250.00')->id => 2], customer: $customer, overrides: ['source' => $source]);
}

function purchaseRows(): Illuminate\Database\Eloquent\Collection
{
    return TrackingEvent::query()->where('event_name', 'purchase')->get();
}

it('fires purchase once on OrderPlaced when the trigger is order_placed, never on delivery', function (): void {
    $fakes = FakeTrackingDestination::install();
    trackingTrigger('order_placed');
    Setting::put(TrackingSettings::CONSENT_MODE, 'notice', SettingGroup::Tracking);

    $order = placeTrackedOrder();
    event(new OrderDelivered($order->id, $order->customer_id, 'shipped', 'delivered'));
    // A re-delivered OrderPlaced is also a no-op (deterministic id + idempotent ingest).
    event(new OrderPlaced($order->id, $order->customer_id));

    $row = purchaseRows()->sole();
    expect($row->event_id)->toBe(TrackingEventIds::purchase($order->number))
        ->and($row->source->value)->toBe('server')
        ->and($row->order_id)->toBe($order->id)
        ->and($row->value?->toDecimal())->toBe($order->grand_total->toDecimal())
        ->and($row->currency)->toBe('BDT')
        ->and($row->payload['transaction_id'])->toBe($order->number)
        ->and($row->payload['items'][0])->toMatchArray(['quantity' => 2, 'price' => '1250.00'])
        ->and($row->ga4_status)->toBe(DispatchStatus::Sent)
        ->and($row->meta_status)->toBe(DispatchStatus::Sent);
    expect($fakes['ga4']->sent)->toHaveCount(1)->and($fakes['meta']->sent)->toHaveCount(1);
    expect($fakes['meta']->sent[0]->transactionId)->toBe($order->number)
        ->and($fakes['meta']->sent[0]->phoneE164)->toBe('+8801712345678');
});

it('fires purchase once on OrderDelivered when the trigger is order_delivered, never on placement', function (): void {
    $fakes = FakeTrackingDestination::install();
    trackingTrigger('order_delivered');
    Setting::put(TrackingSettings::CONSENT_MODE, 'notice', SettingGroup::Tracking);

    $order = placeTrackedOrder();
    expect(purchaseRows())->toHaveCount(0);

    event(new OrderDelivered($order->id, $order->customer_id, 'shipped', 'delivered'));
    event(new OrderDelivered($order->id, $order->customer_id, 'shipped', 'delivered'));

    expect(purchaseRows())->toHaveCount(1)
        ->and(purchaseRows()->sole()->event_id)->toBe(TrackingEventIds::purchase($order->number))
        ->and($fakes['ga4']->sent)->toHaveCount(1);
});

it('gives the confirmation resource the same purchase event_id the server sends', function (string $trigger, bool $browser): void {
    trackingTrigger($trigger);
    $order = placeTrackedOrder();

    $json = (new StorefrontOrderResource($order))->toArray(Request::create('/'));

    expect($json['tracking'])->toBe([
        'purchase_event_id' => TrackingEventIds::purchase($order->number),
        'fire_browser_purchase' => $browser,
    ])->and($json['tracking']['purchase_event_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    // Public tracking never carries it.
    expect(StorefrontOrderResource::forTracking($order)->toArray(Request::create('/'))['tracking'])->toBeNull();
})->with([['order_placed', true], ['order_delivered', false]]);

it('is deterministic: the same order number always yields the same id', function (): void {
    expect(TrackingEventIds::purchase('TMB-26-00042'))->toBe(TrackingEventIds::purchase('TMB-26-00042'))
        ->not->toBe(TrackingEventIds::purchase('TMB-26-00043'));
});

it('uses the checkout context and consent when the storefront sent them', function (): void {
    $fakes = FakeTrackingDestination::install();
    trackingTrigger('order_delivered');
    $order = placeTrackedOrder();
    app(RecordOrderTrackingContext::class)->handle(
        $order->id,
        new TrackingClientContext('103.4.145.27', 'TrackingTestUA/1.0', '111.222', '333', 'fb.1.1.fbpvalue', null),
        new ConsentState(false, true, 'opt_in', '2026-11'),
    );

    event(new OrderDelivered($order->id, $order->customer_id, 'shipped', 'delivered'));

    $row = purchaseRows()->sole();
    // Analytics refused → GA4 skipped; ads granted → Meta sent with the shopper's context.
    expect($row->ga4_status)->toBe(DispatchStatus::SkippedConsent)
        ->and($row->meta_status)->toBe(DispatchStatus::Sent)
        ->and($fakes['meta']->sent[0]->clientIp)->toBe('103.4.145.27')
        ->and($fakes['meta']->sent[0]->fbp)->toBe('fb.1.1.fbpvalue');
});

it('never stores raw PII: phone, email, name, IP and user agent are absent from the row', function (): void {
    FakeTrackingDestination::install();
    trackingTrigger('order_delivered');
    $order = placeTrackedOrder();
    app(RecordOrderTrackingContext::class)->handle($order->id, new TrackingClientContext('103.4.145.27', 'TrackingTestUA/1.0'), new ConsentState(true, true, 'opt_in'));

    event(new OrderDelivered($order->id, $order->customer_id, 'shipped', 'delivered'));

    $row = purchaseRows()->sole();
    $stored = json_encode($row->getAttributes(), JSON_UNESCAPED_UNICODE);
    expect($stored)->not->toContain('8801712345678')
        ->not->toContain('01712345678')
        ->not->toContain('rahela.khatun@example.com')
        ->not->toContain('রাহেলা')
        ->not->toContain('103.4.145.27')
        ->not->toContain('TrackingTestUA')
        // The hashes the driver sent are kept (as sent).
        ->toContain(hash('sha256', 'rahela.khatun@example.com'));
});

it('sends a staff-entered order with server context only in notice mode', function (): void {
    $fakes = FakeTrackingDestination::install();
    trackingTrigger('order_placed');
    Setting::put(TrackingSettings::CONSENT_MODE, 'notice', SettingGroup::Tracking);

    placeTrackedOrder(OrderSource::Phone);

    $row = purchaseRows()->sole();
    expect($row->ga4_status)->toBe(DispatchStatus::Sent)
        ->and($row->consent_state)->toMatchArray(['analytics' => true, 'ads' => true, 'mode' => 'notice'])
        ->and($fakes['meta']->sent[0]->clientIp)->toBeNull()
        ->and($fakes['meta']->sent[0]->userAgent)->toBeNull();
});

it('skips a staff-entered order in opt_in mode: nobody opted in', function (): void {
    $fakes = FakeTrackingDestination::install();
    trackingTrigger('order_placed');

    placeTrackedOrder(OrderSource::Phone);

    $row = purchaseRows()->sole();
    expect($row->ga4_status)->toBe(DispatchStatus::SkippedConsent)
        ->and($row->meta_status)->toBe(DispatchStatus::SkippedConsent)
        ->and($fakes['ga4']->sent)->toBe([])
        ->and($fakes['meta']->sent)->toBe([]);
});

it('queues the OrderPlaced purchase on the tracking queue with a delay, so the checkout context lands first', function (): void {
    $listener = app(RecordPurchaseConversion::class);
    $placed = new OrderPlaced(1, 1);

    expect($listener->viaQueue())->toBe('tracking')
        ->and($listener->withDelay($placed))->toBe(20)
        ->and($listener->withDelay(new OrderDelivered(1, 1, 'shipped', 'delivered')))->toBe(0);

    trackingTrigger('order_delivered');
    expect($listener->shouldQueue($placed))->toBeFalse();
});

it('sends a paid refund to GA4 only', function (): void {
    $fakes = FakeTrackingDestination::install();
    trackingTrigger('order_delivered');
    Setting::put(TrackingSettings::CONSENT_MODE, 'notice', SettingGroup::Tracking);
    $order = placeTrackedOrder();

    event(new RefundPaid(77, $order->id, '500.00', 'bkash', now()->toDateString()));
    event(new RefundPaid(77, $order->id, '500.00', 'bkash', now()->toDateString()));

    $row = TrackingEvent::query()->where('event_name', 'refund')->sole();
    expect($row->event_id)->toBe(TrackingEventIds::refund(77))
        ->and($row->value?->toDecimal())->toBe('500.00')
        ->and($row->payload['transaction_id'])->toBe($order->number)
        ->and($row->ga4_status)->toBe(DispatchStatus::Sent)
        ->and($row->meta_status)->toBe(DispatchStatus::NotApplicable)
        ->and($fakes['meta']->sent)->toBe([]);
});

it('redacts checkout IP, user agent and click ids after the retention period, keeping the consent', function (): void {
    $old = placeTrackedOrder();
    $this->travelTo(now()->subDays(91));
    app(RecordOrderTrackingContext::class)->handle($old->id, new TrackingClientContext('103.4.145.27', 'UA', '1.2', '3', 'fb.1.x', 'fb.1.y'), new ConsentState(true, false, 'opt_in'));
    $this->travelBack();
    $fresh = placeTrackedOrder();
    app(RecordOrderTrackingContext::class)->handle($fresh->id, new TrackingClientContext('103.4.145.28', 'UA'), new ConsentState(true, true, 'opt_in'));

    $this->artisan('toymart:tracking:prune-contexts')->assertSuccessful();

    $redacted = OrderTrackingContext::query()->where('order_id', $old->id)->sole();
    expect($redacted->client_ip)->toBeNull()
        ->and($redacted->user_agent)->toBeNull()
        ->and($redacted->fbp)->toBeNull()
        ->and($redacted->redacted_at)->not->toBeNull()
        ->and($redacted->consent)->toMatchArray(['analytics' => true, 'ads' => false]);
    expect(OrderTrackingContext::query()->where('order_id', $fresh->id)->sole()->client_ip)->toBe('103.4.145.28');
    expect(app(PruneOrderTrackingContexts::class)->handle())->toBe(0);
});
